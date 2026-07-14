<?php
/**
 * Plugin Name: WP Health Check (Fleet Agent)
 * Description: Must-use plugin di monitoraggio per una flotta di siti WordPress, con enroll firmato, endpoint REST protetti da token e self-update firmato dalle release di un repository GitHub pubblico.
 * Version:     1.18.0
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
	define( 'WP_HEALTH_CHECK_VERSION', '1.18.0' );
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

/*
 * Indirizzo email dell'operatore della flotta, avvisato quando un enroll
 * fallisce per URL mismatch (vedi wphc_send_enroll_mismatch_alert). Non e'
 * un segreto: e' l'indirizzo a cui recapitare gli alert diagnostici.
 * Stringa vuota = nessun invio.
 */
if ( ! defined( 'WP_HEALTH_CHECK_ALERT_EMAIL' ) ) {
	define( 'WP_HEALTH_CHECK_ALERT_EMAIL', 'maurizio@mavida.com' );
}

/**
 * Versione dello schema della tabella di log degli update
 * ({$wpdb->prefix}wphc_update_log). Incrementarla forza dbDelta() a
 * rieseguire e allineare lo schema al prossimo caricamento (vedi
 * wphc_maybe_install_update_log_schema()).
 */
if ( ! defined( 'WP_HEALTH_CHECK_DB_VERSION' ) ) {
	define( 'WP_HEALTH_CHECK_DB_VERSION', '1' );
}

/** Giorni di conservazione delle righe della tabella di log update (§6.5). */
if ( ! defined( 'WP_HEALTH_CHECK_LOG_RETENTION_DAYS' ) ) {
	define( 'WP_HEALTH_CHECK_LOG_RETENTION_DAYS', 90 );
}

/** TTL in secondi del lock anti-concorrenza per le rotte di update (§7.1). */
if ( ! defined( 'WP_HEALTH_CHECK_UPDATE_LOCK_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_UPDATE_LOCK_TTL', 300 );
}

// -----------------------------------------------------------------------
// UTILITY CONDIVISE
// -----------------------------------------------------------------------

/**
 * Normalizza un URL qualsiasi con la STESSA regola del sistema centrale,
 * cosi' che il confronto lato sito e il calcolo dell'HMAC del token lato
 * centro operino byte per byte sullo stesso materiale: schema e host in
 * minuscolo, porta inclusa solo se presente/non standard, path invariato
 * senza slash finale, niente query ne' fragment. Vedi README.md per
 * l'esempio numerico completo URL -> token.
 *
 * @param string $url URL grezzo da normalizzare.
 * @return string URL normalizzato, oppure stringa vuota se non parsabile.
 */
function wphc_normalize_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! is_array( $parts ) ) {
		return '';
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
	$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$path   = isset( $parts['path'] ) ? $parts['path'] : '';

	return untrailingslashit( $scheme . '://' . $host . $port . $path );
}

/**
 * URL normalizzato del sito corrente, usato come identificativo "site"
 * nelle risposte e come URL canonico "atteso" nei messaggi di mismatch.
 * E' semplicemente wphc_normalize_url() applicato a home_url().
 *
 * @return string URL normalizzato (es. "https://esempio.com/blog").
 */
function wphc_normalize_site_url() {
	return wphc_normalize_url( home_url() );
}

/**
 * Costruisce l'insieme degli URL canonici plausibili di questo sito, usato
 * per rendere tollerante il confronto in fase di enroll (siti WPML dove
 * home_url() varia per lingua, reverse proxy, varianti www/non-www).
 *
 * Raccoglie home_url(), site_url(), network_home_url() e network_site_url()
 * (site_url()/network_* non sono filtrate per lingua da WPML, quindi il set
 * contiene sempre l'URL base canonico anche quando home_url() porta un
 * prefisso di lingua), genera per ciascuna la variante con/senza "www." sul
 * primo label dell'host, normalizza tutto e deduplica.
 *
 * @return string[] URL normalizzati candidati, senza duplicati.
 */
function wphc_candidate_site_urls() {
	$raw        = array( home_url(), site_url(), network_home_url(), network_site_url() );
	$candidates = array();

	foreach ( $raw as $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			continue;
		}

		$host     = strtolower( $parts['host'] );
		$alt_host = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : 'www.' . $host;
		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path     = isset( $parts['path'] ) ? $parts['path'] : '';

		foreach ( array( $host, $alt_host ) as $variant_host ) {
			$normalized = wphc_normalize_url( $scheme . '://' . $variant_host . $port . $path );
			if ( '' !== $normalized ) {
				// Chiave dell'array = dedup automatica delle varianti coincidenti.
				$candidates[ $normalized ] = true;
			}
		}
	}

	return array_keys( $candidates );
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
		'wp_health_check_site_url',
		'wp_health_check_dashboard_origin',
		'wp_health_check_enrolled_at',
		'wp_health_check_enrolled_ip',
		'wp_health_check_enroll_issued_at',
		'wp_health_check_last_request_at',
		'wp_health_check_last_request_ip',
		'wp_health_check_last_enroll_error',
	);
	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}

	// Solo il lock anti-concorrenza (stato transitorio legato al sito): lo
	// storico della tabella wphc_update_log e il kill-switch
	// wp_health_check_updates_enabled sono config/audit del sito, non
	// dell'enrollment, e sopravvivono volutamente a un reset.
	delete_transient( 'wp_health_check_update_lock' );
}

/**
 * Registra l'esito di un tentativo di enroll FALLITO in
 * wp_health_check_last_enroll_error, per poterlo mostrare nella tab Site
 * Health e diagnosticare rapidamente il motivo (es. URL inviato sbagliato).
 * Su enroll riuscito l'opzione va invece cancellata (vedi wphc_route_enroll).
 *
 * NB: /enroll e' pubblica, quindi anche tentativi con firma non valida
 * finiscono qui: il valore "received" e' sanitizzato in scrittura e va
 * comunque escapizzato in output (l'URL e' input non autenticato in questo
 * ramo). L'opzione e' visibile solo a manage_options.
 *
 * @param string $code     Codice macchina del fallimento (es. wphc_enroll_url_mismatch).
 * @param string $reason   Descrizione umana del fallimento.
 * @param string $received URL inviato nella busta (grezzo), o stringa vuota se non disponibile.
 */
function wphc_record_enroll_error( $code, $reason, $received = '' ) {
	update_option(
		'wp_health_check_last_enroll_error',
		array(
			'at'       => gmdate( 'c' ),
			'code'     => $code,
			'reason'   => $reason,
			'received' => sanitize_text_field( $received ),
			'ip'       => wphc_get_client_ip(),
		),
		false
	);
}

/**
 * Invia a WP_HEALTH_CHECK_ALERT_EMAIL un avviso quando un enroll fallisce per
 * URL mismatch, con il dettaglio utile a correggere la configurazione: URL
 * inviato, URL atteso e l'elenco completo degli URL validi con cui firmare.
 *
 * Rate-limit: al massimo un'email all'ora per sito (transient), per evitare
 * un flood se il sistema centrale ritenta l'enroll di frequente. Questo ramo
 * e' comunque raggiungibile SOLO con una firma Ed25519 valida (la firma e'
 * verificata prima, vedi wphc_route_enroll): non e' quindi un vettore aperto
 * ad attaccanti anonimi. L'invio usa wp_mail() del core, nessuna dipendenza
 * esterna.
 *
 * @param string   $expected   URL canonico atteso dal sito (home normalizzato).
 * @param string   $received   URL inviato nella busta di enroll (grezzo).
 * @param string[] $candidates Elenco degli URL validi per l'enroll su questo sito.
 */
function wphc_send_enroll_mismatch_alert( $expected, $received, $candidates ) {
	// is_email() e' false anche per la stringa vuota: copre sia il caso
	// "nessun destinatario configurato" sia quello "indirizzo non valido".
	$to = WP_HEALTH_CHECK_ALERT_EMAIL;
	if ( ! is_email( $to ) ) {
		return;
	}

	// Throttle: non piu' di un avviso all'ora per non trasformare i retry
	// dell'enroll in un flood di email.
	if ( false !== get_transient( 'wphc_enroll_alert_sent' ) ) {
		return;
	}

	$site_home = home_url();

	/* translators: %s: URL del sito che ha rifiutato l'enroll. */
	$subject = sprintf( __( '[WP Health Check] Enroll fallito (URL mismatch) su %s', 'wp-health-check' ), $site_home );

	$lines   = array();
	$lines[] = __( 'Un tentativo di enroll e\' stato rifiutato: il site_url firmato dal sistema centrale non coincide con nessuno degli URL canonici del sito.', 'wp-health-check' );
	$lines[] = '';
	$lines[] = __( 'Sito:', 'wp-health-check' ) . ' ' . $site_home;
	$lines[] = __( 'URL ricevuto:', 'wp-health-check' ) . ' ' . $received;
	$lines[] = __( 'URL atteso (principale):', 'wp-health-check' ) . ' ' . $expected;
	$lines[] = __( 'IP chiamante:', 'wp-health-check' ) . ' ' . wphc_get_client_ip();
	$lines[] = __( 'Quando (UTC):', 'wp-health-check' ) . ' ' . gmdate( 'c' );
	$lines[] = '';
	$lines[] = __( 'URL validi per l\'enroll (il centro deve firmare uno di questi):', 'wp-health-check' );
	foreach ( $candidates as $candidate ) {
		$lines[] = '  - ' . $candidate;
	}
	$lines[] = '';
	$lines[] = __( 'Suggerimento: verificare www/non-www, http/https ed eventuali riscritture del dominio (es. plugin multilingua). Se il plugin e\' aggiornato, il confronto tollera automaticamente le varianti www/non-www.', 'wp-health-check' );

	$sent = wp_mail( $to, $subject, implode( "\n", $lines ) );

	if ( $sent ) {
		set_transient( 'wphc_enroll_alert_sent', gmdate( 'c' ), HOUR_IN_SECONDS );
	}
}

/**
 * Rileva due segnali booleani sul sito, calcolati dai plugin/temi
 * effettivamente attivi: presenza di un consent manager GDPR (has_gdpr) e
 * presenza di un page builder (has_builder). L'API centrale li persiste e li
 * espone; qui li si calcola perche' il plugin ha accesso diretto a WordPress.
 *
 * PUNTO UNICO DEGLI SLUG: gli elenchi degli slug riconosciuti vivono solo
 * dentro questa funzione, cosi' aggiungerne altri (nuovi consent manager o
 * builder) non richiede toccare altre parti del plugin ne' l'API centrale.
 * Gli slug plugin sono la cartella (primo segmento di "cartella/file.php") e
 * vanno verificati contro le versioni realmente installate (Cookiebot in
 * particolare ha avuto slug diversi nel tempo).
 *
 * @return array{has_gdpr: bool, has_builder: bool} Segnali rilevati.
 */
