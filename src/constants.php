<?php

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
	define( 'WP_HEALTH_CHECK_VERSION', '1.38.1' );
}

/** Coordinate del repository GitHub pubblico da cui arrivano le release. */
if ( ! defined( 'WP_HEALTH_CHECK_GH_OWNER' ) ) {
	define( 'WP_HEALTH_CHECK_GH_OWNER', 'mavidasnc' );
}
if ( ! defined( 'WP_HEALTH_CHECK_GH_REPO' ) ) {
	define( 'WP_HEALTH_CHECK_GH_REPO', 'wp-health-check' );
}

/**
 * Release servita dall'hub (dalla 1.38.0): prima fonte del self-update, con
 * ripiego su GitHub. L'hub interroga GitHub una volta e distribuisce la
 * release alla flotta, cosi' i siti sugli hosting condivisi non esauriscono
 * il limite dell'API GitHub per IP. La risposta ha la forma dell'API GitHub
 * (tag_name, assets[]). Non e' un perimetro di fiducia: SHA-256 e firma k2
 * sono verificati comunque. Sovrascrivibile in wp-config.php; stringa vuota
 * per disattivare la fonte hub. Stesso schema di WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL.
 */
if ( ! defined( 'WP_HEALTH_CHECK_RELEASE_URL' ) ) {
	define( 'WP_HEALTH_CHECK_RELEASE_URL', 'https://hub.mavida.com/api/v1/fleet/agent-release/latest' );
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
 * fallisce per URL mismatch (vedi WPHC_Enrollment::send_enroll_mismatch_alert). Non e'
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
 * WPHC_Update_Log::maybe_install_schema()).
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
 * impostato uno proprio in wp-admin (vedi WPHC_Webhook::url()) e non ha
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
 * WPHC_Autologin::maybe_consume()) la chiude comunque a zero dopo il primo uso.
 */
if ( ! defined( 'WP_HEALTH_CHECK_AUTOLOGIN_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_AUTOLOGIN_TTL', 20 );
}

/**
 * Chiave pubblica Ed25519 (kid "k2") DEDICATA alle release del plugin
 * (dalla 1.35.0, M-8 della review 2026-10-11). "k1" (WP_HEALTH_CHECK_CENTRAL_PUBKEY)
 * firma solo le buste del centro verso il sito (enroll, rotate, revoke);
 * "k2" firma solo le release: chi custodisce la chiave delle release non puo'
 * forgiare buste verso i siti, e chi custodisce k1 non puo' firmare release.
 * La separazione e' imposta da WPHC_Crypto::verify_central_signature() con $purpose.
 */
if ( ! defined( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2' ) ) {
	define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2', 'mGJDfcvmM2lCoaq5+lJaC94mhpvrwjqJIQfjJrC/6Ko=' );
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
 * quale WPHC_Auth::require_token() accetta ancora wp_health_check_token_prev.
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
 * sito (vedi WPHC_Thumbnail::generate()). Costante per permettere a un
 * singolo sito di puntare a un renderer diverso da wp-config.php, senza
 * toccare questo file. Dalla 1.30.0 (prima era inline nella funzione).
 */
if ( ! defined( 'WP_HEALTH_CHECK_THUMB_SERVICE' ) ) {
	define( 'WP_HEALTH_CHECK_THUMB_SERVICE', 'https://image.thum.io/get/noanimate/png/width/400/' );
}

/**
 * File del plugin: il mu-plugin distribuito. E' il file che WPHC_Self_Updater::install_release() sostituisce nel
 * self-update (backup .bak accanto, scrittura atomica con rename(), rilettura di controllo, invalidazione di
 * opcache). Nel file distribuito questo define() vale __FILE__ del mu-plugin: lo stesso valore che la 1.38.1
 * usava direttamente dentro la funzione. Il default __FILE__ e' corretto SOLO nel file costruito da bin/build.php
 * (nei file di src/ __FILE__ indicherebbe il file sorgente): per questo la build ammette __FILE__ e __DIR__
 * soltanto in questo file. Come le altre costanti e' sovrascrivibile (i test possono puntarla su una copia
 * temporanea), ma chi la ridefinisce deve rispettare due vincoli: il file indicato deve stare sullo stesso
 * filesystem di WPMU_PLUGIN_DIR, perche' il file temporaneo si crea li' e poi si fa rename() sul percorso della
 * costante (fra dispositivi diversi il rename fallisce), e il probe di scrittura controlla sempre
 * WPMU_PLUGIN_DIR, non la cartella del file indicato. La copia non deve mai essere un .php nella radice di
 * mu-plugins/: WordPress la caricherebbe al giro successivo ("Cannot declare class").
 */
if ( ! defined( 'WP_HEALTH_CHECK_FILE' ) ) {
	define( 'WP_HEALTH_CHECK_FILE', __FILE__ );
}
