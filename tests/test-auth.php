<?php
/**
 * Test di caratterizzazione dell'autenticazione delle rotte dati (wphc_require_token,
 * plugin 1.38.1): ordine dei controlli, throttle, Bearer, firma HMAC v2 e dual-stack.
 *
 * Ordine REALE dei controlli sulle rotte dati (da preservare):
 *   HTTPS -> token assente (503) -> revocato (403) -> throttle (429) -> Bearer (401)
 *   -> token corrente o precedente in grazia -> firma HMAC se protocol >= 2 o se presente.
 *
 * Come rotta "positiva" si usa GET /ping: e' economica e non scrive nulla.
 *
 * @package WP_Health_Check
 */

/**
 * Autenticazione delle rotte protette da bearer token.
 */
class WPHC_Test_Auth extends WPHC_Test_Case {

	/**
	 * Rotte REST attese del namespace health-check/v1, con i metodi accettati.
	 * E' l'inventario che il refactoring non deve alterare.
	 *
	 * @var array<string,string[]>
	 */
	const EXPECTED_ROUTES = array(
		'/health-check/v1/enroll'           => array( 'POST' ),
		'/health-check/v1/rotate'           => array( 'POST' ),
		'/health-check/v1/revoke'           => array( 'POST' ),
		'/health-check/v1/health'           => array( 'GET' ),
		'/health-check/v1/ping'             => array( 'GET' ),
		'/health-check/v1/detail/plugins'   => array( 'GET' ),
		'/health-check/v1/detail/theme'     => array( 'GET' ),
		'/health-check/v1/detail/server'    => array( 'GET' ),
		'/health-check/v1/detail/users'     => array( 'GET' ),
		'/health-check/v1/update'           => array( 'POST' ),
		'/health-check/v1/update/plugin'    => array( 'POST' ),
		'/health-check/v1/update/theme'     => array( 'POST' ),
		'/health-check/v1/update/core'      => array( 'POST' ),
		'/health-check/v1/update/log'       => array( 'GET' ),
		'/health-check/v1/update/reactivate' => array( 'POST' ),
		'/health-check/v1/update/bulk'      => array( 'GET', 'POST' ),
		'/health-check/v1/update/bulk/cancel' => array( 'POST' ),
		'/health-check/v1/thumbnail'        => array( 'GET', 'POST' ),
		'/health-check/v1/debug'            => array( 'GET' ),
		'/health-check/v1/autologin/token'  => array( 'POST' ),
	);

	/**
	 * Rotte protette dal bearer token (metodo, rotta senza namespace).
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function token_routes() {
		return array(
			array( 'GET', '/health' ),
			array( 'GET', '/ping' ),
			array( 'GET', '/detail/plugins' ),
			array( 'GET', '/detail/theme' ),
			array( 'GET', '/detail/server' ),
			array( 'GET', '/detail/users' ),
			array( 'POST', '/update' ),
			array( 'POST', '/update/plugin' ),
			array( 'POST', '/update/theme' ),
			array( 'POST', '/update/core' ),
			array( 'GET', '/update/log' ),
			array( 'POST', '/update/reactivate' ),
			array( 'GET', '/update/bulk' ),
			array( 'POST', '/update/bulk' ),
			array( 'POST', '/update/bulk/cancel' ),
			array( 'GET', '/thumbnail' ),
			array( 'POST', '/thumbnail' ),
		);
	}

	// -----------------------------------------------------------------------
	// Smoke test e inventario delle rotte
	// -----------------------------------------------------------------------

	/**
	 * Smoke test dell'infrastruttura: un sito non enrolled risponde 503 wphc_not_enrolled su
	 * /health, e la risposta ha la forma standard di un WP_Error REST.
	 */
	public function test_smoke_health_not_enrolled() {
		$before   = $this->plugin_options();
		$response = $this->data_request( 'GET', '/health' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wphc_not_enrolled', $this->error_code( $response ) );
		$this->assertSame( 'wphc_not_enrolled', $response->get_data()['code'] );
		$this->assertSame( 503, $response->get_data()['data']['status'] );

		$this->assert_golden( 'auth/not-enrolled', $response, $before );
	}

	/**
	 * Inventario delle rotte del namespace: pattern e metodi.
	 */
	public function test_registered_routes() {
		$routes = array();
		foreach ( rest_get_server()->get_routes( 'health-check/v1' ) as $pattern => $handlers ) {
			$methods = array();
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
			}
			$methods = array_values( array_unique( $methods ) );
			sort( $methods );
			$routes[ $pattern ] = $methods;
		}
		// L'indice del namespace e' aggiunto dal core: non fa parte del plugin.
		unset( $routes['/health-check/v1'] );

		$expected = self::EXPECTED_ROUTES;
		ksort( $expected );
		ksort( $routes );

		$this->assertSame( $expected, $routes );
	}

