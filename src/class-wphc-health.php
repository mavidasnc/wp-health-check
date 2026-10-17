<?php
/**
 * Rotte di salute: /health, /ping, /detail/plugins, /detail/theme, /detail/server, stato di auto-update e cache.
 *
 * @package WP_Health_Check
 */

/**
 * Le rotte "di lettura" principali del fleet agent: GET /health (economica, O(1), con micro-cache di 60
 * secondi), GET /ping, GET /detail/plugins, /detail/theme e /detail/server (cache di un'ora, con ?fresh=1
 * per forzare), piu' gli helper che le servono: stato di auto-update del core, di plugin e temi, segnali
 * del sito (GDPR, builder, e-commerce) e i tre flush delle cache.
 *
 * Raccoglie la sezione K del file 1.38.1 (tranne /detail/users, che sta in WPHC_Users) e, dalla sezione C,
 * l'auto-update, i flush e detect_site_signals(). Hook serviti (registrati
 * in WPHC_Plugin::boot()): i 20 hook update_option_/add_option_ sulle option auto_update_* che
 * invalidano le cache. route_health() ha effetti collaterali: puo' generare la thumbnail (chiamata
 * remota) e chiamare api.ipify.org, e scrive la micro-cache.
 *
 * Stato: la cache per richiesta dello stato di auto-update del core, che nel file 1.38.1 era una
 * `static $cache` dentro la funzione core_auto_update_state(), e' la proprieta' statica privata $core_auto_update_state.
 */
final class WPHC_Health {