function wphc_detect_site_signals() {
	// Slug (cartella) dei consent manager GDPR riconosciuti.
	$gdpr_plugin_slugs = array(
		'iubenda-cookie-law-solution',
		'cookiebot',
		'cookiebot-manager',
	);

	// Slug (cartella) dei page builder riconosciuti come plugin.
	$builder_plugin_slugs = array(
		'elementor',
	);

	// Slug (stylesheet/template) dei temi builder riconosciuti.
	$builder_theme_slugs = array(
		'divi',
	);

	$active_plugins = (array) get_option( 'active_plugins', array() );
	$plugin_slugs   = array_map(
		function ( $plugin_file ) {
			// "elementor/elementor.php" -> "elementor"; per un plugin a file
			// singolo nella radice ("foo.php") il primo segmento e' il file.
			return explode( '/', $plugin_file )[0];
		},
		$active_plugins
	);

	$active_theme = wp_get_theme();
	$theme_slugs  = array_filter(
		array(
			strtolower( $active_theme->get_stylesheet() ),
			strtolower( $active_theme->get_template() ),
		)
	);

	$has_gdpr = (bool) array_intersect( $plugin_slugs, $gdpr_plugin_slugs );

	$has_builder = (bool) array_intersect( $plugin_slugs, $builder_plugin_slugs )
		|| (bool) array_intersect( $theme_slugs, $builder_theme_slugs );

	return array(
		'has_gdpr'    => $has_gdpr,
		'has_builder' => $has_builder,
	);
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

	// Aggiornamento di software di terze parti (plugin/temi/core), distinto
	// dal self-update dell'agent sopra: vedi wphc_perform_item_update() e
	// wphc_perform_core_update() per il flusso completo.
	register_rest_route(
		'health-check/v1',
		'/update/plugin',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_update_plugin',
			'permission_callback' => 'wphc_require_token',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/update/theme',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_update_theme',
			'permission_callback' => 'wphc_require_token',
		)
	);

	register_rest_route(
		'health-check/v1',
		'/update/core',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_update_core',
			'permission_callback' => 'wphc_require_token',
		)
	);

	// Sola lettura: resta accessibile anche a kill-switch spento (vedi
	// wphc_route_update_log), utile per diagnosticare la flotta a feature
	// disattivata.
	register_rest_route(
		'health-check/v1',
		'/update/log',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_update_log',
			'permission_callback' => 'wphc_require_token',
		)
	);

	// Rotta diagnostica: gated su manage_options (autenticazione WordPress,
	// quindi chiamabile con una application password), NON sul bearer token.
	// E' uno strumento per l'operatore: aiuta a capire discrepanze fra i
	// conteggi update visti in admin e via REST.
	register_rest_route(
		'health-check/v1',
		'/debug',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_debug',
			'permission_callback' => 'wphc_debug_permission',
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
		wphc_record_enroll_error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), '' );

		return new WP_Error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	// URL inviato, se presente: usato solo per diagnostica nel log errori.
	$reported_site_url = isset( $body['site_url'] ) ? (string) $body['site_url'] : '';

	// 1. Presenza dei campi obbligatori (dashboard_origin e' l'unico
	// campo opzionale/nullable del payload).
	foreach ( array( 'site_url', 'token', 'issued_at', 'signature' ) as $field ) {
		if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
			/* translators: %s: nome del campo mancante. */
			$reason = sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field );
			wphc_record_enroll_error( 'wphc_enroll_missing_field', $reason, $reported_site_url );

			return new WP_Error( 'wphc_enroll_missing_field', $reason, array( 'status' => 400 ) );
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
		// Messaggio unico e generico verso il chiamante: non distingue
		// "firma non valida" da "chiave malformata" o altro, per non offrire
		// informazioni utili a chi tenta richieste non autorizzate. La
		// diagnostica interna (tab admin) registra invece un motivo esplicito.
		wphc_record_enroll_error( 'wphc_enroll_unauthorized', __( 'Firma non valida (busta non prodotta dal sistema centrale).', 'wp-health-check' ), $reported_site_url );

		return new WP_Error( 'wphc_enroll_unauthorized', __( 'Richiesta di enroll non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	// 3. Il payload firmato deve riguardare uno degli URL canonici di questo
	// sito: impedisce di riusare una busta firmata valida ma destinata a un
	// altro dominio della flotta contro un sito diverso. Il confronto e'
	// TOLLERANTE (set di candidati home/site/network_* x www/non-www,
	// tutti normalizzati) per non fallire su siti WPML, reverse proxy o
	// varianti www/non-www dove l'URL locale differisce da quello canonico
	// che il centro ha firmato. A questo punto la firma e' gia' valida:
	// $site_url e' autenticato (e' cio' che il centro ha firmato), non input
	// arbitrario. NB: il confronto non usa hash_equals perche' un URL non e'
	// un segreto; l'autenticazione e' la firma Ed25519, gia' verificata.
	$received_site_url   = $site_url;                       // grezzo: memorizzato e mostrato in diagnostica.
	$received_normalized = wphc_normalize_url( $site_url );  // normalizzato: solo per il confronto.
	$candidates          = wphc_candidate_site_urls();

	if ( ! in_array( $received_normalized, $candidates, true ) ) {
		$canonical_home = wphc_normalize_site_url();
		if ( WP_DEBUG ) {
			error_log( sprintf( 'wp-health-check: enroll URL mismatch — atteso: %1$s ricevuto: %2$s', $canonical_home, $received_site_url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$mismatch_message = sprintf(
			/* translators: 1: URL canonico atteso, 2: URL ricevuto nella busta. */
			__( 'URL atteso: %1$s — ricevuto: %2$s', 'wp-health-check' ),
			$canonical_home,
			$received_site_url
		);
		// Registra il dettaglio sul sito (tab Site Health) e avvisa via email
		// l'operatore della flotta: entrambi utili a correggere l'URL usato.
		wphc_record_enroll_error( 'wphc_enroll_url_mismatch', $mismatch_message, $received_site_url );
		wphc_send_enroll_mismatch_alert( $canonical_home, $received_site_url, $candidates );

		return new WP_Error(
			'wphc_enroll_url_mismatch',
			$mismatch_message,
			array(
				'status'   => 403,
				'expected' => $canonical_home,
				'received' => $received_site_url,
			)
		);
	}

	// 4. Persistenza in wp_options (mai in wp-config.php). Si memorizza
	// ESATTAMENTE il site_url firmato ricevuto (autenticato dalla firma):
	// e' la chiave a cui il centro ha legato il token e che riusera' identica.
	update_option( 'wp_health_check_token', $token, false );
	update_option( 'wp_health_check_site_url', $received_site_url, false );
	update_option( 'wp_health_check_dashboard_origin', $dashboard_origin, false );
	update_option( 'wp_health_check_enrolled_at', gmdate( 'c' ), false );
	update_option( 'wp_health_check_enrolled_ip', wphc_get_client_ip(), false );
	// issued_at e' solo memorizzato come metadato operativo (non usato
	// per alcuna logica di scadenza, che per progetto non esiste).
	update_option( 'wp_health_check_enroll_issued_at', $issued_at, false );
	// Enroll riuscito: azzera l'eventuale ultimo errore diagnostico.
	delete_option( 'wp_health_check_last_enroll_error' );

	// 5. Conferma: si restituisce il site_url firmato realmente registrato.
	return rest_ensure_response(
		array(
			'enrolled'      => true,
			'site'          => $received_site_url,
			'agent_version' => WP_HEALTH_CHECK_VERSION,
		)
	);
}

// -----------------------------------------------------------------------
// LETTURA ROBUSTA DEI TRANSIENT DI UPDATE
// -----------------------------------------------------------------------

/**
 * Neutralizza temporaneamente gli short-circuit "pre_site_transient_update_*".
 * Alcuni plugin/ottimizzazioni registrano FUORI dall'admin filtri come
 * add_filter( 'pre_site_transient_update_plugins', '__return_null' ) per
 * disabilitare i controlli di aggiornamento su frontend/REST: quel filtro fa
 * si' che get_site_transient( 'update_plugins' ) restituisca null (cortocircuito
 * del core), quindi in contesto REST i conteggi update risulterebbero 0 anche
 * con aggiornamenti realmente presenti, a differenza di quanto vede
 * l'amministratore (dove quei filtri di norma non sono attivi).
 *
 * Rimuove solo i filtri "pre_" (il cortocircuito): NON tocca i filtri di
 * lettura "site_transient_update_*", cosi' le iniezioni legittime dei plugin
 * premium (es. ACF, Gravity Forms) restano attive. Va sempre seguita da
 * wphc_restore_update_shortcircuit() con il valore restituito.
 *
 * @return array<string, mixed> Filtri rimossi, da ripristinare.
 */
function wphc_mute_update_shortcircuit() {
	global $wp_filter;
	$hooks = array( 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'pre_site_transient_update_core' );
	$saved = array();
	foreach ( $hooks as $hook ) {
		if ( isset( $wp_filter[ $hook ] ) ) {
			$saved[ $hook ] = $wp_filter[ $hook ];
			unset( $wp_filter[ $hook ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- rimozione temporanea e controllata, ripristinata subito da wphc_restore_update_shortcircuit().
		}
	}
	return $saved;
}

/**
 * Ripristina gli short-circuit rimossi da wphc_mute_update_shortcircuit().
 *
 * @param array<string, mixed> $saved Valore restituito da wphc_mute_update_shortcircuit().
 */
function wphc_restore_update_shortcircuit( $saved ) {
	global $wp_filter;
	foreach ( $saved as $hook => $obj ) {
		$wp_filter[ $hook ] = $obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ripristino del valore salvato da wphc_mute_update_shortcircuit().
	}
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
		// ?fresh=1 BYPASSA le cache locali (payload wphc + liste plugin/temi)
		// e rilegge lo stato di aggiornamento CORRENTE del sito, cioe' quello
		// che vede anche l'amministratore. Svuota le cache delle liste cosi'
		// get_plugins()/wp_get_themes() riscansionano la cartella (totali
		// corretti anche dietro object cache persistente). false = NON tocca
		// i transient update_plugins/update_themes.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );

		// IMPORTANTE: qui NON si chiama wp_update_plugins()/wp_update_themes()/
		// wp_version_check(). In una richiesta REST i plugin/temi PREMIUM (che
		// si aggiornano da server propri, non da wordpress.org) non caricano i
		// loro update-checker: una wp_update_plugins() in questo contesto
		// ricostruirebbe il transient update_plugins SENZA i loro aggiornamenti
		// e SOVRASCRIVEREBBE quello completo mantenuto dal cron (che gira
		// caricando tutti i plugin), riportando conteggi errati (es. 0 invece
		// di 11) e corrompendo anche il dato mostrato all'amministratore. Si
		// leggono quindi i transient gia' mantenuti dal cron. La freschezza del
		// controllo update e' comunque esposta in "updates_checked_at".
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
	// il controllo di capability. I transient vengono letti neutralizzando
	// gli short-circuit "pre_site_transient_update_*" che alcuni siti
	// registrano fuori dall'admin (vedi wphc_mute_update_shortcircuit): senza
	// questo, in contesto REST i conteggi risulterebbero 0 anche con
	// aggiornamenti reali.
	$muted_updates            = wphc_mute_update_shortcircuit();
	$update_plugins_transient = get_site_transient( 'update_plugins' );
	$plugins_updates_count    = ( is_object( $update_plugins_transient ) && ! empty( $update_plugins_transient->response ) )
		? count( $update_plugins_transient->response )
		: 0;

	$update_themes_transient = get_site_transient( 'update_themes' );
	$themes_updates_count    = ( is_object( $update_themes_transient ) && ! empty( $update_themes_transient->response ) )
		? count( $update_themes_transient->response )
		: 0;

	$core_updates = get_core_updates( array( 'dismissed' => false ) );
	wphc_restore_update_shortcircuit( $muted_updates );

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

	// Segnali booleani (consent manager GDPR, page builder) calcolati dai
	// plugin/temi attivi. Operazioni O(1) su liste gia' disponibili: coerente
	// col contratto "economico" di /health.
	$signals = wphc_detect_site_signals();

	// Aggiornamento plugin/temi/core via API (vedi sezione dedicata piu'
	// sotto nel file): tre letture O(1) (due opzioni, un file_exists),
	// coerenti col contratto economico di questa rotta.
	$maintenance_file  = wphc_maintenance_file_path();
	$maintenance_stuck = file_exists( $maintenance_file ) && ( time() - (int) filemtime( $maintenance_file ) ) > 600;
	$last_update       = get_option( 'wp_health_check_last_update' );

	$payload = array(
		'site'                => wphc_normalize_site_url(),
		'generated_at'        => gmdate( 'c' ),
		'fleet_agent_version' => WP_HEALTH_CHECK_VERSION,
		'summary'             => array(
			'wp_version'              => get_bloginfo( 'version' ),
			'php_version'             => PHP_VERSION,
			'php_memory_limit'        => (string) ini_get( 'memory_limit' ),
			'server_ip'               => '' !== $server_ip ? $server_ip : null,
			'plugin_version'          => WP_HEALTH_CHECK_VERSION,
			'plugins_total'           => count( $all_plugins ),
			'plugins_active'          => count( $active_plugins ),
			'plugins_updates'         => $plugins_updates_count,
			'themes_total'            => count( $all_themes ),
			'themes_updates'          => $themes_updates_count,
			'theme_name'              => $active_theme_name,
			'parent_theme_name'       => $parent_theme_name,
			'core_update'             => $core_update_available,
			'has_gdpr'                => $signals['has_gdpr'],
			'has_builder'             => $signals['has_builder'],
			'mu_dir_writable'         => (bool) wp_is_writable( WPMU_PLUGIN_DIR ),
			'updates_checked_at'      => $updates_checked_at,
			'updates_via_api_enabled' => (bool) get_option( 'wp_health_check_updates_enabled', false ),
			'last_update'             => $last_update ? $last_update : null,
			'maintenance_stuck'       => $maintenance_stuck,
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
		// ?fresh=1 svuota la cache della lista plugin, cosi' get_plugins()
		// qui sotto riscansiona la cartella e il conteggio e' corretto anche
		// dietro un object cache persistente mal configurato. false = non
		// tocca il transient update_plugins. NON si chiama wp_update_plugins():
		// in contesto REST ricostruirebbe il transient senza gli aggiornamenti
		// dei plugin premium (che si aggiornano da server propri e non caricano
		// il loro update-checker qui), sovrascrivendo quello completo del cron
		// e riportando conteggi/versioni errati. Si legge il transient del cron.
		wp_clean_plugins_cache( false );
	}

	$all_plugins = get_plugins();
	// get_plugin_updates() legge internamente get_site_transient('update_plugins'):
	// si neutralizza lo short-circuit "pre_" attorno alla chiamata, altrimenti
	// in contesto REST tornerebbe vuoto su siti che disabilitano i controlli
	// update fuori dall'admin (vedi wphc_mute_update_shortcircuit).
	$muted_updates  = wphc_mute_update_shortcircuit();
	$plugin_updates = get_plugin_updates();
	wphc_restore_update_shortcircuit( $muted_updates );
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
		// ?fresh=1 svuota la cache delle liste temi (riscansione), ma NON
		// chiama wp_update_themes(): in contesto REST i temi premium non
		// caricano il loro update-checker, quindi una wp_update_themes() qui
		// sovrascriverebbe il transient update_themes del cron perdendo i loro
		// aggiornamenti. Si legge il transient gia' mantenuto dal cron.
		wp_clean_themes_cache( false );
	}

	$active_theme = wp_get_theme();
	// Come per i plugin: neutralizza lo short-circuit "pre_" attorno a
	// get_theme_updates() (che legge get_site_transient('update_themes')).
	$muted_updates = wphc_mute_update_shortcircuit();
	$theme_updates = get_theme_updates();
	wphc_restore_update_shortcircuit( $muted_updates );
	$stylesheet = $active_theme->get_stylesheet();

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

	// Elenco completo dei temi installati (non solo l'attivo): richiesto dalla
	// dashboard per mostrare tutti i temi presenti sul sito. wp_get_themes()
	// fa una scansione della cartella temi con cache interna di WP, nessuna
	// chiamata remota. Lo stato aggiornamenti riusa $theme_updates gia' letto
	// sopra (stessa neutralizzazione dello short-circuit "pre_"), quindi non
	// comporta ulteriori accessi ai transient.
	$all_themes        = wp_get_themes();
	$active_stylesheet = $stylesheet;
	$themes            = array();
	foreach ( $all_themes as $theme_stylesheet => $theme ) {
		/** @var array<string,string>|false $item_update */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$item_update      = isset( $theme_updates[ $theme_stylesheet ] ) ? $theme_updates[ $theme_stylesheet ]->update : false;
		$item_has_update  = is_array( $item_update ) && isset( $item_update['new_version'] );
		$item_new_version = $item_has_update ? $item_update['new_version'] : null;

		$themes[] = array(
			'name'             => $theme->get( 'Name' ),
			'stylesheet'       => (string) $theme_stylesheet,
			'version'          => $theme->get( 'Version' ),
			'active'           => ( (string) $theme_stylesheet === $active_stylesheet ),
			// get_template() restituisce lo stylesheet del parent per un child
			// theme, oppure quello del tema stesso se non e' un child: si
			// espone il parent solo nel primo caso, altrimenti null.
			'parent'           => $theme->parent() ? $theme->get_template() : null,
			'update_available' => $item_has_update,
			'new_version'      => $item_new_version,
		);
	}

	$payload = array(
		'site'         => wphc_normalize_site_url(),
		'generated_at' => gmdate( 'c' ),
		'active_theme' => $active_theme_payload,
		'parent_theme' => $parent_theme_payload,
		'themes'       => $themes,
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
 * Logica CONDIVISA fra la rotta REST POST /update e il pulsante nella tab
 * Site Health: restituisce un array normalizzato, che ciascun chiamante
 * mappa nel proprio formato (WP_REST_Response/WP_Error, oppure messaggio
 * di redirect admin). Non registra l'accesso ne' invia risposte HTTP:
 * quelle sono responsabilita' del chiamante.
 *
 * @return array<string, mixed> Esito normalizzato. La chiave 'result' e' uno di:
 *         'updated' (con 'from'/'to'), 'up_to_date' (con 'current'/'latest'),
 *         'not_writable', 'integrity_check_failed', oppure 'error' (con
 *         'code', 'message', 'http').
 */
function wphc_perform_self_update() {
	$current_version = WP_HEALTH_CHECK_VERSION;

	// 1. Interroga la release piu' recente pubblicata su GitHub.
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
		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_network_error',
			'message' => __( 'Impossibile contattare GitHub.', 'wp-health-check' ),
			'http'    => 502,
		);
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_github_error',
			'message' => __( 'GitHub ha risposto con un errore nel recuperare la release.', 'wp-health-check' ),
			'http'    => 502,
		);
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_bad_release',
			'message' => __( 'Risposta di GitHub non valida.', 'wp-health-check' ),
			'http'    => 502,
		);
	}

	// 2. Normalizza il tag (rimuove un eventuale prefisso "v") e confronta.
	$latest_tag = ltrim( (string) $release['tag_name'], 'v' );
	if ( ! version_compare( $latest_tag, $current_version, '>' ) ) {
		return array(
			'result'  => 'up_to_date',
			'current' => $current_version,
			'latest'  => $latest_tag,
		);
	}

	// 3. Preflight di scrittura: verifica PRIMA di scaricare qualunque
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
		return array( 'result' => 'not_writable' );
	}

	// 4. Individua gli asset della release: il file del plugin e il suo
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

		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_asset_missing',
			'message' => __( 'Asset wp-health-check.php non trovato nella release.', 'wp-health-check' ),
			'http'    => 502,
		);
	}

	$download = wp_remote_get( $asset_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $download ) || 200 !== (int) wp_remote_retrieve_response_code( $download ) ) {
		@unlink( $test_file );

		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_download_failed',
			'message' => __( 'Impossibile scaricare il nuovo file del plugin.', 'wp-health-check' ),
			'http'    => 502,
		);
	}
	$new_contents = wp_remote_retrieve_body( $download );

	// 5. Verifica di integrita', PRIMA di toccare qualunque file di
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

		return array( 'result' => 'integrity_check_failed' );
	}

	// 6. Backup del file corrente, prima di qualsiasi scrittura.
	$plugin_file = __FILE__;
	$backup_file = $plugin_file . '.bak';
	if ( false === @copy( $plugin_file, $backup_file ) ) {
		@unlink( $test_file );

		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_backup_failed',
			'message' => __( 'Impossibile creare il backup prima di aggiornare.', 'wp-health-check' ),
			'http'    => 500,
		);
	}

	// 7. Scrittura atomica: il temporaneo NON ha estensione .php di
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

		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_write_failed',
			'message' => __( 'Scrittura del nuovo file fallita.', 'wp-health-check' ),
			'http'    => 500,
		);
	}

	// 8. Sanity check post-scrittura: se qualcosa non torna, ripristina
	// immediatamente dal backup del punto 6.
	$written_contents = @file_get_contents( $plugin_file );
	if ( false === $written_contents || '' === $written_contents || 0 !== strpos( $written_contents, '<?php' ) ) {
		@copy( $backup_file, $plugin_file );
		@unlink( $test_file );

		return array(
			'result'  => 'error',
			'code'    => 'wphc_update_sanity_failed',
			'message' => __( 'Verifica post-scrittura fallita: ripristinato il backup precedente.', 'wp-health-check' ),
			'http'    => 500,
		);
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// 9. Invalida l'opcode cache per la nuova versione: senza questo
	// passo, sui SAPI con opcache persistente (es. php-fpm) la vecchia
	// versione compilata resterebbe in uso fino al riavvio del pool.
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $plugin_file, true );
	}

	// 10. Rimuove il file di test di scrivibilita' del punto 3 e invalida
	// la cache della "ultima versione" (ora e' quella installata).
	@unlink( $test_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	delete_transient( 'wphc_latest_version_cache' );

	if ( WP_DEBUG ) {
		error_log( sprintf( 'wp-health-check: aggiornato da %1$s a %2$s', $current_version, $latest_tag ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	return array(
		'result' => 'updated',
		'from'   => $current_version,
		'to'     => $latest_tag,
	);
}

/**
 * Callback della rotta REST POST /update: registra l'accesso, esegue il
 * self-update condiviso e mappa l'esito normalizzato nel formato REST
 * storico (invariato rispetto alle versioni precedenti).
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error Esito dell'update.
 */
function wphc_route_update( WP_REST_Request $request ) {
	unset( $request );

	// L'autenticazione e' gia' garantita dal permission_callback; qui si
	// registra soltanto l'accesso, come per ogni chiamata dati autenticata.
	wphc_record_access();

	$outcome = wphc_perform_self_update();

	switch ( $outcome['result'] ) {
		case 'error':
			return new WP_Error( $outcome['code'], $outcome['message'], array( 'status' => $outcome['http'] ) );

		case 'updated':
			return rest_ensure_response(
				array(
					'updated' => true,
					'from'    => $outcome['from'],
					'to'      => $outcome['to'],
				)
			);

		case 'up_to_date':
			return rest_ensure_response(
				array(
					'updated' => false,
					'reason'  => 'up_to_date',
					'current' => $outcome['current'],
					'latest'  => $outcome['latest'],
				)
			);

		default: // esiti non riusciti ma non erronei: not_writable / integrity_check_failed.
			return rest_ensure_response(
				array(
					'updated' => false,
					'reason'  => $outcome['result'],
				)
			);
	}
}

/**
 * Restituisce l'ultima versione (tag senza "v") pubblicata su GitHub,
 * cachata 1h in un transient per non interrogare l'API ad ogni render
 * della tab Site Health. Solo lettura: non tocca il filesystem. Usata dal
 * pannello admin per mostrare se esiste un aggiornamento.
 *
 * @param bool $force Se true, ignora la cache e reinterroga GitHub.
 * @return string|null Ultima versione disponibile, o null se non determinabile.
 */
function wphc_get_latest_version( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( 'wphc_latest_version_cache' );
		if ( false !== $cached ) {
			return '' !== $cached ? $cached : null;
		}
	}

	$api_url = sprintf(
		'https://api.github.com/repos/%s/%s/releases/latest',
		rawurlencode( WP_HEALTH_CHECK_GH_OWNER ),
		rawurlencode( WP_HEALTH_CHECK_GH_REPO )
	);

	$response = wp_remote_get(
		$api_url,
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'wp-health-check-agent',
			),
		)
	);

	$latest = '';
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $release ) && ! empty( $release['tag_name'] ) ) {
			$latest = ltrim( (string) $release['tag_name'], 'v' );
		}
	}

	// Cache breve anche in caso di fallimento (stringa vuota), per non
	// martellare GitHub ad ogni apertura della tab se l'API e' irraggiungibile.
	set_transient( 'wphc_latest_version_cache', $latest, HOUR_IN_SECONDS );

	return '' !== $latest ? $latest : null;
}