	/**
	 * Le rotte di diagnostica e autologin usano l'autenticazione WordPress (manage_options),
	 * NON il bearer token: un token valido non basta, un utente non amministratore neppure.
	 */
	public function test_capability_routes_do_not_accept_the_bearer_token() {
		$token = 'tok-test';
		$this->seed_enrolled( $token );

		foreach ( array(
			array( 'GET', '/debug' ),
			array( 'POST', '/autologin/token' ),
		) as $route ) {
			list( $method, $path ) = $route;

			// Anonimo con token valido: 401 rest_forbidden.
			$response = $this->data_request( $method, $path, $token );
			$this->assertSame( 401, $response->get_status(), $path );
			$this->assertSame( 'rest_forbidden', $this->error_code( $response ), $path );

			// Utente loggato senza capability: 403.
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$this->assertSame( 403, $this->data_request( $method, $path )->get_status(), $path );
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Preflight CORS: una OPTIONS verso qualunque rotta del namespace risponde 200 senza
	 * autenticazione (nemmeno il token), anche a sito enrolled.
	 */
	public function test_options_preflight_needs_no_authentication() {
		$this->seed_enrolled( 'tok-test' );

		foreach ( array( '/health', '/enroll', '/update/plugin' ) as $route ) {
			$response = $this->request( 'OPTIONS', $route );

			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertNull( $response->get_data(), $route );
		}
	}

	// -----------------------------------------------------------------------
	// Ordine dei controlli
	// -----------------------------------------------------------------------

	/**
	 * Sito non enrolled: ogni rotta dati risponde 503 wphc_not_enrolled, anche senza header.
	 */
	public function test_not_enrolled_on_every_data_route() {
		foreach ( $this->token_routes() as $route ) {
			list( $method, $path ) = $route;

			$response = $this->data_request( $method, $path );

			$this->assertSame( 503, $response->get_status(), "{$method} {$path}" );
			$this->assertSame( 'wphc_not_enrolled', $this->error_code( $response ), "{$method} {$path}" );
		}
		// 503 non e' un fallimento di autenticazione: il throttle resta a zero.
		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * Sito enrolled senza Authorization: ogni rotta dati risponde 401 wphc_unauthorized.
	 */
	public function test_unauthorized_on_every_data_route() {
		$this->seed_enrolled( 'tok-test' );

		foreach ( $this->token_routes() as $route ) {
			list( $method, $path ) = $route;
			$this->set_throttle_failures( 0 );

			$response = $this->data_request( $method, $path );

			$this->assertSame( 401, $response->get_status(), "{$method} {$path}" );
			$this->assertSame( 'wphc_unauthorized', $this->error_code( $response ), "{$method} {$path}" );
		}
	}

	/**
	 * Il controllo HTTPS e' il primo: senza HTTPS risponde 403 anche a sito non enrolled.
	 */
	public function test_https_is_required_before_everything() {
		unset( $_SERVER['HTTPS'] );

		$response = $this->data_request( 'GET', '/ping', 'qualunque' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_https_required', $this->error_code( $response ) );
	}

	/**
	 * "Non enrolled" (503) e "revocato" (403) precedono il throttle (429): anche con 10
	 * fallimenti gia' registrati la risposta e' 503/403, non 429.
	 */
	public function test_not_enrolled_and_revoked_precede_throttle() {
		$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS );
		$this->assertSame( 'wphc_not_enrolled', $this->error_code( $this->data_request( 'GET', '/ping', 'x' ) ) );

		$this->seed_enrolled( 'tok-test' );
		update_option( 'wp_health_check_revoked_at', gmdate( 'c' ), false );
		$this->assertSame( 'wphc_revoked', $this->error_code( $this->data_request( 'GET', '/ping', 'tok-test' ) ) );
	}

	/**
	 * Sito revocato: 403 wphc_revoked con token e firma corretti, senza toccare il throttle.
	 */
	public function test_revoked_site_is_forbidden() {
		$this->seed_enrolled( 'tok-test', 2 );
		update_option( 'wp_health_check_revoked_at', gmdate( 'c' ), false );

		$before   = $this->plugin_options();
		$response = $this->data_request( 'GET', '/ping', 'tok-test', 'tok-test' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_revoked', $this->error_code( $response ) );
		$this->assertSame( 0, $this->throttle_failures() );

		$this->assert_golden( 'auth/revoked', $response, $before );
	}

	// -----------------------------------------------------------------------
	// Bearer token
	// -----------------------------------------------------------------------

	/**
	 * Protocollo 1: basta il Bearer corretto. Lo schema e' case-insensitive ("bearer") e gli
	 * spazi attorno al token sono ignorati; un successo non tocca il contatore del throttle.
	 */
	public function test_bearer_success_protocol_one() {
		$this->seed_enrolled( 'tok-test', 1 );

		$response = $this->data_request( 'GET', '/ping', 'tok-test' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $response->get_data()['status'] );
		$this->assertSame( $this->own_site_url(), $response->get_data()['site'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $response->get_data()['agent_version'] );

		$request = new WP_REST_Request( 'GET', self::NS . '/ping' );
		$request->set_header( 'Authorization', 'bearer   tok-test  ' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * Bearer errato o assente, schema diverso: 401 con la STESSA risposta (stesso codice e
	 * messaggio), e ogni fallimento incrementa il contatore.
	 */
	public function test_bearer_failures_are_indistinguishable() {
		$this->seed_enrolled( 'tok-test', 1 );
		$before = $this->plugin_options();

		$no_header = $this->data_request( 'GET', '/ping' );
		$wrong     = $this->data_request( 'GET', '/ping', 'sbagliato' );

		$basic = new WP_REST_Request( 'GET', self::NS . '/ping' );
		$basic->set_header( 'Authorization', 'Basic dGVzdDp0ZXN0' );
		$basic_response = rest_do_request( $basic );

		foreach ( array( $no_header, $wrong, $basic_response ) as $response ) {
			$this->assertSame( 401, $response->get_status() );
			$this->assertSame( 'wphc_unauthorized', $this->error_code( $response ) );
		}
		$this->assertSame( $no_header->get_data(), $wrong->get_data() );
		$this->assertSame( $no_header->get_data(), $basic_response->get_data() );
		$this->assertSame( 3, $this->throttle_failures() );

		$this->assert_golden( 'auth/unauthorized', $wrong, $before );
	}

	// -----------------------------------------------------------------------
	// Throttle
	// -----------------------------------------------------------------------

	/**
	 * Dopo 10 fallimenti dallo stesso IP la richiesta successiva riceve 429 wphc_rate_limited,
	 * anche con il token corretto. Il successo non azzera il contatore, e un 429 non lo
	 * incrementa. (L'header Retry-After viene inviato con header(): in-process non e' osservabile.)
	 */
	public function test_rate_limit_after_ten_failures() {
		$this->seed_enrolled( 'tok-test', 1 );

		for ( $i = 1; $i <= WP_HEALTH_CHECK_RATE_MAX_FAILS; $i++ ) {
			$response = $this->data_request( 'GET', '/ping', 'sbagliato' );
			$this->assertSame( 401, $response->get_status(), "tentativo {$i}" );
		}
		$this->assertSame( WP_HEALTH_CHECK_RATE_MAX_FAILS, $this->throttle_failures() );
		// La finestra del contatore e' di 300 s dall'ultimo fallimento.
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( '_transient_timeout_wphc_fail_' . hash( 'sha1', self::CLIENT_IP ) ), 5 );

		$before   = $this->plugin_options();
		$response = $this->data_request( 'GET', '/ping', 'tok-test' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'wphc_rate_limited', $this->error_code( $response ) );
		$this->assertSame( WP_HEALTH_CHECK_RATE_MAX_FAILS, $this->throttle_failures(), 'un 429 non incrementa il contatore' );

		$this->assert_golden( 'auth/rate-limited', $response, $before );
	}

	/**
	 * Nove fallimenti non bastano; il successo non azzera il contatore (il decimo
	 * fallimento successivo porta comunque al blocco).
	 */
	public function test_rate_limit_threshold_and_success_does_not_reset() {
		$this->seed_enrolled( 'tok-test', 1 );
		$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS - 1 );

		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );
		$this->assertSame( WP_HEALTH_CHECK_RATE_MAX_FAILS - 1, $this->throttle_failures() );

		$this->assertSame( 401, $this->data_request( 'GET', '/ping', 'sbagliato' )->get_status() );
		$this->assertSame( 429, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );
	}

	/**
	 * Il contatore e' per IP: un altro IP non e' bloccato. Con wp_health_check_trust_proxy
	 * l'IP e' l'ULTIMO valido di X-Forwarded-For (quello accodato dal proxy fidato).
	 */
	public function test_rate_limit_is_per_ip_and_trust_proxy_uses_rightmost() {
		$this->seed_enrolled( 'tok-test', 1 );
		$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status(), 'altro IP: non bloccato' );

		// Senza trust_proxy l'header X-Forwarded-For e' ignorato: l'IP bloccato resta bloccato.
		$_SERVER['REMOTE_ADDR']          = self::CLIENT_IP;
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$this->assertSame( 429, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );

		// Con trust_proxy conta il valore piu' a destra: spoofare il primo non aiuta.
		update_option( 'wp_health_check_trust_proxy', true );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, ' . self::CLIENT_IP;
		$this->assertSame( 429, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = self::CLIENT_IP . ', 198.51.100.1';
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );
	}

	/**
	 * La finestra del contatore si RINNOVA a ogni fallimento anche sulle rotte dati: retrodatando
	 * la scadenza a +60 s (ancora valida) un nuovo fallimento la riporta a ~+300 s.
	 */
	public function test_throttle_window_is_renewed_on_every_data_route_failure() {
		$this->seed_enrolled( 'tok-test', 1 );
		$timeout_option = '_transient_timeout_wphc_fail_' . hash( 'sha1', self::CLIENT_IP );

		$this->data_request( 'GET', '/ping', 'sbagliato' );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( $timeout_option ), 5 );

		update_option( $timeout_option, time() + 60 );
		$this->data_request( 'GET', '/ping', 'sbagliato' );

		$this->assertSame( 2, $this->throttle_failures() );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( $timeout_option ), 5 );
	}

	// -----------------------------------------------------------------------
	// Protocollo 2: firma HMAC anti-replay
	// -----------------------------------------------------------------------

	/**
	 * Protocollo 2: il Bearer da solo non basta (401), serve anche la firma HMAC valida.
	 */
	public function test_protocol_two_requires_signature() {
		$this->seed_enrolled( 'tok-test', 2 );

		$rejected = $this->data_request( 'GET', '/ping', 'tok-test' );
		$this->assertSame( 401, $rejected->get_status() );
		$this->assertSame( 'wphc_unauthorized', $this->error_code( $rejected ) );
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test', 'tok-test' )->get_status() );
	}

	/**
	 * La firma copre metodo, rotta, hash del corpo, timestamp e nonce: ogni scostamento e' 401
	 * con la stessa risposta generica; la query string NON e' firmata.
	 */
	public function test_signature_covers_method_route_body_timestamp_and_nonce() {
		$this->seed_enrolled( 'tok-test', 2 );
		$ok = function ( array $headers ) {
			$request = new WP_REST_Request( 'GET', self::NS . '/ping' );
			$request->set_header( 'Authorization', 'Bearer tok-test' );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}

			return rest_do_request( $request );
		};

		// Ogni rifiuto e' un 401 con il codice generico wphc_unauthorized (nessuna indicazione del controllo fallito).
		$reject = function ( array $headers, $label ) use ( $ok ) {
			$response = $ok( $headers );
			$this->assertSame( 401, $response->get_status(), $label );
			$this->assertSame( 'wphc_unauthorized', $this->error_code( $response ), $label );
		};

		// Firma valida di riferimento.
		$this->assertSame( 200, $ok( $this->signed_headers( 'tok-test', 'GET', '/ping' ) )->get_status() );

		// Rotta diversa (firmata per /health, usata su /ping).
		$reject( $this->signed_headers( 'tok-test', 'GET', '/health' ), 'rotta diversa' );
		// Metodo diverso.
		$reject( $this->signed_headers( 'tok-test', 'POST', '/ping' ), 'metodo diverso' );
		// Corpo diverso (la richiesta non ha corpo, la firma lo dava non vuoto).
		$reject( $this->signed_headers( 'tok-test', 'GET', '/ping', '{"a":1}' ), 'corpo diverso' );
		// Segreto diverso.
		$reject( $this->signed_headers( 'altro-segreto', 'GET', '/ping' ), 'segreto diverso' );
		// Header mancante (nonce).
		$headers = $this->signed_headers( 'tok-test', 'GET', '/ping' );
		unset( $headers['X-WPHC-Nonce'] );
		$reject( $headers, 'nonce mancante' );
		// Firma alterata.
		$headers                     = $this->signed_headers( 'tok-test', 'GET', '/ping' );
		$headers['X-WPHC-Signature'] = 'AAAA' . $headers['X-WPHC-Signature'];
		$reject( $headers, 'firma alterata' );
		// Timestamp spostato di +1 s con la STESSA firma: il timestamp e' coperto dalla firma (e' ancora nella finestra).
		$headers                     = $this->signed_headers( 'tok-test', 'GET', '/ping' );
		$headers['X-WPHC-Timestamp'] = (string) ( (int) $headers['X-WPHC-Timestamp'] + 1 );
		$reject( $headers, 'timestamp +1 con la stessa firma' );
		// Nonce sostituito con un altro (mai visto) con la STESSA firma: anche il nonce e' coperto dalla firma.
		$headers                 = $this->signed_headers( 'tok-test', 'GET', '/ping' );
		$headers['X-WPHC-Nonce'] = self::new_nonce();
		$reject( $headers, 'nonce sostituito con la stessa firma' );
	}

