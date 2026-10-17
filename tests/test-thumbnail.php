<?php
/**
 * Test di caratterizzazione della thumbnail del sito (plugin 1.38.1): GET /thumbnail, POST /thumbnail
 * e il suo uso da parte di GET /health, con HTTP mockato (thum.io) tramite `pre_http_request`.
 *
 * Cosa si fissa: stato restituito, rigenerazione (richiesta a thum.io, allegato nella Media Library,
 * option scritte), ogni codice d'errore diagnostico, lock anti-retry di un giorno e comportamenti
 * scomodi (marcati PRESERVATO). Gli upload vanno in una cartella fissa (filtro `upload_dir` della
 * classe base) cosi' URL e nomi file sono deterministici su qualunque ambiente.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-read.php';

/**
 * Thumbnail del sito.
 */
class WPHC_Test_Thumbnail extends WPHC_Read_Test_Case {

	/** URL della thumbnail dopo una generazione riuscita (upload fissi + nome file del plugin). */
	const THUMB_URL = 'https://uploads.invalid/wphc-test/wp-health-check-site-thumb.png';

	/**
	 * URL richiesto a thum.io: servizio + home pubblica del sito.
	 *
	 * @return string
	 */
	private function service_url() {
		return WP_HEALTH_CHECK_THUMB_SERVICE . get_home_url();
	}

	/**
	 * Mock di thum.io che risponde con un file (download in streaming) e registra le richieste.
	 *
	 * @param int    $code Codice HTTP.
	 * @param string $body Contenuto del file.
	 */
	private function mock_thumbnail_service( $code, $body ) {
		$service = $this->service_url();
		$this->mock_http(
			static function ( $url, $args ) use ( $service, $code, $body ) {
				return $service === $url ? self::http_stream_response( $args, $code, $body ) : null;
			}
		);
	}

	/**
	 * Numero di richieste fatte a thum.io finora.
	 *
	 * @return int
	 */
	private function service_calls() {
		return count( array_keys( wp_list_pluck( $this->http_requests, 'url' ), $this->service_url(), true ) );
	}

	// -----------------------------------------------------------------------
	// GET /thumbnail
	// -----------------------------------------------------------------------

	/**
	 * Senza thumbnail ne' errori: tutto null; la rotta registra l'accesso ma NON fa richieste in uscita.
	 */
	public function test_get_status_empty() {
		$this->enrolled();
		$before = $this->plugin_options();

		$response = $this->get_route( '/thumbnail' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'thumbnail'    => null,
				'generated_at' => null,
				'error'        => null,
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->blocked_http_calls, 'GET /thumbnail e\' solo lettura: nessuna richiesta remota' );
		$this->assertArrayHasKey( 'wp_health_check_last_request_at', $this->plugin_options() );

		$this->assert_read_golden( 'read/thumbnail-status-empty', $response, $before );
	}

	/**
	 * Con thumbnail e ultimo errore salvati: URL, data ISO da thumb_at ed errore completo. PRESERVATO:
	 * l'errore e' riportato anche se la thumbnail esiste (e' lo STORICO dell'ultimo fallimento).
	 */
	public function test_get_status_reports_stored_state() {
		$this->enrolled();
		update_option( 'wp_health_check_thumb', 'https://uploads.invalid/t.png', false );
		update_option( 'wp_health_check_thumb_at', 1700000000, false );
		$error = array(
			'at'       => '2026-01-01T00:00:00+00:00',
			'code'     => 'wphc_thumb_not_an_image',
			'message'  => 'vecchio errore',
			'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
		);
		update_option( 'wp_health_check_thumb_error', $error, false );
		$before = $this->plugin_options();

		$response = $this->get_route( '/thumbnail' );

		$this->assertSame(
			array(
				'thumbnail'    => 'https://uploads.invalid/t.png',
				'generated_at' => '2023-11-14T22:13:20+00:00',
				'error'        => $error,
			),
			$response->get_data()
		);
		$this->assert_read_golden( 'read/thumbnail-status-populated', $response, $before );
	}

