<?php
/**
 * Mappa UNICA "funzione di wp-health-check 1.38.1 -> metodo statico che la sostituisce nel
 * refactoring in classi" (tabella 2.2 di __docs/wp-health-check/refactoring-classi.md).
 *
 * Si usa solo per le funzioni `wphc_*` che i test richiamano per NOME (chiamate dirette, hook
 * registrati, Reflection). Il refactoring non lascia wrapper `wphc_*` permanenti: se la funzione
 * non esiste piu', `WPHC_Test_Case::cb()` / `WPHC_Test_Case::call()` risolvono il metodo qui sotto.
 * E' l'UNICO punto da aggiornare se i nomi cambiano; il bootstrap lo legge per riconoscere il
 * plugin caricato (forma a funzioni o a classi) senza dipendere dalla classe base dei test.
 *
 * Le funzioni che i test usano e NON compaiono qui vanno chiamate cosi' come sono (sono le
 * "API di test da riadattare": elenco in tests/README.md).
 *
 * @package WP_Health_Check
 */

return array(
	// Util.
	'wphc_normalize_url'               => array( 'WPHC_Util', 'normalize_url' ),
	'wphc_normalize_site_url'          => array( 'WPHC_Util', 'normalize_site_url' ),
	// Crypto.
	'wphc_build_enroll_signing_payload' => array( 'WPHC_Crypto', 'build_enroll_signing_payload' ),
	'wphc_base64url_encode'            => array( 'WPHC_Crypto', 'base64url_encode' ),
	'wphc_base64url_decode'            => array( 'WPHC_Crypto', 'base64url_decode' ),
	'wphc_verify_central_signature'    => array( 'WPHC_Crypto', 'verify_central_signature' ),
	'wphc_build_signed_payload'        => array( 'WPHC_Crypto', 'build_signed_payload' ),
	// Guardie di richiesta e autenticazione.
	'wphc_verify_request_signature'    => array( 'WPHC_Guard', 'verify_request_signature' ),
	'wphc_require_token'               => array( 'WPHC_Auth', 'require_token' ),
	// Enrollment.
	'wphc_reset_enrollment'            => array( 'WPHC_Enrollment', 'reset' ),
	// Self-update (metodi PRIVATI nel refactoring: la build dovra' renderli raggiungibili dai test, o i
	// test che li usano vanno adattati).
	'wphc_is_release_tag'              => array( 'WPHC_Self_Updater', 'is_release_tag' ),
	'wphc_install_release'             => array( 'WPHC_Self_Updater', 'install_release' ),
	// Log di update e webhook.
	'wphc_update_log_table'            => array( 'WPHC_Update_Log', 'table' ),
	'wphc_build_outbound_signature'    => array( 'WPHC_Webhook', 'build_outbound_signature' ),
	// CORS, autologin, ultimo login.
	'wphc_reassert_cors_headers'       => array( 'WPHC_Cors', 'reassert_headers' ),
	'wphc_handle_options_preflight'    => array( 'WPHC_Cors', 'handle_options_preflight' ),
	'wphc_maybe_send_cors_headers'     => array( 'WPHC_Cors', 'maybe_send_headers' ),
	'wphc_maybe_consume_autologin'     => array( 'WPHC_Autologin', 'maybe_consume' ),
	'wphc_record_last_login'           => array( 'WPHC_Users', 'record_last_login' ),
);
