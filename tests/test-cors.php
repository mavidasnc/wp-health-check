<?php
/**
 * Test di caratterizzazione di CORS e preflight (plugin 1.38.1): wphc_maybe_send_cors_headers(),
 * il filtro `rest_pre_serve_request` (riasserzione dopo il dispatch) e il filtro `rest_pre_dispatch`
 * (risposta alle OPTIONS).
 *
 * LIMITE DI OSSERVABILITA'. Il plugin invia gli header con header() direttamente (non li mette
 * sull'oggetto WP_REST_Response) e in CLI header()/headers_list() non conservano nulla; Xdebug
 * non c'e' nel container. Percio':
 *  - la LOGICA di reflection (origin assente/non configurata/diversa/uguale, formato esatto degli
 *    header, header_remove iniziali) si verifica eseguendo il sorgente REALE della funzione con
 *    header() sostituito da una spia (WPHC_Read_Test_Case::capture_cors());
 *  - l'ESECUZIONE della funzione nei flussi reali (permission_callback, preflight, riasserzione) si
 *    osserva dal filtro `nocache_headers`, che la funzione invoca sempre tramite nocache_headers();
 *  - il fatto che gli header NON siano sulla WP_REST_Response e' esso stesso asserito.
 * Cio' che resta non osservabile: l'invio effettivo dei byte HTTP e l'ordine rispetto a
 * rest_send_cors_headers() del core (che gira alla priorita' 10, prima della riasserzione a 20).
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-read.php';

/**
 * CORS e preflight.
 */
class WPHC_Test_Cors extends WPHC_Read_Test_Case {

	/** Origin della dashboard usata nei test. */
	const ORIGIN = 'https://dash.example.com';

	/** Header rimossi all'inizio di ogni esecuzione, nell'ordine. */
	const REMOVED = array(
		'Access-Control-Allow-Origin',
		'Access-Control-Allow-Credentials',
		'Access-Control-Allow-Methods',
		'Access-Control-Allow-Headers',
		'Access-Control-Expose-Headers',
	);

	// -----------------------------------------------------------------------
	// Logica di wphc_maybe_send_cors_headers() (header catturati)
	// -----------------------------------------------------------------------

	/**
	 * Senza `wp_health_check_dashboard_origin` (option assente o vuota) NESSUN header CORS viene inviato,
	 * qualunque sia l'origin della richiesta: restano solo le rimozioni iniziali degli header del core.
	 */
	public function test_no_cors_headers_without_configured_origin() {
		foreach ( array( null, '', false ) as $configured ) {
			$captured = $this->capture_cors( 'https://evil.example', $configured );

			$this->assertSame( array(), $captured['headers'], 'configurata: ' . var_export( $configured, true ) );
			$this->assertSame( self::REMOVED, $captured['removed'] );
		}
	}

	/**
	 * Senza header Origin nella richiesta (chiamata server-to-server dell'hub) non si invia nulla,
	 * nemmeno con un'origin configurata.
	 */
	public function test_no_cors_headers_without_request_origin() {
		foreach ( array( null, '' ) as $request_origin ) {
			$captured = $this->capture_cors( $request_origin, self::ORIGIN );

			$this->assertSame( array(), $captured['headers'] );
			$this->assertSame( self::REMOVED, $captured['removed'] );
		}
	}

	/**
	 * Con l'origin configurata e uguale a quella della richiesta si riflette SOLO quella, con metodi,
	 * header consentiti (compresi quelli di firma X-WPHC-*) e `Vary: Origin`. Mai il wildcard, mai
	 * Allow-Credentials. L'ordine degli header e' parte del comportamento.
	 */
	public function test_reflects_only_the_configured_origin_with_vary() {
		$captured = $this->capture_cors( self::ORIGIN, self::ORIGIN );

		$this->assertSame(
			array(
				'Access-Control-Allow-Origin: ' . self::ORIGIN,
				'Access-Control-Allow-Methods: GET, POST, OPTIONS',
				'Access-Control-Allow-Headers: Authorization, Content-Type, X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature',
				'Vary: Origin',
			),
			$captured['headers']
		);
		$this->assertSame( self::REMOVED, $captured['removed'], 'prima gli header del core vengono rimossi' );
		$this->assertStringNotContainsString( '*', $captured['headers'][0] );
		foreach ( $captured['headers'] as $header ) {
			$this->assertStringNotContainsString( 'Allow-Credentials', $header );
		}
	}

