<?php
/**
 * Mappa UNICA "funzione di wp-health-check 1.38.1 -> metodo statico che la sostituisce nel
 * refactoring in classi" (tabella 2.2 di __docs/wp-health-check/refactoring-classi.md: tutte le 157
 * funzioni `wphc_*` del file, nello stesso ordine della tabella).
 *
 * Si usa per ogni funzione `wphc_*` che i test richiamano per NOME (chiamate dirette, hook
 * registrati, Reflection). Il refactoring non lascia wrapper `wphc_*` permanenti: se la funzione
 * non esiste piu', `WPHC_Test_Case::cb()` / `WPHC_Test_Case::call()` risolvono il metodo qui sotto.
 * E' l'UNICO punto da aggiornare se i nomi cambiano; il bootstrap lo legge per riconoscere il
 * plugin caricato (forma a funzioni o a classi) senza dipendere dalla classe base dei test, e il
 * registro degli hook (tests/test-hook-registry.php) ne usa la mappa inversa per riportare i
 * callback registrati ai nomi della 1.38.1.
 *
 * Il valore e' array( classe, metodo ). Il commento a fine riga riporta la visibilita' prevista nel
 * refactoring: un metodo `private` NON e' raggiungibile da fuori, e `WPHC_Test_Case::cb()` lo rende
 * chiamabile con la Reflection (vedi la sua documentazione); un metodo `public` e' chiamabile direttamente.
 * Con il plugin 1.38.1 (funzioni) la mappa non e' usata: `cb()` preferisce la funzione che esiste.
 *
 * @package WP_Health_Check
 */

