<?php
/**
 * Plugin Name: WP Health Check (Fleet Agent)
 * Description: Must-use plugin di monitoraggio per una flotta di siti WordPress, con enroll firmato, endpoint REST protetti da token e self-update firmato dalle release di un repository GitHub pubblico.
 * Version:     1.8.0
 * Author:      MAVIDA
 * Author URI:  https://mavida.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-health-check
 *
 * @package WP_Health_Check
 *
 * ARCHITETTURA (perche' il file e' fatto cosi')
 * -----------------------------------------------------------------------
 * Questo file viene installato una sola volta a mano (SFTP/SSH) in
 * wp-content/mu-plugins/wp-health-check.php e da quel momento e' identico
 * per TUTTI i siti della flotta: WordPress carica automaticamente solo i
 * .php nella radice di mu-plugins, quindi deve restare un file singolo e
 * autoconsistente, senza autoload o dipendenze esterne a runtime.
 *
 * Vincolo architetturale fondamentale: il file NON scrive mai in
 * wp-config.php (nessun accesso SSH/SFTP ripetuto per configurare un
 * sito). Le costanti qui sotto sono valori NON segreti, hardcoded e
 * identici per tutta la flotta (sono distribuiti via GitHub, quindi
 * pubblici de facto). Tutto cio' che e' specifico del singolo sito
 * (token, IP dell'ultimo accesso, origin della dashboard...) vive nelle
 * wp_options di quel sito. Nessun segreto risiede mai sul sito: la
 * chiave incorporata qui e' una chiave PUBBLICA Ed25519, sicura da
 * versionare perche' serve solo a verificare firme, non a produrle.
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------
// COSTANTI DI FLOTTA (identiche su tutti i siti, non segrete)
// -----------------------------------------------------------------------

/**
 * Versione dell'agent. E' il perno del confronto con la release piu'
 * recente su GitHub (self-update) e deve combaciare con la riga
 * "Version:" dell'header del plugin qui sopra: il flusso di update
 * verifica che il file scaricato dichiari la stessa versione del tag
 * della release, come prova aggiuntiva di integrita'.
 */
if ( ! defined( 'WP_HEALTH_CHECK_VERSION' ) ) {
	define( 'WP_HEALTH_CHECK_VERSION', '1.8.0' );
}

/** Coordinate del repository GitHub pubblico da cui arrivano le release. */
if ( ! defined( 'WP_HEALTH_CHECK_GH_OWNER' ) ) {
	define( 'WP_HEALTH_CHECK_GH_OWNER', 'mavidasnc' );
}
if ( ! defined( 'WP_HEALTH_CHECK_GH_REPO' ) ) {
	define( 'WP_HEALTH_CHECK_GH_REPO', 'wp-health-check' );
}

/**
 * Chiave pubblica Ed25519 del sistema centrale, in base64 standard.
 * Usata SOLO per verificare le firme delle buste di /enroll: e' una
 * chiave pubblica, quindi puo' essere incorporata e versionata senza
 * rischio (la chiave privata resta esclusivamente lato centro).
 *
 * NOTA OPERATIVA: sostituire il placeholder con la chiave reale generata
 * dal sistema centrale (vedi bin/generate-keys.php) prima del primo
 * deploy: con il placeholder vuoto ogni /enroll fallira' la verifica
 * firma (comportamento sicuro "fail closed", non "fail open").
 */
if ( ! defined( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY' ) ) {
	define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY', 'uXzoP9VTpihQ1ipNUMCwITN9wKmJV/VFuYxms5Tt0CE=' );
}

// -----------------------------------------------------------------------
// UTILITY CONDIVISE
// -----------------------------------------------------------------------

/**
 * Calcola l'URL normalizzato del sito, usato sia come identificativo
 * "site" nelle risposte sia come materiale su cui il centro calcola
 * l'HMAC del token. Deve essere BYTE PER BYTE identico a quello che
 * ricalcola il centro, quindi la normalizzazione e' minima e deterministica:
 * schema e host in minuscolo, niente slash finale. Vedi README.md per
 * l'esempio numerico completo URL -> token.
 *
 * @return string URL normalizzato (es. "https://esempio.com/blog").
 */
function wphc_normalize_site_url() {
	$home  = home_url();
	$parts = wp_parse_url( $home );

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
	$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$path   = isset( $parts['path'] ) ? $parts['path'] : '';

	return untrailingslashit( $scheme . '://' . $host . $port . $path );
}

/**
 * Determina l'IP del chiamante. Per default legge REMOTE_ADDR (l'unico
 * dato affidabile senza un proxy davanti a PHP). Se l'opzione
 * wp_health_check_trust_proxy e' attiva, legge invece il primo IP
 * valido di X-Forwarded-For.
 *
 * ATTENZIONE (documentato anche in README): X-Forwarded-For e' un header
 * HTTP fornito dal CLIENT e quindi falsificabile a piacere. E' attendibile
 * SOLO se davanti a PHP c'e' un proxy/load balancer fidato che lo
 * sovrascrive sempre (CDN, reverse proxy aziendale). Va attivato
 * consapevolmente, non di default.
 *
 * @return string IP valido, oppure stringa vuota se non determinabile.
 */
function wphc_get_client_ip() {
	$trust_proxy = (bool) get_option( 'wp_health_check_trust_proxy', false );

	if ( $trust_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$xff        = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$candidates = array_map( 'trim', explode( ',', $xff ) );
		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
	}

	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
}

/**
 * Determina l'IP del server WordPress (la macchina che esegue PHP), letto
 * da SERVER_ADDR. E' l'indirizzo su cui il web server ha accettato la
 * connessione: dietro un reverse proxy / load balancer puo' essere l'IP
 * interno del backend e non quello pubblico del sito, e su alcuni SAPI puo'
 * non essere impostato affatto (in quel caso si restituisce stringa vuota).
 * Non e' un dato di sicurezza, solo informativo per la dashboard.
 *
 * @return string IP valido del server, oppure stringa vuota se non determinabile.
 */
function wphc_get_server_ip() {
	$server_addr = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

	return filter_var( $server_addr, FILTER_VALIDATE_IP ) ? $server_addr : '';
}

/**
 * Legge il flag ?fresh=1, l'unico modo previsto per forzare un refresh
 * (bypassando cache/transient) su /health e sulle rotte /detail.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return bool True se il chiamante ha chiesto esplicitamente dati freschi.
 */
function wphc_request_wants_fresh( WP_REST_Request $request ) {
	return '1' === (string) $request->get_param( 'fresh' );
}

/**
 * Registra l'accesso corrente autenticato e restituisce il valore
 * PRECEDENTE (prima della sovrascrittura). L'ordine e' importante: va
 * letto il vecchio valore prima di aggiornarlo, cosi' /health puo'
 * esporre l'accesso precedente come segnale di audit ("chi ha chiamato
 * l'ultima volta, prima di questa chiamata").
 *
 * Va invocata come primissimo passo di ogni rotta autenticata con
 * successo (health, detail/*, update), PRIMA di qualunque controllo di
 * cache: il tracciamento accessi deve avvenire ad ogni richiesta reale,
 * indipendentemente dal fatto che il corpo della risposta sia servito
 * da cache o ricalcolato.
 *
 * @return array{at: string|null, ip: string|null} Timestamp/IP dell'accesso precedente.
 */
function wphc_record_access() {
	$previous_at = get_option( 'wp_health_check_last_request_at' );
	$previous_ip = get_option( 'wp_health_check_last_request_ip' );

	update_option( 'wp_health_check_last_request_at', gmdate( 'c' ), false );
	update_option( 'wp_health_check_last_request_ip', wphc_get_client_ip(), false );

	return array(
		'at' => $previous_at ? $previous_at : null,
		'ip' => $previous_ip ? $previous_ip : null,
	);
}

/**
 * Costruisce il messaggio canonico su cui il centro appone la firma
 * Ed25519 per /enroll, e su cui il sito la verifica. La concatenazione e'
 * fissa e ordinata, con "\n" come separatore. dashboard_origin puo'
 * essere null (dashboard non ancora configurata): in quel caso il suo
 * "posto" nella concatenazione e' una stringa vuota, non la stringa
 * letterale "null" (scelta arbitraria ma univoca, documentata in
 * README affinche' il centro la replichi esattamente).
 *
 * @param string      $site_url         URL normalizzato del sito target.
 * @param string      $token            Token opaco assegnato dal centro.
 * @param string|null $dashboard_origin Origin della dashboard, o null.
 * @param int         $issued_at        Timestamp Unix di emissione della busta.
 * @return string Messaggio canonico da firmare/verificare.
 */
function wphc_build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at ) {
	$origin_component = null === $dashboard_origin ? '' : (string) $dashboard_origin;

	return $site_url . "\n" . $token . "\n" . $origin_component . "\n" . (string) $issued_at;
}