	/**
	 * Qualunque origin diversa da quella configurata (confronto stretto, byte per byte) non ottiene
	 * nessun header: altro host, sottodominio, schema diverso, slash finale, maiuscole, porta.
	 */
	public function test_other_origins_get_no_headers() {
		$others = array(
			'https://evil.example',
			'https://sub.dash.example.com',
			'http://dash.example.com',
			'https://dash.example.com/',
			'https://DASH.example.com',
			'https://dash.example.com:8443',
			'null',
			'*',
		);
		foreach ( $others as $other ) {
			$captured = $this->capture_cors( $other, self::ORIGIN );

			$this->assertSame( array(), $captured['headers'], $other );
			$this->assertSame( self::REMOVED, $captured['removed'], $other );
		}
	}

	/**
	 * PRESERVATO: l'origin della richiesta passa da sanitize_text_field(), che toglie gli spazi ai bordi:
	 * " https://dash.example.com " e' accettata e riflessa ripulita. L'origin CONFIGURATA, invece, non viene
	 * ripulita ne' normalizzata: se e' salvata con lo slash finale non coincide mai con l'origin del browser.
	 */
	public function test_origin_comparison_quirks() {
		$captured = $this->capture_cors( '  ' . self::ORIGIN . '  ', self::ORIGIN );
		$this->assertSame( 'Access-Control-Allow-Origin: ' . self::ORIGIN, $captured['headers'][0] );

		$captured = $this->capture_cors( self::ORIGIN, self::ORIGIN . '/' );
		$this->assertSame( array(), $captured['headers'], 'configurata con slash finale: nessuna corrispondenza' );
	}

	// -----------------------------------------------------------------------
	// Esecuzione della funzione nei flussi reali (spia nocache_headers)
	// -----------------------------------------------------------------------

	/**
	 * La funzione CORS e' la PRIMA cosa che fa il permission_callback delle rotte dati: gira anche quando
	 * la richiesta e' rifiutata (non enrolled 503, HTTPS mancante 403, Bearer errato 401) e imposta la
	 * costante DONOTCACHEPAGE per i plugin di page-cache.
	 */
	public function test_data_routes_run_cors_even_when_the_request_is_rejected() {
		// 503: sito non enrolled.
		$this->assertSame( 503, $this->request( 'GET', '/health' )->get_status() );
		$this->assertSame( 1, $this->nocache_calls );

		// 403: senza HTTPS (il controllo CORS precede quello HTTPS).
		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 403, $this->request( 'GET', '/ping' )->get_status() );
		$this->assertSame( 2, $this->nocache_calls );
		$_SERVER['HTTPS'] = 'on';

		// 401: Bearer errato su un sito enrolled.
		$this->seed_enrolled( 'tok-test' );
		$this->assertSame( 401, $this->data_request( 'GET', '/ping', 'sbagliato' )->get_status() );
		$this->assertSame( 3, $this->nocache_calls );

