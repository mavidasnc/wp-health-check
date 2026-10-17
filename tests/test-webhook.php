<?php
/**
 * Test di caratterizzazione del webhook di notifica firmato (fine job bulk), plugin 1.38.1:
 * scelta dell'URL, allowlist, firma in uscita, consegna, retry con backoff, payload.
 *
 * La firma HMAC usa il segreto del sito (wp_health_check_token): la stringa canonica
 * "POST\n<event>\n<sha256(body)>\n<timestamp>\n<nonce>" e' quella verificata dall'hub
 * (hub/services/fleet/webhook.py, build_webhook_canonical) e gia' coperta dai vettori di contratto
 * (tests/test-contract-vectors.php); qui si verifica che la richiesta davvero spedita la rispetti.
 *
 * Gli URL di prova sono IP letterali pubblici (8.8.8.8): wp_http_validate_url() risolverebbe un nome
 * di dominio con gethostbyname(), cioe' con una vera richiesta DNS. Nessuna richiesta parte davvero.
 *
 * Comportamenti sorprendenti fissati (da preservare o cambiare con decisione esplicita):
 *  - il campo retry_at di wp_health_check_webhook_last e' calcolato dal solo codice d'errore
 *    (transport/http) e dal numero di tentativo, non da "retryable": un 4xx definitivo riporta
 *    comunque un retry_at, anche se nessun retry verra' pianificato;
 *  - un retry cron riuscito (o fallito) NON aggiorna il documento del job: job.webhook resta
 *    com'era dopo il primo tentativo.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-update.php';

/**
 * Webhook di notifica.
 */
class WPHC_Test_Upd_Webhook extends WPHC_Update_Test_Case {

	/** URL di prova (IP pubblico letterale). */
	const HOOK_URL = 'https://8.8.8.8/hook';

	/** Evento del report di fine job. */
	const EVENT = 'wphc.bulk_update.completed';

	/**
	 * Attiva il webhook sull'URL di prova e imposta la risposta del ricevente.
	 *
	 * @param mixed $response Risposta finta (array o WP_Error).
	 */
	private function enable_webhook( $response ) {
		update_option( 'wp_health_check_webhook_url', self::HOOK_URL );
		$this->mock_http( array( self::HOOK_URL => $response ) );
	}

	/**
	 * Chiamate HTTP verso il webhook.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function hook_calls() {
		return array_values(
			array_filter(
				$this->http_calls,
				static function ( $call ) {
					return WPHC_Test_Upd_Webhook::HOOK_URL === $call['url'];
				}
			)
		);
	}

	/**
	 * Ricalcola la firma attesa di una richiesta spedita, con il segreto del sito.
	 *
	 * @param array  $args  Argomenti della richiesta catturati.
	 * @param string $event Evento.
	 * @return string Firma base64url.
	 */
	private function expected_signature( array $args, $event ) {
		$headers   = $args['headers'];
		$canonical = "POST\n" . $event . "\n" . hash( 'sha256', $args['body'] ) . "\n" . $headers['X-WPHC-Timestamp'] . "\n" . $headers['X-WPHC-Nonce'];

		return self::b64url( hash_hmac( 'sha256', $canonical, get_option( 'wp_health_check_token' ), true ) );
	}

	/**
	 * Job di prova con N elementi pendenti (documento completo, contatori coerenti).
	 *
	 * @param int $count Numero di elementi.
	 * @return array
	 */
	private function sample_job( $count = 2 ) {
		$plugins = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$plugins[] = "w{$i}/w{$i}.php";
		}
		$normalized = wphc_bulk_normalize_items( array( 'plugins' => $plugins ) );
		$job        = array(
			'job_id'        => 'abcdef0123456789',
			'status'        => 'completed',
			'abort_reason'  => null,
			'created_ts'    => time() - 100,
			'started_ts'    => time() - 90,
			'finished_ts'   => time() - 10,
			'next_run_ts'   => time(),
			'ticks'         => 1,
			'source'        => 'wp-admin',
			'requested_ip'  => self::CLIENT_IP,
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
		wphc_bulk_recount( $job );

		return $job;
	}

	/**
	 * Confronta con un golden il risultato di una funzione (non una risposta REST) e le option scritte.
	 *
	 * @param string $name           Nome del golden.
	 * @param mixed  $result         Valore da fissare.
	 * @param array  $options_before Option prima.
	 */
	private function assert_result_golden( $name, $result, array $options_before ) {
		$response = new WP_REST_Response( $result, 200 );
		$this->assert_update_golden( $name, $response, $options_before );
	}

	// -----------------------------------------------------------------------
	// URL del webhook
	// -----------------------------------------------------------------------

