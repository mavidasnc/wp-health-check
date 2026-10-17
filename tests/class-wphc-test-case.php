<?php
/**
 * Classe base dei test di caratterizzazione di wp-health-check.
 *
 * Contiene SOLO supporto: costruzione delle buste firmate (enroll v1/v2, rotate, revoke),
 * firma HMAC delle richieste operative, helper per le richieste REST e reset dello stato.
 * Le funzioni di firma sono volutamente indipendenti dal plugin (non chiamano wphc_*):
 * implementano il formato documentato nel README e verificato dai vettori di contratto
 * (tests/test-contract-vectors.php), cosi' i test falliscono se il plugin cambia formato.
 *
 * @package WP_Health_Check
 */

/**
 * Base per i test del plugin: ambiente HTTPS, IP fisso, nessuna rete, stato pulito.
 */
abstract class WPHC_Test_Case extends WP_UnitTestCase {

	/** IP del chiamante simulato (REMOTE_ADDR): fisso, cosi' il throttle e' prevedibile. */
	const CLIENT_IP = '203.0.113.10';

	/** Namespace REST del plugin. */
	const NS = '/health-check/v1';

	/**
	 * Richieste HTTP in uscita intercettate (URL), per verificare che nulla esca davvero.
	 *
	 * @var string[]
	 */
	protected $blocked_http_calls = array();

	/**
	 * Richieste HTTP in uscita arrivate in fondo alla catena di `pre_http_request` ancora "bloccate",
	 * cioe' NON gestite da nessun mock del test: una chiamata di rete che il test non aspettava.
	 *
	 * @var string[]
	 */
	protected $unmocked_http_calls = array();

	/**
	 * Opt-in: il test si aspetta chiamate HTTP non mockate (es. verifica che il plugin tenti la rete
	 * e che il fallimento sia gestito). Di default una chiamata non mockata fa fallire il test.
	 *
	 * @var bool
	 */
	protected $expects_unmocked_http = false;

	/**
	 * Host per cui le chiamate HTTP non mockate sono tollerate (chiamate "di sfondo" che il plugin fa
	 * in quel contesto e che i test non mockano, es. il controllo dell'ultima release nella tab admin).
	 * Una chiamata verso un host diverso fa comunque fallire il test.
	 *
	 * @var string[]
	 */
	protected $allowed_unmocked_hosts = array();

	// -----------------------------------------------------------------------
	// Ciclo di vita
	// -----------------------------------------------------------------------

	/**
	 * Prepara ogni test: ambiente HTTPS, IP fisso, rete bloccata, stato del plugin azzerato.
	 */
	public function set_up() {
		parent::set_up();

		// Le rotte del plugin pretendono HTTPS (wphc_require_https): si simula con $_SERVER.
		// Va impostato DOPO parent::set_up(), che puo' ripulire $_SERVER.
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO'] );

		// Nessuna chiamata di rete reale (ipify, thum.io, GitHub, hub): ogni richiesta fallisce
		// con un WP_Error e viene registrata.
		$this->blocked_http_calls    = array();
		$this->unmocked_http_calls   = array();
		$this->expects_unmocked_http = false;
		$this->allowed_unmocked_hosts = array();
		add_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10, 3 );
		// Ultima della catena: registra solo le richieste che nessun mock ha gestito.
		add_filter( 'pre_http_request', array( $this, 'flag_unmocked_http_request' ), PHP_INT_MAX, 3 );

		// Mittente valido per wp_mail(): con il dominio di wp-env (localhost) il mittente di
		// default "wordpress@localhost" e' un indirizzo non valido e l'invio fallirebbe.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);

		$this->reset_plugin_state();

		// Server REST nuovo per ogni test: rest_get_server() lo ricrea e rilancia rest_api_init.
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Ripulisce l'ambiente simulato.
	 */
	public function tear_down() {
		unset( $_SERVER['HTTPS'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		$GLOBALS['wp_rest_server'] = null;
		remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'flag_unmocked_http_request' ), PHP_INT_MAX );

		// Chiamate HTTP non mockate e non attese: il test ha lasciato uscire una richiesta di rete.
		$unexpected = array();
		if ( ! $this->expects_unmocked_http ) {
			foreach ( $this->unmocked_http_calls as $url ) {
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );
				if ( ! in_array( $host, $this->allowed_unmocked_hosts, true ) ) {
					$unexpected[] = $url;
				}
			}
		}

