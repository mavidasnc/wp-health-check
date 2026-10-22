<?php
/**
 * WPHC Lite: agent minimale per i siti NON WordPress (es. Astro serviti da Apache/LiteSpeed con PHP).
 *
 * Risponde con lo stesso contratto del mu-plugin wp-health-check (src/) per le sole rotte
 * POST enroll, rotate, revoke e GET ping, health, detail/server del namespace /health-check/v1/.
 * Tutte le altre rotte rispondono 404 rest_no_route.
 *
 * Requisiti: PHP 7.4+, ext-sodium, nessuna dipendenza. Nessun WordPress: lo stato sta in file JSON
 * dentro la cartella dati (fuori dalla web root), con permessi 0600 e un flock esclusivo per tutta la richiesta.
 *
 * Nel sito il file si pubblica come public/wphc-lite/index.php (vedi README.md).
 *
 * @package WP_Health_Check_Lite
 */

// Versione dell'agent lite: l'agent_version restituito all'hub e' "lite-" + questa costante.
if ( ! defined( 'WPHC_LITE_VERSION' ) ) {
	define( 'WPHC_LITE_VERSION', '1.0.0' );
}

/**
 * Errore di storage (cartella dati non creabile o file non scrivibile): diventa 503 wphc_lite_storage.
 */
final class WPHC_Lite_Storage_Exception extends RuntimeException {
}

/**
 * Logica dell'agent lite. handle() e' pura rispetto a PHP (nessun header(), nessun echo, nessun exit):
 * riceve la richiesta e restituisce [status, headers, body] cosi' si puo' testare senza web server.
 */
final class WPHC_Lite {

	/** Chiave pubblica Ed25519 k1 del sistema centrale (buste enroll, rotate, revoke), base64 standard. */
	const DEFAULT_PUBKEY_K1 = 'uXzoP9VTpihQ1ipNUMCwITN9wKmJV/VFuYxms5Tt0CE=';

	/** Finestra di freschezza di issued_at e dei timestamp delle richieste firmate (secondi). */
	const REPLAY_WINDOW = 300;

	/** Per quanto tempo si ricorda un nonce gia' usato (secondi). */
	const NONCE_TTL = 600;

	/** Finestra di grazia del token precedente dopo una rotazione (secondi). */
	const ROTATION_GRACE = 900;

	/** Fallimenti di autenticazione tollerati per IP nella finestra, poi 429. */
	const RATE_MAX_FAILS = 10;

	/** Durata della finestra del rate limit (secondi), rinnovata a ogni fallimento. */
	const RATE_WINDOW = 300;

	/** Cache di /health (secondi). */
	const HEALTH_TTL = 60;

	/** Cache di /detail/server (12 ore). */
	const DETAIL_TTL = 43200;

	/** Cache dell'IP pubblico (7 giorni) e blocco dei ritentativi dopo un tentativo (1 giorno). */
	const PUBLIC_IP_TTL      = 604800;
	const PUBLIC_IP_LOCK_TTL = 86400;

	/** Prefisso REST delle rotte, usato nella stringa canonica della firma delle richieste. */
	const ROUTE_PREFIX = '/health-check/v1/';

	/** @var array Configurazione (site_urls, data_dir, trust_proxy, e per i test now, http_get, sodium). */
	private $config;

	/** @var string Chiave pubblica k1 in base64. */
	private $pubkey_k1;

	/** @var resource|null Handle del file di lock tenuto per tutta la richiesta. */
	private $lock = null;

	/** @var int|null Umask precedente, ripristinata a fine richiesta. */
	private $old_umask = null;

	// Contesto della richiesta corrente (impostato da handle()).
	/** @var string */
	private $method = '';
	/** @var string */
	private $route = '';
	/** @var array */
	private $headers = array();
	/** @var string */
	private $body = '';
	/** @var array */
	private $server = array();
	/** @var array */
	private $query = array();

	/**
	 * @param array  $config    site_urls (array di URL ammessi), data_dir (cartella dati), trust_proxy (bool);
	 *                          solo per i test: now (callable che restituisce il timestamp), http_get (callable
	 *                          che riceve un URL e restituisce il corpo o null), sodium (bool).
	 * @param string $pubkey_k1 Chiave pubblica k1 in base64, sovrascrivibile dai test.
	 * @throws InvalidArgumentException Se site_urls o data_dir mancano.
	 */
	public function __construct( array $config, string $pubkey_k1 = self::DEFAULT_PUBKEY_K1 ) {
		$config['site_urls'] = array_values( array_filter( (array) ( $config['site_urls'] ?? array() ), 'is_string' ) );
		if ( array() === $config['site_urls'] ) {
			throw new InvalidArgumentException( 'site_urls mancante in config.php' );
		}
		if ( ! isset( $config['data_dir'] ) || ! is_string( $config['data_dir'] ) || '' === $config['data_dir'] ) {
			throw new InvalidArgumentException( 'data_dir mancante in config.php' );
		}

		$this->config    = $config;
		$this->pubkey_k1 = $pubkey_k1;
	}

	// -----------------------------------------------------------------------
	// PUNTO DI INGRESSO
	// -----------------------------------------------------------------------

