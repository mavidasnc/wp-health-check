<?php
/**
 * Nomi persistiti del plugin (option, transient, user meta, hook cron, tabella) come costanti di classe.
 *
 * @package WP_Health_Check
 */

/**
 * Nomi di option, transient, user meta, hook cron e tabella del plugin, come costanti di classe.
 *
 * Classe di sole costanti (nessun metodo). I valori sono quelli storici della 1.38.1 e non vanno mai
 * cambiati: sono persistiti sui siti della flotta (option e transient esistenti, eventi cron in coda, tabella
 * di log). Una costante con un valore sbagliato "perde" i dati del sito, quindi ogni valore va controllato
 * contro la tabella 1.8 di __docs/wp-health-check/refactoring-classi.md.
 *
 * Le costanti entrano lotto per lotto (decisione D-3 del progetto): questa classe contiene solo i nomi usati
 * dal codice gia' spostato in classi. Il codice ancora in legacy.php usa i letterali (stessi valori) e li
 * sostituira' quando passera' in classi. I nomi del core WordPress (active_plugins, update_plugins, ...)
 * restano sempre letterali, come i nomi di azioni admin-post, AJAX, nonce e campi POST.
 */
final class WPHC_Options {

	// Nomi delle option di WordPress (tabella wp_options).
	const OPT_TOKEN                  = 'wp_health_check_token';
	const OPT_TOKEN_PREV             = 'wp_health_check_token_prev';
	const OPT_TOKEN_ROTATED_AT       = 'wp_health_check_token_rotated_at';
	const OPT_SITE_URL               = 'wp_health_check_site_url';
	const OPT_DASHBOARD_ORIGIN       = 'wp_health_check_dashboard_origin';
	const OPT_ENROLLED_AT            = 'wp_health_check_enrolled_at';
	const OPT_ENROLLED_IP            = 'wp_health_check_enrolled_ip';
	const OPT_ENROLL_ISSUED_AT       = 'wp_health_check_enroll_issued_at';
	const OPT_LAST_ENROLL_ERROR      = 'wp_health_check_last_enroll_error';
	const OPT_LAST_REQUEST_AT        = 'wp_health_check_last_request_at';
	const OPT_LAST_REQUEST_IP        = 'wp_health_check_last_request_ip';
	const OPT_PROTOCOL               = 'wp_health_check_protocol';
	const OPT_REVOKED_AT             = 'wp_health_check_revoked_at';
	const OPT_SECRET_KID             = 'wp_health_check_secret_kid';
	const OPT_AUTOLOGIN_PENDING      = 'wp_health_check_autologin_pending';
	const OPT_LAST_AUTOLOGIN         = 'wp_health_check_last_autologin';
	const OPT_TRUST_PROXY            = 'wp_health_check_trust_proxy';
	const OPT_DB_VERSION             = 'wp_health_check_db_version';
	const OPT_CORE_VERSION           = 'wp_health_check_core_version';
	const OPT_LAST_UPDATE            = 'wp_health_check_last_update';
	const OPT_UPDATES_ENABLED        = 'wp_health_check_updates_enabled';
	const OPT_RESTRICT_OFFICIAL_ONLY = 'wp_health_check_restrict_official_only';
	const OPT_THUMB                  = 'wp_health_check_thumb';
	const OPT_THUMB_ID               = 'wp_health_check_thumb_id';
	const OPT_THUMB_AT               = 'wp_health_check_thumb_at';
	const OPT_THUMB_ERROR            = 'wp_health_check_thumb_error';
	const OPT_BULK_JOB               = 'wp_health_check_bulk_job';
	const OPT_BULK_STATUS            = 'wp_health_check_bulk_status';
	const OPT_WEBHOOK_URL            = 'wp_health_check_webhook_url';
	const OPT_WEBHOOK_DISABLED       = 'wp_health_check_webhook_disabled';
	const OPT_WEBHOOK_LAST           = 'wp_health_check_webhook_last';
	const OPT_WEBHOOK_PENDING        = 'wp_health_check_webhook_pending';

	// Nomi dei transient e prefissi delle chiavi dei transient.
	const TRANSIENT_UPDATE_LOCK          = 'wp_health_check_update_lock';
	const TRANSIENT_BULK_LOCK            = 'wp_health_check_bulk_lock';
	const TRANSIENT_LOG_PRUNED_AT        = 'wp_health_check_log_pruned_at';
	const TRANSIENT_HEALTH_CACHE         = 'wphc_health_cache';
	const TRANSIENT_DETAIL_PLUGINS_CACHE = 'wphc_detail_plugins_cache';
	const TRANSIENT_DETAIL_THEME_CACHE   = 'wphc_detail_theme_cache';
	const TRANSIENT_DETAIL_SERVER_CACHE  = 'wphc_detail_server_cache';
	const TRANSIENT_LATEST_VERSION_CACHE = 'wphc_latest_version_cache';
	const TRANSIENT_PUBLIC_IP            = 'wphc_public_ip';
	const TRANSIENT_PUBLIC_IP_RETRY_LOCK = 'wphc_public_ip_retry_lock';
	const TRANSIENT_THUMB_RETRY_LOCK     = 'wphc_thumb_retry_lock';
	const TRANSIENT_ENROLL_ALERT_SENT    = 'wphc_enroll_alert_sent';
	const TRANSIENT_BULK_REARM_LOCK      = 'wphc_bulk_rearm_lock';
	const TRANSIENT_WEBHOOK_RETRY_LOCK   = 'wphc_webhook_retry_lock';
	const TRANSIENT_NONCE_PREFIX         = 'wphc_nonce_';
	const TRANSIENT_FAIL_PREFIX          = 'wphc_fail_';
	const TRANSIENT_AUTOLOGIN_PREFIX     = 'wphc_autologin_';

	// Chiavi di user meta.
	const META_LAST_LOGIN    = 'wphc_last_login';
	const META_LAST_LOGIN_IP = 'wphc_last_login_ip';

	// Nomi degli hook di WP-Cron.
	const CRON_BULK_TICK     = 'wphc_bulk_update_tick';
	const CRON_WEBHOOK_RETRY = 'wphc_webhook_retry';

	// Nome della tabella (senza il prefisso di $wpdb).
	const TABLE_UPDATE_LOG = 'wphc_update_log';
}
