<?php
/**
 * Aggiornamento di plugin, temi e core via API: lock, preflight, esecuzione, riattivazione e rotte /update/*.
 *
 * @package WP_Health_Check
 */

/**
 * Aggiornamento di plugin, temi e core del sito su richiesta autenticata dell'hub, appoggiandosi agli upgrader
 * del core WordPress (Plugin_Upgrader, Theme_Upgrader, Core_Upgrader): la richiesta indica solo QUALE elemento
 * aggiornare, mai da dove ne' a quale versione.
 *
 * Raccoglie, dalla sezione M del file 1.38.1, il lock anti-concorrenza, i preflight (update e riattivazione),
 * l'esecuzione dell'update di un elemento e del core, la riconciliazione dei plugin riattivati e i callback REST
 * di POST /update/plugin, /update/theme, /update/core e /update/reactivate. Nessun hook proprio, ma due punti
 * registrano release_update_lock() con register_shutdown_function() (callback ad array) perche' il lock si
 * liberi anche se PHP muore a meta' di un update. perform_item_update() e perform_core_update() impostano il flag
 * "update avviato via API" tramite WPHC_Update_Log::begin_api_update() ed end_api_update(), a coppie attorno a
 * upgrade(), come faceva il codice con $GLOBALS.
 */
final class WPHC_Item_Updater {

