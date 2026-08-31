<?php
/**
 * Plugin Name: WP Health Check (Fleet Agent)
 * Description: Must-use plugin di monitoraggio per una flotta di siti WordPress, con enroll firmato, endpoint REST protetti da token e self-update firmato dalle release di un repository GitHub pubblico.
 * Version:     1.32.0
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
	define( 'WP_HEALTH_CHECK_VERSION', '1.32.0' );
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
	define( 'WP_HEALTH_CHECK_DB_VERSION', '4' );
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
// COSTANTI: aggiornamenti bulk (plugin/temi) via POST /update/bulk + WP-Cron
// -----------------------------------------------------------------------

/** Numero massimo di elementi accettati in un singolo job bulk. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_MAX_ITEMS' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_MAX_ITEMS', 100 );
}

/** Elementi processati al massimo per singolo tick del drain, entro il budget di tempo sotto. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK', 5 );
}

/** Budget di tempo (secondi) per tick, controllato PRIMA di ogni elemento, mai a meta'. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_TIME_BUDGET' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_TIME_BUDGET', 20 );
}

/** Intervallo minimo (secondi) tra un tick e il successivo. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_TICK_GAP' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_TICK_GAP', 30 );
}

/** Tentativi massimi per elemento: 1 iniziale + 3 retry, come richiesto. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS', 4 );
}

/** Backoff (secondi) applicato dopo ciascun tentativo fallito, per indice tentativo. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_BACKOFF' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_BACKOFF', array( 0, 60, 300, 900 ) );
}

/** Deferral massimi (contesa di lock con un update singolo) prima di arrendersi su un elemento. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_MAX_DEFERRALS' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_MAX_DEFERRALS', 20 );
}

/** TTL del mutex di drain (distinto dal lock di update per singolo elemento). */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_LOCK_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_LOCK_TTL', 300 );
}

/** Oltre questa eta' (secondi) un elemento 'running' e' considerato interrotto da un crash. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT', 600 );
}

/**
 * Come sopra, ma per l'elemento core: il rilevamento "interrotto" e' solo
 * un'inferenza dall'eta' del claim, quindi la soglia deve superare la durata
 * plausibile MASSIMA dell'operazione, non quella tipica. Un core update fa
 * download del pacchetto completo, sostituzione di migliaia di file e
 * wp_upgrade(): 600 secondi non basterebbero, e un reap troppo precoce
 * significherebbe un secondo Core_Upgrader in parallelo al primo (il lock di
 * update scade a WP_HEALTH_CHECK_UPDATE_LOCK_TTL, 300 secondi).
 */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT', 1800 );
}

/** Margine (secondi) oltre next_run_ts prima che un job attivo sia considerato stallato. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_STALL_GRACE' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_STALL_GRACE', 600 );
}

/** Scadenza dura del job (secondi dalla creazione): oltre, viene abortito comunque. */
if ( ! defined( 'WP_HEALTH_CHECK_BULK_JOB_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_JOB_TTL', 6 * HOUR_IN_SECONDS );
}

// -----------------------------------------------------------------------
// COSTANTI: webhook di notifica firmato a fine job bulk
// -----------------------------------------------------------------------

/**
 * URL di default del webhook di fleet, usato quando il sito non ne ha
 * impostato uno proprio in wp-admin (vedi wphc_webhook_url()) e non ha
 * spuntato "disattiva webhook". Identico per tutta la flotta, non segreto:
 * stesso principio delle altre costanti di flotta in cima al file.
 */
if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL', 'https://hub.mavida.com/api/v1/fleet/webhook/bulk-update' );
}

/** Timeout (secondi) della POST del webhook: nessun download, solo un breve report JSON. */
if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_TIMEOUT' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_TIMEOUT', 10 );
}

/** Tentativi massimi di consegna del webhook (1 sincrono + retry via cron). */
if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS', 3 );
}

/** Backoff (secondi) tra un tentativo di webhook e il successivo, per indice tentativo. */
if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_BACKOFF' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_BACKOFF', array( 0, 300, 1800 ) );
}

/** Numero massimo di item elencati nel corpo del webhook (oltre, items_truncated=true). */
if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS', 200 );
}

/**
 * TTL in secondi del token di autologin (POST /autologin/token). Volutamente
 * cortissimo: il token e' un bearer opaco che chiunque lo intercetti (log
 * server, cronologia browser) puo' usare per accedere una sola volta a
 * wp-admin come l'amministratore che lo ha generato. Un TTL di pochi secondi
 * rende la finestra di replay trascurabile; il consumo single-use (vedi
 * wphc_maybe_consume_autologin()) la chiude comunque a zero dopo il primo uso.
 */
if ( ! defined( 'WP_HEALTH_CHECK_AUTOLOGIN_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_AUTOLOGIN_TTL', 20 );
}

/**
 * Slot per una seconda chiave pubblica Ed25519 del centro (kid "k2"), vuoto
 * finche' non serve. Permette di far convivere due chiavi durante una
 * rotazione della chiave di firma del centro, senza un redeploy simultaneo
 * dell'agent su tutta la flotta (dalla 1.30.0). "k1" e' sempre
 * WP_HEALTH_CHECK_CENTRAL_PUBKEY qui sopra: vedi wphc_verify_central_signature().
 */
