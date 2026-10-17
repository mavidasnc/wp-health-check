<?php
/**
 * Log degli update (tabella wphc_update_log), audit degli hook dell'upgrader e rotta GET /update/log.
 *
 * @package WP_Health_Check
 */

/**
 * Log persistente delle operazioni di update (tabella {prefix}wphc_update_log) e audit degli aggiornamenti,
 * delle disattivazioni e delle cancellazioni che WordPress esegue per conto proprio (wp-admin, WP-CLI, cron).
 *
 * Raccoglie, dalla sezione M del file 1.38.1, il nome e lo schema della tabella (dbDelta), l'inserimento e la
 * lettura con filtri, la potatura, il rilevamento di chi ha avviato l'operazione (api, wp-admin, cron, wp-cli),
 * i callback degli hook dell'upgrader e della versione del core e il callback REST GET /update/log.
 * Hook serviti (registrati per ora in legacy.php, dal lotto 4 in WPHC_Plugin::boot()): init (schema della
 * tabella, PRIMO dei quattro callback del plugin; versione del core, SECONDO), upgrader_pre_install,
 * upgrader_process_complete, deactivated_plugin, delete_plugin e deleted_plugin.
 *
 * Stato: le tre variabili che nel file 1.38.1 vivevano in $GLOBALS (wphc_version_snapshots,
 * wphc_api_update_in_progress, wphc_deleting_plugins) sono proprieta' statiche private di questa classe. Il flag
 * dell'update avviato via API lo imposta WPHC_Item_Updater con begin_api_update() ed end_api_update(): e' l'unica
 * aggiunta di API rispetto al codice 1.38.1, necessaria perche' scrittore e lettore stanno in classi diverse.
 */
final class WPHC_Update_Log {

	/**
	 * Versioni di plugin e temi lette PRIMA di un upgrade ( upgrader_pre_install ) e rilette a upgrade finito
	 * ( upgrader_process_complete ): chiave 'plugin' o 'theme', poi il file del plugin o lo stylesheet del tema.
	 * Vive per la sola richiesta; la chiave si toglie dopo la lettura. Nel file 1.38.1 era
	 * la chiave wphc_version_snapshots di $GLOBALS.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $version_snapshots = array();

	/**
	 * True mentre un update avviato dalla REST API di questo agent e' in corso: i callback degli hook dell'upgrader
	 * non devono loggare una seconda volta (il flusso API scrive le sue righe). Se upgrade() muore con un fatal il
	 * flag resta impostato fino a fine richiesta, come con la chiave wphc_api_update_in_progress di $GLOBALS nel file 1.38.1
	 * (dove si rimuoveva con unset(): empty() da' lo stesso esito di false).
	 *
	 * @var bool
	 */
	private static $api_update_in_progress = false;

	/**
	 * Nome e versione dei plugin in cancellazione, catturati prima che il file sparisca ( delete_plugin ) e letti a
	 * cancellazione avvenuta ( deleted_plugin ): chiave il plugin file. Nel file 1.38.1 era
	 * la chiave wphc_deleting_plugins di $GLOBALS.
	 *
	 * @var array<string,array{name:string,version:string}>
	 */
	private static $deleting_plugins = array();

	/**
	 * Segna l'inizio di un update avviato dalla REST API: da chiamare subito prima di upgrade(), in coppia con
	 * end_api_update(). Sostituisce la scrittura del flag in $GLOBALS del file 1.38.1.
	 */
	public static function begin_api_update() {
		self::$api_update_in_progress = true;
	}

	/**
	 * Segna la fine di un update avviato dalla REST API: da chiamare subito dopo upgrade(). Sostituisce l'unset() del
	 * flag in $GLOBALS del file 1.38.1.
	 */
	public static function end_api_update() {
		self::$api_update_in_progress = false;
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
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . WPHC_Options::TABLE_UPDATE_LOG;
	}