	/**
	 * Gestisce una richiesta.
	 *
	 * @param string $method  Metodo HTTP.
	 * @param string $route   Rotta relativa a /health-check/v1/ (es. "ping", "detail/server").
	 * @param array  $headers Header della richiesta (nomi in qualunque maiuscolo/minuscolo).
	 * @param string $body    Corpo grezzo.
	 * @param array  $server  Variabili in stile $_SERVER (REMOTE_ADDR, SERVER_ADDR, HTTPS, ...).
	 * @param array  $query   Parametri della query string (serve solo "fresh").
	 * @return array{0:int,1:array,2:array} Status, header di risposta, corpo (da serializzare in JSON).
	 */
	public function handle( string $method, string $route, array $headers, string $body, array $server, array $query ): array {
		$this->method  = strtoupper( $method );
		$this->route   = trim( $route, '/' );
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
		$this->server  = $server;
		$this->query   = $query;

		// 1. Rotta e metodo: solo le sei rotte note, il resto e' 404 come in WordPress.
		$handlers = array(
			'POST enroll'        => 'route_enroll',
			'POST rotate'        => 'route_rotate',
			'POST revoke'        => 'route_revoke',
			'GET ping'           => 'route_ping',
			'GET health'         => 'route_health',
			'GET detail/server'  => 'route_detail_server',
		);
		$key = $this->method . ' ' . $this->route;
		if ( ! isset( $handlers[ $key ] ) ) {
			return $this->error( 'rest_no_route', 'No route was found matching the URL and request method.', 404 );
		}

		// 2. Senza sodium non si puo' verificare nessuna firma: l'agent non e' utilizzabile.
		if ( ! $this->sodium_available() ) {
			return $this->error( 'wphc_lite_sodium_missing', 'Estensione PHP sodium mancante: agent lite non utilizzabile.', 503 );
		}

		// 3. Apre la cartella dati e prende il lock esclusivo per tutta la richiesta: chiude le corse fra
		// controllo e registrazione dei nonce e fra lettura e scrittura dei contatori.
		try {
			$this->open_storage();

			return $this->{$handlers[ $key ]}();
		} catch ( WPHC_Lite_Storage_Exception $e ) {
			return $this->error( 'wphc_lite_storage', 'Cartella dati non disponibile o non scrivibile.', 503 );
		} finally {
			$this->close_storage();
		}
	}