if ( ! defined( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2' ) ) {
	define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2', '' );
}

/**
 * Finestra di freschezza (secondi, +/-) per il timestamp delle richieste
 * firmate (protocollo v2: /rotate, /revoke, /enroll v2) e per l'header
 * X-WPHC-Timestamp delle chiamate operative firmate. Dalla 1.30.0.
 */
if ( ! defined( 'WP_HEALTH_CHECK_REPLAY_WINDOW' ) ) {
	define( 'WP_HEALTH_CHECK_REPLAY_WINDOW', 300 );
}

/**
 * TTL (secondi) del transient che ricorda un nonce gia' visto: il doppio di
 * WP_HEALTH_CHECK_REPLAY_WINDOW, cosi' un nonce non puo' essere riproposto
 * nemmeno subito dopo la scadenza del proprio record. Dalla 1.30.0.
 */
if ( ! defined( 'WP_HEALTH_CHECK_NONCE_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_NONCE_TTL', 600 );
}

/**
 * Finestra di grazia (secondi) dopo una rotazione del segreto, durante la
 * quale wphc_require_token() accetta ancora wp_health_check_token_prev.
 * Copre il caso in cui la conferma di /rotate all'hub vada persa pur avendo
 * il sito gia' scritto il segreto nuovo. Dalla 1.30.0.
 */
if ( ! defined( 'WP_HEALTH_CHECK_ROTATION_GRACE' ) ) {
	define( 'WP_HEALTH_CHECK_ROTATION_GRACE', 900 );
}

/**
 * Soglia e finestra (secondi) del rate limit sui tentativi di autenticazione
 * falliti (§3.12): oltre WP_HEALTH_CHECK_RATE_MAX_FAILS fallimenti dallo
 * stesso IP entro WP_HEALTH_CHECK_RATE_WINDOW secondi, le rotte dati
 * rispondono 429. Incrementato solo sui fallimenti, mai sulle richieste
 * autenticate con successo. Dalla 1.30.0.
 */
if ( ! defined( 'WP_HEALTH_CHECK_RATE_MAX_FAILS' ) ) {
	define( 'WP_HEALTH_CHECK_RATE_MAX_FAILS', 10 );
}
if ( ! defined( 'WP_HEALTH_CHECK_RATE_WINDOW' ) ) {
	define( 'WP_HEALTH_CHECK_RATE_WINDOW', 300 );
}

/**
 * URL base del servizio di screenshot usato per generare la thumbnail del
 * sito (vedi wphc_generate_site_thumbnail()). Costante per permettere a un
 * singolo sito di puntare a un renderer diverso da wp-config.php, senza
 * toccare questo file. Dalla 1.30.0 (prima era inline nella funzione).
 */
if ( ! defined( 'WP_HEALTH_CHECK_THUMB_SERVICE' ) ) {
	define( 'WP_HEALTH_CHECK_THUMB_SERVICE', 'https://image.thum.io/get/noanimate/png/width/400/' );
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
 * Vero se l'IP dato e' un indirizzo pubblico instradabile (non privato, non
 * riservato, non loopback). Usata sia per decidere se SERVER_ADDR e' gia'
 * sufficiente, sia per validare la risposta del servizio di risoluzione
 * esterno prima di persisterla.
 *
 * @param string $ip Indirizzo IP da valutare.
 * @return bool True se l'IP e' valido e pubblico.
 */
function wphc_ip_is_public( $ip ) {
	return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Determina l'IP pubblico di uscita del server, per i casi (dietro reverse
 * proxy / load balancer / container) in cui SERVER_ADDR e' un indirizzo di
 * rete interna (es. 192.168.x.x) e quindi inutile per identificare il sito
 * dall'esterno. Cascata a tre livelli, dal piu' economico al piu' costoso:
 *
 * 1. Se SERVER_ADDR e' gia' un IP pubblico, lo si riusa: costo zero, nessuna
 *    query ne' chiamata remota. E' il caso della maggior parte degli hosting
 *    condivisi, quindi copre il percorso caldo di /health.
 * 2. Altrimenti si legge il transient wphc_public_ip (7 giorni): la scadenza
 *    intercetta un eventuale cambio IP dopo una migrazione di hosting senza
 *    tenere un dato stantio per sempre.
 * 3. A transient scaduto, guardia anti-retry-loop (wphc_public_ip_retry_lock,
 *    1 giorno, stesso pattern di wphc_thumb_retry_lock) e chiamata a un
 *    servizio "echo IP" esterno (api.ipify.org). L'esito viene validato con
 *    wphc_ip_is_public() prima di essere persistito.
 *
 * @return string IP pubblico valido, oppure stringa vuota se non determinabile.
 */
function wphc_get_public_ip() {
	$server_ip = wphc_get_server_ip();
	if ( '' !== $server_ip && wphc_ip_is_public( $server_ip ) ) {
		return $server_ip;
	}

	$cached = get_transient( 'wphc_public_ip' );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	if ( false !== get_transient( 'wphc_public_ip_retry_lock' ) ) {
		return '';
	}

	// Impostato PRIMA del tentativo: se il servizio esterno fallisce, il lock
	// resta e blocca i ritentativi per il resto del TTL indipendentemente
	// dall'esito, evitando un retry-loop ad ogni /health.
	set_transient( 'wphc_public_ip_retry_lock', time(), DAY_IN_SECONDS );

	$response = wp_remote_get( 'https://api.ipify.org', array( 'timeout' => 5 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}

	$body = trim( wp_remote_retrieve_body( $response ) );
	if ( ! wphc_ip_is_public( $body ) ) {
		return '';
	}

	set_transient( 'wphc_public_ip', $body, 7 * DAY_IN_SECONDS );

	return $body;
}

/**
 * Deriva lo stato di auto-update del core WordPress, replicando (in sola
 * lettura) la stessa logica di precedenza di Core_Upgrader::should_update_to_version()
 * e WP_Automatic_Updater::is_disabled() — senza istanziare WP_Automatic_Updater,
 * che farebbe anche un giro sul filesystem (is_vcs_checkout()) non necessario
 * qui e non compatibile col contratto O(1) di /health.
 *
 * Ordine di precedenza (identico al core):
 * 1. wp_is_file_mod_allowed()/AUTOMATIC_UPDATER_DISABLED spengono tutto;
 * 2. le option auto_update_core_dev/_minor/_major danno i default;
 * 3. la costante WP_AUTO_UPDATE_CORE batte le option se definita;
 * 4. i filtri allow_{minor,major,dev}_auto_core_updates battono tutto il resto.
 *
 * Nota: su un sito con un checkout VCS (.git/.svn) il core rifiuterebbe
 * comunque l'auto-update indipendentemente da queste impostazioni; questa
 * funzione non lo rileva deliberatamente (richiederebbe stat() ripetute sul
 * filesystem ad ogni /health) e il valore restituito va quindi letto come
 * "cosa farebbe il core in assenza di un checkout VCS".
 *
 * @return array{enabled: bool, level: string, blocked_by: string|null} level
 *              e' uno tra 'none'|'minor'|'major'|'all'.
 */
function wphc_core_auto_update_state() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
		$cache = array(
			'enabled'    => false,
			'level'      => 'none',
			'blocked_by' => 'file_mods',
		);
		return $cache;
	}

	$disabled = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED;
	if ( (bool) apply_filters( 'automatic_updater_disabled', $disabled ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- si legge l'hook core, non se ne dichiara uno nuovo.
		$cache = array(
			'enabled'    => false,
			'level'      => 'none',
			'blocked_by' => 'automatic_updater_disabled',
		);
		return $cache;
	}

	$dev   = 'enabled' === get_site_option( 'auto_update_core_dev', 'enabled' );
	$minor = 'enabled' === get_site_option( 'auto_update_core_minor', 'enabled' );
	$major = 'enabled' === get_site_option( 'auto_update_core_major', 'unset' );

	if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		if ( false === WP_AUTO_UPDATE_CORE ) {
			$dev   = false;
			$minor = false;
			$major = false;
		} elseif ( 'minor' === WP_AUTO_UPDATE_CORE ) {
			$dev   = false;
			$minor = true;
			$major = false;
		} elseif ( true === WP_AUTO_UPDATE_CORE || in_array( WP_AUTO_UPDATE_CORE, array( 'beta', 'rc', 'development', 'branch-development' ), true ) ) {
			$dev   = true;
			$minor = true;
			$major = true;
		}
	}

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- si leggono gli hook core, non se ne dichiara di nuovi.
	$minor = (bool) apply_filters( 'allow_minor_auto_core_updates', $minor );
	$major = (bool) apply_filters( 'allow_major_auto_core_updates', $major );
	$dev   = (bool) apply_filters( 'allow_dev_auto_core_updates', $dev );
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

	if ( $major ) {
		$level = 'all';
	} elseif ( $minor ) {
		$level = 'minor';
	} else {
		$level = 'none';
	}

	$cache = array(
		'enabled'    => ( $minor || $major ),
		'level'      => $level,
		'blocked_by' => null,
	);

	return $cache;
}

/**
 * Wrapper sottile sull'helper core wp_is_auto_update_enabled_for_type(), che
 * a differenza di wphc_core_auto_update_state() sopra E' corretto per
 * 'plugin'/'theme' (per 'core' restituirebbe sempre false: il core non ha
 * un case per quel tipo). Include il file solo se non gia' incluso da chi
 * chiama (le rotte /health e /detail/* lo includono gia').
 *
 * @param string $type 'plugin' | 'theme'.
 * @return bool
 */
function wphc_auto_update_enabled_for( $type ) {
	require_once ABSPATH . 'wp-admin/includes/update.php';
	return (bool) wp_is_auto_update_enabled_for_type( $type );
}

/**
 * Stato di auto-update effettivo per un singolo tema, con la stessa
 * distinzione stored/forced di /detail/plugins (vedi wphc_route_detail_plugins()):
 * null = si applica la scelta salvata in auto_update_themes; true|false = un
 * filtro di terze parti impone lo stato indipendentemente dalla option.
 *
 * @param string $stylesheet         Stylesheet del tema.
 * @param array  $auto_update_themes Contenuto gia' letto di get_site_option('auto_update_themes').
 * @return array{auto_update: bool, auto_update_forced: bool|null}
 */
function wphc_theme_auto_update_state( $stylesheet, array $auto_update_themes ) {
	// Il filtro auto_update_theme si aspetta esattamente questa forma (stessa
	// convenzione usata dalla lista temi del core).
	$item   = (object) array( 'theme' => $stylesheet );
	$forced = wp_is_auto_update_forced_for_item( 'theme', null, $item );
	$stored = in_array( $stylesheet, $auto_update_themes, true );

	return array(
		// Lo stub PHPStan dichiara un return type "bool" per
		// wp_is_auto_update_forced_for_item(), ma l'implementazione reale
		// e' apply_filters( "auto_update_{$type}", null, $item ): se nessun
		// filtro e' agganciato, apply_filters() restituisce il null passato
		// invariato. Il confronto stretto e' quindi corretto nonostante lo
		// stub, non un refuso.
		'auto_update'        => ( null === $forced ) ? $stored : (bool) $forced, // @phpstan-ignore identical.alwaysFalse
		'auto_update_forced' => $forced,
	);
}

/**
 * Invalida le cache 1h di /detail/plugins e /detail/theme, e la micro-cache
 * 60s di /health, quando cambia lo stato di auto-update nativo di WP. Senza
 * questo, un admin che accende/spegne l'auto-update dalla lista plugin/temi
 * (azione AJAX nativa 'toggle-auto-updates', che scrive l'opzione senza
 * toccare i nostri transient) vedrebbe il flag restare stantio fino a un'ora.
 */
function wphc_flush_plugins_detail_cache() {
	delete_transient( 'wphc_detail_plugins_cache' );
	delete_transient( 'wphc_health_cache' );
}

/** Equivalente di wphc_flush_plugins_detail_cache() per i temi. */
function wphc_flush_theme_detail_cache() {
	delete_transient( 'wphc_detail_theme_cache' );
	delete_transient( 'wphc_health_cache' );
}

/** Invalida la sola micro-cache di /health (per le option auto-update del core). */
function wphc_flush_health_cache_only() {
	delete_transient( 'wphc_health_cache' );
}

// Entrambe le famiglie servono: su multisite update_site_option() e' quella
// che effettivamente scrive (i suoi hook update_option_*/add_option_* NON
// scattano li'); su single-site update_site_option() ricade su
// update_option() e sono invece gli hook update_option_*/add_option_* a
// scattare. Registrare solo una famiglia lascerebbe l'altro contesto stantio.
add_action( 'update_site_option_auto_update_plugins', 'wphc_flush_plugins_detail_cache' );
add_action( 'add_site_option_auto_update_plugins', 'wphc_flush_plugins_detail_cache' );
add_action( 'update_option_auto_update_plugins', 'wphc_flush_plugins_detail_cache' );
add_action( 'add_option_auto_update_plugins', 'wphc_flush_plugins_detail_cache' );
add_action( 'update_site_option_auto_update_themes', 'wphc_flush_theme_detail_cache' );
add_action( 'add_site_option_auto_update_themes', 'wphc_flush_theme_detail_cache' );
add_action( 'update_option_auto_update_themes', 'wphc_flush_theme_detail_cache' );
add_action( 'add_option_auto_update_themes', 'wphc_flush_theme_detail_cache' );
foreach ( array( 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' ) as $wphc_core_auto_option ) {
	add_action( "update_site_option_{$wphc_core_auto_option}", 'wphc_flush_health_cache_only' );
	add_action( "add_site_option_{$wphc_core_auto_option}", 'wphc_flush_health_cache_only' );
	add_action( "update_option_{$wphc_core_auto_option}", 'wphc_flush_health_cache_only' );
	add_action( "add_option_{$wphc_core_auto_option}", 'wphc_flush_health_cache_only' );
}
unset( $wphc_core_auto_option );

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
 * Durante wp_doing_cron() (es. il drain degli aggiornamenti bulk, che
 * chiama il preflight una volta per elemento) NON scrive: un job da N
 * elementi sovrascriverebbe altrimenti l'audit "ultimo accesso" con un IP
 * di loopback/vuoto per N volte, cancellando il segnale reale dell'ultima
 * chiamata autenticata da parte del centro. Stessa scelta gia' applicata a
 * /ping. I valori restituiti restano quelli correnti, invariati.
 *
 * @return array{at: string|null, ip: string|null} Timestamp/IP dell'accesso precedente.
 */
function wphc_record_access() {
	$previous_at = get_option( 'wp_health_check_last_request_at' );
	$previous_ip = get_option( 'wp_health_check_last_request_ip' );

	if ( wp_doing_cron() ) {
		return array(
			'at' => $previous_at ? $previous_at : null,
			'ip' => $previous_ip ? $previous_ip : null,
		);
	}

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
		// Protocollo v2 (dalla 1.30.0): stato di rotazione/revoca/firma.
		'wp_health_check_token_prev',
		'wp_health_check_token_rotated_at',
		'wp_health_check_protocol',
		'wp_health_check_revoked_at',
		'wp_health_check_secret_kid',
		'wp_health_check_autologin_pending',
		// wp_health_check_last_autologin sopravviveva erroneamente al reset
		// prima della 1.30.0 (§3.16 dell'analisi di sicurezza): un audit di
		// enrollment resettato non deve conservare l'ultimo autologin del
		// vecchio enrollment.
		'wp_health_check_last_autologin',
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
 * Rileva tre segnali booleani sul sito, calcolati dai plugin/temi
 * effettivamente attivi: presenza di un consent manager GDPR (has_gdpr),
 * presenza di un page builder (has_builder) e presenza di un plugin
 * e-commerce (has_ecommerce). L'API centrale li persiste e li espone; qui
 * li si calcola perche' il plugin ha accesso diretto a WordPress.
 *
 * PUNTO UNICO DEGLI SLUG: gli elenchi degli slug riconosciuti vivono solo
 * dentro questa funzione, cosi' aggiungerne altri (nuovi consent manager,
 * builder o piattaforme e-commerce) non richiede toccare altre parti del
 * plugin ne' l'API centrale. Gli slug plugin sono la cartella (primo
 * segmento di "cartella/file.php") e vanno verificati contro le versioni
 * realmente installate (Cookiebot in particolare ha avuto slug diversi nel
 * tempo).
 *
 * @return array{has_gdpr: bool, has_builder: bool, has_ecommerce: bool} Segnali rilevati.
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

	// Slug (cartella) dei plugin e-commerce riconosciuti.
	$ecommerce_plugin_slugs = array(
		'woocommerce',
		'easy-digital-downloads',
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

	$has_ecommerce = (bool) array_intersect( $plugin_slugs, $ecommerce_plugin_slugs );

	return array(
		'has_gdpr'      => $has_gdpr,
		'has_builder'   => $has_builder,
		'has_ecommerce' => $has_ecommerce,
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
	// X-WPHC-*: header di firma anti-replay del protocollo v2 (dalla 1.30.0),
	// vedi wphc_verify_request_signature().
	header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature' );
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
// PROTOCOLLO V2: FIRMA DEL CENTRO (kid), ANTI-REPLAY, RATE LIMIT, HTTPS
// -----------------------------------------------------------------------
//
// Blocco di funzioni condivise dalla 1.30.0 fra /enroll (v2), le nuove rotte
// /rotate e /revoke (firmate Ed25519 come /enroll) e le rotte dati esistenti
// (che aggiungono la firma HMAC anti-replay quando il sito e' su protocollo
// 2). Nessuna di queste funzioni e' invocata qui: sono solo definite prima
// del punto in cui servono, per lo stesso ordine logico gia' seguito dal
// resto del file (helper condivisi prima, callback dopo).

/**
 * Encoding base64url (RFC 4648 §5) senza padding, usato per la firma HMAC
 * delle richieste operative (X-WPHC-Signature): evita i caratteri '+', '/'
 * e '=' che altrimenti andrebbero percent-encoded in un header HTTP.
 *
 * @param string $binary Dati binari da codificare.
 * @return string Stringa base64url.
 */
function wphc_base64url_encode( $binary ) {
	return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding di una firma HMAC, non offuscamento.
}

/**
 * Decodifica base64url -> binario, inverso di wphc_base64url_encode().
 * Ripristina il padding '=' prima di richiamare base64_decode() in modalita'
 * strict, cosi' un input malformato ritorna false invece di un valore
 * troncato/silenziosamente sbagliato.
 *
 * @param string $data Stringa base64url da decodificare.
 * @return string|false Dati binari, o false se non decodificabile.
 */
function wphc_base64url_decode( $data ) {
	$data      = strtr( (string) $data, '-_', '+/' );
	$remainder = strlen( $data ) % 4;
	if ( 0 !== $remainder ) {
		$data .= str_repeat( '=', 4 - $remainder );
	}

	return base64_decode( $data, true );
}

/**
 * Verifica una firma Ed25519 del sistema centrale, con le stesse tre
 * guardie gia' corrette che erano inline in wphc_route_enroll() prima della
 * 1.30.0: base64_decode(..., true) strict, controllo esplicito delle
 * lunghezze attese (SODIUM_CRYPTO_SIGN_*), fail-closed su SodiumException.
 * Condivisa da /enroll, /rotate e /revoke.
 *
 * Il kid seleziona quale chiave pubblica di flotta usare (WP_HEALTH_CHECK_CENTRAL_PUBKEY
 * per "k1", WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2 per "k2"): permette di far
 * convivere due chiavi durante una rotazione della chiave di firma del
 * centro. Un payload senza kid (buste v1 di /enroll) assume "k1" di default,
 * quindi le buste esistenti continuano a verificare senza modifiche.
 *
 * @param string $message       Messaggio canonico firmato.
 * @param string $signature_b64 Firma Ed25519, base64 standard.
 * @param string $kid           Identificativo della chiave ("k1" o "k2").
 * @return bool True se la firma e' valida per quella chiave.
 */
function wphc_verify_central_signature( $message, $signature_b64, $kid = 'k1' ) {
	$keys = array(
		'k1' => WP_HEALTH_CHECK_CENTRAL_PUBKEY,
		'k2' => WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2,
	);

	if ( ! isset( $keys[ $kid ] ) || '' === $keys[ $kid ] ) {
		return false;
	}

	$pubkey_raw    = base64_decode( $keys[ $kid ], true );
	$signature_raw = base64_decode( (string) $signature_b64, true );

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
 * Costruisce il messaggio canonico firmato per le buste centro->sito diverse
 * da /enroll v1 (che mantiene il proprio formato storico, vedi
 * wphc_build_enroll_signing_payload()). Il primo componente e' un prefisso
 * di dominio letterale ("rotate", "revoke", "enroll") che impedisce di
 * riusare una busta firmata valida per un'operazione contro un'operazione
 * diversa: senza di esso i payload di /rotate e /revoke sarebbero
 * strutturalmente confondibili (entrambi iniziano con site_url).
 *
 * @param string   $kind  Prefisso di dominio ("rotate", "revoke", "enroll").
 * @param string[] $parts Componenti del payload, nell'ordine da firmare.
 * @return string Messaggio canonico.
 */
function wphc_build_signed_payload( $kind, array $parts ) {
	return $kind . "\n" . implode( "\n", $parts );
}

/**
 * True se il nonce indicato risulta gia' registrato (quindi la richiesta
 * e' un replay). Sola lettura: la registrazione (scrittura) avviene solo
 * DOPO che la firma e' stata verificata, vedi wphc_remember_nonce().
 *
 * @param string $nonce Nonce fornito dal chiamante.
 * @return bool True se gia' visto.
 */
function wphc_nonce_seen( $nonce ) {
	return false !== get_transient( 'wphc_nonce_' . hash( 'sha256', (string) $nonce ) );
}

/**
 * Registra un nonce come "usato" per WP_HEALTH_CHECK_NONCE_TTL secondi.
 * Chiamata SOLO dopo che la firma della richiesta e' gia' stata verificata:
 * un chiamante non autenticato che prova nonce a caso non puo' quindi
 * gonfiare wp_options sui siti senza object cache persistente. Chiave
 * indicizzata sull'hash SHA-256 del nonce, mai il valore in chiaro — stesso
 * pattern gia' collaudato dal token di autologin.
 *
 * @param string $nonce Nonce da registrare.
 */
function wphc_remember_nonce( $nonce ) {
	set_transient( 'wphc_nonce_' . hash( 'sha256', (string) $nonce ), time(), WP_HEALTH_CHECK_NONCE_TTL );
}

/**
 * Verifica la firma anti-replay di una richiesta operativa (protocollo v2):
 * header X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature. La stringa
 * canonica firma il METODO e la ROTTA REST (mai l'URL completo, per restare
 * compatibile con reverse proxy e varianti www/non-www, come gia' fa
 * wphc_candidate_site_urls() per l'enroll), l'hash del corpo, il timestamp e
 * il nonce. La query string NON entra nella firma (?fresh=1, ?check=1 non
 * sono sicurezza). Ogni passo che fallisce ritorna lo stesso WP_Error
 * generico, per non rivelare al chiamante quale controllo sia fallito.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @param string          $secret  Segreto (corrente o precedente) con cui verificare l'HMAC.
 * @return true|WP_Error True se la firma e' valida, altrimenti WP_Error 401.
 */
function wphc_verify_request_signature( WP_REST_Request $request, $secret ) {
	$unauthorized = new WP_Error( 'wphc_unauthorized', __( 'Non autorizzato.', 'wp-health-check' ), array( 'status' => 401 ) );

	$timestamp_header = $request->get_header( 'x-wphc-timestamp' );
	$nonce            = $request->get_header( 'x-wphc-nonce' );
	$signature        = $request->get_header( 'x-wphc-signature' );

	if ( empty( $timestamp_header ) || empty( $nonce ) || empty( $signature ) ) {
		return $unauthorized;
	}

	if ( abs( time() - (int) $timestamp_header ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
		return $unauthorized;
	}

	if ( wphc_nonce_seen( $nonce ) ) {
		return $unauthorized;
	}

	$canonical = $request->get_method() . "\n"
		. $request->get_route() . "\n"
		. hash( 'sha256', (string) $request->get_body() ) . "\n"
		. $timestamp_header . "\n"
		. $nonce;

	$expected = wphc_base64url_encode( hash_hmac( 'sha256', $canonical, (string) $secret, true ) );

	if ( ! hash_equals( $expected, (string) $signature ) ) {
		return $unauthorized;
	}

	// Registrata SOLO ora: la firma e' gia' valida, quindi il chiamante e'
	// autenticato (vedi il commento di wphc_remember_nonce()).
	wphc_remember_nonce( $nonce );

	return true;
}

/**
 * Rate limit sui tentativi di autenticazione falliti (§3.12): transient
 * indicizzato sull'hash dell'IP del chiamante, incrementato SOLO dai
 * fallimenti (wphc_throttle_register_failure()), mai dalle richieste
 * autenticate con successo — per non gonfiare wp_options con traffico
 * legittimo sui siti privi di object cache persistente.
 *
 * @return true|WP_Error True se sotto soglia, altrimenti WP_Error 429.
 */
function wphc_throttle_check() {
	$fails = (int) get_transient( 'wphc_fail_' . hash( 'sha1', wphc_get_client_ip() ) );

	if ( $fails >= WP_HEALTH_CHECK_RATE_MAX_FAILS ) {
		header( 'Retry-After: ' . WP_HEALTH_CHECK_RATE_WINDOW );

		return new WP_Error(
			'wphc_rate_limited',
			__( 'Troppi tentativi falliti: riprova piu\' tardi.', 'wp-health-check' ),
			array( 'status' => 429 )
		);
	}

	return true;
}

/**
 * Incrementa il contatore dei tentativi falliti per l'IP del chiamante
 * corrente. Va invocata da ogni ramo di autenticazione fallita delle rotte
 * protette da wphc_throttle_check() (dati, /rotate, /revoke).
 */
function wphc_throttle_register_failure() {
	$key   = 'wphc_fail_' . hash( 'sha1', wphc_get_client_ip() );
	$fails = (int) get_transient( $key );
	set_transient( $key, $fails + 1, WP_HEALTH_CHECK_RATE_WINDOW );
}

/**
 * Impone HTTPS sulle rotte che trasportano credenziali (/enroll, /rotate,
 * /revoke, /autologin/token). Dietro un reverse proxy is_ssl() puo' risultare
 * falso pur essendo il traffico reale in HTTPS: l'opt-out riusa lo stesso
 * meccanismo gia' previsto per wphc_get_client_ip() (wp_health_check_trust_proxy),
 * invece di introdurne uno nuovo.
 *
 * @return true|WP_Error True se la richiesta e' su HTTPS (o il proxy e' fidato).
 */
function wphc_require_https() {
	if ( is_ssl() || (bool) get_option( 'wp_health_check_trust_proxy', false ) ) {
		return true;
	}

	return new WP_Error(
		'wphc_https_required',
		__( 'HTTPS obbligatorio per questa rotta.', 'wp-health-check' ),
		array( 'status' => 403 )
	);
}

// -----------------------------------------------------------------------
// AUTENTICAZIONE DELLE ROTTE DATI
// -----------------------------------------------------------------------

/**
 * Permission_callback condiviso da /health, /detail/*, /update* e /thumbnail.
 * Legge il token salvato in wp_health_check_token (assegnato dall'enroll o
 * dall'ultima rotazione) e lo confronta in tempo costante con il bearer
 * token fornito. Header mancante e token errato restituiscono lo STESSO
 * errore (stesso codice, stesso messaggio) per non rivelare a un chiamante
 * non autenticato quale dei due casi si sia verificato.
 *
 * Dalla 1.30.0 (protocollo v2):
 * - un sito revocato (wp_health_check_revoked_at valorizzata) rifiuta ogni
 *   chiamata con 403, indipendentemente dal token fornito;
 * - un rate limit sui fallimenti (wphc_throttle_check()) risponde 429 prima
 *   ancora di leggere l'header Authorization;
 * - se il token corrente non combacia, si ritenta con wp_health_check_token_prev
 *   ma SOLO dentro la finestra di grazia WP_HEALTH_CHECK_ROTATION_GRACE dalla
 *   rotazione: e' cio' che rende una rotazione senza downtime per la flotta;
 * - se il sito e' su wp_health_check_protocol >= 2, la richiesta deve anche
 *   portare una firma anti-replay valida (X-WPHC-*, vedi
 *   wphc_verify_request_signature()); se il protocollo e' ancora 1 ma la
 *   firma e' comunque presente, viene verificata lo stesso (dual-stack: un
 *   centro aggiornato puo' iniziare a firmare prima che il sito completi la
 *   rotazione a protocollo 2).
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

	if ( ! empty( get_option( 'wp_health_check_revoked_at' ) ) ) {
		return new WP_Error(
			'wphc_revoked',
			__( 'Segreto revocato dal sistema centrale.', 'wp-health-check' ),
			array( 'status' => 403 )
		);
	}

	$throttled = wphc_throttle_check();
	if ( is_wp_error( $throttled ) ) {
		return $throttled;
	}

	$unauthorized = new WP_Error(
		'wphc_unauthorized',
		__( 'Non autorizzato.', 'wp-health-check' ),
		array( 'status' => 401 )
	);

	$auth_header = $request->get_header( 'authorization' );
	if ( empty( $auth_header ) || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
		wphc_throttle_register_failure();
		return $unauthorized;
	}

	$provided_token = trim( substr( $auth_header, 7 ) );

	// hash_equals: confronto in tempo costante, indispensabile per un
	// segreto (previene timing attack che dedurrebbero il token byte per
	// byte misurando quanto a lungo dura il confronto).
	$prev_token = get_option( 'wp_health_check_token_prev' );
	$rotated_at = (int) get_option( 'wp_health_check_token_rotated_at' );
	$using_prev = false;

	if ( hash_equals( (string) $stored_token, $provided_token ) ) {
		$using_prev = false;
	} elseif (
		! empty( $prev_token )
		&& $rotated_at > 0
		&& ( time() - $rotated_at ) <= WP_HEALTH_CHECK_ROTATION_GRACE
		&& hash_equals( (string) $prev_token, $provided_token )
	) {
		$using_prev = true;
	} else {
		wphc_throttle_register_failure();
		return $unauthorized;
	}

	$protocol      = (int) get_option( 'wp_health_check_protocol', 1 );
	$signature_hdr = $request->get_header( 'x-wphc-signature' );

	if ( $protocol >= 2 || ! empty( $signature_hdr ) ) {
		$secret_for_signature = $using_prev ? $prev_token : $stored_token;
		$signature_check      = wphc_verify_request_signature( $request, (string) $secret_for_signature );
		if ( is_wp_error( $signature_check ) ) {
			wphc_throttle_register_failure();
			return $unauthorized;
		}
	}

	if ( $using_prev ) {
		// Riga di log dedicata (§5.B): il segreto precedente e' ancora
		// dentro la finestra di grazia, ma va tracciato che e' stato usato.
		wphc_log_update_row( wphc_generate_correlation_id(), 'token', 'rotation', __( 'Segreto precedente', 'wp-health-check' ), null, null, 'completed', 'grace_window_hit' );
	} elseif ( ! empty( $prev_token ) ) {
		// Primo uso riuscito del segreto NUOVO dopo una rotazione: il
		// precedente decade subito, non solo al tempo (la finestra di
		// grazia si chiude all'evento).
		delete_option( 'wp_health_check_token_prev' );
		delete_option( 'wp_health_check_token_rotated_at' );
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

	// Rotazione del segreto (dalla 1.30.0, §5.B): come /enroll, l'autenticazione
	// e' la firma Ed25519 del centro verificata dentro il callback, non un
	// permission_callback basato su token — il sito non possiede ancora il
	// segreto nuovo nel momento in cui la richiesta arriva.
	register_rest_route(
		'health-check/v1',
		'/rotate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_rotate',
			'permission_callback' => '__return_true',
		)
	);

	// Revoca del segreto corrente (dalla 1.30.0, §5.B): stessa autenticazione
	// firmata di /rotate. A differenza di /rotate non consegna nulla di
	// nuovo: azzera lo stato di autenticazione del sito.
	register_rest_route(
		'health-check/v1',
		'/revoke',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_revoke',
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

	// Heartbeat leggero per il polling ad alta frequenza (uptime + latenza):
	// vedi wphc_route_ping() per il razionale del perche' non riusa /health.
	register_rest_route(
		'health-check/v1',
		'/ping',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_ping',
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
		'/detail/users',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_detail_users',
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

	// Riconciliazione a posteriori dello stato attivo dei plugin (vedi
	// wphc_perform_reactivate()): rispetta kill-switch e lock come le altre
	// rotte di scrittura, salvo il dry-run (?check=1) che resta sola lettura.
	register_rest_route(
		'health-check/v1',
		'/update/reactivate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_reactivate',
			'permission_callback' => 'wphc_require_token',
		)
	);

	// Aggiornamenti bulk (plugin/temi) asincroni: POST accoda un job
	// autenticato, WP-Cron smaltisce la coda gia' autorizzata (mai un update
	// non richiesto). Vedi wphc_route_update_bulk_enqueue() per il flusso
	// completo. GET legge lo stato del job da un singolo option, piu'
	// economico di una query sulla tabella di log.
	register_rest_route(
		'health-check/v1',
		'/update/bulk',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_update_bulk_enqueue',
			'permission_callback' => 'wphc_require_token',
		)
	);
	register_rest_route(
		'health-check/v1',
		'/update/bulk',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_update_bulk_status',
			'permission_callback' => 'wphc_require_token',
		)
	);
	register_rest_route(
		'health-check/v1',
		'/update/bulk/cancel',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_update_bulk_cancel',
			'permission_callback' => 'wphc_require_token',
		)
	);

	// Stato/rigenerazione della thumbnail del sito (dalla 1.30.0): stessa
	// autenticazione a bearer delle altre rotte dati. GET restituisce solo lo
	// stato corrente (economico, nessuna chiamata remota); POST forza una
	// rigenerazione ignorando il cooldown, vedi wphc_maybe_generate_thumbnail().
	register_rest_route(
		'health-check/v1',
		'/thumbnail',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wphc_route_thumbnail_status',
			'permission_callback' => 'wphc_require_token',
		)
	);
	register_rest_route(
		'health-check/v1',
		'/thumbnail',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_thumbnail_regenerate',
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

	// Autologin: genera un token one-time per aprire wp-admin gia' autenticati
	// (uso tipico: pulsante nella dashboard/app centrale). Gated su
	// manage_options, NON sul bearer token: l'identita' che finira' loggata in
	// wp-admin e' esattamente quella risolta da WordPress per la richiesta
	// (cookie o, tipicamente qui, una Application Password), non un
	// user_id arbitrario passato dal chiamante. Vedi
	// wphc_route_autologin_token() e wphc_maybe_consume_autologin().
	register_rest_route(
		'health-check/v1',
		'/autologin/token',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wphc_route_autologin_token',
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
 * come valore opaco.
 *
 * Due formati, distinti dal campo "protocol" del body (default 1 se assente):
 * - protocol 1 (storico): una ripetizione dell'enroll con lo stesso URL
 *   produce sempre lo stesso token (derivazione deterministica lato centro),
 *   quindi un replay e' innocuo, riscrive lo stesso valore. Nessun controllo
 *   anti-replay per questo formato.
 * - protocol 2 (dalla 1.30.0, §3.7/§5.B): il token non e' piu' derivato ma
 *   assegnato dal centro e ruotabile, quindi un replay di una busta vecchia
 *   potrebbe riportare il sito a un segreto gia' ruotato. Aggiunge percio'
 *   "nonce" al payload e una finestra di freschezza su "issued_at"
 *   (+/- WP_HEALTH_CHECK_REPLAY_WINDOW secondi): qui la busta e' quindi
 *   single-use quanto le rotte /rotate e /revoke.
 *
 * @param WP_REST_Request $request Richiesta REST con il payload di enroll.
 * @return WP_REST_Response|WP_Error Esito dell'enroll.
 */
function wphc_route_enroll( WP_REST_Request $request ) {
	wphc_maybe_send_cors_headers();

	$throttled = wphc_throttle_check();
	if ( is_wp_error( $throttled ) ) {
		return $throttled;
	}

	$https_check = wphc_require_https();
	if ( is_wp_error( $https_check ) ) {
		return $https_check;
	}

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		wphc_record_enroll_error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), '' );

		return new WP_Error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	// URL inviato, se presente: usato solo per diagnostica nel log errori.
	$reported_site_url = isset( $body['site_url'] ) ? (string) $body['site_url'] : '';

	// 1. Presenza dei campi obbligatori (dashboard_origin e' l'unico campo
	// sempre opzionale/nullable; "nonce" e' obbligatorio solo in protocol 2).
	$protocol        = isset( $body['protocol'] ) ? (int) $body['protocol'] : 1;
	$required_fields = array( 'site_url', 'token', 'issued_at', 'signature' );
	if ( $protocol >= 2 ) {
		$required_fields[] = 'nonce';
	}

	foreach ( $required_fields as $field ) {
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
	$kid              = isset( $body['kid'] ) ? sanitize_key( (string) $body['kid'] ) : 'k1';
	$nonce            = isset( $body['nonce'] ) ? (string) $body['nonce'] : '';

	// 2. Freschezza e anti-replay, solo per protocol 2 (vedi il docblock).
	if ( $protocol >= 2 ) {
		if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
			wphc_throttle_register_failure();
			wphc_record_enroll_error( 'wphc_enroll_stale', __( 'Busta di enroll scaduta (issued_at fuori dalla finestra di freschezza).', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_stale', __( 'Richiesta di enroll scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( wphc_nonce_seen( $nonce ) ) {
			wphc_throttle_register_failure();
			wphc_record_enroll_error( 'wphc_enroll_replay', __( 'Nonce di enroll gia\' utilizzato.', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_replay', __( 'Richiesta di enroll gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
	}

	// 3. Verifica della firma con la chiave pubblica incorporata nel file
	// (kid "k1" o "k2", vedi wphc_verify_central_signature()). Il messaggio
	// canonico dipende dal protocollo: protocol 1 e' il formato storico
	// (wphc_build_enroll_signing_payload()); protocol 2 aggiunge il prefisso
	// di dominio "enroll", il nonce e il numero di protocollo alla firma.
	$message = ( $protocol >= 2 )
		? wphc_build_signed_payload(
			'enroll',
			array(
				$site_url,
				$token,
				null === $dashboard_origin ? '' : (string) $dashboard_origin,
				$nonce,
				(string) $issued_at,
				(string) $protocol,
			)
		)
		: wphc_build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at );

	if ( ! wphc_verify_central_signature( $message, $signature, $kid ) ) {
		// Messaggio unico e generico verso il chiamante: non distingue
		// "firma non valida" da "chiave malformata" o altro, per non offrire
		// informazioni utili a chi tenta richieste non autorizzate. La
		// diagnostica interna (tab admin) registra invece un motivo esplicito.
		wphc_throttle_register_failure();
		wphc_record_enroll_error( 'wphc_enroll_unauthorized', __( 'Firma non valida (busta non prodotta dal sistema centrale).', 'wp-health-check' ), $reported_site_url );

		return new WP_Error( 'wphc_enroll_unauthorized', __( 'Richiesta di enroll non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	// 4. Il payload firmato deve riguardare uno degli URL canonici di questo
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

	// 5. Persistenza in wp_options (mai in wp-config.php). Si memorizza
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
	update_option( 'wp_health_check_secret_kid', $kid, false );
	if ( $protocol >= 2 ) {
		// Un enroll v2 riuscito porta gia' il sito a protocollo 2: le rotte
		// dati inizieranno a pretendere la firma anti-replay. Il nonce va
		// registrato solo ORA che la firma e' verificata (stesso principio
		// di wphc_verify_request_signature()).
		update_option( 'wp_health_check_protocol', 2, false );
		wphc_remember_nonce( $nonce );
	}
	// Enroll riuscito: azzera l'eventuale ultimo errore diagnostico.
	delete_option( 'wp_health_check_last_enroll_error' );

	// 6. Conferma: si restituisce il site_url firmato realmente registrato.
	return rest_ensure_response(
		array(
			'enrolled'      => true,
			'site'          => $received_site_url,
			'agent_version' => WP_HEALTH_CHECK_VERSION,
		)
	);
}

// -----------------------------------------------------------------------
// CALLBACK: POST /rotate
// -----------------------------------------------------------------------

/**
 * Rotazione firmata del segreto (dalla 1.30.0, §5.B): il centro consegna un
 * segreto nuovo; il sito sposta l'attuale in wp_health_check_token_prev (per
 * la finestra di grazia, vedi wphc_require_token()) e adotta il nuovo come
 * wp_health_check_token. Autenticazione identica a /enroll: firma Ed25519
 * del centro verificata qui dentro, MAI un permission_callback basato sul
 * token corrente (il sito non deve poter rifiutare la propria rotazione solo
 * perche' e' proprio il token in uso quello che sta per essere sostituito).
 *
 * Messaggio canonico: "rotate" \n site_url \n new_token \n nonce \n
 * issued_at \n protocol. Richiede un enrollment gia' completato: non e' un
 * bootstrap, e' un'operazione su un sito gia' noto al centro.
 *
 * @param WP_REST_Request $request Richiesta REST con il payload di rotazione.
 * @return WP_REST_Response|WP_Error Esito della rotazione.
 */
function wphc_route_rotate( WP_REST_Request $request ) {
	wphc_maybe_send_cors_headers();

	$throttled = wphc_throttle_check();
	if ( is_wp_error( $throttled ) ) {
		return $throttled;
	}

	$https_check = wphc_require_https();
	if ( is_wp_error( $https_check ) ) {
		return $https_check;
	}

	$current_token = get_option( 'wp_health_check_token' );
	if ( empty( $current_token ) ) {
		return new WP_Error(
			'wphc_not_enrolled',
			__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
			array( 'status' => 503 )
		);
	}

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'wphc_rotate_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	foreach ( array( 'site_url', 'new_token', 'nonce', 'issued_at', 'signature' ) as $field ) {
		if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
			return new WP_Error(
				'wphc_rotate_missing_field',
				/* translators: %s: nome del campo mancante. */
				sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field ),
				array( 'status' => 400 )
			);
		}
	}

	$site_url  = (string) $body['site_url'];
	$new_token = (string) $body['new_token'];
	$nonce     = (string) $body['nonce'];
	$issued_at = (int) $body['issued_at'];
	$signature = (string) $body['signature'];
	$kid       = isset( $body['kid'] ) ? sanitize_key( (string) $body['kid'] ) : 'k1';
	$protocol  = isset( $body['protocol'] ) ? (int) $body['protocol'] : 2;

	if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
		wphc_throttle_register_failure();
		return new WP_Error( 'wphc_rotate_stale', __( 'Richiesta di rotazione scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
	}
	if ( wphc_nonce_seen( $nonce ) ) {
		wphc_throttle_register_failure();
		return new WP_Error( 'wphc_rotate_replay', __( 'Richiesta di rotazione gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	$message = wphc_build_signed_payload( 'rotate', array( $site_url, $new_token, $nonce, (string) $issued_at, (string) $protocol ) );
	if ( ! wphc_verify_central_signature( $message, $signature, $kid ) ) {
		wphc_throttle_register_failure();
		return new WP_Error( 'wphc_rotate_unauthorized', __( 'Richiesta di rotazione non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	// La busta e' valida per QUESTO sito solo se riguarda uno dei suoi URL
	// canonici — stesso confronto tollerante gia' usato da /enroll, per non
	// rifiutare a torto su siti WPML/reverse proxy/varianti www.
	if ( ! in_array( wphc_normalize_url( $site_url ), wphc_candidate_site_urls(), true ) ) {
		return new WP_Error(
			'wphc_rotate_url_mismatch',
			__( 'URL della busta di rotazione non corrispondente a questo sito.', 'wp-health-check' ),
			array( 'status' => 403 )
		);
	}

	wphc_remember_nonce( $nonce );

	// Sposta l'attuale in _prev (apre la finestra di grazia) e adotta il nuovo.
	update_option( 'wp_health_check_token_prev', $current_token, false );
	update_option( 'wp_health_check_token_rotated_at', time(), false );
	update_option( 'wp_health_check_token', $new_token, false );
	update_option( 'wp_health_check_protocol', max( 2, $protocol ), false );
	update_option( 'wp_health_check_secret_kid', $kid, false );

	wphc_log_update_row( wphc_generate_correlation_id(), 'token', 'rotation', __( 'Segreto ruotato dal sistema centrale', 'wp-health-check' ), null, null, 'completed' );

	return rest_ensure_response(
		array(
			'rotated'       => true,
			'site'          => $site_url,
			'protocol'      => (int) get_option( 'wp_health_check_protocol', 2 ),
			'grace_seconds' => WP_HEALTH_CHECK_ROTATION_GRACE,
		)
	);
}

// -----------------------------------------------------------------------
// CALLBACK: POST /revoke
// -----------------------------------------------------------------------

/**
 * Revoca firmata del segreto corrente (dalla 1.30.0, §5.B): cancella sia il
 * segreto corrente che l'eventuale precedente e registra il timestamp di
 * revoca, cosi' wphc_require_token() rifiuta ogni chiamata successiva con
 * 403 indipendentemente dal token fornito. Invalida anche i token di
 * autologin gia' emessi e non ancora consumati (tramite l'elenco tenuto da
 * wphc_track_pending_autologin()): un segreto compromesso non deve poter
 * aprire wp-admin tramite un token pendente.
 *
 * Autenticazione identica a /enroll e /rotate: firma Ed25519 del centro.
 * Messaggio canonico: "revoke" \n site_url \n nonce \n issued_at.
 *
 * @param WP_REST_Request $request Richiesta REST con il payload di revoca.
 * @return WP_REST_Response|WP_Error Esito della revoca.
 */
function wphc_route_revoke( WP_REST_Request $request ) {
	wphc_maybe_send_cors_headers();

	$throttled = wphc_throttle_check();
	if ( is_wp_error( $throttled ) ) {
		return $throttled;
	}

	$https_check = wphc_require_https();
	if ( is_wp_error( $https_check ) ) {
		return $https_check;
	}

	if ( empty( get_option( 'wp_health_check_token' ) ) ) {
		return new WP_Error(
			'wphc_not_enrolled',
			__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
			array( 'status' => 503 )
		);
	}

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'wphc_revoke_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	foreach ( array( 'site_url', 'nonce', 'issued_at', 'signature' ) as $field ) {
		if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
			return new WP_Error(
				'wphc_revoke_missing_field',
				/* translators: %s: nome del campo mancante. */
				sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field ),
				array( 'status' => 400 )
			);
		}
	}

	$site_url  = (string) $body['site_url'];
	$nonce     = (string) $body['nonce'];
	$issued_at = (int) $body['issued_at'];
	$signature = (string) $body['signature'];
	$kid       = isset( $body['kid'] ) ? sanitize_key( (string) $body['kid'] ) : 'k1';

	if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
		wphc_throttle_register_failure();
		return new WP_Error( 'wphc_revoke_stale', __( 'Richiesta di revoca scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
	}
	if ( wphc_nonce_seen( $nonce ) ) {
		wphc_throttle_register_failure();
		return new WP_Error( 'wphc_revoke_replay', __( 'Richiesta di revoca gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	$message = wphc_build_signed_payload( 'revoke', array( $site_url, $nonce, (string) $issued_at ) );
	if ( ! wphc_verify_central_signature( $message, $signature, $kid ) ) {
		wphc_throttle_register_failure();
		return new WP_Error( 'wphc_revoke_unauthorized', __( 'Richiesta di revoca non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
	}

	if ( ! in_array( wphc_normalize_url( $site_url ), wphc_candidate_site_urls(), true ) ) {
		return new WP_Error(
			'wphc_revoke_url_mismatch',
			__( 'URL della busta di revoca non corrispondente a questo sito.', 'wp-health-check' ),
			array( 'status' => 403 )
		);
	}

	wphc_remember_nonce( $nonce );

	update_option( 'wp_health_check_revoked_at', gmdate( 'c' ), false );
	delete_option( 'wp_health_check_token_prev' );
	delete_option( 'wp_health_check_token_rotated_at' );

	// Invalida ogni token di autologin gia' emesso e non ancora consumato:
	// delete_transient() (non una query diretta su wp_options) e' l'unico
	// modo corretto di rimuovere un transient anche quando il sito usa un
	// object cache persistente (Redis/Memcached).
	$pending_autologins = (array) get_option( 'wp_health_check_autologin_pending', array() );
	foreach ( $pending_autologins as $pending_key ) {
		delete_transient( $pending_key );
	}
	delete_option( 'wp_health_check_autologin_pending' );

	wphc_log_update_row( wphc_generate_correlation_id(), 'token', 'revocation', __( 'Segreto revocato dal sistema centrale', 'wp-health-check' ), null, null, 'completed' );

	return rest_ensure_response(
		array(
			'revoked' => true,
			'site'    => $site_url,
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
// HELPER: thumbnail del sito (thum.io)
// -----------------------------------------------------------------------

/**
 * Scarica dal servizio configurato (WP_HEALTH_CHECK_THUMB_SERVICE, thum.io di
 * default) uno screenshot PNG (larghezza 400, altezza proporzionale) della
 * home pubblica del sito e lo carica nel Media Library. Operazione remota e
 * potenzialmente lenta: va chiamata solo tramite wphc_maybe_generate_thumbnail(),
 * mai direttamente, per rispettare la guardia anti-retry-loop.
 *
 * Dalla 1.30.0 ritorna un array strutturato invece di un int|null silenzioso:
 * ogni punto di fallimento (prima tutti indistinguibili, un semplice "non
 * generata") produce un codice diagnostico esplicito, che
 * wphc_maybe_generate_thumbnail() persiste in wp_health_check_thumb_error e
 * che GET /thumbnail e la tab Site Health espongono.
 *
 * @return array{attachment_id: int|null, error: array{code: string, message: string}|null} Esito della generazione.
 */
function wphc_generate_site_thumbnail() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Preflight sulla scrivibilita' di uploads/: e' la causa di fallimento
	// piu' frequente e, prima della 1.30.0, non era distinguibile da un
	// fallimento di rete (entrambi tornavano semplicemente null).
	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		return array(
			'attachment_id' => null,
			'error'         => array(
				'code'    => 'wphc_thumb_uploads_not_writable',
				'message' => (string) $upload_dir['error'],
			),
		);
	}

	// noanimate+png: senza queste due opzioni thum.io risponde in streaming
	// con un GIF animato (spinner + render progressivo), pensato per essere
	// mostrato dal vivo, non per essere salvato come file.
	// get_home_url() (non get_site_url()): la home PUBBLICA del sito, non
	// l'indirizzo WordPress (che puo' differire, es. installazioni in una
	// sottocartella tecnica).
	$service_url = WP_HEALTH_CHECK_THUMB_SERVICE . get_home_url();

	$tmp_file = download_url( $service_url, 20 );
	if ( is_wp_error( $tmp_file ) ) {
		return array(
			'attachment_id' => null,
			'error'         => array(
				'code'    => 'wphc_thumb_download_failed',
				'message' => $tmp_file->get_error_message(),
			),
		);
	}

	// thum.io non garantisce il Content-Type nell'header: si rileva il tipo
	// reale dal file scaricato invece di assumere .png.
	$image_info = getimagesize( $tmp_file );
	if ( false === $image_info ) {
		wp_delete_file( $tmp_file );
		return array(
			'attachment_id' => null,
			'error'         => array(
				'code'    => 'wphc_thumb_not_an_image',
				'message' => __( 'La risposta del servizio di screenshot non e\' un\'immagine valida.', 'wp-health-check' ),
			),
		);
	}
	if ( IMAGETYPE_GIF === $image_info[2] ) {
		// Un GIF qui significa che noanimate/png non hanno avuto effetto
		// (es. sito non ancora renderizzato): si scarta, il cooldown
		// gestira' il retry.
		wp_delete_file( $tmp_file );
		return array(
			'attachment_id' => null,
			'error'         => array(
				'code'    => 'wphc_thumb_animated_gif',
				'message' => __( 'Il servizio ha risposto con un GIF animato invece del PNG atteso.', 'wp-health-check' ),
			),
		);
	}

	$extension = image_type_to_extension( $image_info[2] );
	if ( false === $extension ) {
		$extension = '.png';
	}

	$file_array = array(
		'name'     => 'wp-health-check-site-thumb' . $extension,
		'tmp_name' => $tmp_file,
	);

	// post_id = 0: l'attachment non appartiene a nessun post, e' solo un
	// riferimento tenuto in wp_health_check_thumb/wp_health_check_thumb_id.
	$attachment_id = media_handle_sideload( $file_array, 0, 'WP Health Check site thumbnail' );

	if ( is_wp_error( $attachment_id ) ) {
		// media_handle_sideload() ripulisce $tmp_file solo in caso di successo.
		wp_delete_file( $tmp_file );
		return array(
			'attachment_id' => null,
			'error'         => array(
				'code'    => 'wphc_thumb_sideload_failed',
				'message' => $attachment_id->get_error_message(),
			),
		);
	}

	return array(
		'attachment_id' => $attachment_id,
		'error'         => null,
	);
}

/**
 * Ritorna l'URL della thumbnail del sito, generandola alla prima chiamata (o
 * on-demand con $force=true, vedi POST /thumbnail e il pulsante "Rigenera
 * anteprima" della tab Site Health). Chiamate successive con l'opzione gia'
 * valorizzata e $force=false sono O(1) (una sola get_option): /health la
 * chiama sempre con $force=false, quindi non paga mai il costo di una
 * rigenerazione nel suo percorso caldo. Un transient di cooldown (1 giorno)
 * impedisce che un fallimento del servizio trasformi ogni /health in un
 * nuovo tentativo di chiamata remota; $force=true lo ignora esplicitamente.
 *
 * Ogni fallimento e' persistito in wp_health_check_thumb_error (dalla
 * 1.30.0): mai piu' un null silenzioso senza diagnosi, vedi
 * wphc_generate_site_thumbnail().
 *
 * @param bool $force Se true, ignora cooldown e attachment esistente e rigenera comunque.
 * @return string|null URL assoluto della thumbnail, o null se non disponibile.
 */
function wphc_maybe_generate_thumbnail( $force = false ) {
	if ( ! $force ) {
		$existing = get_option( 'wp_health_check_thumb' );
		if ( $existing ) {
			return $existing;
		}

		if ( false !== get_transient( 'wphc_thumb_retry_lock' ) ) {
			return null;
		}
	}

	// Impostato PRIMA del tentativo (anche quando $force=true): se il
	// servizio fallisce, il lock resta per il resto del TTL indipendentemente
	// dall'esito, cosi' anche una rigenerazione forzata non puo' trasformarsi
	// in un ciclo di retry ravvicinati contro il servizio remoto.
	set_transient( 'wphc_thumb_retry_lock', time(), DAY_IN_SECONDS );

	if ( $force ) {
		// Rigenerazione esplicita: elimina l'attachment precedente (se
		// esiste) prima di generarne uno nuovo, stessa pulizia gia' fatta dal
		// pulsante admin "Elimina e rigenera", vedi wphc_handle_delete_thumbnail().
		$previous_id = (int) get_option( 'wp_health_check_thumb_id' );
		if ( $previous_id ) {
			wp_delete_attachment( $previous_id, true );
		}
		delete_option( 'wp_health_check_thumb' );
		delete_option( 'wp_health_check_thumb_id' );
	}

	$result = wphc_generate_site_thumbnail();

	if ( ! empty( $result['error'] ) ) {
		update_option(
			'wp_health_check_thumb_error',
			array(
				'at'       => gmdate( 'c' ),
				'code'     => $result['error']['code'],
				'message'  => $result['error']['message'],
				'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
			),
			false
		);

		return null;
	}

	$attachment_id = $result['attachment_id'];
	$url           = wp_get_attachment_url( $attachment_id );
	if ( ! $url ) {
		update_option(
			'wp_health_check_thumb_error',
			array(
				'at'       => gmdate( 'c' ),
				'code'     => 'wphc_thumb_sideload_failed',
				'message'  => __( 'Attachment creato ma senza un URL recuperabile.', 'wp-health-check' ),
				'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
			),
			false
		);

		return null;
	}

	// L'ID viene conservato accanto all'URL per permettere l'eliminazione
	// affidabile dell'attachment dal pulsante "Elimina e rigenera" in admin.
	update_option( 'wp_health_check_thumb', $url, false );
	update_option( 'wp_health_check_thumb_id', $attachment_id, false );
	update_option( 'wp_health_check_thumb_at', time(), false );
	delete_option( 'wp_health_check_thumb_error' );
	delete_transient( 'wphc_thumb_retry_lock' );

	return $url;
}

// -----------------------------------------------------------------------
// CALLBACK: GET|POST /thumbnail
// -----------------------------------------------------------------------

/**
 * GET /thumbnail — stato corrente della thumbnail del sito: URL (se
 * disponibile), timestamp dell'ultima generazione riuscita ed eventuale
 * ultimo errore diagnostico completo. Economica quanto /health: legge solo
 * opzioni gia' mantenute, nessuna chiamata remota (vedi wphc_maybe_generate_thumbnail()).
 *
 * @param WP_REST_Request $request Richiesta REST corrente (nessun payload richiesto).
 * @return WP_REST_Response Stato della thumbnail.
 */
function wphc_route_thumbnail_status( WP_REST_Request $request ) {
	unset( $request );

	wphc_record_access();

	$thumbnail = get_option( 'wp_health_check_thumb' );
	$error     = get_option( 'wp_health_check_thumb_error' );

	return rest_ensure_response(
		array(
			'thumbnail'    => $thumbnail ? $thumbnail : null,
			'generated_at' => ( $thumbnail && get_option( 'wp_health_check_thumb_at' ) ) ? gmdate( 'c', (int) get_option( 'wp_health_check_thumb_at' ) ) : null,
			'error'        => is_array( $error ) ? $error : null,
		)
	);
}

/**
 * POST /thumbnail — rigenera la thumbnail on demand, ignorando il cooldown
 * di un giorno (ma comunque impostandolo per il tentativo appena fatto, vedi
 * wphc_maybe_generate_thumbnail( true )). Risponde con lo stesso esito o
 * errore strutturato, cosi' un fallimento e' diagnosticabile immediatamente
 * invece di scoprirlo solo alla prossima /health.
 *
 * @param WP_REST_Request $request Richiesta REST corrente (nessun payload richiesto).
 * @return WP_REST_Response Esito della rigenerazione.
 */
function wphc_route_thumbnail_regenerate( WP_REST_Request $request ) {
	unset( $request );

	wphc_record_access();

	$thumbnail = wphc_maybe_generate_thumbnail( true );
	$error     = get_option( 'wp_health_check_thumb_error' );

	return rest_ensure_response(
		array(
			'thumbnail' => $thumbnail ? $thumbnail : null,
			'error'     => ( ! $thumbnail && is_array( $error ) ) ? $error : null,
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
	$public_ip = wphc_get_public_ip();

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
	$core_auto         = wphc_core_auto_update_state();

	// Screenshot del sito (thum.io): generato e caricato nel Media Library
	// una sola volta, alla prima /health con opzione vuota. Chiamate
	// successive leggono solo wp_health_check_thumb (O(1)); vedi
	// wphc_maybe_generate_thumbnail() per la guardia anti-retry-loop.
	$thumbnail = wphc_maybe_generate_thumbnail();
	// Solo il codice, non il messaggio completo (che puo' contenere l'esito
	// grezzo di un WP_Error di rete): il payload di /health e' polled di
	// frequente e non deve gonfiarsi con diagnostica estesa, disponibile
	// invece per intero su GET /thumbnail e nella tab Site Health.
	$thumbnail_error = get_option( 'wp_health_check_thumb_error' );

	$payload = array(
		'site'                => wphc_normalize_site_url(),
		'generated_at'        => gmdate( 'c' ),
		'fleet_agent_version' => WP_HEALTH_CHECK_VERSION,
		'summary'             => array(
			'wp_version'                  => get_bloginfo( 'version' ),
			'php_version'                 => PHP_VERSION,
			'php_memory_limit'            => (string) ini_get( 'memory_limit' ),
			'server_ip'                   => '' !== $server_ip ? $server_ip : null,
			'server_ip_is_private'        => '' !== $server_ip && ! wphc_ip_is_public( $server_ip ),
			'public_ip'                   => '' !== $public_ip ? $public_ip : null,
			'plugin_version'              => WP_HEALTH_CHECK_VERSION,
			'plugins_total'               => count( $all_plugins ),
			'plugins_active'              => count( $active_plugins ),
			'plugins_updates'             => $plugins_updates_count,
			'themes_total'                => count( $all_themes ),
			'themes_updates'              => $themes_updates_count,
			'theme_name'                  => $active_theme_name,
			'parent_theme_name'           => $parent_theme_name,
			'is_multisite'                => is_multisite(),
			'comments_pending'            => (int) wp_count_comments()->moderated,
			'core_update'                 => $core_update_available,
			'core_auto_update'            => $core_auto['enabled'],
			'core_auto_update_level'      => $core_auto['level'],
			'core_auto_update_blocked_by' => $core_auto['blocked_by'],
			'plugins_auto_update_enabled' => wphc_auto_update_enabled_for( 'plugin' ),
			'themes_auto_update_enabled'  => wphc_auto_update_enabled_for( 'theme' ),
			'bulk_update'                 => wphc_bulk_summary(),
			'has_gdpr'                    => $signals['has_gdpr'],
			'has_builder'                 => $signals['has_builder'],
			'has_ecommerce'               => $signals['has_ecommerce'],
			'mu_dir_writable'             => (bool) wp_is_writable( WPMU_PLUGIN_DIR ),
			'updates_checked_at'          => $updates_checked_at,
			'updates_via_api_enabled'     => (bool) get_option( 'wp_health_check_updates_enabled', true ),
			'restrict_official_only'      => (bool) get_option( 'wp_health_check_restrict_official_only', false ),
			'last_update'                 => $last_update ? $last_update : null,
			'maintenance_stuck'           => $maintenance_stuck,
			'thumbnail'                   => $thumbnail ? $thumbnail : null,
			'thumbnail_error'             => ( ! $thumbnail && is_array( $thumbnail_error ) && ! empty( $thumbnail_error['code'] ) ) ? $thumbnail_error['code'] : null,
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
// CALLBACK: GET /ping
// -----------------------------------------------------------------------

/**
 * Heartbeat volutamente minimale: pensato per un polling ad alta frequenza
 * (uptime + tempo di risposta) senza pagare il costo di /health. A
 * differenza di wphc_route_health(), qui non si chiama MAI get_plugins()/
 * wp_get_themes() (le uniche due operazioni realmente O(n), con scansione
 * di filesystem, dell'intera rotta /health), e non si scrive mai su
 * wp_options: si salta deliberatamente wphc_record_access(), perche' un
 * ping ad alta frequenza non e' un "accesso" ai fini dell'audit (che resta
 * tracciato da /health, interrogata piu' di rado ma con piu' significato).
 * Nessuna cache/transient: lo scopo della rotta e' misurare il tempo di
 * risposta di QUESTA chiamata, una cache lo renderebbe inutile — e non ce
 * n'e' comunque bisogno, il costo e' gia' O(1) senza bisogno di cache.
 * Stessa autenticazione delle altre rotte dati (bearer token), per non
 * introdurre una nuova superficie anonima.
 *
 * @param WP_REST_Request $request Richiesta REST corrente (nessun parametro).
 * @return WP_REST_Response Esito minimale.
 */
function wphc_route_ping( WP_REST_Request $request ) {
	unset( $request );

	return rest_ensure_response(
		array(
			'status'        => 'ok',
			'site'          => wphc_normalize_site_url(),
			'agent_version' => WP_HEALTH_CHECK_VERSION,
			'generated_at'  => gmdate( 'c' ),
		)
	);
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

	// Sempre get_site_option(), mai get_option(): su multisite auto_update_plugins
	// e' una option di RETE condivisa da tutti i siti (in sitemeta), e get_option()
	// tornerebbe sempre array vuoto li', facendo apparire ogni plugin come "auto-update
	// spento" anche quando non lo e'. Su single-site get_site_option() ricade da sola
	// su get_option(), quindi e' corretta in entrambi i casi.
	$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );

	$items = array();
	foreach ( $all_plugins as $plugin_file => $plugin_data ) {
		$has_update  = isset( $plugin_updates[ $plugin_file ]->update->new_version );
		$new_version = $has_update ? $plugin_updates[ $plugin_file ]->update->new_version : null;

		// Slug: la cartella del plugin (plugin.php in cartella "foo/foo.php"
		// -> "foo"); per i plugin a file singolo nella radice ("bar.php")
		// non esiste cartella, quindi si usa il nome file senza estensione.
		$plugin_dir = dirname( $plugin_file );
		$slug       = ( '.' !== $plugin_dir ) ? $plugin_dir : basename( $plugin_file, '.php' );

		// Convenzione core: null = nessun filtro forza lo stato, si applica la
		// scelta salvata dall'admin (auto_update_plugins); true|false = un
		// filtro di terze parti impone lo stato indipendentemente dalla option,
		// e la UI di WP mostra "gestito da un plugin" senza toggle disponibile.
		// L'item passato al filtro deve avere almeno 'plugin'/'slug': alcuni
		// filtri di terze parti li leggono e generano un warning se assenti.
		$item_object        = (object) array_merge(
			$plugin_data,
			array(
				'plugin' => $plugin_file,
				'slug'   => $slug,
			)
		);
		$forced             = wp_is_auto_update_forced_for_item( 'plugin', null, $item_object );
		$auto_update_stored = in_array( $plugin_file, $auto_update_plugins, true );
		// Vedi la nota su wphc_theme_auto_update_state(): lo stub PHPStan
		// dichiara "bool", l'implementazione reale puo' restituire null.
		$auto_update_effective = ( null === $forced ) ? $auto_update_stored : (bool) $forced; // @phpstan-ignore identical.alwaysFalse

		$items[] = array(
			'name'               => $plugin_data['Name'],
			'slug'               => $slug,
			// Plugin file (chiave di get_plugins(), es. "wordpress-seo/wp-seo.php"):
			// e' il valore esatto da passare come "plugin" a POST /update/plugin,
			// a differenza di "slug" che e' solo la cartella e non identifica
			// univocamente il file principale per i plugin a file singolo.
			'file'               => $plugin_file,
			'version'            => $plugin_data['Version'],
			'active'             => in_array( $plugin_file, $active_plugins, true ),
			'update_available'   => $has_update,
			'new_version'        => $new_version,
			'auto_update'        => $auto_update_effective,
			'auto_update_forced' => $forced,
		);
	}

	$payload = array(
		'site'                         => wphc_normalize_site_url(),
		'generated_at'                 => gmdate( 'c' ),
		'count'                        => count( $items ),
		'auto_update_enabled_globally' => wphc_auto_update_enabled_for( 'plugin' ),
		'plugins'                      => $items,
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

	// Sempre get_site_option(): stessa ragione multisite di auto_update_plugins
	// in wphc_route_detail_plugins() qui sopra.
	$auto_update_themes = (array) get_site_option( 'auto_update_themes', array() );

	$active_auto_update = wphc_theme_auto_update_state( $stylesheet, $auto_update_themes );

	$active_theme_payload = array(
		'name'               => $active_theme->get( 'Name' ),
		'stylesheet'         => $stylesheet,
		'version'            => $active_theme->get( 'Version' ),
		'update_available'   => $has_update,
		'new_version'        => $new_version,
		'auto_update'        => $active_auto_update['auto_update'],
		'auto_update_forced' => $active_auto_update['auto_update_forced'],
	);

	$parent               = $active_theme->parent();
	$parent_theme_payload = null;
	if ( $parent instanceof WP_Theme ) {
		$parent_stylesheet    = $parent->get_stylesheet();
		$parent_auto_update   = wphc_theme_auto_update_state( $parent_stylesheet, $auto_update_themes );
		$parent_theme_payload = array(
			'name'               => $parent->get( 'Name' ),
			'version'            => $parent->get( 'Version' ),
			'auto_update'        => $parent_auto_update['auto_update'],
			'auto_update_forced' => $parent_auto_update['auto_update_forced'],
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
		$item_auto_update = wphc_theme_auto_update_state( (string) $theme_stylesheet, $auto_update_themes );

		$themes[] = array(
			'name'               => $theme->get( 'Name' ),
			'stylesheet'         => (string) $theme_stylesheet,
			'version'            => $theme->get( 'Version' ),
			'active'             => ( (string) $theme_stylesheet === $active_stylesheet ),
			// get_template() restituisce lo stylesheet del parent per un child
			// theme, oppure quello del tema stesso se non e' un child: si
			// espone il parent solo nel primo caso, altrimenti null.
			'parent'             => $theme->parent() ? $theme->get_template() : null,
			'update_available'   => $item_has_update,
			'new_version'        => $item_new_version,
			'auto_update'        => $item_auto_update['auto_update'],
			'auto_update_forced' => $item_auto_update['auto_update_forced'],
		);
	}

	$payload = array(
		'site'                         => wphc_normalize_site_url(),
		'generated_at'                 => gmdate( 'c' ),
		'auto_update_enabled_globally' => wphc_auto_update_enabled_for( 'theme' ),
		'active_theme'                 => $active_theme_payload,
		'parent_theme'                 => $parent_theme_payload,
		'themes'                       => $themes,
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
	$public_ip = wphc_get_public_ip();

	$payload = array(
		'site'         => wphc_normalize_site_url(),
		'generated_at' => gmdate( 'c' ),
		'server'       => array(
			'software'             => $software,
			'server_ip'            => '' !== $server_ip ? $server_ip : null,
			'server_ip_is_private' => '' !== $server_ip && ! wphc_ip_is_public( $server_ip ),
			'public_ip'            => '' !== $public_ip ? $public_ip : null,
			'php_version'          => PHP_VERSION,
			'php_sapi'             => PHP_SAPI,
			'php_memory_limit'     => (string) ini_get( 'memory_limit' ),
			'max_execution_time'   => (string) ini_get( 'max_execution_time' ),
			'max_input_vars'       => (string) ini_get( 'max_input_vars' ),
			'upload_max_filesize'  => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'        => (string) ini_get( 'post_max_size' ),
			'mysql_version'        => $mysql_version,
			'https'                => is_ssl(),
			'extensions'           => array(
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
// CALLBACK: GET /detail/users
// -----------------------------------------------------------------------

/**
 * Riga utente esposta da GET /detail/users. Il chiamante deve aver gia'
 * innescato update_meta_cache('user', ...) per il gruppo di ID coinvolto,
 * altrimenti le due get_user_meta() qui sotto eseguono una query per utente
 * invece di leggere dalla cache gia' popolata in blocco.
 *
 * @param object|WP_User $user Utente da cui estrarre i campi (con almeno
 *                             ID, user_login, display_name, user_email,
 *                             user_registered).
 * @return array<string, mixed>
 */
function wphc_build_user_summary( $user ) {
	$last_login    = get_user_meta( $user->ID, 'wphc_last_login', true );
	$last_login_ip = get_user_meta( $user->ID, 'wphc_last_login_ip', true );

	return array(
		'id'            => (int) $user->ID,
		'user_login'    => $user->user_login,
		'display_name'  => $user->display_name,
		'email'         => $user->user_email,
		// user_registered e' gia' salvato in UTC: il suffisso esplicito
		// toglie ogni ambiguita' a strtotime(), come gia' fa
		// wphc_get_update_log_entries() per created_at.
		'registered'    => gmdate( 'c', strtotime( $user->user_registered . ' UTC' ) ),
		// Null finche' l'utente non effettua un accesso DOPO l'aggiornamento
		// a questa versione dell'agent (wphc_record_last_login() e' nuova
		// dalla 1.29.0): non va letto come "account dormiente" finche' non
		// e' passato abbastanza tempo da escludere semplicemente questo caso.
		'last_login'    => $last_login ? gmdate( 'c', (int) $last_login ) : null,
		'last_login_ip' => $last_login_ip ? $last_login_ip : null,
	);
}

/**
 * Elenco degli amministratori del sito, per censire dalla dashboard
 * centrale chi ha accesso pieno. Legge il ruolo "administrator" sul blog
 * corrente; su multisite include anche i super admin di rete (accesso
 * pieno anche senza il ruolo administrator sul singolo blog), deduplicati
 * per user_login. Nessuna cache transient: dato anagrafico a bassa
 * frequenza di interrogazione, a differenza di /detail/plugins e /detail/theme.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response Elenco amministratori.
 */
function wphc_route_detail_users( WP_REST_Request $request ) {
	unset( $request );

	wphc_record_access();

	// 'fields' come array di nomi colonna di wp_users (non un WP_User
	// completo): restituisce oggetti gia' limitati ai soli campi richiesti.
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'fields' => array( 'ID', 'user_login', 'display_name', 'user_email', 'user_registered' ),
		)
	);

	// get_users() con 'fields' come array NON precarica la meta cache
	// dell'utente (a differenza di un WP_User_Query "pieno"): senza questa
	// chiamata, wphc_build_user_summary() farebbe una query di meta per
	// ciascun amministratore invece di una sola query in blocco.
	if ( $admins ) {
		update_meta_cache( 'user', wp_list_pluck( $admins, 'ID' ) );
	}

	$users       = array();
	$seen_logins = array();
	foreach ( $admins as $user ) {
		$users[]                          = wphc_build_user_summary( $user );
		$seen_logins[ $user->user_login ] = true;
	}

	// Multisite: i super admin di rete non hanno necessariamente il ruolo
	// administrator sul SINGOLO blog (es. non sono mai stati aggiunti come
	// utente di questo sito), ma hanno comunque accesso pieno. get_super_admins()
	// restituisce solo i login: si risolve ciascuno e si aggiunge solo se non
	// gia' incluso sopra.
	if ( is_multisite() ) {
		$extra_users = array();
		foreach ( get_super_admins() as $login ) {
			if ( isset( $seen_logins[ $login ] ) ) {
				continue;
			}
			$user = get_user_by( 'login', $login );
			if ( ! $user ) {
				continue;
			}
			$extra_users[]         = $user;
			$seen_logins[ $login ] = true;
		}

		// Stesso motivo della chiamata sopra: un solo giro di priming per
		// tutto il gruppo di super admin risolti in questo blocco, invece di
		// una query di meta per ciascuno dentro wphc_build_user_summary().
		if ( $extra_users ) {
			update_meta_cache( 'user', wp_list_pluck( $extra_users, 'ID' ) );
		}

		foreach ( $extra_users as $user ) {
			$users[] = wphc_build_user_summary( $user );
		}
	}

	return rest_ensure_response(
		array(
			'site'         => wphc_normalize_site_url(),
			'generated_at' => gmdate( 'c' ),
			'count'        => count( $users ),
			'users'        => $users,
		)
	);
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
		phase VARCHAR(32) NOT NULL,
		message VARCHAR(255) DEFAULT NULL,
		ip VARCHAR(45) DEFAULT NULL,
		active TINYINT(1) DEFAULT NULL,
		source VARCHAR(10) NOT NULL DEFAULT 'api',
		actor VARCHAR(60) DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY correlation_id (correlation_id),
		KEY type_created_at (type, created_at),
		KEY source_created_at (source, created_at)
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
 * @param string      $type           'plugin' | 'theme' | 'core' | 'token' | 'login' (questi
 *                                    ultimi due dall'audit trail dell'autologin, vedi
 *                                    wphc_route_autologin_token()/wphc_maybe_consume_autologin()).
 * @param string      $target         Plugin file, stylesheet, 'core', oppure per 'token'/'login'
 *                                    lo user_login dell'utente coinvolto (o, quando l'identita'
 *                                    non e' recuperabile, un identificatore di ripiego: user_id
 *                                    o prefisso hash del token).
 * @param string      $name           Nome leggibile dell'elemento; per 'token'/'login' il
 *                                    display_name dell'utente (fallback a user_login).
 * @param string|null $version_from   Versione installata prima dell'update; sempre null per
 *                                    'token'/'login'.
 * @param string|null $version_to     Versione target/attesa (dal transient del core); sempre
 *                                    null per 'token'/'login'.
 * @param string      $phase          'requested' | 'completed' | 'failed' | 'rolled_back' |
 *                                    'reactivated' | 'reactivation_failed' (questi ultimi due
 *                                    solo da wphc_perform_reactivate()); 'token'/'login' riusano
 *                                    'completed'/'failed', nessun valore nuovo per loro.
 * @param string|null $message        Dettaglio in caso di errore/rollback.
 * @param bool|null   $active         Stato attivo dell'elemento in questo momento
 *                                    (solo plugin: true/false; null per temi/core/token/login
 *                                    o quando non rilevato).
 * @param string      $source         'api' | 'wp-admin' | 'cron' | 'wp-cli': chi ha avviato
 *                                    l'operazione. Default 'api' per non toccare le chiamate
 *                                    esistenti dal flusso REST di update.
 * @param string|null $actor          user_login di chi ha avviato l'operazione da wp-admin;
 *                                    null per cron, wp-cli e le operazioni via API.
 * @return int ID della riga inserita (0 se l'insert fallisce).
 */
function wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, $phase, $message = null, $active = null, $source = 'api', $actor = null ) {
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
			// $wpdb->insert() ignora il formato dichiarato quando il valore e'
			// realmente null (usa NULL SQL a prescindere), quindi %d va bene
			// anche per le righe temi/core dove $active resta null.
			'active'         => null === $active ? null : (int) $active,
			'source'         => $source,
			'actor'          => $actor,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

/**
 * Determina, dal solo storico di log, quali plugin risultavano attivi
 * l'ultima volta che il loro stato e' stato registrato (riga 'requested' o
 * 'completed' con 'active' valorizzato). Per ciascun target distinto si
 * considera SOLO la riga piu' recente non-null: cosi' un plugin disattivato
 * volontariamente in un update successivo (che avrebbe loggato active=0)
 * smette correttamente di comparire come candidato, evitando falsi positivi.
 * Non confronta con lo stato reale corrente: quello e' compito del chiamante
 * (wphc_perform_reactivate()), qui si restituisce solo l'atteso secondo il log.
 *
 * @return array<int, array{target: string, name: string}> Plugin attesi attivi secondo il log.
 */
function wphc_get_reactivation_candidates() {
	global $wpdb;
	$table = wphc_update_log_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabella custom non cacheata dall'object cache di WP.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.target, l.name, l.active FROM {$table} l INNER JOIN ( SELECT target, MAX(id) AS max_id FROM {$table} WHERE type = %s AND active IS NOT NULL GROUP BY target ) latest ON latest.max_id = l.id WHERE l.active = 1", 'plugin' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table e' un nome fisso ($wpdb->prefix), non input (appare due volte nella query).

	$candidates = array();
	foreach ( (array) $rows as $row ) {
		$candidates[] = array(
			'target' => $row['target'],
			'name'   => $row['name'],
		);
	}

	return $candidates;
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
 * @param string $source 'api' | 'wp-admin' | 'cron' | 'wp-cli'. Default 'api' per non
 *                       toccare le chiamate esistenti dal flusso REST di update.
 */
function wphc_record_last_update( $type, $target, $phase, $source = 'api' ) {
	update_option(
		'wp_health_check_last_update',
		array(
			'type'   => $type,
			'target' => $target,
			'phase'  => $phase,
			'source' => $source,
			'at'     => gmdate( 'c' ),
		)
	);
}

/**
 * Determina chi ha avviato un aggiornamento non passato dalla rotta REST
 * (quel caso resta sempre 'api', valore di default di wphc_log_update_row()).
 * Distingue WP-CLI e i cron (dove ricadono gli auto-update in background di
 * WordPress) da un'azione avviata a mano in wp-admin.
 *
 * @return string 'wp-cli' | 'cron' | 'wp-admin'.
 */
function wphc_detect_update_source() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return 'wp-cli';
	}

	if ( wp_doing_cron() ) {
		return 'cron';
	}

	return 'wp-admin';
}

/**
 * Restituisce lo user_login dell'utente correntemente autenticato in
 * wp-admin, da annotare nella riga di log come 'actor'. Null per cron e
 * WP-CLI, dove non esiste un utente WordPress associato all'operazione.
 *
 * @return string|null
 */
function wphc_current_actor() {
	$user = wp_get_current_user();

	return ( $user instanceof WP_User && $user->exists() ) ? $user->user_login : null;
}

/**
 * Cattura la versione installata di un plugin/tema PRIMA che
 * Plugin_Upgrader/Theme_Upgrader la sovrascriva, cosi' wphc_log_wp_initiated_update()
 * puo' usarla come version_from quando logga l'update a cose fatte (a quel
 * punto, in upgrader_process_complete, la versione vecchia non e' piu'
 * leggibile da nessuna parte). E' un *filter*: deve restituire $response
 * invariato, non e' questo il suo compito.
 *
 * Scatta una volta per elemento anche negli aggiornamenti in blocco
 * (Plugin_Upgrader::bulk_upgrade() invoca run() singolarmente per ciascun
 * plugin), quindi copre correttamente anche "aggiorna tutti i plugin".
 *
 * @param bool|WP_Error $response   Risposta corrente del filtro (pass-through).
 * @param array         $hook_extra Contesto dell'operazione corrente (chiavi 'plugin'/'theme').
 * @return bool|WP_Error $response, invariato.
 */
function wphc_snapshot_version_before_upgrade( $response, $hook_extra ) {
	if ( ! empty( $hook_extra['plugin'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = WP_PLUGIN_DIR . '/' . $hook_extra['plugin'];
		if ( is_readable( $plugin_file ) ) {
			$data = get_plugin_data( $plugin_file, false, false );
			$GLOBALS['wphc_version_snapshots']['plugin'][ $hook_extra['plugin'] ] = $data['Version'];
		}
	} elseif ( ! empty( $hook_extra['theme'] ) ) {
		$theme = wp_get_theme( $hook_extra['theme'] );
		if ( $theme->exists() ) {
			$GLOBALS['wphc_version_snapshots']['theme'][ $hook_extra['theme'] ] = (string) $theme->get( 'Version' );
		}
	}

	return $response;
}
add_filter( 'upgrader_pre_install', 'wphc_snapshot_version_before_upgrade', 10, 2 );

/**
 * Logga un update di plugin/tema avviato da WordPress stesso (wp-admin,
 * WP-CLI, oppure un auto-update in background via cron) invece che dalla
 * rotta REST di questo agent. Aggancia upgrader_process_complete, che
 * scatta a esito GIA' riuscito: si scrive quindi una sola riga 'completed'
 * (il pattern a due righe richiesta/completamento resta esclusivo del
 * flusso API, dove serve a provare che un update e' stato avviato anche se
 * PHP muore a meta').
 *
 * @param WP_Upgrader $upgrader   Istanza dell'upgrader; i dati utili sono tutti in $hook_extra.
 * @param array       $hook_extra Contesto dell'operazione completata.
 */
function wphc_log_wp_initiated_update( $upgrader, $hook_extra ) {
	unset( $upgrader );

	// Un update avviato da POST /update/plugin|/update/theme (che condivide
	// Plugin_Upgrader/Theme_Upgrader) fa scattare comunque questo hook: il
	// flag impostato attorno a $upgrader->upgrade() in
	// wphc_perform_item_update() segnala di ignorarlo, altrimenti l'update
	// risulterebbe loggato due volte.
	if ( ! empty( $GLOBALS['wphc_api_update_in_progress'] ) ) {
		return;
	}

	if ( ! isset( $hook_extra['action'], $hook_extra['type'] ) || 'update' !== $hook_extra['action'] ) {
		return;
	}

	if ( ! in_array( $hook_extra['type'], array( 'plugin', 'theme' ), true ) ) {
		return;
	}

	$type  = $hook_extra['type'];
	$items = array();

	if ( 'plugin' === $type ) {
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$items = $hook_extra['plugins']; // Aggiornamento in blocco.
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$items = array( $hook_extra['plugin'] ); // Aggiornamento singolo.
		}
	} elseif ( ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
			$items = $hook_extra['themes'];
	} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$items = array( $hook_extra['theme'] );
	}

	if ( empty( $items ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$source = wphc_detect_update_source();
	$actor  = wphc_current_actor();

	foreach ( $items as $target ) {
		if ( 'plugin' === $type ) {
			$plugin_file = WP_PLUGIN_DIR . '/' . $target;
			$data        = is_readable( $plugin_file ) ? get_plugin_data( $plugin_file, false, false ) : array();
			$name        = ! empty( $data['Name'] ) ? $data['Name'] : $target;
			$version_to  = isset( $data['Version'] ) ? $data['Version'] : null;
			$active      = is_plugin_active( $target );
		} else {
			$theme      = wp_get_theme( $target );
			$name       = $theme->exists() ? (string) $theme->get( 'Name' ) : $target;
			$version_to = $theme->exists() ? (string) $theme->get( 'Version' ) : null;
			// Nessun equivalente affidabile di "attivo" per un tema in un
			// bulk update: per convenzione (vedi wphc_log_update_row())
			// 'active' resta sempre null fuori dal caso plugin.
			$active = null;
		}

		$version_from = isset( $GLOBALS['wphc_version_snapshots'][ $type ][ $target ] )
			? $GLOBALS['wphc_version_snapshots'][ $type ][ $target ]
			: null;
		unset( $GLOBALS['wphc_version_snapshots'][ $type ][ $target ] );

		$correlation_id = wphc_generate_correlation_id();
		wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'completed', null, $active, $source, $actor );
		wphc_record_last_update( $type, $target, 'completed', $source );
	}
}
add_action( 'upgrader_process_complete', 'wphc_log_wp_initiated_update', 10, 2 );

/**
 * Rileva un aggiornamento del core avviato fuori dal flusso API (wp-admin,
 * WP-CLI, un auto-update in background, o perfino un update manuale via
 * FTP/pannello hosting) confrontando la versione corrente con l'ultima
 * vista, tenuta in un'opzione autoloaded. Core_Upgrader non fa scattare
 * upgrader_pre_install e in upgrader_process_complete la versione vecchia
 * non e' piu' leggibile: la divergenza rispetto al valore memorizzato e' il
 * solo segnale disponibile, ed e' anche l'unico che intercetta pure gli
 * update non passati da nessun hook di WordPress.
 *
 * Costo a regime: una sola get_option() autoloaded (zero query) piu' un
 * confronto di stringhe ad ogni richiesta, stesso pattern O(1) gia' usato
 * da wphc_maybe_install_update_log_schema().
 */
function wphc_maybe_log_core_version_change() {
	$seen = (string) get_option( 'wp_health_check_core_version', '' );
	$now  = (string) get_bloginfo( 'version' );

	if ( '' === $seen ) {
		// Prima esecuzione dopo il deploy di questa versione dell'agent: si
		// limita a "seminare" il valore, senza loggare una falsa divergenza
		// rispetto al nulla.
		update_option( 'wp_health_check_core_version', $now );
		return;
	}

	if ( $seen === $now ) {
		return;
	}

	// wphc_perform_core_update() aggiorna gia' questa opzione subito dopo un
	// update avviato via API: se si arriva qui con una divergenza reale, e'
	// per costruzione un update NON passato da quel flusso.
	update_option( 'wp_health_check_core_version', $now );

	$source         = wphc_detect_update_source();
	$correlation_id = wphc_generate_correlation_id();
	wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $seen, $now, 'completed', null, null, $source, wphc_current_actor() );
	wphc_record_last_update( 'core', 'core', 'completed', $source );
}
add_action( 'init', 'wphc_maybe_log_core_version_change' );

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
 * Verifica che l'host del pacchetto di update sia ammesso. Di DEFAULT
 * ammette qualunque host: l'update via API puo' aggiornare qualsiasi
 * plugin/tema, non solo quelli ospitati su wordpress.org. Questo non e' un
 * indebolimento del vincolo di sicurezza non negoziabile (vedi README.md,
 * "Il vincolo di sicurezza non negoziabile"): il payload REST non accetta
 * MAI un package_url o una version dal chiamante, quindi $package_url qui e'
 * sempre un valore che il sistema di aggiornamento del sito STESSO ha gia'
 * determinato (transient del core, o di un update-checker premium gia'
 * attivo), mai un input della richiesta. Se l'opzione
 * wp_health_check_restrict_official_only e' attiva, si applica invece la
 * allowlist storica (solo downloads.wordpress.org/api.wordpress.org), per i
 * siti che vogliono escludere esplicitamente i plugin/temi premium
 * dall'update via API: vedi il checkbox dedicato nella tab Site Health.
 *
 * @param string $package_url URL del pacchetto da scaricare.
 * @return bool True se l'host e' ammesso.
 */
function wphc_is_package_host_allowed( $package_url ) {
	if ( ! get_option( 'wp_health_check_restrict_official_only', false ) ) {
		return true;
	}

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

	if ( ! get_option( 'wp_health_check_updates_enabled', true ) ) {
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
 * Preambolo per POST /update/reactivate: solo accesso, kill-switch e lock
 * anti-concorrenza (condiviso con le rotte di update, per non riattivare
 * mentre un altro update e' in corso). Deliberatamente PIU' LEGGERO di
 * wphc_update_preflight(): la riattivazione non scarica ne' scrive alcun
 * pacchetto, quindi il requisito WP >= 6.3 e il preflight del filesystem
 * ('direct') non si applicano qui.
 *
 * In caso di successo acquisisce il lock: il chiamante deve rilasciarlo con
 * wphc_release_update_lock() su ogni uscita successiva (rilascio comunque
 * garantito anche in caso di crash, vedi register_shutdown_function sotto).
 *
 * @return true|array True se si puo' procedere, altrimenti array-esito con
 *                     chiavi 'result' e 'http' da restituire cosi' com'e'.
 */
function wphc_reactivate_preflight() {
	wphc_record_access();

	if ( ! get_option( 'wp_health_check_updates_enabled', true ) ) {
		return array(
			'result' => 'disabled',
			'http'   => 403,
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
 * Dopo un update di plugin/tema riuscito, corregge SOLO la entry
 * dell'elemento appena aggiornato nel transient update_plugins/update_themes
 * gia' esistente, invece di cancellarlo (wp_clean_plugins_cache( true )) o
 * di rigenerarlo (wp_update_plugins()/wp_update_themes()).
 *
 * Quest'ultime, in contesto REST, ricostruirebbero il transient SENZA gli
 * aggiornamenti dei plugin/temi premium (i cui update-checker non vengono
 * caricati qui, vedi wphc_mute_update_shortcircuit()), sovrascrivendo quello
 * completo mantenuto dal cron con uno incompleto — lo stesso bug gia'
 * risolto per /health e /detail/plugins (1.13.0/1.16.0). Rimuovendo solo la
 * entry che sappiamo per certo risolta, tutte le altre righe (incluse
 * quelle premium) restano intatte, senza alcuna chiamata di rete aggiuntiva.
 *
 * Chiamata SOLO sull'esito 'completed': su 'rolled_back' l'update a
 * $version_to e' ancora effettivamente pendente (il rollback ha ripristinato
 * $version_from), su 'failed' lo stato e' incerto — in entrambi i casi e'
 * piu' sicuro non toccare il transient.
 *
 * @param string $type       'plugin' | 'theme'.
 * @param string $target     Plugin file oppure stylesheet appena aggiornato.
 * @param string $version_to Versione appena installata.
 */
function wphc_patch_update_transient_after_success( $type, $target, $version_to ) {
	$transient_name = ( 'plugin' === $type ) ? 'update_plugins' : 'update_themes';
	$transient      = get_site_transient( $transient_name );

	if ( ! is_object( $transient ) || ! isset( $transient->response[ $target ] ) ) {
		return;
	}

	unset( $transient->response[ $target ] );
	if ( isset( $transient->checked ) && is_array( $transient->checked ) ) {
		$transient->checked[ $target ] = $version_to;
	}

	// Expiration 0 = non tocca il timeout gia' impostato dall'ultimo check
	// reale del cron: si corregge solo il valore, senza resettare la
	// "freschezza" del transient ne' forzare un nuovo check anticipato.
	set_site_transient( $transient_name, $transient );
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
 * @param string $source  'api' | 'cron': chi ha AVVIATO l'esecuzione di questo tentativo
 *                        (annotato nella riga di log e nell'ultimo aggiornamento). Default
 *                        'api' per non toccare le chiamate esistenti dalle rotte REST
 *                        sincrone; il drain degli aggiornamenti bulk passa 'cron'.
 * @return array Esito normalizzato, vedi wphc_map_item_update_outcome() per il mapping REST.
 */
function wphc_perform_item_update( $type, $target, $dry_run = false, $source = 'api' ) {
	$preflight = wphc_update_preflight( true );
	if ( true !== $preflight ) {
		return $preflight;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// Stato attivo del plugin prima dell'update (solo plugin: un update non
	// cambia mai il tema attivo, non c'e' un equivalente da tracciare per
	// temi/core). $was_network_active distingue l'attivazione di rete
	// (multisite) da quella per singolo sito, per poter riattivare nello
	// stesso ambito se necessario (vedi sotto).
	$was_active         = null;
	$was_network_active = false;

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

		$was_network_active = is_multisite() && is_plugin_active_for_network( $target );
		$was_active         = $was_network_active || is_plugin_active( $target );

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
	wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'requested', null, $was_active, $source );

	$skin     = new Automatic_Upgrader_Skin(); // Nessun output HTML: la richiesta e' REST, non una pagina admin.
	$upgrader = ( 'plugin' === $type ) ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );

	// Flag di richiesta (muore da sola a fine PHP, anche in caso di fatal):
	// upgrade() scatena gli stessi hook upgrader_pre_install/
	// upgrader_process_complete di un update fatto da wp-admin
	// (wphc_log_wp_initiated_update()), che altrimenti loggerebbe due volte
	// lo stesso update.
	$GLOBALS['wphc_api_update_in_progress'] = true;
	$result                                 = $upgrader->upgrade( $target, array( 'clear_update_cache' => false ) );
	unset( $GLOBALS['wphc_api_update_in_progress'] );

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
	// Solo la cache della lista (get_plugins()/wp_get_themes() rileggono la
	// cartella), MAI wp_clean_plugins_cache( true )/wp_clean_themes_cache( true ):
	// cancellerebbero l'intero transient update_plugins/update_themes, non solo
	// la entry di $target, lasciando "0 aggiornamenti" per TUTTI i plugin/temi
	// finche' il cron non lo ripopola. La entry del solo elemento appena
	// aggiornato viene corretta chirurgicamente sotto, solo in caso di successo
	// (wphc_patch_update_transient_after_success()).
	if ( 'plugin' === $type ) {
		wp_clean_plugins_cache( false );
	} else {
		wp_clean_themes_cache( false );
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

		$log_id = wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, $phase, $message, null, $source );
		wphc_record_last_update( $type, $target, $phase, $source );
		wphc_release_update_lock();

		return array(
			'result' => $phase,
			'detail' => $message,
			'log_id' => $log_id,
			'http'   => 200,
		);
	}

	wphc_patch_update_transient_after_success( $type, $target, $version_to );

	// Rete di sicurezza: un update non dovrebbe mai disattivare un plugin
	// gia' attivo (Plugin_Upgrader::upgrade() non tocca active_plugins), ma
	// puo' succedere per cause esterne (plugin di sicurezza/hosting che
	// disattivano su modifica file, un main file rinominato dalla nuova
	// versione...). Se succede, si tenta una riattivazione nello stesso
	// ambito di prima (rete o singolo sito); se anche questa fallisce, lo
	// si segnala come esito distinto invece di dichiarare un successo pieno.
	$active_after       = null;
	$log_message        = null;
	$reactivation_error = null;
	if ( 'plugin' === $type ) {
		$active_after = $was_network_active
			? is_plugin_active_for_network( $target )
			: is_plugin_active( $target );

		if ( $was_active && ! $active_after ) {
			$reactivated = activate_plugin( $target, '', $was_network_active );
			if ( is_wp_error( $reactivated ) ) {
				$reactivation_error = $reactivated->get_error_message();
				$log_message        = sprintf(
					/* translators: %s: messaggio d'errore restituito da activate_plugin(). */
					__( 'Plugin aggiornato correttamente ma la riattivazione automatica e\' fallita: %s', 'wp-health-check' ),
					$reactivation_error
				);
			} else {
				$active_after = $was_network_active
					? is_plugin_active_for_network( $target )
					: is_plugin_active( $target );
				$log_message  = __( 'Plugin disattivato dall\'update e riattivato automaticamente.', 'wp-health-check' );
			}
		}
	}

	$log_id = wphc_log_update_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'completed', $log_message, $active_after, $source );
	wphc_record_last_update( $type, $target, 'completed', $source );
	wphc_release_update_lock();

	if ( null !== $reactivation_error ) {
		return array(
			'result' => 'reactivation_failed',
			'type'   => $type,
			'target' => $target,
			'name'   => $name,
			'from'   => $version_from,
			'to'     => $version_to,
			'log_id' => $log_id,
			'detail' => $log_message,
			'http'   => 200,
		);
	}

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
 * @param bool   $dry_run True per un controllo senza eseguire l'update (?check=1).
 * @param string $source  Origine della richiesta ('api'|'wp-admin'|'cron'|'wp-cli'), solo
 *                         per l'attribuzione nel log: non altera il comportamento sincrono.
 * @return array Esito normalizzato, vedi wphc_map_item_update_outcome().
 */
function wphc_perform_core_update( $dry_run = false, $source = 'api' ) {
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
	wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, 'requested', null, null, $source );

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Core_Upgrader( $skin );

	// Flag di richiesta: Core_Upgrader non passa da upgrader_pre_install, ma
	// wphc_maybe_log_core_version_change() (gated su init) confronterebbe
	// comunque la versione con wp_health_check_core_version alla prossima
	// richiesta. Aggiornando qui sotto quell'opzione SUBITO dopo l'esito
	// reale (successo o fallimento) si evita quel doppio log a prescindere
	// dal flag; il flag resta qui solo per uniformita' con il ramo
	// plugin/tema sopra, nel caso in cui Core_Upgrader arrivi in futuro ad
	// agganciare hook condivisi.
	$GLOBALS['wphc_api_update_in_progress'] = true;
	$result                                 = $upgrader->upgrade( $update );
	unset( $GLOBALS['wphc_api_update_in_progress'] );

	$actual_version = get_bloginfo( 'version' );

	// Sincronizza SEMPRE l'opzione con la versione reale osservata ora,
	// indipendentemente dall'esito: e' il valore che
	// wphc_maybe_log_core_version_change() confrontera' al prossimo init,
	// cosi' un update gia' loggato qui (successo, fallito o rolled_back) non
	// viene ri-loggato come "divergenza" alla richiesta successiva.
	update_option( 'wp_health_check_core_version', $actual_version );

	if ( is_wp_error( $result ) || $actual_version !== $version_to ) {
		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Verifica post-aggiornamento fallita.', 'wp-health-check' );
		// Vedi nota sul rollback in cima alla funzione: qui la distinzione
		// rolled_back/failed si basa solo sulla versione osservata dopo il
		// tentativo, non su una garanzia esplicita del Core_Upgrader.
		$phase = ( $actual_version === $version_from ) ? 'rolled_back' : 'failed';

		$log_id = wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, $phase, $message, null, $source );
		wphc_record_last_update( 'core', 'core', $phase, $source );
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

	// A differenza di plugin/temi, per il core NON esiste un equivalente
	// "update-checker premium" che in contesto REST non si caricherebbe: il
	// check di versione di WordPress e' identico in ogni contesto. Forzare
	// un ricontrollo reale qui e' quindi sicuro (non riproduce il bug di
	// wp_update_plugins()/wp_update_themes() visto sopra) e ripopola subito
	// il transient update_core, cosi' summary.core_update torna false senza
	// dover aspettare il prossimo giro di cron.
	wp_version_check( array(), true );
	delete_transient( 'wphc_health_cache' );

	$log_id = wphc_log_update_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, 'completed', null, null, $source );
	wphc_record_last_update( 'core', 'core', 'completed', $source );
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
 * Riconciliazione a posteriori: su alcuni siti un plugin puo' restare
 * disattivato dopo un aggiornamento (la rete di sicurezza gia' presente in
 * wphc_perform_item_update() tenta una riattivazione immediata, ma non
 * copre i casi in cui la disattivazione avviene con un ritardo, ad opera di
 * un processo esterno, o quando l'update e' stato eseguito fuori da questo
 * agent). Confronta lo stato "atteso attivo" secondo il log
 * (wphc_get_reactivation_candidates()) con lo stato reale corrente
 * (is_plugin_active()) e, per ogni discrepanza trovata, tenta la
 * riattivazione, registrando SEMPRE una riga di log per il tentativo.
 *
 * In dry-run (?check=1) si salta il preflight (kill-switch/lock): e' una
 * lettura pura, nessuno stato del sito viene toccato.
 *
 * @param bool $dry_run True per elencare le discrepanze senza riattivare.
 * @return array<string, mixed> Esito interno. In caso di preflight fallito,
 *         le sole chiavi 'result' ('disabled'|'locked') e 'http' (403|409);
 *         altrimenti 'result' => 'ok', 'http' => 200, 'discrepancies',
 *         'reactivated', 'failed', 'results' (array di dettagli per elemento).
 */
function wphc_perform_reactivate( $dry_run = false ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$candidates = wphc_get_reactivation_candidates();

	// Discrepanza vera: candidato "atteso attivo" secondo l'ultima riga di
	// log non-null per quel target, ma risulta disattivato ORA.
	// is_plugin_active() e' l'unica fonte di verita' sullo stato reale.
	$discrepancies = array();
	foreach ( $candidates as $candidate ) {
		if ( ! is_plugin_active( $candidate['target'] ) ) {
			$discrepancies[] = $candidate;
		}
	}

	if ( $dry_run ) {
		$results = array();
		foreach ( $discrepancies as $candidate ) {
			$results[] = array(
				'target'           => $candidate['target'],
				'name'             => $candidate['name'],
				'was_active'       => true,
				'currently_active' => false,
			);
		}

		return array(
			'result'        => 'ok',
			'http'          => 200,
			'discrepancies' => count( $results ),
			'reactivated'   => 0,
			'failed'        => 0,
			'results'       => $results,
		);
	}

	$preflight = wphc_reactivate_preflight();
	if ( true !== $preflight ) {
		return $preflight;
	}

	// Nomi/versioni "vivi" da get_plugins(), con fallback al nome gia'
	// registrato nel log se il plugin file nel frattempo e' sparito
	// (disinstallato manualmente fra il log e questa chiamata).
	$all_plugins = get_plugins();

	$results     = array();
	$reactivated = 0;
	$failed      = 0;

	foreach ( $discrepancies as $candidate ) {
		$target       = $candidate['target'];
		$name         = isset( $all_plugins[ $target ] ) ? $all_plugins[ $target ]['Name'] : $candidate['name'];
		$version_from = isset( $all_plugins[ $target ] ) ? $all_plugins[ $target ]['Version'] : null;

		$correlation_id = wphc_generate_correlation_id();
		$activation     = activate_plugin( $target, '', false );
		$now_active     = is_plugin_active( $target );

		if ( is_wp_error( $activation ) || ! $now_active ) {
			$message = is_wp_error( $activation )
				? $activation->get_error_message()
				: __( 'activate_plugin() non ha segnalato errori ma il plugin risulta ancora inattivo.', 'wp-health-check' );

			// active = null, NON false: se si scrivesse false, questa
			// diventerebbe la riga piu' recente non-null per il target e
			// wphc_get_reactivation_candidates() smetterebbe di considerarlo
			// "atteso attivo" alla prossima chiamata, non ritentando piu' la
			// riattivazione. Con null resta valida l'ultima riga active=1
			// precedente: la discrepanza continua a essere rilevata.
			$log_id = wphc_log_update_row( $correlation_id, 'plugin', $target, $name, $version_from, null, 'reactivation_failed', $message, null );

			$results[] = array(
				'target'           => $target,
				'name'             => $name,
				'was_active'       => true,
				'currently_active' => false,
				'reactivated'      => false,
				'log_id'           => $log_id,
				'detail'           => $message,
			);
			++$failed;
			continue;
		}

		$log_id = wphc_log_update_row( $correlation_id, 'plugin', $target, $name, $version_from, null, 'reactivated', null, true );

		$results[] = array(
			'target'           => $target,
			'name'             => $name,
			'was_active'       => true,
			'currently_active' => true,
			'reactivated'      => true,
			'log_id'           => $log_id,
		);
		++$reactivated;
	}

	wphc_release_update_lock();

	return array(
		'result'        => 'ok',
		'http'          => 200,
		'discrepancies' => count( $results ),
		'reactivated'   => $reactivated,
		'failed'        => $failed,
		'results'       => $results,
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

	if ( 'reactivation_failed' === $result ) {
		// Il file e' stato sostituito correttamente (updated: true): a
		// fallire e' stato solo il tentativo di riattivare un plugin che
		// era attivo prima dell'update. Va segnalato, ma non e' lo stesso
		// esito di un update non riuscito.
		return rest_ensure_response(
			array(
				'updated' => true,
				'result'  => 'reactivation_failed',
				'type'    => $outcome['type'],
				'target'  => $outcome['target'],
				'name'    => $outcome['name'],
				'from'    => $outcome['from'],
				'to'      => $outcome['to'],
				'log_id'  => $outcome['log_id'],
				'detail'  => $outcome['detail'],
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
 * Query paginata sulla tabella di log update, con filtro opzionale per tipo
 * e/o origine. Estratta dalla callback REST di GET /update/log perche' ha un
 * secondo consumatore: l'handler AJAX del pulsante "Visualizza log" della
 * tab Site Health (wphc_ajax_view_log()), che legge la stessa tabella senza
 * passare da una richiesta REST autenticata col bearer token (l'utente e'
 * gia' autenticato in wp-admin).
 *
 * @param string $type   Filtro 'type': 'plugin'|'theme'|'core'|'token'|'login', altrimenti ignorato.
 * @param string $source Filtro 'source': 'api'|'wp-admin'|'cron'|'wp-cli', altrimenti ignorato.
 * @param int    $limit  Righe per pagina (1-200, default 50 se fuori range).
 * @param int    $offset Righe da saltare (min 0).
 * @return array{entries: array<int, array<string, mixed>>, total: int}
 */
function wphc_get_update_log_entries( $type, $source, $limit, $offset ) {
	global $wpdb;
	$table = wphc_update_log_table();

	$limit  = $limit > 0 ? min( 200, (int) $limit ) : 50;
	$offset = max( 0, (int) $offset );

	$type_filter   = in_array( $type, array( 'plugin', 'theme', 'core', 'token', 'login' ), true ) ? $type : '';
	$source_filter = in_array( $source, array( 'api', 'wp-admin', 'cron', 'wp-cli' ), true ) ? $source : '';

	// Le condizioni si combinano in AND: entrambe le whitelist sopra
	// garantiscono che solo nomi di colonna fissi finiscano nella query,
	// mai un valore preso direttamente dalla richiesta.
	$where  = array();
	$values = array();
	if ( '' !== $type_filter ) {
		$where[]  = 'type = %s';
		$values[] = $type_filter;
	}
	if ( '' !== $source_filter ) {
		$where[]  = 'source = %s';
		$values[] = $source_filter;
	}
	$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

	if ( $values ) {
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- tabella custom non cacheata dall'object cache di WP; $table/$where_sql sono costruiti da un nome fisso e da una whitelist di nomi colonna, non da input diretto; l'ultimo codice e' un falso positivo dello sniff, che non riconosce i placeholder %s dentro $where_sql (una variabile, non un letterale) ne' $values passato come array (forma supportata da $wpdb->prepare()): il conteggio combacia a runtime.
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabella custom non cacheata dall'object cache di WP.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table e' un nome fisso ($wpdb->prefix), non input; nessun altro dato in questo ramo.
	}

	$query_values = array_merge( $values, array( $limit, $offset ) );
	$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $query_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- tabella custom non cacheata dall'object cache di WP; $table/$where_sql non sono input diretto (vedi nota sopra); l'ultimo codice e' un falso positivo, lo sniff conta "1 parametro" perche' $query_values e' un array passato come forma unica supportata da $wpdb->prepare(), non i suoi elementi: a runtime il numero di placeholder (0-2 di $where_sql + 2 di LIMIT/OFFSET) coincide sempre con count( $query_values ).

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
			// Stato attivo del plugin in quel momento (true/false), o null
			// per temi/core e per righe scritte prima della 1.21.0 (colonna
			// aggiunta con dbDelta, valore NULL sulle righe preesistenti).
			'active'         => isset( $row['active'] ) && null !== $row['active'] ? (bool) $row['active'] : null,
			// 'source'/'actor' sono colonne aggiunte in 1.29.0: sulle righe
			// preesistenti dbDelta() ha gia' valorizzato 'source' con il
			// DEFAULT 'api' dello schema, quindi il fallback qui sotto copre
			// solo l'improbabile riga con la colonna davvero NULL.
			'source'         => isset( $row['source'] ) && '' !== $row['source'] ? $row['source'] : 'api',
			'actor'          => isset( $row['actor'] ) && null !== $row['actor'] ? $row['actor'] : null,
		);
	}

	return array(
		'entries' => $entries,
		'total'   => $total,
	);
}

/**
 * Callback REST di GET /update/log: lettura paginata della tabella di log
 * update. Sola lettura, quindi accessibile anche a kill-switch spento
 * (nessuna chiamata a wphc_update_preflight() qui, deliberatamente). La
 * query vera e propria e' in wphc_get_update_log_entries().
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response
 */
function wphc_route_update_log( WP_REST_Request $request ) {
	wphc_record_access();

	$result = wphc_get_update_log_entries(
		(string) $request->get_param( 'type' ),
		(string) $request->get_param( 'source' ),
		(int) $request->get_param( 'limit' ),
		(int) $request->get_param( 'offset' )
	);

	return rest_ensure_response(
		array(
			'site'    => wphc_normalize_site_url(),
			'count'   => count( $result['entries'] ),
			'total'   => $result['total'],
			'entries' => $result['entries'],
		)
	);
}

// -----------------------------------------------------------------------
// CALLBACK: POST /update/reactivate
// -----------------------------------------------------------------------

/**
 * Callback REST di POST /update/reactivate: riconciliazione a posteriori
 * dello stato attivo dei plugin (vedi wphc_perform_reactivate()). Rispetta
 * il kill-switch e il lock anti-concorrenza come le altre rotte di
 * scrittura, salvo in dry-run (?check=1) che resta pura lettura.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error Esito della riconciliazione.
 */
function wphc_route_reactivate( WP_REST_Request $request ) {
	$dry_run = wphc_request_wants_check( $request );
	$outcome = wphc_perform_reactivate( $dry_run );

	$http = isset( $outcome['http'] ) ? (int) $outcome['http'] : 200;

	if ( 403 === $http ) {
		return new WP_Error( 'wphc_updates_disabled', __( 'Aggiornamenti via API disattivati per questo sito.', 'wp-health-check' ), array( 'status' => 403 ) );
	}
	if ( 409 === $http ) {
		return new WP_Error( 'wphc_update_locked', __( 'Un altro aggiornamento e\' gia\' in corso.', 'wp-health-check' ), array( 'status' => 409 ) );
	}

	return rest_ensure_response(
		array(
			'site'          => wphc_normalize_site_url(),
			'generated_at'  => gmdate( 'c' ),
			'check'         => $dry_run,
			'discrepancies' => $outcome['discrepancies'],
			'reactivated'   => $outcome['reactivated'],
			'failed'        => $outcome['failed'],
			'results'       => $outcome['results'],
		)
	);
}

// -----------------------------------------------------------------------
// AGGIORNAMENTI BULK (plugin/temi, oppure core in job esclusivo) VIA POST /update/bulk + WP-CRON
// -----------------------------------------------------------------------
//
// Modello: la rotta REST autenticata ACCODA un job (mai un update di
// per se'), WP-Cron si limita a SMALTIRE una coda gia' autorizzata,
// elemento per elemento, riusando wphc_perform_item_update() cosi' com'e'
// (nessuna modifica al suo comportamento sincrono per singolo elemento).
// Nessun update parte mai senza un trigger autenticato esplicito - coerente
// con la scelta architetturale che il sito non ha un cron di auto-update
// proprio (vedi README, sezione "Architettura e scopo").
//
// Storage: il documento completo del job vive in un option NON autoloadato
// (puo' arrivare a qualche decina di KB con 100 elementi); un secondo
// option piccolo e fisso, autoloadato, ne tiene il solo riassunto per il
// campo O(1) esposto da /health. Un transient dedicato (DISTINTO dal lock
// di update per singolo elemento, che resta interamente dentro
// wphc_perform_item_update()) impedisce a due tick del drain di
// sovrapporsi.

/**
 * Legge il documento completo del job bulk corrente.
 *
 * @param bool $fresh True per bypassare l'object cache: necessario nel
 *                     drain, dove un processo di lunga durata continuerebbe
 *                     altrimenti a servire una copia in memoria stantia
 *                     dell'option mentre un altro tick la aggiorna.
 * @return array|null Il documento, oppure null se non e' mai stato creato un job.
 */
function wphc_bulk_get_job( $fresh = false ) {
	if ( $fresh ) {
		wp_cache_delete( 'wp_health_check_bulk_job', 'options' );
	}
	$job = get_option( 'wp_health_check_bulk_job' );
	return is_array( $job ) ? $job : null;
}

/**
 * Persiste il documento del job e ne sincronizza il riassunto autoloadato.
 *
 * @param array $job Documento completo del job.
 */
function wphc_bulk_save_job( array $job ) {
	update_option( 'wp_health_check_bulk_job', $job, false );
	wphc_bulk_sync_summary( $job );
}

/**
 * Riassunto piccolo e fisso, autoloadato: l'unico che /health legge (vedi
 * wphc_bulk_summary()), cosi' la rotta resta O(1) anche con un documento
 * job di decine di KB.
 *
 * @param array $job Documento completo del job.
 */
function wphc_bulk_sync_summary( array $job ) {
	update_option(
		'wp_health_check_bulk_status',
		array(
			'job_id'       => $job['job_id'],
			'status'       => $job['status'],
			'abort_reason' => $job['abort_reason'],
			'total'        => $job['counters']['total'],
			'done'         => $job['counters']['done'],
			'updated'      => $job['counters']['updated'],
			'skipped'      => $job['counters']['skipped'],
			'warnings'     => $job['counters']['warnings'],
			'failed'       => $job['counters']['failed'],
			'created_at'   => gmdate( 'c', $job['created_ts'] ),
			'finished_at'  => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
			'next_run_ts'  => $job['next_run_ts'],
		),
		true
	);
}

/**
 * Riassunto del job esposto da /health: un solo get_option() autoloadato piu'
 * un confronto time(), nessuna query - coerente col contratto economico
 * della rotta. 'stalled' e' derivato a lettura (mai spinto): funziona anche
 * su un sito dove il cron non gira piu' per definizione.
 *
 * @return array|null Null se non e' mai stato creato un job.
 */
function wphc_bulk_summary() {
	$status = get_option( 'wp_health_check_bulk_status' );
	if ( ! is_array( $status ) ) {
		return null;
	}

	$active  = in_array( $status['status'], array( 'queued', 'running' ), true );
	$stalled = $active && ( time() > ( (int) $status['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE ) );

	return array(
		'job_id'       => $status['job_id'],
		'status'       => $status['status'],
		'total'        => $status['total'],
		'done'         => $status['done'],
		'updated'      => $status['updated'],
		'skipped'      => $status['skipped'],
		'warnings'     => $status['warnings'],
		'failed'       => $status['failed'],
		'created_at'   => $status['created_at'],
		'finished_at'  => $status['finished_at'],
		'next_run_at'  => gmdate( 'c', (int) $status['next_run_ts'] ),
		'stalled'      => $stalled,
		'abort_reason' => $status['abort_reason'],
	);
}

/**
 * True se il job e' in uno stato attivo (non terminale).
 *
 * @param array $job Documento completo del job.
 * @return bool
 */
function wphc_bulk_is_active( array $job ) {
	return in_array( $job['status'], array( 'queued', 'running' ), true );
}

/**
 * Ricalcola i contatori del job dai singoli elementi. Chiamata dopo ogni
 * modifica agli item, cosi' 'counters' resta sempre coerente con 'items'
 * senza doverli tenere sincronizzati a mano in piu' punti.
 *
 * @param array $job Documento del job, passato per riferimento.
 */
function wphc_bulk_recount( array &$job ) {
	$counters = array(
		'total'    => count( $job['items'] ),
		'done'     => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'warnings' => 0,
		'failed'   => 0,
		'pending'  => 0,
	);

	foreach ( $job['items'] as $item ) {
		if ( 'done' === $item['state'] ) {
			++$counters['done'];
			if ( 'updated' === $item['result'] ) {
				++$counters['updated'];
			} elseif ( 'reactivation_failed' === $item['result'] ) {
				++$counters['warnings'];
			} else {
				++$counters['skipped'];
			}
		} elseif ( 'error' === $item['state'] ) {
			++$counters['done'];
			++$counters['failed'];
		} else {
			++$counters['pending'];
		}
	}

	$job['counters'] = $counters;
}

/**
 * Nuovo elemento del job, stato iniziale 'pending'.
 *
 * @param string $type   'plugin' | 'theme' | 'core'.
 * @param string $target Plugin file, stylesheet, oppure 'core' per il core.
 * @return array
 */
function wphc_bulk_new_item( $type, $target ) {
	return array(
		'type'         => $type,
		'target'       => $target,
		// Solo il core ha un nome noto a priori: gli esiti 'up_to_date',
		// 'not_updatable', 'failed' e 'rolled_back' di plugin/temi non lo
		// restituiscono, quindi qui resterebbe null fino al primo tentativo
		// riuscito. Per il core invece e' sempre 'WordPress', e valorizzarlo
		// subito evita un 'name: null' nel payload del webhook proprio nei
		// casi che servono a diagnosticare un fallimento.
		'name'         => ( 'core' === $type ) ? 'WordPress' : null,
		'state'        => 'pending',
		'result'       => null,
		'attempts'     => 0,
		'deferrals'    => 0,
		'next_after'   => 0,
		'claimed_ts'   => null,
		'from'         => null,
		'to'           => null,
		'log_id'       => null,
		'correlations' => array(),
		'last_error'   => null,
		'finished_ts'  => null,
	);
}

/**
 * Valida, deduplica e cappa gli elementi richiesti da POST /update/bulk.
 * Accetta la forma canonica {"items":[{"type":"plugin","target":"..."}]}
 * e, come zucchero, {"plugins":[...],"themes":[...],"core":true}. MAI un
 * pacchetto, una versione o un URL: stesso vincolo non negoziabile delle
 * rotte di update singole (POST /update/plugin, /update/theme, /update/core).
 *
 * Il core e' un item come gli altri (type='core', target sentinella
 * 'core', sempre lo stesso usato da wphc_perform_core_update() per il log),
 * ma un job non puo' mescolarlo con plugin/temi: e' l'unico item il cui
 * fallimento non ha un rollback nativo affidabile (il core non ha il
 * temp-backup di WP 6.3+), quindi il centro deve poterlo accodare, seguire
 * e valutare da solo prima di mettere mano a qualunque altra cosa sul sito.
 *
 * @param array|null $raw Corpo JSON della richiesta.
 * @return array{items: array, rejected: array, error: string|null} 'error' e'
 *              valorizzato SOLO per il mix core + plugin/temi ('items' e
 *              'rejected' restano vuoti in quel caso: non e' una richiesta
 *              parzialmente valida, va corretta e rimandata).
 */
function wphc_bulk_normalize_items( $raw ) {
	$items      = array();
	$rejected   = array();
	$seen       = array();
	$candidates = array();

	if ( is_array( $raw ) ) {
		if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			foreach ( $raw['items'] as $entry ) {
				// Il core non ha un target scelto dal chiamante: 'target' e'
				// facoltativo solo per questo type, normalizzato piu' sotto.
				if ( is_array( $entry ) && isset( $entry['type'] )
					&& ( isset( $entry['target'] ) || 'core' === $entry['type'] ) ) {
					$candidates[] = array(
						'type'   => (string) $entry['type'],
						'target' => isset( $entry['target'] ) ? (string) $entry['target'] : '',
					);
				}
			}
		}
		if ( isset( $raw['plugins'] ) && is_array( $raw['plugins'] ) ) {
			foreach ( $raw['plugins'] as $target ) {
				$candidates[] = array(
					'type'   => 'plugin',
					'target' => (string) $target,
				);
			}
		}
		if ( isset( $raw['themes'] ) && is_array( $raw['themes'] ) ) {
			foreach ( $raw['themes'] as $target ) {
				$candidates[] = array(
					'type'   => 'theme',
					'target' => (string) $target,
				);
			}
		}
		if ( ! empty( $raw['core'] ) ) {
			$candidates[] = array(
				'type'   => 'core',
				'target' => 'core',
			);
		}
	}

	// Esclusivita': un job core non puo' contenere altro. Controllato PRIMA
	// della validazione dei singoli elementi, sul set intero della richiesta:
	// e' una proprieta' della combinazione, non del singolo item.
	$has_core  = false;
	$has_other = false;
	foreach ( $candidates as $candidate ) {
		if ( 'core' === $candidate['type'] ) {
			$has_core = true;
		} else {
			$has_other = true;
		}
	}
	if ( $has_core && $has_other ) {
		return array(
			'items'    => array(),
			'rejected' => array(),
			'error'    => 'core_must_be_exclusive',
		);
	}

	foreach ( $candidates as $candidate ) {
		$type   = $candidate['type'];
		$target = trim( $candidate['target'] );

		if ( 'core' === $type && '' === $target ) {
			$target = 'core'; // Sentinella: il core non ha un target scelto dal chiamante.
		}

		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			$rejected[] = array(
				'type'   => $type,
				'target' => $target,
				'reason' => 'invalid_type',
			);
			continue;
		}

		// Validazione difensiva prima di toccare get_plugins()/wp_get_theme():
		// un plugin file e' "cartella/file.php" o "file.php" alla radice, uno
		// stylesheet e' una singola cartella, il core e' sempre e solo la
		// sentinella 'core'. Nessun ".." in nessuno dei tre.
		if ( 'plugin' === $type ) {
			$pattern = '/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/';
		} elseif ( 'theme' === $type ) {
			$pattern = '/^[A-Za-z0-9._-]+$/';
		} else {
			$pattern = '/^core$/';
		}

		if ( '' === $target || false !== strpos( $target, '..' ) || ! preg_match( $pattern, $target ) ) {
			$rejected[] = array(
				'type'   => $type,
				'target' => $target,
				'reason' => 'invalid_target',
			);
			continue;
		}

		$key = $type . '|' . $target;
		if ( isset( $seen[ $key ] ) ) {
			continue; // Duplicato silenzioso: non e' un errore del chiamante.
		}
		$seen[ $key ] = true;

		if ( count( $items ) >= WP_HEALTH_CHECK_BULK_MAX_ITEMS ) {
			$rejected[] = array(
				'type'   => $type,
				'target' => $target,
				'reason' => 'max_items_exceeded',
			);
			continue;
		}

		$items[] = wphc_bulk_new_item( $type, $target );
	}

	return array(
		'items'    => $items,
		'rejected' => $rejected,
		'error'    => null,
	);
}

/**
 * Acquisisce il mutex di drain. DISTINTO dal lock di update per singolo
 * elemento (interamente dentro wphc_perform_item_update()): serve solo a
 * impedire che due tick dello stesso hook (WP-Cron pseudo-cron + un
 * eventuale cron di sistema + spawn_cron() da una visita) processino in
 * sovrapposizione lo stesso job. Il worst case di una race residua (la
 * finestra get/set_transient non e' atomica) degrada a un tentativo
 * duplicato che rilegge il transient core update_plugins/update_themes e
 * trova up_to_date - mai a corruzione.
 *
 * @return bool True se acquisito.
 */
function wphc_bulk_acquire_lock() {
	if ( false !== get_transient( 'wp_health_check_bulk_lock' ) ) {
		return false;
	}
	set_transient( 'wp_health_check_bulk_lock', 1, WP_HEALTH_CHECK_BULK_LOCK_TTL );
	register_shutdown_function( 'wphc_bulk_release_lock' );
	return true;
}

/**
 * Rilascia il mutex di drain. Registrata anche come shutdown function: cosi'
 * si libera comunque anche se PHP muore a meta' di un tick.
 */
function wphc_bulk_release_lock() {
	delete_transient( 'wp_health_check_bulk_lock' );
}

/**
 * Schedula il prossimo tick del drain con un singolo evento (MAI un evento
 * ricorrente): un singolo evento orfano si autodistrugge al primo firing
 * anche se il mu-plugin viene rimosso, mentre un evento ricorrente
 * resterebbe per sempre nell'option 'cron' (un mu-plugin non ha un hook di
 * disattivazione che potrebbe altrimenti ripulirlo).
 *
 * @param int|null $delay Secondi da ora. Default WP_HEALTH_CHECK_BULK_TICK_GAP.
 * @return int Timestamp Unix schedulato.
 */
function wphc_bulk_schedule_tick( $delay = null ) {
	if ( null === $delay ) {
		$delay = WP_HEALTH_CHECK_BULK_TICK_GAP;
	}
	$when = time() + max( 0, (int) $delay );
	if ( false === wp_next_scheduled( 'wphc_bulk_update_tick' ) ) {
		wp_schedule_single_event( $when, 'wphc_bulk_update_tick' );
	}
	return $when;
}

/** Cancella qualunque tick pianificato: chiamata quando il job termina. */
function wphc_bulk_unschedule_ticks() {
	wp_clear_scheduled_hook( 'wphc_bulk_update_tick' );
}

/**
 * Calcola il prossimo tick: al piu' presto WP_HEALTH_CHECK_BULK_TICK_GAP
 * secondi, ma non prima del backoff piu' vicino tra gli elementi ancora
 * pendenti (cosi' un elemento in attesa di un lungo backoff non causa tick
 * inutili nel frattempo).
 *
 * @param array $job Documento del job.
 * @return int Secondi da ora.
 */
function wphc_bulk_next_delay( array $job ) {
	$now      = time();
	$earliest = null;
	foreach ( $job['items'] as $item ) {
		if ( 'pending' === $item['state'] ) {
			$earliest = ( null === $earliest ) ? $item['next_after'] : min( $earliest, $item['next_after'] );
		}
	}
	if ( null === $earliest ) {
		return WP_HEALTH_CHECK_BULK_TICK_GAP;
	}
	return max( WP_HEALTH_CHECK_BULK_TICK_GAP, $earliest - $now );
}

/**
 * Ri-armo pigro: se un job e' attivo ma non c'e' alcun tick schedulato ed e'
 * gia' passato il momento previsto, ri-schedula e sveglia il cron. Recupera
 * un evento cancellato da un altro plugin o da un riavvio, senza violare la
 * dottrina "nessun update non richiesto": il job era gia' stato autorizzato
 * da una chiamata REST autenticata, cron/visite si limitano a farlo
 * avanzare. Costo sui siti idle (la stragrande maggioranza): una sola
 * lettura di option gia' autoloadata.
 */
function wphc_bulk_maybe_rearm() {
	$status = get_option( 'wp_health_check_bulk_status' );
	if ( ! is_array( $status ) || ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
		return;
	}
	if ( false !== wp_next_scheduled( 'wphc_bulk_update_tick' ) ) {
		return;
	}
	if ( time() < (int) $status['next_run_ts'] ) {
		return;
	}
	if ( false !== get_transient( 'wphc_bulk_rearm_lock' ) ) {
		return;
	}
	set_transient( 'wphc_bulk_rearm_lock', 1, MINUTE_IN_SECONDS );

	wphc_bulk_schedule_tick( 0 );
	spawn_cron();
}
add_action( 'init', 'wphc_bulk_maybe_rearm' );

/**
 * Trova l'indice del prossimo elemento pronto per un tentativo (pending e
 * backoff scaduto), oppure null se nessuno e' pronto ora.
 *
 * @param array $job Documento del job.
 * @param int   $now Timestamp Unix corrente.
 * @return int|null
 */
function wphc_bulk_next_item_index( array $job, $now ) {
	foreach ( $job['items'] as $index => $item ) {
		if ( 'pending' === $item['state'] && $item['next_after'] <= $now ) {
			return $index;
		}
	}
	return null;
}

/**
 * Classifica un $result di wphc_perform_item_update() in una delle
 * categorie di trattamento del drain. Tre dei valori possibili sono
 * verdetti sull'INTERO SITO (kill-switch, versione WP, filesystem), non
 * sul singolo elemento: per questi l'intero job va abortito, ripetere
 * l'elemento 100 volte non servirebbe a nulla.
 *
 * @param string $result Valore del campo 'result' restituito dall'esito.
 * @return string 'success'|'noop'|'warning'|'retry'|'defer'|'job_fatal'.
 */
function wphc_bulk_classify_result( $result ) {
	$map = array(
		'updated'                => 'success',
		'up_to_date'             => 'noop',
		'not_found'              => 'noop',
		'not_updatable'          => 'noop',
		'reactivation_failed'    => 'warning',
		'failed'                 => 'retry',
		'rolled_back'            => 'retry',
		'locked'                 => 'defer',
		'disabled'               => 'job_fatal',
		'unsupported_wp_version' => 'job_fatal',
		'fs_method_unavailable'  => 'job_fatal',
	);

	return isset( $map[ $result ] ) ? $map[ $result ] : 'retry';
}

/**
 * Tempo di backoff (secondi) dopo il tentativo numero $attempts.
 *
 * @param int $attempts Numero di tentativi gia' effettuati.
 * @return int
 */
function wphc_bulk_backoff_for_attempt( $attempts ) {
	$table = WP_HEALTH_CHECK_BULK_BACKOFF;
	$index = max( 0, min( (int) $attempts, count( $table ) - 1 ) );
	return (int) $table[ $index ];
}

/**
 * Applica l'esito di un tentativo all'elemento e ricalcola i contatori.
 * Puo' abortire l'intero job (vedi wphc_bulk_classify_result()).
 *
 * @param array $job     Documento del job, passato per riferimento.
 * @param int   $index   Indice dell'elemento in $job['items'].
 * @param array $outcome Esito grezzo di wphc_perform_item_update().
 */
function wphc_bulk_apply_outcome( array &$job, $index, array $outcome ) {
	$item   = &$job['items'][ $index ];
	$result = isset( $outcome['result'] ) ? $outcome['result'] : 'failed';
	$class  = wphc_bulk_classify_result( $result );

	$item['result'] = $result;
	if ( isset( $outcome['name'] ) ) {
		$item['name'] = $outcome['name'];
	}
	// 'updated'/'reactivation_failed' popolano 'from'/'to' (versione reale
	// prima/dopo); i risultati che non scrivono nulla (up_to_date,
	// not_updatable...) popolano invece 'current'/'latest' — vedi
	// wphc_perform_item_update(). Senza questo doppio controllo gli item
	// riusciti del job restavano con from/to sempre null.
	if ( isset( $outcome['from'] ) ) {
		$item['from'] = $outcome['from'];
	} elseif ( isset( $outcome['current'] ) ) {
		$item['from'] = $outcome['current'];
	}
	if ( isset( $outcome['to'] ) ) {
		$item['to'] = $outcome['to'];
	} elseif ( isset( $outcome['latest'] ) ) {
		$item['to'] = $outcome['latest'];
	}
	if ( isset( $outcome['log_id'] ) && $outcome['log_id'] ) {
		$item['log_id']         = $outcome['log_id'];
		$item['correlations'][] = $outcome['log_id'];
	}
	if ( isset( $outcome['detail'] ) ) {
		$item['last_error'] = mb_substr( (string) $outcome['detail'], 0, 200 );
	}

	switch ( $class ) {
		case 'success':
		case 'noop':
		case 'warning':
			$item['state']       = 'done';
			$item['finished_ts'] = time();
			break;

		case 'retry':
			++$item['attempts'];
			if ( $item['attempts'] >= WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS ) {
				$item['state']       = 'error';
				$item['finished_ts'] = time();
			} else {
				$item['state']      = 'pending';
				$item['next_after'] = time() + wphc_bulk_backoff_for_attempt( $item['attempts'] );
			}
			break;

		case 'defer':
			// Contesa di lock con un update singolo concorrente: non consuma
			// un tentativo, altrimenti un sito occupato brucerebbe tutti i
			// retry su un elemento mai davvero provato.
			++$item['deferrals'];
			if ( $item['deferrals'] >= WP_HEALTH_CHECK_BULK_MAX_DEFERRALS ) {
				$item['state']       = 'error';
				$item['result']      = 'failed';
				$item['last_error']  = 'lock contention';
				$item['finished_ts'] = time();
			} else {
				$item['state']      = 'pending';
				$item['next_after'] = time() + WP_HEALTH_CHECK_BULK_TICK_GAP;
			}
			break;

		case 'job_fatal':
			unset( $item );
			wphc_bulk_abort_job( $job, $result );
			return;
	}

	unset( $item );
	wphc_bulk_recount( $job );
}

/**
 * Aborta l'intero job: gli elementi ancora pendenti/in corso diventano
 * 'error' con un motivo esplicito, cosi' il report finale (e il webhook)
 * non li mostra come "in sospeso" per sempre.
 *
 * @param array  $job    Documento del job, passato per riferimento.
 * @param string $reason Motivo macchina (es. 'disabled', 'job_timeout').
 */
function wphc_bulk_abort_job( array &$job, $reason ) {
	$job['status']       = 'aborted';
	$job['abort_reason'] = $reason;
	$job['finished_ts']  = time();

	foreach ( $job['items'] as &$item ) {
		if ( in_array( $item['state'], array( 'pending', 'running' ), true ) ) {
			$item['state']       = 'error';
			$item['last_error']  = 'job aborted: ' . $reason;
			$item['finished_ts'] = time();
		}
	}
	unset( $item );

	wphc_bulk_recount( $job );
}

/**
 * Rileva elementi 'running' orfani (claimed_ts piu' vecchio del timeout):
 * un crash PHP a meta' di un tentativo lascia lo stato incoerente
 * altrimenti per sempre. L'evidenza corroborante esiste gia' nella tabella
 * di log: una riga 'requested' senza la corrispondente riga terminale e'
 * esattamente cio' che il pattern a due righe e' pensato per rivelare.
 *
 * @param array $job Documento del job, passato per riferimento.
 * @param int   $now Timestamp Unix corrente.
 * @return bool True se almeno un elemento e' stato "riparato".
 */
function wphc_bulk_reap_stuck_items( array &$job, $now ) {
	$reaped = false;

	foreach ( $job['items'] as &$item ) {
		if ( 'running' !== $item['state'] || null === $item['claimed_ts'] ) {
			continue;
		}
		// Il core ha una soglia piu' larga (vedi WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT):
		// un download di pacchetto completo + migliaia di file + wp_upgrade()
		// supera facilmente i 600s usati per plugin/temi.
		$timeout = ( 'core' === $item['type'] ) ? WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT : WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT;
		if ( ( $now - $item['claimed_ts'] ) <= $timeout ) {
			continue;
		}

		++$item['attempts'];
		$item['last_error'] = 'interrupted (timeout/fatal)';
		if ( $item['attempts'] >= WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS ) {
			$item['state']       = 'error';
			$item['result']      = 'failed';
			$item['finished_ts'] = $now;
		} else {
			$item['state']      = 'pending';
			$item['next_after'] = $now + wphc_bulk_backoff_for_attempt( $item['attempts'] );
		}
		$reaped = true;
	}
	unset( $item );

	if ( $reaped ) {
		wphc_bulk_recount( $job );
	}

	return $reaped;
}

/**
 * Scadenza dura del job (WP_HEALTH_CHECK_BULK_JOB_TTL dalla creazione):
 * finalizza come 'aborted' se superata. Chiamata sia a inizio tick sia da
 * GET /update/bulk, cosi' anche un sito col cron morto viene finalizzato
 * dal solo polling del centro.
 *
 * @param array $job Documento del job, passato per riferimento.
 * @return bool True se il job e' stato abortito per timeout in questa chiamata.
 */
function wphc_bulk_maybe_reap( array &$job ) {
	if ( ! wphc_bulk_is_active( $job ) ) {
		return false;
	}
	if ( ( time() - $job['created_ts'] ) < WP_HEALTH_CHECK_BULK_JOB_TTL ) {
		return false;
	}

	wphc_bulk_abort_job( $job, 'job_timeout' );
	return true;
}

/**
 * Determina se il job e' completo (nessun elemento ancora pendente) e, se
 * si', ne fissa lo stato terminale. Restituisce true SOLO al momento della
 * transizione, cosi' wphc_bulk_run_tick() invia il webhook esattamente una
 * volta.
 *
 * @param array $job Documento del job, passato per riferimento.
 * @return bool
 */
function wphc_bulk_maybe_finalize( array &$job ) {
	if ( 'aborted' === $job['status'] ) {
		return true; // Gia' finalizzato da wphc_bulk_abort_job().
	}
	if ( $job['counters']['pending'] > 0 ) {
		return false;
	}

	$job['status']      = ( $job['counters']['failed'] > 0 ) ? 'completed_with_errors' : 'completed';
	$job['finished_ts'] = time();

	return true;
}

/**
 * Finalizza il job: cancella lo scheduling, persiste, invia il webhook
 * (se non gia' inviato per questo job) e ripersiste col relativo esito.
 *
 * @param array $job Documento del job, passato per riferimento.
 */
function wphc_bulk_finish( array &$job ) {
	wphc_bulk_unschedule_ticks();
	wphc_bulk_save_job( $job );

	if ( empty( $job['webhook']['sent'] ) ) {
		wphc_bulk_send_webhook_for_job( $job );
		wphc_bulk_save_job( $job );
	}
}

/**
 * Il tick del drain: hook di WP-Cron. Acquisisce il mutex di drain (MAI il
 * lock di update per singolo elemento, gia' gestito internamente da
 * wphc_perform_item_update() su ogni chiamata sequenziale), processa fino a
 * WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK elementi entro
 * WP_HEALTH_CHECK_BULK_TIME_BUDGET secondi (controllato PRIMA di ogni
 * elemento, mai a meta', sempre almeno un elemento per tick), poi finalizza
 * o ri-schedula.
 */
function wphc_bulk_run_tick() {
	if ( ! wphc_bulk_acquire_lock() ) {
		return;
	}

	$job = wphc_bulk_get_job( true );
	if ( null === $job || ! wphc_bulk_is_active( $job ) ) {
		wphc_bulk_release_lock();
		return;
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- il budget di tempo sopra e' il vero governatore, non il limite PHP.
	}
	wphc_clear_stale_maintenance();

	$job['status'] = 'running';
	$job['ticks']  = isset( $job['ticks'] ) ? $job['ticks'] + 1 : 1;
	if ( null === $job['started_ts'] ) {
		$job['started_ts'] = time();
	}

	wphc_bulk_reap_stuck_items( $job, time() );

	if ( wphc_bulk_maybe_reap( $job ) ) {
		wphc_bulk_finish( $job );
		wphc_bulk_release_lock();
		return;
	}

	wphc_bulk_save_job( $job );

	$start = microtime( true );
	$done  = 0;
	while ( $done < WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK ) {
		if ( $done > 0 && ( microtime( true ) - $start ) > WP_HEALTH_CHECK_BULK_TIME_BUDGET ) {
			break;
		}

		$job   = wphc_bulk_get_job( true );
		$index = wphc_bulk_next_item_index( $job, time() );
		if ( null === $index ) {
			break;
		}

		$item_type   = $job['items'][ $index ]['type'];
		$item_target = $job['items'][ $index ]['target'];

		$job['items'][ $index ]['state']      = 'running';
		$job['items'][ $index ]['claimed_ts'] = time();
		wphc_bulk_save_job( $job );

		$outcome = ( 'core' === $item_type )
			? wphc_perform_core_update( false, 'cron' )
			: wphc_perform_item_update( $item_type, $item_target, false, 'cron' );

		$job = wphc_bulk_get_job( true );
		wphc_bulk_apply_outcome( $job, $index, $outcome );
		wphc_bulk_save_job( $job );

		if ( 'aborted' === $job['status'] ) {
			break;
		}

		++$done;

		if ( 'core' === $item_type ) {
			// Core_Upgrader ha appena sostituito centinaia di file sotto i
			// piedi di questo stesso processo PHP: classi e funzioni gia'
			// caricate in memoria possono non corrispondere piu' ai file su
			// disco. Si chiude il tick qui, il prossimo riparte da un
			// processo nuovo. Con l'esclusivita' del job core (vedi
			// wphc_bulk_normalize_items()) e' di fatto un no-op, ma rende
			// esplicito l'invariante anche se in futuro cambiasse.
			break;
		}
	}

	if ( wphc_bulk_maybe_finalize( $job ) ) {
		wphc_bulk_finish( $job );
	} else {
		$job['next_run_ts'] = wphc_bulk_schedule_tick( wphc_bulk_next_delay( $job ) );
		wphc_bulk_save_job( $job );
	}

	delete_transient( 'wphc_health_cache' );
	wphc_bulk_release_lock();
}
add_action( 'wphc_bulk_update_tick', 'wphc_bulk_run_tick' );

/**
 * Vero solo se il body della richiesta menziona il core e nient'altro.
 * Euristica DELIBERATAMENTE conservativa sul body grezzo, non una
 * validazione: in ogni caso ambiguo ritorna false, cosi' il gate WP 6.3
 * resta applicato. L'autorita' sul contenuto del job resta
 * wphc_bulk_normalize_items(): un body che sostiene di essere core-only ma
 * e' misto passa comunque questo controllo (non serve il gate 6.3 per un
 * core update), ma viene rifiutato con 400 subito dopo dalla normalizzazione.
 *
 * @param array $body Corpo JSON gia' decodificato della richiesta.
 * @return bool
 */
function wphc_bulk_body_is_core_only( array $body ) {
	$core  = ! empty( $body['core'] );
	$other = ( ! empty( $body['plugins'] ) || ! empty( $body['themes'] ) );

	if ( isset( $body['items'] ) ) {
		if ( ! is_array( $body['items'] ) ) {
			return false;
		}
		foreach ( $body['items'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['type'] ) && 'core' === $entry['type'] ) {
				$core = true;
			} else {
				$other = true;
			}
		}
	}

	return $core && ! $other;
}

/**
 * Preambolo per POST /update/bulk: accesso, kill-switch, requisito versione
 * WP, filesystem 'direct'. DELIBERATAMENTE senza il lock di update: l'ENQUEUE
 * non tocca alcun file, e un lock momentaneo di un altro update in corso non
 * deve far fallire la sola messa in coda.
 *
 * @param bool $requires_wp63 False per un job core-only: il core non usa il
 *                             temp-backup nativo (vedi wphc_perform_core_update()),
 *                             quindi il requisito WP 6.3 non gli si applica,
 *                             esattamente come per POST /update/core.
 * @return true|array True se si puo' procedere, altrimenti array-esito con
 *                     chiavi 'result' e 'http'.
 */
function wphc_bulk_enqueue_preflight( $requires_wp63 = true ) {
	wphc_record_access();

	if ( ! get_option( 'wp_health_check_updates_enabled', true ) ) {
		return array(
			'result' => 'disabled',
			'http'   => 403,
		);
	}

	if ( $requires_wp63 && version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
		return array(
			'result' => 'unsupported_wp_version',
			'http'   => 200,
		);
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( 'direct' !== get_filesystem_method() ) {
		return array(
			'result' => 'fs_method_unavailable',
			'http'   => 200,
		);
	}

	return true;
}

// -----------------------------------------------------------------------
// CALLBACK: POST|GET /update/bulk, POST /update/bulk/cancel
// -----------------------------------------------------------------------

/**
 * POST /update/bulk: accoda un job di aggiornamento bulk (plugin/temi, oppure
 * il solo core: i due non si possono mescolare, vedi wphc_bulk_normalize_items()).
 * Il body indica SOLO quali elementi aggiornare, mai un pacchetto/versione/URL
 * - stesso vincolo non negoziabile delle rotte di update singole.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response|WP_Error
 */
function wphc_route_update_bulk_enqueue( WP_REST_Request $request ) {
	// Letto prima del preflight (pura decodifica, nessun effetto collaterale)
	// solo per determinare se il requisito WP 6.3 si applica: un job core-only
	// non lo richiede, esattamente come POST /update/core.
	$body      = (array) $request->get_json_params();
	$preflight = wphc_bulk_enqueue_preflight( ! wphc_bulk_body_is_core_only( $body ) );
	if ( true !== $preflight ) {
		if ( 403 === (int) $preflight['http'] ) {
			return new WP_Error( 'wphc_updates_disabled', __( 'Aggiornamenti via API disattivati per questo sito.', 'wp-health-check' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response(
			array(
				'accepted' => false,
				'result'   => $preflight['result'],
			)
		);
	}

	$force       = ! empty( $body['force'] );
	$current_job = wphc_bulk_get_job( true );

	if ( null !== $current_job && wphc_bulk_is_active( $current_job ) ) {
		$summary = wphc_bulk_summary();
		$stalled = is_array( $summary ) && ! empty( $summary['stalled'] );

		if ( ! $force || ! $stalled ) {
			return new WP_Error(
				'wphc_bulk_job_in_progress',
				__( 'Un job di aggiornamento bulk e\' gia\' in corso.', 'wp-health-check' ),
				array(
					'status'      => 409,
					'job_id'      => $current_job['job_id'],
					'job_status'  => $current_job['status'],
					'total'       => $current_job['counters']['total'],
					'done'        => $current_job['counters']['done'],
					'stalled'     => $stalled,
					'next_run_at' => gmdate( 'c', (int) $current_job['next_run_ts'] ),
				)
			);
		}

		// force=true su un job stallato: lo abortiamo esplicitamente prima di
		// accodarne uno nuovo, cosi' non resta mai un documento 'running'
		// orfano mentre un secondo job procede in parallelo.
		wphc_bulk_abort_job( $current_job, 'replaced_by_new_job' );
		wphc_bulk_finish( $current_job );
	}

	$normalized = wphc_bulk_normalize_items( $body );
	if ( ! empty( $normalized['error'] ) ) {
		return new WP_Error(
			'wphc_bulk_core_not_exclusive',
			__( 'Un job che include il core WordPress non puo\' contenere anche plugin o temi: accoda due job separati, in sequenza.', 'wp-health-check' ),
			array(
				'status' => 400,
				'reason' => $normalized['error'],
			)
		);
	}
	if ( empty( $normalized['items'] ) ) {
		return new WP_Error( 'wphc_bulk_no_valid_items', __( 'Nessun elemento valido da aggiornare.', 'wp-health-check' ), array( 'status' => 400 ) );
	}

	$now = time();
	$job = array(
		'job_id'        => wphc_generate_correlation_id(),
		'status'        => 'queued',
		'abort_reason'  => null,
		'created_ts'    => $now,
		'started_ts'    => null,
		'finished_ts'   => null,
		'next_run_ts'   => $now,
		'ticks'         => 0,
		'source'        => wphc_detect_update_source(),
		'requested_ip'  => wphc_get_client_ip(),
		'agent_version' => WP_HEALTH_CHECK_VERSION,
		'dry_run'       => false,
		'items'         => $normalized['items'],
		'counters'      => array(),
		'webhook'       => array(
			'sent'       => false,
			'attempts'   => 0,
			'sending_ts' => null,
			'sent_ts'    => null,
			'code'       => null,
			'last_error' => null,
		),
	);
	wphc_bulk_recount( $job );
	wphc_bulk_save_job( $job );

	wphc_bulk_schedule_tick( 0 );
	spawn_cron();

	return new WP_REST_Response(
		array(
			'accepted'      => true,
			'job_id'        => $job['job_id'],
			'status'        => $job['status'],
			'total'         => $job['counters']['total'],
			'rejected'      => $normalized['rejected'],
			'scheduled_at'  => gmdate( 'c', $now ),
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'poll'          => array(
				'job' => rest_url( 'health-check/v1/update/bulk' ),
				'log' => rest_url( 'health-check/v1/update/log' ) . '?source=cron',
			),
		),
		202
	);
}

/**
 * GET /update/bulk: legge il documento del job da un singolo option non
 * autoloadato - piu' economico di una query sulla tabella di log, e l'unico
 * modo di conoscere elementi ancora pendenti (che non hanno righe di log).
 * Chiama anche wphc_bulk_maybe_reap(): il polling da centro innesca da solo
 * la finalizzazione di un job stallato, anche su un sito col cron morto.
 *
 * @param WP_REST_Request $request Richiesta REST corrente.
 * @return WP_REST_Response
 */
function wphc_route_update_bulk_status( WP_REST_Request $request ) {
	wphc_record_access();

	$job = wphc_bulk_get_job( true );
	if ( null === $job ) {
		return rest_ensure_response(
			array(
				'site' => wphc_normalize_site_url(),
				'job'  => null,
			)
		);
	}

	if ( wphc_bulk_maybe_reap( $job ) ) {
		wphc_bulk_finish( $job );
	}

	$show_items = '0' !== (string) $request->get_param( 'items' );

	$payload = array(
		'job_id'       => $job['job_id'],
		'status'       => $job['status'],
		'abort_reason' => $job['abort_reason'],
		'created_at'   => gmdate( 'c', $job['created_ts'] ),
		'started_at'   => $job['started_ts'] ? gmdate( 'c', $job['started_ts'] ) : null,
		'finished_at'  => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
		'next_run_at'  => gmdate( 'c', (int) $job['next_run_ts'] ),
		'stalled'      => wphc_bulk_is_active( $job ) && ( time() > ( (int) $job['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE ) ),
		'counters'     => $job['counters'],
		'webhook'      => $job['webhook'],
	);
	if ( $show_items ) {
		$payload['items'] = $job['items'];
	}

	return rest_ensure_response(
		array(
			'site'         => wphc_normalize_site_url(),
			'generated_at' => gmdate( 'c' ),
			'job'          => $payload,
		)
	);
}

/**
 * POST /update/bulk/cancel: interrompe un job attivo tra un elemento e
 * l'altro (un elemento gia' dentro Plugin_Upgrader::upgrade() completa
 * comunque). Il webhook viene inviato anche in questo caso, col report
 * parziale.
 *
 * @return WP_REST_Response|WP_Error
 */
function wphc_route_update_bulk_cancel() {
	$job = wphc_bulk_get_job( true );
	if ( null === $job || ! wphc_bulk_is_active( $job ) ) {
		return new WP_Error( 'wphc_bulk_no_active_job', __( 'Nessun job di aggiornamento bulk in corso.', 'wp-health-check' ), array( 'status' => 404 ) );
	}

	$job['status']      = 'cancelled';
	$job['finished_ts'] = time();
	foreach ( $job['items'] as &$item ) {
		if ( in_array( $item['state'], array( 'pending', 'running' ), true ) ) {
			$item['state']       = 'error';
			$item['last_error']  = 'cancelled';
			$item['finished_ts'] = time();
		}
	}
	unset( $item );
	wphc_bulk_recount( $job );
	wphc_bulk_finish( $job );

	return rest_ensure_response(
		array(
			'site'   => wphc_normalize_site_url(),
			'job_id' => $job['job_id'],
			'status' => $job['status'],
		)
	);
}

// -----------------------------------------------------------------------
// WEBHOOK DI NOTIFICA FIRMATO (a fine job bulk, o su richiesta di prova)
// -----------------------------------------------------------------------
//
// Firma HMAC-SHA256 speculare (in uscita) allo schema di verifica del
// protocollo v2 in ingresso (wphc_verify_request_signature()): stessa
// primitiva, stesso segreto di sito, cosi' il ricevente puo' riusare la
// stessa logica di verifica gia' scritta per l'ingresso.

/**
 * URL del webhook configurato, o stringa vuota se il feature e' spento.
 * Se il sito non ha impostato un URL proprio, ricade sul default di flotta
 * (WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL) a meno che l'operatore non abbia
 * spuntato esplicitamente "disattiva webhook" in wp-admin: un campo vuoto,
 * da solo, non basta piu' a significare "spento" ora che esiste un default.
 *
 * @return string
 */
function wphc_webhook_url() {
	$url = (string) get_option( 'wp_health_check_webhook_url', '' );
	if ( '' !== $url ) {
		return $url;
	}
	if ( get_option( 'wp_health_check_webhook_disabled', false ) ) {
		return '';
	}
	return WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL;
}

/**
 * Guardia di salvataggio (difesa in profondita', non un confine di
 * sicurezza assoluto: nessun re-check DNS all'invio, TOCTOU-vulnerabile e
 * non affidabile su DNS split-horizon legittimo). wp_http_validate_url() e'
 * lo stesso validatore che l'HTTP API di WP applica gia' alle richieste
 * sicure; lo scheme https e' verificato a parte perche' quel validatore da
 * solo accetta anche http; se l'host e' un IP letterale deve essere
 * pubblico (wphc_ip_is_public(), gia' esistente - riusata, non riscritta).
 *
 * @param string $url URL da validare.
 * @return bool
 */
function wphc_webhook_url_is_allowed( $url ) {
	if ( ! wp_http_validate_url( $url ) ) {
		return false;
	}
	if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
		return false;
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! wphc_ip_is_public( $host ) ) {
		return false;
	}
	return true;
}

/**
 * Costruisce la firma HMAC-SHA256 di una POST in uscita. La stringa firmata
 * usa $event al posto di un path di rotta (che non esiste per un POST in
 * uscita): fornisce comunque separazione di dominio, mentre l'identita' del
 * sito viaggia dentro il body firmato ('site'), non in uno slot separato.
 *
 * @param string $event     Nome evento (es. 'wphc.bulk_update.completed').
 * @param string $body_json Corpo JSON gia' codificato UNA sola volta:
 *                           l'hash e l'invio devono usare esattamente la
 *                           stessa stringa, mai una ri-codifica separata.
 * @param string $secret    Segreto di sito (wp_health_check_token).
 * @return array{timestamp:int,nonce:string,signature:string}
 */
function wphc_build_outbound_signature( $event, $body_json, $secret ) {
	$timestamp = time();
	$nonce     = bin2hex( random_bytes( 16 ) );
	$canonical = "POST\n{$event}\n" . hash( 'sha256', $body_json ) . "\n{$timestamp}\n{$nonce}";
	$signature = wphc_base64url_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );

	return array(
		'timestamp' => $timestamp,
		'nonce'     => $nonce,
		'signature' => $signature,
	);
}

/**
 * Backoff (secondi) per il tentativo di webhook numero $attempt.
 *
 * @param int $attempt Numero di tentativo (1-based).
 * @return int
 */
function wphc_webhook_backoff_for_attempt( $attempt ) {
	$table = WP_HEALTH_CHECK_WEBHOOK_BACKOFF;
	$index = max( 0, min( (int) $attempt, count( $table ) - 1 ) );
	return (int) $table[ $index ];
}

/**
 * Registra l'ultimo esito di consegna del webhook, stesso pattern di
 * wp_health_check_thumb_error: array con 'at'/codice macchina/messaggio
 * troncato, mai l'URL completo (solo l'host).
 *
 * @param bool        $ok          Esito.
 * @param int         $code        Codice HTTP (0 se errore di trasporto).
 * @param string|null $error       Codice macchina dell'errore, null se ok.
 * @param string      $message     Messaggio (troncato a 500 char).
 * @param string      $url         URL di destinazione (solo l'host viene salvato).
 * @param string      $job_id      Id del job (o 'test-...' per un invio di prova).
 * @param int         $attempt     Numero di tentativo.
 * @param int|null    $duration_ms Durata della richiesta in millisecondi.
 */
function wphc_record_webhook_result( $ok, $code, $error, $message, $url, $job_id, $attempt, $duration_ms ) {
	$retry_at = null;
	if ( ! $ok && in_array( $error, array( 'wphc_webhook_transport_error', 'wphc_webhook_http_error' ), true ) && $attempt < WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
		$retry_at = gmdate( 'c', time() + wphc_webhook_backoff_for_attempt( $attempt ) );
	}

	update_option(
		'wp_health_check_webhook_last',
		array(
			'at'          => gmdate( 'c' ),
			'ok'          => (bool) $ok,
			'job_id'      => $job_id,
			'attempt'     => $attempt,
			'code'        => $code,
			'error'       => $error,
			'message'     => mb_substr( (string) $message, 0, 500 ),
			'url_host'    => wp_parse_url( $url, PHP_URL_HOST ),
			'duration_ms' => $duration_ms,
			'retry_at'    => $retry_at,
		),
		false
	);
}

/**
 * Invia (o tenta di inviare) una POST firmata verso il webhook configurato.
 * Gestisce, in ordine, la degradazione a "nessun invio, nessun errore" (non
 * configurato), a errore esplicito (non enrollato / revocato / URL non
 * ammesso), poi il trasporto vero e proprio. Un sito revocato tace del
 * tutto (stessa scelta di wphc_require_token() in ingresso) e cancella
 * qualunque retry pendente.
 *
 * @param string $event   Nome evento.
 * @param array  $payload Corpo del report (verra' codificato una volta sola).
 * @param string $job_id  Id del job, per idempotenza lato ricevente (header X-WPHC-Delivery).
 * @param int    $attempt Numero di tentativo (1-based).
 * @return array{ok:bool,code:int,error:string|null,retryable:bool}
 */
function wphc_dispatch_webhook_payload( $event, array $payload, $job_id, $attempt ) {
	$url = wphc_webhook_url();
	if ( '' === $url ) {
		return array(
			'ok'        => false,
			'code'      => 0,
			'error'     => null,
			'retryable' => false,
		);
	}

	$secret = get_option( 'wp_health_check_token' );
	if ( empty( $secret ) ) {
		wphc_record_webhook_result( false, 0, 'wphc_webhook_not_enrolled', '', $url, $job_id, $attempt, null );
		return array(
			'ok'        => false,
			'code'      => 0,
			'error'     => 'wphc_webhook_not_enrolled',
			'retryable' => false,
		);
	}

	if ( get_option( 'wp_health_check_revoked_at' ) ) {
		delete_option( 'wp_health_check_webhook_pending' );
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
		wphc_record_webhook_result( false, 0, 'wphc_webhook_revoked', '', $url, $job_id, $attempt, null );
		return array(
			'ok'        => false,
			'code'      => 0,
			'error'     => 'wphc_webhook_revoked',
			'retryable' => false,
		);
	}

	if ( ! wphc_webhook_url_is_allowed( $url ) ) {
		wphc_record_webhook_result( false, 0, 'wphc_webhook_url_blocked', '', $url, $job_id, $attempt, null );
		return array(
			'ok'        => false,
			'code'      => 0,
			'error'     => 'wphc_webhook_url_blocked',
			'retryable' => false,
		);
	}

	$body_json = wp_json_encode( $payload );
	$sig       = wphc_build_outbound_signature( $event, $body_json, $secret );

	$headers = array(
		'Content-Type'      => 'application/json; charset=utf-8',
		'Accept'            => 'application/json',
		'User-Agent'        => 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION,
		'X-WPHC-Timestamp'  => (string) $sig['timestamp'],
		'X-WPHC-Nonce'      => $sig['nonce'],
		'X-WPHC-Signature'  => $sig['signature'],
		'X-WPHC-Event'      => $event,
		'X-WPHC-Secret-Kid' => (string) get_option( 'wp_health_check_secret_kid', '' ),
		'X-WPHC-Site'       => wphc_normalize_site_url(),
		'X-WPHC-Delivery'   => (string) $job_id,
		'X-WPHC-Attempt'    => (string) $attempt,
	);

	$started     = microtime( true );
	$response    = wp_remote_post(
		$url,
		array(
			'timeout'     => WP_HEALTH_CHECK_WEBHOOK_TIMEOUT,
			'redirection' => 0, // Una 30x non deve far ripostare il body firmato altrove.
			'sslverify'   => true, // Esplicito: e' anche il default, non abbassarlo MAI.
			'headers'     => $headers,
			'body'        => $body_json,
		)
	);
	$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

	if ( is_wp_error( $response ) ) {
		$message = $response->get_error_message();
		wphc_record_webhook_result( false, 0, 'wphc_webhook_transport_error', $message, $url, $job_id, $attempt, $duration_ms );
		return array(
			'ok'        => false,
			'code'      => 0,
			'error'     => 'wphc_webhook_transport_error',
			'retryable' => true,
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code >= 200 && $code < 300 ) {
		wphc_record_webhook_result( true, $code, null, '', $url, $job_id, $attempt, $duration_ms );
		return array(
			'ok'        => true,
			'code'      => $code,
			'error'     => null,
			'retryable' => false,
		);
	}

	// Retry solo su errori transitori: un 4xx (a parte 408/429) significa
	// che il ricevente ha rifiutato la richiesta, ripeterla non risolve nulla.
	$retryable = ( 408 === $code || 429 === $code || $code >= 500 );
	$message   = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
	wphc_record_webhook_result( false, $code, 'wphc_webhook_http_error', $message, $url, $job_id, $attempt, $duration_ms );

	return array(
		'ok'        => false,
		'code'      => $code,
		'error'     => 'wphc_webhook_http_error',
		'retryable' => $retryable,
	);
}

/**
 * Costruisce il report JSON di fine job bulk: un solo vocabolario di
 * 'result' condiviso con la REST e con la tabella di log
 * (wphc_map_item_update_outcome()), nessun segreto/token/dato utente.
 *
 * @param array $job Documento completo del job.
 * @return array
 */
function wphc_bulk_build_webhook_payload( array $job ) {
	$items           = array();
	$items_truncated = false;

	foreach ( $job['items'] as $item ) {
		if ( count( $items ) >= WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS ) {
			$items_truncated = true;
			break;
		}
		$items[] = array(
			'type'        => $item['type'],
			'target'      => $item['target'],
			'name'        => $item['name'],
			'from'        => $item['from'],
			'to'          => $item['to'],
			'result'      => $item['result'],
			'attempts'    => $item['attempts'],
			'log_id'      => $item['log_id'],
			'finished_at' => $item['finished_ts'] ? gmdate( 'c', $item['finished_ts'] ) : null,
		);
	}

	return array(
		'schema'          => 'wphc.bulk_update.report/1',
		'event'           => 'wphc.bulk_update.completed',
		'site'            => wphc_normalize_site_url(),
		'agent_version'   => WP_HEALTH_CHECK_VERSION,
		'secret_kid'      => (string) get_option( 'wp_health_check_secret_kid', '' ),
		'generated_at'    => gmdate( 'c' ),
		'job'             => array(
			'id'           => $job['job_id'],
			'source'       => $job['source'],
			'status'       => $job['status'],
			'abort_reason' => $job['abort_reason'],
			'started_at'   => $job['started_ts'] ? gmdate( 'c', $job['started_ts'] ) : null,
			'finished_at'  => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
		),
		'totals'          => $job['counters'],
		'items'           => $items,
		'items_truncated' => $items_truncated,
	);
}

/**
 * Invia il report di fine job (o pianifica il primo retry) e aggiorna lo
 * stato 'webhook' del documento. Chiamata da wphc_bulk_finish() SOLO dopo
 * che il job e' gia' stato finalizzato/persistito, cosi' un ricevente lento
 * non tiene mai occupato il lock di drain per la durata della POST.
 *
 * @param array $job Documento del job, passato per riferimento.
 */
function wphc_bulk_send_webhook_for_job( array &$job ) {
	++$job['webhook']['attempts'];
	$job['webhook']['sending_ts'] = time();

	$payload = wphc_bulk_build_webhook_payload( $job );
	$result  = wphc_dispatch_webhook_payload( 'wphc.bulk_update.completed', $payload, $job['job_id'], $job['webhook']['attempts'] );

	$job['webhook']['code']       = $result['code'];
	$job['webhook']['last_error'] = $result['ok'] ? null : $result['error'];

	if ( $result['ok'] ) {
		$job['webhook']['sent']    = true;
		$job['webhook']['sent_ts'] = time();
		delete_option( 'wp_health_check_webhook_pending' );
		return;
	}

	if ( $result['retryable'] && $job['webhook']['attempts'] < WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
		update_option(
			'wp_health_check_webhook_pending',
			array(
				'job_id'   => $job['job_id'],
				'event'    => 'wphc.bulk_update.completed',
				'body'     => $payload,
				'attempts' => $job['webhook']['attempts'],
			),
			false
		);
		wp_schedule_single_event( time() + wphc_webhook_backoff_for_attempt( $job['webhook']['attempts'] ), 'wphc_webhook_retry' );
	}
}

/**
 * Cron callback di retry per un webhook fallito. Ri-firma sempre con
 * timestamp/nonce freschi sullo STESSO body (la finestra di freschezza
 * ±300s del protocollo scadrebbe altrimenti prima del retry).
 */
function wphc_maybe_retry_webhook() {
	$pending = get_option( 'wp_health_check_webhook_pending' );
	if ( ! is_array( $pending ) ) {
		return;
	}
	if ( false !== get_transient( 'wphc_webhook_retry_lock' ) ) {
		return;
	}
	set_transient( 'wphc_webhook_retry_lock', time(), 5 * MINUTE_IN_SECONDS );

	$attempt = (int) $pending['attempts'] + 1;
	$result  = wphc_dispatch_webhook_payload( $pending['event'], $pending['body'], $pending['job_id'], $attempt );

	if ( $result['ok'] || ! $result['retryable'] || $attempt >= WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
		delete_option( 'wp_health_check_webhook_pending' );
	} else {
		$pending['attempts'] = $attempt;
		update_option( 'wp_health_check_webhook_pending', $pending, false );
		wp_schedule_single_event( time() + wphc_webhook_backoff_for_attempt( $attempt ), 'wphc_webhook_retry' );
	}

	delete_transient( 'wphc_webhook_retry_lock' );
}
add_action( 'wphc_webhook_retry', 'wphc_maybe_retry_webhook' );

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
// AUTOLOGIN: POST /autologin/token + consumo su init
// -----------------------------------------------------------------------
//
// Pattern a due passi: le Application Password autenticano una singola
// chiamata REST (Basic Auth), ma non creano una sessione a cookie navigabile
// nel browser. Per aprire wp-admin gia' autenticati serve quindi generare un
// token one-time via una chiamata REST autenticata (questa sezione, primo
// passo), e poi "consumarlo" con una normale navigazione del browser che
// imposta il cookie di sessione (wphc_maybe_consume_autologin(), secondo
// passo, agganciata su 'init' e volutamente FUORI dalla REST API).

/**
 * Aggiunge una chiave di transient di autologin all'elenco di quelle
 * pendenti (dalla 1.30.0), cosi' POST /revoke puo' invalidarle tutte in
 * blocco tramite delete_transient() — l'unico modo corretto di rimuovere un
 * transient anche quando il sito usa un object cache persistente (Redis/
 * Memcached), dove una DELETE diretta su wp_options non basterebbe.
 * L'elenco resta volutamente corto (il TTL dei token e' di pochi secondi):
 * scarta le chiavi piu' vecchie oltre un tetto, per non far crescere
 * l'opzione se qualcosa lasciasse residui non consumati.
 *
 * @param string $key Chiave del transient da tracciare.
 */
function wphc_track_pending_autologin( $key ) {
	$pending   = (array) get_option( 'wp_health_check_autologin_pending', array() );
	$pending[] = $key;
	if ( count( $pending ) > 20 ) {
		$pending = array_slice( $pending, -20 );
	}
	update_option( 'wp_health_check_autologin_pending', $pending, false );
}

/**
 * Rimuove una chiave dall'elenco dei token di autologin pendenti, dopo che
 * e' stata consumata (o invalidata da una revoca). Controparte di
 * wphc_track_pending_autologin().
 *
 * @param string $key Chiave del transient da rimuovere dall'elenco.
 */
function wphc_untrack_pending_autologin( $key ) {
	$pending = (array) get_option( 'wp_health_check_autologin_pending', array() );
	$pending = array_values( array_diff( $pending, array( $key ) ) );
	if ( empty( $pending ) ) {
		delete_option( 'wp_health_check_autologin_pending' );
	} else {
		update_option( 'wp_health_check_autologin_pending', $pending, false );
	}
}

/**
 * POST /autologin/token — genera un token one-time per aprire wp-admin gia'
 * autenticati come l'utente corrente. L'identita' e' quella che WordPress
 * stesso ha gia' risolto per questa richiesta (tipicamente una Application
 * Password, ma funziona anche con cookie), MAI un user_id passato dal
 * chiamante: niente mapping da mantenere in questo plugin.
 *
 * Il token e' un valore casuale ad alta entropia (32 byte da random_bytes(),
 * CSPRNG — mai rand()/uniqid()), mai derivato ne' prevedibile. Il transient
 * che lo custodisce e' indicizzato dall'hash SHA-256 del token, non dal
 * token in chiaro: chi legge le wp_options non trova il segreto navigabile
 * in tabella.
 *
 * @param WP_REST_Request $request Richiesta REST corrente (nessun payload richiesto).
 * @return WP_REST_Response Esito con l'URL di autologin e la scadenza.
 */
function wphc_route_autologin_token( WP_REST_Request $request ) {
	unset( $request );

	wphc_maybe_send_cors_headers();

	$user = wp_get_current_user();

	$token          = bin2hex( random_bytes( 32 ) );
	$key            = 'wphc_autologin_' . hash( 'sha256', $token );
	$correlation_id = wphc_generate_correlation_id();

	// Il transient custodisce anche il correlation_id, cosi' la riga di log
	// del consumo (wphc_maybe_consume_autologin()) puo' collegarsi a questa
	// riga di richiesta senza dover ricalcolare o esporre nulla in piu'.
	set_transient(
		$key,
		array(
			'user_id'        => $user->ID,
			'correlation_id' => $correlation_id,
		),
		WP_HEALTH_CHECK_AUTOLOGIN_TTL
	);
	// Registrato anche in un piccolo elenco di chiavi pendenti (dalla 1.30.0):
	// e' cio' che permette a POST /revoke di invalidare i token di autologin
	// gia' emessi e non ancora consumati, vedi wphc_track_pending_autologin().
	wphc_track_pending_autologin( $key );

	// Audit minimo pre-esistente (non una tabella dedicata): solo l'ultimo
	// autologin richiesto, sullo stesso spirito di wphc_record_access().
	// Resta accanto alla riga di log sotto (che invece conserva lo storico).
	update_option(
		'wp_health_check_last_autologin',
		array(
			'user_id' => $user->ID,
			'login'   => $user->user_login,
			'at'      => gmdate( 'c' ),
			'ip'      => wphc_get_client_ip(),
		),
		false
	);

	// Riga di log dell'audit trail: type='token', target/name identificano
	// l'utente per cui e' stato generato (stesso schema tecnico/leggibile
	// gia' usato per plugin/temi/core, dove target e' l'identificatore e
	// name l'etichetta). version_from/to e active non si applicano: null.
	wphc_log_update_row( $correlation_id, 'token', $user->user_login, $user->display_name ? $user->display_name : $user->user_login, null, null, 'completed' );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( 'wp-health-check: autologin token generato per user #%d (%s)', $user->ID, $user->user_login ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	return rest_ensure_response(
		array(
			'autologin_url' => add_query_arg( 'wphc_autologin', $token, home_url( '/' ) ),
			'expires_in'    => WP_HEALTH_CHECK_AUTOLOGIN_TTL,
			'user_id'       => $user->ID,
			'user_login'    => $user->user_login,
		)
	);
}

/**
 * Consuma un token di autologin. Agganciata su 'init' e deliberatamente FUORI
 * dalla REST API: questo endpoint deve rispondere a una semplice navigazione
 * del browser (?wphc_autologin=<token> sull'home del sito), non a una
 * chiamata con header di autenticazione. Girare su 'init', prima che
 * qualunque output sia inviato, evita ogni rischio "headers already sent"
 * nel chiamare wp_set_auth_cookie().
 *
 * Fail-closed: qualunque anomalia (token assente, scaduto/già consumato,
 * utente inesistente) porta a un redirect silenzioso al login, senza
 * distinguere il motivo nella risposta — stesso principio di
 * wphc_require_token() per le rotte dati. Ogni esito (riuscito o fallito)
 * lascia comunque una riga type='login' nella tabella di log: sul successo
 * riusa il correlation_id della riga 'token' corrispondente; se il token e'
 * del tutto sconosciuto/scaduto (transient gia' assente) non c'e' alcuna
 * identita' recuperabile, quindi si logga con un correlation_id nuovo e
 * target = prefisso dell'hash del token (permette di riconoscere tentativi
 * ripetuti con lo stesso token senza esporlo in chiaro); se il token e'
 * valido ma l'utente e' stato nel frattempo cancellato, si riusa comunque il
 * correlation_id della riga 'token', con target = user_id (non si dispone
 * piu' dello user_login).
 */
function wphc_maybe_consume_autologin() {
	if ( empty( $_GET['wphc_autologin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- il token stesso e' la credenziale one-time, non serve un nonce di sessione.
		return;
	}

	$token = sanitize_text_field( wp_unslash( $_GET['wphc_autologin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key   = 'wphc_autologin_' . hash( 'sha256', $token );

	$data = get_transient( $key );

	// Consumo single-use: il transient viene cancellato SUBITO dopo la
	// lettura, indipendentemente dall'esito, per chiudere la finestra di
	// replay al primo utilizzo anche se l'utente risultasse poi invalido.
	delete_transient( $key );
	wphc_untrack_pending_autologin( $key );

	if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
		// Token sconosciuto, scaduto o gia' consumato: nessuna identita'
		// recuperabile. target = prefisso dell'hash del token (stesso hash
		// gia' calcolato per la chiave del transient), per poter comunque
		// distinguere tentativi ripetuti con lo stesso token nei log.
		wphc_log_update_row(
			wphc_generate_correlation_id(),
			'login',
			substr( hash( 'sha256', $token ), 0, 16 ),
			__( 'Token sconosciuto o scaduto', 'wp-health-check' ),
			null,
			null,
			'failed',
			__( 'Token assente, scaduto o gia\' consumato.', 'wp-health-check' )
		);
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	$user = get_user_by( 'id', (int) $data['user_id'] );
	if ( ! $user ) {
		wphc_log_update_row(
			$data['correlation_id'],
			'login',
			(string) $data['user_id'],
			__( 'Utente non trovato', 'wp-health-check' ),
			null,
			null,
			'failed',
			__( 'L\'utente e\' stato eliminato dopo la richiesta del token.', 'wp-health-check' )
		);
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID, true );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- si invoca l'hook core "wp_login" (non se ne dichiara uno nuovo), cosi' i plugin di audit/2FA che vi si agganciano reagiscono anche a questo login.
	do_action( 'wp_login', $user->user_login, $user );

	wphc_log_update_row( $data['correlation_id'], 'login', $user->user_login, $user->display_name ? $user->display_name : $user->user_login, null, null, 'completed' );

	wp_safe_redirect( admin_url() );
	exit;
}
add_action( 'init', 'wphc_maybe_consume_autologin' );

// -----------------------------------------------------------------------
// TRACCIAMENTO ULTIMO LOGIN
// -----------------------------------------------------------------------
//
// Data/IP dell'ultimo accesso di ciascun utente, esposti da GET /detail/users
// (dalla 1.29.0). Deliberatamente NESSUNA riga nella tabella di log per un
// login ordinario: su un sito con accessi frequenti gonfierebbe la tabella
// senza aggiungere nulla rispetto alla user meta qui sotto. Il type =
// 'login' della tabella resta riservato all'audit dell'autologin
// (wphc_maybe_consume_autologin() sopra), che e' un evento raro e sensibile.

/**
 * Registra data (timestamp Unix, per poter ordinare/confrontare senza
 * parsing) e IP dell'ultimo accesso riuscito di un utente, in due user meta
 * dedicate. Agganciata a wp_login, che WordPress invoca gia' con lo
 * user_login e l'oggetto WP_User risolto; il fallback su get_user_by()
 * copre i rari casi (pluggable auth custom) in cui il secondo parametro non
 * arrivasse.
 *
 * @param string       $user_login Login dell'utente autenticato.
 * @param WP_User|null $user       Utente autenticato, se gia' risolto dal chiamante.
 */
function wphc_record_last_login( $user_login, $user = null ) {
	if ( ! ( $user instanceof WP_User ) ) {
		$user = get_user_by( 'login', $user_login );
	}
	if ( ! ( $user instanceof WP_User ) ) {
		return;
	}

	update_user_meta( $user->ID, 'wphc_last_login', time() );

	// wphc_get_client_ip() rispetta gia' l'opzione wp_health_check_trust_proxy:
	// stessa fonte usata per la colonna 'ip' della tabella di log.
	$ip = wphc_get_client_ip();
	if ( '' !== $ip ) {
		update_user_meta( $user->ID, 'wphc_last_login_ip', $ip );
	}
}
add_action( 'wp_login', 'wphc_record_last_login', 10, 2 );

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
	$updates_via_api_enabled = (bool) get_option( 'wp_health_check_updates_enabled', true );
	$restrict_official_only  = (bool) get_option( 'wp_health_check_restrict_official_only', false );
	$thumbnail               = get_option( 'wp_health_check_thumb' );
	$current_secret          = (string) get_option( 'wp_health_check_token', '' );
	$secret_kid              = (string) get_option( 'wp_health_check_secret_kid', '' );
	$secret_rotated_at       = get_option( 'wp_health_check_token_rotated_at' );
	$secret_revoked_at       = get_option( 'wp_health_check_revoked_at' );
	$protocol_version        = (int) get_option( 'wp_health_check_protocol', 1 );
	// $webhook_url_raw e' il SOLO override del sito (campo del form): resta
	// vuoto se il sito non ha impostato nulla, anche quando il default di
	// flotta e' attivo. $webhook_url e' invece l'URL EFFETTIVO (con fallback
	// al default), usato solo per decidere se mostrare il pulsante di test e
	// per il testo informativo sotto il campo.
	$webhook_url_raw   = (string) get_option( 'wp_health_check_webhook_url', '' );
	$webhook_disabled  = (bool) get_option( 'wp_health_check_webhook_disabled', false );
	$webhook_url       = wphc_webhook_url();
	$webhook_last      = get_option( 'wp_health_check_webhook_last' );
	$webhook_url_field = $webhook_url_raw;
	$webhook_bad_url   = get_transient( 'wphc_webhook_bad_url_' . get_current_user_id() );
	if ( false !== $webhook_bad_url ) {
		$webhook_url_field = $webhook_bad_url;
		delete_transient( 'wphc_webhook_bad_url_' . get_current_user_id() );
	}

	// Il segreto non viene MAI stampato per intero nell'HTML della pagina
	// (finirebbe in cache di pagina, view-source, screenshot automatici):
	// qui solo una versione mascherata e un fingerprint, sufficienti a
	// verificare "e' il segreto giusto?" senza esporlo. Il valore completo
	// arriva al browser solo in risposta al pulsante "Mostra" (vedi
	// wphc_ajax_reveal_secret()).
	$secret_masked = '';
	if ( '' !== $current_secret ) {
		$secret_masked = ( strlen( $current_secret ) > 10 )
			? substr( $current_secret, 0, 6 ) . str_repeat( '•', 8 ) . substr( $current_secret, -4 )
			: str_repeat( '•', strlen( $current_secret ) );
	}
	$secret_fingerprint = ( '' !== $current_secret ) ? substr( hash( 'sha256', $current_secret ), 0, 12 ) : '';
	$thumb_error        = get_option( 'wp_health_check_thumb_error' );

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
		<?php elseif ( isset( $_GET['wphc_thumb_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Anteprima del sito eliminata: verra\' rigenerata alla prossima chiamata /health.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_thumb_regenerated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( get_option( 'wp_health_check_thumb' ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Anteprima rigenerata con successo.', 'wp-health-check' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Rigenerazione non riuscita: vedi il dettaglio dell\'errore nella riga "Anteprima sito" qui sotto.', 'wp-health-check' ); ?></p></div>
			<?php endif; ?>
		<?php elseif ( isset( $_GET['wphc_webhook_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p>
				<?php
				echo '' === $webhook_url
					? esc_html__( 'Webhook disattivato.', 'wp-health-check' )
					: esc_html__( 'URL del webhook salvato.', 'wp-health-check' );
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_webhook_invalid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p>
				<?php
				$webhook_reason        = isset( $_GET['reason'] ) ? sanitize_key( wp_unslash( $_GET['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$webhook_reason_labels = array(
					'not_https'    => __( 'L\'URL deve usare https.', 'wp-health-check' ),
					'blocked_host' => __( 'L\'host indicato e\' un indirizzo privato o di loopback: non e\' consentito.', 'wp-health-check' ),
					'bad_url'      => __( 'URL non valido.', 'wp-health-check' ),
				);
				$webhook_reason_label  = isset( $webhook_reason_labels[ $webhook_reason ] ) ? $webhook_reason_labels[ $webhook_reason ] : __( 'URL non valido.', 'wp-health-check' );
				echo esc_html( $webhook_reason_label ) . ' <code>' . esc_html( $webhook_reason ) . '</code>';
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_webhook_tested'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( is_array( $webhook_last ) && ! empty( $webhook_last['ok'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Webhook di prova inviato con successo.', 'wp-health-check' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Invio del webhook di prova non riuscito: vedi il dettaglio qui sotto.', 'wp-health-check' ); ?></p></div>
			<?php endif; ?>
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
					<td><?php esc_html_e( 'Anteprima sito', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( $thumbnail ) : ?>
							<p>
								<img src="<?php echo esc_url( $thumbnail ); ?>" width="200" alt="<?php esc_attr_e( 'Anteprima del sito', 'wp-health-check' ); ?>" style="height:auto;border:1px solid #ccc;">
							</p>
							<form
								method="post"
								action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Eliminare l\'anteprima? Verra\' rigenerata alla prossima chiamata /health.', 'wp-health-check' ) ) ); ?> );"
							>
								<input type="hidden" name="action" value="wphc_delete_thumbnail" />
								<?php wp_nonce_field( 'wphc_delete_thumbnail' ); ?>
								<?php submit_button( __( 'Elimina e rigenera', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
							</form>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'non ancora generata', 'wp-health-check' ); ?></p>
						<?php endif; ?>
						<form
							method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							style="margin-top:6px;"
						>
							<input type="hidden" name="action" value="wphc_regenerate_thumbnail" />
							<?php wp_nonce_field( 'wphc_regenerate_thumbnail' ); ?>
							<?php submit_button( __( 'Rigenera anteprima ora', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
						</form>
						<?php if ( is_array( $thumb_error ) && ! empty( $thumb_error['code'] ) ) : ?>
							<p class="description" style="color:#d63638;">
								<?php
								printf(
									/* translators: 1: codice macchina dell'errore, 2: messaggio diagnostico, 3: data/ora ISO 8601. */
									esc_html__( 'Ultimo errore: %1$s — %2$s (%3$s)', 'wp-health-check' ),
									esc_html( $thumb_error['code'] ),
									esc_html( isset( $thumb_error['message'] ) ? $thumb_error['message'] : '' ),
									esc_html( isset( $thumb_error['at'] ) ? $thumb_error['at'] : '' )
								);
								?>
							</p>
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
					<td><?php esc_html_e( 'Segreto di flotta', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( ! empty( $secret_revoked_at ) ) : ?>
							<strong style="color:#d63638;"><?php esc_html_e( 'Revocato', 'wp-health-check' ); ?></strong>
							<?php
							printf(
								/* translators: %s: data/ora ISO 8601 della revoca. */
								esc_html__( '(il %s)', 'wp-health-check' ),
								esc_html( $secret_revoked_at )
							);
							?>
						<?php elseif ( '' !== $secret_masked ) : ?>
							<code id="wphc-secret-masked"><?php echo esc_html( $secret_masked ); ?></code>
							<button type="button" class="button button-small" id="wphc-secret-reveal-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wphc_reveal_secret' ) ); ?>">
								<?php esc_html_e( 'Mostra', 'wp-health-check' ); ?>
							</button>
							<input type="text" id="wphc-secret-full" readonly style="display:none;width:26em;" />
							<button type="button" class="button button-small" id="wphc-secret-copy-btn" style="display:none;">
								<?php esc_html_e( 'Copia', 'wp-health-check' ); ?>
							</button>
							<p class="description">
								<?php
								printf(
									/* translators: 1: fingerprint SHA-256 troncato, 2: protocollo (1 o 2), 3: data di rotazione o "mai". */
									esc_html__( 'Fingerprint: %1$s — protocollo: %2$d — ultima rotazione: %3$s.', 'wp-health-check' ),
									esc_html( $secret_fingerprint ),
									(int) $protocol_version,
									esc_html( $secret_rotated_at ? gmdate( 'c', (int) $secret_rotated_at ) : __( 'mai', 'wp-health-check' ) )
								);
								?>
								<?php esc_html_e( 'E\' la credenziale con cui il sistema centrale governa questo sito: chi la legge puo\' impersonarlo fino alla prossima rotazione. La rotazione e la revoca sono operazioni del centro, non disponibili da qui.', 'wp-health-check' ); ?>
							</p>
						<?php else : ?>
							&mdash;
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

		<h3><?php esc_html_e( 'Log degli aggiornamenti', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Consultazione dello storico registrato nella tabella di log (stessi dati di GET /update/log), filtrabile per tipo e per origine: distingue gli aggiornamenti avviati via API da quelli fatti dalla bacheca di WordPress, da un auto-update in background o da WP-CLI.', 'wp-health-check' ); ?>
		</p>
		<div id="wphc-log-viewer" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wphc_view_log' ) ); ?>">
			<select id="wphc-log-type">
				<option value=""><?php esc_html_e( 'Tutti i tipi', 'wp-health-check' ); ?></option>
				<option value="plugin"><?php esc_html_e( 'Plugin', 'wp-health-check' ); ?></option>
				<option value="theme"><?php esc_html_e( 'Temi', 'wp-health-check' ); ?></option>
				<option value="core"><?php esc_html_e( 'Core', 'wp-health-check' ); ?></option>
				<option value="token"><?php esc_html_e( 'Token autologin', 'wp-health-check' ); ?></option>
				<option value="login"><?php esc_html_e( 'Accesso autologin', 'wp-health-check' ); ?></option>
			</select>
			<select id="wphc-log-source">
				<option value=""><?php esc_html_e( 'Tutte le origini', 'wp-health-check' ); ?></option>
				<option value="api"><?php esc_html_e( 'API', 'wp-health-check' ); ?></option>
				<option value="wp-admin"><?php esc_html_e( 'wp-admin', 'wp-health-check' ); ?></option>
				<option value="cron"><?php esc_html_e( 'Cron (auto-update)', 'wp-health-check' ); ?></option>
				<option value="wp-cli"><?php esc_html_e( 'WP-CLI', 'wp-health-check' ); ?></option>
			</select>
			<button type="button" class="button" id="wphc-log-view-btn"><?php esc_html_e( 'Visualizza log', 'wp-health-check' ); ?></button>
		</div>

		<div id="wphc-modal" class="wphc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wphc-modal-title">
			<div class="wphc-modal-box">
				<div class="wphc-modal-head">
					<strong id="wphc-modal-title"></strong>
					<button type="button" class="button-link wphc-modal-close" aria-label="<?php esc_attr_e( 'Chiudi', 'wp-health-check' ); ?>">&times;</button>
				</div>
				<p id="wphc-modal-meta" class="description" style="margin:.5em 0;word-break:break-all;"></p>
				<pre id="wphc-modal-body"></pre>
				<div id="wphc-modal-table" style="display:none;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Data', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Elemento', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Versione', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Esito', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Origine', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Attore', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'IP', 'wp-health-check' ); ?></th>
							</tr>
						</thead>
						<tbody id="wphc-log-tbody"></tbody>
					</table>
					<p style="margin-top:10px;">
						<button type="button" class="button" id="wphc-log-load-more" style="display:none;"><?php esc_html_e( 'Carica altri 50', 'wp-health-check' ); ?></button>
					</p>
				</div>
			</div>
		</div>

		<style>
			.wphc-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:5vh 16px;box-sizing:border-box;}
			.wphc-modal-box{background:#fff;width:80%;max-height:88vh;overflow:auto;border-radius:4px;padding:14px 20px 20px;box-shadow:0 4px 24px rgba(0,0,0,.3);}
			.wphc-modal-head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #dcdcde;padding-bottom:8px;margin-bottom:6px;}
			.wphc-modal-close{font-size:22px;line-height:1;text-decoration:none;color:#646970;}
			#wphc-modal-body{background:#1d2327;color:#f0f0f1;padding:12px;border-radius:3px;overflow:auto;max-height:60vh;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;margin:0;}
			#wphc-modal-table table{font-size:12px;}
			#wphc-modal-table td, #wphc-modal-table th{word-break:break-word;}
			#wphc-endpoint-tester .button{margin:0 6px 6px 0;}
			#wphc-log-viewer{margin-bottom:10px;}
			#wphc-log-viewer select{margin:0 6px 6px 0;}
			.wphc-status-ok{color:#008a20;font-weight:600;}
			.wphc-status-bad{color:#d63638;font-weight:600;}
		</style>

		<script>
			( function () {
				var modal     = document.getElementById( 'wphc-modal' );
				var titleEl   = document.getElementById( 'wphc-modal-title' );
				var metaEl    = document.getElementById( 'wphc-modal-meta' );
				var bodyEl    = document.getElementById( 'wphc-modal-body' );
				var tableWrap = document.getElementById( 'wphc-modal-table' );
				var tbody     = document.getElementById( 'wphc-log-tbody' );

				// mode 'table' mostra la tabella del log e nasconde il <pre> del
				// tester endpoint (e viceversa): la modale e' condivisa fra le due
				// funzionalita', non duplicata.
				function openModal( title, mode ) {
					titleEl.textContent      = title;
					metaEl.textContent       = 'Chiamata in corso…';
					bodyEl.textContent       = '';
					bodyEl.style.display     = ( 'table' === mode ) ? 'none' : '';
					tableWrap.style.display  = ( 'table' === mode ) ? '' : 'none';
					modal.style.display      = 'flex';
				}
				function closeModal() { modal.style.display = 'none'; }
				function prettify( txt ) {
					try { return JSON.stringify( JSON.parse( txt ), null, 2 ); } catch ( e ) { return txt; }
				}
				function escapeHtml( s ) { var d = document.createElement( 'div' ); d.textContent = s; return d.innerHTML; }

				var wrap = document.getElementById( 'wphc-endpoint-tester' );
				if ( wrap ) {
					var nonce = wrap.dataset.nonce;
					wrap.querySelectorAll( '.wphc-test-btn' ).forEach( function ( btn ) {
						btn.addEventListener( 'click', function () {
							openModal( btn.textContent.trim(), 'json' );
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
				}

				// Visualizzatore del log: stessa modale, contenuto tabellare
				// costruito con createElement/textContent (mai innerHTML) perche'
				// 'message'/'actor'/'target' sono dati che arrivano dal server.
				var logWrap = document.getElementById( 'wphc-log-viewer' );
				if ( logWrap ) {
					var logNonce    = logWrap.dataset.nonce;
					var typeSelect  = document.getElementById( 'wphc-log-type' );
					var srcSelect   = document.getElementById( 'wphc-log-source' );
					var viewBtn     = document.getElementById( 'wphc-log-view-btn' );
					var loadMoreBtn = document.getElementById( 'wphc-log-load-more' );
					var state       = { type: '', source: '', offset: 0, total: 0, shown: 0 };

					function renderRows( entries ) {
						entries.forEach( function ( entry ) {
							var tr     = document.createElement( 'tr' );
							var vers   = ( entry.version_from || '—' ) + ' → ' + ( entry.version_to || '—' );
							var cells  = [ entry.created_at, entry.type, entry.target, vers, entry.phase, entry.source, entry.actor || '—', entry.ip || '—' ];
							cells.forEach( function ( val ) {
								var td = document.createElement( 'td' );
								td.textContent = val;
								tr.appendChild( td );
							} );
							tbody.appendChild( tr );
						} );
						state.shown += entries.length;
					}

					function loadPage() {
						metaEl.textContent = 'Caricamento…';
						var fd = new FormData();
						fd.append( 'action', 'wphc_view_log' );
						fd.append( 'type', state.type );
						fd.append( 'source', state.source );
						fd.append( 'offset', state.offset );
						fd.append( '_ajax_nonce', logNonce );
						fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) {
								if ( res && res.success ) {
									state.total = res.data.total;
									renderRows( res.data.entries );
									state.offset += res.data.entries.length;
									metaEl.textContent = state.shown + ' di ' + state.total + ' righe';
									loadMoreBtn.style.display = ( state.offset < state.total ) ? 'inline-block' : 'none';
								} else {
									metaEl.textContent = 'Errore';
								}
							} )
							.catch( function () { metaEl.textContent = 'Errore di rete'; } );
					}

					viewBtn.addEventListener( 'click', function () {
						state.type   = typeSelect.value;
						state.source = srcSelect.value;
						state.offset = 0;
						state.shown  = 0;
						tbody.innerHTML = '';
						openModal( 'Log degli aggiornamenti', 'table' );
						loadPage();
					} );

					loadMoreBtn.addEventListener( 'click', loadPage );
				}

				modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { closeModal(); } } );
				document.querySelector( '.wphc-modal-close' ).addEventListener( 'click', closeModal );
				document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { closeModal(); } } );
			}() );
		</script>

		<script>
			// Pulsanti "Mostra"/"Copia" della riga "Segreto di flotta" (dalla
			// 1.30.0): il valore in chiaro arriva SOLO qui, via AJAX, mai
			// stampato nel markup della pagina.
			( function () {
				var revealBtn = document.getElementById( 'wphc-secret-reveal-btn' );
				if ( ! revealBtn ) {
					return;
				}
				var fullField = document.getElementById( 'wphc-secret-full' );
				var copyBtn   = document.getElementById( 'wphc-secret-copy-btn' );

				revealBtn.addEventListener( 'click', function () {
					var body = new FormData();
					body.append( 'action', 'wphc_reveal_secret' );
					body.append( '_ajax_nonce', revealBtn.dataset.nonce );

					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( ! data.success ) {
								window.alert( ( data.data && data.data.message ) || 'Errore' );
								return;
							}
							fullField.value = data.data.secret;
							fullField.style.display = 'inline-block';
							copyBtn.style.display = 'inline-block';
							revealBtn.style.display = 'none';
						} );
				} );

				if ( copyBtn ) {
					copyBtn.addEventListener( 'click', function () {
						fullField.select();
						if ( navigator.clipboard ) {
							navigator.clipboard.writeText( fullField.value );
						} else {
							document.execCommand( 'copy' );
						}
					} );
				}
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
			<?php esc_html_e( 'Interruttore master: quando spento, le rotte POST /update/plugin, /update/theme e /update/core rifiutano ogni richiesta con 403 (GET /update/log resta sempre leggibile). Di default e\' aggiornabile qualsiasi plugin/tema, non solo quelli ospitati su wordpress.org (vedi checkbox sotto); rollback automatico via temp-backup nativo di WordPress per plugin e temi.', 'wp-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_toggle_updates" />
			<?php wp_nonce_field( 'wphc_toggle_updates' ); ?>
			<p>
				<label>
					<input type="checkbox" name="wphc_updates_enabled" value="1" <?php checked( $updates_via_api_enabled ); ?> />
					<?php esc_html_e( 'Consenti aggiornamenti (plugin, temi, core) via API', 'wp-health-check' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wphc_restrict_official_only" value="1" <?php checked( $restrict_official_only ); ?> />
					<?php esc_html_e( 'Limita gli aggiornamenti ai soli pacchetti ospitati su wordpress.org (esclude i plugin/temi premium)', 'wp-health-check' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Salva', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Webhook di notifica aggiornamenti bulk', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'A fine di ogni job di aggiornamento bulk (POST /update/bulk), se configurato, viene inviata una POST firmata con HMAC-SHA256 sul segreto di flotta (header X-WPHC-*) contenente il dettaglio di cosa e\' stato fatto. Deve essere un URL https; nessun token nella query string e\' necessario, l\'autenticazione e\' nella firma.', 'wp-health-check' ); ?>
		</p>
		<p class="description">
			<?php if ( $webhook_disabled ) : ?>
				<?php esc_html_e( 'Webhook disattivato esplicitamente per questo sito: nessuna notifica viene inviata.', 'wp-health-check' ); ?>
			<?php elseif ( '' !== $webhook_url_raw ) : ?>
				<?php esc_html_e( 'In uso l\'URL impostato qui sotto per questo sito.', 'wp-health-check' ); ?>
			<?php else : ?>
				<?php
				printf(
					/* translators: %s: URL di default della flotta. */
					esc_html__( 'Nessun URL impostato per questo sito: in uso il default di flotta %s. Compila il campo per sovrascriverlo, o spunta "disattiva" per non inviare nulla.', 'wp-health-check' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				);
				?>
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_save_webhook" />
			<?php wp_nonce_field( 'wphc_save_webhook' ); ?>
			<p>
				<input type="url" class="regular-text code" name="wphc_webhook_url" id="wphc-webhook-url"
					value="<?php echo esc_attr( $webhook_url_field ); ?>"
					placeholder="https://hub.esempio.com/wphc/webhook" />
			</p>
			<p>
				<label>
					<input type="checkbox" name="wphc_webhook_disabled" value="1" <?php checked( $webhook_disabled ); ?> />
					<?php esc_html_e( 'Non inviare notifiche webhook per questo sito (ignora anche il default di flotta)', 'wp-health-check' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Salva', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( '' !== $webhook_url ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wphc_test_webhook" />
				<?php wp_nonce_field( 'wphc_test_webhook' ); ?>
				<?php submit_button( __( 'Invia un webhook di prova', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php if ( is_array( $webhook_last ) ) : ?>
			<p class="description" <?php echo empty( $webhook_last['ok'] ) ? 'style="color:#d63638;"' : ''; ?>>
				<?php
				printf(
					/* translators: 1: data/ora, 2: esito, 3: codice HTTP, 4: codice errore macchina, 5: host di destinazione. */
					esc_html__( 'Ultimo invio: %1$s — %2$s (http %3$s%4$s) verso %5$s', 'wp-health-check' ),
					esc_html( $webhook_last['at'] ),
					empty( $webhook_last['ok'] ) ? esc_html__( 'fallito', 'wp-health-check' ) : esc_html__( 'riuscito', 'wp-health-check' ),
					esc_html( (string) $webhook_last['code'] ),
					empty( $webhook_last['error'] ) ? '' : ', ' . esc_html( $webhook_last['error'] ),
					esc_html( (string) $webhook_last['url_host'] )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Nessun webhook inviato finora.', 'wp-health-check' ); ?></p>
		<?php endif; ?>

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
 * Handler AJAX (admin) del pulsante "Visualizza log" della tab Site Health:
 * legge la stessa tabella di GET /update/log tramite wphc_get_update_log_entries(),
 * ma con una query diretta invece che via loopback REST, perche' qui
 * l'utente e' gia' autenticato in wp-admin e non serve il bearer token.
 */
function wphc_ajax_view_log() {
	check_ajax_referer( 'wphc_view_log' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
	}

	$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

	$result = wphc_get_update_log_entries( $type, $source, 50, $offset );

	wp_send_json_success(
		array(
			'entries' => $result['entries'],
			'total'   => $result['total'],
		)
	);
}
add_action( 'wp_ajax_wphc_view_log', 'wphc_ajax_view_log' );

/**
 * Handler AJAX (admin) del pulsante "Mostra" nella riga "Segreto di flotta"
 * (dalla 1.30.0): l'unico punto in cui il segreto in chiaro raggiunge il
 * browser, e solo in risposta a questa chiamata esplicita — mai stampato
 * nell'HTML della pagina, per non finire in cache di pagina, view-source o
 * screenshot automatici. Su multisite richiede anche manage_network: su una
 * rete il segreto e' condiviso da tutti i siti, non del singolo sito, quindi
 * la sola capacita' locale manage_options non basta. Ogni reveal lascia una
 * riga nella tabella di log (type='token', message='secret_revealed').
 */
function wphc_ajax_reveal_secret() {
	check_ajax_referer( 'wphc_reveal_secret' );
	if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
	}

	$secret = (string) get_option( 'wp_health_check_token', '' );
	if ( '' === $secret ) {
		wp_send_json_error( array( 'message' => __( 'Nessun segreto registrato.', 'wp-health-check' ) ), 404 );
	}

	$user = wp_get_current_user();
	wphc_log_update_row(
		wphc_generate_correlation_id(),
		'token',
		'reveal',
		$user->display_name ? $user->display_name : $user->user_login,
		null,
		null,
		'completed',
		'secret_revealed',
		null,
		'wp-admin',
		$user->user_login
	);

	wp_send_json_success( array( 'secret' => $secret ) );
}
add_action( 'wp_ajax_wphc_reveal_secret', 'wphc_ajax_reveal_secret' );

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
	delete_transient( 'wphc_public_ip' );
	delete_transient( 'wphc_public_ip_retry_lock' );

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
 * Handler di admin-post.php per il pulsante "Elimina e rigenera" della
 * riga "Anteprima sito": elimina l'attachment dal Media Library e le
 * opzioni collegate, cosi' la prossima /health rigenera la thumbnail.
 * Il delete_transient() finale rimuove anche l'eventuale cooldown residuo,
 * per non far attendere l'utente che ha appena chiesto una rigenerazione.
 */
function wphc_handle_delete_thumbnail() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_delete_thumbnail' );

	$attachment_id = (int) get_option( 'wp_health_check_thumb_id' );
	if ( ! $attachment_id ) {
		// Retrocompatibilita' con le thumbnail create dalla 1.27.0, che
		// salvava solo l'URL (wp_health_check_thumb_id non esisteva ancora).
		$attachment_id = attachment_url_to_postid( (string) get_option( 'wp_health_check_thumb' ) );
	}
	if ( $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}

	delete_option( 'wp_health_check_thumb' );
	delete_option( 'wp_health_check_thumb_id' );
	delete_option( 'wp_health_check_thumb_error' );
	delete_option( 'wp_health_check_thumb_at' );
	delete_transient( 'wphc_thumb_retry_lock' );

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'                => 'wp-health-check',
				'wphc_thumb_deleted' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_delete_thumbnail', 'wphc_handle_delete_thumbnail' );

/**
 * Handler di admin-post.php per il pulsante "Rigenera anteprima ora" (dalla
 * 1.30.0): a differenza di "Elimina e rigenera" (che si limita a cancellare
 * e demanda la rigenerazione alla prossima /health), questo chiama
 * wphc_maybe_generate_thumbnail( true ) sincronamente nella stessa richiesta
 * admin, cosi' un eventuale errore e' visibile subito nella riga "Anteprima
 * sito" invece di scoprirlo solo al prossimo polling.
 */
function wphc_handle_regenerate_thumbnail() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_regenerate_thumbnail' );

	wphc_maybe_generate_thumbnail( true );

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'                    => 'wp-health-check',
				'wphc_thumb_regenerated' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_regenerate_thumbnail', 'wphc_handle_regenerate_thumbnail' );

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
 * Handler di admin-post.php per i checkbox "Consenti aggiornamenti (plugin,
 * temi, core) via API" e "Limita gli aggiornamenti ai soli pacchetti
 * ospitati su wordpress.org" nella tab Site Health: stesso form/nonce, unico
 * interruttore master (§5 della specifica) da cui dipendono le rotte
 * POST /update/{plugin,theme,core}, piu' la sotto-opzione che ne restringe
 * l'host del pacchetto (vedi wphc_is_package_host_allowed()).
 */
function wphc_handle_toggle_updates() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_toggle_updates' );

	update_option( 'wp_health_check_updates_enabled', ! empty( $_POST['wphc_updates_enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().
	update_option( 'wp_health_check_restrict_official_only', ! empty( $_POST['wphc_restrict_official_only'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

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

/**
 * Handler di admin-post.php per il campo "Webhook di notifica aggiornamenti
 * bulk". Pipeline di validazione: esc_url_raw() normalizza (non valida),
 * wp_http_validate_url() e' il gate vero (lo stesso validatore che l'HTTP
 * API di WP applica gia' alle richieste sicure, quindi si rifiuta esattamente
 * cio' che il trasporto rifiuterebbe comunque), poi scheme https esplicito
 * (il validatore da solo accetta anche http) e, se l'host e' un IP letterale,
 * wphc_ip_is_public() (riusata, non riscritta). Un valore rifiutato non
 * sovrascrive mai un URL funzionante gia' salvato.
 *
 * Gestisce anche la checkbox "disattiva webhook", indipendente dal campo
 * URL: da quando esiste un default di flotta (WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL),
 * un campo vuoto non significa piu' "spento" ma "usa il default", quindi
 * serve un modo esplicito di dire "niente notifiche per questo sito".
 */
function wphc_handle_save_webhook() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_save_webhook' );

	$raw      = isset( $_POST['wphc_webhook_url'] ) ? trim( (string) wp_unslash( $_POST['wphc_webhook_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().
	$disabled = ! empty( $_POST['wphc_webhook_disabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

	update_option( 'wp_health_check_webhook_disabled', $disabled );
	if ( $disabled ) {
		// Nessuna notifica deve restare in volo (retry pianificato) quando
		// l'operatore ha appena chiesto di spegnere tutto, a prescindere dal
		// campo URL sotto.
		delete_option( 'wp_health_check_webhook_pending' );
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
	}

	if ( '' === $raw ) {
		delete_option( 'wp_health_check_webhook_url' );
		delete_option( 'wp_health_check_webhook_pending' );
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                => 'wp-health-check',
					'wphc_webhook_saved' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	$url    = esc_url_raw( $raw );
	$reason = '';
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		$reason = 'bad_url';
	} elseif ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
		$reason = 'not_https';
	} elseif ( ! wphc_webhook_url_is_allowed( $url ) ) {
		$reason = 'blocked_host';
	}

	if ( '' !== $reason ) {
		// Il valore rifiutato viaggia in un transient per utente, non nella
		// query string (contenuto riflesso): serve solo a ripresentarlo nel
		// campo dopo il redirect.
		set_transient( 'wphc_webhook_bad_url_' . get_current_user_id(), $raw, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                  => 'wp-health-check',
					'wphc_webhook_invalid' => '1',
					'reason'               => $reason,
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	update_option( 'wp_health_check_webhook_url', $url, false );

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'                => 'wp-health-check',
				'wphc_webhook_saved' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_save_webhook', 'wphc_handle_save_webhook' );

/**
 * Handler di admin-post.php per "Invia un webhook di prova": invio sincrono
 * dell'evento wphc.test, MAI un retry pianificato (a differenza del report di
 * fine job bulk). Unico modo pratico per un admin di verificare che la
 * propria integrazione HMAC sia corretta prima che giri un job reale.
 */
function wphc_handle_test_webhook() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_test_webhook' );

	$payload = array(
		'schema'        => 'wphc.test/1',
		'event'         => 'wphc.test',
		'site'          => wphc_normalize_site_url(),
		'agent_version' => WP_HEALTH_CHECK_VERSION,
		'secret_kid'    => (string) get_option( 'wp_health_check_secret_kid', '' ),
		'generated_at'  => gmdate( 'c' ),
	);
	wphc_dispatch_webhook_payload( 'wphc.test', $payload, 'test-' . wphc_generate_correlation_id(), 1 );

	wp_safe_redirect(
		add_query_arg(
			array(
				'tab'                 => 'wp-health-check',
				'wphc_webhook_tested' => '1',
			),
			admin_url( 'site-health.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wphc_test_webhook', 'wphc_handle_test_webhook' );

// -----------------------------------------------------------------------
// COMANDI WP-CLI: wp health-check reset | secret | status
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

		/**
		 * Mostra fingerprint, protocollo, data di rotazione e stato di revoca
		 * del segreto di flotta corrente, senza aprire wp-admin.
		 *
		 * Con --show stampa il segreto per intero, in chiaro: utile per un
		 * recupero d'emergenza da shell (es. per confrontarlo manualmente con
		 * quanto conservato dal centro), dove non c'e' un browser da cui il
		 * valore potrebbe essere esfiltrato via XSS.
		 *
		 * ## OPTIONS
		 *
		 * [--show]
		 * : Stampa anche il segreto in chiaro, non solo il fingerprint.
		 *
		 * ## EXAMPLES
		 *
		 *     wp health-check secret
		 *     wp health-check secret --show
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Argomenti posizionali (non usati).
		 * @param array $assoc_args Argomenti nominali (--show).
		 */
		public function secret( $args, $assoc_args ) {
			unset( $args );

			$current = (string) get_option( 'wp_health_check_token', '' );
			if ( '' === $current ) {
				WP_CLI::error( 'Nessun segreto registrato (sito non ancora enrollato).' );
			}

			$revoked_at = get_option( 'wp_health_check_revoked_at' );
			$rotated_at = get_option( 'wp_health_check_token_rotated_at' );

			WP_CLI::log( 'Fingerprint: ' . substr( hash( 'sha256', $current ), 0, 12 ) );
			WP_CLI::log( 'Protocollo: ' . (int) get_option( 'wp_health_check_protocol', 1 ) );
			WP_CLI::log( 'Ultima rotazione: ' . ( $rotated_at ? gmdate( 'c', (int) $rotated_at ) : 'mai' ) );
			WP_CLI::log( 'Revocato: ' . ( $revoked_at ? $revoked_at : 'no' ) );

			if ( ! empty( $assoc_args['show'] ) ) {
				WP_CLI::log( 'Segreto: ' . $current );
			}
		}

		/**
		 * Riepilogo diagnostico dello stato del sito: enrollment, versione,
		 * protocollo, ultimo errore di enroll e stato della thumbnail. Quanto
		 * serve per diagnosticare un sito senza aprire wp-admin.
		 *
		 * ## EXAMPLES
		 *
		 *     wp health-check status
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Argomenti posizionali (non usati).
		 * @param array $assoc_args Argomenti nominali (non usati).
		 */
		public function status( $args, $assoc_args ) {
			unset( $args, $assoc_args );

			$is_enrolled = ! empty( get_option( 'wp_health_check_token' ) );
			$last_error  = get_option( 'wp_health_check_last_enroll_error' );
			$thumb_error = get_option( 'wp_health_check_thumb_error' );

			WP_CLI::log( 'Versione agent: ' . WP_HEALTH_CHECK_VERSION );
			WP_CLI::log( 'Enrollment: ' . ( $is_enrolled ? 'registrato' : 'non registrato' ) );
			WP_CLI::log( 'Protocollo: ' . (int) get_option( 'wp_health_check_protocol', 1 ) );
			WP_CLI::log( 'Revocato: ' . ( get_option( 'wp_health_check_revoked_at' ) ? 'si\'' : 'no' ) );
			WP_CLI::log( 'Ultimo enroll fallito: ' . ( is_array( $last_error ) ? ( $last_error['code'] . ' — ' . $last_error['reason'] ) : 'nessuno' ) );
			WP_CLI::log( 'Thumbnail: ' . ( get_option( 'wp_health_check_thumb' ) ? 'presente' : ( is_array( $thumb_error ) ? ( 'errore: ' . $thumb_error['code'] ) : 'non ancora generata' ) ) );
		}
	}

	WP_CLI::add_command( 'health-check', 'WPHC_CLI_Command' );
}