	/**
	 * Un POST con corpo: la firma copre l'hash esatto dei byte del corpo.
	 */
	public function test_signature_covers_post_body() {
		$this->seed_enrolled( 'tok-test', 2 );
		$body = '{"plugin":"akismet/akismet.php"}';

		// Chiama direttamente il permission_callback con le intestazioni e il corpo indicati.
		$check = function ( array $headers, $payload ) {
			$request = new WP_REST_Request( 'POST', self::NS . '/update/bulk/cancel' );
			$request->set_header( 'Authorization', 'Bearer tok-test' );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
			$request->set_body( $payload );

			return self::call( 'wphc_require_token', $request );
		};

		// Corpo identico a quello firmato: autorizzato.
		$this->assertTrue( $check( $this->signed_headers( 'tok-test', 'POST', '/update/bulk/cancel', $body ), $body ) );

		// Anche un solo byte in piu' nel corpo cambia l'hash: 401.
		$result = $check( $this->signed_headers( 'tok-test', 'POST', '/update/bulk/cancel', $body ), $body . ' ' );
		$this->assertWPError( $result );
		$this->assertSame( 'wphc_unauthorized', $result->get_error_code() );
	}

	/**
	 * Freschezza del timestamp: +/-300 s. Si prova a +/-290 (dentro) e +/-310 (fuori).
	 */
	public function test_signature_timestamp_window() {
		$this->seed_enrolled( 'tok-test', 2 );

		foreach ( array( -290, 290 ) as $offset ) {
			$headers = $this->signed_headers( 'tok-test', 'GET', '/ping', '', time() + $offset );
			$this->assertSame( 200, $this->request( 'GET', '/ping', null, array_merge( array( 'Authorization' => 'Bearer tok-test' ), $headers ) )->get_status(), "{$offset}s" );
		}
		foreach ( array( -310, 310 ) as $offset ) {
			$headers  = $this->signed_headers( 'tok-test', 'GET', '/ping', '', time() + $offset );
			$response = $this->request( 'GET', '/ping', null, array_merge( array( 'Authorization' => 'Bearer tok-test' ), $headers ) );
			$this->assertSame( 401, $response->get_status(), "{$offset}s" );
			$this->assertSame( 'wphc_unauthorized', $this->error_code( $response ), "{$offset}s" );
		}
	}

