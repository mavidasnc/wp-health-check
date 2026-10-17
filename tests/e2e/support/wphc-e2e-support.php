<?php
/**
 * Plugin Name: WPHC E2E support (SOLO per i test)
 * Description: mu-plugin di supporto dell'ambiente wp-env e2e di wp-health-check. NON va mai installato su un sito reale.
 *
 * Cosa fa, in ordine:
 *  1. forza HTTPS (le rotte del plugin lo pretendono: il sito di test e' servito in HTTP sulla
 *     porta 8890, quindi si dichiara $_SERVER['HTTPS'] come farebbe un reverse proxy con TLS);
 *  2. isola la rete (pre_http_request, deny-by-default): le POST verso l'IP pubblico di test
 *     93.184.216.34 (il "ricevente" del webhook) sono registrate (URL, header, body) e ricevono lo
 *     status scelto; thum.io risponde con un PNG fisso, ipify con un IP fisso, ogni altra
 *     richiesta in uscita (wordpress.org, GitHub, ...) fallisce con un WP_Error e viene
 *     registrata (campo "http_denied" di /state): nessun test esce mai in rete;
 *  3. espone un piccolo namespace REST "wphc-e2e/v1" per azzerare e leggere lo stato del plugin,
 *     scrivere option, far girare gli eventi di cron senza aspettare, invalidare l'opcache e
 *     impostare l'esito del finto ricevente del webhook.
 *
 * Il namespace di supporto accetta solo richieste dall'host (loopback o rete privata del bridge
 * docker, dove cade il gateway) E con la chiave fissa nell'header X-E2E-Key.
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

/** IP pubblico fisso restituito dal finto servizio "echo IP" (api.ipify.org). Non e' in 192.0.2.0/24, che il core blocca. */
const WPHC_E2E_PUBLIC_IP = '198.51.100.7';

/** PNG 1x1 valido (base64): risposta fissa del servizio di anteprima (image.thum.io). */
const WPHC_E2E_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

// ---------------------------------------------------------------------------
// 2. Rete isolata: pre_http_request in deny-by-default
// ---------------------------------------------------------------------------
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		// Un altro filtro ha gia' risposto: non si interferisce.
		if ( false !== $preempt ) {
			return $preempt;
		}

		$url  = (string) $url;
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		// 2a. Finto ricevente del webhook: registra la richiesta cosi' come il plugin l'ha costruita
		// e risponde con lo status scelto dal test (default 200).
		if ( 0 === strpos( $url, 'https://' . WPHC_E2E_WEBHOOK_HOST . '/' ) ) {
			$calls   = get_option( 'e2e_webhook_calls', array() );
			$calls[] = array(
				'url'     => $url,
				'method'  => isset( $args['method'] ) ? $args['method'] : '',
				'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
				'body'    => isset( $args['body'] ) ? $args['body'] : '',
				'at'      => time(),
			);
			update_option( 'e2e_webhook_calls', $calls, false );

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
		}

		// 2b. Anteprima del sito (thum.io): download_url() chiede lo streaming su un file temporaneo,
		// che qui si riempie con un PNG valido (getimagesize() e media_handle_sideload() lo accettano).
		if ( 'image.thum.io' === $host ) {
			$png = base64_decode( WPHC_E2E_PNG_B64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => ! empty( $args['stream'] ) ? '' : $png,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => isset( $args['filename'] ) ? $args['filename'] : null,
			);
		}

		// 2c. Servizio "echo IP" (ipify): un IP pubblico fisso, cosi' /health e' deterministico.
		if ( 'api.ipify.org' === $host ) {
			return array(
				'headers'  => array(),
				'body'     => WPHC_E2E_PUBLIC_IP,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// 2d. Tutto il resto (wordpress.org, GitHub, ...) e' negato: errore e traccia in "e2e_http_denied".
		$denied   = get_option( 'e2e_http_denied', array() );
		$denied[] = $url;
		update_option( 'e2e_http_denied', array_slice( $denied, -50 ), false );

		return new WP_Error( 'wphc_e2e_http_denied', 'Richiesta in uscita negata dall\'ambiente e2e: ' . $url );
	},
	10,
	3
);

// ---------------------------------------------------------------------------
// 3. Namespace REST di supporto
// ---------------------------------------------------------------------------