	/**
	 * Crea/allinea la tabella di log degli update con dbDelta(), solo quando
	 * lo schema installato (wp_health_check_db_version) non combacia con
	 * quello atteso. I mu-plugin non hanno hook di attivazione: questo e' il
	 * sostituto, gated da un'opzione autoloaded cosi' il controllo resta O(1)
	 * a ogni richiesta salvo la prima dopo un deploy o un bump di schema.
	 */
	public static function maybe_install_schema() {
		if ( get_option( WPHC_Options::OPT_DB_VERSION ) === WP_HEALTH_CHECK_DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table();
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

		update_option( WPHC_Options::OPT_DB_VERSION, WP_HEALTH_CHECK_DB_VERSION );
	}

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
	 *                                    WPHC_Autologin::route_token()/WPHC_Autologin::maybe_consume()).
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
	 *                                    solo da WPHC_Item_Updater::perform_reactivate()) | 'deactivated' |
	 *                                    'deleted' (plugin disattivato/cancellato, da hook o dalla
	 *                                    rete di sicurezza di WPHC_Item_Updater::perform_reactivate());
	 *                                    'token'/'login' riusano
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
	public static function log_row( $correlation_id, $type, $target, $name, $version_from, $version_to, $phase, $message = null, $active = null, $source = 'api', $actor = null ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
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
				'ip'             => WPHC_Util::get_client_ip(),
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
	 * Lo stesso vale per le righe active=0 delle fasi 'deactivated' e 'deleted'
	 * (hook su deactivated_plugin/deleted_plugin): disattivazioni e cancellazioni
	 * intenzionali chiudono il candidato.
	 * Non confronta con lo stato reale corrente: quello e' compito del chiamante
	 * (WPHC_Item_Updater::perform_reactivate()), qui si restituisce solo l'atteso secondo il log.
	 *
	 * @return array<int, array{target: string, name: string}> Plugin attesi attivi secondo il log.
	 */
	public static function get_reactivation_candidates() {
		global $wpdb;
		$table = self::table();

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
	public static function maybe_prune() {
		if ( false !== get_transient( WPHC_Options::TRANSIENT_LOG_PRUNED_AT ) ) {
			return;
		}

		global $wpdb;
		$table     = self::table();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( WP_HEALTH_CHECK_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabella custom non cacheata dall'object cache di WP; $table e' un nome fisso ($wpdb->prefix), non input; $threshold e' comunque passato via prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold ) );

		set_transient( WPHC_Options::TRANSIENT_LOG_PRUNED_AT, time(), DAY_IN_SECONDS );
	}

	/**
	 * Aggiorna wp_health_check_last_update (opzione autoloaded) con l'esito
	 * dell'ultima operazione di update. Usata da /health (§9 della specifica)
	 * per esporre "last_update" senza una query alla tabella di log nel path
	 * di polling frequente, che deve restare O(1) (vedi WPHC_Health::route_health()).
	 *
	 * @param string $type   'plugin' | 'theme' | 'core'.
	 * @param string $target Plugin file, stylesheet, oppure 'core'.
	 * @param string $phase  'completed' | 'failed' | 'rolled_back'.
	 * @param string $source 'api' | 'wp-admin' | 'cron' | 'wp-cli'. Default 'api' per non
	 *                       toccare le chiamate esistenti dal flusso REST di update.
	 */
	public static function record_last_update( $type, $target, $phase, $source = 'api' ) {
		update_option(
			WPHC_Options::OPT_LAST_UPDATE,
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
	 * (quel caso resta sempre 'api', valore di default di WPHC_Update_Log::log_row()).
	 * Distingue WP-CLI e i cron (dove ricadono gli auto-update in background di
	 * WordPress) da un'azione avviata a mano in wp-admin.
	 *
	 * @return string 'wp-cli' | 'cron' | 'wp-admin'.
	 */
	public static function detect_update_source() {
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
	private static function current_actor() {
		$user = wp_get_current_user();

		return ( $user instanceof WP_User && $user->exists() ) ? $user->user_login : null;
	}

	/**
	 * Cattura la versione installata di un plugin/tema PRIMA che
	 * Plugin_Upgrader/Theme_Upgrader la sovrascriva, cosi' WPHC_Update_Log::log_wp_initiated_update()
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
	public static function snapshot_version_before_upgrade( $response, $hook_extra ) {
		if ( ! empty( $hook_extra['plugin'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugin_file = WP_PLUGIN_DIR . '/' . $hook_extra['plugin'];
			if ( is_readable( $plugin_file ) ) {
				$data = get_plugin_data( $plugin_file, false, false );
				self::$version_snapshots['plugin'][ $hook_extra['plugin'] ] = $data['Version'];
			}
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$theme = wp_get_theme( $hook_extra['theme'] );
			if ( $theme->exists() ) {
				self::$version_snapshots['theme'][ $hook_extra['theme'] ] = (string) $theme->get( 'Version' );
			}
		}

		return $response;
	}

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
	public static function log_wp_initiated_update( $upgrader, $hook_extra ) {
		unset( $upgrader );

		// Un update avviato da POST /update/plugin|/update/theme (che condivide
		// Plugin_Upgrader/Theme_Upgrader) fa scattare comunque questo hook: il
		// flag impostato attorno a $upgrader->upgrade() in
		// WPHC_Item_Updater::perform_item_update() segnala di ignorarlo, altrimenti l'update
		// risulterebbe loggato due volte.
		if ( ! empty( self::$api_update_in_progress ) ) {
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

		$source = self::detect_update_source();
		$actor  = self::current_actor();

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
				// bulk update: per convenzione (vedi WPHC_Update_Log::log_row())
				// 'active' resta sempre null fuori dal caso plugin.
				$active = null;
			}

			$version_from = isset( self::$version_snapshots[ $type ][ $target ] )
				? self::$version_snapshots[ $type ][ $target ]
				: null;
			unset( self::$version_snapshots[ $type ][ $target ] );

			$correlation_id = WPHC_Util::generate_correlation_id();
			self::log_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'completed', null, $active, $source, $actor );
			self::record_last_update( $type, $target, 'completed', $source );
		}
	}

	/**
	 * Registra la disattivazione di un plugin come scelta intenzionale, con una
	 * riga 'deactivated' e active=0: e' l'ultima riga non-null del target, quindi
	 * WPHC_Update_Log::get_reactivation_candidates() smette di considerarlo "atteso attivo" e
	 * il reactivate non lo riattiva piu'.
	 *
	 * Plugin_Upgrader disattiva in modalita' *silent* (deactivate_plugins( $p, true )),
	 * che non fa scattare 'deactivated_plugin': gli update non vengono quindi
	 * scambiati per disattivazioni volute. Una disattivazione non-silent fatta da
	 * codice terzo e' invece trattata come voluta (compromesso accettato).
	 *
	 * @param string $plugin       Plugin file (es. 'akismet/akismet.php').
	 * @param bool   $network_wide Non usato.
	 */
	public static function log_plugin_deactivation( $plugin, $network_wide ) {
		unset( $network_wide );

		// Per sicurezza: durante un update eseguito dall'agent nessuna
		// disattivazione va considerata intenzionale.
		if ( ! empty( self::$api_update_in_progress ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Nome e versione dal file, con fallback al plugin file se illeggibile.
		$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
		$data        = is_readable( $plugin_file ) ? get_plugin_data( $plugin_file, false, false ) : array();
		$name        = ! empty( $data['Name'] ) ? $data['Name'] : $plugin;
		$version     = ! empty( $data['Version'] ) ? $data['Version'] : null;

		self::log_row( WPHC_Util::generate_correlation_id(), 'plugin', $plugin, $name, $version, null, 'deactivated', null, false, self::detect_update_source(), self::current_actor() );
	}

	/**
	 * Cattura nome e versione di un plugin PRIMA della cancellazione: a
	 * 'deleted_plugin' il file non esiste piu' e non sarebbero leggibili.
	 *
	 * @param string $plugin_file Plugin file in cancellazione.
	 */
	public static function snapshot_plugin_before_delete( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( is_readable( $path ) ) {
			$data                                   = get_plugin_data( $path, false, false );
			self::$deleting_plugins[ $plugin_file ] = array(
				'name'    => $data['Name'],
				'version' => $data['Version'],
			);
		}
	}

	/**
	 * Registra la cancellazione di un plugin (riga 'deleted', active=0), per
	 * audit. WP permette di cancellare solo plugin inattivi, quindi di norma
	 * esiste gia' la riga 'deactivated'; questa chiude comunque il ciclo di vita.
	 *
	 * @param string $plugin_file Plugin file cancellato.
	 * @param bool   $deleted     True se la cancellazione e' riuscita.
	 */
	public static function log_plugin_deletion( $plugin_file, $deleted ) {
		$snapshot = isset( self::$deleting_plugins[ $plugin_file ] ) ? self::$deleting_plugins[ $plugin_file ] : array();
		unset( self::$deleting_plugins[ $plugin_file ] );

		if ( ! $deleted ) {
			return;
		}

		$name    = ! empty( $snapshot['name'] ) ? $snapshot['name'] : $plugin_file;
		$version = ! empty( $snapshot['version'] ) ? $snapshot['version'] : null;

		self::log_row( WPHC_Util::generate_correlation_id(), 'plugin', $plugin_file, $name, $version, null, 'deleted', null, false, self::detect_update_source(), self::current_actor() );
	}

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
	 * da WPHC_Update_Log::maybe_install_schema().
	 */
	public static function maybe_log_core_version_change() {
		$seen = (string) get_option( WPHC_Options::OPT_CORE_VERSION, '' );
		$now  = (string) get_bloginfo( 'version' );

		if ( '' === $seen ) {
			// Prima esecuzione dopo il deploy di questa versione dell'agent: si
			// limita a "seminare" il valore, senza loggare una falsa divergenza
			// rispetto al nulla.
			update_option( WPHC_Options::OPT_CORE_VERSION, $now );
			return;
		}

		if ( $seen === $now ) {
			return;
		}

		// WPHC_Item_Updater::perform_core_update() aggiorna gia' questa opzione subito dopo un
		// update avviato via API: se si arriva qui con una divergenza reale, e'
		// per costruzione un update NON passato da quel flusso.
		update_option( WPHC_Options::OPT_CORE_VERSION, $now );

		$source         = self::detect_update_source();
		$correlation_id = WPHC_Util::generate_correlation_id();
		self::log_row( $correlation_id, 'core', 'core', 'WordPress', $seen, $now, 'completed', null, null, $source, self::current_actor() );
		self::record_last_update( 'core', 'core', 'completed', $source );
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
	public static function get_entries( $type, $source, $limit, $offset ) {
		global $wpdb;
		$table = self::table();

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
				// created_at e' salvato in UTC (gmdate) da WPHC_Update_Log::log_row(): il
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
	 * (nessuna chiamata a WPHC_Item_Updater::update_preflight() qui, deliberatamente). La
	 * query vera e propria e' in WPHC_Update_Log::get_entries().
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response
	 */
	public static function route_update_log( WP_REST_Request $request ) {
		WPHC_Auth::record_access();

		$result = self::get_entries(
			(string) $request->get_param( 'type' ),
			(string) $request->get_param( 'source' ),
			(int) $request->get_param( 'limit' ),
			(int) $request->get_param( 'offset' )
		);

		return rest_ensure_response(
			array(
				'site'    => WPHC_Util::normalize_site_url(),
				'count'   => count( $result['entries'] ),
				'total'   => $result['total'],
				'entries' => $result['entries'],
			)
		);
	}
}
