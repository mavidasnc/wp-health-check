<?php
/**
 * PRESERVATO: bug noto, vedi finding (`__docs/wp-health-check/finding-throttle-doppia-valutazione.md`).
 *
 * Su richieste HTTP reali il `permission_callback` `wphc_require_token` gira PIU' di una volta per richiesta:
 * la prima e' quella del dispatch, le altre sono dentro `rest_send_allow_header()` del core (agganciata a
 * `rest_post_dispatch`), che per ogni handler e per ogni metodo della rotta abbinata richiama il
 * permission_callback con lo STESSO oggetto richiesta e ne scarta l'esito. Per una richiesta firmata
 * (protocollo 2) la seconda valutazione trova il nonce appena registrato dalla prima e fallisce, quindi chiama
 * `wphc_throttle_register_failure()`: ogni richiesta VALIDA aumenta il contatore dei fallimenti.
 *
 * `rest_do_request()` (usato da tutta la suite) chiama solo `dispatch()` e NON passa da `rest_post_dispatch`:
 * per questo il fenomeno non si vede in-process. Qui si riproduce la fase mancante chiamando
 * `apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request )` con lo stesso oggetto richiesta,
 * come fa `WP_REST_Server::serve_request()`, e si fissa il delta del contatore. Gli stessi numeri sono misurati su
 * HTTP vero dall'e2e (`tests/e2e/hub_contract.py`).
 *
 * Se il bug verra' corretto (rendere `require_token` idempotente per richiesta, vedi il finding, par. 6) questi
 * test vanno INVERTITI con decisione esplicita: +0 per una richiesta valida, +1 per un Bearer errato.
 *
 * @package WP_Health_Check
 */

/**
 * Pin del comportamento: delta del contatore del throttle con la fase `rest_post_dispatch`.
 */
class WPHC_Test_Throttle_Double_Dispatch extends WPHC_Test_Case {

	/** Segreto del sito di test. */
	const SECRET = 'tok-throttle-double';

	/**
	 * Esegue la richiesta come farebbe `serve_request()`: dispatch e poi `rest_post_dispatch` con lo STESSO
	 * oggetto richiesta (e' quello che rifa' girare il permission_callback).
	 *
	 * @param string      $method  Metodo.
	 * @param string      $route   Rotta senza namespace.
	 * @param string|null $bearer  Token Bearer (null = nessun header Authorization).
	 * @param string|null $hmac    Segreto per la firma X-WPHC-* (null = nessuna firma).
	 * @param bool        $second  Se false si salta la fase rest_post_dispatch (solo il dispatch).
	 * @return WP_REST_Response
	 */
	private function dispatch_like_http( $method, $route, $bearer, $hmac, $second = true ) {
		$request = new WP_REST_Request( $method, self::NS . $route );
		if ( null !== $bearer ) {
			$request->set_header( 'Authorization', 'Bearer ' . $bearer );
		}
		if ( null !== $hmac ) {
			foreach ( $this->signed_headers( $hmac, $method, $route ) as $name => $value ) {
				$request->set_header( $name, $value );
			}
		}

		$response = rest_do_request( $request );
		if ( $second ) {
			// La fase che in-process manca: il filtro del core rivaluta il permission_callback.
			apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		}

		return $response;
	}

	/**
	 * Il filtro del core che causa la doppia valutazione deve essere agganciato: se un'altra versione di WordPress
	 * lo togliesse, i numeri sotto cambierebbero per una ragione che non e' del plugin.
	 */
	public function test_the_core_filter_that_reevaluates_permissions_is_hooked() {
		rest_get_server();
		$this->assertSame( 10, has_filter( 'rest_post_dispatch', 'rest_send_allow_header' ) );
	}

	/**
	 * GET /ping valido (protocollo 2, Bearer e firma giusti): dispatch (riuscito) piu' UNA rivalutazione
	 * (handler unico, metodo GET) che fallisce sul nonce gia' usato: +1. PRESERVATO: bug noto, vedi finding.
	 */
	public function test_valid_v2_single_handler_route_adds_one_failure() {
		$this->seed_enrolled( self::SECRET, 2 );
		$this->assertSame( 0, $this->throttle_failures() );

		$response = $this->dispatch_like_http( 'GET', '/ping', self::SECRET, self::SECRET );

		$this->assertSame( 200, $response->get_status(), 'la richiesta del dispatch e\' valida' );
		$this->assertSame( 1, $this->throttle_failures() );
	}