	/**
	 * Rilascia il lock anti-concorrenza acquisito da WPHC_Item_Updater::update_preflight().
	 * Registrata anche come register_shutdown_function: cosi' il lock si
	 * libera comunque anche se PHP muore (timeout, fatal) a meta' di un
	 * update, invece di restare bloccato su 409 locked fino allo scadere
	 * naturale del TTL del transient.
	 */
	public static function release_update_lock() {
		delete_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK );
	}

	/**
	 * Percorso del file .maintenance nella root del sito: il core lo crea
	 * durante l'upgrade di plugin/temi/core per servire la pagina "in
	 * manutenzione" e lo rimuove al termine.
	 *
	 * @return string Percorso assoluto.
	 */
	public static function maintenance_file_path() {
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
	public static function clear_stale_maintenance( $max_age_seconds = 600 ) {
		$file = self::maintenance_file_path();
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
	private static function is_package_host_allowed( $package_url ) {
		if ( ! get_option( WPHC_Options::OPT_RESTRICT_OFFICIAL_ONLY, false ) ) {
			return true;
		}

		$host = wp_parse_url( (string) $package_url, PHP_URL_HOST );
		return in_array( $host, array( 'downloads.wordpress.org', 'api.wordpress.org' ), true );
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
	 * rilasciarlo con WPHC_Item_Updater::release_update_lock() su OGNI uscita successiva
	 * (il rilascio e' comunque garantito anche in caso di crash, vedi sopra).
	 *
	 * @param bool $requires_wp63 True per plugin/temi (richiede WP >= 6.3); false per il core.
	 * @return true|array True se si puo' procedere, altrimenti array-esito con
	 *                     chiavi 'result' e 'http' da restituire cosi' com'e'.
	 */
	private static function update_preflight( $requires_wp63 ) {
		WPHC_Auth::record_access();

		if ( ! get_option( WPHC_Options::OPT_UPDATES_ENABLED, true ) ) {
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

		if ( false !== get_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK ) ) {
			return array(
				'result' => 'locked',
				'http'   => 409,
			);
		}
		set_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK, 1, WP_HEALTH_CHECK_UPDATE_LOCK_TTL );
		register_shutdown_function( array( 'WPHC_Item_Updater', 'release_update_lock' ) );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			self::release_update_lock();
			return array(
				'result' => 'fs_method_unavailable',
				'http'   => 200,
			);
		}

		self::clear_stale_maintenance();
		WPHC_Update_Log::maybe_prune();

		return true;
	}

	/**
	 * Preambolo per POST /update/reactivate: solo accesso, kill-switch e lock
	 * anti-concorrenza (condiviso con le rotte di update, per non riattivare
	 * mentre un altro update e' in corso). Deliberatamente PIU' LEGGERO di
	 * WPHC_Item_Updater::update_preflight(): la riattivazione non scarica ne' scrive alcun
	 * pacchetto, quindi il requisito WP >= 6.3 e il preflight del filesystem
	 * ('direct') non si applicano qui.
	 *
	 * In caso di successo acquisisce il lock: il chiamante deve rilasciarlo con
	 * WPHC_Item_Updater::release_update_lock() su ogni uscita successiva (rilascio comunque
	 * garantito anche in caso di crash, vedi register_shutdown_function sotto).
	 *
	 * @return true|array True se si puo' procedere, altrimenti array-esito con
	 *                     chiavi 'result' e 'http' da restituire cosi' com'e'.
	 */
	private static function reactivate_preflight() {
		WPHC_Auth::record_access();

		if ( ! get_option( WPHC_Options::OPT_UPDATES_ENABLED, true ) ) {
			return array(
				'result' => 'disabled',
				'http'   => 403,
			);
		}

		if ( false !== get_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK ) ) {
			return array(
				'result' => 'locked',
				'http'   => 409,
			);
		}
		set_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK, 1, WP_HEALTH_CHECK_UPDATE_LOCK_TTL );
		register_shutdown_function( array( 'WPHC_Item_Updater', 'release_update_lock' ) );

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
	private static function maybe_invalidate_item_opcache( $type, $target ) {
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
	 * caricati qui, vedi WPHC_Util::mute_update_shortcircuit()), sovrascrivendo quello
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
	private static function patch_update_transient_after_success( $type, $target, $version_to ) {
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
	 * WPHC_Item_Updater::update_preflight()). Condivisa da WPHC_Item_Updater::route_update_plugin() e
	 * WPHC_Item_Updater::route_update_theme(): plugin e temi seguono esattamente lo stesso
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
	 * @return array Esito normalizzato, vedi WPHC_Item_Updater::map_item_update_outcome() per il mapping REST.
	 */
	public static function perform_item_update( $type, $target, $dry_run = false, $source = 'api' ) {
		$preflight = self::update_preflight( true );
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
				self::release_update_lock();
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
			$muted     = WPHC_Util::mute_update_shortcircuit();
			$transient = get_site_transient( 'update_plugins' );
			WPHC_Util::restore_update_shortcircuit( $muted );

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
				self::release_update_lock();
				return array(
					'result' => 'not_found',
					'http'   => 200,
				);
			}
			$name         = (string) $theme->get( 'Name' );
			$version_from = (string) $theme->get( 'Version' );

			wp_clean_themes_cache( false );
			$muted     = WPHC_Util::mute_update_shortcircuit();
			$transient = get_site_transient( 'update_themes' );
			WPHC_Util::restore_update_shortcircuit( $muted );

			// Le entry di update_themes->response sono ARRAY (a differenza dei
			// plugin sopra): stessa nota, e' una particolarita' del core.
			$update     = ( is_object( $transient ) && isset( $transient->response[ $target ] ) ) ? $transient->response[ $target ] : null;
			$package    = ( null !== $update && isset( $update['package'] ) ) ? (string) $update['package'] : '';
			$version_to = ( null !== $update && isset( $update['new_version'] ) ) ? (string) $update['new_version'] : '';
		}

		if ( null === $update ) {
			self::release_update_lock();
			return array(
				'result'  => 'up_to_date',
				'current' => $version_from,
				'http'    => 200,
			);
		}

		if ( '' === $package || ! self::is_package_host_allowed( $package ) ) {
			self::release_update_lock();
			return array(
				'result' => 'not_updatable',
				'detail' => __( 'pacchetto non ospitato su wordpress.org', 'wp-health-check' ),
				'http'   => 200,
			);
		}

		if ( $dry_run ) {
			self::release_update_lock();
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

		$correlation_id = WPHC_Util::generate_correlation_id();
		WPHC_Update_Log::log_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'requested', null, $was_active, $source );

		$skin     = new Automatic_Upgrader_Skin(); // Nessun output HTML: la richiesta e' REST, non una pagina admin.
		$upgrader = ( 'plugin' === $type ) ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );

		// Flag di richiesta (muore da sola a fine PHP, anche in caso di fatal):
		// upgrade() scatena gli stessi hook upgrader_pre_install/
		// upgrader_process_complete di un update fatto da wp-admin
		// (WPHC_Update_Log::log_wp_initiated_update()), che altrimenti loggerebbe due volte
		// lo stesso update.
		WPHC_Update_Log::begin_api_update();
		$result = $upgrader->upgrade( $target, array( 'clear_update_cache' => false ) );
		WPHC_Update_Log::end_api_update();

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

		self::maybe_invalidate_item_opcache( $type, $target );
		// Solo la cache della lista (get_plugins()/wp_get_themes() rileggono la
		// cartella), MAI wp_clean_plugins_cache( true )/wp_clean_themes_cache( true ):
		// cancellerebbero l'intero transient update_plugins/update_themes, non solo
		// la entry di $target, lasciando "0 aggiornamenti" per TUTTI i plugin/temi
		// finche' il cron non lo ripopola. La entry del solo elemento appena
		// aggiornato viene corretta chirurgicamente sotto, solo in caso di successo
		// (WPHC_Item_Updater::patch_update_transient_after_success()).
		if ( 'plugin' === $type ) {
			wp_clean_plugins_cache( false );
		} else {
			wp_clean_themes_cache( false );
		}
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE );

		if ( is_wp_error( $result ) || $actual_version !== $version_to ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Verifica post-aggiornamento fallita.', 'wp-health-check' );

			// Se l'elemento e' presente ed e' tornato alla versione originale,
			// il temp-backup nativo (WP 6.3+) ha gia' ripristinato con
			// successo: si registra 'rolled_back'. Altrimenti lo stato e'
			// incerto (elemento mancante o a una versione imprevista):
			// 'failed', da verificare manualmente sul sito.
			$phase = ( $exists && $actual_version === $version_from ) ? 'rolled_back' : 'failed';

			$log_id = WPHC_Update_Log::log_row( $correlation_id, $type, $target, $name, $version_from, $version_to, $phase, $message, null, $source );
			WPHC_Update_Log::record_last_update( $type, $target, $phase, $source );
			self::release_update_lock();

			return array(
				'result' => $phase,
				'detail' => $message,
				'log_id' => $log_id,
				'http'   => 200,
			);
		}

		self::patch_update_transient_after_success( $type, $target, $version_to );

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

		$log_id = WPHC_Update_Log::log_row( $correlation_id, $type, $target, $name, $version_from, $version_to, 'completed', $log_message, $active_after, $source );
		WPHC_Update_Log::record_last_update( $type, $target, 'completed', $source );
		self::release_update_lock();

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
	 * @return array Esito normalizzato, vedi WPHC_Item_Updater::map_item_update_outcome().
	 */
	public static function perform_core_update( $dry_run = false, $source = 'api' ) {
		$preflight = self::update_preflight( false );
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
			self::release_update_lock();
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
		if ( empty( $update->download ) || ! self::is_package_host_allowed( $update->download ) ) {
			self::release_update_lock();
			return array(
				'result' => 'not_updatable',
				'detail' => __( 'pacchetto non ospitato su wordpress.org', 'wp-health-check' ),
				'http'   => 200,
			);
		}

		if ( $dry_run ) {
			self::release_update_lock();
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

		$correlation_id = WPHC_Util::generate_correlation_id();
		WPHC_Update_Log::log_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, 'requested', null, null, $source );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );

		// Flag di richiesta: Core_Upgrader non passa da upgrader_pre_install, ma
		// WPHC_Update_Log::maybe_log_core_version_change() (gated su init) confronterebbe
		// comunque la versione con wp_health_check_core_version alla prossima
		// richiesta. Aggiornando qui sotto quell'opzione SUBITO dopo l'esito
		// reale (successo o fallimento) si evita quel doppio log a prescindere
		// dal flag; il flag resta qui solo per uniformita' con il ramo
		// plugin/tema sopra, nel caso in cui Core_Upgrader arrivi in futuro ad
		// agganciare hook condivisi.
		WPHC_Update_Log::begin_api_update();
		$result = $upgrader->upgrade( $update );
		WPHC_Update_Log::end_api_update();

		$actual_version = get_bloginfo( 'version' );

		// Sincronizza SEMPRE l'opzione con la versione reale osservata ora,
		// indipendentemente dall'esito: e' il valore che
		// WPHC_Update_Log::maybe_log_core_version_change() confrontera' al prossimo init,
		// cosi' un update gia' loggato qui (successo, fallito o rolled_back) non
		// viene ri-loggato come "divergenza" alla richiesta successiva.
		update_option( WPHC_Options::OPT_CORE_VERSION, $actual_version );

		if ( is_wp_error( $result ) || $actual_version !== $version_to ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Verifica post-aggiornamento fallita.', 'wp-health-check' );
			// Vedi nota sul rollback in cima alla funzione: qui la distinzione
			// rolled_back/failed si basa solo sulla versione osservata dopo il
			// tentativo, non su una garanzia esplicita del Core_Upgrader.
			$phase = ( $actual_version === $version_from ) ? 'rolled_back' : 'failed';

			$log_id = WPHC_Update_Log::log_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, $phase, $message, null, $source );
			WPHC_Update_Log::record_last_update( 'core', 'core', $phase, $source );
			self::release_update_lock();

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
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );

		$log_id = WPHC_Update_Log::log_row( $correlation_id, 'core', 'core', 'WordPress', $version_from, $version_to, 'completed', null, null, $source );
		WPHC_Update_Log::record_last_update( 'core', 'core', 'completed', $source );
		self::release_update_lock();

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
	 * WPHC_Item_Updater::perform_item_update() tenta una riattivazione immediata, ma non
	 * copre i casi in cui la disattivazione avviene con un ritardo, ad opera di
	 * un processo esterno, o quando l'update e' stato eseguito fuori da questo
	 * agent). Confronta lo stato "atteso attivo" secondo il log
	 * (WPHC_Update_Log::get_reactivation_candidates()) con lo stato reale corrente
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
	private static function perform_reactivate( $dry_run = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$candidates  = WPHC_Update_Log::get_reactivation_candidates();
		$all_plugins = get_plugins();

		// Discrepanza vera: candidato "atteso attivo" secondo l'ultima riga di
		// log non-null per quel target, ma risulta disattivato ORA.
		// is_plugin_active() e' l'unica fonte di verita' sullo stato reale.
		// I candidati il cui file non esiste piu' (cancellati fuori da WP: FTP,
		// pannello hosting) non sono riattivabili: finiscono in $missing.
		$discrepancies = array();
		$missing       = array();
		foreach ( $candidates as $candidate ) {
			if ( is_plugin_active( $candidate['target'] ) ) {
				continue;
			}

			if ( ! isset( $all_plugins[ $candidate['target'] ] ) ) {
				$missing[] = $candidate;
				continue;
			}

			$discrepancies[] = $candidate;
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

		$preflight = self::reactivate_preflight();
		if ( true !== $preflight ) {
			return $preflight;
		}

		// Plugin spariti dal disco: una riga 'deleted' (active=0) li chiude, cosi'
		// alla chiamata successiva non sono piu' candidati. Non finiscono in
		// $results/$failed: l'esito e' "nessuna discrepanza".
		foreach ( $missing as $candidate ) {
			WPHC_Update_Log::log_row( WPHC_Util::generate_correlation_id(), 'plugin', $candidate['target'], $candidate['name'], null, null, 'deleted', null, false );
		}

		$results     = array();
		$reactivated = 0;
		$failed      = 0;

		foreach ( $discrepancies as $candidate ) {
			// Nome/versione "vivi" da get_plugins(): qui il file esiste sempre.
			$target       = $candidate['target'];
			$name         = $all_plugins[ $target ]['Name'];
			$version_from = $all_plugins[ $target ]['Version'];

			$correlation_id = WPHC_Util::generate_correlation_id();
			$activation     = activate_plugin( $target, '', false );
			$now_active     = is_plugin_active( $target );

			if ( is_wp_error( $activation ) || ! $now_active ) {
				$message = is_wp_error( $activation )
					? $activation->get_error_message()
					: __( 'activate_plugin() non ha segnalato errori ma il plugin risulta ancora inattivo.', 'wp-health-check' );

				// active = null, NON false: se si scrivesse false, questa
				// diventerebbe la riga piu' recente non-null per il target e
				// WPHC_Update_Log::get_reactivation_candidates() smetterebbe di considerarlo
				// "atteso attivo" alla prossima chiamata, non ritentando piu' la
				// riattivazione. Con null resta valida l'ultima riga active=1
				// precedente: la discrepanza continua a essere rilevata.
				$log_id = WPHC_Update_Log::log_row( $correlation_id, 'plugin', $target, $name, $version_from, null, 'reactivation_failed', $message, null );

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

			$log_id = WPHC_Update_Log::log_row( $correlation_id, 'plugin', $target, $name, $version_from, null, 'reactivated', null, true );

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

		self::release_update_lock();

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
	 * Mappa l'array-esito interno (comune a WPHC_Item_Updater::perform_item_update() e
	 * WPHC_Item_Updater::perform_core_update()) nella risposta REST contrattuale: un
	 * WP_Error per gli status non-200 (403 disabled, 409 locked), altrimenti
	 * un 200 con "updated" true/false e il dettaglio dell'esito.
	 *
	 * @param array $outcome Esito interno con almeno le chiavi 'result' e 'http'.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function map_item_update_outcome( $outcome ) {
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
	 * file, mai una sorgente/versione), poi delega a WPHC_Item_Updater::perform_item_update().
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response|WP_Error Esito dell'update.
	 */
	public static function route_update_plugin( WP_REST_Request $request ) {
		$plugin = (string) $request->get_param( 'plugin' );
		if ( '' === trim( $plugin ) ) {
			return new WP_Error( 'wphc_missing_plugin', __( 'Campo "plugin" obbligatorio.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		$outcome = self::perform_item_update( 'plugin', $plugin, WPHC_Rest::request_wants_check( $request ) );

		return self::map_item_update_outcome( $outcome );
	}

	/**
	 * Callback REST di POST /update/theme: identica a WPHC_Item_Updater::route_update_plugin()
	 * con lo stylesheet come chiave.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response|WP_Error Esito dell'update.
	 */
	public static function route_update_theme( WP_REST_Request $request ) {
		$theme = (string) $request->get_param( 'theme' );
		if ( '' === trim( $theme ) ) {
			return new WP_Error( 'wphc_missing_theme', __( 'Campo "theme" obbligatorio.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		$outcome = self::perform_item_update( 'theme', $theme, WPHC_Rest::request_wants_check( $request ) );

		return self::map_item_update_outcome( $outcome );
	}

	/**
	 * Callback REST di POST /update/core: nessun campo obbligatorio nel
	 * payload, la versione target e' sempre quella che WordPress stesso ha
	 * determinato disponibile.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response|WP_Error Esito dell'update.
	 */
	public static function route_update_core( WP_REST_Request $request ) {
		$outcome = self::perform_core_update( WPHC_Rest::request_wants_check( $request ) );

		return self::map_item_update_outcome( $outcome );
	}

	// -----------------------------------------------------------------------
	// CALLBACK: POST /update/reactivate
	// -----------------------------------------------------------------------

	/**
	 * Callback REST di POST /update/reactivate: riconciliazione a posteriori
	 * dello stato attivo dei plugin (vedi WPHC_Item_Updater::perform_reactivate()). Rispetta
	 * il kill-switch e il lock anti-concorrenza come le altre rotte di
	 * scrittura, salvo in dry-run (?check=1) che resta pura lettura.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response|WP_Error Esito della riconciliazione.
	 */
	public static function route_reactivate( WP_REST_Request $request ) {
		$dry_run = WPHC_Rest::request_wants_check( $request );
		$outcome = self::perform_reactivate( $dry_run );

		$http = isset( $outcome['http'] ) ? (int) $outcome['http'] : 200;

		if ( 403 === $http ) {
			return new WP_Error( 'wphc_updates_disabled', __( 'Aggiornamenti via API disattivati per questo sito.', 'wp-health-check' ), array( 'status' => 403 ) );
		}
		if ( 409 === $http ) {
			return new WP_Error( 'wphc_update_locked', __( 'Un altro aggiornamento e\' gia\' in corso.', 'wp-health-check' ), array( 'status' => 409 ) );
		}

		return rest_ensure_response(
			array(
				'site'          => WPHC_Util::normalize_site_url(),
				'generated_at'  => gmdate( 'c' ),
				'check'         => $dry_run,
				'discrepancies' => $outcome['discrepancies'],
				'reactivated'   => $outcome['reactivated'],
				'failed'        => $outcome['failed'],
				'results'       => $outcome['results'],
			)
		);
	}
}