return array(
	// WPHC_Util.
	'wphc_normalize_url'                           => array( 'WPHC_Util', 'normalize_url' ), // public
	'wphc_normalize_site_url'                      => array( 'WPHC_Util', 'normalize_site_url' ), // public
	'wphc_candidate_site_urls'                     => array( 'WPHC_Util', 'candidate_site_urls' ), // public
	'wphc_get_client_ip'                           => array( 'WPHC_Util', 'get_client_ip' ), // public
	'wphc_get_server_ip'                           => array( 'WPHC_Util', 'get_server_ip' ), // public
	'wphc_ip_is_public'                            => array( 'WPHC_Util', 'ip_is_public' ), // public
	'wphc_get_public_ip'                           => array( 'WPHC_Util', 'get_public_ip' ), // public
	// WPHC_Health.
	'wphc_core_auto_update_state'                  => array( 'WPHC_Health', 'core_auto_update_state' ), // private
	'wphc_auto_update_enabled_for'                 => array( 'WPHC_Health', 'auto_update_enabled_for' ), // private
	'wphc_theme_auto_update_state'                 => array( 'WPHC_Health', 'theme_auto_update_state' ), // private
	'wphc_flush_plugins_detail_cache'              => array( 'WPHC_Health', 'flush_plugins_detail_cache' ), // public
	'wphc_flush_theme_detail_cache'                => array( 'WPHC_Health', 'flush_theme_detail_cache' ), // public
	'wphc_flush_health_cache_only'                 => array( 'WPHC_Health', 'flush_health_cache_only' ), // public
	// WPHC_Rest.
	'wphc_request_wants_fresh'                     => array( 'WPHC_Rest', 'request_wants_fresh' ), // public
	// WPHC_Auth.
	'wphc_record_access'                           => array( 'WPHC_Auth', 'record_access' ), // public
	// WPHC_Crypto.
	'wphc_build_enroll_signing_payload'            => array( 'WPHC_Crypto', 'build_enroll_signing_payload' ), // public
	// WPHC_Enrollment.
	'wphc_reset_enrollment'                        => array( 'WPHC_Enrollment', 'reset' ), // public
	'wphc_record_enroll_error'                     => array( 'WPHC_Enrollment', 'record_enroll_error' ), // private
	'wphc_send_enroll_mismatch_alert'              => array( 'WPHC_Enrollment', 'send_enroll_mismatch_alert' ), // private
	// WPHC_Health.
	'wphc_detect_site_signals'                     => array( 'WPHC_Health', 'detect_site_signals' ), // private
	// WPHC_Cors.
	'wphc_maybe_send_cors_headers'                 => array( 'WPHC_Cors', 'maybe_send_headers' ), // public
	'wphc_reassert_cors_headers'                   => array( 'WPHC_Cors', 'reassert_headers' ), // public
	'wphc_handle_options_preflight'                => array( 'WPHC_Cors', 'handle_options_preflight' ), // public
	// WPHC_Crypto.
	'wphc_base64url_encode'                        => array( 'WPHC_Crypto', 'base64url_encode' ), // public
	'wphc_base64url_decode'                        => array( 'WPHC_Crypto', 'base64url_decode' ), // public
	'wphc_verify_central_signature'                => array( 'WPHC_Crypto', 'verify_central_signature' ), // public
	'wphc_build_signed_payload'                    => array( 'WPHC_Crypto', 'build_signed_payload' ), // public
	// WPHC_Guard.
	'wphc_nonce_seen'                              => array( 'WPHC_Guard', 'nonce_seen' ), // public
	'wphc_remember_nonce'                          => array( 'WPHC_Guard', 'remember_nonce' ), // public
	'wphc_verify_request_signature'                => array( 'WPHC_Guard', 'verify_request_signature' ), // public
	'wphc_throttle_check'                          => array( 'WPHC_Guard', 'throttle_check' ), // public
	'wphc_throttle_register_failure'               => array( 'WPHC_Guard', 'throttle_register_failure' ), // public
	'wphc_require_https'                           => array( 'WPHC_Guard', 'require_https' ), // public
	// WPHC_Auth.
	'wphc_require_token'                           => array( 'WPHC_Auth', 'require_token' ), // public
	// WPHC_Rest.
	'wphc_register_routes'                         => array( 'WPHC_Rest', 'register_routes' ), // public
	// WPHC_Enrollment.
	'wphc_route_enroll'                            => array( 'WPHC_Enrollment', 'route_enroll' ), // public
	'wphc_route_rotate'                            => array( 'WPHC_Enrollment', 'route_rotate' ), // public
	'wphc_route_revoke'                            => array( 'WPHC_Enrollment', 'route_revoke' ), // public
	// WPHC_Util.
	'wphc_mute_update_shortcircuit'                => array( 'WPHC_Util', 'mute_update_shortcircuit' ), // public
	'wphc_restore_update_shortcircuit'             => array( 'WPHC_Util', 'restore_update_shortcircuit' ), // public
	// WPHC_Thumbnail.
	'wphc_generate_site_thumbnail'                 => array( 'WPHC_Thumbnail', 'generate' ), // private
	'wphc_maybe_generate_thumbnail'                => array( 'WPHC_Thumbnail', 'maybe_generate' ), // public
	'wphc_route_thumbnail_status'                  => array( 'WPHC_Thumbnail', 'route_status' ), // public
	'wphc_route_thumbnail_regenerate'              => array( 'WPHC_Thumbnail', 'route_regenerate' ), // public
	// WPHC_Health.
	'wphc_route_health'                            => array( 'WPHC_Health', 'route_health' ), // public
	'wphc_route_ping'                              => array( 'WPHC_Health', 'route_ping' ), // public
	'wphc_route_detail_plugins'                    => array( 'WPHC_Health', 'route_detail_plugins' ), // public
	'wphc_route_detail_theme'                      => array( 'WPHC_Health', 'route_detail_theme' ), // public
	'wphc_route_detail_server'                     => array( 'WPHC_Health', 'route_detail_server' ), // public
	// WPHC_Users.
	'wphc_build_user_summary'                      => array( 'WPHC_Users', 'build_summary' ), // private
	'wphc_route_detail_users'                      => array( 'WPHC_Users', 'route_detail_users' ), // public
	// WPHC_Self_Updater.
	'wphc_github_request_args'                     => array( 'WPHC_Self_Updater', 'github_request_args' ), // private
	'wphc_fetch_latest_release'                    => array( 'WPHC_Self_Updater', 'fetch_latest_release' ), // private
	'wphc_is_release_tag'                          => array( 'WPHC_Self_Updater', 'is_release_tag' ), // private
	'wphc_fetch_latest_release_from_hub'           => array( 'WPHC_Self_Updater', 'fetch_latest_release_from_hub' ), // private
	'wphc_fetch_latest_release_without_api'        => array( 'WPHC_Self_Updater', 'fetch_latest_release_without_api' ), // private
	'wphc_download_release_assets'                 => array( 'WPHC_Self_Updater', 'download_release_assets' ), // private
	'wphc_probe_mu_dir_writable'                   => array( 'WPHC_Self_Updater', 'probe_mu_dir_writable' ), // private
	'wphc_install_release'                         => array( 'WPHC_Self_Updater', 'install_release' ), // private
	'wphc_perform_self_update'                     => array( 'WPHC_Self_Updater', 'perform_self_update' ), // public
	'wphc_perform_pushed_update'                   => array( 'WPHC_Self_Updater', 'perform_pushed_update' ), // private
	'wphc_route_update'                            => array( 'WPHC_Self_Updater', 'route_update' ), // public
	'wphc_get_latest_version'                      => array( 'WPHC_Self_Updater', 'get_latest_version' ), // public
	// WPHC_Update_Log.
	'wphc_update_log_table'                        => array( 'WPHC_Update_Log', 'table' ), // public
	'wphc_maybe_install_update_log_schema'         => array( 'WPHC_Update_Log', 'maybe_install_schema' ), // public
	'wphc_log_update_row'                          => array( 'WPHC_Update_Log', 'log_row' ), // public
	'wphc_get_reactivation_candidates'             => array( 'WPHC_Update_Log', 'get_reactivation_candidates' ), // public
	'wphc_maybe_prune_update_log'                  => array( 'WPHC_Update_Log', 'maybe_prune' ), // public
	'wphc_record_last_update'                      => array( 'WPHC_Update_Log', 'record_last_update' ), // public
	'wphc_detect_update_source'                    => array( 'WPHC_Update_Log', 'detect_update_source' ), // public
	'wphc_current_actor'                           => array( 'WPHC_Update_Log', 'current_actor' ), // private
	'wphc_snapshot_version_before_upgrade'         => array( 'WPHC_Update_Log', 'snapshot_version_before_upgrade' ), // public
	'wphc_log_wp_initiated_update'                 => array( 'WPHC_Update_Log', 'log_wp_initiated_update' ), // public
	'wphc_log_plugin_deactivation'                 => array( 'WPHC_Update_Log', 'log_plugin_deactivation' ), // public
	'wphc_snapshot_plugin_before_delete'           => array( 'WPHC_Update_Log', 'snapshot_plugin_before_delete' ), // public
	'wphc_log_plugin_deletion'                     => array( 'WPHC_Update_Log', 'log_plugin_deletion' ), // public
	'wphc_maybe_log_core_version_change'           => array( 'WPHC_Update_Log', 'maybe_log_core_version_change' ), // public
	// WPHC_Item_Updater.
	'wphc_release_update_lock'                     => array( 'WPHC_Item_Updater', 'release_update_lock' ), // public
	'wphc_maintenance_file_path'                   => array( 'WPHC_Item_Updater', 'maintenance_file_path' ), // public
	'wphc_clear_stale_maintenance'                 => array( 'WPHC_Item_Updater', 'clear_stale_maintenance' ), // public
	'wphc_is_package_host_allowed'                 => array( 'WPHC_Item_Updater', 'is_package_host_allowed' ), // private
	// WPHC_Util.
	'wphc_generate_correlation_id'                 => array( 'WPHC_Util', 'generate_correlation_id' ), // public
	// WPHC_Rest.
	'wphc_request_wants_check'                     => array( 'WPHC_Rest', 'request_wants_check' ), // public
	// WPHC_Item_Updater.
	'wphc_update_preflight'                        => array( 'WPHC_Item_Updater', 'update_preflight' ), // private
	'wphc_reactivate_preflight'                    => array( 'WPHC_Item_Updater', 'reactivate_preflight' ), // private
	'wphc_maybe_invalidate_item_opcache'           => array( 'WPHC_Item_Updater', 'maybe_invalidate_item_opcache' ), // private
	'wphc_patch_update_transient_after_success'    => array( 'WPHC_Item_Updater', 'patch_update_transient_after_success' ), // private
	'wphc_perform_item_update'                     => array( 'WPHC_Item_Updater', 'perform_item_update' ), // public
	'wphc_perform_core_update'                     => array( 'WPHC_Item_Updater', 'perform_core_update' ), // public
	'wphc_perform_reactivate'                      => array( 'WPHC_Item_Updater', 'perform_reactivate' ), // private
	'wphc_map_item_update_outcome'                 => array( 'WPHC_Item_Updater', 'map_item_update_outcome' ), // private
	'wphc_route_update_plugin'                     => array( 'WPHC_Item_Updater', 'route_update_plugin' ), // public
	'wphc_route_update_theme'                      => array( 'WPHC_Item_Updater', 'route_update_theme' ), // public
	'wphc_route_update_core'                       => array( 'WPHC_Item_Updater', 'route_update_core' ), // public
	// WPHC_Update_Log.
	'wphc_get_update_log_entries'                  => array( 'WPHC_Update_Log', 'get_entries' ), // public
	'wphc_route_update_log'                        => array( 'WPHC_Update_Log', 'route_update_log' ), // public
	// WPHC_Item_Updater.
	'wphc_route_reactivate'                        => array( 'WPHC_Item_Updater', 'route_reactivate' ), // public
	// WPHC_Bulk.
	'wphc_bulk_get_job'                            => array( 'WPHC_Bulk', 'get_job' ), // private
	'wphc_bulk_save_job'                           => array( 'WPHC_Bulk', 'save_job' ), // private
	'wphc_bulk_compute_stall_after_ts'             => array( 'WPHC_Bulk', 'compute_stall_after_ts' ), // private
	'wphc_bulk_sync_summary'                       => array( 'WPHC_Bulk', 'sync_summary' ), // private
	'wphc_bulk_summary'                            => array( 'WPHC_Bulk', 'summary' ), // public
	'wphc_bulk_is_active'                          => array( 'WPHC_Bulk', 'is_active' ), // private
	'wphc_bulk_recount'                            => array( 'WPHC_Bulk', 'recount' ), // private
	'wphc_bulk_new_item'                           => array( 'WPHC_Bulk', 'new_item' ), // private
	'wphc_bulk_normalize_items'                    => array( 'WPHC_Bulk', 'normalize_items' ), // private
	'wphc_bulk_acquire_lock'                       => array( 'WPHC_Bulk', 'acquire_lock' ), // private
	'wphc_bulk_release_lock'                       => array( 'WPHC_Bulk', 'release_lock' ), // public
	'wphc_bulk_schedule_tick'                      => array( 'WPHC_Bulk', 'schedule_tick' ), // private
	'wphc_bulk_unschedule_ticks'                   => array( 'WPHC_Bulk', 'unschedule_ticks' ), // private
	'wphc_bulk_next_delay'                         => array( 'WPHC_Bulk', 'next_delay' ), // private
	'wphc_bulk_maybe_rearm'                        => array( 'WPHC_Bulk', 'maybe_rearm' ), // public
	'wphc_bulk_next_item_index'                    => array( 'WPHC_Bulk', 'next_item_index' ), // private
	'wphc_bulk_classify_result'                    => array( 'WPHC_Bulk', 'classify_result' ), // private
	'wphc_bulk_backoff_for_attempt'                => array( 'WPHC_Bulk', 'backoff_for_attempt' ), // private
	'wphc_bulk_apply_outcome'                      => array( 'WPHC_Bulk', 'apply_outcome' ), // private
	'wphc_bulk_abort_job'                          => array( 'WPHC_Bulk', 'abort_job' ), // private
	'wphc_bulk_reap_stuck_items'                   => array( 'WPHC_Bulk', 'reap_stuck_items' ), // private
	'wphc_bulk_maybe_reap'                         => array( 'WPHC_Bulk', 'maybe_reap' ), // private
	'wphc_bulk_maybe_finalize'                     => array( 'WPHC_Bulk', 'maybe_finalize' ), // private
	'wphc_bulk_finish'                             => array( 'WPHC_Bulk', 'finish' ), // private
	'wphc_bulk_run_tick'                           => array( 'WPHC_Bulk', 'run_tick' ), // public
	'wphc_bulk_body_is_core_only'                  => array( 'WPHC_Bulk', 'body_is_core_only' ), // private
	'wphc_bulk_enqueue_preflight'                  => array( 'WPHC_Bulk', 'enqueue_preflight' ), // private
	'wphc_route_update_bulk_enqueue'               => array( 'WPHC_Bulk', 'route_enqueue' ), // public
	'wphc_route_update_bulk_status'                => array( 'WPHC_Bulk', 'route_status' ), // public
	'wphc_route_update_bulk_cancel'                => array( 'WPHC_Bulk', 'route_cancel' ), // public
	// WPHC_Webhook.
	'wphc_webhook_url'                             => array( 'WPHC_Webhook', 'url' ), // public
	'wphc_webhook_url_is_allowed'                  => array( 'WPHC_Webhook', 'url_is_allowed' ), // public
	'wphc_build_outbound_signature'                => array( 'WPHC_Webhook', 'build_outbound_signature' ), // public
	'wphc_webhook_backoff_for_attempt'             => array( 'WPHC_Webhook', 'backoff_for_attempt' ), // public
	'wphc_record_webhook_result'                   => array( 'WPHC_Webhook', 'record_result' ), // private
	'wphc_dispatch_webhook_payload'                => array( 'WPHC_Webhook', 'dispatch_payload' ), // public
	// WPHC_Bulk.
	'wphc_bulk_build_webhook_payload'              => array( 'WPHC_Bulk', 'build_webhook_payload' ), // private
	'wphc_bulk_send_webhook_for_job'               => array( 'WPHC_Bulk', 'send_webhook_for_job' ), // private
	// WPHC_Webhook.
	'wphc_maybe_retry_webhook'                     => array( 'WPHC_Webhook', 'maybe_retry' ), // public
	// WPHC_Auth.
	'wphc_debug_permission'                        => array( 'WPHC_Auth', 'debug_permission' ), // public
	// WPHC_Debug.
	'wphc_debug_hook_callbacks'                    => array( 'WPHC_Debug', 'hook_callbacks' ), // private
	'wphc_route_debug'                             => array( 'WPHC_Debug', 'route_debug' ), // public
	// WPHC_Autologin.
	'wphc_track_pending_autologin'                 => array( 'WPHC_Autologin', 'track_pending' ), // private
	'wphc_untrack_pending_autologin'               => array( 'WPHC_Autologin', 'untrack_pending' ), // private
	'wphc_route_autologin_token'                   => array( 'WPHC_Autologin', 'route_token' ), // public
	'wphc_maybe_consume_autologin'                 => array( 'WPHC_Autologin', 'maybe_consume' ), // public
	// WPHC_Users.
	'wphc_record_last_login'                       => array( 'WPHC_Users', 'record_last_login' ), // public
	// WPHC_Admin.
	'wphc_register_site_health_tab'                => array( 'WPHC_Admin', 'register_tab' ), // public
	// WPHC_Admin_View.
	'wphc_render_site_health_tab'                  => array( 'WPHC_Admin_View', 'render_tab' ), // public
	// WPHC_Admin.
	'wphc_ajax_test_endpoint'                      => array( 'WPHC_Admin', 'ajax_test_endpoint' ), // public
	'wphc_ajax_view_log'                           => array( 'WPHC_Admin', 'ajax_view_log' ), // public
	'wphc_ajax_reveal_secret'                      => array( 'WPHC_Admin', 'ajax_reveal_secret' ), // public
	'wphc_handle_self_update'                      => array( 'WPHC_Admin', 'handle_self_update' ), // public
	'wphc_handle_clear_caches'                     => array( 'WPHC_Admin', 'handle_clear_caches' ), // public
	'wphc_handle_delete_thumbnail'                 => array( 'WPHC_Admin', 'handle_delete_thumbnail' ), // public
	'wphc_handle_regenerate_thumbnail'             => array( 'WPHC_Admin', 'handle_regenerate_thumbnail' ), // public
	'wphc_handle_reset_enrollment'                 => array( 'WPHC_Admin', 'handle_reset_enrollment' ), // public
	'wphc_handle_toggle_updates'                   => array( 'WPHC_Admin', 'handle_toggle_updates' ), // public
	'wphc_handle_save_webhook'                     => array( 'WPHC_Admin', 'handle_save_webhook' ), // public
	'wphc_handle_test_webhook'                     => array( 'WPHC_Admin', 'handle_test_webhook' ), // public
);