	/**
	 * La stessa richiesta senza la fase `rest_post_dispatch` (come in tutta la suite) NON incrementa: e' la
	 * prova che il +1 viene dalla rivalutazione del core e non dal dispatch.
	 */
	public function test_without_the_post_dispatch_phase_a_valid_request_adds_nothing() {
		$this->seed_enrolled( self::SECRET, 2 );

		$response = $this->dispatch_like_http( 'GET', '/ping', self::SECRET, self::SECRET, false );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * GET /update/bulk valido: la rotta ha DUE handler (GET e POST registrati separatamente), quindi la fase
	 * `rest_post_dispatch` rivaluta il permission_callback due volte (una per metodo): +2. PRESERVATO.
	 */
	public function test_valid_v2_two_handler_route_adds_two_failures() {
		$this->seed_enrolled( self::SECRET, 2 );

		$response = $this->dispatch_like_http( 'GET', '/update/bulk', self::SECRET, self::SECRET );

		$this->assertSame( 200, $response->get_status(), 'la richiesta del dispatch e\' valida' );
		$this->assertSame( 2, $this->throttle_failures() );
	}

	/**
	 * Bearer errato: il dispatch fallisce (+1) e ogni rivalutazione fallisce di nuovo: +2 su /ping (un handler).
	 * PRESERVATO: la protezione contro la forza bruta conta di piu', non di meno.
	 */
	public function test_wrong_bearer_adds_two_failures_on_a_single_handler_route() {
		$this->seed_enrolled( self::SECRET, 2 );

		$response = $this->dispatch_like_http( 'GET', '/ping', 'bearer-sbagliato', null );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 2, $this->throttle_failures() );
	}

	/**
	 * Bearer errato su /update/bulk (due handler): +1 del dispatch e +2 delle rivalutazioni = +3.
	 */
	public function test_wrong_bearer_adds_three_failures_on_a_two_handler_route() {
		$this->seed_enrolled( self::SECRET, 2 );

		$response = $this->dispatch_like_http( 'GET', '/update/bulk', 'bearer-sbagliato', null );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 3, $this->throttle_failures() );
	}

	/**
	 * OPTIONS (preflight CORS): `wphc_handle_options_preflight` risponde in `rest_pre_dispatch`, la risposta non
	 * ha una rotta abbinata e `rest_send_allow_header()` esce subito: nessun permission_callback, +0.
	 */
	public function test_options_preflight_adds_nothing() {
		$this->seed_enrolled( self::SECRET, 2 );

		$response = $this->dispatch_like_http( 'OPTIONS', '/ping', null, null );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * Protocollo 1 valido (solo Bearer, nessun nonce): la rivalutazione riesce di nuovo e non incrementa: +0.
	 * Il fenomeno riguarda solo le richieste firmate.
	 */
	public function test_valid_v1_request_adds_nothing() {
		$this->seed_enrolled( self::SECRET, 1 );

		$response = $this->dispatch_like_http( 'GET', '/ping', self::SECRET, null );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * Conseguenza pratica: con la soglia di 10 fallimenti, DIECI richieste firmate valide (una per ogni +1) riempiono
	 * il contatore e l'undicesima, anche se valida, riceve 429. PRESERVATO: bug noto (auto-blocco del cliente
	 * legittimo), vedi il finding par. 3 e 5.
	 */
	public function test_ten_valid_v2_requests_lock_out_the_eleventh() {
		$this->seed_enrolled( self::SECRET, 2 );

		for ( $i = 1; $i <= 10; $i++ ) {
			$response = $this->dispatch_like_http( 'GET', '/ping', self::SECRET, self::SECRET );
			$this->assertSame( 200, $response->get_status(), "richiesta {$i}" );
		}
		$this->assertSame( WP_HEALTH_CHECK_RATE_MAX_FAILS, $this->throttle_failures() );

		$response = $this->dispatch_like_http( 'GET', '/ping', self::SECRET, self::SECRET );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'wphc_rate_limited', $this->error_code( $response ) );
	}
}