/**
 * Cancella tutte le opzioni di enrollment di questo sito. Condivisa dal
 * comando WP-CLI "wp health-check reset" e dal pulsante di reset nella tab
 * Site Health: un'unica lista di opzioni, per evitare che le due strade
 * finiscano per disallinearsi nel tempo.
 */
function wphc_reset_enrollment() {
	$options = array(
		'wp_health_check_token',
		'wp_health_check_dashboard_origin',
		'wp_health_check_enrolled_at',
		'wp_health_check_enrolled_ip',
		'wp_health_check_enroll_issued_at',
		'wp_health_check_last_request_at',
		'wp_health_check_last_request_ip',
	);
	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}
}

/**
 * Verifica che una stringa sia un origin HTTP(S) valido: schema http/https,
 * host presente, nessun path/query/frammento (un origin non li contempla).
 * Usata per validare il valore inserito manualmente nella tab Site Health,
 * prima di salvarlo in wp_health_check_dashboard_origin.
 *
 * @param string $value Valore da validare.
 * @return bool True se e' un origin valido.
 */
function wphc_is_valid_origin( $value ) {
	$parts = wp_parse_url( $value );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}

	return empty( $parts['path'] ) && empty( $parts['query'] ) && empty( $parts['fragment'] );
}

// -----------------------------------------------------------------------
// CORS
// -----------------------------------------------------------------------

/**
 * Invia gli header CORS. Se wp_health_check_dashboard_origin e' configurata,
 * SOLO quell'origin esatta viene autorizzata (comportamento originale). Se
 * e' vuota (dashboard non ancora registrata via /enroll, o resettata con
 * wp health-check reset), viene autorizzata qualunque origin che invia la
 * richiesta: e' una scelta deliberata per non bloccare le chiamate in fase
 * di setup/sviluppo prima che una dashboard_origin sia stata configurata.
 * Non viene mai inviato il wildcard letterale "*": l'origin della richiesta
 * viene sempre riflessa nell'header, cosi' il comportamento resta corretto
 * anche se in futuro le richieste iniziassero a includere credenziali.
 * L'autenticazione resta comunque affidata al bearer token o alla firma
 * Ed25519 (vedi wphc_require_token()): CORS qui e' difesa in profondita'
 * aggiuntiva, non il controllo di accesso primario.
 *
 * IMPORTANTE #1 (cache): dice ANCHE a WordPress (e ai plugin di page-cache
 * come LiteSpeed Cache, WP Super Cache, W3TC, WP Rocket...) di non mettere
 * mai in cache questa risposta. Senza questo, una cache condivisa lato
 * server puo' salvare la risposta di UN chiamante (con il SUO Origin, o
 * senza Origin affatto) e riservirla identica a chiunque altro, ignorando
 * sia l'Origin reale del richiedente sia il bearer token: e' esattamente
 * la causa di risposte CORS incoerenti osservate in produzione dietro
 * LiteSpeed Cache. La cache "propria" del plugin resta quella via
 * transient nelle singole rotte (vedi Caching per-rotta in README.md), non
 * influenzata da questo.
 *
 * IMPORTANTE #2 (default del core): WordPress core registra di serie
 * rest_send_cors_headers() sul filtro rest_pre_serve_request, che riflette
 * QUALUNQUE Origin con Access-Control-Allow-Credentials: true, per l'intera
 * REST API. Quel filtro gira DOPO il dispatch della rotta (quindi dopo la
 * prima chiamata a questa funzione), e header() di PHP sostituisce di
 * default un header con lo stesso nome: senza rimuovere prima gli header
 * eventualmente gia' impostati dal core, la restrizione su
 * wp_health_check_dashboard_origin sarebbe vanificata. Per questo la
 * funzione va richiamata una seconda volta, con priorita' piu' alta, su
 * rest_pre_serve_request stesso: vedi wphc_reassert_cors_headers().
 */
function wphc_maybe_send_cors_headers() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		// Nome imposto dalla convenzione condivisa fra i plugin di
		// page-cache (LiteSpeed Cache, WP Super Cache, W3TC, WP Rocket...):
		// non puo' avere il prefisso wphc_/WP_HEALTH_CHECK, o quei plugin
		// non lo riconoscerebbero piu'.
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	}
	nocache_headers();

	// Rimuove eventuali header CORS gia' impostati (tipicamente dal
	// rest_send_cors_headers() di default del core, vedi sopra): questa
	// funzione deve avere sempre l'ultima parola su questi header.
	header_remove( 'Access-Control-Allow-Origin' );
	header_remove( 'Access-Control-Allow-Credentials' );
	header_remove( 'Access-Control-Allow-Methods' );
	header_remove( 'Access-Control-Allow-Headers' );
	header_remove( 'Access-Control-Expose-Headers' );

	$request_origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	if ( '' === $request_origin ) {
		return;
	}

	$configured_origin = get_option( 'wp_health_check_dashboard_origin' );
	if ( ! empty( $configured_origin ) && $request_origin !== $configured_origin ) {
		return;
	}

	header( 'Access-Control-Allow-Origin: ' . $request_origin );
	header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
	// Vary: Origin evita che una cache intermedia (CDN/proxy) serva la
	// risposta CORS di un'origin ad un'altra origin diversa.
	header( 'Vary: Origin' );
}