	/**
	 * Cache per richiesta dello stato di auto-update del core: nel file 1.38.1 era la `static $cache` dentro
	 * la funzione core_auto_update_state(). Calcolata una sola volta per processo PHP (nei test PHPUnit persiste fra un
	 * test e l'altro, esattamente come la variabile statica di funzione); null = non ancora calcolata.
	 * Si legge e si scrive solo in WPHC_Health::core_auto_update_state().
	 *
	 * @var array{enabled: bool, level: string, blocked_by: string|null}|null
	 */
	private static $core_auto_update_state = null;

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
	private static function core_auto_update_state() {
		if ( null !== self::$core_auto_update_state ) {
			return self::$core_auto_update_state;
		}

		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			self::$core_auto_update_state = array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => 'file_mods',
			);
			return self::$core_auto_update_state;
		}

		$disabled = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED;
		if ( (bool) apply_filters( 'automatic_updater_disabled', $disabled ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- si legge l'hook core, non se ne dichiara uno nuovo.
			self::$core_auto_update_state = array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => 'automatic_updater_disabled',
			);
			return self::$core_auto_update_state;
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

		self::$core_auto_update_state = array(
			'enabled'    => ( $minor || $major ),
			'level'      => $level,
			'blocked_by' => null,
		);

		return self::$core_auto_update_state;
	}

	/**
	 * Wrapper sottile sull'helper core wp_is_auto_update_enabled_for_type(), che
	 * a differenza di WPHC_Health::core_auto_update_state() sopra E' corretto per
	 * 'plugin'/'theme' (per 'core' restituirebbe sempre false: il core non ha
	 * un case per quel tipo). Include il file solo se non gia' incluso da chi
	 * chiama (le rotte /health e /detail/* lo includono gia').
	 *
	 * @param string $type 'plugin' | 'theme'.
	 * @return bool
	 */
	private static function auto_update_enabled_for( $type ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		return (bool) wp_is_auto_update_enabled_for_type( $type );
	}

	/**
	 * Stato di auto-update effettivo per un singolo tema, con la stessa
	 * distinzione stored/forced di /detail/plugins (vedi WPHC_Health::route_detail_plugins()):
	 * null = si applica la scelta salvata in auto_update_themes; true|false = un
	 * filtro di terze parti impone lo stato indipendentemente dalla option.
	 *
	 * @param string $stylesheet         Stylesheet del tema.
	 * @param array  $auto_update_themes Contenuto gia' letto di get_site_option('auto_update_themes').
	 * @return array{auto_update: bool, auto_update_forced: bool|null}
	 */
	private static function theme_auto_update_state( $stylesheet, array $auto_update_themes ) {
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
	public static function flush_plugins_detail_cache() {
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
	}

	/** Equivalente di WPHC_Health::flush_plugins_detail_cache() per i temi. */
	public static function flush_theme_detail_cache() {
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
	}

	/** Invalida la sola micro-cache di /health (per le option auto-update del core). */
	public static function flush_health_cache_only() {
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
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
	private static function detect_site_signals() {
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
	public static function route_health( WP_REST_Request $request ) {
		// Il tracciamento accessi avviene sempre, anche quando la risposta
		// sotto e' poi servita dalla cache: altrimenti, con un polling piu'
		// frequente del TTL di cache, l'audit perderebbe la maggior parte
		// delle chiamate realmente ricevute.
		$previous_access = WPHC_Auth::record_access();

		$fresh = WPHC_Rest::request_wants_fresh( $request );

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
			$cached = get_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
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
		// registrano fuori dall'admin (vedi WPHC_Util::mute_update_shortcircuit): senza
		// questo, in contesto REST i conteggi risulterebbero 0 anche con
		// aggiornamenti reali.
		$muted_updates            = WPHC_Util::mute_update_shortcircuit();
		$update_plugins_transient = get_site_transient( 'update_plugins' );
		$plugins_updates_count    = ( is_object( $update_plugins_transient ) && ! empty( $update_plugins_transient->response ) )
			? count( $update_plugins_transient->response )
			: 0;

		$update_themes_transient = get_site_transient( 'update_themes' );
		$themes_updates_count    = ( is_object( $update_themes_transient ) && ! empty( $update_themes_transient->response ) )
			? count( $update_themes_transient->response )
			: 0;

		$core_updates = get_core_updates( array( 'dismissed' => false ) );
		WPHC_Util::restore_update_shortcircuit( $muted_updates );

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

		$server_ip = WPHC_Util::get_server_ip();
		$public_ip = WPHC_Util::get_public_ip();

		// Segnali booleani (consent manager GDPR, page builder) calcolati dai
		// plugin/temi attivi. Operazioni O(1) su liste gia' disponibili: coerente
		// col contratto "economico" di /health.
		$signals = self::detect_site_signals();

		// Aggiornamento plugin/temi/core via API (vedi sezione dedicata piu'
		// sotto nel file): tre letture O(1) (due opzioni, un file_exists),
		// coerenti col contratto economico di questa rotta.
		$maintenance_file  = WPHC_Item_Updater::maintenance_file_path();
		$maintenance_stuck = file_exists( $maintenance_file ) && ( time() - (int) filemtime( $maintenance_file ) ) > 600;
		$last_update       = get_option( WPHC_Options::OPT_LAST_UPDATE );
		$core_auto         = self::core_auto_update_state();

		// Screenshot del sito (thum.io): generato e caricato nel Media Library
		// una sola volta, alla prima /health con opzione vuota. Chiamate
		// successive leggono solo wp_health_check_thumb (O(1)); vedi
		// WPHC_Thumbnail::maybe_generate() per la guardia anti-retry-loop.
		$thumbnail = WPHC_Thumbnail::maybe_generate();
		// Solo il codice, non il messaggio completo (che puo' contenere l'esito
		// grezzo di un WP_Error di rete): il payload di /health e' polled di
		// frequente e non deve gonfiarsi con diagnostica estesa, disponibile
		// invece per intero su GET /thumbnail e nella tab Site Health.
		$thumbnail_error = get_option( WPHC_Options::OPT_THUMB_ERROR );

		$payload = array(
			'site'                => WPHC_Util::normalize_site_url(),
			'generated_at'        => gmdate( 'c' ),
			'fleet_agent_version' => WP_HEALTH_CHECK_VERSION,
			'summary'             => array(
				'wp_version'                  => get_bloginfo( 'version' ),
				'php_version'                 => PHP_VERSION,
				'php_memory_limit'            => (string) ini_get( 'memory_limit' ),
				'server_ip'                   => '' !== $server_ip ? $server_ip : null,
				'server_ip_is_private'        => '' !== $server_ip && ! WPHC_Util::ip_is_public( $server_ip ),
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
				'plugins_auto_update_enabled' => self::auto_update_enabled_for( 'plugin' ),
				'themes_auto_update_enabled'  => self::auto_update_enabled_for( 'theme' ),
				'bulk_update'                 => WPHC_Bulk::summary(),
				'has_gdpr'                    => $signals['has_gdpr'],
				'has_builder'                 => $signals['has_builder'],
				'has_ecommerce'               => $signals['has_ecommerce'],
				'mu_dir_writable'             => (bool) wp_is_writable( WPMU_PLUGIN_DIR ),
				'updates_checked_at'          => $updates_checked_at,
				'updates_via_api_enabled'     => (bool) get_option( WPHC_Options::OPT_UPDATES_ENABLED, true ),
				'restrict_official_only'      => (bool) get_option( WPHC_Options::OPT_RESTRICT_OFFICIAL_ONLY, false ),
				'last_update'                 => $last_update ? $last_update : null,
				'maintenance_stuck'           => $maintenance_stuck,
				'thumbnail'                   => $thumbnail ? $thumbnail : null,
				'thumbnail_error'             => ( ! $thumbnail && is_array( $thumbnail_error ) && ! empty( $thumbnail_error['code'] ) ) ? $thumbnail_error['code'] : null,
			),
			'last_access'         => array(
				'at'          => $previous_access['at'],
				'ip'          => $previous_access['ip'],
				'enrolled_at' => get_option( WPHC_Options::OPT_ENROLLED_AT ) ? get_option( WPHC_Options::OPT_ENROLLED_AT ) : null,
			),
			'detail_routes'       => array(
				'plugins' => rest_url( 'health-check/v1/detail/plugins' ),
				'theme'   => rest_url( 'health-check/v1/detail/theme' ),
				'server'  => rest_url( 'health-check/v1/detail/server' ),
			),
		);

		// Micro-cache breve: pensata per assorbire polling ravvicinato senza
		// impedire che un aggiornamento reale sia visibile entro un minuto.
		set_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE, $payload, 60 );

		return rest_ensure_response( $payload );
	}

	// -----------------------------------------------------------------------
	// CALLBACK: GET /ping
	// -----------------------------------------------------------------------

	/**
	 * Heartbeat volutamente minimale: pensato per un polling ad alta frequenza
	 * (uptime + tempo di risposta) senza pagare il costo di /health. A
	 * differenza di WPHC_Health::route_health(), qui non si chiama MAI get_plugins()/
	 * wp_get_themes() (le uniche due operazioni realmente O(n), con scansione
	 * di filesystem, dell'intera rotta /health), e non si scrive mai su
	 * wp_options: si salta deliberatamente WPHC_Auth::record_access(), perche' un
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
	public static function route_ping( WP_REST_Request $request ) {
		unset( $request );

		return rest_ensure_response(
			array(
				'status'        => 'ok',
				'site'          => WPHC_Util::normalize_site_url(),
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
	public static function route_detail_plugins( WP_REST_Request $request ) {
		WPHC_Auth::record_access();

		$fresh = WPHC_Rest::request_wants_fresh( $request );
		if ( ! $fresh ) {
			$cached = get_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE );
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
		// update fuori dall'admin (vedi WPHC_Util::mute_update_shortcircuit).
		$muted_updates  = WPHC_Util::mute_update_shortcircuit();
		$plugin_updates = get_plugin_updates();
		WPHC_Util::restore_update_shortcircuit( $muted_updates );
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
			// Vedi la nota su WPHC_Health::theme_auto_update_state(): lo stub PHPStan
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
			'site'                         => WPHC_Util::normalize_site_url(),
			'generated_at'                 => gmdate( 'c' ),
			'count'                        => count( $items ),
			'auto_update_enabled_globally' => self::auto_update_enabled_for( 'plugin' ),
			'plugins'                      => $items,
		);

		set_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE, $payload, HOUR_IN_SECONDS );

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
	public static function route_detail_theme( WP_REST_Request $request ) {
		WPHC_Auth::record_access();

		$fresh = WPHC_Rest::request_wants_fresh( $request );
		if ( ! $fresh ) {
			$cached = get_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE );
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
		$muted_updates = WPHC_Util::mute_update_shortcircuit();
		$theme_updates = get_theme_updates();
		WPHC_Util::restore_update_shortcircuit( $muted_updates );
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
		// in WPHC_Health::route_detail_plugins() qui sopra.
		$auto_update_themes = (array) get_site_option( 'auto_update_themes', array() );

		$active_auto_update = self::theme_auto_update_state( $stylesheet, $auto_update_themes );

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
			$parent_auto_update   = self::theme_auto_update_state( $parent_stylesheet, $auto_update_themes );
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
			$item_auto_update = self::theme_auto_update_state( (string) $theme_stylesheet, $auto_update_themes );

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
			'site'                         => WPHC_Util::normalize_site_url(),
			'generated_at'                 => gmdate( 'c' ),
			'auto_update_enabled_globally' => self::auto_update_enabled_for( 'theme' ),
			'active_theme'                 => $active_theme_payload,
			'parent_theme'                 => $parent_theme_payload,
			'themes'                       => $themes,
		);

		set_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE, $payload, HOUR_IN_SECONDS );

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
	public static function route_detail_server( WP_REST_Request $request ) {
		WPHC_Auth::record_access();

		$fresh = WPHC_Rest::request_wants_fresh( $request );
		if ( ! $fresh ) {
			$cached = get_transient( WPHC_Options::TRANSIENT_DETAIL_SERVER_CACHE );
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
		$server_ip = WPHC_Util::get_server_ip();
		$public_ip = WPHC_Util::get_public_ip();

		$payload = array(
			'site'         => WPHC_Util::normalize_site_url(),
			'generated_at' => gmdate( 'c' ),
			'server'       => array(
				'software'             => $software,
				'server_ip'            => '' !== $server_ip ? $server_ip : null,
				'server_ip_is_private' => '' !== $server_ip && ! WPHC_Util::ip_is_public( $server_ip ),
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

		set_transient( WPHC_Options::TRANSIENT_DETAIL_SERVER_CACHE, $payload, 12 * HOUR_IN_SECONDS );

		return rest_ensure_response( $payload );
	}
}