		parent::tear_down();

		$this->assertSame( array(), $unexpected, 'Chiamate HTTP bloccate non attese (se il test le aspetta: $this->expect_unmocked_http())' );
	}

	/**
	 * Dichiara che il test si aspetta chiamate HTTP non mockate (bloccate da block_http_request()).
	 */
	protected function expect_unmocked_http() {
		$this->expects_unmocked_http = true;
	}

	/**
	 * Tollera le chiamate HTTP non mockate verso questi host (chiamate di sfondo note del contesto).
	 *
	 * @param string[] $hosts Host (senza porta), es. 'api.github.com'.
	 */
	protected function allow_unmocked_http_hosts( array $hosts ) {
		$this->allowed_unmocked_hosts = array_merge( $this->allowed_unmocked_hosts, $hosts );
	}

	/**
	 * Filtro pre_http_request a priorita' massima: se il valore e' ancora l'errore di blocco, nessun
	 * mock ha risposto e la richiesta e' "non mockata".
	 *
	 * @param false|array|WP_Error $preempt Valore corrente del filtro.
	 * @param array                $args    Argomenti della richiesta.
	 * @param string               $url     URL richiesto.
	 * @return false|array|WP_Error Il valore, invariato.
	 */
	public function flag_unmocked_http_request( $preempt, $args, $url ) {
		unset( $args );
		// Il loopback di WP-Cron (spawn_cron(): .../wp-cron.php?doing_wp_cron=...) e' del core, parte da
		// ogni wp_schedule_*() e non dipende dal plugin: non e' una chiamata di rete "inattesa".
		if ( false !== strpos( $url, '/wp-cron.php?doing_wp_cron' ) ) {
			return $preempt;
		}
		if ( is_wp_error( $preempt ) && 'wphc_test_http_blocked' === $preempt->get_error_code() ) {
			$this->unmocked_http_calls[] = $url;
		}

		return $preempt;
	}

	/**
	 * Filtro pre_http_request: registra l'URL e impedisce la richiesta.
	 *
	 * @param false|array|WP_Error $preempt Valore corrente del filtro.
	 * @param array                $args    Argomenti della richiesta.
	 * @param string               $url     URL richiesto.
	 * @return WP_Error Errore sempre, per non uscire in rete.
	 */
	public function block_http_request( $preempt, $args, $url ) {
		unset( $preempt, $args );
		$this->blocked_http_calls[] = $url;

		return new WP_Error( 'wphc_test_http_blocked', 'HTTP bloccato dai test: ' . $url );
	}

	/**
	 * Cancella dal database le option e i transient del plugin (wp_health_check_*, wphc_*),
	 * tranne wp_health_check_db_version: la tabella di log e' creata una volta sola al boot
	 * (fuori dalla transazione dei test) e quella option dice che lo schema c'e' gia'.
	 */
	protected function reset_plugin_state() {
		global $wpdb;

		$like_patterns = array(
			$wpdb->esc_like( 'wp_health_check_' ) . '%',
			$wpdb->esc_like( 'wphc_' ) . '%',
			$wpdb->esc_like( '_transient_wphc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wphc_' ) . '%',
			$wpdb->esc_like( '_transient_wp_health_check_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wp_health_check_' ) . '%',
		);
		foreach ( $like_patterns as $pattern ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $pattern, 'wp_health_check_db_version' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Svuota la cache a oggetti: altrimenti get_option() potrebbe restituire valori vecchi.
		wp_cache_flush();
	}

	// -----------------------------------------------------------------------
	// Chiavi e firme Ed25519 (centro -> sito)
	// -----------------------------------------------------------------------

	/**
	 * Firma Ed25519 detached (base64 standard) di un messaggio con una chiave di TEST.
	 *
	 * @param string $message Messaggio canonico.
	 * @param string $kid     'k1' (buste) o 'k2' (release).
	 * @return string Firma in base64 standard.
	 */
	public static function sign_message( $message, $kid = 'k1' ) {
		$seed    = ( 'k2' === $kid ) ? WPHC_TEST_SEED_K2 : WPHC_TEST_SEED_K1;
		$keypair = sodium_crypto_sign_seed_keypair( $seed );
		$secret  = sodium_crypto_sign_secretkey( $keypair );

		return base64_encode( sodium_crypto_sign_detached( $message, $secret ) );
	}

	/**
	 * Messaggio canonico della busta enroll v1: "site\ntoken\norigin\nissued_at".
	 * Un dashboard_origin null occupa il suo posto come stringa vuota.
	 *
	 * @param string      $site_url  URL firmato.
	 * @param string      $token     Token.
	 * @param string|null $origin    Origin della dashboard o null.
	 * @param int         $issued_at Timestamp di emissione.
	 * @return string
	 */
	public static function message_enroll_v1( $site_url, $token, $origin, $issued_at ) {
		return $site_url . "\n" . $token . "\n" . ( null === $origin ? '' : $origin ) . "\n" . $issued_at;
	}

	/**
	 * Messaggio canonico delle buste con prefisso di dominio: "kind\n" + parti separate da "\n".
	 *
	 * @param string   $kind  'enroll', 'rotate', 'revoke' o 'release'.
	 * @param string[] $parts Componenti nell'ordine firmato.
	 * @return string
	 */
	public static function message_signed( $kind, array $parts ) {
		return $kind . "\n" . implode( "\n", $parts );
	}

	/**
	 * Nonce casuale a 32 caratteri esadecimali, come quello generato dall'hub.
	 *
	 * @return string
	 */
	public static function new_nonce() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Mappa funzione 1.38.1 -> metodo del refactoring (tests/refactor-map.php), caricata una volta.
	 *
	 * @return array<string,string[]>
	 */
	protected static function refactor_map() {
		static $map = null;
		if ( null === $map ) {
			$map = require __DIR__ . '/refactor-map.php';
		}

		return $map;
	}

	/**
	 * Callable di una funzione del plugin: il nome della funzione se esiste ancora (plugin 1.38.1),
	 * altrimenti il metodo statico che la sostituisce secondo tests/refactor-map.php. E' l'UNICO
	 * punto di adattamento ai nomi del refactoring: i test chiamano le funzioni interne del plugin
	 * solo tramite cb() / call() (o direttamente, se la funzione non e' nella mappa).
	 *
	 * @param string $function_name Nome della funzione del plugin 1.38.1 (chiave della mappa).
	 * @return string|string[] Callable valido per call_user_func(), has_filter() e has_action().
	 */
	public static function cb( $function_name ) {
		if ( function_exists( $function_name ) ) {
			return $function_name;
		}
		$map = self::refactor_map();
		if ( isset( $map[ $function_name ] ) && is_callable( $map[ $function_name ] ) ) {
			return $map[ $function_name ];
		}

		throw new RuntimeException( "Ne' la funzione {$function_name} ne' il metodo che la sostituisce esistono: aggiornare tests/refactor-map.php." );
	}

	/**
	 * Chiama una funzione del plugin (o il metodo che la sostituisce) per nome 1.38.1.
	 *
	 * @param string $function_name Nome della funzione 1.38.1.
	 * @param mixed  ...$args       Argomenti.
	 * @return mixed
	 */
	public static function call( $function_name, ...$args ) {
		return call_user_func_array( self::cb( $function_name ), $args );
	}

	/**
	 * URL normalizzato del sito di test, ricavato dal plugin stesso: con HTTPS attivo e'
	 * es. "https://example.org". Si deriva a ogni test invece di scriverlo a mano. NB: il valore e'
	 * fissato in modo INDIPENDENTE dal plugin da test_site_url_is_the_https_test_domain().
	 *
	 * @return string
	 */
	protected function own_site_url() {
		return self::call( 'wphc_normalize_site_url' );
	}

	/**
	 * Host (con eventuale porta) del sito di test, ricavato dall'URL del plugin: serve a
	 * costruire varianti (www, schema) senza presumere un dominio fisso (wp-env usa
	 * localhost:8889, la test library da sola example.org).
	 *
	 * @return string
	 */
	protected function site_hostport() {
		$parts = wp_parse_url( $this->own_site_url() );

		return $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Costruisce il body di una busta firmata.
	 *
	 * I valori in $overrides sono applicati PRIMA della firma (quindi la firma resta valida
	 * per il valore modificato), con due eccezioni applicate DOPO: 'signature' (sostituisce la
	 * firma calcolata) e 'kid' (campo del body, non firmato direttamente).
	 * Con $omit si tolgono chiavi dal body finale (la firma resta calcolata con i valori pieni).
	 * $sign_kid sceglie la chiave di TEST che firma ('k1' envelope, 'k2' release).
	 *
	 * @param string   $type      'enroll_v1' | 'enroll_v2' | 'rotate' | 'revoke'.
	 * @param array    $overrides Campi da sovrascrivere.
	 * @param string   $sign_kid  Chiave di firma.
	 * @param string[] $omit      Chiavi da togliere dal body.
	 * @return array Body pronto per wp_json_encode().
	 */
	public function envelope( $type, array $overrides = array(), $sign_kid = 'k1', array $omit = array() ) {
		$nonce = self::new_nonce();

		switch ( $type ) {
			case 'enroll_v1':
				$fields = array_merge(
					array(
						'site_url'         => $this->own_site_url(),
						'token'            => 'tok-v1-test',
						'dashboard_origin' => null,
						'issued_at'        => time(),
					),
					$overrides
				);
				$message = self::message_enroll_v1( $fields['site_url'], $fields['token'], $fields['dashboard_origin'], $fields['issued_at'] );
				break;

			case 'enroll_v2':
				$fields = array_merge(
					array(
						'site_url'         => $this->own_site_url(),
						'token'            => 'tok-v2-' . $nonce,
						'dashboard_origin' => null,
						'nonce'            => $nonce,
						'issued_at'        => time(),
						'protocol'         => 2,
					),
					$overrides
				);
				$message = self::message_signed(
					'enroll',
					array(
						$fields['site_url'],
						$fields['token'],
						null === $fields['dashboard_origin'] ? '' : $fields['dashboard_origin'],
						$fields['nonce'],
						(string) $fields['issued_at'],
						(string) $fields['protocol'],
					)
				);
				break;

			case 'rotate':
				$fields  = array_merge(
					array(
						'site_url'  => $this->own_site_url(),
						'new_token' => 'tok-rot-' . $nonce,
						'nonce'     => $nonce,
						'issued_at' => time(),
						'protocol'  => 2,
					),
					$overrides
				);
				$message = self::message_signed( 'rotate', array( $fields['site_url'], $fields['new_token'], $fields['nonce'], (string) $fields['issued_at'], (string) $fields['protocol'] ) );
				break;

			case 'revoke':
				$fields  = array_merge(
					array(
						'site_url'  => $this->own_site_url(),
						'nonce'     => $nonce,
						'issued_at' => time(),
					),
					$overrides
				);
				$message = self::message_signed( 'revoke', array( $fields['site_url'], $fields['nonce'], (string) $fields['issued_at'] ) );
				break;

			default:
				throw new InvalidArgumentException( "Tipo di busta sconosciuto: {$type}" );
		}

		$body              = $fields;
		$body['signature'] = self::sign_message( $message, $sign_kid );

		// Eccezioni applicate dopo la firma.
		if ( array_key_exists( 'signature', $overrides ) ) {
			$body['signature'] = $overrides['signature'];
		}
		foreach ( $omit as $key ) {
			unset( $body[ $key ] );
		}

		return $body;
	}

	// -----------------------------------------------------------------------
	// Firma HMAC delle richieste operative (hub -> sito)
	// -----------------------------------------------------------------------

	/**
	 * Base64url senza padding (RFC 4648 par. 5).
	 *
	 * @param string $binary Dati binari.
	 * @return string
	 */
	public static function b64url( $binary ) {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	/**
	 * Firma HMAC di una richiesta operativa. Stringa canonica:
	 * "METODO\nROTTA\nsha256hex(body)\ntimestamp\nnonce", dove ROTTA e' il path REST senza
	 * /wp-json (es. "/health-check/v1/health"), come fa hub/services/fleet/proxy.py.
	 *
	 * @param string $secret    Segreto (token del sito).
	 * @param string $method    GET o POST.
	 * @param string $route     Rotta REST completa di namespace.
	 * @param string $body      Corpo grezzo ('' per le GET).
	 * @param string $timestamp Timestamp come stringa.
	 * @param string $nonce     Nonce.
	 * @return string Firma base64url.
	 */
	public static function hmac_signature( $secret, $method, $route, $body, $timestamp, $nonce ) {
		$canonical = $method . "\n" . $route . "\n" . hash( 'sha256', $body ) . "\n" . $timestamp . "\n" . $nonce;

		return self::b64url( hash_hmac( 'sha256', $canonical, $secret, true ) );
	}

	/**
	 * Header X-WPHC-Timestamp/Nonce/Signature per una richiesta.
	 *
	 * @param string      $secret    Segreto con cui firmare.
	 * @param string      $method    GET o POST.
	 * @param string      $route     Rotta senza namespace (es. "/health"): il namespace si aggiunge qui.
	 * @param string      $body      Corpo grezzo.
	 * @param int|null    $timestamp Timestamp (default: adesso).
	 * @param string|null $nonce     Nonce (default: nuovo).
	 * @return array<string,string> Header pronti per WP_REST_Request::set_header().
	 */
	public function signed_headers( $secret, $method, $route, $body = '', $timestamp = null, $nonce = null ) {
		$timestamp = null === $timestamp ? time() : $timestamp;
		$nonce     = null === $nonce ? self::new_nonce() : $nonce;

		return array(
			'X-WPHC-Timestamp' => (string) $timestamp,
			'X-WPHC-Nonce'     => $nonce,
			'X-WPHC-Signature' => self::hmac_signature( $secret, $method, self::NS . $route, $body, (string) $timestamp, $nonce ),
		);
	}

	// -----------------------------------------------------------------------
	// Richieste REST
	// -----------------------------------------------------------------------

	/**
	 * Esegue una richiesta REST in-process (rest_do_request) verso il namespace del plugin.
	 *
	 * @param string            $method  GET o POST.
	 * @param string            $route   Rotta senza namespace (es. "/enroll").
	 * @param array|string|null $body    Body: array (codificato in JSON), stringa grezza o null.
	 * @param array             $headers Header della richiesta.
	 * @param array             $query   Parametri di query string.
	 * @return WP_REST_Response
	 */
	public function request( $method, $route, $body = null, array $headers = array(), array $query = array() ) {
		$request = new WP_REST_Request( $method, self::NS . $route );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( null !== $body ) {
			// Content-Type JSON di default, sovrascrivibile dai $headers (es. text/plain).
			if ( ! array_key_exists( 'content-type', array_change_key_case( $headers ) ) ) {
				$request->set_header( 'content-type', 'application/json' );
			}
			$request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
		}
		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}

		return rest_do_request( $request );
	}

	/**
	 * Richiesta a una rotta dati con Bearer e, se richiesto, firma HMAC.
	 *
	 * @param string      $method Metodo.
	 * @param string      $route  Rotta senza namespace (es. "/health").
	 * @param string|null $bearer Token Bearer (null = nessun header Authorization).
	 * @param string|null $hmac   Segreto per la firma X-WPHC-* (null = nessuna firma).
	 * @param string      $body   Corpo grezzo.
	 * @return WP_REST_Response
	 */
	public function data_request( $method, $route, $bearer = null, $hmac = null, $body = '' ) {
		$headers = array();
		if ( null !== $bearer ) {
			$headers['Authorization'] = 'Bearer ' . $bearer;
		}
		if ( null !== $hmac ) {
			$headers = array_merge( $headers, $this->signed_headers( $hmac, $method, $route, $body ) );
		}

		return $this->request( $method, $route, '' === $body ? null : $body, $headers );
	}

	/**
	 * Codice d'errore WP_Error della risposta ("wphc_not_enrolled", ...), o null se non e' un errore.
	 *
	 * @param WP_REST_Response $response Risposta.
	 * @return string|null
	 */
	protected function error_code( WP_REST_Response $response ) {
		$data = $response->get_data();

		return ( $response->is_error() && is_array( $data ) && isset( $data['code'] ) ) ? $data['code'] : null;
	}

	// -----------------------------------------------------------------------
	// Stato del sito
	// -----------------------------------------------------------------------

	/**
	 * Porta il sito in stato "enrolled" scrivendo direttamente le option (scorciatoia per i
	 * test di autenticazione che non vogliono ripassare dall'enroll).
	 *
	 * @param string $token    Token corrente.
	 * @param int    $protocol 1 o 2.
	 */
	protected function seed_enrolled( $token = 'tok-test', $protocol = 1 ) {
		update_option( 'wp_health_check_token', $token, false );
		update_option( 'wp_health_check_site_url', $this->own_site_url(), false );
		update_option( 'wp_health_check_protocol', $protocol, false );
	}

	/**
	 * Enroll completo via REST (v1 o v2) con asserzione di successo.
	 *
	 * @param int   $version   1 o 2.
	 * @param array $overrides Campi della busta da sovrascrivere.
	 * @return string Token consegnato al sito.
	 */
	protected function enroll_via_rest( $version = 1, array $overrides = array() ) {
		$body     = $this->envelope( 1 === $version ? 'enroll_v1' : 'enroll_v2', $overrides );
		$response = $this->request( 'POST', '/enroll', $body );
		$this->assertSame( 200, $response->get_status(), 'Enroll di preparazione fallito: ' . wp_json_encode( $response->get_data() ) );

		return $body['token'];
	}

	/**
	 * Tutte le option "wp_health_check_*" nel database (nome => valore deserializzato),
	 * escluse quelle di schema. Legge dal DB, non dalla cache, cosi' vede cio' che e' stato scritto.
	 *
	 * @return array<string,mixed>
	 */
	protected function plugin_options() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s ORDER BY option_name", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( 'wp_health_check_' ) . '%',
				'wp_health_check_db_version'
			),
			ARRAY_A
		);

		$options = array();
		foreach ( $rows as $row ) {
			$options[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}

		return $options;
	}

	/**
	 * Confronta la risposta (e le option scritte rispetto a $options_before) con il golden
	 * tests/fixtures/golden/<nome>.json. Vedi WPHC_Golden.
	 *
	 * @param string           $name           Nome del golden.
	 * @param WP_REST_Response $response       Risposta ottenuta.
	 * @param array            $options_before Option del plugin prima della richiesta (plugin_options()).
	 */
	protected function assert_golden( $name, WP_REST_Response $response, array $options_before ) {
		WPHC_Golden::assert_matches( $this, $name, WPHC_Golden::capture( $response, $options_before, $this->plugin_options() ) );
	}

	/**
	 * Numero di fallimenti di autenticazione registrati per l'IP di test (il contatore del throttle).
	 *
	 * @return int
	 */
	protected function throttle_failures() {
		return (int) get_transient( 'wphc_fail_' . hash( 'sha1', self::CLIENT_IP ) );
	}

	/**
	 * Imposta il contatore del throttle, per arrivare alla soglia senza ripetere 10 richieste.
	 *
	 * @param int $count Numero di fallimenti.
	 */
	protected function set_throttle_failures( $count ) {
		set_transient( 'wphc_fail_' . hash( 'sha1', self::CLIENT_IP ), $count, WP_HEALTH_CHECK_RATE_WINDOW );
	}

	/**
	 * Carica i vettori di contratto generati da tests/tools/gen_contract_vectors.py.
	 *
	 * @return array
	 */
	public static function contract_vectors() {
		static $vectors = null;
		if ( null === $vectors ) {
			$raw     = file_get_contents( __DIR__ . '/fixtures/contract-vectors.json' );
			$vectors = json_decode( (string) $raw, true );
			if ( ! is_array( $vectors ) ) {
				throw new RuntimeException( 'tests/fixtures/contract-vectors.json mancante o non valido: esegui gen_contract_vectors.py' );
			}
		}

		return $vectors;
	}
}