/**
 * Riapplica wphc_maybe_send_cors_headers() su rest_pre_serve_request, con
 * priorita' piu' alta del rest_send_cors_headers() di default del core
 * (priorita' 10): senza questo, il comportamento permissivo del core
 * (qualunque Origin, con credenziali) vincerebbe sempre sulle regole di
 * wp_health_check_dashboard_origin, perche' gira dopo il dispatch della
 * rotta. Limitata al solo namespace health-check/v1, per non interferire
 * con le altre rotte REST del sito.
 *
 * @param bool            $served  Valore corrente del filtro (non alterato).
 * @param mixed           $result  Risultato della richiesta (non usato).
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return bool Il valore $served invariato.
 */
function wphc_reassert_cors_headers( $served, $result, $request ) {
	unset( $result );

	if ( 0 !== strpos( $request->get_route(), '/health-check/v1' ) ) {
		return $served;
	}

	wphc_maybe_send_cors_headers();

	return $served;
}
add_filter( 'rest_pre_serve_request', 'wphc_reassert_cors_headers', 20, 3 );

/**
 * Intercetta le richieste OPTIONS (preflight CORS) dirette al namespace
 * health-check/v1 PRIMA che WordPress le instradi verso una rotta reale,
 * e risponde 200 con i soli header CORS, senza alcuna autenticazione:
 * il preflight del browser non include mai l'header Authorization.
 *
 * Agganciato su rest_pre_dispatch: restituire un valore non-null da
 * questo filtro interrompe il dispatch normale della REST API.
 *
 * @param mixed           $result  Risultato corrente (null se non ancora gestito).
 * @param WP_REST_Server  $server  Istanza del server REST (non usata).
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return mixed Il risultato originale, oppure una WP_REST_Response per le OPTIONS.
 */
function wphc_handle_options_preflight( $result, $server, $request ) {
	unset( $server );

	if ( 'OPTIONS' !== $request->get_method() ) {
		return $result;
	}
	if ( 0 !== strpos( $request->get_route(), '/health-check/v1' ) ) {
		return $result;
	}

	wphc_maybe_send_cors_headers();

	return new WP_REST_Response( null, 200 );
}
add_filter( 'rest_pre_dispatch', 'wphc_handle_options_preflight', 10, 3 );

// -----------------------------------------------------------------------
// AUTENTICAZIONE DELLE ROTTE DATI
// -----------------------------------------------------------------------

/**
 * Permission_callback condiviso da /health, /detail/* e /update.
 * Legge il token salvato in wp_health_check_token (assegnato una volta
 * dall'enroll) e lo confronta in tempo costante con il bearer token
 * fornito. Header mancante e token errato restituiscono lo STESSO errore
 * (stesso codice, stesso messaggio) per non rivelare a un chiamante non
 * autenticato quale dei due casi si sia verificato.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return true|WP_Error True se autorizzato, altrimenti WP_Error con lo status corretto.
 */
function wphc_require_token( WP_REST_Request $request ) {
	wphc_maybe_send_cors_headers();

	$stored_token = get_option( 'wp_health_check_token' );
	if ( empty( $stored_token ) ) {
		// Il sito esiste ma non ha mai completato l'enroll: e' uno stato
		// diverso da "token errato", quindi ha un codice HTTP dedicato.
		return new WP_Error(
			'wphc_not_enrolled',
			__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
			array( 'status' => 503 )
		);
	}

	$auth_header  = $request->get_header( 'authorization' );
	$unauthorized = new WP_Error(
		'wphc_unauthorized',
		__( 'Non autorizzato.', 'wp-health-check' ),
		array( 'status' => 401 )
	);

	if ( empty( $auth_header ) || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
		return $unauthorized;
	}

	$provided_token = trim( substr( $auth_header, 7 ) );

	// hash_equals: confronto in tempo costante, indispensabile per un
	// segreto (previene timing attack che dedurrebbero il token byte per
	// byte misurando quanto a lungo dura il confronto).
	if ( ! hash_equals( (string) $stored_token, $provided_token ) ) {
		return $unauthorized;
	}

	return true;
}

// -----------------------------------------------------------------------
// REGISTRAZIONE ROTTE REST
// -----------------------------------------------------------------------

/**
 * Registra tutte le rotte del namespace health-check/v1.
 *
 * REGOLA DI PROGETTAZIONE: qui si registrano SOLO le rotte, senza
 * includere alcun file pesante del core (class-wp-debug-data.php,
 * plugin.php, update.php). Quei file vengono richiesti dentro le
 * singole funzioni di callback, solo quando la rotta chiamata ne ha
 * davvero bisogno: /health e' pensata per il polling frequente e non
 * deve pagare il costo di include che servono solo a /detail/server.
 */
function wphc_register_routes() {
	register_rest_route(
		'health-check/v1',
		'/enroll',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_enroll',
			// Nessun permission_callback basato su token: l'enroll e' il
			// bootstrap stesso del token. L'autenticazione qui e' la
			// firma Ed25519, verificata dentro il callback.
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/health',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_health',
			'permission_callback' => 'wphc_require_token',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/detail/plugins',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_detail_plugins',
			'permission_callback' => 'wphc_require_token',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/detail/theme',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_detail_theme',
			'permission_callback' => 'wphc_require_token',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/detail/server',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_detail_server',
			'permission_callback' => 'wphc_require_token',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/update',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_update',
			'permission_callback' => 'wphc_require_token',
		)
	);
}
add_action( 'rest_api_init', 'wphc_register_routes' );

// -----------------------------------------------------------------------
// CALLBACK: POST /enroll
// -----------------------------------------------------------------------

/**
 * Bootstrap firmato: consegna al sito il proprio token la prima volta,
 * senza toccare wp-config.php. Il sito non deriva mai il token (non
 * possiede il MASTER_SECRET): lo riceve gia' calcolato e lo conserva
 * come valore opaco. Una ripetizione dell'enroll con lo stesso URL
 * produce sempre lo stesso token (derivazione deterministica lato
 * centro), quindi un replay e' innocuo: riscrive lo stesso valore.
 * Per questo non serve alcun controllo anti-replay (nonce, contatore).
 *
 * @param WP_REST_Request $request Richiesta REST con il payload di enroll.
 * @return WP_REST_Response|WP_Error Esito dell'enroll.
 */
