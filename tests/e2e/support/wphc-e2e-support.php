<?php
/**
 * Plugin Name: WPHC E2E support (SOLO per i test)
 * Description: mu-plugin di supporto dell'ambiente wp-env e2e di wp-health-check. NON va mai installato su un sito reale.
 *
 * Cosa fa, in ordine:
 *  1. forza HTTPS (le rotte del plugin lo pretendono: il sito di test e' servito in HTTP sulla
 *     porta 8890, quindi si dichiara $_SERVER['HTTPS'] come farebbe un reverse proxy con TLS);
 *  2. espone un piccolo namespace REST "wphc-e2e/v1" (protetto da una chiave fissa, solo
 *     localhost) per azzerare e leggere lo stato del plugin, scrivere option, far girare gli
 *     eventi di cron senza aspettare e impostare l'esito del finto ricevente del webhook;
 *  3. intercetta le POST verso l'IP pubblico di test 93.184.216.34 (il "ricevente" del
 *     webhook): le registra (URL, header, body) e risponde con lo status scelto, senza uscire
 *     in rete.
 *
 * Il file e' copiato dalla suite in tests/e2e/.work/mu-plugins/, la cartella montata come
 * wp-content/mu-plugins del container: il codice del plugin sotto test non e' mai modificato.
 *
 * @package WP_Health_Check
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// 1. HTTPS forzato (prima che WordPress calcoli home_url() e is_ssl())
// ---------------------------------------------------------------------------
$_SERVER['HTTPS'] = 'on';

/** Chiave fissa del namespace di supporto: l'ambiente e' locale e usa-e-getta. */
const WPHC_E2E_KEY = 'wphc-e2e-local-only';

/** Host (IP pubblico letterale) del finto ricevente del webhook. */
const WPHC_E2E_WEBHOOK_HOST = '93.184.216.34';

// ---------------------------------------------------------------------------
// 2. Finto ricevente del webhook
// ---------------------------------------------------------------------------
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://' . WPHC_E2E_WEBHOOK_HOST . '/' ) ) {
			return $preempt;
		}

		// Registra la richiesta cosi' come il plugin l'ha costruita.
		$calls   = get_option( 'e2e_webhook_calls', array() );
		$calls[] = array(
			'url'     => (string) $url,
			'method'  => isset( $args['method'] ) ? $args['method'] : '',
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => isset( $args['body'] ) ? $args['body'] : '',
			'at'      => time(),
		);
		update_option( 'e2e_webhook_calls', $calls, false );

		// Risponde con lo status scelto dal test (default 200).
		return array(
			'headers'  => array(),
			'body'     => 'e2e',
			'response' => array(
				'code'    => (int) get_option( 'e2e_webhook_status', 200 ),
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// ---------------------------------------------------------------------------
// 3. Namespace REST di supporto
// ---------------------------------------------------------------------------
add_action(
	'rest_api_init',
	static function () {
		// Un solo permission_callback: la chiave fissa nell'header X-E2E-Key.
		$permission = static function ( WP_REST_Request $request ) {
			return hash_equals( WPHC_E2E_KEY, (string) $request->get_header( 'x-e2e-key' ) );
		};

		$routes = array(
			array( '/reset', 'POST', 'wphc_e2e_reset' ),
			array( '/state', 'GET', 'wphc_e2e_state' ),
			array( '/options', 'POST', 'wphc_e2e_options' ),
			array( '/cron/run', 'POST', 'wphc_e2e_cron_run' ),
			array( '/webhook', 'POST', 'wphc_e2e_webhook' ),
			array( '/updates', 'POST', 'wphc_e2e_updates' ),
			array( '/throttle', 'POST', 'wphc_e2e_throttle' ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				'wphc-e2e/v1',
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => $route[2],
					'permission_callback' => $permission,
				)
			);
		}
	}
);

/**
 * Cancella ogni traccia del plugin sotto test: option wp_health_check_* (tranne la versione
 * dello schema, altrimenti la tabella di log verrebbe ricreata), transient wphc_*, eventi di
 * cron wphc_*, righe di log, e le option di supporto e2e_*.
 *
 * @return array{ok:bool}
 */
function wphc_e2e_reset() {
	global $wpdb;

	$patterns = array(
		'wp\_health\_check\_%',
		'e2e\_%',
		'\_transient\_wphc\_%',
		'\_transient\_timeout\_wphc\_%',
		'\_transient\_wp\_health\_check\_%',
		'\_transient\_timeout\_wp\_health\_check\_%',
	);
	foreach ( $patterns as $pattern ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $pattern, 'wp_health_check_db_version' ) ); // phpcs:ignore WordPress.DB
	}
	// Riporta il cron a "nessun evento del plugin".
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			if ( 0 === strpos( $hook, 'wphc_' ) ) {
				foreach ( $events as $event ) {
					wp_unschedule_event( $timestamp, $hook, $event['args'] );
				}
			}
		}
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wphc_update_log" ); // phpcs:ignore WordPress.DB
	wp_cache_flush();

	return array( 'ok' => true );
}

/**
 * Stato completo letto dal database: option del plugin, transient wphc_*, eventi di cron
 * wphc_*, righe di log, chiamate al finto webhook e versione dichiarata dal file caricato.
 *
 * @return array<string,mixed>
 */
