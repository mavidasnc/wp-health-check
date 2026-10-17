<?php
/**
 * Plugin Name: WP Health Check (Fleet Agent)
 * Description: Must-use plugin di monitoraggio per una flotta di siti WordPress, con enroll firmato, endpoint REST protetti da token e self-update firmato dalle release di un repository GitHub pubblico.
 * Version:     1.39.0
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












if ( ! defined( 'WP_HEALTH_CHECK_VERSION' ) ) {
	define( 'WP_HEALTH_CHECK_VERSION', '1.39.0' );
}


if ( ! defined( 'WP_HEALTH_CHECK_GH_OWNER' ) ) {
	define( 'WP_HEALTH_CHECK_GH_OWNER', 'mavidasnc' );
}
if ( ! defined( 'WP_HEALTH_CHECK_GH_REPO' ) ) {
	define( 'WP_HEALTH_CHECK_GH_REPO', 'wp-health-check' );
}










if ( ! defined( 'WP_HEALTH_CHECK_RELEASE_URL' ) ) {
	define( 'WP_HEALTH_CHECK_RELEASE_URL', 'https://hub.mavida.com/api/v1/fleet/agent-release/latest' );
}












if ( ! defined( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY' ) ) {
	define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY', 'uXzoP9VTpihQ1ipNUMCwITN9wKmJV/VFuYxms5Tt0CE=' );
}







if ( ! defined( 'WP_HEALTH_CHECK_ALERT_EMAIL' ) ) {
	define( 'WP_HEALTH_CHECK_ALERT_EMAIL', 'maurizio@mavida.com' );
}







if ( ! defined( 'WP_HEALTH_CHECK_DB_VERSION' ) ) {
	define( 'WP_HEALTH_CHECK_DB_VERSION', '4' );
}


if ( ! defined( 'WP_HEALTH_CHECK_LOG_RETENTION_DAYS' ) ) {
	define( 'WP_HEALTH_CHECK_LOG_RETENTION_DAYS', 90 );
}


if ( ! defined( 'WP_HEALTH_CHECK_UPDATE_LOCK_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_UPDATE_LOCK_TTL', 300 );
}






if ( ! defined( 'WP_HEALTH_CHECK_BULK_MAX_ITEMS' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_MAX_ITEMS', 100 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK', 5 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_TIME_BUDGET' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_TIME_BUDGET', 20 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_TICK_GAP' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_TICK_GAP', 30 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS', 4 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_BACKOFF' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_BACKOFF', array( 0, 60, 300, 900 ) );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_MAX_DEFERRALS' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_MAX_DEFERRALS', 20 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_LOCK_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_LOCK_TTL', 300 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT', 600 );
}










if ( ! defined( 'WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT', 1800 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_STALL_GRACE' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_STALL_GRACE', 600 );
}


if ( ! defined( 'WP_HEALTH_CHECK_BULK_JOB_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_BULK_JOB_TTL', 6 * HOUR_IN_SECONDS );
}











if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL', 'https://hub.mavida.com/api/v1/fleet/webhook/bulk-update' );
}


if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_TIMEOUT' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_TIMEOUT', 10 );
}


if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS', 3 );
}


if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_BACKOFF' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_BACKOFF', array( 0, 300, 1800 ) );
}


if ( ! defined( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS' ) ) {
	define( 'WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS', 200 );
}









if ( ! defined( 'WP_HEALTH_CHECK_AUTOLOGIN_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_AUTOLOGIN_TTL', 20 );
}









if ( ! defined( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2' ) ) {
	define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2', 'mGJDfcvmM2lCoaq5+lJaC94mhpvrwjqJIQfjJrC/6Ko=' );
}






if ( ! defined( 'WP_HEALTH_CHECK_REPLAY_WINDOW' ) ) {
	define( 'WP_HEALTH_CHECK_REPLAY_WINDOW', 300 );
}






if ( ! defined( 'WP_HEALTH_CHECK_NONCE_TTL' ) ) {
	define( 'WP_HEALTH_CHECK_NONCE_TTL', 600 );
}







if ( ! defined( 'WP_HEALTH_CHECK_ROTATION_GRACE' ) ) {
	define( 'WP_HEALTH_CHECK_ROTATION_GRACE', 900 );
}








if ( ! defined( 'WP_HEALTH_CHECK_RATE_MAX_FAILS' ) ) {
	define( 'WP_HEALTH_CHECK_RATE_MAX_FAILS', 10 );
}
if ( ! defined( 'WP_HEALTH_CHECK_RATE_WINDOW' ) ) {
	define( 'WP_HEALTH_CHECK_RATE_WINDOW', 300 );
}







if ( ! defined( 'WP_HEALTH_CHECK_THUMB_SERVICE' ) ) {
	define( 'WP_HEALTH_CHECK_THUMB_SERVICE', 'https://image.thum.io/get/noanimate/png/width/400/' );
}














if ( ! defined( 'WP_HEALTH_CHECK_FILE' ) ) {
	define( 'WP_HEALTH_CHECK_FILE', __FILE__ );
}



















final class WPHC_Options {

	
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

	
	const TRANSIENT_UPDATE_LOCK            = 'wp_health_check_update_lock';
	const TRANSIENT_BULK_LOCK              = 'wp_health_check_bulk_lock';
	const TRANSIENT_LOG_PRUNED_AT          = 'wp_health_check_log_pruned_at';
	const TRANSIENT_HEALTH_CACHE           = 'wphc_health_cache';
	const TRANSIENT_DETAIL_PLUGINS_CACHE   = 'wphc_detail_plugins_cache';
	const TRANSIENT_DETAIL_THEME_CACHE     = 'wphc_detail_theme_cache';
	const TRANSIENT_DETAIL_SERVER_CACHE    = 'wphc_detail_server_cache';
	const TRANSIENT_LATEST_VERSION_CACHE   = 'wphc_latest_version_cache';
	const TRANSIENT_PUBLIC_IP              = 'wphc_public_ip';
	const TRANSIENT_PUBLIC_IP_RETRY_LOCK   = 'wphc_public_ip_retry_lock';
	const TRANSIENT_THUMB_RETRY_LOCK       = 'wphc_thumb_retry_lock';
	const TRANSIENT_ENROLL_ALERT_SENT      = 'wphc_enroll_alert_sent';
	const TRANSIENT_BULK_REARM_LOCK        = 'wphc_bulk_rearm_lock';
	const TRANSIENT_WEBHOOK_RETRY_LOCK     = 'wphc_webhook_retry_lock';
	const TRANSIENT_NONCE_PREFIX           = 'wphc_nonce_';
	const TRANSIENT_FAIL_PREFIX            = 'wphc_fail_';
	const TRANSIENT_AUTOLOGIN_PREFIX       = 'wphc_autologin_';
	const TRANSIENT_WEBHOOK_BAD_URL_PREFIX = 'wphc_webhook_bad_url_';

	
	const META_LAST_LOGIN    = 'wphc_last_login';
	const META_LAST_LOGIN_IP = 'wphc_last_login_ip';

	
	const CRON_BULK_TICK     = 'wphc_bulk_update_tick';
	const CRON_WEBHOOK_RETRY = 'wphc_webhook_retry';

	
	const TABLE_UPDATE_LOG = 'wphc_update_log';
}















final class WPHC_Util {

	










	public static function normalize_url( $url ) {
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

	






	public static function normalize_site_url() {
		return self::normalize_url( home_url() );
	}

	












	public static function candidate_site_urls() {
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
				$normalized = self::normalize_url( $scheme . '://' . $variant_host . $port . $path );
				if ( '' !== $normalized ) {
					
					$candidates[ $normalized ] = true;
				}
			}
		}

		return array_keys( $candidates );
	}

	


















	public static function get_client_ip() {
		$trust_proxy = (bool) get_option( WPHC_Options::OPT_TRUST_PROXY, false );

		if ( $trust_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff        = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidates = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
			foreach ( $candidates as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
	}

	









	public static function get_server_ip() {
		$server_addr = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

		return filter_var( $server_addr, FILTER_VALIDATE_IP ) ? $server_addr : '';
	}

	








	public static function ip_is_public( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	


















	public static function get_public_ip() {
		$server_ip = self::get_server_ip();
		if ( '' !== $server_ip && self::ip_is_public( $server_ip ) ) {
			return $server_ip;
		}

		$cached = get_transient( WPHC_Options::TRANSIENT_PUBLIC_IP );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( false !== get_transient( WPHC_Options::TRANSIENT_PUBLIC_IP_RETRY_LOCK ) ) {
			return '';
		}

		
		
		
		set_transient( WPHC_Options::TRANSIENT_PUBLIC_IP_RETRY_LOCK, time(), DAY_IN_SECONDS );

		$response = wp_remote_get( 'https://api.ipify.org', array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = trim( wp_remote_retrieve_body( $response ) );
		if ( ! self::ip_is_public( $body ) ) {
			return '';
		}

		set_transient( WPHC_Options::TRANSIENT_PUBLIC_IP, $body, 7 * DAY_IN_SECONDS );

		return $body;
	}

	
	
	

	
















	public static function mute_update_shortcircuit() {
		global $wp_filter;
		$hooks = array( 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'pre_site_transient_update_core' );
		$saved = array();
		foreach ( $hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$saved[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] ); 
			}
		}
		return $saved;
	}

	




	public static function restore_update_shortcircuit( $saved ) {
		global $wp_filter;
		foreach ( $saved as $hook => $obj ) {
			$wp_filter[ $hook ] = $obj; 
		}
	}

	






	public static function generate_correlation_id() {
		return bin2hex( random_bytes( 8 ) );
	}
}














final class WPHC_Crypto {

	














	public static function build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at ) {
		$origin_component = null === $dashboard_origin ? '' : (string) $dashboard_origin;

		return $site_url . "\n" . $token . "\n" . $origin_component . "\n" . (string) $issued_at;
	}

	







	public static function base64url_encode( $binary ) {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); 
	}

	








	public static function base64url_decode( $data ) {
		$data      = strtr( (string) $data, '-_', '+/' );
		$remainder = strlen( $data ) % 4;
		if ( 0 !== $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}

		return base64_decode( $data, true );
	}

	




















	public static function verify_central_signature( $message, $signature_b64, $kid = 'k1', $purpose = 'envelope' ) {
		$keys = ( 'release' === $purpose )
			? array( 'k2' => WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2 )
			: array( 'k1' => WP_HEALTH_CHECK_CENTRAL_PUBKEY );

		
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

	












	public static function build_signed_payload( $kind, array $parts ) {
		return $kind . "\n" . implode( "\n", $parts );
	}
}




























final class WPHC_Guard {

	







	public static function nonce_seen( $nonce ) {
		return false !== get_transient( WPHC_Options::TRANSIENT_NONCE_PREFIX . hash( 'sha256', (string) $nonce ) );
	}

	









	public static function remember_nonce( $nonce ) {
		set_transient( WPHC_Options::TRANSIENT_NONCE_PREFIX . hash( 'sha256', (string) $nonce ), time(), WP_HEALTH_CHECK_NONCE_TTL );
	}

	













	public static function verify_request_signature( WP_REST_Request $request, $secret ) {
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

		if ( self::nonce_seen( $nonce ) ) {
			return $unauthorized;
		}

		$canonical = $request->get_method() . "\n"
			. $request->get_route() . "\n"
			. hash( 'sha256', (string) $request->get_body() ) . "\n"
			. $timestamp_header . "\n"
			. $nonce;

		$expected = WPHC_Crypto::base64url_encode( hash_hmac( 'sha256', $canonical, (string) $secret, true ) );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			return $unauthorized;
		}

		
		
		self::remember_nonce( $nonce );

		return true;
	}

	








	public static function throttle_check() {
		$fails = (int) get_transient( WPHC_Options::TRANSIENT_FAIL_PREFIX . hash( 'sha1', WPHC_Util::get_client_ip() ) );

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

	




	public static function throttle_register_failure() {
		$key   = WPHC_Options::TRANSIENT_FAIL_PREFIX . hash( 'sha1', WPHC_Util::get_client_ip() );
		$fails = (int) get_transient( $key );
		set_transient( $key, $fails + 1, WP_HEALTH_CHECK_RATE_WINDOW );
	}

	













	public static function require_https() {
		if ( is_ssl() ) {
			return true;
		}

		if ( (bool) get_option( WPHC_Options::OPT_TRUST_PROXY, false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$protos = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) );
			if ( 'https' === strtolower( (string) end( $protos ) ) ) {
				return true;
			}
		}

		return new WP_Error(
			'wphc_https_required',
			__( 'HTTPS obbligatorio per questa rotta.', 'wp-health-check' ),
			array( 'status' => 403 )
		);
	}
}















final class WPHC_Cors {

	




































	public static function maybe_send_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			
			
			
			
			define( 'DONOTCACHEPAGE', true ); 
		}
		nocache_headers();

		
		
		
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header_remove( 'Access-Control-Allow-Methods' );
		header_remove( 'Access-Control-Allow-Headers' );
		header_remove( 'Access-Control-Expose-Headers' );

		$request_origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' === $request_origin ) {
			return;
		}

		
		
		
		
		
		
		$configured_origin = get_option( \WPHC_Options::OPT_DASHBOARD_ORIGIN );
		if ( empty( $configured_origin ) || $request_origin !== $configured_origin ) {
			return;
		}

		header( 'Access-Control-Allow-Origin: ' . $request_origin );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		
		
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature' );
		
		
		header( 'Vary: Origin' );
	}

	













	public static function reassert_headers( $served, $result, $request ) {
		unset( $result );

		if ( 0 !== strpos( $request->get_route(), '/health-check/v1' ) ) {
			return $served;
		}

		self::maybe_send_headers();

		return $served;
	}

	













	public static function handle_options_preflight( $result, $server, $request ) {
		unset( $server );

		if ( 'OPTIONS' !== $request->get_method() ) {
			return $result;
		}
		if ( 0 !== strpos( $request->get_route(), '/health-check/v1' ) ) {
			return $result;
		}

		self::maybe_send_headers();

		return new WP_REST_Response( null, 200 );
	}
}















final class WPHC_Auth {

	





















	public static function record_access() {
		$previous_at = get_option( WPHC_Options::OPT_LAST_REQUEST_AT );
		$previous_ip = get_option( WPHC_Options::OPT_LAST_REQUEST_IP );

		if ( wp_doing_cron() ) {
			return array(
				'at' => $previous_at ? $previous_at : null,
				'ip' => $previous_ip ? $previous_ip : null,
			);
		}

		update_option( WPHC_Options::OPT_LAST_REQUEST_AT, gmdate( 'c' ), false );
		update_option( WPHC_Options::OPT_LAST_REQUEST_IP, WPHC_Util::get_client_ip(), false );

		return array(
			'at' => $previous_at ? $previous_at : null,
			'ip' => $previous_ip ? $previous_ip : null,
		);
	}

	
	
	

	

























	public static function require_token( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		
		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$stored_token = get_option( WPHC_Options::OPT_TOKEN );
		if ( empty( $stored_token ) ) {
			
			
			return new WP_Error(
				'wphc_not_enrolled',
				__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
				array( 'status' => 503 )
			);
		}

		if ( ! empty( get_option( WPHC_Options::OPT_REVOKED_AT ) ) ) {
			return new WP_Error(
				'wphc_revoked',
				__( 'Segreto revocato dal sistema centrale.', 'wp-health-check' ),
				array( 'status' => 403 )
			);
		}

		$throttled = WPHC_Guard::throttle_check();
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
			WPHC_Guard::throttle_register_failure();
			return $unauthorized;
		}

		$provided_token = trim( substr( $auth_header, 7 ) );

		
		
		
		$prev_token = get_option( WPHC_Options::OPT_TOKEN_PREV );
		$rotated_at = (int) get_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );
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
			WPHC_Guard::throttle_register_failure();
			return $unauthorized;
		}

		$protocol      = (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 );
		$signature_hdr = $request->get_header( 'x-wphc-signature' );

		if ( $protocol >= 2 || ! empty( $signature_hdr ) ) {
			$secret_for_signature = $using_prev ? $prev_token : $stored_token;
			$signature_check      = WPHC_Guard::verify_request_signature( $request, (string) $secret_for_signature );
			if ( is_wp_error( $signature_check ) ) {
				WPHC_Guard::throttle_register_failure();
				return $unauthorized;
			}
		}

		if ( $using_prev ) {
			
			
			WPHC_Update_Log::log_row( WPHC_Util::generate_correlation_id(), 'token', 'rotation', __( 'Segreto precedente', 'wp-health-check' ), null, null, 'completed', 'grace_window_hit' );
		} elseif ( ! empty( $prev_token ) ) {
			
			
			
			delete_option( WPHC_Options::OPT_TOKEN_PREV );
			delete_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );
		}

		return true;
	}

	





	public static function debug_permission() {
		return current_user_can( 'manage_options' );
	}
}














final class WPHC_Rest {

	






	public static function request_wants_fresh( WP_REST_Request $request ) {
		return '1' === (string) $request->get_param( 'fresh' );
	}

	









	public static function register_routes() {
		register_rest_route(
			'health-check/v1',
			'/enroll',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Enrollment', 'route_enroll' ),
				
				
				
				'permission_callback' => '__return_true',
			)
		);

		
		
		
		
		register_rest_route(
			'health-check/v1',
			'/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Enrollment', 'route_rotate' ),
				'permission_callback' => '__return_true',
			)
		);

		
		
		
		register_rest_route(
			'health-check/v1',
			'/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Enrollment', 'route_revoke' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'health-check/v1',
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_health' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		register_rest_route(
			'health-check/v1',
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_ping' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/plugins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_detail_plugins' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/theme',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_detail_theme' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_detail_server' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Users', 'route_detail_users' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Self_Updater', 'route_update' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		
		register_rest_route(
			'health-check/v1',
			'/update/plugin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Item_Updater', 'route_update_plugin' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/update/theme',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Item_Updater', 'route_update_theme' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/update/core',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Item_Updater', 'route_update_core' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		
		register_rest_route(
			'health-check/v1',
			'/update/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Update_Log', 'route_update_log' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		
		register_rest_route(
			'health-check/v1',
			'/update/reactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Item_Updater', 'route_reactivate' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		
		
		
		register_rest_route(
			'health-check/v1',
			'/update/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Bulk', 'route_enqueue' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);
		register_rest_route(
			'health-check/v1',
			'/update/bulk',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Bulk', 'route_status' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);
		register_rest_route(
			'health-check/v1',
			'/update/bulk/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Bulk', 'route_cancel' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		
		
		register_rest_route(
			'health-check/v1',
			'/thumbnail',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Thumbnail', 'route_status' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);
		register_rest_route(
			'health-check/v1',
			'/thumbnail',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Thumbnail', 'route_regenerate' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		
		
		
		
		register_rest_route(
			'health-check/v1',
			'/debug',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Debug', 'route_debug' ),
				'permission_callback' => array( 'WPHC_Auth', 'debug_permission' ),
			)
		);

		
		
		
		
		
		
		
		register_rest_route(
			'health-check/v1',
			'/autologin/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Autologin', 'route_token' ),
				'permission_callback' => array( 'WPHC_Auth', 'debug_permission' ),
			)
		);
	}

	








	public static function request_wants_check( WP_REST_Request $request ) {
		return (bool) $request->get_param( 'check' );
	}
}






















final class WPHC_Update_Log {

	







	private static $version_snapshots = array();

	







	private static $api_update_in_progress = false;

	






	private static $deleting_plugins = array();

	



	public static function begin_api_update() {
		self::$api_update_in_progress = true;
	}

	



	public static function end_api_update() {
		self::$api_update_in_progress = false;
	}

	
	
	
	
	
	
	
	
	
	
	
	
	
	
	

	






	public static function table() {
		global $wpdb;
		return $wpdb->prefix . WPHC_Options::TABLE_UPDATE_LOG;
	}

	






	public static function maybe_install_schema() {
		if ( get_option( WPHC_Options::OPT_DB_VERSION ) === WP_HEALTH_CHECK_DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		
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
				
				
				
				'active'         => null === $active ? null : (int) $active,
				'source'         => $source,
				'actor'          => $actor,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	














	public static function get_reactivation_candidates() {
		global $wpdb;
		$table = self::table();

		
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.target, l.name, l.active FROM {$table} l INNER JOIN ( SELECT target, MAX(id) AS max_id FROM {$table} WHERE type = %s AND active IS NOT NULL GROUP BY target ) latest ON latest.max_id = l.id WHERE l.active = 1", 'plugin' ), ARRAY_A ); 

		$candidates = array();
		foreach ( (array) $rows as $row ) {
			$candidates[] = array(
				'target' => $row['target'],
				'name'   => $row['name'],
			);
		}

		return $candidates;
	}

	





	public static function maybe_prune() {
		if ( false !== get_transient( WPHC_Options::TRANSIENT_LOG_PRUNED_AT ) ) {
			return;
		}

		global $wpdb;
		$table     = self::table();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( WP_HEALTH_CHECK_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );

		
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold ) );

		set_transient( WPHC_Options::TRANSIENT_LOG_PRUNED_AT, time(), DAY_IN_SECONDS );
	}

	











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

	







	public static function detect_update_source() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		return 'wp-admin';
	}

	






	private static function current_actor() {
		$user = wp_get_current_user();

		return ( $user instanceof WP_User && $user->exists() ) ? $user->user_login : null;
	}

	















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

	











	public static function log_wp_initiated_update( $upgrader, $hook_extra ) {
		unset( $upgrader );

		
		
		
		
		
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
				$items = $hook_extra['plugins']; 
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$items = array( $hook_extra['plugin'] ); 
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

	













	public static function log_plugin_deactivation( $plugin, $network_wide ) {
		unset( $network_wide );

		
		
		if ( ! empty( self::$api_update_in_progress ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		
		$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
		$data        = is_readable( $plugin_file ) ? get_plugin_data( $plugin_file, false, false ) : array();
		$name        = ! empty( $data['Name'] ) ? $data['Name'] : $plugin;
		$version     = ! empty( $data['Version'] ) ? $data['Version'] : null;

		self::log_row( WPHC_Util::generate_correlation_id(), 'plugin', $plugin, $name, $version, null, 'deactivated', null, false, self::detect_update_source(), self::current_actor() );
	}

	





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

	













	public static function maybe_log_core_version_change() {
		$seen = (string) get_option( WPHC_Options::OPT_CORE_VERSION, '' );
		$now  = (string) get_bloginfo( 'version' );

		if ( '' === $seen ) {
			
			
			
			update_option( WPHC_Options::OPT_CORE_VERSION, $now );
			return;
		}

		if ( $seen === $now ) {
			return;
		}

		
		
		
		update_option( WPHC_Options::OPT_CORE_VERSION, $now );

		$source         = self::detect_update_source();
		$correlation_id = WPHC_Util::generate_correlation_id();
		self::log_row( $correlation_id, 'core', 'core', 'WordPress', $seen, $now, 'completed', null, null, $source, self::current_actor() );
		self::record_last_update( 'core', 'core', 'completed', $source );
	}

	













	public static function get_entries( $type, $source, $limit, $offset ) {
		global $wpdb;
		$table = self::table();

		$limit  = $limit > 0 ? min( 200, (int) $limit ) : 50;
		$offset = max( 0, (int) $offset );

		$type_filter   = in_array( $type, array( 'plugin', 'theme', 'core', 'token', 'login' ), true ) ? $type : '';
		$source_filter = in_array( $source, array( 'api', 'wp-admin', 'cron', 'wp-cli' ), true ) ? $source : '';

		
		
		
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
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $values ) ); 
		} else {
			
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); 
		}

		$query_values = array_merge( $values, array( $limit, $offset ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $query_values ), ARRAY_A ); 

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$entries[] = array(
				'id'             => (int) $row['id'],
				'correlation_id' => $row['correlation_id'],
				
				
				'created_at'     => gmdate( 'c', strtotime( $row['created_at'] . ' UTC' ) ),
				'type'           => $row['type'],
				'target'         => $row['target'],
				'name'           => $row['name'],
				'version_from'   => $row['version_from'],
				'version_to'     => $row['version_to'],
				'phase'          => $row['phase'],
				'message'        => $row['message'],
				'ip'             => $row['ip'],
				
				
				
				'active'         => isset( $row['active'] ) && null !== $row['active'] ? (bool) $row['active'] : null,
				
				
				
				
				'source'         => isset( $row['source'] ) && '' !== $row['source'] ? $row['source'] : 'api',
				'actor'          => isset( $row['actor'] ) && null !== $row['actor'] ? $row['actor'] : null,
			);
		}

		return array(
			'entries' => $entries,
			'total'   => $total,
		);
	}

	








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
















final class WPHC_Enrollment {

	





	public static function reset() {
		$options = array(
			WPHC_Options::OPT_TOKEN,
			WPHC_Options::OPT_SITE_URL,
			WPHC_Options::OPT_DASHBOARD_ORIGIN,
			WPHC_Options::OPT_ENROLLED_AT,
			WPHC_Options::OPT_ENROLLED_IP,
			WPHC_Options::OPT_ENROLL_ISSUED_AT,
			WPHC_Options::OPT_LAST_REQUEST_AT,
			WPHC_Options::OPT_LAST_REQUEST_IP,
			WPHC_Options::OPT_LAST_ENROLL_ERROR,
			
			WPHC_Options::OPT_TOKEN_PREV,
			WPHC_Options::OPT_TOKEN_ROTATED_AT,
			WPHC_Options::OPT_PROTOCOL,
			WPHC_Options::OPT_REVOKED_AT,
			WPHC_Options::OPT_SECRET_KID,
			WPHC_Options::OPT_AUTOLOGIN_PENDING,
			
			
			
			
			WPHC_Options::OPT_LAST_AUTOLOGIN,
		);
		foreach ( $options as $option_name ) {
			delete_option( $option_name );
		}

		
		
		
		
		delete_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK );
	}

	














	private static function record_enroll_error( $code, $reason, $received = '' ) {
		update_option(
			WPHC_Options::OPT_LAST_ENROLL_ERROR,
			array(
				'at'       => gmdate( 'c' ),
				'code'     => $code,
				'reason'   => $reason,
				'received' => sanitize_text_field( $received ),
				'ip'       => WPHC_Util::get_client_ip(),
			),
			false
		);
	}

	















	private static function send_enroll_mismatch_alert( $expected, $received, $candidates ) {
		
		
		$to = WP_HEALTH_CHECK_ALERT_EMAIL;
		if ( ! is_email( $to ) ) {
			return;
		}

		
		
		if ( false !== get_transient( WPHC_Options::TRANSIENT_ENROLL_ALERT_SENT ) ) {
			return;
		}

		$site_home = home_url();

		
		$subject = sprintf( __( '[WP Health Check] Enroll fallito (URL mismatch) su %s', 'wp-health-check' ), $site_home );

		$lines   = array();
		$lines[] = __( 'Un tentativo di enroll e\' stato rifiutato: il site_url firmato dal sistema centrale non coincide con nessuno degli URL canonici del sito.', 'wp-health-check' );
		$lines[] = '';
		$lines[] = __( 'Sito:', 'wp-health-check' ) . ' ' . $site_home;
		$lines[] = __( 'URL ricevuto:', 'wp-health-check' ) . ' ' . $received;
		$lines[] = __( 'URL atteso (principale):', 'wp-health-check' ) . ' ' . $expected;
		$lines[] = __( 'IP chiamante:', 'wp-health-check' ) . ' ' . WPHC_Util::get_client_ip();
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
			set_transient( WPHC_Options::TRANSIENT_ENROLL_ALERT_SENT, gmdate( 'c' ), HOUR_IN_SECONDS );
		}
	}

	
	
	

	























	public static function route_enroll( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			WPHC_Guard::throttle_register_failure();
			self::record_enroll_error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), '' );

			return new WP_Error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		
		$reported_site_url = isset( $body['site_url'] ) ? (string) $body['site_url'] : '';

		
		
		$protocol        = isset( $body['protocol'] ) ? (int) $body['protocol'] : 1;
		$required_fields = array( 'site_url', 'token', 'issued_at', 'signature' );
		if ( $protocol >= 2 ) {
			$required_fields[] = 'nonce';
		}

		foreach ( $required_fields as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				WPHC_Guard::throttle_register_failure();
				
				$reason = sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field );
				self::record_enroll_error( 'wphc_enroll_missing_field', $reason, $reported_site_url );

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

		
		
		
		if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
			WPHC_Guard::throttle_register_failure();
			self::record_enroll_error( 'wphc_enroll_stale', __( 'Busta di enroll scaduta (issued_at fuori dalla finestra di freschezza).', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_stale', __( 'Richiesta di enroll scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( $protocol >= 2 ) {
			if ( WPHC_Guard::nonce_seen( $nonce ) ) {
				WPHC_Guard::throttle_register_failure();
				self::record_enroll_error( 'wphc_enroll_replay', __( 'Nonce di enroll gia\' utilizzato.', 'wp-health-check' ), $reported_site_url );

				return new WP_Error( 'wphc_enroll_replay', __( 'Richiesta di enroll gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
			}
		}

		
		
		
		
		
		$message = ( $protocol >= 2 )
			? WPHC_Crypto::build_signed_payload(
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
			: WPHC_Crypto::build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at );

		if ( ! WPHC_Crypto::verify_central_signature( $message, $signature, $kid ) ) {
			
			
			
			
			WPHC_Guard::throttle_register_failure();
			self::record_enroll_error( 'wphc_enroll_unauthorized', __( 'Firma non valida (busta non prodotta dal sistema centrale).', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_unauthorized', __( 'Richiesta di enroll non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		
		
		
		
		
		if ( $protocol < 2 && (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 ) >= 2 ) {
			self::record_enroll_error( 'wphc_enroll_downgrade', __( 'Busta di enroll v1 rifiutata: il sito e\' gia\' al protocollo 2.', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_downgrade', __( 'Il sito e\' al protocollo 2: enroll v1 non ammesso.', 'wp-health-check' ), array( 'status' => 409 ) );
		}

		
		
		
		
		
		
		
		
		
		
		$received_site_url   = $site_url;                       
		$received_normalized = WPHC_Util::normalize_url( $site_url );  
		$candidates          = WPHC_Util::candidate_site_urls();

		if ( ! in_array( $received_normalized, $candidates, true ) ) {
			$canonical_home = WPHC_Util::normalize_site_url();
			if ( WP_DEBUG ) {
				error_log( sprintf( 'wp-health-check: enroll URL mismatch — atteso: %1$s ricevuto: %2$s', $canonical_home, $received_site_url ) ); 
			}

			$mismatch_message = sprintf(
				
				__( 'URL atteso: %1$s — ricevuto: %2$s', 'wp-health-check' ),
				$canonical_home,
				$received_site_url
			);
			
			
			self::record_enroll_error( 'wphc_enroll_url_mismatch', $mismatch_message, $received_site_url );
			self::send_enroll_mismatch_alert( $canonical_home, $received_site_url, $candidates );

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

		
		
		
		update_option( WPHC_Options::OPT_TOKEN, $token, false );
		update_option( WPHC_Options::OPT_SITE_URL, $received_site_url, false );
		update_option( WPHC_Options::OPT_DASHBOARD_ORIGIN, $dashboard_origin, false );
		update_option( WPHC_Options::OPT_ENROLLED_AT, gmdate( 'c' ), false );
		update_option( WPHC_Options::OPT_ENROLLED_IP, WPHC_Util::get_client_ip(), false );
		
		
		update_option( WPHC_Options::OPT_ENROLL_ISSUED_AT, $issued_at, false );
		update_option( WPHC_Options::OPT_SECRET_KID, $kid, false );
		if ( $protocol >= 2 ) {
			
			
			
			
			update_option( WPHC_Options::OPT_PROTOCOL, 2, false );
			WPHC_Guard::remember_nonce( $nonce );
		}
		
		delete_option( WPHC_Options::OPT_LAST_ENROLL_ERROR );

		
		return rest_ensure_response(
			array(
				'enrolled'      => true,
				'site'          => $received_site_url,
				'agent_version' => WP_HEALTH_CHECK_VERSION,
			)
		);
	}

	
	
	

	















	public static function route_rotate( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$current_token = get_option( WPHC_Options::OPT_TOKEN );
		if ( empty( $current_token ) ) {
			return new WP_Error(
				'wphc_not_enrolled',
				__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
				array( 'status' => 503 )
			);
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		foreach ( array( 'site_url', 'new_token', 'nonce', 'issued_at', 'signature' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				WPHC_Guard::throttle_register_failure();
				return new WP_Error(
					'wphc_rotate_missing_field',
					
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
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_stale', __( 'Richiesta di rotazione scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( WPHC_Guard::nonce_seen( $nonce ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_replay', __( 'Richiesta di rotazione gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		$message = WPHC_Crypto::build_signed_payload( 'rotate', array( $site_url, $new_token, $nonce, (string) $issued_at, (string) $protocol ) );
		if ( ! WPHC_Crypto::verify_central_signature( $message, $signature, $kid ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_unauthorized', __( 'Richiesta di rotazione non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		
		
		
		if ( ! in_array( WPHC_Util::normalize_url( $site_url ), WPHC_Util::candidate_site_urls(), true ) ) {
			return new WP_Error(
				'wphc_rotate_url_mismatch',
				__( 'URL della busta di rotazione non corrispondente a questo sito.', 'wp-health-check' ),
				array( 'status' => 403 )
			);
		}

		WPHC_Guard::remember_nonce( $nonce );

		
		update_option( WPHC_Options::OPT_TOKEN_PREV, $current_token, false );
		update_option( WPHC_Options::OPT_TOKEN_ROTATED_AT, time(), false );
		update_option( WPHC_Options::OPT_TOKEN, $new_token, false );
		update_option( WPHC_Options::OPT_PROTOCOL, max( 2, $protocol ), false );
		update_option( WPHC_Options::OPT_SECRET_KID, $kid, false );

		WPHC_Update_Log::log_row( WPHC_Util::generate_correlation_id(), 'token', 'rotation', __( 'Segreto ruotato dal sistema centrale', 'wp-health-check' ), null, null, 'completed' );

		return rest_ensure_response(
			array(
				'rotated'       => true,
				'site'          => $site_url,
				'protocol'      => (int) get_option( WPHC_Options::OPT_PROTOCOL, 2 ),
				'grace_seconds' => WP_HEALTH_CHECK_ROTATION_GRACE,
			)
		);
	}

	
	
	

	














	public static function route_revoke( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		if ( empty( get_option( WPHC_Options::OPT_TOKEN ) ) ) {
			return new WP_Error(
				'wphc_not_enrolled',
				__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
				array( 'status' => 503 )
			);
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		foreach ( array( 'site_url', 'nonce', 'issued_at', 'signature' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				WPHC_Guard::throttle_register_failure();
				return new WP_Error(
					'wphc_revoke_missing_field',
					
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
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_stale', __( 'Richiesta di revoca scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( WPHC_Guard::nonce_seen( $nonce ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_replay', __( 'Richiesta di revoca gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		$message = WPHC_Crypto::build_signed_payload( 'revoke', array( $site_url, $nonce, (string) $issued_at ) );
		if ( ! WPHC_Crypto::verify_central_signature( $message, $signature, $kid ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_unauthorized', __( 'Richiesta di revoca non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		if ( ! in_array( WPHC_Util::normalize_url( $site_url ), WPHC_Util::candidate_site_urls(), true ) ) {
			return new WP_Error(
				'wphc_revoke_url_mismatch',
				__( 'URL della busta di revoca non corrispondente a questo sito.', 'wp-health-check' ),
				array( 'status' => 403 )
			);
		}

		WPHC_Guard::remember_nonce( $nonce );

		update_option( WPHC_Options::OPT_REVOKED_AT, gmdate( 'c' ), false );
		delete_option( WPHC_Options::OPT_TOKEN_PREV );
		delete_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );

		
		
		
		
		$pending_autologins = (array) get_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, array() );
		foreach ( $pending_autologins as $pending_key ) {
			delete_transient( $pending_key );
		}
		delete_option( WPHC_Options::OPT_AUTOLOGIN_PENDING );

		WPHC_Update_Log::log_row( WPHC_Util::generate_correlation_id(), 'token', 'revocation', __( 'Segreto revocato dal sistema centrale', 'wp-health-check' ), null, null, 'completed' );

		return rest_ensure_response(
			array(
				'revoked' => true,
				'site'    => $site_url,
			)
		);
	}
}















final class WPHC_Thumbnail {

	
	
	

	














	private static function generate() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		
		
		
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

		
		
		$attachment_id = media_handle_sideload( $file_array, 0, 'WP Health Check site thumbnail' );

		if ( is_wp_error( $attachment_id ) ) {
			
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

	
















	public static function maybe_generate( $force = false ) {
		if ( ! $force ) {
			$existing = get_option( WPHC_Options::OPT_THUMB );
			if ( $existing ) {
				return $existing;
			}

			if ( false !== get_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK ) ) {
				return null;
			}
		}

		
		
		
		
		set_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK, time(), DAY_IN_SECONDS );

		if ( $force ) {
			
			
			
			$previous_id = (int) get_option( WPHC_Options::OPT_THUMB_ID );
			if ( $previous_id ) {
				wp_delete_attachment( $previous_id, true );
			}
			delete_option( WPHC_Options::OPT_THUMB );
			delete_option( WPHC_Options::OPT_THUMB_ID );
		}

		$result = self::generate();

		if ( ! empty( $result['error'] ) ) {
			update_option(
				WPHC_Options::OPT_THUMB_ERROR,
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
				WPHC_Options::OPT_THUMB_ERROR,
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

		
		
		update_option( WPHC_Options::OPT_THUMB, $url, false );
		update_option( WPHC_Options::OPT_THUMB_ID, $attachment_id, false );
		update_option( WPHC_Options::OPT_THUMB_AT, time(), false );
		delete_option( WPHC_Options::OPT_THUMB_ERROR );
		delete_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK );

		return $url;
	}

	
	
	

	








	public static function route_status( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Auth::record_access();

		$thumbnail = get_option( WPHC_Options::OPT_THUMB );
		$error     = get_option( WPHC_Options::OPT_THUMB_ERROR );

		return rest_ensure_response(
			array(
				'thumbnail'    => $thumbnail ? $thumbnail : null,
				'generated_at' => ( $thumbnail && get_option( WPHC_Options::OPT_THUMB_AT ) ) ? gmdate( 'c', (int) get_option( WPHC_Options::OPT_THUMB_AT ) ) : null,
				'error'        => is_array( $error ) ? $error : null,
			)
		);
	}

	









	public static function route_regenerate( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Auth::record_access();

		$thumbnail = self::maybe_generate( true );
		$error     = get_option( WPHC_Options::OPT_THUMB_ERROR );

		return rest_ensure_response(
			array(
				'thumbnail' => $thumbnail ? $thumbnail : null,
				'error'     => ( ! $thumbnail && is_array( $error ) ) ? $error : null,
			)
		);
	}
}





















final class WPHC_Health {

	







	private static $core_auto_update_state = null;

	





















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
		if ( (bool) apply_filters( 'automatic_updater_disabled', $disabled ) ) { 
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

		
		$minor = (bool) apply_filters( 'allow_minor_auto_core_updates', $minor );
		$major = (bool) apply_filters( 'allow_major_auto_core_updates', $major );
		$dev   = (bool) apply_filters( 'allow_dev_auto_core_updates', $dev );
		

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

	









	private static function auto_update_enabled_for( $type ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		return (bool) wp_is_auto_update_enabled_for_type( $type );
	}

	









	private static function theme_auto_update_state( $stylesheet, array $auto_update_themes ) {
		
		
		$item   = (object) array( 'theme' => $stylesheet );
		$forced = wp_is_auto_update_forced_for_item( 'theme', null, $item );
		$stored = in_array( $stylesheet, $auto_update_themes, true );

		return array(
			
			
			
			
			
			
			'auto_update'        => ( null === $forced ) ? $stored : (bool) $forced, 
			'auto_update_forced' => $forced,
		);
	}

	






	public static function flush_plugins_detail_cache() {
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
	}

	
	public static function flush_theme_detail_cache() {
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
	}

	
	public static function flush_health_cache_only() {
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
	}

	
















	private static function detect_site_signals() {
		
		$gdpr_plugin_slugs = array(
			'iubenda-cookie-law-solution',
			'cookiebot',
			'cookiebot-manager',
		);

		
		$builder_plugin_slugs = array(
			'elementor',
		);

		
		$builder_theme_slugs = array(
			'divi',
		);

		
		$ecommerce_plugin_slugs = array(
			'woocommerce',
			'easy-digital-downloads',
		);

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_slugs   = array_map(
			function ( $plugin_file ) {
				
				
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

	
	
	

	









	public static function route_health( WP_REST_Request $request ) {
		
		
		
		
		$previous_access = WPHC_Auth::record_access();

		$fresh = WPHC_Rest::request_wants_fresh( $request );

		if ( $fresh ) {
			
			
			
			
			
			
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/update.php';
			wp_clean_plugins_cache( false );
			wp_clean_themes_cache( false );

			
			
			
			
			
			
			
			
			
			
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

		
		
		
		
		
		$all_themes        = wp_get_themes();
		$active_theme      = wp_get_theme();
		$active_theme_name = (string) $active_theme->get( 'Name' );
		$parent_theme      = $active_theme->parent();
		$parent_theme_name = ( $parent_theme instanceof WP_Theme ) ? (string) $parent_theme->get( 'Name' ) : null;

		$server_ip = WPHC_Util::get_server_ip();
		$public_ip = WPHC_Util::get_public_ip();

		
		
		
		$signals = self::detect_site_signals();

		
		
		
		$maintenance_file  = WPHC_Item_Updater::maintenance_file_path();
		$maintenance_stuck = file_exists( $maintenance_file ) && ( time() - (int) filemtime( $maintenance_file ) ) > 600;
		$last_update       = get_option( WPHC_Options::OPT_LAST_UPDATE );
		$core_auto         = self::core_auto_update_state();

		
		
		
		
		$thumbnail = WPHC_Thumbnail::maybe_generate();
		
		
		
		
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

		
		
		set_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE, $payload, 60 );

		return rest_ensure_response( $payload );
	}

	
	
	

	

















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
			
			
			
			
			
			
			
			
			wp_clean_plugins_cache( false );
		}

		$all_plugins = get_plugins();
		
		
		
		
		$muted_updates  = WPHC_Util::mute_update_shortcircuit();
		$plugin_updates = get_plugin_updates();
		WPHC_Util::restore_update_shortcircuit( $muted_updates );
		$active_plugins = (array) get_option( 'active_plugins', array() );

		
		
		
		
		
		$auto_update_plugins = (array) get_site_option( 'auto_update_plugins', array() );

		$items = array();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$has_update  = isset( $plugin_updates[ $plugin_file ]->update->new_version );
			$new_version = $has_update ? $plugin_updates[ $plugin_file ]->update->new_version : null;

			
			
			
			$plugin_dir = dirname( $plugin_file );
			$slug       = ( '.' !== $plugin_dir ) ? $plugin_dir : basename( $plugin_file, '.php' );

			
			
			
			
			
			
			$item_object        = (object) array_merge(
				$plugin_data,
				array(
					'plugin' => $plugin_file,
					'slug'   => $slug,
				)
			);
			$forced             = wp_is_auto_update_forced_for_item( 'plugin', null, $item_object );
			$auto_update_stored = in_array( $plugin_file, $auto_update_plugins, true );
			
			
			$auto_update_effective = ( null === $forced ) ? $auto_update_stored : (bool) $forced; 

			$items[] = array(
				'name'               => $plugin_data['Name'],
				'slug'               => $slug,
				
				
				
				
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
			
			
			
			
			
			wp_clean_themes_cache( false );
		}

		$active_theme = wp_get_theme();
		
		
		$muted_updates = WPHC_Util::mute_update_shortcircuit();
		$theme_updates = get_theme_updates();
		WPHC_Util::restore_update_shortcircuit( $muted_updates );
		$stylesheet = $active_theme->get_stylesheet();

		
		
		
		
		 
		$theme_update = isset( $theme_updates[ $stylesheet ] ) ? $theme_updates[ $stylesheet ]->update : false;
		$has_update   = is_array( $theme_update ) && isset( $theme_update['new_version'] );
		$new_version  = $has_update ? $theme_update['new_version'] : null;

		
		
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

		
		
		
		
		
		
		$all_themes        = wp_get_themes();
		$active_stylesheet = $stylesheet;
		$themes            = array();
		foreach ( $all_themes as $theme_stylesheet => $theme ) {
			 
			$item_update      = isset( $theme_updates[ $theme_stylesheet ] ) ? $theme_updates[ $theme_stylesheet ]->update : false;
			$item_has_update  = is_array( $item_update ) && isset( $item_update['new_version'] );
			$item_new_version = $item_has_update ? $item_update['new_version'] : null;
			$item_auto_update = self::theme_auto_update_state( (string) $theme_stylesheet, $auto_update_themes );

			$themes[] = array(
				'name'               => $theme->get( 'Name' ),
				'stylesheet'         => (string) $theme_stylesheet,
				'version'            => $theme->get( 'Version' ),
				'active'             => ( (string) $theme_stylesheet === $active_stylesheet ),
				
				
				
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
			
			
			
			
			
			$debug_data = array();
			if ( WP_DEBUG ) {
				error_log( 'wp-health-check: eccezione in debug_data(): ' . $e->getMessage() ); 
			}
		}

		$server_section   = isset( $debug_data['wp-server']['fields'] ) ? $debug_data['wp-server']['fields'] : array();
		$database_section = isset( $debug_data['wp-database']['fields'] ) ? $debug_data['wp-database']['fields'] : array();

		
		
		
		
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

		
		
		global $wpdb;
		$mysql_version = isset( $database_section['server_version']['value'] ) && '' !== $database_section['server_version']['value']
			? (string) $database_section['server_version']['value']
			: ( method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '' );

		
		
		
		
		
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














final class WPHC_Users {

	
	
	

	










	private static function build_summary( $user ) {
		$last_login    = get_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN, true );
		$last_login_ip = get_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN_IP, true );

		return array(
			'id'            => (int) $user->ID,
			'user_login'    => $user->user_login,
			'display_name'  => $user->display_name,
			'email'         => $user->user_email,
			
			
			
			'registered'    => gmdate( 'c', strtotime( $user->user_registered . ' UTC' ) ),
			
			
			
			
			'last_login'    => $last_login ? gmdate( 'c', (int) $last_login ) : null,
			'last_login_ip' => $last_login_ip ? $last_login_ip : null,
		);
	}

	










	public static function route_detail_users( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Auth::record_access();

		
		
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login', 'display_name', 'user_email', 'user_registered' ),
			)
		);

		
		
		
		
		if ( $admins ) {
			update_meta_cache( 'user', wp_list_pluck( $admins, 'ID' ) );
		}

		$users       = array();
		$seen_logins = array();
		foreach ( $admins as $user ) {
			$users[]                          = self::build_summary( $user );
			$seen_logins[ $user->user_login ] = true;
		}

		
		
		
		
		
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

			
			
			
			if ( $extra_users ) {
				update_meta_cache( 'user', wp_list_pluck( $extra_users, 'ID' ) );
			}

			foreach ( $extra_users as $user ) {
				$users[] = self::build_summary( $user );
			}
		}

		return rest_ensure_response(
			array(
				'site'         => WPHC_Util::normalize_site_url(),
				'generated_at' => gmdate( 'c' ),
				'count'        => count( $users ),
				'users'        => $users,
			)
		);
	}

	
	
	
	
	
	
	
	
	
	

	










	public static function record_last_login( $user_login, $user = null ) {
		if ( ! ( $user instanceof WP_User ) ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		update_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN, time() );

		
		
		$ip = WPHC_Util::get_client_ip();
		if ( '' !== $ip ) {
			update_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN_IP, $ip );
		}
	}
}















final class WPHC_Debug {

	
	
	

	







	private static function hook_callbacks( $hook ) {
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

	









	public static function route_debug( WP_REST_Request $request ) {
		unset( $request );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		
		$filtered       = get_site_transient( 'update_plugins' );
		$filtered_slugs = ( is_object( $filtered ) && ! empty( $filtered->response ) ) ? array_keys( (array) $filtered->response ) : array();

		
		
		
		
		
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
		
		$raw_slugs = ( is_object( $raw ) && ! empty( $raw->response ) ) ? array_keys( (array) $raw->response ) : array();

		
		
		$muted_fix      = WPHC_Util::mute_update_shortcircuit();
		$plugin_updates = get_plugin_updates();
		$health_tp      = get_site_transient( 'update_plugins' );
		WPHC_Util::restore_update_shortcircuit( $muted_fix );
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
				
				
				
				'health_plugins_updates'    => $health_plugins_updates,
				'filters'                   => array(
					'site_transient_update_plugins'     => self::hook_callbacks( 'site_transient_update_plugins' ),
					'pre_set_site_transient_update_plugins' => self::hook_callbacks( 'pre_set_site_transient_update_plugins' ),
					'pre_site_transient_update_plugins' => self::hook_callbacks( 'pre_site_transient_update_plugins' ),
				),
			)
		);
	}
}
















final class WPHC_Autologin {

	
	
	
	
	
	
	
	
	
	
	

	











	private static function track_pending( $key ) {
		$pending   = (array) get_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, array() );
		$pending[] = $key;
		if ( count( $pending ) > 20 ) {
			$pending = array_slice( $pending, -20 );
		}
		update_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, $pending, false );
	}

	






	private static function untrack_pending( $key ) {
		$pending = (array) get_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, array() );
		$pending = array_values( array_diff( $pending, array( $key ) ) );
		if ( empty( $pending ) ) {
			delete_option( WPHC_Options::OPT_AUTOLOGIN_PENDING );
		} else {
			update_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, $pending, false );
		}
	}

	


















	public static function route_token( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Cors::maybe_send_headers();

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$user = wp_get_current_user();

		$token          = bin2hex( random_bytes( 32 ) );
		$key            = WPHC_Options::TRANSIENT_AUTOLOGIN_PREFIX . hash( 'sha256', $token );
		$correlation_id = WPHC_Util::generate_correlation_id();

		
		
		
		set_transient(
			$key,
			array(
				'user_id'        => $user->ID,
				'correlation_id' => $correlation_id,
			),
			WP_HEALTH_CHECK_AUTOLOGIN_TTL
		);
		
		
		
		self::track_pending( $key );

		
		
		
		update_option(
			WPHC_Options::OPT_LAST_AUTOLOGIN,
			array(
				'user_id' => $user->ID,
				'login'   => $user->user_login,
				'at'      => gmdate( 'c' ),
				'ip'      => WPHC_Util::get_client_ip(),
			),
			false
		);

		
		
		
		
		WPHC_Update_Log::log_row( $correlation_id, 'token', $user->user_login, $user->display_name ? $user->display_name : $user->user_login, null, null, 'completed' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'wp-health-check: autologin token generato per user #%d (%s)', $user->ID, $user->user_login ) ); 
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

	






























	public static function maybe_consume() {
		if ( empty( $_GET['wphc_autologin'] ) ) { 
			return;
		}

		if ( is_wp_error( WPHC_Guard::throttle_check() ) || is_wp_error( WPHC_Guard::require_https() ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['wphc_autologin'] ) ); 
		$key   = WPHC_Options::TRANSIENT_AUTOLOGIN_PREFIX . hash( 'sha256', $token );

		$data = get_transient( $key );

		
		
		
		delete_transient( $key );
		self::untrack_pending( $key );

		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			
			
			
			
			WPHC_Guard::throttle_register_failure();
			WPHC_Update_Log::log_row(
				WPHC_Util::generate_correlation_id(),
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
			WPHC_Update_Log::log_row(
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
		
		wp_set_auth_cookie( $user->ID, false );
		
		do_action( 'wp_login', $user->user_login, $user );

		WPHC_Update_Log::log_row( $data['correlation_id'], 'login', $user->user_login, $user->display_name ? $user->display_name : $user->user_login, null, null, 'completed' );

		wp_safe_redirect( admin_url() );
		exit;
	}
}



















final class WPHC_Self_Updater {

	
	
	

	










	private static function github_request_args( $timeout, $extra_headers = array() ) {
		return array(
			'timeout' => $timeout,
			'headers' => array_merge(
				array( 'User-Agent' => 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION ),
				$extra_headers
			),
		);
	}

	























	private static function fetch_latest_release( $timeout = 15, $skip_hub = false ) {
		if ( ! $skip_hub ) {
			$from_hub = self::fetch_latest_release_from_hub( $timeout );
			if ( null !== $from_hub ) {
				return $from_hub;
			}
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( WP_HEALTH_CHECK_GH_OWNER ),
			rawurlencode( WP_HEALTH_CHECK_GH_REPO )
		);

		$response = wp_remote_get( $api_url, self::github_request_args( $timeout, array( 'Accept' => 'application/vnd.github+json' ) ) );

		if ( is_wp_error( $response ) ) {
			$failure = array(
				'code'   => 'wphc_update_network_error',
				'http'   => 0,
				'detail' => $response->get_error_message(),
			);
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$failure = array(
				'code'   => 'wphc_update_github_error',
				'http'   => (int) wp_remote_retrieve_response_code( $response ),
				
				'detail' => sprintf( __( 'HTTP %d', 'wp-health-check' ), (int) wp_remote_retrieve_response_code( $response ) ),
			);
		} else {
			$release = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $release ) && ! empty( $release['tag_name'] ) ) {
				return $release;
			}
			$failure = array(
				'code'   => 'wphc_update_bad_release',
				'http'   => 200,
				'detail' => '',
			);
		}

		$fallback = self::fetch_latest_release_without_api( $timeout );
		if ( null !== $fallback ) {
			return $fallback;
		}

		return $failure;
	}

	






	private static function is_release_tag( $tag ) {
		return is_string( $tag ) && 1 === preg_match( '/^v?\d+\.\d+\.\d+(?:[-+.][0-9A-Za-z.-]+)?$/', $tag );
	}

	









	private static function fetch_latest_release_from_hub( $timeout ) {
		$url = (string) WP_HEALTH_CHECK_RELEASE_URL;
		
		if ( '' === $url || false === strrpos( $url, '/' ) ) { 
			return null;
		}

		$response = wp_remote_get( $url, self::github_request_args( $timeout, array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || ! isset( $release['tag_name'] ) || ! self::is_release_tag( $release['tag_name'] ) ) {
			return null;
		}

		$prefix = substr( $url, 0, strrpos( $url, '/' ) + 1 );
		$assets = array();
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && 0 === strpos( (string) $asset['browser_download_url'], $prefix ) ) {
					$assets[] = array(
						'name'                 => (string) $asset['name'],
						'browser_download_url' => (string) $asset['browser_download_url'],
					);
				}
			}
		}

		return array(
			'tag_name'    => $release['tag_name'],
			'assets'      => $assets,
			'wphc_source' => 'hub',
		);
	}

	








	private static function fetch_latest_release_without_api( $timeout ) {
		$owner = rawurlencode( WP_HEALTH_CHECK_GH_OWNER );
		$repo  = rawurlencode( WP_HEALTH_CHECK_GH_REPO );

		$args                = self::github_request_args( $timeout );
		$args['redirection'] = 0; 
		$response            = wp_remote_get( sprintf( 'https://github.com/%s/%s/releases/latest', $owner, $repo ), $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $code < 300 || $code >= 400 || '' === $location ) {
			return null;
		}

		$prefix = sprintf( 'https://github.com/%s/%s/releases/tag/', $owner, $repo );
		if ( 0 !== strpos( $location, $prefix ) ) {
			return null;
		}
		$tag = rawurldecode( substr( $location, strlen( $prefix ) ) );
		if ( ! preg_match( '/^v?\d+\.\d+\.\d+(?:[-+.][0-9A-Za-z.-]+)?$/', $tag ) ) {
			return null;
		}

		$base   = sprintf( 'https://github.com/%s/%s/releases/download/%s/', $owner, $repo, rawurlencode( $tag ) );
		$assets = array();
		foreach ( array( 'wp-health-check.php', 'wp-health-check.php.sha256', 'wp-health-check.php.sig' ) as $name ) {
			$assets[] = array(
				'name'                 => $name,
				'browser_download_url' => $base . $name,
			);
		}

		return array(
			'tag_name' => $tag,
			'assets'   => $assets,
		);
	}

	












	private static function download_release_assets( $release ) {
		$asset_url     = null;
		$sha_asset_url = null;
		$sig_asset_url = null;
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( 'wp-health-check.php' === $asset['name'] ) {
					$asset_url = $asset['browser_download_url'];
				} elseif ( 'wp-health-check.php.sha256' === $asset['name'] ) {
					$sha_asset_url = $asset['browser_download_url'];
				} elseif ( 'wp-health-check.php.sig' === $asset['name'] ) {
					$sig_asset_url = $asset['browser_download_url'];
				}
			}
		}

		if ( null === $asset_url ) {
			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_asset_missing',
				'message' => __( 'Asset wp-health-check.php non trovato nella release.', 'wp-health-check' ),
				'http'    => 502,
			);
		}

		$download = wp_remote_get( $asset_url, self::github_request_args( 30 ) );
		if ( is_wp_error( $download ) || 200 !== (int) wp_remote_retrieve_response_code( $download ) ) {
			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_download_failed',
				'message' => __( 'Impossibile scaricare il nuovo file del plugin.', 'wp-health-check' ),
				'http'    => 502,
			);
		}

		
		
		
		$expected_sha256 = null;
		if ( null !== $sha_asset_url ) {
			$sha_response = wp_remote_get( $sha_asset_url, self::github_request_args( 30 ) );
			if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
				
				
				$sha_body        = trim( wp_remote_retrieve_body( $sha_response ) );
				$expected_sha256 = strtolower( (string) strtok( $sha_body, " \t\n" ) );
			}
		}

		$sig_data = null;
		if ( null !== $sig_asset_url ) {
			$sig_response = wp_remote_get( $sig_asset_url, self::github_request_args( 30 ) );
			if ( ! is_wp_error( $sig_response ) && 200 === (int) wp_remote_retrieve_response_code( $sig_response ) ) {
				$decoded  = json_decode( wp_remote_retrieve_body( $sig_response ), true );
				$sig_data = is_array( $decoded ) ? $decoded : null;
			}
		}

		return array(
			'contents' => wp_remote_retrieve_body( $download ),
			'sha256'   => $expected_sha256,
			'sig'      => $sig_data,
		);
	}

	







	private static function probe_mu_dir_writable() {
		$test_file = trailingslashit( WPMU_PLUGIN_DIR ) . '.wphc-writetest-' . wp_generate_password( 12, false, false );

		
		
		$written = false !== @file_put_contents( $test_file, 'wphc' ); 
		@unlink( $test_file ); 

		return $written;
	}

	





















	private static function install_release( $tag, $new_contents, $expected_sha256, $sig_data, $allow_same = false ) {
		$current_version = WP_HEALTH_CHECK_VERSION;

		
		
		$latest_tag = ltrim( (string) $tag, 'v' );
		$comparison = version_compare( $latest_tag, $current_version );
		if ( $comparison < 0 || ( 0 === $comparison && ! $allow_same ) ) {
			return array(
				'result'  => 'up_to_date',
				'current' => $current_version,
				'latest'  => $latest_tag,
			);
		}

		
		
		if ( ! self::probe_mu_dir_writable() ) {
			return array( 'result' => 'not_writable' );
		}
		$mu_dir = trailingslashit( WPMU_PLUGIN_DIR );

		
		
		
		
		
		

		
		
		
		$integrity_ok = true;
		if ( '' === $new_contents ) {
			$integrity_ok = false;
		} elseif ( 0 !== strpos( $new_contents, '<?php' ) ) {
			$integrity_ok = false;
		} elseif ( ! preg_match( '/Version:\s+' . preg_quote( $latest_tag, '/' ) . '(?:\s|$)/', $new_contents ) ) {
			
			
			$integrity_ok = false;
		} elseif ( empty( $expected_sha256 ) || ! hash_equals( $expected_sha256, hash( 'sha256', $new_contents ) ) ) {
			$integrity_ok = false;
		}

		if ( ! $integrity_ok ) {

			return array( 'result' => 'integrity_check_failed' );
		}

		
		
		
		
		
		
		
		
		
		if ( ! is_array( $sig_data ) || empty( $sig_data['signature'] ) ) {
			if ( WP_DEBUG ) {
				error_log( sprintf( 'wp-health-check: la release %s non ha la firma (.sig), aggiornamento annullato', (string) $tag ) ); 
			}

			return array( 'result' => 'integrity_check_failed' );
		}

		$sig_kid        = isset( $sig_data['kid'] ) ? sanitize_key( (string) $sig_data['kid'] ) : 'k2';
		$signed_message = "release\n" . (string) $tag . "\n" . $expected_sha256;
		if ( ! WPHC_Crypto::verify_central_signature( $signed_message, (string) $sig_data['signature'], $sig_kid, 'release' ) ) {
			if ( WP_DEBUG ) {
				error_log( sprintf( 'wp-health-check: firma della release %s non valida, aggiornamento annullato', (string) $tag ) ); 
			}

			return array( 'result' => 'integrity_check_failed' );
		}
		$signature_status = 'verified';

		
		
		
		$plugin_file = WP_HEALTH_CHECK_FILE;
		$backup_file = $plugin_file . '.bak';
		if ( false === @copy( $plugin_file, $backup_file ) ) {

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_backup_failed',
				'message' => __( 'Impossibile creare il backup prima di aggiornare.', 'wp-health-check' ),
				'http'    => 500,
			);
		}

		
		
		
		
		
		
		
		
		$tmp_file = $mu_dir . '.wphc-new-' . wp_generate_password( 12, false, false ) . '.tmp';
		if ( false === @file_put_contents( $tmp_file, $new_contents ) || false === @rename( $tmp_file, $plugin_file ) ) {
			@unlink( $tmp_file );

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_write_failed',
				'message' => __( 'Scrittura del nuovo file fallita.', 'wp-health-check' ),
				'http'    => 500,
			);
		}

		
		
		$written_contents = @file_get_contents( $plugin_file );
		if ( false === $written_contents || '' === $written_contents || 0 !== strpos( $written_contents, '<?php' ) ) {
			@copy( $backup_file, $plugin_file );

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_sanity_failed',
				'message' => __( 'Verifica post-scrittura fallita: ripristinato il backup precedente.', 'wp-health-check' ),
				'http'    => 500,
			);
		}
		

		
		
		
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $plugin_file, true );
		}

		
		
		
		
		@unlink( $backup_file ); 
		delete_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE );

		if ( WP_DEBUG ) {
			error_log( sprintf( 'wp-health-check: aggiornato da %1$s a %2$s', $current_version, $latest_tag ) ); 
		}

		return array(
			'result'    => 'updated',
			'from'      => $current_version,
			'to'        => $latest_tag,
			'signature' => $signature_status,
		);
	}

	

















	public static function perform_self_update() {
		
		
		$release = self::fetch_latest_release( 15 );
		if ( ! isset( $release['tag_name'] ) ) {
			$detail = isset( $release['detail'] ) ? ' (' . $release['detail'] . ')' : '';
			if ( 'wphc_update_network_error' === ( isset( $release['code'] ) ? $release['code'] : '' ) ) {
				return array(
					'result'  => 'error',
					'code'    => 'wphc_update_network_error',
					'message' => __( 'Impossibile contattare GitHub.', 'wp-health-check' ) . $detail,
					'http'    => 502,
				);
			}
			if ( 'wphc_update_bad_release' === ( isset( $release['code'] ) ? $release['code'] : '' ) ) {
				return array(
					'result'  => 'error',
					'code'    => 'wphc_update_bad_release',
					'message' => __( 'Risposta di GitHub non valida.', 'wp-health-check' ),
					'http'    => 502,
				);
			}

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_github_error',
				'message' => __( 'GitHub ha risposto con un errore nel recuperare la release.', 'wp-health-check' ) . $detail,
				'http'    => 502,
			);
		}

		
		$latest_tag = ltrim( (string) $release['tag_name'], 'v' );
		if ( ! version_compare( $latest_tag, WP_HEALTH_CHECK_VERSION, '>' ) ) {
			return array(
				'result'  => 'up_to_date',
				'current' => WP_HEALTH_CHECK_VERSION,
				'latest'  => $latest_tag,
			);
		}

		
		if ( ! self::probe_mu_dir_writable() ) {
			return array( 'result' => 'not_writable' );
		}

		
		
		$assets = self::download_release_assets( $release );
		if ( isset( $assets['result'] ) && isset( $release['wphc_source'] ) && 'hub' === $release['wphc_source'] ) {
			$github_release = self::fetch_latest_release( 15, true );
			if ( isset( $github_release['tag_name'] ) ) {
				$release = $github_release;
				$assets  = self::download_release_assets( $release );
			}
		}
		if ( isset( $assets['result'] ) ) {
			return $assets;
		}

		
		return self::install_release( (string) $release['tag_name'], $assets['contents'], $assets['sha256'], $assets['sig'] );
	}

	












	private static function perform_pushed_update( $package ) {
		$invalid_message = __( 'Pacchetto di aggiornamento non valido.', 'wp-health-check' );
		$invalid         = array(
			'result'  => 'error',
			'code'    => 'wphc_update_push_invalid',
			'message' => $invalid_message,
			'http'    => 400,
		);

		
		if (
			! is_array( $package )
			|| ! isset( $package['tag'], $package['sha256'], $package['sig'], $package['contents_gz_b64'] )
			|| ! self::is_release_tag( $package['tag'] )
			|| ! is_string( $package['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/i', $package['sha256'] )
			|| ! is_array( $package['sig'] )
			|| ! is_string( $package['contents_gz_b64'] ) || strlen( $package['contents_gz_b64'] ) > 2 * MB_IN_BYTES
			|| ! function_exists( 'gzdecode' )
		) {
			return $invalid;
		}

		$compressed = base64_decode( $package['contents_gz_b64'], true ); 
		$contents   = false === $compressed ? false : @gzdecode( $compressed, 4 * MB_IN_BYTES ); 
		if ( false === $contents || '' === $contents ) {
			return $invalid;
		}

		return self::install_release( $package['tag'], $contents, strtolower( $package['sha256'] ), $package['sig'], ! empty( $package['force'] ) );
	}

	









	public static function route_update( WP_REST_Request $request ) {
		
		
		WPHC_Auth::record_access();

		$body = $request->get_json_params();
		if ( is_array( $body ) && array_key_exists( 'package', $body ) ) {
			$outcome = self::perform_pushed_update( $body['package'] );
			$mode    = array( 'mode' => 'push' );
		} else {
			$outcome = self::perform_self_update();
			$mode    = array();
		}

		switch ( $outcome['result'] ) {
			case 'error':
				return new WP_Error( $outcome['code'], $outcome['message'], array( 'status' => $outcome['http'] ) );

			case 'updated':
				return rest_ensure_response(
					array_merge(
						array(
							'updated'   => true,
							'from'      => $outcome['from'],
							'to'        => $outcome['to'],
							'signature' => $outcome['signature'],
						),
						$mode
					)
				);

			case 'up_to_date':
				return rest_ensure_response(
					array_merge(
						array(
							'updated' => false,
							'reason'  => 'up_to_date',
							'current' => $outcome['current'],
							'latest'  => $outcome['latest'],
						),
						$mode
					)
				);

			default: 
				return rest_ensure_response(
					array_merge(
						array(
							'updated' => false,
							'reason'  => $outcome['result'],
						),
						$mode
					)
				);
		}
	}

	








	public static function get_latest_version( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE );
			if ( false !== $cached ) {
				return '' !== $cached ? $cached : null;
			}
		}

		$release = self::fetch_latest_release( 10 );
		$latest  = isset( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], 'v' ) : '';

		
		
		set_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE, $latest, HOUR_IN_SECONDS );

		return '' !== $latest ? $latest : null;
	}
}



















final class WPHC_Item_Updater {

	






	public static function release_update_lock() {
		delete_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK );
	}

	






	public static function maintenance_file_path() {
		return trailingslashit( ABSPATH ) . '.maintenance';
	}

	








	public static function clear_stale_maintenance( $max_age_seconds = 600 ) {
		$file = self::maintenance_file_path();
		if ( file_exists( $file ) && ( time() - (int) filemtime( $file ) ) > $max_age_seconds ) {
			wp_delete_file( $file );
		}
	}

	

















	private static function is_package_host_allowed( $package_url ) {
		if ( ! get_option( WPHC_Options::OPT_RESTRICT_OFFICIAL_ONLY, false ) ) {
			return true;
		}

		$host = wp_parse_url( (string) $package_url, PHP_URL_HOST );
		return in_array( $host, array( 'downloads.wordpress.org', 'api.wordpress.org' ), true );
	}

	















	private static function update_preflight( $requires_wp63 ) {
		WPHC_Auth::record_access();

		if ( ! get_option( WPHC_Options::OPT_UPDATES_ENABLED, true ) ) {
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

		
		
		
		set_site_transient( $transient_name, $transient );
	}

	


















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

		$skin     = new Automatic_Upgrader_Skin(); 
		$upgrader = ( 'plugin' === $type ) ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );

		
		
		
		
		
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

		
		
		
		
		
		
		
		
		WPHC_Update_Log::begin_api_update();
		$result = $upgrader->upgrade( $update );
		WPHC_Update_Log::end_api_update();

		$actual_version = get_bloginfo( 'version' );

		
		
		
		
		
		update_option( WPHC_Options::OPT_CORE_VERSION, $actual_version );

		if ( is_wp_error( $result ) || $actual_version !== $version_to ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Verifica post-aggiornamento fallita.', 'wp-health-check' );
			
			
			
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

		
		
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		wp_upgrade();

		if ( function_exists( 'opcache_reset' ) ) {
			
			
			
			opcache_reset();
		}

		
		
		
		
		
		
		
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

	



















	private static function perform_reactivate( $dry_run = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$candidates  = WPHC_Update_Log::get_reactivation_candidates();
		$all_plugins = get_plugins();

		
		
		
		
		
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

		
		
		
		foreach ( $missing as $candidate ) {
			WPHC_Update_Log::log_row( WPHC_Util::generate_correlation_id(), 'plugin', $candidate['target'], $candidate['name'], null, null, 'deleted', null, false );
		}

		$results     = array();
		$reactivated = 0;
		$failed      = 0;

		foreach ( $discrepancies as $candidate ) {
			
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

	






	public static function route_update_plugin( WP_REST_Request $request ) {
		$plugin = (string) $request->get_param( 'plugin' );
		if ( '' === trim( $plugin ) ) {
			return new WP_Error( 'wphc_missing_plugin', __( 'Campo "plugin" obbligatorio.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		$outcome = self::perform_item_update( 'plugin', $plugin, WPHC_Rest::request_wants_check( $request ) );

		return self::map_item_update_outcome( $outcome );
	}

	






	public static function route_update_theme( WP_REST_Request $request ) {
		$theme = (string) $request->get_param( 'theme' );
		if ( '' === trim( $theme ) ) {
			return new WP_Error( 'wphc_missing_theme', __( 'Campo "theme" obbligatorio.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		$outcome = self::perform_item_update( 'theme', $theme, WPHC_Rest::request_wants_check( $request ) );

		return self::map_item_update_outcome( $outcome );
	}

	







	public static function route_update_core( WP_REST_Request $request ) {
		$outcome = self::perform_core_update( WPHC_Rest::request_wants_check( $request ) );

		return self::map_item_update_outcome( $outcome );
	}

	
	
	

	








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
















final class WPHC_Webhook {

	
	
	
	
	
	
	
	

	








	public static function url() {
		$url = (string) get_option( WPHC_Options::OPT_WEBHOOK_URL, '' );
		if ( '' !== $url ) {
			return $url;
		}
		if ( get_option( WPHC_Options::OPT_WEBHOOK_DISABLED, false ) ) {
			return '';
		}
		return WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL;
	}

	











	public static function url_is_allowed( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! WPHC_Util::ip_is_public( $host ) ) {
			return false;
		}
		return true;
	}

	












	public static function build_outbound_signature( $event, $body_json, $secret ) {
		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$canonical = "POST\n{$event}\n" . hash( 'sha256', $body_json ) . "\n{$timestamp}\n{$nonce}";
		$signature = WPHC_Crypto::base64url_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );

		return array(
			'timestamp' => $timestamp,
			'nonce'     => $nonce,
			'signature' => $signature,
		);
	}

	





	public static function backoff_for_attempt( $attempt ) {
		$table = WP_HEALTH_CHECK_WEBHOOK_BACKOFF;
		$index = max( 0, min( (int) $attempt, count( $table ) - 1 ) );
		return (int) $table[ $index ];
	}

	













	private static function record_result( $ok, $code, $error, $message, $url, $job_id, $attempt, $duration_ms ) {
		$retry_at = null;
		if ( ! $ok && in_array( $error, array( 'wphc_webhook_transport_error', 'wphc_webhook_http_error' ), true ) && $attempt < WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
			$retry_at = gmdate( 'c', time() + self::backoff_for_attempt( $attempt ) );
		}

		update_option(
			WPHC_Options::OPT_WEBHOOK_LAST,
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

	













	public static function dispatch_payload( $event, array $payload, $job_id, $attempt ) {
		$url = self::url();
		if ( '' === $url ) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => null,
				'retryable' => false,
			);
		}

		$secret = get_option( WPHC_Options::OPT_TOKEN );
		if ( empty( $secret ) ) {
			self::record_result( false, 0, 'wphc_webhook_not_enrolled', '', $url, $job_id, $attempt, null );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_not_enrolled',
				'retryable' => false,
			);
		}

		if ( get_option( WPHC_Options::OPT_REVOKED_AT ) ) {
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			wp_clear_scheduled_hook( WPHC_Options::CRON_WEBHOOK_RETRY );
			self::record_result( false, 0, 'wphc_webhook_revoked', '', $url, $job_id, $attempt, null );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_revoked',
				'retryable' => false,
			);
		}

		if ( ! self::url_is_allowed( $url ) ) {
			self::record_result( false, 0, 'wphc_webhook_url_blocked', '', $url, $job_id, $attempt, null );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_url_blocked',
				'retryable' => false,
			);
		}

		$body_json = wp_json_encode( $payload );
		$sig       = self::build_outbound_signature( $event, $body_json, $secret );

		$headers = array(
			'Content-Type'      => 'application/json; charset=utf-8',
			'Accept'            => 'application/json',
			'User-Agent'        => 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION,
			'X-WPHC-Timestamp'  => (string) $sig['timestamp'],
			'X-WPHC-Nonce'      => $sig['nonce'],
			'X-WPHC-Signature'  => $sig['signature'],
			'X-WPHC-Event'      => $event,
			'X-WPHC-Secret-Kid' => (string) get_option( WPHC_Options::OPT_SECRET_KID, '' ),
			'X-WPHC-Site'       => WPHC_Util::normalize_site_url(),
			'X-WPHC-Delivery'   => (string) $job_id,
			'X-WPHC-Attempt'    => (string) $attempt,
		);

		$started = microtime( true );
		
		
		
		$response    = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => WP_HEALTH_CHECK_WEBHOOK_TIMEOUT,
				'redirection' => 0, 
				'sslverify'   => true, 
				'headers'     => $headers,
				'body'        => $body_json,
			)
		);
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			self::record_result( false, 0, 'wphc_webhook_transport_error', $message, $url, $job_id, $attempt, $duration_ms );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_transport_error',
				'retryable' => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			self::record_result( true, $code, null, '', $url, $job_id, $attempt, $duration_ms );
			return array(
				'ok'        => true,
				'code'      => $code,
				'error'     => null,
				'retryable' => false,
			);
		}

		
		
		$retryable = ( 408 === $code || 429 === $code || $code >= 500 );
		$message   = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		self::record_result( false, $code, 'wphc_webhook_http_error', $message, $url, $job_id, $attempt, $duration_ms );

		return array(
			'ok'        => false,
			'code'      => $code,
			'error'     => 'wphc_webhook_http_error',
			'retryable' => $retryable,
		);
	}

	




	public static function maybe_retry() {
		$pending = get_option( WPHC_Options::OPT_WEBHOOK_PENDING );
		if ( ! is_array( $pending ) ) {
			return;
		}
		if ( false !== get_transient( WPHC_Options::TRANSIENT_WEBHOOK_RETRY_LOCK ) ) {
			return;
		}
		set_transient( WPHC_Options::TRANSIENT_WEBHOOK_RETRY_LOCK, time(), 5 * MINUTE_IN_SECONDS );

		$attempt = (int) $pending['attempts'] + 1;
		$result  = self::dispatch_payload( $pending['event'], $pending['body'], $pending['job_id'], $attempt );

		if ( $result['ok'] || ! $result['retryable'] || $attempt >= WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
		} else {
			$pending['attempts'] = $attempt;
			update_option( WPHC_Options::OPT_WEBHOOK_PENDING, $pending, false );
			wp_schedule_single_event( time() + self::backoff_for_attempt( $attempt ), WPHC_Options::CRON_WEBHOOK_RETRY );
		}

		delete_transient( WPHC_Options::TRANSIENT_WEBHOOK_RETRY_LOCK );
	}
}



















final class WPHC_Bulk {

	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	

	








	private static function get_job( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( WPHC_Options::OPT_BULK_JOB, 'options' );
		}
		$job = get_option( WPHC_Options::OPT_BULK_JOB );
		return is_array( $job ) ? $job : null;
	}

	







	private static function save_job( array $job ) {
		$job['stall_after_ts'] = self::compute_stall_after_ts( $job );
		update_option( WPHC_Options::OPT_BULK_JOB, $job, false );
		self::sync_summary( $job );
	}

	











	private static function compute_stall_after_ts( array $job ) {
		foreach ( $job['items'] as $item ) {
			if ( 'running' === $item['state'] && null !== $item['claimed_ts'] ) {
				$timeout = ( 'core' === $item['type'] ) ? WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT : WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT;
				return $item['claimed_ts'] + $timeout + WP_HEALTH_CHECK_BULK_STALL_GRACE;
			}
		}
		return $job['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE;
	}

	






	private static function sync_summary( array $job ) {
		update_option(
			WPHC_Options::OPT_BULK_STATUS,
			array(
				'job_id'         => $job['job_id'],
				'status'         => $job['status'],
				'abort_reason'   => $job['abort_reason'],
				'total'          => $job['counters']['total'],
				'done'           => $job['counters']['done'],
				'updated'        => $job['counters']['updated'],
				'skipped'        => $job['counters']['skipped'],
				'warnings'       => $job['counters']['warnings'],
				'failed'         => $job['counters']['failed'],
				'created_at'     => gmdate( 'c', $job['created_ts'] ),
				'finished_at'    => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
				'next_run_ts'    => $job['next_run_ts'],
				'stall_after_ts' => $job['stall_after_ts'],
			),
			true
		);
	}

	







	public static function summary() {
		$status = get_option( WPHC_Options::OPT_BULK_STATUS );
		if ( ! is_array( $status ) ) {
			return null;
		}

		
		
		
		$stall_after = isset( $status['stall_after_ts'] )
			? (int) $status['stall_after_ts']
			: ( (int) $status['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE );

		$active  = in_array( $status['status'], array( 'queued', 'running' ), true );
		$stalled = $active && ( time() > $stall_after );

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

	





	private static function is_active( array $job ) {
		return in_array( $job['status'], array( 'queued', 'running' ), true );
	}

	






	private static function recount( array &$job ) {
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

	






	private static function new_item( $type, $target ) {
		return array(
			'type'         => $type,
			'target'       => $target,
			
			
			
			
			
			
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

	



















	private static function normalize_items( $raw ) {
		$items      = array();
		$rejected   = array();
		$seen       = array();
		$candidates = array();

		if ( is_array( $raw ) ) {
			if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
				foreach ( $raw['items'] as $entry ) {
					
					
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
				$target = 'core'; 
			}

			if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
				$rejected[] = array(
					'type'   => $type,
					'target' => $target,
					'reason' => 'invalid_type',
				);
				continue;
			}

			
			
			
			
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
				continue; 
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

			$items[] = self::new_item( $type, $target );
		}

		return array(
			'items'    => $items,
			'rejected' => $rejected,
			'error'    => null,
		);
	}

	











	private static function acquire_lock() {
		if ( false !== get_transient( WPHC_Options::TRANSIENT_BULK_LOCK ) ) {
			return false;
		}
		set_transient( WPHC_Options::TRANSIENT_BULK_LOCK, 1, WP_HEALTH_CHECK_BULK_LOCK_TTL );
		register_shutdown_function( array( 'WPHC_Bulk', 'release_lock' ) );
		return true;
	}

	



	public static function release_lock() {
		delete_transient( WPHC_Options::TRANSIENT_BULK_LOCK );
	}

	









	private static function schedule_tick( $delay = null ) {
		if ( null === $delay ) {
			$delay = WP_HEALTH_CHECK_BULK_TICK_GAP;
		}
		$when = time() + max( 0, (int) $delay );
		if ( false === wp_next_scheduled( WPHC_Options::CRON_BULK_TICK ) ) {
			wp_schedule_single_event( $when, WPHC_Options::CRON_BULK_TICK );
		}
		return $when;
	}

	
	private static function unschedule_ticks() {
		wp_clear_scheduled_hook( WPHC_Options::CRON_BULK_TICK );
	}

	








	private static function next_delay( array $job ) {
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

	








	public static function maybe_rearm() {
		$status = get_option( WPHC_Options::OPT_BULK_STATUS );
		if ( ! is_array( $status ) || ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		if ( false !== wp_next_scheduled( WPHC_Options::CRON_BULK_TICK ) ) {
			return;
		}
		if ( time() < (int) $status['next_run_ts'] ) {
			return;
		}
		if ( false !== get_transient( WPHC_Options::TRANSIENT_BULK_REARM_LOCK ) ) {
			return;
		}
		set_transient( WPHC_Options::TRANSIENT_BULK_REARM_LOCK, 1, MINUTE_IN_SECONDS );

		self::schedule_tick( 0 );
		spawn_cron();
	}

	







	private static function next_item_index( array $job, $now ) {
		foreach ( $job['items'] as $index => $item ) {
			if ( 'pending' === $item['state'] && $item['next_after'] <= $now ) {
				return $index;
			}
		}
		return null;
	}

	









	private static function classify_result( $result ) {
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

	





	private static function backoff_for_attempt( $attempts ) {
		$table = WP_HEALTH_CHECK_BULK_BACKOFF;
		$index = max( 0, min( (int) $attempts, count( $table ) - 1 ) );
		return (int) $table[ $index ];
	}

	







	private static function apply_outcome( array &$job, $index, array $outcome ) {
		$item   = &$job['items'][ $index ];
		$result = isset( $outcome['result'] ) ? $outcome['result'] : 'failed';
		$class  = self::classify_result( $result );

		$item['result'] = $result;
		if ( isset( $outcome['name'] ) ) {
			$item['name'] = $outcome['name'];
		}
		
		
		
		
		
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
					$item['next_after'] = time() + self::backoff_for_attempt( $item['attempts'] );
				}
				break;

			case 'defer':
				
				
				
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
				self::abort_job( $job, $result );
				return;
		}

		unset( $item );
		self::recount( $job );
	}

	







	private static function abort_job( array &$job, $reason ) {
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

		self::recount( $job );
	}

	










	private static function reap_stuck_items( array &$job, $now ) {
		$reaped = false;

		foreach ( $job['items'] as &$item ) {
			if ( 'running' !== $item['state'] || null === $item['claimed_ts'] ) {
				continue;
			}
			
			
			
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
				$item['next_after'] = $now + self::backoff_for_attempt( $item['attempts'] );
			}
			$reaped = true;
		}
		unset( $item );

		if ( $reaped ) {
			self::recount( $job );
		}

		return $reaped;
	}

	








	private static function maybe_reap( array &$job ) {
		if ( ! self::is_active( $job ) ) {
			return false;
		}
		if ( ( time() - $job['created_ts'] ) < WP_HEALTH_CHECK_BULK_JOB_TTL ) {
			return false;
		}

		self::abort_job( $job, 'job_timeout' );
		return true;
	}

	








	private static function maybe_finalize( array &$job ) {
		if ( 'aborted' === $job['status'] ) {
			return true; 
		}
		if ( $job['counters']['pending'] > 0 ) {
			return false;
		}

		$job['status']      = ( $job['counters']['failed'] > 0 ) ? 'completed_with_errors' : 'completed';
		$job['finished_ts'] = time();

		return true;
	}

	





	private static function finish( array &$job ) {
		self::unschedule_ticks();
		self::save_job( $job );

		if ( empty( $job['webhook']['sent'] ) ) {
			self::send_webhook_for_job( $job );
			self::save_job( $job );
		}
	}

	








	public static function run_tick() {
		if ( ! self::acquire_lock() ) {
			return;
		}

		$job = self::get_job( true );
		if ( null === $job || ! self::is_active( $job ) ) {
			self::release_lock();
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); 
		}
		WPHC_Item_Updater::clear_stale_maintenance();

		$job['status'] = 'running';
		$job['ticks']  = isset( $job['ticks'] ) ? $job['ticks'] + 1 : 1;
		if ( null === $job['started_ts'] ) {
			$job['started_ts'] = time();
		}

		self::reap_stuck_items( $job, time() );

		if ( self::maybe_reap( $job ) ) {
			self::finish( $job );
			self::release_lock();
			return;
		}

		self::save_job( $job );

		$start = microtime( true );
		$done  = 0;
		while ( $done < WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK ) {
			if ( $done > 0 && ( microtime( true ) - $start ) > WP_HEALTH_CHECK_BULK_TIME_BUDGET ) {
				break;
			}

			$job   = self::get_job( true );
			$index = self::next_item_index( $job, time() );
			if ( null === $index ) {
				break;
			}

			$item_type   = $job['items'][ $index ]['type'];
			$item_target = $job['items'][ $index ]['target'];

			$job['items'][ $index ]['state']      = 'running';
			$job['items'][ $index ]['claimed_ts'] = time();
			
			
			
			
			$job['next_run_ts'] = time() + WP_HEALTH_CHECK_BULK_TICK_GAP;
			self::save_job( $job );

			if ( 'core' === $item_type ) {
				
				
				
				
				
				
				set_transient( WPHC_Options::TRANSIENT_BULK_LOCK, 1, WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT );
			}

			$outcome = ( 'core' === $item_type )
				? WPHC_Item_Updater::perform_core_update( false, 'cron' )
				: WPHC_Item_Updater::perform_item_update( $item_type, $item_target, false, 'cron' );

			$job = self::get_job( true );
			self::apply_outcome( $job, $index, $outcome );
			self::save_job( $job );

			if ( 'aborted' === $job['status'] ) {
				break;
			}

			++$done;

			if ( 'core' === $item_type ) {
				
				
				
				
				
				
				
				break;
			}
		}

		if ( self::maybe_finalize( $job ) ) {
			self::finish( $job );
		} else {
			$job['next_run_ts'] = self::schedule_tick( self::next_delay( $job ) );
			self::save_job( $job );
		}

		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
		self::release_lock();
	}

	











	private static function body_is_core_only( array $body ) {
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

	












	private static function enqueue_preflight( $requires_wp63 = true ) {
		WPHC_Auth::record_access();

		if ( ! get_option( WPHC_Options::OPT_UPDATES_ENABLED, true ) ) {
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

	
	
	

	








	public static function route_enqueue( WP_REST_Request $request ) {
		
		
		
		$body      = (array) $request->get_json_params();
		$preflight = self::enqueue_preflight( ! self::body_is_core_only( $body ) );
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
		$current_job = self::get_job( true );

		if ( null !== $current_job && self::is_active( $current_job ) ) {
			$summary = self::summary();
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

			
			
			
			self::abort_job( $current_job, 'replaced_by_new_job' );
			self::finish( $current_job );
		}

		$normalized = self::normalize_items( $body );
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
			'job_id'        => WPHC_Util::generate_correlation_id(),
			'status'        => 'queued',
			'abort_reason'  => null,
			'created_ts'    => $now,
			'started_ts'    => null,
			'finished_ts'   => null,
			'next_run_ts'   => $now,
			'ticks'         => 0,
			'source'        => WPHC_Update_Log::detect_update_source(),
			'requested_ip'  => WPHC_Util::get_client_ip(),
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
		self::recount( $job );
		self::save_job( $job );

		self::schedule_tick( 0 );
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

	















	public static function route_status( WP_REST_Request $request ) {
		WPHC_Auth::record_access();

		$job = self::get_job( true );
		if ( null === $job ) {
			return rest_ensure_response(
				array(
					'site' => WPHC_Util::normalize_site_url(),
					'job'  => null,
				)
			);
		}

		if ( self::is_active( $job ) && self::reap_stuck_items( $job, time() ) ) {
			if ( self::maybe_finalize( $job ) ) {
				self::finish( $job );
			} else {
				self::save_job( $job );
				self::schedule_tick( 0 );
				spawn_cron();
			}
		}

		if ( self::maybe_reap( $job ) ) {
			self::finish( $job );
		}

		
		
		
		$stall_after = isset( $job['stall_after_ts'] )
			? (int) $job['stall_after_ts']
			: ( (int) $job['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE );

		$show_items = '0' !== (string) $request->get_param( 'items' );

		$payload = array(
			'job_id'       => $job['job_id'],
			'status'       => $job['status'],
			'abort_reason' => $job['abort_reason'],
			'created_at'   => gmdate( 'c', $job['created_ts'] ),
			'started_at'   => $job['started_ts'] ? gmdate( 'c', $job['started_ts'] ) : null,
			'finished_at'  => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
			'next_run_at'  => gmdate( 'c', (int) $job['next_run_ts'] ),
			'stalled'      => self::is_active( $job ) && ( time() > $stall_after ),
			'counters'     => $job['counters'],
			'webhook'      => $job['webhook'],
		);
		if ( $show_items ) {
			$payload['items'] = $job['items'];
		}

		return rest_ensure_response(
			array(
				'site'         => WPHC_Util::normalize_site_url(),
				'generated_at' => gmdate( 'c' ),
				'job'          => $payload,
			)
		);
	}

	







	public static function route_cancel() {
		$job = self::get_job( true );
		if ( null === $job || ! self::is_active( $job ) ) {
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
		self::recount( $job );
		self::finish( $job );

		return rest_ensure_response(
			array(
				'site'   => WPHC_Util::normalize_site_url(),
				'job_id' => $job['job_id'],
				'status' => $job['status'],
			)
		);
	}

	







	private static function build_webhook_payload( array $job ) {
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
			'site'            => WPHC_Util::normalize_site_url(),
			'agent_version'   => WP_HEALTH_CHECK_VERSION,
			'secret_kid'      => (string) get_option( WPHC_Options::OPT_SECRET_KID, '' ),
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

	







	private static function send_webhook_for_job( array &$job ) {
		++$job['webhook']['attempts'];
		$job['webhook']['sending_ts'] = time();

		$payload = self::build_webhook_payload( $job );
		$result  = WPHC_Webhook::dispatch_payload( 'wphc.bulk_update.completed', $payload, $job['job_id'], $job['webhook']['attempts'] );

		$job['webhook']['code']       = $result['code'];
		$job['webhook']['last_error'] = $result['ok'] ? null : $result['error'];

		if ( $result['ok'] ) {
			$job['webhook']['sent']    = true;
			$job['webhook']['sent_ts'] = time();
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			return;
		}

		if ( $result['retryable'] && $job['webhook']['attempts'] < WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
			update_option(
				WPHC_Options::OPT_WEBHOOK_PENDING,
				array(
					'job_id'   => $job['job_id'],
					'event'    => 'wphc.bulk_update.completed',
					'body'     => $payload,
					'attempts' => $job['webhook']['attempts'],
				),
				false
			);
			wp_schedule_single_event( time() + WPHC_Webhook::backoff_for_attempt( $job['webhook']['attempts'] ), WPHC_Options::CRON_WEBHOOK_RETRY );
		}
	}
}


















final class WPHC_Admin {

	
	
	
	
	
	
	
	
	
	

	





	public static function register_tab( $tabs ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $tabs;
		}

		$tabs['wp-health-check'] = __( 'WP Health Check', 'wp-health-check' );

		return $tabs;
	}

	






	public static function ajax_test_endpoint() {
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

		
		$query_args = array( '_cb' => wp_generate_password( 16, false, false ) );
		if ( $endpoint['fresh'] ) {
			$query_args['fresh'] = '1';
		}
		$url = add_query_arg( $query_args, rest_url( 'health-check/v1/' . $endpoint['path'] ) );

		
		
		$headers = array();
		$token   = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
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

	





	public static function ajax_view_log() {
		check_ajax_referer( 'wphc_view_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
		}

		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		$result = WPHC_Update_Log::get_entries( $type, $source, 50, $offset );

		wp_send_json_success(
			array(
				'entries' => $result['entries'],
				'total'   => $result['total'],
			)
		);
	}

	









	public static function ajax_reveal_secret() {
		check_ajax_referer( 'wphc_reveal_secret' );
		if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
		}

		$secret = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
		if ( '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'Nessun segreto registrato.', 'wp-health-check' ) ), 404 );
		}

		$user = wp_get_current_user();
		WPHC_Update_Log::log_row(
			WPHC_Util::generate_correlation_id(),
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

	






	public static function handle_self_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_self_update' );

		$outcome       = WPHC_Self_Updater::perform_self_update();
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

			default: 
				$redirect_args['wphc_update_failed'] = '1';
				$redirect_args['reason']             = $outcome['result'];
				break;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'site-health.php' ) ) );
		exit;
	}

	







	public static function handle_clear_caches() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_clear_caches' );

		
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_SERVER_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_PUBLIC_IP );
		delete_transient( WPHC_Options::TRANSIENT_PUBLIC_IP_RETRY_LOCK );

		
		
		
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

	






	public static function handle_delete_thumbnail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_delete_thumbnail' );

		$attachment_id = (int) get_option( WPHC_Options::OPT_THUMB_ID );
		if ( ! $attachment_id ) {
			
			
			$attachment_id = attachment_url_to_postid( (string) get_option( WPHC_Options::OPT_THUMB ) );
		}
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		delete_option( WPHC_Options::OPT_THUMB );
		delete_option( WPHC_Options::OPT_THUMB_ID );
		delete_option( WPHC_Options::OPT_THUMB_ERROR );
		delete_option( WPHC_Options::OPT_THUMB_AT );
		delete_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK );

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

	







	public static function handle_regenerate_thumbnail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_regenerate_thumbnail' );

		WPHC_Thumbnail::maybe_generate( true );

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

	




	public static function handle_reset_enrollment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_reset_enrollment' );

		WPHC_Enrollment::reset();

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

	







	public static function handle_toggle_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_toggle_updates' );

		update_option( WPHC_Options::OPT_UPDATES_ENABLED, ! empty( $_POST['wphc_updates_enabled'] ) ); 
		update_option( WPHC_Options::OPT_RESTRICT_OFFICIAL_ONLY, ! empty( $_POST['wphc_restrict_official_only'] ) ); 

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

	














	public static function handle_save_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_save_webhook' );

		$raw      = isset( $_POST['wphc_webhook_url'] ) ? trim( (string) wp_unslash( $_POST['wphc_webhook_url'] ) ) : ''; 
		$disabled = ! empty( $_POST['wphc_webhook_disabled'] ); 

		update_option( WPHC_Options::OPT_WEBHOOK_DISABLED, $disabled );
		if ( $disabled ) {
			
			
			
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			wp_clear_scheduled_hook( WPHC_Options::CRON_WEBHOOK_RETRY );
		}

		if ( '' === $raw ) {
			delete_option( WPHC_Options::OPT_WEBHOOK_URL );
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			wp_clear_scheduled_hook( WPHC_Options::CRON_WEBHOOK_RETRY );
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
		} elseif ( ! WPHC_Webhook::url_is_allowed( $url ) ) {
			$reason = 'blocked_host';
		}

		if ( '' !== $reason ) {
			
			
			
			set_transient( WPHC_Options::TRANSIENT_WEBHOOK_BAD_URL_PREFIX . get_current_user_id(), $raw, 5 * MINUTE_IN_SECONDS );
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

		update_option( WPHC_Options::OPT_WEBHOOK_URL, $url, false );

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

	





	public static function handle_test_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_test_webhook' );

		$payload = array(
			'schema'        => 'wphc.test/1',
			'event'         => 'wphc.test',
			'site'          => WPHC_Util::normalize_site_url(),
			'agent_version' => WP_HEALTH_CHECK_VERSION,
			'secret_kid'    => (string) get_option( WPHC_Options::OPT_SECRET_KID, '' ),
			'generated_at'  => gmdate( 'c' ),
		);
		WPHC_Webhook::dispatch_payload( 'wphc.test', $payload, 'test-' . WPHC_Util::generate_correlation_id(), 1 );

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
}















final class WPHC_Admin_View {

	






	public static function render_tab( $tab ) {
		if ( 'wp-health-check' !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_enrolled             = ! empty( get_option( WPHC_Options::OPT_TOKEN ) );
		$signed_site_url         = (string) get_option( WPHC_Options::OPT_SITE_URL, '' );
		$enrolled_at             = get_option( WPHC_Options::OPT_ENROLLED_AT );
		$enrolled_ip             = get_option( WPHC_Options::OPT_ENROLLED_IP );
		$last_request_at         = get_option( WPHC_Options::OPT_LAST_REQUEST_AT );
		$last_request_ip         = get_option( WPHC_Options::OPT_LAST_REQUEST_IP );
		$trust_proxy             = (bool) get_option( WPHC_Options::OPT_TRUST_PROXY, false );
		$last_enroll_error       = get_option( WPHC_Options::OPT_LAST_ENROLL_ERROR );
		$updates_via_api_enabled = (bool) get_option( WPHC_Options::OPT_UPDATES_ENABLED, true );
		$restrict_official_only  = (bool) get_option( WPHC_Options::OPT_RESTRICT_OFFICIAL_ONLY, false );
		$thumbnail               = get_option( WPHC_Options::OPT_THUMB );
		$current_secret          = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
		$secret_kid              = (string) get_option( WPHC_Options::OPT_SECRET_KID, '' );
		$secret_rotated_at       = get_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );
		$secret_revoked_at       = get_option( WPHC_Options::OPT_REVOKED_AT );
		$protocol_version        = (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 );
		
		
		
		
		
		$webhook_url_raw   = (string) get_option( WPHC_Options::OPT_WEBHOOK_URL, '' );
		$webhook_disabled  = (bool) get_option( WPHC_Options::OPT_WEBHOOK_DISABLED, false );
		$webhook_url       = WPHC_Webhook::url();
		$webhook_last      = get_option( WPHC_Options::OPT_WEBHOOK_LAST );
		$webhook_url_field = $webhook_url_raw;
		$webhook_bad_url   = get_transient( WPHC_Options::TRANSIENT_WEBHOOK_BAD_URL_PREFIX . get_current_user_id() );
		if ( false !== $webhook_bad_url ) {
			$webhook_url_field = $webhook_bad_url;
			delete_transient( WPHC_Options::TRANSIENT_WEBHOOK_BAD_URL_PREFIX . get_current_user_id() );
		}

		
		
		
		
		
		
		$secret_masked = '';
		if ( '' !== $current_secret ) {
			$secret_masked = ( strlen( $current_secret ) > 10 )
				? substr( $current_secret, 0, 6 ) . str_repeat( '•', 8 ) . substr( $current_secret, -4 )
				: str_repeat( '•', strlen( $current_secret ) );
		}
		$secret_fingerprint = ( '' !== $current_secret ) ? substr( hash( 'sha256', $current_secret ), 0, 12 ) : '';
		$thumb_error        = get_option( WPHC_Options::OPT_THUMB_ERROR );

		$candidates       = WPHC_Util::candidate_site_urls();
		$canonical_home   = WPHC_Util::normalize_site_url();
		$latest_version   = WPHC_Self_Updater::get_latest_version();
		$update_available = ( null !== $latest_version ) && version_compare( $latest_version, WP_HEALTH_CHECK_VERSION, '>' );

		
		
		
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

		<?php if ( isset( $_GET['wphc_updated'] ) ) : ?>
			<div class="notice notice-success"><p>
				<?php
				$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; 
				printf(
					
					esc_html__( 'Plugin aggiornato alla versione %s.', 'wp-health-check' ),
					'<code>' . esc_html( $to ) . '</code>'
				);
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_uptodate'] ) ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'Il plugin e\' gia\' aggiornato all\'ultima versione disponibile.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_update_failed'] ) ) : ?>
			<div class="notice notice-error"><p>
				<?php
				$reason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : ''; 
				printf(
					
					esc_html__( 'Aggiornamento non riuscito (%s). Nessuna modifica al file del plugin.', 'wp-health-check' ),
					'<code>' . esc_html( $reason ) . '</code>'
				);
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_cleared'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Cache dell\'agent svuotate e aggiornamenti ricontrollati.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_reset'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_updates_toggled'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Preferenza sugli aggiornamenti via API salvata.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_thumb_deleted'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Anteprima del sito eliminata: verra\' rigenerata alla prossima chiamata /health.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_thumb_regenerated'] ) ) : ?>
			<?php if ( get_option( WPHC_Options::OPT_THUMB ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Anteprima rigenerata con successo.', 'wp-health-check' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Rigenerazione non riuscita: vedi il dettaglio dell\'errore nella riga "Anteprima sito" qui sotto.', 'wp-health-check' ); ?></p></div>
			<?php endif; ?>
		<?php elseif ( isset( $_GET['wphc_webhook_saved'] ) ) : ?>
			<div class="notice notice-success"><p>
				<?php
				echo '' === $webhook_url
					? esc_html__( 'Webhook disattivato.', 'wp-health-check' )
					: esc_html__( 'URL del webhook salvato.', 'wp-health-check' );
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_webhook_invalid'] ) ) : ?>
			<div class="notice notice-error"><p>
				<?php
				$webhook_reason        = isset( $_GET['reason'] ) ? sanitize_key( wp_unslash( $_GET['reason'] ) ) : ''; 
				$webhook_reason_labels = array(
					'not_https'    => __( 'L\'URL deve usare https.', 'wp-health-check' ),
					'blocked_host' => __( 'L\'host indicato e\' un indirizzo privato o di loopback: non e\' consentito.', 'wp-health-check' ),
					'bad_url'      => __( 'URL non valido.', 'wp-health-check' ),
				);
				$webhook_reason_label  = isset( $webhook_reason_labels[ $webhook_reason ] ) ? $webhook_reason_labels[ $webhook_reason ] : __( 'URL non valido.', 'wp-health-check' );
				echo esc_html( $webhook_reason_label ) . ' <code>' . esc_html( $webhook_reason ) . '</code>';
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_webhook_tested'] ) ) : ?>
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
			
			printf( esc_html__( 'Tema attivo: %s. Conteggi degli aggiornamenti letti dai transient del cron (come la bacheca); usa il pulsante "Svuota cache e ricontrolla" se sembrano non aggiornati.', 'wp-health-check' ), '<strong>' . esc_html( $active_theme_name ) . '</strong>' ); 
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
}















final class WPHC_Plugin {

	









	public static function boot() {
		
		
		
		
		
		
		
		
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

		

		
		
		

		add_filter( 'rest_pre_serve_request', array( 'WPHC_Cors', 'reassert_headers' ), 20, 3 );

		add_filter( 'rest_pre_dispatch', array( 'WPHC_Cors', 'handle_options_preflight' ), 10, 3 );

		
		
		

		add_action( 'rest_api_init', array( 'WPHC_Rest', 'register_routes' ) );

		
		
		add_action( 'init', array( 'WPHC_Update_Log', 'maybe_install_schema' ) );

		add_filter( 'upgrader_pre_install', array( 'WPHC_Update_Log', 'snapshot_version_before_upgrade' ), 10, 2 );

		add_action( 'upgrader_process_complete', array( 'WPHC_Update_Log', 'log_wp_initiated_update' ), 10, 2 );

		add_action( 'deactivated_plugin', array( 'WPHC_Update_Log', 'log_plugin_deactivation' ), 10, 2 );

		add_action( 'delete_plugin', array( 'WPHC_Update_Log', 'snapshot_plugin_before_delete' ) );

		add_action( 'deleted_plugin', array( 'WPHC_Update_Log', 'log_plugin_deletion' ), 10, 2 );

		add_action( 'init', array( 'WPHC_Update_Log', 'maybe_log_core_version_change' ) );

		
		add_action( 'init', array( 'WPHC_Bulk', 'maybe_rearm' ) );

		add_action( WPHC_Options::CRON_BULK_TICK, array( 'WPHC_Bulk', 'run_tick' ) );

		add_action( WPHC_Options::CRON_WEBHOOK_RETRY, array( 'WPHC_Webhook', 'maybe_retry' ) );

		
		
		add_action( 'init', array( 'WPHC_Autologin', 'maybe_consume' ) );

		add_action( 'wp_login', array( 'WPHC_Users', 'record_last_login' ), 10, 2 );

		
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






WPHC_Plugin::boot();










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

			WPHC_Enrollment::reset();

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

			$current = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
			if ( '' === $current ) {
				WP_CLI::error( 'Nessun segreto registrato (sito non ancora enrollato).' );
			}

			$revoked_at = get_option( WPHC_Options::OPT_REVOKED_AT );
			$rotated_at = get_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );

			WP_CLI::log( 'Fingerprint: ' . substr( hash( 'sha256', $current ), 0, 12 ) );
			WP_CLI::log( 'Protocollo: ' . (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 ) );
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

			$is_enrolled = ! empty( get_option( WPHC_Options::OPT_TOKEN ) );
			$last_error  = get_option( WPHC_Options::OPT_LAST_ENROLL_ERROR );
			$thumb_error = get_option( WPHC_Options::OPT_THUMB_ERROR );

			WP_CLI::log( 'Versione agent: ' . WP_HEALTH_CHECK_VERSION );
			WP_CLI::log( 'Enrollment: ' . ( $is_enrolled ? 'registrato' : 'non registrato' ) );
			WP_CLI::log( 'Protocollo: ' . (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 ) );
			WP_CLI::log( 'Revocato: ' . ( get_option( WPHC_Options::OPT_REVOKED_AT ) ? 'si\'' : 'no' ) );
			WP_CLI::log( 'Ultimo enroll fallito: ' . ( is_array( $last_error ) ? ( $last_error['code'] . ' — ' . $last_error['reason'] ) : 'nessuno' ) );
			WP_CLI::log( 'Thumbnail: ' . ( get_option( WPHC_Options::OPT_THUMB ) ? 'presente' : ( is_array( $thumb_error ) ? ( 'errore: ' . $thumb_error['code'] ) : 'non ancora generata' ) ) );
		}
	}

	WP_CLI::add_command( 'health-check', 'WPHC_CLI_Command' );
}