	/**
	 * Default di flotta, override dell'URL del sito, disattivazione esplicita.
	 */
	public function test_webhook_url_resolution() {
		delete_option( 'wp_health_check_webhook_disabled' );

		// Nessuna impostazione: default di flotta (nei test e' l'URL .invalid della config).
		$this->assertSame( WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL, wphc_webhook_url() );
		$this->assertSame( 'https://webhook.invalid/wphc-test', wphc_webhook_url() );

		// URL del sito: vince sul default.
		update_option( 'wp_health_check_webhook_url', 'https://8.8.4.4/custom' );
		$this->assertSame( 'https://8.8.4.4/custom', wphc_webhook_url() );

		// Un URL esplicito vince anche sulla disattivazione.
		update_option( 'wp_health_check_webhook_disabled', 1 );
		$this->assertSame( 'https://8.8.4.4/custom', wphc_webhook_url() );

		// Senza URL, la disattivazione spegne tutto: stringa vuota.
		delete_option( 'wp_health_check_webhook_url' );
		$this->assertSame( '', wphc_webhook_url() );

		// Campo vuoto + non disattivato = default (un campo vuoto non significa piu' "spento").
		update_option( 'wp_health_check_webhook_url', '' );
		update_option( 'wp_health_check_webhook_disabled', 0 );
		$this->assertSame( WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL, wphc_webhook_url() );
	}

	/**
	 * Allowlist dell'URL: https, validatore di WordPress, IP letterali solo pubblici.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_allowlist() {
		return array(
			'https con IP pubblico'              => array( 'https://8.8.8.8/hook', true ),
			'https con IP pubblico e query'      => array( 'https://8.8.4.4/a/b?x=1', true ),
			'http non ammesso'                   => array( 'http://8.8.8.8/hook', false ),
			'ftp'                                => array( 'ftp://8.8.8.8/hook', false ),
			'senza schema'                       => array( '8.8.8.8/hook', false ),
			'stringa casuale'                    => array( 'non un url', false ),
			'vuoto'                              => array( '', false ),
			'IP privato 10.x'                    => array( 'https://10.0.0.5/hook', false ),
			'IP privato 192.168.x'               => array( 'https://192.168.1.10/hook', false ),
			'IP privato 172.16.x'                => array( 'https://172.16.0.1/hook', false ),
			'loopback'                           => array( 'https://127.0.0.1/hook', false ),
			'link-local / metadata cloud'        => array( 'https://169.254.169.254/latest', false ),
			'rete riservata 240.x'               => array( 'https://240.0.0.1/hook', false ),
			'credenziali nell\'URL'              => array( 'https://user:pass@8.8.8.8/hook', false ),
			'porta non standard'                 => array( 'https://8.8.8.8:8443/hook', false ),
			'porta 443 esplicita'                => array( 'https://8.8.8.8:443/hook', true ),
		);
	}

	/**
	 * Verifica l'allowlist.
	 *
	 * @dataProvider provide_allowlist
	 *
	 * @param string $url      URL.
	 * @param bool   $expected Atteso.
	 */
	public function test_webhook_url_is_allowed( $url, $expected ) {
		$this->assertSame( $expected, wphc_webhook_url_is_allowed( $url ) );
	}

	// -----------------------------------------------------------------------
	// Backoff
	// -----------------------------------------------------------------------

	/**
	 * Tabella [0, 300, 1800] indicizzata dal numero di tentativo (1 -> 300, 2 -> 1800), saturata.
	 */
	public function test_backoff_for_attempt() {
		$this->assertSame( array( 0, 300, 1800 ), WP_HEALTH_CHECK_WEBHOOK_BACKOFF );
		$this->assertSame( 3, WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS );
		foreach ( array( -1 => 0, 0 => 0, 1 => 300, 2 => 1800, 3 => 1800, 9 => 1800 ) as $attempt => $seconds ) {
			$this->assertSame( $seconds, wphc_webhook_backoff_for_attempt( $attempt ), "tentativo {$attempt}" );
		}
	}

	// -----------------------------------------------------------------------
	// Consegna: stati prima del trasporto
	// -----------------------------------------------------------------------

	/**
	 * Webhook spento: nessun invio e nessun errore ("ok" falso, error null), niente option di esito.
	 */
	public function test_dispatch_disabled_is_silent() {
		$before = $this->plugin_options();
		$result = wphc_dispatch_webhook_payload( self::EVENT, array( 'a' => 1 ), 'job1', 1 );

		$this->assertSame(
			array(
				'ok'        => false,
				'code'      => 0,
				'error'     => null,
				'retryable' => false,
			),
			$result
		);
		$this->assertSame( array(), $this->http_calls );
		$this->assertSame( array(), $this->blocked_http_calls );
		$this->assertFalse( get_option( 'wp_health_check_webhook_last' ) );
		$this->assertSame( $before, $this->plugin_options() );
	}