function wphc_e2e_state() {
	global $wpdb;
	wp_cache_flush(); // Lettura fresca: i cron e gli altri processi scrivono nel database.

	$options = array();
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s ORDER BY option_name", 'wp\_health\_check\_%', 'wp_health_check_db_version' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $row ) {
		$options[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
	}

	$transients = array();
	$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name", '\_transient\_wphc\_%', '\_transient\_wp\_health\_check\_%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $row ) {
		$transients[ substr( $row['option_name'], strlen( '_transient_' ) ) ] = maybe_unserialize( $row['option_value'] );
	}

	$cron = array();
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			if ( 0 === strpos( $hook, 'wphc_' ) ) {
				foreach ( $events as $event ) {
					$cron[] = array(
						'hook'      => $hook,
						'timestamp' => $timestamp,
						'args'      => $event['args'],
					);
				}
			}
		}
	}

	$log = $wpdb->get_results( "SELECT type, target, phase, message, source FROM {$wpdb->prefix}wphc_update_log ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB

	return array(
		'now'               => time(),
		'loaded_version'    => defined( 'WP_HEALTH_CHECK_VERSION' ) ? WP_HEALTH_CHECK_VERSION : null,
		'options'           => $options,
		'transients'        => $transients,
		'cron'              => $cron,
		'log'               => $log,
		'webhook_calls'     => get_option( 'e2e_webhook_calls', array() ),
		'webhook_status'    => (int) get_option( 'e2e_webhook_status', 200 ),
		'php_version'       => PHP_VERSION,
		'plugin_file'       => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR . '/wp-health-check.php' : null,
	);
}

/**
 * Scrive o cancella option wp_health_check_* (solo quelle: nessun altro nome e' ammesso).
 *
 * Corpo: { "set": { "nome": valore, ... }, "delete": [ "nome", ... ] }.
 *
 * @param WP_REST_Request $request Richiesta.
 * @return array{ok:bool}|WP_Error
 */
function wphc_e2e_options( WP_REST_Request $request ) {
	$body = (array) $request->get_json_params();

	foreach ( array_keys( isset( $body['set'] ) ? (array) $body['set'] : array() ) as $name ) {
		if ( 0 !== strpos( $name, 'wp_health_check_' ) ) {
			return new WP_Error( 'e2e_bad_option', 'Solo option wp_health_check_*', array( 'status' => 400 ) );
		}
	}
	foreach ( isset( $body['set'] ) ? (array) $body['set'] : array() as $name => $value ) {
		update_option( $name, $value, false );
	}
	foreach ( isset( $body['delete'] ) ? (array) $body['delete'] : array() as $name ) {
		if ( 0 === strpos( $name, 'wp_health_check_' ) ) {
			delete_option( $name );
		}
	}
	wp_cache_flush();

	return array( 'ok' => true );
}

/**
 * Fa girare gli eventi di cron pianificati per un hook, come farebbe WP-Cron quando e' scaduto
 * il loro istante: ogni evento viene tolto dalla pianificazione e poi si esegue l'azione con i
 * suoi argomenti. Quello che l'azione ripianifica resta in coda.
 *
 * Corpo: { "hook": "wphc_bulk_update_tick" }.
 *
 * @param WP_REST_Request $request Richiesta.
 * @return array{ran:int}|WP_Error
 */
function wphc_e2e_cron_run( WP_REST_Request $request ) {
	$hook = (string) $request->get_param( 'hook' );
	if ( 0 !== strpos( $hook, 'wphc_' ) ) {
		return new WP_Error( 'e2e_bad_hook', 'Solo hook wphc_*', array( 'status' => 400 ) );
	}

	$ran = 0;
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		if ( ! isset( $hooks[ $hook ] ) ) {
			continue;
		}
		foreach ( $hooks[ $hook ] as $event ) {
			wp_unschedule_event( $timestamp, $hook, $event['args'] );
			do_action_ref_array( $hook, $event['args'] );
			++$ran;
		}
	}

	return array( 'ran' => $ran );
}

/**
 * Imposta lo status HTTP del finto ricevente del webhook e/o ne azzera il registro.
 *
 * Corpo: { "status": 500, "clear": true }.
 *
 * @param WP_REST_Request $request Richiesta.
 * @return array{ok:bool}
 */
function wphc_e2e_webhook( WP_REST_Request $request ) {
	$body = (array) $request->get_json_params();
	if ( isset( $body['status'] ) ) {
		update_option( 'e2e_webhook_status', (int) $body['status'], false );
	}
	if ( ! empty( $body['clear'] ) ) {
		delete_option( 'e2e_webhook_calls' );
	}

	return array( 'ok' => true );
}

/**
 * Azzera i transient di aggiornamento del core WordPress (update_plugins/update_themes) con uno
 * stato "nessun aggiornamento disponibile", cosi' gli item di un job bulk si risolvono senza
 * interrogare wordpress.org.
 *
 * @return array{ok:bool}
 */
function wphc_e2e_updates() {
	$empty               = new stdClass();
	$empty->last_checked = time();
	$empty->checked      = array();
	$empty->response     = array();
	$empty->translations = array();
	$empty->no_update    = array();
	set_site_transient( 'update_plugins', $empty );
	set_site_transient( 'update_themes', $empty );

	return array( 'ok' => true );
}

/**
 * Azzera il contatore dei fallimenti di autenticazione (transient wphc_fail_*).
 *
 * Serve perche', su richieste HTTP reali, ogni richiesta firmata (protocollo 2) incrementa il
 * contatore anche quando riesce: la callback di permesso gira due volte (la seconda dentro
 * rest_send_allow_header() del core) e la seconda vede il nonce gia' usato. Una sequenza di test
 * lunga raggiungerebbe presto la soglia di 10 e riceverebbe 429.
 *
 * @return array{ok:bool}
 */
function wphc_e2e_throttle() {
	global $wpdb;

	foreach ( array( '\_transient\_wphc\_fail\_%', '\_transient\_timeout\_wphc\_fail\_%' ) as $pattern ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB
	}
	wp_cache_flush();

	return array( 'ok' => true );
}