	/**
	 * Anti-replay: lo stesso nonce non si riusa. Un nonce di una richiesta FALLITA non viene
	 * registrato (solo dopo una firma valida), quindi si puo' riprovare con lo stesso nonce.
	 */
	public function test_signature_nonce_replay() {
		$this->seed_enrolled( 'tok-test', 2 );
		$nonce = self::new_nonce();
		$auth  = array( 'Authorization' => 'Bearer tok-test' );

		// Firma sbagliata con il nonce N: rifiutata e il nonce NON viene bruciato.
		$bad      = $this->signed_headers( 'altro-segreto', 'GET', '/ping', '', null, $nonce );
		$rejected = $this->request( 'GET', '/ping', null, array_merge( $auth, $bad ) );
		$this->assertSame( 401, $rejected->get_status() );
		$this->assertSame( 'wphc_unauthorized', $this->error_code( $rejected ) );
		$this->assertFalse( get_transient( 'wphc_nonce_' . hash( 'sha256', $nonce ) ) );

		// Firma giusta con lo stesso nonce: accettata...
		$good = $this->signed_headers( 'tok-test', 'GET', '/ping', '', null, $nonce );
		$this->assertSame( 200, $this->request( 'GET', '/ping', null, array_merge( $auth, $good ) )->get_status() );
		$this->assertNotFalse( get_transient( 'wphc_nonce_' . hash( 'sha256', $nonce ) ) );

		// ...e una seconda volta e' un replay. (Il timestamp cambia solo se passa un secondo:
		// si riusa l'header identico, che e' il caso reale di un replay.)
		$replay = $this->request( 'GET', '/ping', null, array_merge( $auth, $good ) );
		$this->assertSame( 401, $replay->get_status() );
		$this->assertSame( 'wphc_unauthorized', $this->error_code( $replay ) );
	}