function wphc_route_enroll( WP_REST_Request $request ) {
	wphc_maybe_send_cors_headers();

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	// 1. Presenza dei campi obbligatori (dashboard_origin e' l'unico
	// campo opzionale/nullable del payload).
	foreach ( array( 'site_url', 'token', 'issued_at', 'signature' ) as $field ) {
		if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
			return new WP_Error(
				'wphc_enroll_missing_field',
				/* translators: %s: nome del campo mancante. */
				sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field ),
				array( 'status' => 400 )
			);
		}
	}

	$site_url         = (string) $body['site_url'];
	$token            = (string) $body['token'];
	$dashboard_origin = array_key_exists( 'dashboard_origin', $body ) ? $body['dashboard_origin'] : null;
	$issued_at        = (int) $body['issued_at'];
	$signature        = (string) $body['signature'];

	// 2. Verifica della firma con la chiave pubblica incorporata nel file.
	$message       = wphc_build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at );
	$pubkey_raw    = base64_decode( WP_HEALTH_CHECK_CENTRAL_PUBKEY, true );
	$signature_raw = base64_decode( $signature, true );

	$signature_valid = false;
	if (
		false !== $pubkey_raw && false !== $signature_raw
		&& SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $pubkey_raw )
		&& SODIUM_CRYPTO_SIGN_BYTES === strlen( $signature_raw )
	) {
		try {
			$signature_valid = sodium_crypto_sign_verify_detached( $signature_raw, $message, $pubkey_raw );
		} catch ( SodiumException $e ) {
			$signature_valid = false;
		}
	}

	if ( ! $signature_valid ) {
		// Messaggio unico e generico: non distingue "firma non valida" da
		// "chiave malformata" o altro, per non offrire informazioni utili
		// a chi tenta richieste non autorizzate.
		return new WP_Error( 'wphc_enroll_unauthorized', __( 'Richiesta di enroll non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	// 3. Il payload firmato deve riguardare ESATTAMENTE questo sito:
	// impedisce di riusare una busta firmata valida ma destinata a un
	// altro dominio della flotta contro un sito diverso.
	$current_site_url = wphc_normalize_site_url();
	if ( ! hash_equals( $current_site_url, $site_url ) ) {
		return new WP_Error( 'wphc_enroll_site_mismatch', __( 'La busta di enroll non corrisponde a questo sito.', 'wp-health-check' ), array( 'status' => 403 ) );
	}

	// 4. Persistenza in wp_options (mai in wp-config.php).
	update_option( 'wp_health_check_token', $token, false );
	update_option( 'wp_health_check_dashboard_origin', $dashboard_origin, false );
	update_option( 'wp_health_check_enrolled_at', gmdate( 'c' ), false );
	update_option( 'wp_health_check_enrolled_ip', wphc_get_client_ip(), false );
	// issued_at e' solo memorizzato come metadato operativo (non usato
	// per alcuna logica di scadenza, che per progetto non esiste).
	update_option( 'wp_health_check_enroll_issued_at', $issued_at, false );

	// 5. Conferma.
	return rest_ensure_response(
		array(
			'enrolled'      => true,
			'site'          => $current_site_url,
			'agent_version' => WP_HEALTH_CHECK_VERSION,
		)
	);
}

// -----------------------------------------------------------------------
// CALLBACK: GET /health
// -----------------------------------------------------------------------

/**
 * Sommario economico per il polling frequente. Tutte le operazioni sono
 * O(1) o letture da transient gia' mantenuti dal cron di WordPress:
 * questa rotta non chiama MAI WP_Debug_Data::debug_data() ne' forza
 * check remoti verso wordpress.org, per restare adatta a essere
 * interrogata molto spesso senza generare carico o rallentamenti.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response Sommario di stato del sito.
 */
function wphc_route_health( WP_REST_Request $request ) {
	// Il tracciamento accessi avviene sempre, anche quando la risposta
	// sotto e' poi servita dalla cache: altrimenti, con un polling piu'
	// frequente del TTL di cache, l'audit perderebbe la maggior parte
	// delle chiamate realmente ricevute.
	$previous_access = wphc_record_access();

	$fresh = wphc_request_wants_fresh( $request );

	if ( $fresh ) {
		// Ramo esplicitamente lento: SOLO su richiesta manuale (?fresh=1),
		// mai nel polling automatico. Va oltre "letture da transient" e
		// forza una verifica reale contro wordpress.org.
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
	} else {
		$cached = get_transient( 'wphc_health_cache' );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	$all_plugins    = get_plugins();
	$active_plugins = (array) get_option( 'active_plugins', array() );

	// NON wp_get_update_data(): i suoi conteggi sono condizionati da
	// current_user_can( 'update_plugins'/'update_themes'/'update_core' ),
	// che in questa rotta vale sempre false (nessun utente WP loggato:
	// l'autenticazione qui e' il bearer token, non una sessione utente),
	// quindi restituirebbe sempre 0/false anche con aggiornamenti
	// realmente disponibili — bug osservato in produzione: /detail/plugins
	// (che usa get_plugin_updates(), senza alcun controllo di capability)
	// mostrava correttamente un aggiornamento disponibile, mentre /health
	// riportava plugins_updates: 0 per lo stesso sito. Si leggono quindi
	// direttamente gli stessi transient di update gia' mantenuti dal cron,
	// con la stessa logica di conteggio di wp_get_update_data() ma senza
	// il controllo di capability.
	$update_plugins_transient = get_site_transient( 'update_plugins' );
	$plugins_updates_count    = ( is_object( $update_plugins_transient ) && ! empty( $update_plugins_transient->response ) )
		? count( $update_plugins_transient->response )
		: 0;

	$update_themes_transient = get_site_transient( 'update_themes' );
	$themes_updates_count    = ( is_object( $update_themes_transient ) && ! empty( $update_themes_transient->response ) )
		? count( $update_themes_transient->response )
		: 0;

	$core_updates          = get_core_updates( array( 'dismissed' => false ) );
	$core_update_available = is_array( $core_updates )
		&& isset( $core_updates[0]->response )
		&& ! in_array( $core_updates[0]->response, array( 'development', 'latest' ), true );

	$updates_checked_at = ( is_object( $update_plugins_transient ) && ! empty( $update_plugins_transient->last_checked ) )
		? gmdate( 'c', (int) $update_plugins_transient->last_checked )
		: null;

	// Temi: conteggio totale + nome del tema attivo e dell'eventuale parent.
	// wp_get_themes()/wp_get_theme() fanno una scansione della cartella temi
	// analoga a get_plugins() gia' usata sopra (cache interna di WP): stesso
	// ordine di costo, nessuna chiamata remota, coerente col contratto di
	// questa rotta.
	$all_themes        = wp_get_themes();
	$active_theme      = wp_get_theme();
	$active_theme_name = (string) $active_theme->get( 'Name' );
	$parent_theme      = $active_theme->parent();
	$parent_theme_name = ( $parent_theme instanceof WP_Theme ) ? (string) $parent_theme->get( 'Name' ) : null;

	$server_ip = wphc_get_server_ip();

	$payload = array(
		'site'                => wphc_normalize_site_url(),
		'generated_at'        => gmdate( 'c' ),
		'fleet_agent_version' => WP_HEALTH_CHECK_VERSION,
		'summary'             => array(
			'wp_version'         => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'php_memory_limit'   => (string) ini_get( 'memory_limit' ),
			'server_ip'          => '' !== $server_ip ? $server_ip : null,
			'plugin_version'     => WP_HEALTH_CHECK_VERSION,
			'plugins_total'      => count( $all_plugins ),
			'plugins_active'     => count( $active_plugins ),
			'plugins_updates'    => $plugins_updates_count,
			'themes_total'       => count( $all_themes ),
			'themes_updates'     => $themes_updates_count,
			'theme_name'         => $active_theme_name,
			'parent_theme_name'  => $parent_theme_name,
			'core_update'        => $core_update_available,
			'mu_dir_writable'    => (bool) wp_is_writable( WPMU_PLUGIN_DIR ),
			'updates_checked_at' => $updates_checked_at,
		),
		'last_access'         => array(
			'at'          => $previous_access['at'],
			'ip'          => $previous_access['ip'],
			'enrolled_at' => get_option( 'wp_health_check_enrolled_at' ) ? get_option( 'wp_health_check_enrolled_at' ) : null,
		),
		'detail_routes'       => array(
			'plugins' => rest_url( 'health-check/v1/detail/plugins' ),
			'theme'   => rest_url( 'health-check/v1/detail/theme' ),
			'server'  => rest_url( 'health-check/v1/detail/server' ),
		),
	);

	// Micro-cache breve: pensata per assorbire polling ravvicinato senza
	// impedire che un aggiornamento reale sia visibile entro un minuto.
	set_transient( 'wphc_health_cache', $payload, 60 );

	return rest_ensure_response( $payload );
}

// -----------------------------------------------------------------------
// CALLBACK: GET /detail/plugins
// -----------------------------------------------------------------------

/**
 * Elenco completo dei plugin installati, con stato aggiornamenti. Legge
 * da transient esistenti salvo ?fresh=1; l'output e' a sua volta
 * cacheato 1h, perche' l'elenco plugin di un sito cambia raramente.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response Elenco plugin.
 */
function wphc_route_detail_plugins( WP_REST_Request $request ) {
	wphc_record_access();

	$fresh = wphc_request_wants_fresh( $request );
	if ( ! $fresh ) {
		$cached = get_transient( 'wphc_detail_plugins_cache' );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	if ( $fresh ) {
		// Chiamata remota esplicita, solo su richiesta dell'utente della
		// dashboard (drill-down), mai nel polling di /health.
		wp_update_plugins();
	}

	$all_plugins    = get_plugins();
	$plugin_updates = get_plugin_updates();
	$active_plugins = (array) get_option( 'active_plugins', array() );

	$items = array();
	foreach ( $all_plugins as $plugin_file => $plugin_data ) {
		$has_update  = isset( $plugin_updates[ $plugin_file ]->update->new_version );
		$new_version = $has_update ? $plugin_updates[ $plugin_file ]->update->new_version : null;

		// Slug: la cartella del plugin (plugin.php in cartella "foo/foo.php"
		// -> "foo"); per i plugin a file singolo nella radice ("bar.php")
		// non esiste cartella, quindi si usa il nome file senza estensione.
		$plugin_dir = dirname( $plugin_file );
		$slug       = ( '.' !== $plugin_dir ) ? $plugin_dir : basename( $plugin_file, '.php' );

		$items[] = array(
			'name'             => $plugin_data['Name'],
			'slug'             => $slug,
			'version'          => $plugin_data['Version'],
			'active'           => in_array( $plugin_file, $active_plugins, true ),
			'update_available' => $has_update,
			'new_version'      => $new_version,
		);
	}

	$payload = array(
		'site'         => wphc_normalize_site_url(),
		'generated_at' => gmdate( 'c' ),
		'count'        => count( $items ),
		'plugins'      => $items,
	);

	set_transient( 'wphc_detail_plugins_cache', $payload, HOUR_IN_SECONDS );

	return rest_ensure_response( $payload );
}

// -----------------------------------------------------------------------
// CALLBACK: GET /detail/theme
// -----------------------------------------------------------------------

/**
 * Dettaglio del tema attivo e dell'eventuale tema parent (per i child
 * theme). Stessa politica di cache/fresh di /detail/plugins.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response Dettaglio tema.
 */
function wphc_route_detail_theme( WP_REST_Request $request ) {
	wphc_record_access();

	$fresh = wphc_request_wants_fresh( $request );
	if ( ! $fresh ) {
		$cached = get_transient( 'wphc_detail_theme_cache' );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/update.php';

	if ( $fresh ) {
		wp_update_themes();
	}

	$active_theme  = wp_get_theme();
	$theme_updates = get_theme_updates();
	$stylesheet    = $active_theme->get_stylesheet();

	// ->update e' dichiarato "false" negli stub (il suo valore di default nel
	// core), ma get_theme_updates() lo sovrascrive dinamicamente con un array
	// quando esiste un aggiornamento: il cast via @var riflette il tipo
	// realmente possibile a runtime, non quello (incompleto) dello stub.
	/** @var array<string,string>|false $theme_update */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
	$theme_update = isset( $theme_updates[ $stylesheet ] ) ? $theme_updates[ $stylesheet ]->update : false;
	$has_update   = is_array( $theme_update ) && isset( $theme_update['new_version'] );
	$new_version  = $has_update ? $theme_update['new_version'] : null;

	$active_theme_payload = array(
		'name'             => $active_theme->get( 'Name' ),
		'stylesheet'       => $stylesheet,
		'version'          => $active_theme->get( 'Version' ),
		'update_available' => $has_update,
		'new_version'      => $new_version,
	);

	$parent               = $active_theme->parent();
	$parent_theme_payload = null;
	if ( $parent instanceof WP_Theme ) {
		$parent_theme_payload = array(
			'name'    => $parent->get( 'Name' ),
			'version' => $parent->get( 'Version' ),
		);
	}

	$payload = array(
		'site'         => wphc_normalize_site_url(),
		'generated_at' => gmdate( 'c' ),
		'active_theme' => $active_theme_payload,
		'parent_theme' => $parent_theme_payload,
	);

	set_transient( 'wphc_detail_theme_cache', $payload, HOUR_IN_SECONDS );

	return rest_ensure_response( $payload );
}

// -----------------------------------------------------------------------
// CALLBACK: GET /detail/server
// -----------------------------------------------------------------------

/**
 * Dettaglio ambiente server/PHP/database: l'unica rotta potenzialmente
 * lenta e per questo isolata dietro cache 12h e mai chiamata dal
 * polling. Usa WP_Debug_Data::debug_data() come da contratto, ma NON
 * inoltra mai l'array grezzo del core: costruisce un payload con un
 * allowlist esplicito di campi, cosi' un campo privato del core (utente
 * o host del database) non puo' finire nella risposta nemmeno se una
 * futura versione di WordPress cambiasse cosa debug_data() espone.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response Dettaglio server.
 */
function wphc_route_detail_server( WP_REST_Request $request ) {
	wphc_record_access();

	$fresh = wphc_request_wants_fresh( $request );
	if ( ! $fresh ) {
		$cached = get_transient( 'wphc_detail_server_cache' );
		if ( false !== $cached ) {
			return rest_ensure_response( $cached );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';

	try {
		$debug_data = WP_Debug_Data::debug_data();
	} catch ( Throwable $e ) {
		// WP_Debug_Data::debug_data() introspeziona l'intero ambiente
		// (Imagick, GD, filesystem, ecc.) e su alcuni host puo' lanciare
		// eccezioni impreviste durante quell'introspezione: non deve far
		// fallire l'intera rotta con un 500, si prosegue semplicemente
		// senza quella sezione (i campi restano con i fallback sotto).
		$debug_data = array();
		if ( WP_DEBUG ) {
			error_log( 'wp-health-check: eccezione in debug_data(): ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	$server_section   = isset( $debug_data['wp-server']['fields'] ) ? $debug_data['wp-server']['fields'] : array();
	$database_section = isset( $debug_data['wp-database']['fields'] ) ? $debug_data['wp-database']['fields'] : array();

	// Software server: preferisce il valore human-readable di debug_data
	// (chiave storicamente instabile fra versioni core: si prova prima
	// "httpd_software", poi il vecchio "server_software"), con fallback
	// sull'header HTTP grezzo se debug_data non lo espone.
	$software = '';
	foreach ( array( 'httpd_software', 'server_software' ) as $key ) {
		if ( isset( $server_section[ $key ]['value'] ) && '' !== $server_section[ $key ]['value'] ) {
			$software = (string) $server_section[ $key ]['value'];
			break;
		}
	}
	if ( '' === $software && isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
		$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
	}

	// Versione MySQL/MariaDB: preferisce debug_data, con fallback su
	// $wpdb->db_version() (sempre disponibile, non richiede introspezione).
	global $wpdb;
	$mysql_version = isset( $database_section['server_version']['value'] ) && '' !== $database_section['server_version']['value']
		? (string) $database_section['server_version']['value']
		: ( method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '' );

	// I valori numerici di configurazione PHP sono letti direttamente da
	// ini_get(): debug_data() li restituisce gia' formattati per essere
	// letti da un umano (es. "On (32M)" per gli upload) e non sono
	// pensati per essere ri-parsati programmaticamente; ini_get() da'
	// invece lo stesso identico dato in forma stabile e diretta.
	$server_ip = wphc_get_server_ip();

	$payload = array(
		'site'         => wphc_normalize_site_url(),
		'generated_at' => gmdate( 'c' ),
		'server'       => array(
			'software'            => $software,
			'server_ip'           => '' !== $server_ip ? $server_ip : null,
			'php_version'         => PHP_VERSION,
			'php_sapi'            => PHP_SAPI,
			'php_memory_limit'    => (string) ini_get( 'memory_limit' ),
			'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
			'max_input_vars'      => (string) ini_get( 'max_input_vars' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
			'mysql_version'       => $mysql_version,
			'https'               => is_ssl(),
			'extensions'          => array(
				'curl'     => extension_loaded( 'curl' ),
				'imagick'  => extension_loaded( 'imagick' ),
				'gd'       => extension_loaded( 'gd' ),
				'mbstring' => extension_loaded( 'mbstring' ),
				'intl'     => extension_loaded( 'intl' ),
			),
		),
	);

	set_transient( 'wphc_detail_server_cache', $payload, 12 * HOUR_IN_SECONDS );

	return rest_ensure_response( $payload );
}

// -----------------------------------------------------------------------
// CALLBACK: POST /update
// -----------------------------------------------------------------------

/**
 * Self-update da GitHub. Segue rigorosamente l'ordine: verifica versione
 * -> preflight di scrivibilita' -> download -> verifica integrita' ->
 * backup -> scrittura atomica -> sanity check -> pulizia. Ogni passo che
 * fallisce interrompe il flusso PRIMA di toccare il file di produzione,
 * cosi' un errore a meta' strada non puo' mai lasciare il sito con un
 * mu-plugin corrotto o mancante.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error Esito dell'update.
 */
function wphc_route_update( WP_REST_Request $request ) {
	unset( $request );

	// 1. L'autenticazione e' gia' garantita dal permission_callback; qui
	// si registra soltanto l'accesso, come richiesto per ogni chiamata
	// dati autenticata con successo.
	wphc_record_access();

	$current_version = WP_HEALTH_CHECK_VERSION;

	// 2. Interroga la release piu' recente pubblicata su GitHub.
	$api_url = sprintf(
		'https://api.github.com/repos/%s/%s/releases/latest',
		rawurlencode( WP_HEALTH_CHECK_GH_OWNER ),
		rawurlencode( WP_HEALTH_CHECK_GH_REPO )
	);

	$response = wp_remote_get(
		$api_url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				// GitHub rifiuta le richieste API senza uno User-Agent.
				'User-Agent' => 'wp-health-check-agent',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'wphc_update_network_error', __( 'Impossibile contattare GitHub.', 'wp-health-check' ), array( 'status' => 502 ) );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'wphc_update_github_error', __( 'GitHub ha risposto con un errore nel recuperare la release.', 'wp-health-check' ), array( 'status' => 502 ) );
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return new WP_Error( 'wphc_update_bad_release', __( 'Risposta di GitHub non valida.', 'wp-health-check' ), array( 'status' => 502 ) );
	}

	// 3. Normalizza il tag (rimuove un eventuale prefisso "v") e confronta.
	$latest_tag = ltrim( (string) $release['tag_name'], 'v' );
	if ( ! version_compare( $latest_tag, $current_version, '>' ) ) {
		return rest_ensure_response(
			array(
				'updated' => false,
				'reason'  => 'up_to_date',
				'current' => $current_version,
				'latest'  => $latest_tag,
			)
		);
	}

	// 4. Preflight di scrittura: verifica PRIMA di scaricare qualunque
	// cosa che la directory di destinazione sia scrivibile. Nome del
	// file di test casuale per evitare collisioni fra aggiornamenti
	// concorrenti eventualmente lanciati su piu' siti in parallelo.
	$mu_dir    = trailingslashit( WPMU_PLUGIN_DIR );
	$test_file = $mu_dir . '.wphc-writetest-' . wp_generate_password( 12, false, false );

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	// Funzioni filesystem PHP native (non WP_Filesystem) per scelta di
	// progetto: questo file deve restare autoconsistente e funzionare
	// anche quando WP_Filesystem non e' inizializzato o richiederebbe
	// credenziali FTP; l'operatore "@" silenzia solo il warning PHP,
	// l'esito e' comunque sempre controllato esplicitamente sotto.
	if ( false === @file_put_contents( $test_file, 'wphc' ) ) {
		return rest_ensure_response(
			array(
				'updated' => false,
				'reason'  => 'not_writable',
			)
		);
	}

	// 5. Individua gli asset della release: il file del plugin e il suo
	// hash sha256 affiancato.
	$asset_url     = null;
	$sha_asset_url = null;
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
				continue;
			}
			if ( 'wp-health-check.php' === $asset['name'] ) {
				$asset_url = $asset['browser_download_url'];
			} elseif ( 'wp-health-check.php.sha256' === $asset['name'] ) {
				$sha_asset_url = $asset['browser_download_url'];
			}
		}
	}

	if ( null === $asset_url ) {
		@unlink( $test_file );

		return new WP_Error( 'wphc_update_asset_missing', __( 'Asset wp-health-check.php non trovato nella release.', 'wp-health-check' ), array( 'status' => 502 ) );
	}

	$download = wp_remote_get( $asset_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $download ) || 200 !== (int) wp_remote_retrieve_response_code( $download ) ) {
		@unlink( $test_file );

		return new WP_Error( 'wphc_update_download_failed', __( 'Impossibile scaricare il nuovo file del plugin.', 'wp-health-check' ), array( 'status' => 502 ) );
	}
	$new_contents = wp_remote_retrieve_body( $download );

	// 6. Verifica di integrita', PRIMA di toccare qualunque file di
	// produzione: hash atteso (asset .sha256, oppure riga "sha256: <hash>"
	// nel corpo della release), contenuto non vuoto, prefisso "<?php" e
	// coerenza fra la versione dichiarata nel file e il tag della release.
	$expected_sha256 = null;
	if ( null !== $sha_asset_url ) {
		$sha_response = wp_remote_get( $sha_asset_url, array( 'timeout' => 30 ) );
		if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
			// Il file .sha256 puo' seguire il formato "sha256sum"
			// ("<hash>  <nomefile>") oppure contenere il solo hash.
			$sha_body        = trim( wp_remote_retrieve_body( $sha_response ) );
			$expected_sha256 = strtolower( (string) strtok( $sha_body, " \t\n" ) );
		}
	}
	if ( ( null === $expected_sha256 || '' === $expected_sha256 ) && ! empty( $release['body'] ) ) {
		if ( preg_match( '/sha256:\s*([a-f0-9]{64})/i', (string) $release['body'], $matches ) ) {
			$expected_sha256 = strtolower( $matches[1] );
		}
	}

	$integrity_ok = true;
	if ( '' === $new_contents ) {
		$integrity_ok = false;
	} elseif ( 0 !== strpos( $new_contents, '<?php' ) ) {
		$integrity_ok = false;
	} elseif ( ! preg_match( '/Version:\s+' . preg_quote( $latest_tag, '/' ) . '(?:\s|$)/', $new_contents ) ) {
		// L'header del plugin allinea i campi con spazi multipli (es. "Version:     1.0.0"),
		// quindi il confronto non puo' cercare un singolo spazio letterale dopo "Version:".
		$integrity_ok = false;
	} elseif ( empty( $expected_sha256 ) || ! hash_equals( $expected_sha256, hash( 'sha256', $new_contents ) ) ) {
		$integrity_ok = false;
	}

	if ( ! $integrity_ok ) {
		@unlink( $test_file );

		return rest_ensure_response(
			array(
				'updated' => false,
				'reason'  => 'integrity_check_failed',
			)
		);
	}

	// 7. Backup del file corrente, prima di qualsiasi scrittura.
	$plugin_file = __FILE__;
	$backup_file = $plugin_file . '.bak';
	if ( false === @copy( $plugin_file, $backup_file ) ) {
		@unlink( $test_file );

		return new WP_Error( 'wphc_update_backup_failed', __( 'Impossibile creare il backup prima di aggiornare.', 'wp-health-check' ), array( 'status' => 500 ) );
	}

	// 8. Scrittura atomica: il temporaneo NON ha estensione .php di
	// proposito. Se il rename() sottostante fallisse e il temporaneo
	// restasse orfano nella cartella, un file con estensione .php in
	// mu-plugins verrebbe caricato automaticamente al giro successivo,
	// ridichiarando tutte le funzioni di questo plugin e rompendo l'intero
	// sito con un fatal error: l'estensione neutra rende questo scenario
	// innocuo. rename() sullo stesso filesystem e' atomico: non lascia
	// mai __FILE__ in uno stato "a meta' scritto".
	$tmp_file = $mu_dir . '.wphc-new-' . wp_generate_password( 12, false, false ) . '.tmp';
	if ( false === @file_put_contents( $tmp_file, $new_contents ) || false === @rename( $tmp_file, $plugin_file ) ) {
		@unlink( $tmp_file );
		@unlink( $test_file );

		return new WP_Error( 'wphc_update_write_failed', __( 'Scrittura del nuovo file fallita.', 'wp-health-check' ), array( 'status' => 500 ) );
	}

	// 9. Sanity check post-scrittura: se qualcosa non torna, ripristina
	// immediatamente dal backup del punto 7.
	$written_contents = @file_get_contents( $plugin_file );
	if ( false === $written_contents || '' === $written_contents || 0 !== strpos( $written_contents, '<?php' ) ) {
		@copy( $backup_file, $plugin_file );
		@unlink( $test_file );

		return new WP_Error( 'wphc_update_sanity_failed', __( 'Verifica post-scrittura fallita: ripristinato il backup precedente.', 'wp-health-check' ), array( 'status' => 500 ) );
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// 10. Invalida l'opcode cache per la nuova versione: senza questo
	// passo, sui SAPI con opcache persistente (es. php-fpm) la vecchia
	// versione compilata resterebbe in uso fino al riavvio del pool.
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $plugin_file, true );
	}

	// 11. Rimuove il file di test di scrivibilita' del punto 4.
	@unlink( $test_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

	if ( WP_DEBUG ) {
		error_log( sprintf( 'wp-health-check: aggiornato da %1$s a %2$s', $current_version, $latest_tag ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	// 12. Esito positivo.
	return rest_ensure_response(
		array(
			'updated' => true,
			'from'    => $current_version,
			'to'      => $latest_tag,
		)
	);
}

// -----------------------------------------------------------------------
// TAB SITE HEALTH: stato e configurazione da wp-admin
// -----------------------------------------------------------------------
//
// Aggiunge una tab dedicata in Strumenti -> Salute del sito (disponibile
// da WordPress 5.8 via i filtri/azioni site_health_navigation_tabs e
// site_health_tab_content), visibile solo a chi puo' manage_options: a
// differenza della tab "Informazioni" (sola lettura, alimentata dal filtro
// debug_information), qui si puo' anche MODIFICARE wp_health_check_dashboard_origin
// e resettare l'enrollment, quindi il controllo di accesso e' piu' stretto
// della sola capacita' di visualizzare la Salute del sito.

/**
 * Registra la tab "WP Health Check" nella pagina Salute del sito.
 *
 * @param array<string,string> $tabs Tab gia' registrate (slug => etichetta).
 * @return array<string,string> Tab con l'eventuale aggiunta.
 */
function wphc_register_site_health_tab( $tabs ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $tabs;
	}

	$tabs['wp-health-check'] = __( 'WP Health Check', 'wp-health-check' );

	return $tabs;
}
add_filter( 'site_health_navigation_tabs', 'wphc_register_site_health_tab' );

/**
 * Renderizza il contenuto della tab "WP Health Check": versione del plugin,
 * stato di enrollment, ultimo accesso, form per modificare
 * wp_health_check_dashboard_origin e pulsante di reset dell'enrollment.
 *
 * @param string $tab Slug della tab richiesta.
 */
function wphc_render_site_health_tab( $tab ) {
	if ( 'wp-health-check' !== $tab || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$dashboard_origin = (string) get_option( 'wp_health_check_dashboard_origin', '' );
	$is_enrolled      = ! empty( get_option( 'wp_health_check_token' ) );
	$enrolled_at      = get_option( 'wp_health_check_enrolled_at' );
	$enrolled_ip      = get_option( 'wp_health_check_enrolled_ip' );
	$last_request_at  = get_option( 'wp_health_check_last_request_at' );
	$last_request_ip  = get_option( 'wp_health_check_last_request_ip' );
	$trust_proxy      = (bool) get_option( 'wp_health_check_trust_proxy', false );
	?>
	<div class="health-check-body health-check-wp-health-check-tab hide-if-no-js">
		<h2><?php esc_html_e( 'WP Health Check — Fleet Agent', 'wp-health-check' ); ?></h2>

		<?php if ( isset( $_GET['wphc_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo un flag di stato per il messaggio, nessuna azione eseguita qui. ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Origin della dashboard aggiornata.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Origin non valida: deve essere nella forma schema://host[:porta], senza percorso (es. https://dashboard.esempio.com).', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".', 'wp-health-check' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width: 800px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Versione plugin', 'wp-health-check' ); ?></td>
					<td><code><?php echo esc_html( WP_HEALTH_CHECK_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Repository GitHub', 'wp-health-check' ); ?></td>
					<td><code><?php echo esc_html( WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Stato enrollment', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( $is_enrolled ) : ?>
							<?php esc_html_e( 'Registrato', 'wp-health-check' ); ?>
							<?php if ( $enrolled_at ) : ?>
								<?php
								printf(
									/* translators: 1: data/ora ISO 8601 dell'enroll, 2: IP del chiamante. */
									esc_html__( '(il %1$s da %2$s)', 'wp-health-check' ),
									esc_html( $enrolled_at ),
									esc_html( $enrolled_ip ? $enrolled_ip : '—' )
								);
								?>
							<?php endif; ?>
						<?php else : ?>
							<?php esc_html_e( 'Non registrato (in attesa di /enroll)', 'wp-health-check' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Ultimo accesso registrato', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( $last_request_at ) : ?>
							<?php echo esc_html( $last_request_at ); ?> — <?php echo esc_html( $last_request_ip ? $last_request_ip : '—' ); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Trust proxy (X-Forwarded-For)', 'wp-health-check' ); ?></td>
					<td>
						<?php echo $trust_proxy ? esc_html__( 'Attivo', 'wp-health-check' ) : esc_html__( 'Disattivo', 'wp-health-check' ); ?>
						<p class="description">
							<?php esc_html_e( 'Sola lettura qui: va attivato solo manualmente (wp option update wp_health_check_trust_proxy 1) e solo se un proxy/CDN fidato sovrascrive sempre l\'header, mai di default.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Origin della dashboard', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Origin autorizzata per le richieste CORS da browser (es. https://dashboard.esempio.com). Se lasciata vuota, viene autorizzata qualunque origin.', 'wp-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_save_dashboard_origin" />
			<?php wp_nonce_field( 'wphc_save_dashboard_origin' ); ?>
			<input
				type="url"
				name="wphc_dashboard_origin"
				value="<?php echo esc_attr( $dashboard_origin ); ?>"
				class="regular-text"
				placeholder="https://dashboard.esempio.com"
			/>
			<?php submit_button( __( 'Salva origin', 'wp-health-check' ), 'primary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Reset enrollment', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Cancella token, origin della dashboard e tutti i metadati di enrollment. Il sito torna allo stato "non registrato" finche\' il sistema centrale non ripete l\'enroll. Equivalente a "wp health-check reset".', 'wp-health-check' ); ?>
		</p>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm( <?php echo wp_json_encode( __( 'Confermi il reset dell\'enrollment? Il sito restera\' non registrato finche\' il sistema centrale non ripete l\'enroll.', 'wp-health-check' ) ); ?> );"
		>
			<input type="hidden" name="action" value="wphc_reset_enrollment" />
			<?php wp_nonce_field( 'wphc_reset_enrollment' ); ?>
			<?php submit_button( __( 'Resetta enrollment', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
add_action( 'site_health_tab_content', 'wphc_render_site_health_tab' );

/**
 * Handler di admin-post.php per il salvataggio di wp_health_check_dashboard_origin
 * dalla tab Site Health. Valida l'origin (o accetta la stringa vuota, che
 * riapre CORS a qualunque origin, vedi wphc_maybe_send_cors_headers()) e
 * reindirizza alla tab con un flag di esito (pattern POST-redirect-GET).
 */
function wphc_handle_save_dashboard_origin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_save_dashboard_origin' );

	$origin = isset( $_POST['wphc_dashboard_origin'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wphc_dashboard_origin'] ) ) ) : '';

	$redirect_args = array( 'tab' => 'wp-health-check' );
	if ( '' === $origin || wphc_is_valid_origin( $origin ) ) {
		update_option( 'wp_health_check_dashboard_origin', $origin, false );
		$redirect_args['wphc_saved'] = '1';
	} else {
		$redirect_args['wphc_error'] = '1';
	}

	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'site-health.php' ) ) );
	exit;
}
add_action( 'admin_post_wphc_save_dashboard_origin', 'wphc_handle_save_dashboard_origin' );

/**
 * Handler di admin-post.php per il pulsante di reset enrollment nella tab
 * Site Health: stessa logica condivisa con "wp health-check reset"
 * (wphc_reset_enrollment()), poi redirect alla tab (POST-redirect-GET).
 */
function wphc_handle_reset_enrollment() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_reset_enrollment' );

	wphc_reset_enrollment();

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'        => 'wp-health-check',
				'wphc_reset' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_reset_enrollment', 'wphc_handle_reset_enrollment' );

// -----------------------------------------------------------------------
// COMANDO WP-CLI: wp health-check reset
// -----------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Comandi WP-CLI del fleet agent wp-health-check.
	 *
	 * Definita solo dentro il branch WP_CLI per non caricare la classe
	 * su ogni richiesta HTTP normale, dove non serve mai.
	 */
	class WPHC_CLI_Command {

		/**
		 * Cancella le opzioni di enrollment di questo sito.
		 *
		 * E' una utility operativa di re-provisioning/offboarding (es.
		 * sito che cambia dominio, o va rimosso dalla flotta), NON un
		 * meccanismo di scadenza del token: per progetto il token non
		 * scade mai da solo. Dopo il reset il sito torna nello stato
		 * "non enrolled" (503 su tutte le rotte dati) finche' il sistema
		 * centrale non ripete l'enroll.
		 *
		 * ## EXAMPLES
		 *
		 *     wp health-check reset
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Argomenti posizionali (non usati).
		 * @param array $assoc_args Argomenti nominali (non usati).
		 */
		public function reset( $args, $assoc_args ) {
			unset( $args, $assoc_args );

			wphc_reset_enrollment();

			WP_CLI::success( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".' );
		}
	}

	WP_CLI::add_command( 'health-check', 'WPHC_CLI_Command' );
}