// -----------------------------------------------------------------------
// AGGIORNAMENTO PLUGIN/TEMI/CORE VIA API (software di terze parti)
// -----------------------------------------------------------------------
//
// Distinto dal self-update dell'agent sopra: qui si aggiornano plugin,
// temi e il core del sito appoggiandosi alle primitive del core WordPress
// (Plugin_Upgrader / Theme_Upgrader / Core_Upgrader), che gia' sanno
// scaricare, scompattare, sostituire cartelle ed eseguire il rollback via
// temp-backup nativo (WP 6.3+). Vedi docs/plugin-update-via-api-specifiche.md
// per il contratto REST completo.
//
// Vincolo di sicurezza non negoziabile: la richiesta indica solo QUALE
// elemento aggiornare, MAI da dove ne' a quale versione. La sorgente del
// pacchetto e' sempre e solo quella che il core ha gia' determinato nel
// proprio transient di update (mai un valore preso dal payload).

/**
 * Nome (con prefisso multisite-aware) della tabella di log degli update
 * plugin/temi/core. Centralizzato qui perche' usato sia dallo schema sia
 * dalle query di lettura/scrittura.
 *
 * @return string Nome completo della tabella.
 */
function wphc_update_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'wphc_update_log';
}

/**
 * Crea/allinea la tabella di log degli update con dbDelta(), solo quando
 * lo schema installato (wp_health_check_db_version) non combacia con
 * quello atteso. I mu-plugin non hanno hook di attivazione: questo e' il
 * sostituto, gated da un'opzione autoloaded cosi' il controllo resta O(1)
 * a ogni richiesta salvo la prima dopo un deploy o un bump di schema.
 */