	/**
	 * La query string non fa parte della firma: ?fresh=1 non invalida una firma gia' calcolata.
	 */
	public function test_signature_ignores_query_string() {
		$this->seed_enrolled( 'tok-test', 2 );
		$headers = array_merge( array( 'Authorization' => 'Bearer tok-test' ), $this->signed_headers( 'tok-test', 'GET', '/ping' ) );

		$this->assertSame( 200, $this->request( 'GET', '/ping', null, $headers, array( 'fresh' => '1' ) )->get_status() );
	}

	// -----------------------------------------------------------------------
	// Dual-stack (protocollo 1 con firma)
	// -----------------------------------------------------------------------

	/**
	 * Dual-stack: a protocollo 1 la firma non e' obbligatoria, ma se e' PRESENTE viene verificata.
	 * Una firma valida passa; una firma presente ma sbagliata e' 401 (e conta come fallimento).
	 */
	public function test_dual_stack_signature_is_verified_when_present_on_protocol_one() {
		$this->seed_enrolled( 'tok-test', 1 );

		// Senza firma: ok.
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );
		// Con firma valida: ok.
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-test', 'tok-test' )->get_status() );

		// Con firma presente ma sbagliata: 401, anche se il Bearer e' corretto.
		$this->set_throttle_failures( 0 );
		$rejected = $this->data_request( 'GET', '/ping', 'tok-test', 'altro-segreto' );
		$this->assertSame( 401, $rejected->get_status() );
		$this->assertSame( 'wphc_unauthorized', $this->error_code( $rejected ) );
		$this->assertSame( 1, $this->throttle_failures() );
	}

	/**
	 * Un fallimento di firma (con Bearer giusto) conta come fallimento di autenticazione:
	 * dopo 10 tentativi con firma errata scatta il 429.
	 */
	public function test_signature_failures_count_toward_the_throttle() {
		$this->seed_enrolled( 'tok-test', 2 );

		for ( $i = 0; $i < WP_HEALTH_CHECK_RATE_MAX_FAILS; $i++ ) {
			$this->assertSame( 401, $this->data_request( 'GET', '/ping', 'tok-test' )->get_status() );
		}

		$this->assertSame( 429, $this->data_request( 'GET', '/ping', 'tok-test', 'tok-test' )->get_status() );
	}

	// -----------------------------------------------------------------------
	// Rotazione: token precedente in finestra di grazia
	// -----------------------------------------------------------------------

	/**
	 * Durante la grazia il Bearer precedente va firmato con il segreto PRECEDENTE: una firma
	 * calcolata con il token nuovo, abbinata al Bearer vecchio, e' rifiutata.
	 */
	public function test_grace_window_signature_uses_the_previous_secret() {
		$this->seed_enrolled( 'tok-new', 2 );
		update_option( 'wp_health_check_token_prev', 'tok-old', false );
		update_option( 'wp_health_check_token_rotated_at', time() - 60, false );

		// Bearer vecchio + firma col segreto vecchio: ok.
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-old', 'tok-old' )->get_status() );
		// Bearer vecchio + firma col segreto nuovo: rifiutato.
		$this->assertSame( 'wphc_unauthorized', $this->error_code( $this->data_request( 'GET', '/ping', 'tok-old', 'tok-new' ) ) );
		// Bearer nuovo + firma col segreto vecchio: rifiutato.
		$this->assertSame( 'wphc_unauthorized', $this->error_code( $this->data_request( 'GET', '/ping', 'tok-new', 'tok-old' ) ) );
	}

	/**
	 * Confine della grazia: a 890 s il token precedente vale, a 910 s no (si evitano i
	 * secondi esatti attorno ai 900 per non avere test instabili).
	 */
	public function test_grace_window_boundary() {
		$this->seed_enrolled( 'tok-new', 1 );
		update_option( 'wp_health_check_token_prev', 'tok-old', false );

		update_option( 'wp_health_check_token_rotated_at', time() - 890, false );
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-old' )->get_status() );

		update_option( 'wp_health_check_token_rotated_at', time() - 910, false );
		$this->assertSame( 401, $this->data_request( 'GET', '/ping', 'tok-old' )->get_status() );
	}

	/**
	 * Il token precedente senza rotated_at (o senza token_prev) non e' mai accettato.
	 */
	public function test_previous_token_requires_rotation_timestamp() {
		$this->seed_enrolled( 'tok-new', 1 );
		update_option( 'wp_health_check_token_prev', 'tok-old', false );

		$this->assertSame( 401, $this->data_request( 'GET', '/ping', 'tok-old' )->get_status() );
	}
}
