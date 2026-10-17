<?php
/**
 * Registrazione di tutti gli hook di WordPress del plugin.
 *
 * @package WP_Health_Check
 */

/**
 * Punto unico in cui il plugin si aggancia a WordPress.
 *
 * Nel file 1.38.1 le chiamate add_action() e add_filter() stavano al livello del file, sparse fra le funzioni;
 * ora le fa tutte boot(), nello stesso identico ordine. L'ordine conta: per ogni hook e priorita' WordPress esegue i
 * callback nell'ordine di registrazione, e i quattro callback del plugin su init (schema del log, versione del core,
 * ri-armo del bulk, consumo dell'autologin) dipendono l'uno dall'altro. Le rotte REST non si registrano qui ma
 * dentro WPHC_Rest::register_routes(), agganciata a rest_api_init; il comando WP-CLI sta nel proprio file.
 */
final class WPHC_Plugin {

	/**
	 * Registra tutti gli hook del plugin, nello stesso ordine del file 1.38.1.
	 *
	 * Va invocata una sola volta, al livello del file (boot.php, in coda alle classi), durante l'inclusione del
	 * mu-plugin: prima di muplugins_loaded e quindi prima di qualunque plugin normale. Non va mai spostata dentro un
	 * altro hook (per esempio muplugins_loaded o plugins_loaded): cambierebbe l'ordine relativo dei nostri callback
	 * rispetto a quelli di terzi con la stessa priorita'.
	 *
	 * @return void
	 */
	public static function boot() {
		// 1. Invalidazione delle cache dei payload quando cambiano le option di auto-update di plugin, temi e core.
		// Sono 20 registrazioni: 4 per plugin, 4 per temi e 4 per ciascuna delle tre option del core (dev, minor,
		// major).
		// Entrambe le famiglie servono: su multisite update_site_option() e' quella
		// che effettivamente scrive (i suoi hook update_option_*/add_option_* NON
		// scattano li'); su single-site update_site_option() ricade su
		// update_option() e sono invece gli hook update_option_*/add_option_* a
		// scattare. Registrare solo una famiglia lascerebbe l'altro contesto stantio.
		add_action( 'update_site_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
		add_action( 'add_site_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
		add_action( 'update_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
		add_action( 'add_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
		add_action( 'update_site_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
		add_action( 'add_site_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
		add_action( 'update_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
		add_action( 'add_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
		foreach ( array( 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' ) as $core_auto_option ) {
			add_action( "update_site_option_{$core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
			add_action( "add_site_option_{$core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
			add_action( "update_option_{$core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
			add_action( "add_option_{$core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
		}

		// 2. CORS e rotte REST: i filtri sulla risposta REST e sul preflight, poi la registrazione delle rotte.

		// -----------------------------------------------------------------------
		// CORS
		// -----------------------------------------------------------------------

		add_filter( 'rest_pre_serve_request', array( 'WPHC_Cors', 'reassert_headers' ), 20, 3 );

		add_filter( 'rest_pre_dispatch', array( 'WPHC_Cors', 'handle_options_preflight' ), 10, 3 );

		// -----------------------------------------------------------------------
		// REGISTRAZIONE ROTTE REST
		// -----------------------------------------------------------------------

		add_action( 'rest_api_init', array( 'WPHC_Rest', 'register_routes' ) );

		// 3. Schema della tabella di log e audit degli aggiornamenti. Primo e secondo callback su init, in questo ordine:
		// prima lo schema, poi il confronto con la versione del core, che scrive nella tabella.
		add_action( 'init', array( 'WPHC_Update_Log', 'maybe_install_schema' ) );

		add_filter( 'upgrader_pre_install', array( 'WPHC_Update_Log', 'snapshot_version_before_upgrade' ), 10, 2 );

		add_action( 'upgrader_process_complete', array( 'WPHC_Update_Log', 'log_wp_initiated_update' ), 10, 2 );

		add_action( 'deactivated_plugin', array( 'WPHC_Update_Log', 'log_plugin_deactivation' ), 10, 2 );

		add_action( 'delete_plugin', array( 'WPHC_Update_Log', 'snapshot_plugin_before_delete' ) );

		add_action( 'deleted_plugin', array( 'WPHC_Update_Log', 'log_plugin_deletion' ), 10, 2 );

		add_action( 'init', array( 'WPHC_Update_Log', 'maybe_log_core_version_change' ) );

		// 4. Aggiornamenti bulk e webhook: terzo callback su init (ri-armo del tick) e i due eventi cron.
		add_action( 'init', array( 'WPHC_Bulk', 'maybe_rearm' ) );

		add_action( WPHC_Options::CRON_BULK_TICK, array( 'WPHC_Bulk', 'run_tick' ) );

		add_action( WPHC_Options::CRON_WEBHOOK_RETRY, array( 'WPHC_Webhook', 'maybe_retry' ) );

		// 5. Autologin (quarto e ultimo callback su init: il consumo del token fa exit, quindi deve restare in coda) e
		// tracciamento dell'ultimo login.
		add_action( 'init', array( 'WPHC_Autologin', 'maybe_consume' ) );

		add_action( 'wp_login', array( 'WPHC_Users', 'record_last_login' ), 10, 2 );

		// 6. Tab Site Health, handler AJAX e handler admin-post (sezione S del file 1.38.1), nell'ordine del file.
		add_filter( 'site_health_navigation_tabs', array( 'WPHC_Admin', 'register_tab' ) );
		add_action( 'site_health_tab_content', array( 'WPHC_Admin_View', 'render_tab' ) );
		add_action( 'wp_ajax_wphc_test_endpoint', array( 'WPHC_Admin', 'ajax_test_endpoint' ) );
		add_action( 'wp_ajax_wphc_view_log', array( 'WPHC_Admin', 'ajax_view_log' ) );
		add_action( 'wp_ajax_wphc_reveal_secret', array( 'WPHC_Admin', 'ajax_reveal_secret' ) );
		add_action( 'admin_post_wphc_self_update', array( 'WPHC_Admin', 'handle_self_update' ) );
		add_action( 'admin_post_wphc_clear_caches', array( 'WPHC_Admin', 'handle_clear_caches' ) );
		add_action( 'admin_post_wphc_delete_thumbnail', array( 'WPHC_Admin', 'handle_delete_thumbnail' ) );
		add_action( 'admin_post_wphc_regenerate_thumbnail', array( 'WPHC_Admin', 'handle_regenerate_thumbnail' ) );
		add_action( 'admin_post_wphc_reset_enrollment', array( 'WPHC_Admin', 'handle_reset_enrollment' ) );
		add_action( 'admin_post_wphc_toggle_updates', array( 'WPHC_Admin', 'handle_toggle_updates' ) );
		add_action( 'admin_post_wphc_save_webhook', array( 'WPHC_Admin', 'handle_save_webhook' ) );
		add_action( 'admin_post_wphc_test_webhook', array( 'WPHC_Admin', 'handle_test_webhook' ) );
	}
}