	/**
	 * Entry point web: legge la richiesta da PHP, chiama handle() e stampa la risposta JSON.
	 * Attivato in fondo al file solo quando il file e' eseguito direttamente (non incluso dai test).
	 *
	 * @param string $config_file Percorso di config.php (array restituito con return).
	 */
	public static function serve( string $config_file ): void {
		// Mai errori PHP a video: potrebbero rivelare percorsi del server.
		ini_set( 'display_errors', '0' );

		// Route: dal parametro "route" del rewrite, altrimenti dal path (dopo /health-check/v1/).
		if ( isset( $_GET['route'] ) && is_string( $_GET['route'] ) ) {
			$route = $_GET['route'];
		} else {
			$path  = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
			$route = preg_match( '#/health-check/v1/(.*)$#', $path, $m ) ? $m[1] : '';
		}
		$query = $_GET;
		unset( $query['route'] );

		// Header HTTP da $_SERVER (HTTP_X_WPHC_SIGNATURE diventa x-wphc-signature).
		$headers = array();
		foreach ( $_SERVER as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'HTTP_' ) && is_string( $value ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( $name, 5 ) ) ) ] = $value;
			}
		}
		// Alcuni PHP (FastCGI/LiteSpeed) passano Authorization solo come variabile di ambiente del rewrite.
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $name ) {
			if ( ! empty( $_SERVER[ $name ] ) && is_string( $_SERVER[ $name ] ) ) {
				$headers['authorization'] = $_SERVER[ $name ];
				break;
			}
		}

		// Configurazione: se manca o e' incompleta e' un errore di installazione, non un 500 muto.
		try {
			$config = is_file( $config_file ) ? include $config_file : null;
			if ( ! is_array( $config ) ) {
				throw new InvalidArgumentException( 'config.php mancante' );
			}
			$lite   = new self( $config );
			$result = $lite->handle(
				(string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ),
				$route,
				$headers,
				(string) file_get_contents( 'php://input' ),
				$_SERVER,
				$query
			);
		} catch ( InvalidArgumentException $e ) {
			$result = array( 503, array(), array( 'code' => 'wphc_lite_config', 'message' => 'Configurazione dell\'agent lite mancante o incompleta.', 'data' => array( 'status' => 503 ) ) );
		}

		// Risposta: mai in cache, sempre JSON.
		http_response_code( $result[0] );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		foreach ( $result[1] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo json_encode( $result[2], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	// -----------------------------------------------------------------------
	// PRIMITIVE PURE (statiche: usate anche dai test contro i vettori di contratto)
	// -----------------------------------------------------------------------

	/**
	 * Normalizza un URL come WPHC_Util::normalize_url(): schema e host minuscoli, porta solo se presente,
	 * path invariato senza slash finali, niente query ne' fragment. Replica wp_parse_url() + untrailingslashit().
	 *
	 * @param string $url URL grezzo.
	 * @return string URL normalizzato, o stringa vuota se non parsabile.
	 */
	public static function normalize_url( string $url ): string {
		// Come wp_parse_url(): gli URL senza schema ("//host/x") o relativi ("/x") ricevono un segnaposto
		// per far lavorare parse_url(), poi le parti segnaposto si scartano.
		$to_unset = array();
		if ( 0 === strpos( $url, '//' ) ) {
			$to_unset = array( 'scheme' );
			$url      = 'placeholder:' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$to_unset = array( 'scheme', 'host' );
			$url      = 'placeholder://placeholder' . $url;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		foreach ( $to_unset as $part ) {
			unset( $parts[ $part ] );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';

		// untrailingslashit(): toglie "/" e "\" finali.
		return rtrim( $scheme . '://' . $host . $port . $path, '/\\' );
	}

	/** Base64url senza padding (RFC 4648 par. 5), usato per la firma HMAC delle richieste. */
	public static function base64url_encode( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	/** Messaggio canonico dell'enroll v1 (formato storico, senza prefisso di dominio). */
	public static function build_enroll_signing_payload( string $site_url, string $token, ?string $dashboard_origin, int $issued_at ): string {
		return $site_url . "\n" . $token . "\n" . ( null === $dashboard_origin ? '' : $dashboard_origin ) . "\n" . $issued_at;
	}

	/** Messaggio canonico delle buste v2, rotate e revoke: prefisso di dominio, poi le parti separate da "\n". */
	public static function build_signed_payload( string $kind, array $parts ): string {
		return $kind . "\n" . implode( "\n", $parts );
	}

	/**
	 * Firma HMAC-SHA256 base64url di una richiesta operativa.
	 * Stringa canonica: METODO \n rotta REST \n sha256(corpo) \n timestamp \n nonce.
	 */
	public static function sign_request( string $secret, string $method, string $route, string $body, string $timestamp, string $nonce ): string {
		$canonical = $method . "\n" . $route . "\n" . hash( 'sha256', $body ) . "\n" . $timestamp . "\n" . $nonce;

		return self::base64url_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );
	}

	// -----------------------------------------------------------------------
	// ROTTE
	// -----------------------------------------------------------------------

	/**
	 * POST /enroll: il centro consegna il token (protocollo 1 o 2) con una busta firmata Ed25519 k1.
	 * Ordine dei controlli identico al plugin: throttle, https, corpo, campi, freschezza, replay, firma,
	 * downgrade, URL.
	 */
	private function route_enroll(): array {
		$error = $this->throttle_check() ?? $this->require_https();
		if ( null !== $error ) {
			return $error;
		}

		$body = json_decode( $this->body, true );
		if ( ! is_array( $body ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_enroll_invalid_body', 'Corpo della richiesta non valido o non JSON.', 400 );
		}

		// Campi obbligatori: il nonce solo dal protocollo 2. dashboard_origin e' sempre opzionale.
		$protocol = isset( $body['protocol'] ) ? (int) $body['protocol'] : 1;
		$required = array( 'site_url', 'token', 'issued_at', 'signature' );
		if ( $protocol >= 2 ) {
			$required[] = 'nonce';
		}
		foreach ( $required as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				$this->throttle_fail();

				return $this->error( 'wphc_enroll_missing_field', 'Campo obbligatorio mancante: ' . $field, 400 );
			}
		}

		$site_url         = self::str( $body['site_url'] );
		$token            = self::str( $body['token'] );
		$dashboard_origin = array_key_exists( 'dashboard_origin', $body ) && null !== $body['dashboard_origin'] ? self::str( $body['dashboard_origin'] ) : null;
		$issued_at        = (int) $body['issued_at'];
		$signature        = self::str( $body['signature'] );
		$kid              = self::sanitize_key( isset( $body['kid'] ) ? self::str( $body['kid'] ) : 'k1' );
		$nonce            = isset( $body['nonce'] ) ? self::str( $body['nonce'] ) : '';

		// Freschezza (anche per la v1) e, dalla v2, anti-replay del nonce.
		if ( abs( $this->now() - $issued_at ) > self::REPLAY_WINDOW ) {
			$this->throttle_fail();

			return $this->error( 'wphc_enroll_stale', 'Richiesta di enroll scaduta.', 401 );
		}
		if ( $protocol >= 2 && $this->nonce_seen( $nonce ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_enroll_replay', 'Richiesta di enroll gia\' processata.', 401 );
		}

		// Firma: la v1 ha il formato storico, la v2 prefisso "enroll", nonce e protocollo.
		$message = $protocol >= 2
			? self::build_signed_payload( 'enroll', array( $site_url, $token, null === $dashboard_origin ? '' : $dashboard_origin, $nonce, (string) $issued_at, (string) $protocol ) )
			: self::build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at );
		if ( ! $this->verify_central_signature( $message, $signature, $kid ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_enroll_unauthorized', 'Richiesta di enroll non autorizzata.', 401 );
		}

		// Niente downgrade: un sito gia' al protocollo 2 rifiuta le buste v1 (senza contare un fallimento).
		$state = $this->read( 'state' );
		if ( $protocol < 2 && (int) ( $state['protocol'] ?? 1 ) >= 2 ) {
			return $this->error( 'wphc_enroll_downgrade', 'Il sito e\' al protocollo 2: enroll v1 non ammesso.', 409 );
		}

		// L'URL firmato deve essere uno di quelli del sito (varianti www incluse).
		if ( ! in_array( self::normalize_url( $site_url ), $this->candidate_site_urls(), true ) ) {
			$canonical = $this->site_url();

			return $this->error(
				'wphc_enroll_url_mismatch',
				'URL atteso: ' . $canonical . ' — ricevuto: ' . $site_url,
				403,
				array(
					'expected' => $canonical,
					'received' => $site_url,
				)
			);
		}

		// Salva lo stato. Come nel plugin, un enroll NON azzera revoked_at (vedi README: reset manuale).
		$state['token']            = $token;
		$state['site_url']         = $site_url;
		$state['dashboard_origin'] = $dashboard_origin;
		$state['enrolled_at']      = $this->iso();
		$state['enrolled_ip']      = $this->client_ip();
		$state['kid']              = $kid;
		if ( $protocol >= 2 ) {
			$state['protocol'] = 2;
		}
		$this->write( 'state', $state );
		if ( $protocol >= 2 ) {
			// Il nonce si registra solo ora che la firma e' verificata.
			$this->remember_nonce( $nonce );
		}

		return array(
			200,
			array(),
			array(
				'enrolled'      => true,
				'site'          => $site_url,
				'agent_version' => $this->agent_version(),
			),
		);
	}

	/**
	 * POST /rotate: il centro consegna un segreto nuovo; il corrente passa in token_prev per la finestra di grazia.
	 */
	private function route_rotate(): array {
		$error = $this->throttle_check() ?? $this->require_https();
		if ( null !== $error ) {
			return $error;
		}

		$state = $this->read( 'state' );
		if ( empty( $state['token'] ) ) {
			return $this->not_enrolled();
		}

		$body = json_decode( $this->body, true );
		if ( ! is_array( $body ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_rotate_invalid_body', 'Corpo della richiesta non valido o non JSON.', 400 );
		}
		foreach ( array( 'site_url', 'new_token', 'nonce', 'issued_at', 'signature' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				$this->throttle_fail();

				return $this->error( 'wphc_rotate_missing_field', 'Campo obbligatorio mancante: ' . $field, 400 );
			}
		}

		$site_url  = self::str( $body['site_url'] );
		$new_token = self::str( $body['new_token'] );
		$nonce     = self::str( $body['nonce'] );
		$issued_at = (int) $body['issued_at'];
		$signature = self::str( $body['signature'] );
		$kid       = self::sanitize_key( isset( $body['kid'] ) ? self::str( $body['kid'] ) : 'k1' );
		$protocol  = isset( $body['protocol'] ) ? (int) $body['protocol'] : 2;

		if ( abs( $this->now() - $issued_at ) > self::REPLAY_WINDOW ) {
			$this->throttle_fail();

			return $this->error( 'wphc_rotate_stale', 'Richiesta di rotazione scaduta.', 401 );
		}
		if ( $this->nonce_seen( $nonce ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_rotate_replay', 'Richiesta di rotazione gia\' processata.', 401 );
		}

		$message = self::build_signed_payload( 'rotate', array( $site_url, $new_token, $nonce, (string) $issued_at, (string) $protocol ) );
		if ( ! $this->verify_central_signature( $message, $signature, $kid ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_rotate_unauthorized', 'Richiesta di rotazione non autorizzata.', 401 );
		}
		if ( ! in_array( self::normalize_url( $site_url ), $this->candidate_site_urls(), true ) ) {
			return $this->error( 'wphc_rotate_url_mismatch', 'URL della busta di rotazione non corrispondente a questo sito.', 403 );
		}

		$this->remember_nonce( $nonce );

		// Il segreto corrente apre la finestra di grazia, il nuovo diventa quello attivo.
		$state['token_prev']       = $state['token'];
		$state['token_rotated_at'] = $this->now();
		$state['token']            = $new_token;
		$state['protocol']         = max( 2, $protocol );
		$state['kid']              = $kid;
		$this->write( 'state', $state );

		return array(
			200,
			array(),
			array(
				'rotated'       => true,
				'site'          => $site_url,
				'protocol'      => $state['protocol'],
				'grace_seconds' => self::ROTATION_GRACE,
			),
		);
	}

	/**
	 * POST /revoke: registra revoked_at e chiude la finestra di grazia. Il token resta in archivio (come nel plugin):
	 * le rotte dati rispondono 403 wphc_revoked a prescindere dal token.
	 */
	private function route_revoke(): array {
		$error = $this->throttle_check() ?? $this->require_https();
		if ( null !== $error ) {
			return $error;
		}

		$state = $this->read( 'state' );
		if ( empty( $state['token'] ) ) {
			return $this->not_enrolled();
		}

		$body = json_decode( $this->body, true );
		if ( ! is_array( $body ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_revoke_invalid_body', 'Corpo della richiesta non valido o non JSON.', 400 );
		}
		foreach ( array( 'site_url', 'nonce', 'issued_at', 'signature' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				$this->throttle_fail();

				return $this->error( 'wphc_revoke_missing_field', 'Campo obbligatorio mancante: ' . $field, 400 );
			}
		}

		$site_url  = self::str( $body['site_url'] );
		$nonce     = self::str( $body['nonce'] );
		$issued_at = (int) $body['issued_at'];
		$signature = self::str( $body['signature'] );
		$kid       = self::sanitize_key( isset( $body['kid'] ) ? self::str( $body['kid'] ) : 'k1' );

		if ( abs( $this->now() - $issued_at ) > self::REPLAY_WINDOW ) {
			$this->throttle_fail();

			return $this->error( 'wphc_revoke_stale', 'Richiesta di revoca scaduta.', 401 );
		}
		if ( $this->nonce_seen( $nonce ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_revoke_replay', 'Richiesta di revoca gia\' processata.', 401 );
		}

		$message = self::build_signed_payload( 'revoke', array( $site_url, $nonce, (string) $issued_at ) );
		if ( ! $this->verify_central_signature( $message, $signature, $kid ) ) {
			$this->throttle_fail();

			return $this->error( 'wphc_revoke_unauthorized', 'Richiesta di revoca non autorizzata.', 401 );
		}
		if ( ! in_array( self::normalize_url( $site_url ), $this->candidate_site_urls(), true ) ) {
			return $this->error( 'wphc_revoke_url_mismatch', 'URL della busta di revoca non corrispondente a questo sito.', 403 );
		}

		$this->remember_nonce( $nonce );

		$state['revoked_at'] = $this->iso();
		unset( $state['token_prev'], $state['token_rotated_at'] );
		$this->write( 'state', $state );

		return array(
			200,
			array(),
			array(
				'revoked' => true,
				'site'    => $site_url,
			),
		);
	}

	/**
	 * GET /ping: heartbeat senza cache e senza tracciamento dell'accesso (come il plugin).
	 */
	private function route_ping(): array {
		$error = $this->require_token();
		if ( null !== $error ) {
			return $error;
		}

		return array(
			200,
			array(),
			array(
				'status'        => 'ok',
				'site'          => $this->site_url(),
				'agent_version' => $this->agent_version(),
				'generated_at'  => $this->iso(),
			),
		);
	}

	/**
	 * GET /health: sommario ridotto (niente dati WordPress), cache 60 s, ?fresh=1 per saltarla.
	 */
	private function route_health(): array {
		$error = $this->require_token();
		if ( null !== $error ) {
			return $error;
		}

		// L'accesso si traccia sempre, anche se la risposta arriva dalla cache.
		$previous = $this->record_access();

		if ( ! $this->wants_fresh() ) {
			$cached = $this->read( 'health' );
			if ( isset( $cached['payload'], $cached['expires'] ) && $cached['expires'] > $this->now() ) {
				return array( 200, array(), $cached['payload'] );
			}
		}

		$server_ip = $this->server_ip();
		$public_ip = $this->public_ip();
		$state     = $this->read( 'state' );
		$payload   = array(
			'site'                => $this->site_url(),
			'generated_at'        => $this->iso(),
			'fleet_agent_version' => $this->agent_version(),
			'summary'             => array(
				'php_version'          => PHP_VERSION,
				'php_memory_limit'     => (string) ini_get( 'memory_limit' ),
				'server_ip'            => '' !== $server_ip ? $server_ip : null,
				'server_ip_is_private' => '' !== $server_ip && ! self::ip_is_public( $server_ip ),
				'public_ip'            => '' !== $public_ip ? $public_ip : null,
				'plugin_version'       => $this->agent_version(),
			),
			'last_access'         => array(
				'at'          => $previous['at'],
				'ip'          => $previous['ip'],
				'enrolled_at' => ! empty( $state['enrolled_at'] ) ? $state['enrolled_at'] : null,
			),
			'detail_routes'       => array(
				'server' => $this->site_url() . '/wp-json/health-check/v1/detail/server',
			),
		);

		$this->write( 'health', array( 'expires' => $this->now() + self::HEALTH_TTL, 'payload' => $payload ) );

		return array( 200, array(), $payload );
	}

	/**
	 * GET /detail/server: dati del server, cache 12 ore, ?fresh=1 per saltarla.
	 */
	private function route_detail_server(): array {
		$error = $this->require_token();
		if ( null !== $error ) {
			return $error;
		}

		$this->record_access();

		if ( ! $this->wants_fresh() ) {
			$cached = $this->read( 'detail-server' );
			if ( isset( $cached['payload'], $cached['expires'] ) && $cached['expires'] > $this->now() ) {
				return array( 200, array(), $cached['payload'] );
			}
		}

		$server_ip = $this->server_ip();
		$public_ip = $this->public_ip();
		$payload   = array(
			'site'         => $this->site_url(),
			'generated_at' => $this->iso(),
			'server'       => array(
				'software'             => isset( $this->server['SERVER_SOFTWARE'] ) ? self::str( $this->server['SERVER_SOFTWARE'] ) : '',
				'server_ip'            => '' !== $server_ip ? $server_ip : null,
				'server_ip_is_private' => '' !== $server_ip && ! self::ip_is_public( $server_ip ),
				'public_ip'            => '' !== $public_ip ? $public_ip : null,
				'php_version'          => PHP_VERSION,
				'php_sapi'             => PHP_SAPI,
				'php_memory_limit'     => (string) ini_get( 'memory_limit' ),
				'max_execution_time'   => (string) ini_get( 'max_execution_time' ),
				'max_input_vars'       => (string) ini_get( 'max_input_vars' ),
				'upload_max_filesize'  => (string) ini_get( 'upload_max_filesize' ),
				'post_max_size'        => (string) ini_get( 'post_max_size' ),
				'mysql_version'        => '',
				'https'                => $this->is_ssl(),
				'extensions'           => array(
					'curl'     => extension_loaded( 'curl' ),
					'imagick'  => extension_loaded( 'imagick' ),
					'gd'       => extension_loaded( 'gd' ),
					'mbstring' => extension_loaded( 'mbstring' ),
					'intl'     => extension_loaded( 'intl' ),
				),
			),
		);

		$this->write( 'detail-server', array( 'expires' => $this->now() + self::DETAIL_TTL, 'payload' => $payload ) );

		return array( 200, array(), $payload );
	}

	// -----------------------------------------------------------------------
	// AUTENTICAZIONE DELLE ROTTE DATI
	// -----------------------------------------------------------------------

	/**
	 * Equivalente di WPHC_Auth::require_token(): https, enroll, revoca, throttle, Bearer, finestra di grazia,
	 * firma v2. L'autenticazione si valuta UNA sola volta per richiesta: ogni richiesta valida lascia il
	 * contatore dei fallimenti a zero (il plugin invece, per la doppia valutazione del permission_callback,
	 * conta come fallimento la rivalutazione di una richiesta firmata: qui non si replica).
	 *
	 * @return array|null Risposta d'errore, oppure null se la richiesta e' autorizzata.
	 */
	private function require_token(): ?array {
		$error = $this->require_https();
		if ( null !== $error ) {
			return $error;
		}

		$state  = $this->read( 'state' );
		$stored = $state['token'] ?? '';
		if ( empty( $stored ) ) {
			return $this->not_enrolled();
		}

		if ( ! empty( $state['revoked_at'] ) ) {
			return $this->error( 'wphc_revoked', 'Segreto revocato dal sistema centrale.', 403 );
		}

		$error = $this->throttle_check();
		if ( null !== $error ) {
			return $error;
		}

		// Bearer (schema case-insensitive). Header mancante e token errato danno lo stesso errore.
		$auth = isset( $this->headers['authorization'] ) ? self::str( $this->headers['authorization'] ) : '';
		if ( empty( $auth ) || 0 !== stripos( $auth, 'Bearer ' ) ) {
			return $this->unauthorized();
		}
		$provided = trim( substr( $auth, 7 ) );

		// Confronto in tempo costante: prima il token corrente, poi il precedente dentro la finestra di grazia.
		$prev       = $state['token_prev'] ?? '';
		$rotated_at = (int) ( $state['token_rotated_at'] ?? 0 );
		$using_prev = false;
		if ( hash_equals( (string) $stored, $provided ) ) {
			$using_prev = false;
		} elseif ( ! empty( $prev ) && $rotated_at > 0 && ( $this->now() - $rotated_at ) <= self::ROTATION_GRACE && hash_equals( (string) $prev, $provided ) ) {
			$using_prev = true;
		} else {
			return $this->unauthorized();
		}

		// Firma della richiesta: obbligatoria dal protocollo 2, verificata anche prima se l'header e' presente,
		// con il segreto che ha fatto match.
		if ( (int) ( $state['protocol'] ?? 1 ) >= 2 || ! empty( $this->headers['x-wphc-signature'] ) ) {
			if ( ! $this->verify_request_signature( (string) ( $using_prev ? $prev : $stored ) ) ) {
				return $this->unauthorized();
			}
		}

		// Primo uso riuscito del segreto NUOVO dopo una rotazione: il precedente decade subito.
		if ( ! $using_prev && ! empty( $prev ) ) {
			unset( $state['token_prev'], $state['token_rotated_at'] );
			$this->write( 'state', $state );
		}

		return null;
	}

	/**
	 * Verifica la firma anti-replay di una richiesta (header X-WPHC-Timestamp, Nonce, Signature).
	 * Il nonce si registra solo dopo che la firma e' valida.
	 */
	private function verify_request_signature( string $secret ): bool {
		$timestamp = isset( $this->headers['x-wphc-timestamp'] ) ? self::str( $this->headers['x-wphc-timestamp'] ) : '';
		$nonce     = isset( $this->headers['x-wphc-nonce'] ) ? self::str( $this->headers['x-wphc-nonce'] ) : '';
		$signature = isset( $this->headers['x-wphc-signature'] ) ? self::str( $this->headers['x-wphc-signature'] ) : '';

		if ( empty( $timestamp ) || empty( $nonce ) || empty( $signature ) ) {
			return false;
		}
		if ( abs( $this->now() - (int) $timestamp ) > self::REPLAY_WINDOW ) {
			return false;
		}
		if ( $this->nonce_seen( $nonce ) ) {
			return false;
		}

		$expected = self::sign_request( $secret, $this->method, self::ROUTE_PREFIX . $this->route, $this->body, $timestamp, $nonce );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		$this->remember_nonce( $nonce );

		return true;
	}

	/**
	 * Verifica una firma Ed25519 k1 (solo buste): base64 standard strict, lunghezze 32/64, fail-closed.
	 */
	private function verify_central_signature( string $message, string $signature_b64, string $kid ): bool {
		if ( 'k1' !== $kid || '' === $this->pubkey_k1 ) {
			return false;
		}

		$pubkey_raw    = base64_decode( $this->pubkey_k1, true );
		$signature_raw = base64_decode( $signature_b64, true );
		if (
			false === $pubkey_raw || false === $signature_raw
			|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pubkey_raw )
			|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature_raw )
		) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature_raw, $message, $pubkey_raw );
		} catch ( SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Registra l'accesso autenticato corrente e restituisce quello PRECEDENTE (audit mostrato da /health).
	 *
	 * @return array{at: string|null, ip: string|null}
	 */
	private function record_access(): array {
		$state    = $this->read( 'state' );
		$previous = array(
			'at' => ! empty( $state['last_request_at'] ) ? $state['last_request_at'] : null,
			'ip' => ! empty( $state['last_request_ip'] ) ? $state['last_request_ip'] : null,
		);

		$state['last_request_at'] = $this->iso();
		$state['last_request_ip'] = $this->client_ip();
		$this->write( 'state', $state );

		return $previous;
	}

	// -----------------------------------------------------------------------
	// GUARDIE: HTTPS, NONCE, RATE LIMIT
	// -----------------------------------------------------------------------

	/**
	 * HTTPS obbligatorio. Con trust_proxy conta X-Forwarded-Proto, valore piu' a destra.
	 *
	 * @return array|null Risposta 403 o null.
	 */
	private function require_https(): ?array {
		if ( $this->is_ssl() ) {
			return null;
		}

		if ( ! empty( $this->config['trust_proxy'] ) && ! empty( $this->server['HTTP_X_FORWARDED_PROTO'] ) ) {
			$protos = array_map( 'trim', explode( ',', self::str( $this->server['HTTP_X_FORWARDED_PROTO'] ) ) );
			if ( 'https' === strtolower( (string) end( $protos ) ) ) {
				return null;
			}
		}

		return $this->error( 'wphc_https_required', 'HTTPS obbligatorio per questa rotta.', 403 );
	}

	/** Come is_ssl() di WordPress, sulle variabili $server della richiesta. */
	private function is_ssl(): bool {
		if ( isset( $this->server['HTTPS'] ) ) {
			$https = self::str( $this->server['HTTPS'] );

			return 'on' === strtolower( $https ) || '1' === $https;
		}

		return isset( $this->server['SERVER_PORT'] ) && '443' === self::str( $this->server['SERVER_PORT'] );
	}

	/** True se il nonce e' gia' registrato (e non scaduto). Sola lettura. */
	private function nonce_seen( string $nonce ): bool {
		$nonces = $this->read( 'nonces' );
		$key    = hash( 'sha256', $nonce );

		return isset( $nonces[ $key ] ) && $nonces[ $key ] > $this->now();
	}

	/** Registra un nonce per NONCE_TTL secondi (chiave sha256, mai in chiaro) e ripulisce i scaduti. */
	private function remember_nonce( string $nonce ): void {
		$now    = $this->now();
		$nonces = array_filter(
			$this->read( 'nonces' ),
			static function ( $expires ) use ( $now ) {
				return $expires > $now;
			}
		);

		$nonces[ hash( 'sha256', $nonce ) ] = $now + self::NONCE_TTL;
		$this->write( 'nonces', $nonces );
	}

	/**
	 * 429 se l'IP ha raggiunto la soglia di fallimenti nella finestra.
	 *
	 * @return array|null Risposta 429 o null.
	 */
	private function throttle_check(): ?array {
		$rate  = $this->read( 'rate' );
		$entry = $rate[ hash( 'sha1', $this->client_ip() ) ] ?? null;

		if ( is_array( $entry ) && $entry['expires'] > $this->now() && $entry['count'] >= self::RATE_MAX_FAILS ) {
			return $this->error( 'wphc_rate_limited', 'Troppi tentativi falliti: riprova piu\' tardi.', 429, array(), array( 'Retry-After' => (string) self::RATE_WINDOW ) );
		}

		return null;
	}

	/** Conta un fallimento per l'IP corrente e rinnova la finestra; ripulisce le voci scadute. */
	private function throttle_fail(): void {
		$now  = $this->now();
		$rate = array_filter(
			$this->read( 'rate' ),
			static function ( $entry ) use ( $now ) {
				return is_array( $entry ) && $entry['expires'] > $now;
			}
		);

		$key           = hash( 'sha1', $this->client_ip() );
		$count         = isset( $rate[ $key ] ) ? (int) $rate[ $key ]['count'] : 0;
		$rate[ $key ]  = array(
			'count'   => $count + 1,
			'expires' => $now + self::RATE_WINDOW,
		);
		$this->write( 'rate', $rate );
	}

	// -----------------------------------------------------------------------
	// IP, URL, ORA
	// -----------------------------------------------------------------------

	/** IP del chiamante: REMOTE_ADDR, oppure con trust_proxy l'ultimo IP valido di X-Forwarded-For (il piu' a destra). */
	private function client_ip(): string {
		if ( ! empty( $this->config['trust_proxy'] ) && ! empty( $this->server['HTTP_X_FORWARDED_FOR'] ) ) {
			$candidates = array_reverse( array_map( 'trim', explode( ',', self::str( $this->server['HTTP_X_FORWARDED_FOR'] ) ) ) );
			foreach ( $candidates as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		$remote = isset( $this->server['REMOTE_ADDR'] ) ? self::str( $this->server['REMOTE_ADDR'] ) : '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/** IP del server (SERVER_ADDR), stringa vuota se assente o non valido. */
	private function server_ip(): string {
		$addr = isset( $this->server['SERVER_ADDR'] ) ? self::str( $this->server['SERVER_ADDR'] ) : '';

		return filter_var( $addr, FILTER_VALIDATE_IP ) ? $addr : '';
	}

	/** True se l'IP e' pubblico (non privato, non riservato). */
	private static function ip_is_public( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * IP pubblico di uscita: SERVER_ADDR se e' gia' pubblico; altrimenti cache 7 giorni; altrimenti, con un lock
	 * di 1 giorno impostato PRIMA del tentativo (niente retry-loop), una chiamata a api.ipify.org.
	 */
	private function public_ip(): string {
		$server_ip = $this->server_ip();
		if ( '' !== $server_ip && self::ip_is_public( $server_ip ) ) {
			return $server_ip;
		}

		$now   = $this->now();
		$cache = $this->read( 'public-ip' );
		if ( ! empty( $cache['value'] ) && isset( $cache['expires'] ) && $cache['expires'] > $now ) {
			return (string) $cache['value'];
		}
		if ( isset( $cache['lock_until'] ) && $cache['lock_until'] > $now ) {
			return '';
		}

		// Il lock resta anche se il servizio esterno fallisce.
		$cache['lock_until'] = $now + self::PUBLIC_IP_LOCK_TTL;
		$this->write( 'public-ip', $cache );

		$http_get = $this->config['http_get'] ?? array( $this, 'http_get' );
		$body     = trim( (string) call_user_func( $http_get, 'https://api.ipify.org' ) );
		if ( '' === $body || ! self::ip_is_public( $body ) ) {
			return '';
		}

		$cache['value']   = $body;
		$cache['expires'] = $now + self::PUBLIC_IP_TTL;
		$this->write( 'public-ip', $cache );

		return $body;
	}

	/**
	 * GET semplice con timeout di 5 secondi (curl, altrimenti stream).
	 *
	 * @return string|null Corpo se status 200, altrimenti null.
	 */
	private function http_get( string $url ): ?string {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5 ) );
			$response = curl_exec( $ch );
			$code     = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );

			return ( false !== $response && 200 === $code ) ? (string) $response : null;
		}

		$response = @file_get_contents( $url, false, stream_context_create( array( 'http' => array( 'timeout' => 5 ) ) ) );

		return false !== $response ? $response : null;
	}

	/** URL normalizzato del sito: il primo di site_urls (mai dedotto da Host). */
	private function site_url(): string {
		return self::normalize_url( $this->config['site_urls'][0] );
	}

	/** URL candidati per enroll, rotate e revoke: site_urls + varianti con/senza "www.". */
	private function candidate_site_urls(): array {
		$candidates = array();
		foreach ( $this->config['site_urls'] as $url ) {
			$parts = parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}

			$host     = strtolower( $parts['host'] );
			$alt_host = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : 'www.' . $host;
			$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
			$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			$path     = isset( $parts['path'] ) ? $parts['path'] : '';

			foreach ( array( $host, $alt_host ) as $variant_host ) {
				$normalized = self::normalize_url( $scheme . '://' . $variant_host . $port . $path );
				if ( '' !== $normalized ) {
					$candidates[ $normalized ] = true;
				}
			}
		}

		return array_keys( $candidates );
	}

	/** Timestamp corrente: unico punto di accesso all'ora, iniettabile dai test. */
	private function now(): int {
		return isset( $this->config['now'] ) ? (int) call_user_func( $this->config['now'] ) : time();
	}

	/** Data ISO 8601 UTC dell'ora corrente (come gmdate('c')). */
	private function iso(): string {
		return gmdate( 'c', $this->now() );
	}

	/** Versione restituita all'hub, es. "lite-1.0.0". */
	private function agent_version(): string {
		return 'lite-' . WPHC_LITE_VERSION;
	}

	/** True se il chiamante chiede ?fresh=1. */
	private function wants_fresh(): bool {
		return isset( $this->query['fresh'] ) && is_scalar( $this->query['fresh'] ) && '1' === (string) $this->query['fresh'];
	}

	/** ext-sodium disponibile (sovrascrivibile da config solo nei test). */
	private function sodium_available(): bool {
		return isset( $this->config['sodium'] ) ? (bool) $this->config['sodium'] : function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	// -----------------------------------------------------------------------
	// RISPOSTE
	// -----------------------------------------------------------------------

	/** Errore nella forma del plugin: {code, message, data:{status}}. */
	private function error( string $code, string $message, int $status, array $data = array(), array $headers = array() ): array {
		return array(
			$status,
			$headers,
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array_merge( array( 'status' => $status ), $data ),
			),
		);
	}

	/** 503 sito non ancora enrollato. */
	private function not_enrolled(): array {
		return $this->error( 'wphc_not_enrolled', 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 503 );
	}

	/** 401 generico: conta un fallimento nel rate limit (una volta per richiesta). */
	private function unauthorized(): array {
		$this->throttle_fail();

		return $this->error( 'wphc_unauthorized', 'Non autorizzato.', 401 );
	}

	/** Stringa da un valore scalare del JSON (gli array diventano stringa vuota). */
	private static function str( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Equivalente di sanitize_key() di WordPress: minuscolo, solo [a-z0-9_-]. */
	private static function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}

	// -----------------------------------------------------------------------
	// STORAGE SU FILE
	// -----------------------------------------------------------------------

	/**
	 * Crea la cartella dati se manca (solo se il genitore e' scrivibile) e prende il lock esclusivo.
	 *
	 * @throws WPHC_Lite_Storage_Exception Se la cartella o il lock non sono disponibili.
	 */
	private function open_storage(): void {
		$dir = $this->config['data_dir'];

		// Umask restrittiva per tutta la richiesta: nessun file (nemmeno il temporaneo) e' mai leggibile da altri utenti.
		$this->old_umask = umask( 0077 );

		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new WPHC_Lite_Storage_Exception( 'data_dir non creabile' );
		}

		$lock = @fopen( $dir . '/lock', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new WPHC_Lite_Storage_Exception( 'lock non ottenibile' );
		}
		@chmod( $dir . '/lock', 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$this->lock = $lock;
	}

	/** Rilascia il lock (anche dopo un'eccezione). */
	private function close_storage(): void {
		if ( is_resource( $this->lock ) ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
		}
		$this->lock = null;

		if ( null !== $this->old_umask ) {
			umask( $this->old_umask );
			$this->old_umask = null;
		}
	}

	/** Legge un file JSON della cartella dati; assente o illeggibile e' un array vuoto. */
	private function read( string $name ): array {
		$path = $this->config['data_dir'] . '/' . $name . '.json';
		if ( ! is_file( $path ) ) {
			return array();
		}

		$data = json_decode( (string) @file_get_contents( $path ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Scrive un file JSON in modo atomico (file temporaneo + rename) con permessi 0600.
	 *
	 * @throws WPHC_Lite_Storage_Exception Se la scrittura fallisce.
	 */
	private function write( string $name, array $data ): void {
		$path = $this->config['data_dir'] . '/' . $name . '.json';
		$tmp  = $path . '.tmp';

		if ( false === @file_put_contents( $tmp, json_encode( $data ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new WPHC_Lite_Storage_Exception( 'scrittura fallita: ' . $name );
		}
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new WPHC_Lite_Storage_Exception( 'rename fallito: ' . $name );
		}
	}
}

// Front controller: attivo solo quando il file e' eseguito come entry point (public/wphc-lite/index.php),
// mai quando e' incluso da un altro script (test).
if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	WPHC_Lite::serve( __DIR__ . '/config.php' );
}