function wphc_maybe_install_update_log_schema() {
	if ( get_option( 'wp_health_check_db_version' ) === WP_HEALTH_CHECK_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = wphc_update_log_table();
	$charset_collate = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name e' costruito da $wpdb->prefix (non input utente); sintassi standard dbDelta() da Codex.
	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		correlation_id CHAR(16) NOT NULL,
		created_at DATETIME NOT NULL,
		type VARCHAR(10) NOT NULL,
		target VARCHAR(191) NOT NULL,
		name VARCHAR(191) NOT NULL,
		version_from VARCHAR(32) DEFAULT NULL,
		version_to VARCHAR(32) DEFAULT NULL,
		phase VARCHAR(16) NOT NULL,
		message VARCHAR(255) DEFAULT NULL,
		ip VARCHAR(45) DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY correlation_id (correlation_id),
		KEY type_created_at (type, created_at)
	) {$charset_collate};";

	dbDelta( $sql );

	update_option( 'wp_health_check_db_version', WP_HEALTH_CHECK_DB_VERSION );
}
add_action( 'init', 'wphc_maybe_install_update_log_schema' );

/**
 * Inserisce una riga nella tabella di log update: il pattern richiesto e'
 * a DUE righe per operazione, stesso correlation_id. Una PRIMA di toccare
 * qualunque file (phase 'requested': resta come prova che un aggiornamento
 * e' stato avviato anche se PHP muore a meta'), una al termine
 * ('completed' / 'failed' / 'rolled_back').
 *
 * @param string      $correlation_id Lega le righe della stessa operazione.
 * @param string      $type           'plugin' | 'theme' | 'core'.
 * @param string      $target         Plugin file, stylesheet, oppure 'core'.
 * @param string      $name           Nome leggibile dell'elemento.
 * @param string|null $version_from   Versione installata prima dell'update.
 * @param string|null $version_to     Versione target/attesa (dal transient del core).
 * @param string      $phase          'requested' | 'completed' | 'failed' | 'rolled_back'.
 * @param string|null $message        Dettaglio in caso di errore/rollback.
 * @return int ID della riga inserita (0 se l'insert fallisce).
 */
function wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, $phase, $message = null ) {
	global $wpdb;

	$wpdb->insert(
		wphc_update_log_table(),
		array(
			'correlation_id' => $correlation_id,
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'type'           => $type,
			'target'         => $target,
			'name'           => $name,
			'version_from'   => $version_from,
			'version_to'     => $version_to,
			'phase'          => $phase,
			'message'        => $message,
			'ip'             => wphc_get_client_ip(),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

/**
 * Prune opportunistico delle righe di log piu' vecchie della retention
 * (WP_HEALTH_CHECK_LOG_RETENTION_DAYS), al massimo una volta al giorno
 * (gate via transient): evita una crescita illimitata della tabella senza
 * dipendere da un cron dedicato.
 */
function wphc_maybe_prune_update_log() {
	if ( false !== get_transient( 'wp_health_check_log_pruned_at' ) ) {
		return;
	}

	global $wpdb;
	$table     = wphc_update_log_table();
	$threshold = gmdate( 'Y-m-d H:i:s', time() - ( WP_HEALTH_CHECK_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabella custom non cacheata dall'object cache di WP; $table e' un nome fisso ($wpdb->prefix), non input; $threshold e' comunque passato via prepare().
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold ) );

	set_transient( 'wp_health_check_log_pruned_at', time(), DAY_IN_SECONDS );
}

/**
 * Aggiorna wp_health_check_last_update (opzione autoloaded) con l'esito
 * dell'ultima operazione di update. Usata da /health (§9 della specifica)
 * per esporre "last_update" senza una query alla tabella di log nel path
 * di polling frequente, che deve restare O(1) (vedi wphc_route_health()).
 *
 * @param string $type   'plugin' | 'theme' | 'core'.
 * @param string $target Plugin file, stylesheet, oppure 'core'.
 * @param string $phase  'completed' | 'failed' | 'rolled_back'.
 */
function wphc_record_last_update( $type, $target, $phase ) {
	update_option(
		'wp_health_check_last_update',
		array(
			'type'   => $type,
			'target' => $target,
			'phase'  => $phase,
			'at'     => gmdate( 'c' ),
		)
	);
}

/**
 * Rilascia il lock anti-concorrenza acquisito da wphc_update_preflight().
 * Registrata anche come register_shutdown_function: cosi' il lock si
 * libera comunque anche se PHP muore (timeout, fatal) a meta' di un
 * update, invece di restare bloccato su 409 locked fino allo scadere
 * naturale del TTL del transient.
 */
function wphc_release_update_lock() {
	delete_transient( 'wp_health_check_update_lock' );
}

/**
 * Percorso del file .maintenance nella root del sito: il core lo crea
 * durante l'upgrade di plugin/temi/core per servire la pagina "in
 * manutenzione" e lo rimuove al termine.
 *
 * @return string Percorso assoluto.
 */
function wphc_maintenance_file_path() {
	return trailingslashit( ABSPATH ) . '.maintenance';
}

/**
 * Rimuove un file .maintenance orfano (piu' vecchio di $max_age_seconds):
 * puo' restare se PHP e' morto a meta' di un upgrade precedente (timeout,
 * OOM). Va eseguita PRIMA di ogni nuovo tentativo di update, altrimenti il
 * core la troverebbe gia' presente e servirebbe la pagina di manutenzione
 * anche durante il nuovo tentativo.
 *
 * @param int $max_age_seconds Eta' massima tollerata prima di considerarla orfana.
 */
function wphc_clear_stale_maintenance( $max_age_seconds = 600 ) {
	$file = wphc_maintenance_file_path();
	if ( file_exists( $file ) && ( time() - (int) filemtime( $file ) ) > $max_age_seconds ) {
		wp_delete_file( $file );
	}
}

/**
 * Verifica che l'host del pacchetto di update sia nell'allowlist
 * wordpress.org: unico controllo che impedisce a un plugin/tema premium
 * (con update-checker proprio, package su host terzi) di essere scaricato
 * ed eseguito da questa rotta. Copre anche il caso di un transient
 * avvelenato da un filtro di terze parti.
 *
 * @param string $package_url URL del pacchetto da scaricare.
 * @return bool True se l'host e' ammesso.
 */
function wphc_is_package_host_allowed( $package_url ) {
	$host = wp_parse_url( (string) $package_url, PHP_URL_HOST );
	return in_array( $host, array( 'downloads.wordpress.org', 'api.wordpress.org' ), true );
}

/**
 * Genera un identificativo di correlazione a 16 caratteri esadecimali, per
 * legare la riga 'requested' e la riga finale della stessa operazione di
 * update nella tabella di log.
 *
 * @return string Correlation id, 16 caratteri hex.
 */
function wphc_generate_correlation_id() {
	return bin2hex( random_bytes( 8 ) );
}

/**
 * True se la richiesta chiede una simulazione (?check=1): non esegue
 * alcun update, verifica solo se l'elemento e' aggiornabile (dry-run,
 * migliora la UX della dashboard che puo' sapere in anticipo cosa e'
 * aggiornabile senza eseguire nulla).
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return bool
 */
function wphc_request_wants_check( WP_REST_Request $request ) {
	return (bool) $request->get_param( 'check' );
}

/**
 * Preambolo comune a tutte le rotte di update di terze parti: accesso,
 * kill-switch, requisito versione WP (solo plugin/temi, per la garanzia di
 * rollback via temp-backup nativo), lock anti-concorrenza, preflight
 * filesystem, pulizia .maintenance orfano, prune opportunistico del log.
 * Va chiamato PRIMA di qualsiasi include pesante o lettura di transient di
 * update.
 *
 * In caso di successo acquisisce il lock: il chiamante e' responsabile di
 * rilasciarlo con wphc_release_update_lock() su OGNI uscita successiva
 * (il rilascio e' comunque garantito anche in caso di crash, vedi sopra).
 *
 * @param bool $requires_wp63 True per plugin/temi (richiede WP >= 6.3); false per il core.
 * @return true|array True se si puo' procedere, altrimenti array-esito con
 *                     chiavi 'result' e 'http' da restituire cosi' com'e'.
 */
function wphc_update_preflight( $requires_wp63 ) {
	wphc_record_access();

	if ( ! get_option( 'wp_health_check_updates_enabled', false ) ) {
		return array(
			'result' => 'disabled',
			'http'   => 403,
		);
	}

	if ( $requires_wp63 && version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
		// Scelta fail-safe: sotto WP 6.3 non esiste il temp-backup nativo,
		// quindi non si tenta l'update senza una rete di sicurezza equivalente.
		return array(
			'result' => 'unsupported_wp_version',
			'http'   => 200,
		);
	}

	if ( false !== get_transient( 'wp_health_check_update_lock' ) ) {
		return array(
			'result' => 'locked',
			'http'   => 409,
		);
	}
	set_transient( 'wp_health_check_update_lock', 1, WP_HEALTH_CHECK_UPDATE_LOCK_TTL );
	register_shutdown_function( 'wphc_release_update_lock' );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( 'direct' !== get_filesystem_method() ) {
		wphc_release_update_lock();
		return array(
			'result' => 'fs_method_unavailable',
			'http'   => 200,
		);
	}

	wphc_clear_stale_maintenance();
	wphc_maybe_prune_update_log();

	return true;
}

/**
 * Invalida l'opcache dei file PHP dell'elemento appena aggiornato: sui
 * SAPI con opcache persistente (es. php-fpm) i vecchi file compilati
 * resterebbero altrimenti in uso fino al riavvio del pool, anche dopo che
 * il filesystem e' gia' stato sostituito.
 *
 * @param string $type   'plugin' | 'theme'.
 * @param string $target Plugin file oppure stylesheet.
 */
function wphc_maybe_invalidate_item_opcache( $type, $target ) {
	if ( ! function_exists( 'opcache_invalidate' ) ) {
		return;
	}

	if ( 'plugin' === $type ) {
		$file = WP_PLUGIN_DIR . '/' . $target;
		if ( file_exists( $file ) ) {
			opcache_invalidate( $file, true );
		}
		return;
	}

	$theme = wp_get_theme( $target );
	if ( $theme->exists() ) {
		$functions_php = trailingslashit( $theme->get_stylesheet_directory() ) . 'functions.php';
		if ( file_exists( $functions_php ) ) {
			opcache_invalidate( $functions_php, true );
		}
	}
}

/**
 * Esegue (o simula, se $dry_run) l'aggiornamento di un singolo plugin o
 * tema tramite le primitive del core (Plugin_Upgrader / Theme_Upgrader),
 * con temp-backup/rollback nativo (WP 6.3+, garantito dal preflight in
 * wphc_update_preflight()). Condivisa da wphc_route_update_plugin() e
 * wphc_route_update_theme(): plugin e temi seguono esattamente lo stesso
 * flusso, cambia solo quale classe/funzioni del core si usano e la forma
 * (oggetto per i plugin, array per i temi) dell'entry nel transient di
 * update — una particolarita' del core, non un refuso qui.
 *
 * @param string $type    'plugin' | 'theme'.
 * @param string $target  Plugin file (chiave di get_plugins()) oppure stylesheet.
 * @param bool   $dry_run True per un controllo senza eseguire l'update (?check=1).
 * @return array Esito normalizzato, vedi wphc_map_item_update_outcome() per il mapping REST.
 */
function wphc_perform_item_update( $type, $target, $dry_run = false ) {
	$preflight = wphc_update_preflight( true );
	if ( true !== $preflight ) {
		return $preflight;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	if ( 'plugin' === $type ) {
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

		$all_plugins = get_plugins();
		if ( ! isset( $all_plugins[ $target ] ) ) {
			wphc_release_update_lock();
			return array(
				'result' => 'not_found',
				'http'   => 200,
			);
		}
		$name         = $all_plugins[ $target ]['Name'];
		$version_from = $all_plugins[ $target ]['Version'];

		wp_clean_plugins_cache( false );
		$muted     = wphc_mute_update_shortcircuit();
		$transient = get_site_transient( 'update_plugins' );
		wphc_restore_update_shortcircuit( $muted );

		// Le entry di update_plugins->response sono OGGETTI (a differenza
		// dei temi, vedi ramo sotto: e' cosi' che il core popola i due
		// transient, non una scelta di questo file).
		$update     = ( is_object( $transient ) && isset( $transient->response[ $target ] ) ) ? $transient->response[ $target ] : null;
		$package    = ( null !== $update && isset( $update->package ) ) ? (string) $update->package : '';
		$version_to = ( null !== $update && isset( $update->new_version ) ) ? (string) $update->new_version : '';
	} else {
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';

		$theme = wp_get_theme( $target );
		if ( ! $theme->exists() ) {
			wphc_release_update_lock();
			return array(
				'result' => 'not_found',
				'http'   => 200,
			);
		}
		$name         = (string) $theme->get( 'Name' );
		$version_from = (string) $theme->get( 'Version' );

		wp_clean_themes_cache( false );
		$muted     = wphc_mute_update_shortcircuit();
		$transient = get_site_transient( 'update_themes' );
		wphc_restore_update_shortcircuit( $muted );

		// Le entry di update_themes->response sono ARRAY (a differenza dei
		// plugin sopra): stessa nota, e' una particolarita' del core.
		$update     = ( is_object( $transient ) && isset( $transient->response[ $target ] ) ) ? $transient->response[ $target ] : null;
		$package    = ( null !== $update && isset( $update['package'] ) ) ? (string) $update['package'] : '';
		$version_to = ( null !== $update && isset( $update['new_version'] ) ) ? (string) $update['new_version'] : '';
	}

	if ( null === $update ) {
		wphc_release_update_lock();
		return array(
			'result'  => 'up_to_date',
			'current' => $version_from,
			'http'    => 200,
		);
	}

	if ( '' === $package || ! wphc_is_package_host_allowed( $package ) ) {
		wphc_release_update_lock();
		return array(
			'result' => 'not_updatable',
			'detail' => __( 'pacchetto non ospitato su wordpress.org', 'wp-health-check' ),
			'http'   => 200,
		);
	}

	if ( $dry_run ) {
		wphc_release_update_lock();
		return array(
			'result'  => 'updatable',
			'type'    => $type,
			'target'  => $target,
			'name'    => $name,
			'current' => $version_from,
			'latest'  => $version_to,
			'http'    => 200,
		);
	}

	$correlation_id = wphc_generate_correlation_id();
	wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'requested' );

	$skin     = new Automatic_Upgrader_Skin(); // Nessun output HTML: la richiesta e' REST, non una pagina admin.
	$upgrader = ( 'plugin' === $type ) ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
	$result   = $upgrader->upgrade( $target, array( 'clear_update_cache' => false ) );

	$exists         = false;
	$actual_version = null;
	if ( 'plugin' === $type ) {
		$fresh_plugins  = get_plugins();
		$exists         = isset( $fresh_plugins[ $target ] );
		$actual_version = $exists ? $fresh_plugins[ $target ]['Version'] : null;
	} else {
		$fresh_theme    = wp_get_theme( $target );
		$exists         = $fresh_theme->exists();
		$actual_version = $exists ? (string) $fresh_theme->get( 'Version' ) : null;
	}

	wphc_maybe_invalidate_item_opcache( $type, $target );
	if ( 'plugin' === $type ) {
		wp_clean_plugins_cache( true );
	} else {
		wp_clean_themes_cache( true );
	}
	delete_transient( 'wphc_health_cache' );
	delete_transient( 'wphc_detail_plugins_cache' );
	delete_transient( 'wphc_detail_theme_cache' );

	if ( is_wp_error( $result ) || $actual_version !== $version_to ) {
		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Verifica post-aggiornamento fallita.', 'wp-health-check' );

		// Se l'elemento e' presente ed e' tornato alla versione originale,
		// il temp-backup nativo (WP 6.3+) ha gia' ripristinato con
		// successo: si registra 'rolled_back'. Altrimenti lo stato e'
		// incerto (elemento mancante o a una versione imprevista):
		// 'failed', da verificare manualmente sul sito.
		$phase = ( $exists && $actual_version === $version_from ) ? 'rolled_back' : 'failed';

		$log_id = wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, $phase, $message );
		wphc_record_last_update( $type, $target, $phase );
		wphc_release_update_lock();

		return array(
			'result' => $phase,
			'detail' => $message,
			'log_id' => $log_id,
			'http'   => 200,
		);
	}

	$log_id = wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'completed' );
	wphc_record_last_update( $type, $target, 'completed' );
	wphc_release_update_lock();

	return array(
		'result' => 'updated',
		'type'   => $type,
		'target' => $target,
		'name'   => $name,
		'from'   => $version_from,
		'to'     => $version_to,
		'log_id' => $log_id,
		'http'   => 200,
	);
}

/**
 * Esegue (o simula) l'aggiornamento del core WordPress tramite
 * Core_Upgrader. A differenza di plugin/temi, il core NON usa il
 * temp-backup nativo: il suo meccanismo di rollback e' quello proprio
 * dell'upgrader, una garanzia diversa e piu' debole (per questo il
 * requisito WP 6.3 non si applica a questo ramo: non ci sarebbe comunque
 * un temp-backup da richiedere). Dopo la sostituzione dei file invoca
 * wp_upgrade() per completare l'aggiornamento del database in contesto
 * headless, dove nessuna visita a wp-admin/upgrade.php lo farebbe altrimenti.
 *
 * @param bool $dry_run True per un controllo senza eseguire l'update (?check=1).
 * @return array Esito normalizzato, vedi wphc_map_item_update_outcome().
 */
function wphc_perform_core_update( $dry_run = false ) {
	$preflight = wphc_update_preflight( false );
	if ( true !== $preflight ) {
		return $preflight;
	}

	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';

	$version_from = get_bloginfo( 'version' );

	// Stessa lettura usata dal core in wp-admin/update-core.php: l'indice 0
	// e' l'update che WordPress stesso ha determinato disponibile per
	// questo sito (versione e locale), mai una versione indicata dal
	// chiamante.
	$core_updates = get_core_updates( array( 'dismissed' => false ) );
	$update       = ( is_array( $core_updates ) && isset( $core_updates[0] ) ) ? $core_updates[0] : null;

	if ( null === $update || ! isset( $update->response ) || in_array( $update->response, array( 'development', 'latest' ), true ) ) {
		wphc_release_update_lock();
		return array(
			'result'  => 'up_to_date',
			'current' => $version_from,
			'http'    => 200,
		);
	}

	$version_to = isset( $update->current ) ? (string) $update->current : '';

	// Gli update object del core non hanno un campo ->package come
	// plugin/temi: il campo equivalente e' ->download, che per un update
	// standard (risposta diversa da 'development'/'autoupdate' parziale)
	// coincide con ->packages->full, cioe' il pacchetto che Core_Upgrader
	// scarica davvero nel ramo che percorriamo qui sotto.
	if ( empty( $update->download ) || ! wphc_is_package_host_allowed( $update->download ) ) {
		wphc_release_update_lock();
		return array(
			'result' => 'not_updatable',
			'detail' => __( 'pacchetto non ospitato su wordpress.org', 'wp-health-check' ),
			'http'   => 200,
		);
	}

	if ( $dry_run ) {
		wphc_release_update_lock();
		return array(
			'result'  => 'updatable',
			'type'    => 'core',
			'target'  => 'core',
			'name'    => 'WordPress',
			'current' => $version_from,
			'latest'  => $version_to,
			'http'    => 200,
		);
	}

	$correlation_id = wphc_generate_correlation_id();
	wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, 'requested' );

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Core_Upgrader( $skin );
	$result   = $upgrader->upgrade( $update );

	$actual_version = get_bloginfo( 'version' );

	if ( is_wp_error( $result ) || $actual_version !== $version_to ) {
		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Verifica post-aggiornamento fallita.', 'wp-health-check' );
		// Vedi nota sul rollback in cima alla funzione: qui la distinzione
		// rolled_back/failed si basa solo sulla versione osservata dopo il
		// tentativo, non su una garanzia esplicita del Core_Upgrader.
		$phase = ( $actual_version === $version_from ) ? 'rolled_back' : 'failed';

		$log_id = wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, $phase, $message );
		wphc_record_last_update( 'core', 'core', $phase );
		wphc_release_update_lock();

		return array(
			'result' => $phase,
			'detail' => $message,
			'log_id' => $log_id,
			'http'   => 200,
		);
	}

	// Completa l'upgrade del database: in contesto headless (nessuna
	// sessione admin che visiterebbe wp-admin/upgrade.php) le routine di
	// migrazione del DB non partirebbero altrimenti da sole.
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	wp_upgrade();

	if ( function_exists( 'opcache_reset' ) ) {
		// Il core sostituisce centinaia di file in un colpo solo: a
		// differenza del path per singolo plugin/tema, invalidare uno per
		// uno non e' praticabile qui.
		opcache_reset();
	}
	delete_transient( 'wphc_health_cache' );

	$log_id = wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, 'completed' );
	wphc_record_last_update( 'core', 'core', 'completed' );
	wphc_release_update_lock();

	return array(
		'result' => 'updated',
		'type'   => 'core',
		'target' => 'core',
		'name'   => 'WordPress',
		'from'   => $version_from,
		'to'     => $version_to,
		'log_id' => $log_id,
		'http'   => 200,
	);
}