	/**
	 * Sito non enrolled: errore esplicito non ripetibile, esito registrato, nessuna richiesta.
	 */
	public function test_dispatch_not_enrolled() {
		$this->enable_webhook( $this->http_response( 200 ) );
		delete_option( 'wp_health_check_token' );
		$before = $this->plugin_options();

		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$this->assertSame( 'wphc_webhook_not_enrolled', $result['error'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( array(), $this->http_calls );
		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'wphc_webhook_not_enrolled', $last['error'] );
		$this->assertNull( $last['duration_ms'] );
		$this->assertSame( '8.8.8.8', $last['url_host'] );
		$this->assertNull( $last['retry_at'] );
		$this->assert_result_golden( 'update/webhook/dispatch-not-enrolled', $result, $before );
	}

	/**
	 * Sito revocato: tace del tutto, cancella il retry pendente e l'evento cron.
	 */
	public function test_dispatch_revoked_clears_pending_retry() {
		$this->enable_webhook( $this->http_response( 200 ) );
		update_option( 'wp_health_check_revoked_at', time() );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j', 'event' => self::EVENT, 'body' => array(), 'attempts' => 1 ), false );
		wp_schedule_single_event( time() + 300, 'wphc_webhook_retry' );

		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 2 );

		$this->assertSame( 'wphc_webhook_revoked', $result['error'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( array(), $this->http_calls );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assertSame( 'wphc_webhook_revoked', get_option( 'wp_health_check_webhook_last' )['error'] );
	}

	/**
	 * URL non ammesso (http o IP privato): errore 'wphc_webhook_url_blocked', nessuna richiesta.
	 */
	public function test_dispatch_url_blocked() {
		update_option( 'wp_health_check_webhook_url', 'http://8.8.8.8/hook' );
		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$this->assertSame( 'wphc_webhook_url_blocked', $result['error'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertSame( array(), $this->blocked_http_calls );

		update_option( 'wp_health_check_webhook_url', 'https://10.1.2.3/hook' );
		$this->assertSame( 'wphc_webhook_url_blocked', wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 )['error'] );
		$this->assertSame( array(), $this->blocked_http_calls );
	}

	/**
	 * Ordine dei controlli: non enrolled prima di revocato prima di URL bloccato.
	 */
	public function test_dispatch_check_order() {
		update_option( 'wp_health_check_webhook_url', 'http://8.8.8.8/hook' );
		update_option( 'wp_health_check_revoked_at', time() );
		delete_option( 'wp_health_check_token' );
		$this->assertSame( 'wphc_webhook_not_enrolled', wphc_dispatch_webhook_payload( self::EVENT, array(), 'j', 1 )['error'] );

		update_option( 'wp_health_check_token', 'tok' );
		$this->assertSame( 'wphc_webhook_revoked', wphc_dispatch_webhook_payload( self::EVENT, array(), 'j', 1 )['error'] );

		delete_option( 'wp_health_check_revoked_at' );
		$this->assertSame( 'wphc_webhook_url_blocked', wphc_dispatch_webhook_payload( self::EVENT, array(), 'j', 1 )['error'] );
	}

	// -----------------------------------------------------------------------
	// Consegna: richiesta in uscita e firma
	// -----------------------------------------------------------------------

	/**
	 * La richiesta spedita: header, firma ricalcolata sul corpo ESATTO, opzioni di trasporto.
	 */
	public function test_dispatch_request_headers_and_signature() {
		update_option( 'wp_health_check_secret_kid', 'k-test-1', false );
		$this->enable_webhook( $this->http_response( 200, 'ok' ) );
		$payload = array(
			'event' => self::EVENT,
			'x'     => array( 'accentata' => 'perche\'', 'slash' => 'a/b' ),
		);

		$result = wphc_dispatch_webhook_payload( self::EVENT, $payload, 'job-delivery-1', 1 );

		$this->assertSame(
			array(
				'ok'        => true,
				'code'      => 200,
				'error'     => null,
				'retryable' => false,
			),
			$result
		);
		$calls = $this->hook_calls();
		$this->assertCount( 1, $calls );
		$args = $calls[0]['args'];

		// Corpo: JSON codificato UNA volta, esattamente quello firmato.
		$this->assertSame( wp_json_encode( $payload ), $args['body'] );
		$this->assertSame( 'POST', $args['method'] );

		// Opzioni di trasporto (wp_safe_remote_post: URL sicuri, niente redirect, TLS verificato).
		$this->assertSame( 10, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );

		// Header, nell'ordine.
		$headers = $args['headers'];
		$this->assertSame(
			array( 'Content-Type', 'Accept', 'User-Agent', 'X-WPHC-Timestamp', 'X-WPHC-Nonce', 'X-WPHC-Signature', 'X-WPHC-Event', 'X-WPHC-Secret-Kid', 'X-WPHC-Site', 'X-WPHC-Delivery', 'X-WPHC-Attempt' ),
			array_keys( $headers )
		);
		$this->assertSame( 'application/json; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'application/json', $headers['Accept'] );
		$this->assertSame( 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION, $headers['User-Agent'] );
		$this->assertMatchesRegularExpression( '/^\d{10}$/', $headers['X-WPHC-Timestamp'] );
		$this->assertEqualsWithDelta( time(), (int) $headers['X-WPHC-Timestamp'], 5 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $headers['X-WPHC-Nonce'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $headers['X-WPHC-Signature'], 'HMAC-SHA256 in base64url senza padding.' );
		$this->assertSame( self::EVENT, $headers['X-WPHC-Event'] );
		$this->assertSame( 'k-test-1', $headers['X-WPHC-Secret-Kid'] );
		$this->assertSame( $this->own_site_url(), $headers['X-WPHC-Site'] );
		$this->assertSame( 'job-delivery-1', $headers['X-WPHC-Delivery'] );
		$this->assertSame( '1', $headers['X-WPHC-Attempt'] );

		// Firma: stringa canonica POST\nevent\nsha256(body)\nts\nnonce con il segreto del sito.
		$this->assertSame( $this->expected_signature( $args, self::EVENT ), $headers['X-WPHC-Signature'] );

		// Il segreto non viaggia mai nella richiesta.
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $args ) );

		// Golden degli header (con i valori volatili sostituiti).
		$snapshot                           = $headers;
		$snapshot['X-WPHC-Signature']       = '<SIG>';
		$snapshot['X-WPHC-Nonce']           = '<NONCE>';
		$snapshot['X-WPHC-Timestamp']       = '<TS>';
		$snapshot['_transport']             = array( 'timeout' => $args['timeout'], 'redirection' => $args['redirection'], 'sslverify' => $args['sslverify'], 'reject_unsafe_urls' => $args['reject_unsafe_urls'], 'method' => $args['method'] );
		$this->assert_result_golden( 'update/webhook/outbound-request', $snapshot, array() );
	}

	/**
	 * Senza kid configurato l'header X-WPHC-Secret-Kid e' una stringa vuota.
	 */
	public function test_dispatch_secret_kid_defaults_to_empty_string() {
		$this->enable_webhook( $this->http_response( 200 ) );
		wphc_dispatch_webhook_payload( self::EVENT, array(), 'j', 1 );

		$this->assertSame( '', $this->hook_calls()[0]['args']['headers']['X-WPHC-Secret-Kid'] );
	}

	/**
	 * Timestamp e nonce cambiano a ogni invio (firma sempre fresca) a parita' di corpo.
	 */
	public function test_each_dispatch_has_fresh_nonce() {
		$this->enable_webhook( $this->http_response( 200 ) );
		wphc_dispatch_webhook_payload( self::EVENT, array( 'a' => 1 ), 'j', 1 );
		wphc_dispatch_webhook_payload( self::EVENT, array( 'a' => 1 ), 'j', 2 );
		$calls = $this->hook_calls();

		$this->assertNotSame( $calls[0]['args']['headers']['X-WPHC-Nonce'], $calls[1]['args']['headers']['X-WPHC-Nonce'] );
		$this->assertNotSame( $calls[0]['args']['headers']['X-WPHC-Signature'], $calls[1]['args']['headers']['X-WPHC-Signature'] );
		$this->assertSame( $calls[0]['args']['body'], $calls[1]['args']['body'] );
		$this->assertSame( '2', $calls[1]['args']['headers']['X-WPHC-Attempt'] );
	}

	// -----------------------------------------------------------------------
	// Consegna: esiti del ricevente
	// -----------------------------------------------------------------------

	/**
	 * Codici HTTP: successo 2xx, errori ripetibili (408, 429, 5xx) e definitivi (altri 4xx, 3xx).
	 *
	 * @return array<string,array{0:int,1:bool,2:bool}>
	 */
	public function provide_http_codes() {
		return array(
			'200'           => array( 200, true, false ),
			'204'           => array( 204, true, false ),
			'299'           => array( 299, true, false ),
			'301'           => array( 301, false, false ),
			'400'           => array( 400, false, false ),
			'401'           => array( 401, false, false ),
			'404'           => array( 404, false, false ),
			'408 ripetibile' => array( 408, false, true ),
			'429 ripetibile' => array( 429, false, true ),
			'500 ripetibile' => array( 500, false, true ),
			'503 ripetibile' => array( 503, false, true ),
		);
	}

	/**
	 * Esito per codice.
	 *
	 * @dataProvider provide_http_codes
	 *
	 * @param int  $code      Codice HTTP.
	 * @param bool $ok        Consegnato.
	 * @param bool $retryable Ripetibile.
	 */
	public function test_dispatch_http_outcomes( $code, $ok, $retryable ) {
		$this->enable_webhook( $this->http_response( $code, 'corpo del ricevente' ) );
		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$this->assertSame( $ok, $result['ok'] );
		$this->assertSame( $code, $result['code'] );
		$this->assertSame( $ok ? null : 'wphc_webhook_http_error', $result['error'] );
		$this->assertSame( $retryable, $result['retryable'] );

		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertSame( $ok, $last['ok'] );
		$this->assertSame( $code, $last['code'] );
		$this->assertSame( 'job1', $last['job_id'] );
		$this->assertSame( 1, $last['attempt'] );
		$this->assertSame( $ok ? '' : 'corpo del ricevente', $last['message'] );
	}

	/**
	 * Forma di wp_health_check_webhook_last e retry_at: calcolato dal solo codice d'errore e dal tentativo.
	 */
	public function test_webhook_last_shape_and_retry_at() {
		$this->enable_webhook( $this->http_response( 500, 'boom' ) );
		$before = $this->plugin_options();
		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertSame( array( 'at', 'ok', 'job_id', 'attempt', 'code', 'error', 'message', 'url_host', 'duration_ms', 'retry_at' ), array_keys( $last ) );
		$this->assertSame( '8.8.8.8', $last['url_host'], 'Solo l\'host, mai l\'URL completo.' );
		$this->assertIsInt( $last['duration_ms'] );
		$this->assertEqualsWithDelta( time() + 300, strtotime( $last['retry_at'] ), 5, 'Dopo il tentativo 1: +300 s.' );
		$this->assert_result_golden( 'update/webhook/dispatch-http-500', $result, $before );

		wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 2 );
		$this->assertEqualsWithDelta( time() + 1800, strtotime( get_option( 'wp_health_check_webhook_last' )['retry_at'] ), 5, 'Dopo il tentativo 2: +1800 s.' );

		wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 3 );
		$this->assertNull( get_option( 'wp_health_check_webhook_last' )['retry_at'], 'Al terzo tentativo nessun altro retry.' );

		// Preservato: un 4xx definitivo riporta comunque retry_at (calcolato dal codice d'errore, non da "retryable").
		$this->http_routes[ self::HOOK_URL ] = $this->http_response( 400, 'no' );
		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );
		$this->assertFalse( $result['retryable'] );
		$this->assertNotNull( get_option( 'wp_health_check_webhook_last' )['retry_at'] );

		// Un successo non ha retry_at.
		$this->http_routes[ self::HOOK_URL ] = $this->http_response( 200 );
		wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );
		$this->assertNull( get_option( 'wp_health_check_webhook_last' )['retry_at'] );
		$this->assertTrue( get_option( 'wp_health_check_webhook_last' )['ok'] );
	}

	/**
	 * Errore di trasporto: codice 0, ripetibile, messaggio dell'errore registrato.
	 */
	public function test_dispatch_transport_error() {
		$this->enable_webhook( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
		$result = wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$this->assertSame(
			array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_transport_error',
				'retryable' => true,
			),
			$result
		);
		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertSame( 'cURL error 28: timeout', $last['message'] );
		$this->assertSame( 0, $last['code'] );
		$this->assertNotNull( $last['retry_at'] );
	}

	/**
	 * Il messaggio dell'esito e' troncato a 500 caratteri.
	 */
	public function test_dispatch_message_is_truncated() {
		$this->enable_webhook( $this->http_response( 500, str_repeat( 'é', 800 ) ) );
		wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$message = get_option( 'wp_health_check_webhook_last' )['message'];
		$this->assertSame( 500, mb_strlen( $message ) );
	}

	/**
	 * L'option dell'ultimo esito non e' autoloaded.
	 */
	public function test_webhook_last_is_not_autoloaded() {
		global $wpdb;
		$this->enable_webhook( $this->http_response( 200 ) );
		wphc_dispatch_webhook_payload( self::EVENT, array(), 'job1', 1 );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wp_health_check_webhook_last' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ) );
	}

	// -----------------------------------------------------------------------
	// Payload del report
	// -----------------------------------------------------------------------

	/**
	 * Forma del payload: schema, evento, sito, versione, kid, job, totali, elementi; nessun segreto.
	 */
	public function test_payload_shape() {
		update_option( 'wp_health_check_secret_kid', 'kid-9', false );
		$job                         = $this->sample_job( 2 );
		$job['items'][0]['state']    = 'done';
		$job['items'][0]['result']   = 'updated';
		$job['items'][0]['name']     = 'W1';
		$job['items'][0]['from']     = '1.0';
		$job['items'][0]['to']       = '1.1';
		$job['items'][0]['attempts'] = 0;
		$job['items'][0]['log_id']   = 44;
		$job['items'][0]['finished_ts'] = time() - 20;
		wphc_bulk_recount( $job );

		$payload = wphc_bulk_build_webhook_payload( $job );

		$this->assertSame( array( 'schema', 'event', 'site', 'agent_version', 'secret_kid', 'generated_at', 'job', 'totals', 'items', 'items_truncated' ), array_keys( $payload ) );
		$this->assertSame( 'wphc.bulk_update.report/1', $payload['schema'] );
		$this->assertSame( self::EVENT, $payload['event'] );
		$this->assertSame( $this->own_site_url(), $payload['site'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $payload['agent_version'] );
		$this->assertSame( 'kid-9', $payload['secret_kid'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $payload['generated_at'] );
		$this->assertSame( array( 'id', 'source', 'status', 'abort_reason', 'started_at', 'finished_at' ), array_keys( $payload['job'] ) );
		$this->assertSame( 'abcdef0123456789', $payload['job']['id'] );
		$this->assertSame( 'wp-admin', $payload['job']['source'] );
		$this->assertSame( 'completed', $payload['job']['status'] );
		$this->assertNull( $payload['job']['abort_reason'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $payload['job']['started_at'] );
		$this->assertSame( $job['counters'], $payload['totals'] );
		$this->assertFalse( $payload['items_truncated'] );
		$this->assertSame(
			array( 'type', 'target', 'name', 'from', 'to', 'result', 'attempts', 'log_id', 'finished_at' ),
			array_keys( $payload['items'][0] )
		);
		$this->assertSame( 'updated', $payload['items'][0]['result'] );
		$this->assertSame( 44, $payload['items'][0]['log_id'] );
		$this->assertNull( $payload['items'][1]['finished_at'], 'Elemento non concluso: nessuna data.' );

		// Nessun dato interno del job (token, ip, correlazioni) nel report.
		$encoded = wp_json_encode( $payload );
		$this->assertStringNotContainsString( self::TOKEN, $encoded );
		$this->assertStringNotContainsString( self::CLIENT_IP, $encoded );
		$this->assertStringNotContainsString( 'requested_ip', $encoded );
		$this->assertStringNotContainsString( 'correlations', $encoded );

		// Job senza started/finished: date null; senza kid: stringa vuota.
		delete_option( 'wp_health_check_secret_kid' );
		$job['started_ts']  = null;
		$job['finished_ts'] = null;
		$payload            = wphc_bulk_build_webhook_payload( $job );
		$this->assertNull( $payload['job']['started_at'] );
		$this->assertNull( $payload['job']['finished_at'] );
		$this->assertSame( '', $payload['secret_kid'] );
	}

	/**
	 * Tetto di 200 elementi nel corpo: oltre, items_truncated=true e i totali restano quelli veri.
	 */
	public function test_payload_items_are_capped_at_200() {
		$this->assertSame( 200, WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS );
		$job = $this->sample_job( 3 );
		for ( $i = 4; $i <= 205; $i++ ) {
			$job['items'][] = wphc_bulk_new_item( 'plugin', "w{$i}/w{$i}.php" );
		}
		wphc_bulk_recount( $job );

		$payload = wphc_bulk_build_webhook_payload( $job );
		$this->assertCount( 200, $payload['items'] );
		$this->assertTrue( $payload['items_truncated'] );
		$this->assertSame( 205, $payload['totals']['total'] );

		// Esattamente 200: non troncato.
		$job['items'] = array_slice( $job['items'], 0, 200 );
		wphc_bulk_recount( $job );
		$payload = wphc_bulk_build_webhook_payload( $job );
		$this->assertCount( 200, $payload['items'] );
		$this->assertFalse( $payload['items_truncated'] );
	}

	// -----------------------------------------------------------------------
	// Invio per un job e retry via cron
	// -----------------------------------------------------------------------

	/**
	 * Consegna riuscita: job.webhook segnato come inviato, nessun retry pendente.
	 */
	public function test_send_for_job_success() {
		$this->enable_webhook( $this->http_response( 202, 'accepted' ) );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'vecchio' ), false );
		$job = $this->sample_job( 2 );

		wphc_bulk_send_webhook_for_job( $job );

		$this->assertSame( 1, $job['webhook']['attempts'] );
		$this->assertTrue( $job['webhook']['sent'] );
		$this->assertSame( 202, $job['webhook']['code'] );
		$this->assertNull( $job['webhook']['last_error'] );
		$this->assertNotNull( $job['webhook']['sending_ts'] );
		$this->assertNotNull( $job['webhook']['sent_ts'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ), 'Il pendente di un invio precedente viene cancellato.' );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );

		// Il corpo spedito e' il payload del job; l'evento e l'id di consegna quelli del job.
		$args = $this->hook_calls()[0]['args'];
		$this->assertSame( 'abcdef0123456789', $args['headers']['X-WPHC-Delivery'] );
		$this->assertSame( self::EVENT, json_decode( $args['body'], true )['event'] );
		$this->assertSame( 'abcdef0123456789', json_decode( $args['body'], true )['job']['id'] );
	}

	/**
	 * Fallimento ripetibile: option "pending" (non autoloaded) con il corpo, evento cron a +300 s, stato del job.
	 */
	public function test_send_for_job_retryable_failure_schedules_retry() {
		global $wpdb;
		$this->enable_webhook( $this->http_response( 503, 'giu' ) );
		$job    = $this->sample_job( 2 );
		$before = $this->plugin_options();

		wphc_bulk_send_webhook_for_job( $job );

		$this->assertFalse( $job['webhook']['sent'] );
		$this->assertSame( 503, $job['webhook']['code'] );
		$this->assertSame( 'wphc_webhook_http_error', $job['webhook']['last_error'] );
		$this->assertNull( $job['webhook']['sent_ts'] );

		$pending = get_option( 'wp_health_check_webhook_pending' );
		$this->assertSame( array( 'job_id', 'event', 'body', 'attempts' ), array_keys( $pending ) );
		$this->assertSame( 'abcdef0123456789', $pending['job_id'] );
		$this->assertSame( self::EVENT, $pending['event'] );
		$this->assertSame( 1, $pending['attempts'] );
		$this->assertSame( 'wphc.bulk_update.report/1', $pending['body']['schema'] );
		$this->assertEqualsWithDelta( time() + 300, wp_next_scheduled( 'wphc_webhook_retry' ), 5 );
		$this->assertFalse( wp_get_scheduled_event( 'wphc_webhook_retry' )->schedule, 'Evento singolo.' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wp_health_check_webhook_pending' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ) );
		$this->assert_result_golden( 'update/webhook/send-retryable-failure', $job['webhook'], $before );
	}

	/**
	 * Niente retry per un errore definitivo (400) o al raggiungimento di 3 tentativi.
	 */
	public function test_send_for_job_no_retry_when_not_retryable_or_exhausted() {
		$this->enable_webhook( $this->http_response( 400, 'rifiutato' ) );
		$job = $this->sample_job( 1 );
		wphc_bulk_send_webhook_for_job( $job );
		$this->assertSame( 'wphc_webhook_http_error', $job['webhook']['last_error'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );

		// Ripetibile ma al terzo tentativo: niente altro retry.
		$this->http_routes[ self::HOOK_URL ] = $this->http_response( 500, '' );
		$job                                 = $this->sample_job( 1 );
		$job['webhook']['attempts']          = 2;
		wphc_bulk_send_webhook_for_job( $job );
		$this->assertSame( 3, $job['webhook']['attempts'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
	}

	/**
	 * Webhook spento: tentativo contato, nessun errore, nessuna consegna.
	 */
	public function test_send_for_job_when_disabled() {
		$job = $this->sample_job( 1 );
		wphc_bulk_send_webhook_for_job( $job );

		$this->assertSame( 1, $job['webhook']['attempts'] );
		$this->assertFalse( $job['webhook']['sent'] );
		$this->assertSame( 0, $job['webhook']['code'] );
		$this->assertNull( $job['webhook']['last_error'] );
		$this->assertNotNull( $job['webhook']['sending_ts'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
	}

	/**
	 * Retry cron: ri-firma con timestamp e nonce nuovi lo STESSO corpo, con il numero di tentativo successivo.
	 */
	public function test_retry_resends_same_body_with_fresh_signature() {
		$this->enable_webhook( $this->http_response( 503, '' ) );
		$job = $this->sample_job( 2 );
		wphc_bulk_send_webhook_for_job( $job );
		$first = $this->hook_calls()[0]['args'];

		$this->http_routes[ self::HOOK_URL ] = $this->http_response( 200, 'ok' );
		wphc_maybe_retry_webhook();

		$calls = $this->hook_calls();
		$this->assertCount( 2, $calls );
		$retry = $calls[1]['args'];
		$this->assertSame( $first['body'], $retry['body'], 'Stesso corpo.' );
		$this->assertSame( '2', $retry['headers']['X-WPHC-Attempt'] );
		$this->assertSame( 'abcdef0123456789', $retry['headers']['X-WPHC-Delivery'] );
		$this->assertNotSame( $first['headers']['X-WPHC-Nonce'], $retry['headers']['X-WPHC-Nonce'] );
		$this->assertSame( $this->expected_signature( $retry, self::EVENT ), $retry['headers']['X-WPHC-Signature'] );

		// Riuscito: pendente cancellato, lock rilasciato, nessun nuovo evento.
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( get_transient( 'wphc_webhook_retry_lock' ) );
		$this->assertTrue( get_option( 'wp_health_check_webhook_last' )['ok'] );
		$this->assertSame( 2, get_option( 'wp_health_check_webhook_last' )['attempt'] );
	}

	/**
	 * Preservato: il retry non aggiorna il documento del job (job.webhook resta com'era dopo il primo tentativo).
	 */
	public function test_retry_does_not_update_the_job_document() {
		$this->enable_webhook( $this->http_response( 500, '' ) );
		$this->enqueue_bulk( array( 'plugins' => array( 'n/n.php' ) ) );
		$this->run_tick();

		$job = $this->bulk_job();
		$this->assertSame( 'completed', $job['status'] );
		$this->assertFalse( $job['webhook']['sent'] );
		$this->assertSame( 1, $job['webhook']['attempts'] );
		$this->assertIsArray( get_option( 'wp_health_check_webhook_pending' ) );

		$this->http_routes[ self::HOOK_URL ] = $this->http_response( 200, '' );
		wphc_maybe_retry_webhook();

		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$after = $this->bulk_job();
		$this->assertFalse( $after['webhook']['sent'], 'Il documento del job non registra il retry riuscito.' );
		$this->assertSame( 1, $after['webhook']['attempts'] );
	}

	/**
	 * Retry fallito ripetibile: il pendente avanza (attempts) e il retry successivo e' a +1800 s;
	 * al terzo tentativo, o con errore definitivo, il pendente e' cancellato.
	 */
	public function test_retry_progression_and_exhaustion() {
		$this->enable_webhook( $this->http_response( 500, '' ) );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j1', 'event' => self::EVENT, 'body' => array( 'k' => 'v' ), 'attempts' => 1 ), false );

		// Tentativo 2: fallisce ancora, ripetibile -> nuovo retry a +1800.
		wphc_maybe_retry_webhook();
		$pending = get_option( 'wp_health_check_webhook_pending' );
		$this->assertSame( 2, $pending['attempts'] );
		$this->assertEqualsWithDelta( time() + 1800, wp_next_scheduled( 'wphc_webhook_retry' ), 5 );
		$this->assertFalse( get_transient( 'wphc_webhook_retry_lock' ) );

		// Tentativo 3: ultimo, pendente cancellato anche se ripetibile.
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
		wphc_maybe_retry_webhook();
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assertSame( 3, get_option( 'wp_health_check_webhook_last' )['attempt'] );

		// Errore definitivo al secondo tentativo: pendente cancellato.
		$this->http_routes[ self::HOOK_URL ] = $this->http_response( 404, '' );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j1', 'event' => self::EVENT, 'body' => array(), 'attempts' => 1 ), false );
		wphc_maybe_retry_webhook();
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
	}

	/**
	 * Nessun pendente, oppure lock di retry attivo: nessuna richiesta.
	 */
	public function test_retry_noop_cases() {
		$this->enable_webhook( $this->http_response( 200, '' ) );

		wphc_maybe_retry_webhook();
		$this->assertSame( array(), $this->hook_calls(), 'Nessun pendente.' );

		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j1', 'event' => self::EVENT, 'body' => array(), 'attempts' => 1 ), false );
		set_transient( 'wphc_webhook_retry_lock', time(), 300 );
		wphc_maybe_retry_webhook();
		$this->assertSame( array(), $this->hook_calls(), 'Lock di retry attivo.' );
		$this->assertIsArray( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertNotFalse( get_transient( 'wphc_webhook_retry_lock' ), 'Il lock altrui resta.' );

		// Pendente non valido (non array): ignorato.
		delete_transient( 'wphc_webhook_retry_lock' );
		update_option( 'wp_health_check_webhook_pending', 'rotto', false );
		wphc_maybe_retry_webhook();
		$this->assertSame( array(), $this->hook_calls() );
	}

	/**
	 * Retry di un sito revocato nel frattempo: nessuna richiesta, pendente e cron cancellati.
	 */
	public function test_retry_for_revoked_site_is_dropped() {
		$this->enable_webhook( $this->http_response( 200, '' ) );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j1', 'event' => self::EVENT, 'body' => array(), 'attempts' => 1 ), false );
		update_option( 'wp_health_check_revoked_at', time() );

		wphc_maybe_retry_webhook();

		$this->assertSame( array(), $this->hook_calls() );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
	}
}