		// 200: richiesta valida.
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );
		$this->assertSame( 4, $this->nocache_calls );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/**
	 * Anche /enroll, /rotate e /revoke eseguono la funzione CORS (dentro il callback, prima di ogni controllo).
	 */
	public function test_enrollment_routes_run_cors() {
		foreach ( array( '/enroll', '/rotate', '/revoke' ) as $index => $route ) {
			$this->request( 'POST', $route, array() );

			$this->assertSame( $index + 1, $this->nocache_calls, $route );
		}
	}

	/**
	 * PRESERVATO: /debug (permission manage_options) NON invia header CORS ne' DONOTCACHEPAGE: la funzione
	 * non e' mai chiamata, ne' dal permission_callback ne' dal callback.
	 */
	public function test_debug_route_does_not_run_cors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 200, $this->request( 'GET', '/debug' )->get_status() );

		$this->assertSame( 0, $this->nocache_calls );
	}

	/**
	 * Gli header CORS non sono sulla WP_REST_Response (sono inviati con header()): neanche con l'origin
	 * giusta. Documenta il limite di osservabilita'.
	 */
	public function test_cors_headers_are_not_on_the_response_object() {
		$this->seed_enrolled( 'tok-test' );
		update_option( 'wp_health_check_dashboard_origin', self::ORIGIN );
		$_SERVER['HTTP_ORIGIN'] = self::ORIGIN;

		$response = $this->data_request( 'GET', '/ping', 'tok-test' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_headers() );
	}

	// -----------------------------------------------------------------------
	// rest_pre_serve_request: riasserzione dopo il dispatch
	// -----------------------------------------------------------------------

	/**
	 * La riasserzione e' agganciata a `rest_pre_serve_request` con priorita' 20 e 3 argomenti, DOPO
	 * rest_send_cors_headers() del core (priorita' 10), e il preflight a `rest_pre_dispatch` con priorita' 10.
	 */
	public function test_hook_registration() {
		rest_get_server(); // Fa girare rest_api_init e i filtri predefiniti del core.

		$this->assertSame( 20, has_filter( 'rest_pre_serve_request', self::cb( 'wphc_reassert_cors_headers' ) ) );
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', self::cb( 'wphc_handle_options_preflight' ) ) );
		$this->assertSame( 10, has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );
	}

	/**
	 * La riasserzione agisce solo sulle rotte che INIZIANO con "/health-check/v1" e restituisce sempre il
	 * valore di $served invariato. PRESERVATO: il controllo e' un prefisso di stringa, quindi vale anche per
	 * "/health-check/v10/..." (un namespace diverso).
	 */
	public function test_reassert_applies_only_to_the_plugin_namespace() {
		$server = rest_get_server();

		$run = function ( $route, $served ) use ( $server ) {
			$before = $this->nocache_calls;
			$result = apply_filters( 'rest_pre_serve_request', $served, new WP_REST_Response(), new WP_REST_Request( 'GET', $route ), $server );

			return array( $result, $this->nocache_calls - $before );
		};

		$this->assertSame( array( true, 1 ), $run( '/health-check/v1/health', true ) );
		$this->assertSame( array( false, 1 ), $run( '/health-check/v1/ping', false ) );
		$this->assertSame( array( true, 0 ), $run( '/wp/v2/posts', true ) );
		$this->assertSame( array( true, 0 ), $run( '/altro/health-check/v1/health', true ), 'non e\' un prefisso' );
		$this->assertSame( array( false, 1 ), $run( '/health-check/v10/qualcosa', false ), 'PRESERVATO: prefisso di stringa' );
	}

	// -----------------------------------------------------------------------
	// rest_pre_dispatch: preflight OPTIONS
	// -----------------------------------------------------------------------

	/**
	 * Il filtro di preflight: per una OPTIONS verso il namespace del plugin risponde 200 con corpo null
	 * SOSTITUENDO qualunque risultato precedente; per ogni altro metodo o namespace restituisce $result intatto.
	 */
	public function test_preflight_filter_logic() {
		$server = rest_get_server();

		$options = apply_filters( 'rest_pre_dispatch', null, $server, new WP_REST_Request( 'OPTIONS', '/health-check/v1/health' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $options );
		$this->assertSame( 200, $options->get_status() );
		$this->assertNull( $options->get_data() );
		$this->assertSame( 1, $this->nocache_calls );

		// Sostituisce un risultato gia' presente.
		$replaced = apply_filters( 'rest_pre_dispatch', 'gia-gestito', $server, new WP_REST_Request( 'OPTIONS', '/health-check/v1/health' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $replaced );

		// GET sul namespace: non intercettato.
		$before = $this->nocache_calls;
		$this->assertSame( 'x', apply_filters( 'rest_pre_dispatch', 'x', $server, new WP_REST_Request( 'GET', '/health-check/v1/health' ) ) );
		// OPTIONS su un altro namespace: non intercettato.
		$this->assertSame( 'y', apply_filters( 'rest_pre_dispatch', 'y', $server, new WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ) ) );
		$this->assertSame( $before, $this->nocache_calls );
	}

	/**
	 * Preflight reale (rest_do_request): 200 e corpo null su QUALUNQUE rotta del namespace, anche
	 * inesistente, e in ogni stato del sito (non enrolled, enrolled, revocato), senza autenticazione.
	 * Non scrive nulla (ne' option ne' throttle) e non registra l'accesso.
	 */
	public function test_preflight_is_unauthenticated_and_has_no_side_effects() {
		$states = array(
			'non enrolled' => function () {},
			'enrolled'     => function () {
				$this->seed_enrolled( 'tok-test' );
			},
			'revocato'     => function () {
				update_option( 'wp_health_check_revoked_at', gmdate( 'c' ), false );
			},
		);
		foreach ( $states as $label => $arrange ) {
			$arrange();
			$before = $this->plugin_options();

			foreach ( array( '/health', '/enroll', '/debug', '/rotta-che-non-esiste' ) as $route ) {
				$response = $this->request( 'OPTIONS', $route );

				$this->assertSame( 200, $response->get_status(), "{$label} {$route}" );
				$this->assertNull( $response->get_data(), "{$label} {$route}" );
			}

			$this->assertSame( $before, $this->plugin_options(), $label );
			$this->assertSame( 0, $this->throttle_failures(), $label );
		}
		$this->assertSame( 12, $this->nocache_calls, 'la funzione CORS gira a ogni preflight' );

		$this->seed_enrolled( 'tok-test' );
		$before   = $this->plugin_options();
		$response = $this->request( 'OPTIONS', '/health' );
		$this->assert_read_golden( 'read/cors-preflight', $response, $before );
	}

	/**
	 * Un OPTIONS verso un altro namespace NON e' gestito dal plugin: la funzione CORS non gira.
	 */
	public function test_preflight_ignores_other_namespaces() {
		rest_do_request( new WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ) );

		$this->assertSame( 0, $this->nocache_calls );
	}
}