/**
 * Mappa l'array-esito interno (comune a wphc_perform_item_update() e
 * wphc_perform_core_update()) nella risposta REST contrattuale: un
 * WP_Error per gli status non-200 (403 disabled, 409 locked), altrimenti
 * un 200 con "updated" true/false e il dettaglio dell'esito.
 *
 * @param array $outcome Esito interno con almeno le chiavi 'result' e 'http'.
 * @return WP_REST_Response|WP_Error
 */
function wphc_map_item_update_outcome( $outcome ) {
	$result = $outcome['result'];
	$http   = isset( $outcome['http'] ) ? (int) $outcome['http'] : 200;

	if ( 403 === $http ) {
		return new WP_Error( 'wphc_updates_disabled', __( 'Aggiornamenti via API disattivati per questo sito.', 'wp-health-check' ), array( 'status' => 403 ) );
	}
	if ( 409 === $http ) {
		return new WP_Error( 'wphc_update_locked', __( 'Un altro aggiornamento e\' gia\' in corso.', 'wp-health-check' ), array( 'status' => 409 ) );
	}

	if ( 'updated' === $result ) {
		return rest_ensure_response(
			array(
				'updated' => true,
				'type'    => $outcome['type'],
				'target'  => $outcome['target'],
				'name'    => $outcome['name'],
				'from'    => $outcome['from'],
				'to'      => $outcome['to'],
				'log_id'  => $outcome['log_id'],
			)
		);
	}

	if ( 'updatable' === $result ) {
		// Esito del dry-run (?check=1): nessun update e' stato eseguito.
		return rest_ensure_response(
			array(
				'updated' => false,
				'result'  => 'updatable',
				'type'    => $outcome['type'],
				'target'  => $outcome['target'],
				'name'    => $outcome['name'],
				'current' => $outcome['current'],
				'latest'  => $outcome['latest'],
			)
		);
	}

	$response = array(
		'updated' => false,
		'result'  => $result,
	);
	foreach ( array( 'current', 'detail', 'log_id' ) as $key ) {
		if ( isset( $outcome[ $key ] ) ) {
			$response[ $key ] = $outcome[ $key ];
		}
	}

	return rest_ensure_response( $response );
}

/**
 * Callback REST di POST /update/plugin: valida il payload (solo il plugin
 * file, mai una sorgente/versione), poi delega a wphc_perform_item_update().
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error Esito dell'update.
 */