	/**
	 * Casi limite dello stato: senza thumb_at `generated_at` e' null; senza thumbnail `generated_at` e' null
	 * anche con thumb_at (che resta orfano); un'option `thumb_error` che non e' un array diventa null.
	 */
	public function test_get_status_edge_cases() {
		$this->enrolled();

		update_option( 'wp_health_check_thumb', 'https://uploads.invalid/t.png', false );
		$this->assertNull( $this->get_route( '/thumbnail' )->get_data()['generated_at'], 'thumb senza thumb_at' );

		delete_option( 'wp_health_check_thumb' );
		update_option( 'wp_health_check_thumb_at', 1700000000, false );
		$data = $this->get_route( '/thumbnail' )->get_data();
		$this->assertNull( $data['thumbnail'] );
		$this->assertNull( $data['generated_at'], 'thumb_at orfano senza thumbnail' );

		update_option( 'wp_health_check_thumb_error', 'stringa', false );
		$this->assertNull( $this->get_route( '/thumbnail' )->get_data()['error'], 'error non-array = null' );
	}

	// -----------------------------------------------------------------------
	// POST /thumbnail: rigenerazione riuscita
	// -----------------------------------------------------------------------

	/**
	 * Rigenerazione riuscita: scarica da thum.io (GET in streaming, timeout 20 s, URL = servizio + home),
	 * crea l'allegato "WP Health Check site thumbnail" (senza post padre), scrive thumb/thumb_id/thumb_at,
	 * cancella thumb_error e il lock anti-retry.
	 */
	public function test_post_regenerates_thumbnail_successfully() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, self::png_bytes() );
		// Residui di un fallimento precedente: devono sparire.
		update_option(
			'wp_health_check_thumb_error',
			array(
				'at'       => '2026-01-01T00:00:00+00:00',
				'code'     => 'wphc_thumb_download_failed',
				'message'  => 'x',
				'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
			),
			false
		);
		set_transient( 'wphc_thumb_retry_lock', time(), DAY_IN_SECONDS );
		$before = $this->plugin_options();

		$response = $this->post_route( '/thumbnail' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'thumbnail' => self::THUMB_URL,
				'error'     => null,
			),
			$response->get_data()
		);

		// Una sola richiesta a thum.io: GET in streaming con timeout di 20 secondi.
		$this->assertSame( array( $this->service_url() ), wp_list_pluck( $this->http_requests, 'url' ) );
		$this->assertSame( 20, $this->http_requests[0]['args']['timeout'] );
		$this->assertTrue( $this->http_requests[0]['args']['stream'] );
		$this->assertSame( 'GET', $this->http_requests[0]['args']['method'] );

		// Option: thumb, thumb_id, thumb_at scritte; thumb_error cancellata; lock rimosso.
		$options = $this->plugin_options();
		$this->assertSame( self::THUMB_URL, $options['wp_health_check_thumb'] );
		$this->assertArrayNotHasKey( 'wp_health_check_thumb_error', $options );
		$this->assertFalse( get_transient( 'wphc_thumb_retry_lock' ) );
		$this->assertEqualsWithDelta( time(), (int) $options['wp_health_check_thumb_at'], 5 );

		// Allegato: titolo del plugin, nessun post padre, id salvato accanto all'URL.
		$attachment = get_post( (int) $options['wp_health_check_thumb_id'] );
		$this->assertSame( 'attachment', $attachment->post_type );
		$this->assertSame( 'WP Health Check site thumbnail', $attachment->post_title );
		$this->assertSame( 0, (int) $attachment->post_parent );
		$this->assertSame( 'image/png', $attachment->post_mime_type );

		$this->assert_read_golden( 'read/thumbnail-regenerate-success', $response, $before, array( 'options_written.wp_health_check_thumb_id' ) );
	}

	// -----------------------------------------------------------------------
	// POST /thumbnail: ogni punto di fallimento ha un codice diagnostico
	// -----------------------------------------------------------------------

	/**
	 * Esegue POST /thumbnail e verifica la forma comune di ogni fallimento: risposta 200 con thumbnail
	 * null ed errore strutturato {at, code, message, provider}, lo stesso errore persistito in
	 * `wp_health_check_thumb_error`, lock anti-retry impostato (~1 giorno), nessuna thumbnail salvata.
	 *
	 * @param string      $name    Nome del caso (anche nome del golden).
	 * @param string      $code    Codice d'errore atteso.
	 * @param string|null $message Messaggio atteso (null = solo "non vuoto").
	 * @param string[]    $env     Percorsi del golden da mascherare (testi che sono del core, non del plugin).
	 */
	private function assert_failure( $name, $code, $message = null, array $env = array() ) {
		$before = $this->plugin_options();

		$response = $this->post_route( '/thumbnail' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNull( $data['thumbnail'] );
		$this->assertSame( array( 'at', 'code', 'message', 'provider' ), array_keys( $data['error'] ) );
		$this->assertSame( $code, $data['error']['code'] );
		$this->assertSame( WP_HEALTH_CHECK_THUMB_SERVICE, $data['error']['provider'] );
		if ( null !== $message ) {
			$this->assertSame( $message, $data['error']['message'] );
		}
		$this->assertNotSame( '', $data['error']['message'] );

		// L'errore e' persistito identico; nessuna thumbnail; lock anti-retry impostato per ~1 giorno.
		$options = $this->plugin_options();
		$this->assertSame( $data['error'], $options['wp_health_check_thumb_error'] );
		$this->assertArrayNotHasKey( 'wp_health_check_thumb', $options );
		$this->assertArrayNotHasKey( 'wp_health_check_thumb_id', $options );
		$this->assertGreaterThan( DAY_IN_SECONDS - 10, $this->transient_ttl( 'wphc_thumb_retry_lock' ) );

		$this->assert_read_golden( 'read/thumbnail-failure-' . $name, $response, $before, $env );
	}

	/**
	 * Errore di rete: il messaggio e' quello del WP_Error.
	 */
	public function test_post_failure_network_error() {
		$this->enrolled();
		$this->mock_http(
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
			}
		);

		$this->assert_failure( 'network-error', 'wphc_thumb_download_failed', 'cURL error 28: timeout' );
	}

	/**
	 * Risposta HTTP diversa da 200: il messaggio e' la frase di stato.
	 */
	public function test_post_failure_http_error_status() {
		$this->enrolled();
		$this->mock_thumbnail_service( 500, 'errore' );

		$this->assert_failure( 'http-error', 'wphc_thumb_download_failed', 'Internal Server Error' );
	}

	/**
	 * 200 ma il corpo non e' un'immagine.
	 */
	public function test_post_failure_not_an_image() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, "questo non e' un PNG" );

		$this->assert_failure( 'not-an-image', 'wphc_thumb_not_an_image' );
	}

	/**
	 * GIF animato: noanimate/png non hanno avuto effetto, il file e' scartato.
	 */
	public function test_post_failure_animated_gif() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, self::gif_bytes() );

		$this->assert_failure( 'animated-gif', 'wphc_thumb_animated_gif' );
	}

	/**
	 * uploads/ non scrivibile: il controllo e' PRIMA della richiesta remota (nessuna chiamata a thum.io) e
	 * il messaggio e' quello di wp_upload_dir(). Si simula con un percorso impossibile da creare
	 * (wp_upload_dir() memorizza in una static l'esito per percorso: va usato un percorso mai provato).
	 */
	public function test_post_failure_uploads_not_writable() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, self::png_bytes() );
		add_filter(
			'upload_dir',
			static function ( $dirs ) {
				$dirs['path'] = '/dev/null/wphc-test-non-scrivibile';

				return $dirs;
			},
			20
		);
		wp_upload_dir( null, true, true ); // Rilegge la cartella di upload con il filtro appena aggiunto.
		$this->assertNotEmpty( wp_upload_dir()['error'], 'precondizione: la cartella non risulta creabile' );

		// Il testo dell'errore e' del core: nel golden e' mascherato.
		$this->assert_failure( 'uploads-not-writable', 'wphc_thumb_uploads_not_writable', null, array( 'body.error.message', 'options_written.wp_health_check_thumb_error.message' ) );
		$this->assertSame( 0, $this->service_calls() );
	}

	/**
	 * Sideload rifiutato da WordPress (qui: tipo di file non consentito). Il messaggio e' del core:
	 * nel golden e' mascherato.
	 */
	public function test_post_failure_sideload_rejected() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, self::png_bytes() );
		add_filter(
			'wp_check_filetype_and_ext',
			static function ( $data ) {
				$data['ext']  = false;
				$data['type'] = false;

				return $data;
			}
		);

		$this->assert_failure( 'sideload-failed', 'wphc_thumb_sideload_failed', null, array( 'body.error.message', 'options_written.wp_health_check_thumb_error.message' ) );
	}

	/**
	 * Allegato creato ma senza URL recuperabile: stesso codice del sideload fallito, messaggio dedicato.
	 */
	public function test_post_failure_attachment_without_url() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, self::png_bytes() );
		add_filter( 'wp_get_attachment_url', '__return_empty_string' );

		$this->assert_failure( 'attachment-no-url', 'wphc_thumb_sideload_failed', 'Attachment creato ma senza un URL recuperabile.' );
	}

	/**
	 * PRESERVATO: con "attachment senza URL" l'allegato resta nella Media Library come orfano (non e'
	 * ne' cancellato ne' tracciato in thumb_id).
	 */
	public function test_post_attachment_without_url_leaves_orphan_attachment() {
		$this->enrolled();
		$this->mock_thumbnail_service( 200, self::png_bytes() );
		add_filter( 'wp_get_attachment_url', '__return_empty_string' );

		$this->post_route( '/thumbnail' );

		$orphans = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'title'       => 'WP Health Check site thumbnail',
				'numberposts' => -1,
			)
		);
		$this->assertCount( 1, $orphans );
		$this->assertFalse( get_option( 'wp_health_check_thumb_id' ) );
	}

	// -----------------------------------------------------------------------
	// POST /thumbnail: rigenerazione forzata
	// -----------------------------------------------------------------------

	/**
	 * PRESERVATO (scomodo): la rigenerazione forzata CANCELLA la thumbnail precedente (allegato e option)
	 * PRIMA di provare a generarne una nuova: se il servizio fallisce, il sito resta senza thumbnail.
	 */
	public function test_post_force_drops_previous_thumbnail_even_when_regeneration_fails() {
		$this->enrolled();
		$old_id = self::factory()->attachment->create( array( 'post_title' => 'Vecchia thumbnail' ) );
		update_option( 'wp_health_check_thumb', 'https://uploads.invalid/old.png', false );
		update_option( 'wp_health_check_thumb_id', $old_id, false );
		update_option( 'wp_health_check_thumb_at', 1700000000, false );
		$this->mock_thumbnail_service( 500, 'errore' );
		$before = $this->plugin_options();

		$response = $this->post_route( '/thumbnail' );

		$this->assertNull( $response->get_data()['thumbnail'] );
		$this->assertSame( 'wphc_thumb_download_failed', $response->get_data()['error']['code'] );
		$this->assertNull( get_post( $old_id ), 'l\'allegato precedente e\' stato cancellato (force delete)' );
		$options = $this->plugin_options();
		$this->assertArrayNotHasKey( 'wp_health_check_thumb', $options );
		$this->assertArrayNotHasKey( 'wp_health_check_thumb_id', $options );
		// thumb_at NON viene cancellata: resta orfana (lo vede solo la tab, GET /thumbnail la ignora senza thumb).
		$this->assertArrayHasKey( 'wp_health_check_thumb_at', $options );

		$this->assert_read_golden( 'read/thumbnail-force-failure-drops-previous', $response, $before );
	}

	/**
	 * POST ignora il lock anti-retry (e' una rigenerazione esplicita) ma lo RIIMPOSTA a ogni tentativo:
	 * dopo un fallimento il valore del lock e' quello del tentativo appena fatto.
	 */
	public function test_post_ignores_retry_lock_and_resets_it() {
		$this->enrolled();
		$this->mock_thumbnail_service( 500, 'errore' );
		$old_lock = time() - 5000;
		set_transient( 'wphc_thumb_retry_lock', $old_lock, DAY_IN_SECONDS );

		$this->post_route( '/thumbnail' );

		$this->assertSame( 1, $this->service_calls(), 'la richiesta parte nonostante il lock' );
		$this->assertGreaterThan( $old_lock, (int) get_transient( 'wphc_thumb_retry_lock' ), 'il lock e\' stato riscritto' );
	}

	// -----------------------------------------------------------------------
	// GET /health: uso della thumbnail e lock anti-retry
	// -----------------------------------------------------------------------

	/**
	 * Con la thumbnail gia' salvata /health la riporta senza alcuna richiesta e senza errori in summary,
	 * anche se esiste un vecchio `thumb_error`.
	 */
	public function test_health_uses_existing_thumbnail_without_http() {
		$this->enrolled();
		$_SERVER['SERVER_ADDR'] = '8.8.8.8';
		update_option( 'wp_health_check_thumb', 'https://uploads.invalid/t.png', false );
		update_option(
			'wp_health_check_thumb_error',
			array(
				'at'       => '2026-01-01T00:00:00+00:00',
				'code'     => 'wphc_thumb_animated_gif',
				'message'  => 'vecchio',
				'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
			),
			false
		);

		$summary = $this->get_route( '/health' )->get_data()['summary'];

		$this->assertSame( 'https://uploads.invalid/t.png', $summary['thumbnail'] );
		$this->assertNull( $summary['thumbnail_error'] );
		$this->assertSame( array(), $this->blocked_http_calls );
	}

	/**
	 * Senza thumbnail e senza lock /health ci prova UNA volta (fallisce: lock da un giorno e codice
	 * d'errore in summary); con il lock attivo non ritenta, nemmeno con ?fresh=1.
	 */
	public function test_health_tries_once_then_respects_retry_lock() {
		$this->enrolled();
		$_SERVER['SERVER_ADDR'] = '8.8.8.8';
		$this->mock_thumbnail_service( 500, 'errore' );

		$summary = $this->get_route( '/health' )->get_data()['summary'];
		$this->assertSame( 1, $this->service_calls() );
		$this->assertNull( $summary['thumbnail'] );
		$this->assertSame( 'wphc_thumb_download_failed', $summary['thumbnail_error'] );
		$this->assertGreaterThan( DAY_IN_SECONDS - 10, $this->transient_ttl( 'wphc_thumb_retry_lock' ) );

		// Ancora senza thumbnail: il lock impedisce un secondo tentativo (anche ricalcolando il payload).
		$summary = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data()['summary'];
		$this->assertSame( 1, $this->service_calls(), 'un solo tentativo al giorno' );
		$this->assertSame( 'wphc_thumb_download_failed', $summary['thumbnail_error'], 'il codice viene dall\'option persistita' );
	}

	/**
	 * `thumbnail_error` di /health vale null quando manca un codice (error vuoto o senza `code`).
	 */
	public function test_health_thumbnail_error_requires_a_code() {
		$this->enrolled();
		$_SERVER['SERVER_ADDR'] = '8.8.8.8';
		set_transient( 'wphc_thumb_retry_lock', time(), DAY_IN_SECONDS );
		update_option( 'wp_health_check_thumb_error', array( 'message' => 'senza codice' ), false );

		$this->assertNull( $this->get_route( '/health' )->get_data()['summary']['thumbnail_error'] );
	}

	/**
	 * /health genera la thumbnail alla prima chiamata se thum.io risponde: URL in summary, option scritte
	 * (stesso percorso di POST) e lock cancellato.
	 */
	public function test_health_generates_thumbnail_on_first_call() {
		$this->enrolled();
		$_SERVER['SERVER_ADDR'] = '8.8.8.8';
		$this->mock_thumbnail_service( 200, self::png_bytes() );

		$summary = $this->get_route( '/health' )->get_data()['summary'];

		$this->assertSame( self::THUMB_URL, $summary['thumbnail'] );
		$this->assertNull( $summary['thumbnail_error'] );
		$this->assertSame( self::THUMB_URL, get_option( 'wp_health_check_thumb' ) );
		$this->assertFalse( get_transient( 'wphc_thumb_retry_lock' ) );
	}
}