/**
 * True se l'indirizzo del chiamante e' del loopback o della rete privata (RFC 1918).
 *
 * Il test gira sull'host e raggiunge il container dalla porta 8890: PHP vede come REMOTE_ADDR il
 * gateway del bridge docker (osservato: 172.x.0.1), che cade in 172.16.0.0/12. Si accettano anche
 * 10.0.0.0/8 e 192.168.0.0/16 perche' altre configurazioni di Docker usano quei blocchi. Un
 * chiamante con indirizzo pubblico (ambiente esposto per errore) e' respinto anche con la chiave giusta.
 *
 * @param string $ip Valore di $_SERVER['REMOTE_ADDR'].
 * @return bool
 */
function wphc_e2e_is_local_caller( $ip ) {
	if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
		return true;
	}
	$long = ip2long( $ip );
	if ( false === $long ) {
		return false;
	}
	foreach ( array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8' ) as $cidr ) {
		list( $net, $bits ) = explode( '/', $cidr );
		$mask               = -1 << ( 32 - (int) $bits );
		if ( ( $long & $mask ) === ( ip2long( $net ) & $mask ) ) {
			return true;
		}
	}

	return false;
}

add_action(
	'rest_api_init',
	static function () {
		// Un solo permission_callback: chiamante locale E chiave fissa nell'header X-E2E-Key.
		$permission = static function ( WP_REST_Request $request ) {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return wphc_e2e_is_local_caller( $remote ) && hash_equals( WPHC_E2E_KEY, (string) $request->get_header( 'x-e2e-key' ) );
		};

		$routes = array(
			array( '/reset', 'POST', 'wphc_e2e_reset' ),
			array( '/state', 'GET', 'wphc_e2e_state' ),
			array( '/options', 'POST', 'wphc_e2e_options' ),
			array( '/cron/run', 'POST', 'wphc_e2e_cron_run' ),
			array( '/webhook', 'POST', 'wphc_e2e_webhook' ),
			array( '/updates', 'POST', 'wphc_e2e_updates' ),
			array( '/throttle', 'POST', 'wphc_e2e_throttle' ),
			array( '/opcache', 'POST', 'wphc_e2e_opcache' ),
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
 * Stato completo letto dal database: option del plugin (con la colonna autoload), transient
 * wphc_*, eventi di cron wphc_*, righe di log, chiamate al finto webhook, richieste in uscita
 * negate e informazioni sul codice CARICATO dal PHP del sito (versione, sha256, opcache).
 *
 * @return array<string,mixed>
 */
function wphc_e2e_state() {
	global $wpdb;
	wp_cache_flush(); // Lettura fresca: i cron e gli altri processi scrivono nel database.

	// Option del plugin: valore e colonna autoload GREZZA ('on', 'off', 'auto', 'yes', 'no' a seconda
	// di cosa passa il plugin e della versione di WordPress): un cambio di autoload e' un cambio di comportamento.
	$options  = array();
	$autoload = array();
	$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s ORDER BY option_name", 'wp\_health\_check\_%', 'wp_health_check_db_version' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	foreach ( $rows as $row ) {
		$options[ $row['option_name'] ]  = maybe_unserialize( $row['option_value'] );
		$autoload[ $row['option_name'] ] = $row['autoload'];
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

	return array_merge(
		array(
			'now'               => time(),
			'loaded_version'    => defined( 'WP_HEALTH_CHECK_VERSION' ) ? WP_HEALTH_CHECK_VERSION : null,
			'options'           => $options,
			'options_autoload'  => $autoload,
			'transients'        => $transients,
			'cron'              => $cron,
			'log'               => $log,
			'webhook_calls'     => get_option( 'e2e_webhook_calls', array() ),
			'webhook_status'    => (int) get_option( 'e2e_webhook_status', 200 ),
			'http_denied'       => get_option( 'e2e_http_denied', array() ),
			'php_version'       => PHP_VERSION,
			'plugin_file'       => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR . '/wp-health-check.php' : null,
		),
		wphc_e2e_loaded_code()
	);
}

/**
 * Che cosa sta eseguendo davvero il PHP del sito.
 *
 * Il file e' sempre mu-plugins/wp-health-check.php (anche per la build del refactoring, che non ha
 * funzioni globali). Campi:
 *  - loaded_sha256: sha256 del file installato su disco, calcolato solo se e' fra i file inclusi
 *    (get_included_files()): e' il file da cui il PHP ha caricato il plugin;
 *  - loaded_from_installed: true se il file installato risulta incluso;
 *  - loaded_symbol_file: file da cui e' dichiarato un simbolo noto del plugin (ReflectionFunction
 *    di wphc_normalize_url nella 1.38.1, ReflectionClass di una classe del refactoring), oppure null;
 *  - opcache: abilitata? e, se lo script e' in cache, se il timestamp compilato coincide con la
 *    data del file (false = codice in cache VECCHIO rispetto al disco).
 *
 * @return array<string,mixed>
 */
function wphc_e2e_loaded_code() {
	$file = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR . '/wp-health-check.php' : '';
	$real = '' !== $file ? realpath( $file ) : false;

	// 1. Il file installato e' fra quelli inclusi in questa richiesta?
	$included = false;
	if ( false !== $real ) {
		foreach ( get_included_files() as $included_file ) {
			if ( realpath( $included_file ) === $real ) {
				$included = true;
				break;
			}
		}
	}

	// 2. Il file da cui e' dichiarato un simbolo noto (con ripiego per le build a classi).
	$symbol_file = null;
	try {
		if ( function_exists( 'wphc_normalize_url' ) ) {
			$symbol_file = ( new ReflectionFunction( 'wphc_normalize_url' ) )->getFileName();
		} else {
			foreach ( array( 'WPHC_Util', 'WPHC_Plugin', 'WPHC_Auth' ) as $class ) {
				if ( class_exists( $class, false ) ) {
					$symbol_file = ( new ReflectionClass( $class ) )->getFileName();
					break;
				}
			}
		}
	} catch ( ReflectionException $e ) {
		$symbol_file = null;
	}

	// 3. Opcache: lo script e' in cache con la stessa data del file su disco?
	$opcache = array(
		'enabled' => false,
		'fresh'   => null,
	);
	if ( function_exists( 'opcache_get_status' ) ) {
		$status = @opcache_get_status( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $status ) && ! empty( $status['opcache_enabled'] ) ) {
			$opcache['enabled'] = true;
			if ( false !== $real && isset( $status['scripts'][ $real ]['timestamp'] ) ) {
				$opcache['fresh'] = ( (int) $status['scripts'][ $real ]['timestamp'] === (int) filemtime( $real ) );
			}
		}
	}

	return array(
		'loaded_from_installed' => $included,
		'loaded_sha256'         => ( $included && is_readable( $file ) ) ? hash_file( 'sha256', $file ) : null,
		'loaded_symbol_file'    => $symbol_file,
		'loaded_symbol_matches' => ( null !== $symbol_file && false !== $real ) ? ( realpath( $symbol_file ) === $real ) : null,
		'opcache'               => $opcache,
	);
}

/**
 * Invalida l'opcache per il file del plugin (force = true), come fa il plugin al termine di un
 * self-update. Serve dopo una sostituzione ESTERNA del file: con opcache.revalidate_freq=2 le
 * richieste successive potrebbero eseguire per qualche secondo il codice vecchio in cache.
 * Se l'opcache e' assente non fa nulla.
 *
 * @return array{invalidated:bool,opcache:bool}
 */
function wphc_e2e_opcache() {
	$file = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR . '/wp-health-check.php' : '';
	$done = false;
	if ( '' !== $file && function_exists( 'opcache_invalidate' ) ) {
		$done = (bool) @opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	return array(
		'invalidated' => $done,
		'opcache'     => function_exists( 'opcache_invalidate' ),
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
 * Durante l'esecuzione wp_doing_cron() e' VERO (filtro temporaneo): in una richiesta REST sarebbe
 * falso e il plugin si comporterebbe in modo diverso da WP-Cron (wphc_record_access non scrive
 * l'ultimo accesso, wphc_detect_update_source riporta 'cron' invece di 'wp-admin').
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

	// Contesto cron falso-vero: wp_doing_cron() restituisce true fino al termine degli eventi.
	add_filter( 'wp_doing_cron', '__return_true' );

	$ran = 0;
	try {
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
	} finally {
		remove_filter( 'wp_doing_cron', '__return_true' );
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