function wphc_route_update_plugin( WP_REST_Request $request ) {
	$plugin = (string) $request->get_param( 'plugin' );
	if ( '' === trim( $plugin ) ) {
		return new WP_Error( 'wphc_missing_plugin', __( 'Campo "plugin" obbligatorio.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	$outcome = wphc_perform_item_update( 'plugin', $plugin, wphc_request_wants_check( $request ) );

	return wphc_map_item_update_outcome( $outcome );
}

/**
 * Callback REST di POST /update/theme: identica a wphc_route_update_plugin()
 * con lo stylesheet come chiave.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error Esito dell'update.
 */
function wphc_route_update_theme( WP_REST_Request $request ) {
	$theme = (string) $request->get_param( 'theme' );
	if ( '' === trim( $theme ) ) {
		return new WP_Error( 'wphc_missing_theme', __( 'Campo "theme" obbligatorio.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	$outcome = wphc_perform_item_update( 'theme', $theme, wphc_request_wants_check( $request ) );

	return wphc_map_item_update_outcome( $outcome );
}

/**
 * Callback REST di POST /update/core: nessun campo obbligatorio nel
 * payload, la versione target e' sempre quella che WordPress stesso ha
 * determinato disponibile.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error Esito dell'update.
 */
function wphc_route_update_core( WP_REST_Request $request ) {
	$outcome = wphc_perform_core_update( wphc_request_wants_check( $request ) );

	return wphc_map_item_update_outcome( $outcome );
}

/**
 * Callback REST di GET /update/log: lettura paginata della tabella di log
 * update. Sola lettura, quindi accessibile anche a kill-switch spento
 * (nessuna chiamata a wphc_update_preflight() qui, deliberatamente).
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response
 */
function wphc_route_update_log( WP_REST_Request $request ) {
	wphc_record_access();

	global $wpdb;
	$table = wphc_update_log_table();

	$type   = (string) $request->get_param( 'type' );
	$limit  = (int) $request->get_param( 'limit' );
	$limit  = $limit > 0 ? min( 200, $limit ) : 50;
	$offset = max( 0, (int) $request->get_param( 'offset' ) );

	$type_filter = in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ? $type : '';

	if ( '' !== $type_filter ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabella custom non cacheata dall'object cache di WP.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s", $type_filter ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table e' un nome fisso ($wpdb->prefix), non input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d OFFSET %d", $type_filter, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabella custom non cacheata dall'object cache di WP.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table e' un nome fisso ($wpdb->prefix), non input; nessun altro dato in questo ramo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$entries = array();
	foreach ( (array) $rows as $row ) {
		$entries[] = array(
			'id'             => (int) $row['id'],
			'correlation_id' => $row['correlation_id'],
			// created_at e' salvato in UTC (gmdate) da wphc_log_update_row(): il
			// suffisso rende esplicito a strtotime() il fuso da usare.
			'created_at'     => gmdate( 'c', strtotime( $row['created_at'] . ' UTC' ) ),
			'type'           => $row['type'],
			'target'         => $row['target'],
			'name'           => $row['name'],
			'version_from'   => $row['version_from'],
			'version_to'     => $row['version_to'],
			'phase'          => $row['phase'],
			'message'        => $row['message'],
			'ip'             => $row['ip'],
		);
	}

	return rest_ensure_response(
		array(
			'site'    => wphc_normalize_site_url(),
			'count'   => count( $entries ),
			'total'   => $total,
			'entries' => $entries,
		)
	);
}

// -----------------------------------------------------------------------
// ROTTA DIAGNOSTICA: GET /debug
// -----------------------------------------------------------------------

/**
 * Permission callback della rotta /debug: solo manage_options (autenticazione
 * WordPress, non bearer token). Chiamabile con una application password.
 *
 * @return bool True se l'utente corrente puo' gestire le opzioni.
 */
function wphc_debug_permission() {
	return current_user_can( 'manage_options' );
}

/**
 * Restituisce l'elenco leggibile dei callback registrati su un hook, con la
 * loro priorita'. Serve a scoprire quali plugin modificano un filtro (es. il
 * transient degli aggiornamenti plugin).
 *
 * @param string $hook Nome dell'hook/filtro.
 * @return string[] Elenco "priorita': callback".
 */
function wphc_debug_hook_callbacks( $hook ) {
	global $wp_filter;
	$out = array();
	if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
		return $out;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn   = $cb['function'];
			$name = 'sconosciuto';
			if ( is_string( $fn ) ) {
				$name = $fn;
			} elseif ( is_array( $fn ) && isset( $fn[0], $fn[1] ) ) {
				$cls  = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				$name = $cls . '::' . $fn[1];
			} elseif ( $fn instanceof Closure ) {
				$name = 'Closure';
			}
			$out[] = $priority . ': ' . $name;
		}
	}
	return $out;
}

/**
 * Rotta diagnostica /debug: aiuta a capire perche' il conteggio degli
 * aggiornamenti plugin visto in admin puo' differire da quello visto via REST
 * (/health). Confronta il transient update_plugins FILTRATO (cio' che
 * /health legge) con quello GREZZO memorizzato (senza i filtri di terze
 * parti) ed elenca i callback registrati sui relativi filtri. Sola lettura.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response Dati diagnostici.
 */
function wphc_route_debug( WP_REST_Request $request ) {
	unset( $request );

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	// 1. Transient FILTRATO: e' esattamente cio' che legge /health.
	$filtered       = get_site_transient( 'update_plugins' );
	$filtered_slugs = ( is_object( $filtered ) && ! empty( $filtered->response ) ) ? array_keys( (array) $filtered->response ) : array();

	// 2. Transient GREZZO memorizzato: si rimuovono temporaneamente i filtri
	// di terze parti su questo transient, si rilegge e si ripristinano. Cosi'
	// si vede cosa il cron ha davvero PERSISTITO, al netto delle iniezioni/
	// rimozioni a runtime dipendenti dal contesto (admin vs REST).
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- muting/ripristino temporaneo e controllato dei filtri per una lettura diagnostica grezza.
	global $wp_filter;
	$hooks_to_mute = array( 'site_transient_update_plugins', 'pre_site_transient_update_plugins' );
	$saved         = array();
	foreach ( $hooks_to_mute as $h ) {
		if ( isset( $wp_filter[ $h ] ) ) {
			$saved[ $h ] = $wp_filter[ $h ];
			unset( $wp_filter[ $h ] );
		}
	}
	$raw = get_site_transient( 'update_plugins' );
	foreach ( $saved as $h => $obj ) {
		$wp_filter[ $h ] = $obj;
	}
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	$raw_slugs = ( is_object( $raw ) && ! empty( $raw->response ) ) ? array_keys( (array) $raw->response ) : array();

	// 3. Cosa vede get_plugin_updates() (usata da /detail/plugins), con lo
	// stesso trattamento del fix (short-circuit "pre_" neutralizzato).
	$muted_fix      = wphc_mute_update_shortcircuit();
	$plugin_updates = get_plugin_updates();
	$health_tp      = get_site_transient( 'update_plugins' );
	wphc_restore_update_shortcircuit( $muted_fix );
	$health_plugins_updates = ( is_object( $health_tp ) && ! empty( $health_tp->response ) ) ? count( (array) $health_tp->response ) : 0;

	return rest_ensure_response(
		array(
			'context'                   => array(
				'is_admin'           => is_admin(),
				'rest_request'       => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'doing_cron'         => defined( 'DOING_CRON' ) && DOING_CRON,
				'user_id'            => get_current_user_id(),
				'can_update_plugins' => current_user_can( 'update_plugins' ),
				'is_multisite'       => is_multisite(),
				'home_url'           => home_url(),
				'site_url'           => site_url(),
			),
			'update_plugins_filtered'   => array(
				'exists'         => is_object( $filtered ),
				'last_checked'   => ( is_object( $filtered ) && ! empty( $filtered->last_checked ) ) ? gmdate( 'c', (int) $filtered->last_checked ) : null,
				'response_count' => count( $filtered_slugs ),
				'response_slugs' => $filtered_slugs,
			),
			'update_plugins_raw_stored' => array(
				'response_count' => count( $raw_slugs ),
				'response_slugs' => $raw_slugs,
			),
			'get_plugin_updates'        => array(
				'count' => count( $plugin_updates ),
				'slugs' => array_keys( $plugin_updates ),
			),
			// Conteggio che /health riporta ORA (dalla 1.16.0), con lo
			// short-circuit "pre_site_transient_update_*" neutralizzato: deve
			// coincidere con quello visto in admin.
			'health_plugins_updates'    => $health_plugins_updates,
			'filters'                   => array(
				'site_transient_update_plugins'         => wphc_debug_hook_callbacks( 'site_transient_update_plugins' ),
				'pre_set_site_transient_update_plugins' => wphc_debug_hook_callbacks( 'pre_set_site_transient_update_plugins' ),
				'pre_site_transient_update_plugins'     => wphc_debug_hook_callbacks( 'pre_site_transient_update_plugins' ),
			),
		)
	);
}

// -----------------------------------------------------------------------
// TAB SITE HEALTH: stato e configurazione da wp-admin
// -----------------------------------------------------------------------
//
// Aggiunge una tab dedicata in Strumenti -> Salute del sito (disponibile
// da WordPress 5.8 via i filtri/azioni site_health_navigation_tabs e
// site_health_tab_content), visibile solo a chi puo' manage_options: qui si
// puo' anche innescare il self-update e resettare l'enrollment, quindi il
// controllo di accesso e' piu' stretto della sola capacita' di visualizzare
// la Salute del sito.

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
 * Renderizza il contenuto della tab "WP Health Check": versioni installata e
 * disponibile, stato di enrollment, URL da usare per l'enroll, ultimo enroll
 * fallito, ultimo accesso, pulsante di self-update e pulsante di reset.
 *
 * @param string $tab Slug della tab richiesta.
 */
function wphc_render_site_health_tab( $tab ) {
	if ( 'wp-health-check' !== $tab || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_enrolled             = ! empty( get_option( 'wp_health_check_token' ) );
	$signed_site_url         = (string) get_option( 'wp_health_check_site_url', '' );
	$enrolled_at             = get_option( 'wp_health_check_enrolled_at' );
	$enrolled_ip             = get_option( 'wp_health_check_enrolled_ip' );
	$last_request_at         = get_option( 'wp_health_check_last_request_at' );
	$last_request_ip         = get_option( 'wp_health_check_last_request_ip' );
	$trust_proxy             = (bool) get_option( 'wp_health_check_trust_proxy', false );
	$last_enroll_error       = get_option( 'wp_health_check_last_enroll_error' );
	$updates_via_api_enabled = (bool) get_option( 'wp_health_check_updates_enabled', false );

	$candidates       = wphc_candidate_site_urls();
	$canonical_home   = wphc_normalize_site_url();
	$latest_version   = wphc_get_latest_version();
	$update_available = ( null !== $latest_version ) && version_compare( $latest_version, WP_HEALTH_CHECK_VERSION, '>' );

	// Conteggi plugin/temi. Siamo in contesto amministrativo: i transient
	// degli update sono quelli completi mantenuti dal cron/admin (premium
	// inclusi), quindi questi numeri rispecchiano la schermata Plugin/Temi.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$all_plugins     = get_plugins();
	$plugins_total   = count( $all_plugins );
	$plugins_active  = count( (array) get_option( 'active_plugins', array() ) );
	$upd_plugins_t   = get_site_transient( 'update_plugins' );
	$plugins_updates = ( is_object( $upd_plugins_t ) && ! empty( $upd_plugins_t->response ) ) ? count( $upd_plugins_t->response ) : 0;

	$all_themes        = wp_get_themes();
	$themes_total      = count( $all_themes );
	$active_theme_name = (string) wp_get_theme()->get( 'Name' );
	$upd_themes_t      = get_site_transient( 'update_themes' );
	$themes_updates    = ( is_object( $upd_themes_t ) && ! empty( $upd_themes_t->response ) ) ? count( $upd_themes_t->response ) : 0;
	?>
	<div class="health-check-body health-check-wp-health-check-tab hide-if-no-js">
		<h2><?php esc_html_e( 'WP Health Check — Fleet Agent', 'wp-health-check' ); ?></h2>

		<?php if ( isset( $_GET['wphc_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag di stato post-redirect, nessuna azione qui. ?>
			<div class="notice notice-success"><p>
				<?php
				$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf(
					/* translators: %s: versione a cui e' stato aggiornato il plugin. */
					esc_html__( 'Plugin aggiornato alla versione %s.', 'wp-health-check' ),
					'<code>' . esc_html( $to ) . '</code>'
				);
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_uptodate'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'Il plugin e\' gia\' aggiornato all\'ultima versione disponibile.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_update_failed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p>
				<?php
				$reason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf(
					/* translators: %s: motivo macchina del fallimento dell'aggiornamento. */
					esc_html__( 'Aggiornamento non riuscito (%s). Nessuna modifica al file del plugin.', 'wp-health-check' ),
					'<code>' . esc_html( $reason ) . '</code>'
				);
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Cache dell\'agent svuotate e aggiornamenti ricontrollati.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_updates_toggled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Preferenza sugli aggiornamenti via API salvata.', 'wp-health-check' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width: 800px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Versione plugin installata', 'wp-health-check' ); ?></td>
					<td><code><?php echo esc_html( WP_HEALTH_CHECK_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Ultima versione disponibile', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( null === $latest_version ) : ?>
							<?php esc_html_e( 'non determinabile (GitHub irraggiungibile)', 'wp-health-check' ); ?>
						<?php elseif ( $update_available ) : ?>
							<code><?php echo esc_html( $latest_version ); ?></code>
							<span class="dashicons dashicons-update" style="color:#d63638;"></span>
							<strong><?php esc_html_e( 'aggiornamento disponibile', 'wp-health-check' ); ?></strong>
						<?php else : ?>
							<code><?php echo esc_html( $latest_version ); ?></code> — <?php esc_html_e( 'aggiornato', 'wp-health-check' ); ?>
						<?php endif; ?>
					</td>
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
					<td><?php esc_html_e( 'URL firmato registrato', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( '' !== $signed_site_url ) : ?>
							<code><?php echo esc_html( $signed_site_url ); ?></code>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'URL esatto che il sistema centrale ha firmato in fase di enroll: e\' la chiave a cui e\' legato il token. Utile a diagnosticare mismatch su siti WPML o con varianti www/non-www.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'URL validi per l\'enroll', 'wp-health-check' ); ?></td>
					<td>
						<ul style="margin:0;">
							<?php foreach ( $candidates as $candidate ) : ?>
								<li>
									<code><?php echo esc_html( $candidate ); ?></code>
									<?php if ( $candidate === $canonical_home ) : ?>
										<em>(<?php esc_html_e( 'principale', 'wp-health-check' ); ?>)</em>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							<?php esc_html_e( 'Il sistema centrale deve firmare il site_url usando UNO di questi URL (confronto tollerante www/non-www). Quello marcato "principale" e\' l\'URL canonico del sito.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Ultimo enroll fallito', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( is_array( $last_enroll_error ) ) : ?>
							<strong><?php echo esc_html( isset( $last_enroll_error['reason'] ) ? $last_enroll_error['reason'] : '' ); ?></strong><br />
							<span class="description">
								<?php echo esc_html( isset( $last_enroll_error['code'] ) ? $last_enroll_error['code'] : '' ); ?>
								<?php if ( ! empty( $last_enroll_error['at'] ) ) : ?>
									— <?php echo esc_html( $last_enroll_error['at'] ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $last_enroll_error['ip'] ) ) : ?>
									— <?php echo esc_html( $last_enroll_error['ip'] ); ?>
								<?php endif; ?>
							</span>
							<?php if ( ! empty( $last_enroll_error['received'] ) ) : ?>
								<br /><?php esc_html_e( 'URL inviato:', 'wp-health-check' ); ?>
								<code><?php echo esc_html( $last_enroll_error['received'] ); ?></code>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Motivo dell\'ultimo tentativo di enroll fallito (azzerato automaticamente al primo enroll riuscito). Utile per capire perche\' un enroll viene rifiutato e con quale URL.', 'wp-health-check' ); ?>
						</p>
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

		<h3><?php esc_html_e( 'Riepilogo plugin e temi', 'wp-health-check' ); ?></h3>
		<table class="widefat striped" style="max-width: 800px;">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Totali', 'wp-health-check' ); ?></th>
					<th><?php esc_html_e( 'Attivi', 'wp-health-check' ); ?></th>
					<th><?php esc_html_e( 'Da aggiornare', 'wp-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Plugin', 'wp-health-check' ); ?></strong></td>
					<td><?php echo (int) $plugins_total; ?></td>
					<td><?php echo (int) $plugins_active; ?></td>
					<td>
						<?php echo (int) $plugins_updates; ?>
						<?php if ( $plugins_updates > 0 ) : ?>
							<span class="dashicons dashicons-update" style="color:#d63638;"></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Temi', 'wp-health-check' ); ?></strong></td>
					<td><?php echo (int) $themes_total; ?></td>
					<td>1</td>
					<td>
						<?php echo (int) $themes_updates; ?>
						<?php if ( $themes_updates > 0 ) : ?>
							<span class="dashicons dashicons-update" style="color:#d63638;"></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php
			/* translators: %s: nome del tema attivo. */
			printf( esc_html__( 'Tema attivo: %s. Conteggi degli aggiornamenti letti dai transient del cron (come la bacheca); usa il pulsante "Svuota cache e ricontrolla" se sembrano non aggiornati.', 'wp-health-check' ), '<strong>' . esc_html( $active_theme_name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</p>

		<h3><?php esc_html_e( 'Test degli endpoint', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Esegue una chiamata reale all\'endpoint (loopback lato server, con il bearer token e un cache-buster _cb casuale ad ogni chiamata) e mostra la risposta in una finestra. Il token non viene mai esposto nel browser.', 'wp-health-check' ); ?>
		</p>
		<div id="wphc-endpoint-tester" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wphc_test_endpoint' ) ); ?>">
			<button type="button" class="button wphc-test-btn" data-endpoint="health">GET /health</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="health_fresh">GET /health?fresh=1</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_plugins">GET /detail/plugins</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_plugins_fresh">GET /detail/plugins?fresh=1</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_theme">GET /detail/theme</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_theme_fresh">GET /detail/theme?fresh=1</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_server">GET /detail/server</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_server_fresh">GET /detail/server?fresh=1</button>
		</div>

		<div id="wphc-modal" class="wphc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wphc-modal-title">
			<div class="wphc-modal-box">
				<div class="wphc-modal-head">
					<strong id="wphc-modal-title"></strong>
					<button type="button" class="button-link wphc-modal-close" aria-label="<?php esc_attr_e( 'Chiudi', 'wp-health-check' ); ?>">&times;</button>
				</div>
				<p id="wphc-modal-meta" class="description" style="margin:.5em 0;word-break:break-all;"></p>
				<pre id="wphc-modal-body"></pre>
			</div>
		</div>

		<style>
			.wphc-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:5vh 16px;box-sizing:border-box;}
			.wphc-modal-box{background:#fff;max-width:840px;width:100%;max-height:88vh;overflow:auto;border-radius:4px;padding:14px 20px 20px;box-shadow:0 4px 24px rgba(0,0,0,.3);}
			.wphc-modal-head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #dcdcde;padding-bottom:8px;margin-bottom:6px;}
			.wphc-modal-close{font-size:22px;line-height:1;text-decoration:none;color:#646970;}
			#wphc-modal-body{background:#1d2327;color:#f0f0f1;padding:12px;border-radius:3px;overflow:auto;max-height:60vh;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;margin:0;}
			#wphc-endpoint-tester .button{margin:0 6px 6px 0;}
			.wphc-status-ok{color:#008a20;font-weight:600;}
			.wphc-status-bad{color:#d63638;font-weight:600;}
		</style>

		<script>
			( function () {
				var wrap = document.getElementById( 'wphc-endpoint-tester' );
				if ( ! wrap ) { return; }
				var nonce   = wrap.dataset.nonce;
				var modal   = document.getElementById( 'wphc-modal' );
				var titleEl = document.getElementById( 'wphc-modal-title' );
				var metaEl  = document.getElementById( 'wphc-modal-meta' );
				var bodyEl  = document.getElementById( 'wphc-modal-body' );

				function openModal( title ) {
					titleEl.textContent = title;
					metaEl.textContent  = 'Chiamata in corso…';
					bodyEl.textContent  = '';
					modal.style.display = 'flex';
				}
				function closeModal() { modal.style.display = 'none'; }
				function prettify( txt ) {
					try { return JSON.stringify( JSON.parse( txt ), null, 2 ); } catch ( e ) { return txt; }
				}
				function escapeHtml( s ) { var d = document.createElement( 'div' ); d.textContent = s; return d.innerHTML; }

				wrap.querySelectorAll( '.wphc-test-btn' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						openModal( btn.textContent.trim() );
						var fd = new FormData();
						fd.append( 'action', 'wphc_test_endpoint' );
						fd.append( 'endpoint', btn.getAttribute( 'data-endpoint' ) );
						fd.append( '_ajax_nonce', nonce );
						fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) {
								if ( res && res.success ) {
									var d = res.data;
									var cls = ( d.status >= 200 && d.status < 300 ) ? 'wphc-status-ok' : 'wphc-status-bad';
									metaEl.innerHTML = 'HTTP <span class="' + cls + '">' + d.status + '</span> · ' + d.took_ms + ' ms<br>' + escapeHtml( d.url );
									bodyEl.textContent = prettify( d.body );
								} else {
									metaEl.textContent = 'Errore';
									bodyEl.textContent = JSON.stringify( res );
								}
							} )
							.catch( function ( e ) { metaEl.textContent = 'Errore di rete'; bodyEl.textContent = String( e ); } );
					} );
				} );

				modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { closeModal(); } } );
				document.querySelector( '.wphc-modal-close' ).addEventListener( 'click', closeModal );
				document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { closeModal(); } } );
			}() );
		</script>

		<h3><?php esc_html_e( 'Aggiornamento del plugin', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Scarica e installa l\'ultima release firmata da GitHub (stesso flusso di POST /update: verifica integrita\' SHA-256, backup e ripristino automatico in caso di errore).', 'wp-health-check' ); ?>
		</p>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Confermi l\'aggiornamento del plugin all\'ultima release pubblicata su GitHub?', 'wp-health-check' ) ) ); ?> );"
		>
			<input type="hidden" name="action" value="wphc_self_update" />
			<?php wp_nonce_field( 'wphc_self_update' ); ?>
			<?php
			if ( $update_available ) {
				/* translators: %s: versione disponibile. */
				$update_label = sprintf( __( 'Aggiorna alla versione %s', 'wp-health-check' ), $latest_version );
				submit_button( $update_label, 'primary', 'submit', false );
			} else {
				submit_button( __( 'Verifica e aggiorna adesso', 'wp-health-check' ), 'secondary', 'submit', false );
			}
			?>
		</form>

		<h3><?php esc_html_e( 'Aggiornamenti plugin, temi e core via API', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Interruttore master: quando spento, le rotte POST /update/plugin, /update/theme e /update/core rifiutano ogni richiesta con 403 (GET /update/log resta sempre leggibile). Solo pacchetti ospitati su wordpress.org; rollback automatico via temp-backup nativo di WordPress per plugin e temi.', 'wp-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_toggle_updates" />
			<?php wp_nonce_field( 'wphc_toggle_updates' ); ?>
			<label>
				<input type="checkbox" name="wphc_updates_enabled" value="1" <?php checked( $updates_via_api_enabled ); ?> />
				<?php esc_html_e( 'Consenti aggiornamenti (plugin, temi, core) via API', 'wp-health-check' ); ?>
			</label>
			<?php submit_button( __( 'Salva', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Svuota cache e ricontrolla aggiornamenti', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Cancella le cache dell\'agent (transient wphc_*: health, dettaglio plugin/tema/server, ultima versione) e forza un ricontrollo COMPLETO degli aggiornamenti di core, plugin e temi. A differenza di ?fresh=1 (che gira via REST), qui siamo in contesto amministrativo: anche i plugin/temi premium vengono ricontrollati correttamente. Usalo se i conteggi o le versioni degli aggiornamenti sembrano sbagliati.', 'wp-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_clear_caches" />
			<?php wp_nonce_field( 'wphc_clear_caches' ); ?>
			<?php submit_button( __( 'Svuota cache e ricontrolla', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Reset enrollment', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Cancella token, URL firmato e tutti i metadati di enrollment. Il sito torna allo stato "non registrato" finche\' il sistema centrale non ripete l\'enroll. Equivalente a "wp health-check reset".', 'wp-health-check' ); ?>
		</p>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Confermi il reset dell\'enrollment? Il sito restera\' non registrato finche\' il sistema centrale non ripete l\'enroll.', 'wp-health-check' ) ) ); ?> );"
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
 * Handler AJAX (admin) del tester di endpoint della tab Site Health. Fa una
 * richiesta loopback all'endpoint REST richiesto, aggiungendo il bearer token
 * (lato server: non viene mai esposto al browser) e un cache-buster "_cb"
 * casuale, e restituisce status/corpo/latenza da mostrare nella modale. Solo
 * endpoint GET dati in whitelist: nessun effetto collaterale.
 */
function wphc_ajax_test_endpoint() {
	check_ajax_referer( 'wphc_test_endpoint' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
	}

	$key = isset( $_POST['endpoint'] ) ? sanitize_key( wp_unslash( $_POST['endpoint'] ) ) : '';
	$map = array(
		'health'               => array(
			'path'  => 'health',
			'fresh' => false,
		),
		'health_fresh'         => array(
			'path'  => 'health',
			'fresh' => true,
		),
		'detail_plugins'       => array(
			'path'  => 'detail/plugins',
			'fresh' => false,
		),
		'detail_plugins_fresh' => array(
			'path'  => 'detail/plugins',
			'fresh' => true,
		),
		'detail_theme'         => array(
			'path'  => 'detail/theme',
			'fresh' => false,
		),
		'detail_theme_fresh'   => array(
			'path'  => 'detail/theme',
			'fresh' => true,
		),
		'detail_server'        => array(
			'path'  => 'detail/server',
			'fresh' => false,
		),
		'detail_server_fresh'  => array(
			'path'  => 'detail/server',
			'fresh' => true,
		),
	);
	if ( ! isset( $map[ $key ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Endpoint non valido.', 'wp-health-check' ) ), 400 );
	}

	$endpoint = $map[ $key ];

	// Cache-buster casuale ad ogni chiamata + eventuale fresh=1.
	$query_args = array( '_cb' => wp_generate_password( 16, false, false ) );
	if ( $endpoint['fresh'] ) {
		$query_args['fresh'] = '1';
	}
	$url = add_query_arg( $query_args, rest_url( 'health-check/v1/' . $endpoint['path'] ) );

	// Il token resta lato server: viaggia solo nell'header della richiesta
	// loopback, mai verso il browser.
	$headers = array();
	$token   = (string) get_option( 'wp_health_check_token', '' );
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	$start    = microtime( true );
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 20,
			'headers' => $headers,
		)
	);
	$took_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		wp_send_json_success(
			array(
				'url'     => $url,
				'status'  => 0,
				'took_ms' => $took_ms,
				/* translators: %s: messaggio di errore della richiesta loopback. */
				'body'    => sprintf( __( 'Errore loopback: %s', 'wp-health-check' ), $response->get_error_message() ),
			)
		);
	}

	wp_send_json_success(
		array(
			'url'     => $url,
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'took_ms' => $took_ms,
			'body'    => wp_remote_retrieve_body( $response ),
		)
	);
}
add_action( 'wp_ajax_wphc_test_endpoint', 'wphc_ajax_test_endpoint' );

/**
 * Handler di admin-post.php per il pulsante di self-update nella tab Site
 * Health: esegue lo stesso flusso condiviso di POST /update
 * (wphc_perform_self_update()) e reindirizza alla tab con l'esito
 * (pattern POST-redirect-GET). Non richiede enrollment: e' un'azione
 * amministrativa, protetta da manage_options + nonce.
 */
function wphc_handle_self_update() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_self_update' );

	$outcome       = wphc_perform_self_update();
	$redirect_args = array( 'tab' => 'wp-health-check' );

	switch ( $outcome['result'] ) {
		case 'updated':
			$redirect_args['wphc_updated'] = '1';
			$redirect_args['to']           = $outcome['to'];
			break;

		case 'up_to_date':
			$redirect_args['wphc_uptodate'] = '1';
			break;

		case 'error':
			$redirect_args['wphc_update_failed'] = '1';
			$redirect_args['reason']             = $outcome['code'];
			break;

		default: // esiti non riusciti ma non erronei: not_writable / integrity_check_failed.
			$redirect_args['wphc_update_failed'] = '1';
			$redirect_args['reason']             = $outcome['result'];
			break;
	}

	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'site-health.php' ) ) );
	exit;
}
add_action( 'admin_post_wphc_self_update', 'wphc_handle_self_update' );

/**
 * Handler di admin-post.php per il pulsante "Svuota cache e ricontrolla":
 * cancella le cache dell'agent (transient wphc_*) e forza un ricontrollo
 * COMPLETO degli aggiornamenti di core/plugin/temi. Gira in contesto
 * amministrativo (admin-post), dove gli update-checker dei plugin/temi
 * premium sono attivi: a differenza di ?fresh=1 via REST, qui
 * wp_update_plugins()/wp_update_themes() ricostruiscono transient COMPLETI.
 */
function wphc_handle_clear_caches() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_clear_caches' );

	// 1. Cache dei payload dell'agent.
	delete_transient( 'wphc_health_cache' );
	delete_transient( 'wphc_detail_plugins_cache' );
	delete_transient( 'wphc_detail_theme_cache' );
	delete_transient( 'wphc_detail_server_cache' );
	delete_transient( 'wphc_latest_version_cache' );

	// 2. Ricontrollo completo degli aggiornamenti. wp_clean_*_cache( true )
	// svuota sia la lista sia il transient degli update, cosi' wp_update_*()
	// ricostruisce da zero (in contesto admin, quindi con i premium inclusi).
	require_once ABSPATH . 'wp-admin/includes/update.php';
	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );
	wp_version_check();
	wp_update_plugins();
	wp_update_themes();

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'          => 'wp-health-check',
				'wphc_cleared' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_clear_caches', 'wphc_handle_clear_caches' );

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

/**
 * Handler di admin-post.php per il checkbox "Consenti aggiornamenti
 * (plugin, temi, core) via API" nella tab Site Health: unico interruttore
 * master (§5 della specifica) da cui dipendono le rotte POST /update/{plugin,
 * theme,core}.
 */
function wphc_handle_toggle_updates() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_toggle_updates' );

	update_option( 'wp_health_check_updates_enabled', ! empty( $_POST['wphc_updates_enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'                  => 'wp-health-check',
				'wphc_updates_toggled' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_toggle_updates', 'wphc_handle_toggle_updates' );

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
