<?php
/**
 * Test dell'anteprima del sito (lite 1.1.0): generazione da thum.io con il seam http_download iniettato,
 * rotte GET e POST /thumbnail, campi di /health e confronto di forma con i golden del plugin.
 *
 * @package WP_Health_Check_Lite
 */

final class ThumbnailTest extends LiteTestCase {

	/** URL pubblico dell'anteprima di default. */
	const THUMB_URL = self::SITE . '/wphc-lite/site-thumb.png';

	/** Enroll v1 (basta il Bearer) con il token di test. */
	private function enrolled(): void {
		$this->enroll_ok( 1, 'tok' );
	}

	/** POST /thumbnail autenticata. */
	private function post_thumbnail( ?string $token = 'tok' ): array {
		$headers = null !== $token ? array( 'Authorization' => 'Bearer ' . $token ) : array();

		return $this->request( 'POST', 'thumbnail', $headers );
	}

	/** BMP 1x1 valido: un tipo di immagine diverso dal PNG. */
	private static function bmp(): string {
		return 'BM' . pack( 'VvvV', 58, 0, 0, 54 ) . pack( 'VVVvvVVVVVV', 40, 1, 1, 1, 24, 0, 4, 2835, 2835, 0, 0 ) . "\x00\x00\xff\x00";
	}

	/** GIF 1x1 valido (animato o no, per il lite e' comunque da scartare). */
	private static function gif(): string {
		return (string) base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
	}

	/** Golden del plugin (corpo e stato) in tests/fixtures/golden/read/. */
	private function golden( string $name ): array {
		$path = dirname( __DIR__, 2 ) . '/tests/fixtures/golden/read/' . $name . '.json';

		return json_decode( (string) file_get_contents( $path ), true );
	}

	// -----------------------------------------------------------------------
	// GENERAZIONE
	// -----------------------------------------------------------------------

	public function test_post_thumbnail_success_writes_a_public_png(): void {
		$this->enrolled();
		$response = $this->post_thumbnail();

		$this->assertSame( 200, $response[0] );
		$this->assertSame( array( 'thumbnail' => self::THUMB_URL, 'error' => null ), $response[2] );

		// Un solo download, dal servizio con l'URL del sito, con il timeout lungo del percorso POST.
		$this->assertSame( array( array( 'https://image.thum.io/get/noanimate/png/width/400/' . self::SITE, 25 ) ), $this->download_calls );

		// File scritto in modo atomico: contenuto uguale, nessun .tmp, leggibile da Apache (0644, non 0600).
		$file = $this->thumb_dir . '/site-thumb.png';
		$this->assertSame( self::png(), file_get_contents( $file ) );
		$this->assertFileDoesNotExist( $file . '.tmp' );
		$this->assertSame( '0644', substr( sprintf( '%o', fileperms( $file ) ), -4 ) );

		// Lo stato sta nella cartella dati (0600): url, file e data della generazione.
		$state = $this->read_state_file( 'thumb' );
		$this->assertSame( self::THUMB_URL, $state['url'] );
		$this->assertSame( 'site-thumb.png', $state['file'] );
		$this->assertSame( $this->now, $state['at'] );
		$this->assertArrayNotHasKey( 'error', $state );
		$this->assertArrayNotHasKey( 'lock_until', $state );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->data_dir . '/thumb.json' ) ), -4 ) );
	}

	public function test_animated_gif_is_rejected(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 200, 'body' => self::gif(), 'error' => null );
		$response              = $this->post_thumbnail();

		$this->assertSame( 200, $response[0] );
		$this->assertNull( $response[2]['thumbnail'] );
		$this->assertSame( 'wphc_thumb_animated_gif', $response[2]['error']['code'] );
		$this->assertFileDoesNotExist( $this->thumb_dir . '/site-thumb.png' );
		$this->assertFileDoesNotExist( $this->thumb_dir . '/site-thumb.gif' );
	}

	public function test_not_an_image_is_rejected(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 200, 'body' => '<html>Rate limit</html>', 'error' => null );
		$response              = $this->post_thumbnail();

		$this->assertNull( $response[2]['thumbnail'] );
		$this->assertSame( 'wphc_thumb_not_an_image', $response[2]['error']['code'] );
		$this->assertSame( array( '.', '..' ), scandir( $this->thumb_dir ) );
	}

	public function test_network_error_and_http_500_are_download_failures(): void {
		$this->enrolled();

		$this->download_answer = array( 'status' => null, 'body' => null, 'error' => 'Operation timed out' );
		$network               = $this->post_thumbnail();
		$this->assertSame( 'wphc_thumb_download_failed', $network[2]['error']['code'] );
		$this->assertSame( 'Operation timed out', $network[2]['error']['message'] );

		$this->download_answer = array( 'status' => 500, 'body' => 'errore', 'error' => null );
		$http                  = $this->post_thumbnail();
		$this->assertSame( 'wphc_thumb_download_failed', $http[2]['error']['code'] );
		$this->assertSame( 'HTTP 500', $http[2]['error']['message'] );
		$this->assertNull( $http[2]['thumbnail'] );
	}

	public function test_error_is_stored_as_at_code_message_provider(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );
		$this->post_thumbnail();

		$error = $this->read_state_file( 'thumb' )['error'];
		$this->assertSame( array( 'at', 'code', 'message', 'provider' ), array_keys( $error ) );
		$this->assertSame( gmdate( 'c', $this->now ), $error['at'] );
		$this->assertSame( 'https://image.thum.io/get/noanimate/png/width/400/', $error['provider'] );
	}

	public function test_unwritable_directory_is_reported_without_downloading(): void {
		// Cartella inesistente: vale anche per root nel container, dove is_writable() di una 0555 darebbe true.
		$this->lite = $this->make_lite( array( 'thumb_dir' => $this->thumb_dir . '/non-esiste' ) );
		$this->enrolled();
		$response = $this->post_thumbnail();

		$this->assertNull( $response[2]['thumbnail'] );
		$this->assertSame( 'wphc_thumb_not_writable', $response[2]['error']['code'] );
		$this->assertSame( array(), $this->download_calls, 'il preflight evita il download' );
	}

	public function test_non_png_image_gets_its_real_extension_and_a_coherent_url(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 200, 'body' => self::bmp(), 'error' => null );
		$response              = $this->post_thumbnail();

		$this->assertSame( self::SITE . '/wphc-lite/site-thumb.bmp', $response[2]['thumbnail'] );
		$this->assertFileExists( $this->thumb_dir . '/site-thumb.bmp' );
		$this->assertFileDoesNotExist( $this->thumb_dir . '/site-thumb.png' );
		$this->assertSame( 'site-thumb.bmp', $this->read_state_file( 'thumb' )['file'] );
	}

	public function test_custom_thumb_url_drives_the_file_name(): void {
		$this->lite = $this->make_lite( array( 'thumb_url' => 'https://cdn.example/anteprime/home.png' ) );
		$this->enrolled();
		$response = $this->post_thumbnail();

		$this->assertSame( 'https://cdn.example/anteprime/home.png', $response[2]['thumbnail'] );
		$this->assertFileExists( $this->thumb_dir . '/home.png' );
	}

	// -----------------------------------------------------------------------
	// BLOCCO DI RITENTATIVO E FORCE
	// -----------------------------------------------------------------------

	public function test_lazy_failure_locks_retries_for_one_day(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );

		$first = $this->get( 'health', 'tok', false, array( 'fresh' => '1' ) );
		$this->assertCount( 1, $this->download_calls );
		$this->assertSame( 10, $this->download_calls[0][1], 'timeout breve nel percorso lazy' );
		$this->assertNull( $first[2]['summary']['thumbnail'] );
		$this->assertSame( 'wphc_thumb_download_failed', $first[2]['summary']['thumbnail_error'] );

		// Seconda chiamata senza force: il blocco impedisce il ritentativo, l'errore resta visibile.
		$second = $this->get( 'health', 'tok', false, array( 'fresh' => '1' ) );
		$this->assertCount( 1, $this->download_calls );
		$this->assertSame( 'wphc_thumb_download_failed', $second[2]['summary']['thumbnail_error'] );

		// Dopo un giorno si riprova.
		$this->now += 86401;
		$this->get( 'health', 'tok', false, array( 'fresh' => '1' ) );
		$this->assertCount( 2, $this->download_calls );
	}

	public function test_lock_is_set_before_the_attempt(): void {
		$this->enrolled();
		// Se il seam lancia, il blocco deve essere gia' scritto: lo si legge dal file di stato durante il download.
		$seen                  = null;
		$this->lite            = $this->make_lite(
			array(
				'http_download' => function () use ( &$seen ) {
					$seen = $this->read_state_file( 'thumb' )['lock_until'] ?? null;

					return array( 'status' => 500, 'body' => null, 'error' => null );
				},
			)
		);
		$this->post_thumbnail();

		$this->assertSame( $this->now + 86400, $seen );
	}

	public function test_force_ignores_the_lock_and_success_clears_error_and_lock(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );
		$this->get( 'health', 'tok', false, array( 'fresh' => '1' ) );
		$this->assertArrayHasKey( 'lock_until', $this->read_state_file( 'thumb' ) );

		// POST ignora il blocco di un giorno.
		$this->download_answer = null;
		$response              = $this->post_thumbnail();
		$this->assertCount( 2, $this->download_calls );
		$this->assertSame( self::THUMB_URL, $response[2]['thumbnail'] );
		$this->assertNull( $response[2]['error'] );

		$state = $this->read_state_file( 'thumb' );
		$this->assertArrayNotHasKey( 'error', $state );
		$this->assertArrayNotHasKey( 'lock_until', $state );
	}

	public function test_force_failure_drops_the_previous_image(): void {
		$this->enrolled();
		$this->post_thumbnail();
		$this->assertFileExists( $this->thumb_dir . '/site-thumb.png' );

		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );
		$response              = $this->post_thumbnail();

		$this->assertNull( $response[2]['thumbnail'] );
		$this->assertSame( 'wphc_thumb_download_failed', $response[2]['error']['code'] );
		$this->assertFileDoesNotExist( $this->thumb_dir . '/site-thumb.png' );

		$state = $this->read_state_file( 'thumb' );
		$this->assertArrayNotHasKey( 'url', $state );
		$this->assertArrayNotHasKey( 'file', $state );
	}

	public function test_existing_image_is_returned_without_downloading(): void {
		$this->enrolled();
		$this->post_thumbnail();
		$this->download_calls = array();

		$health = $this->get( 'health', 'tok', false, array( 'fresh' => '1' ) );

		$this->assertSame( self::THUMB_URL, $health[2]['summary']['thumbnail'] );
		$this->assertNull( $health[2]['summary']['thumbnail_error'] );
		$this->assertSame( array(), $this->download_calls );
	}

	public function test_missing_file_is_treated_as_absent_and_regenerated(): void {
		$this->enrolled();
		$this->post_thumbnail();
		unlink( $this->thumb_dir . '/site-thumb.png' );

		// GET non genera: l'immagine non c'e' piu'.
		$this->assertNull( $this->get( 'thumbnail', 'tok' )[2]['thumbnail'] );

		// /health la rigenera in modo lazy.
		$this->download_calls = array();
		$health               = $this->get( 'health', 'tok', false, array( 'fresh' => '1' ) );
		$this->assertSame( self::THUMB_URL, $health[2]['summary']['thumbnail'] );
		$this->assertCount( 1, $this->download_calls );
	}

	// -----------------------------------------------------------------------
	// ROTTE GET e POST /thumbnail
	// -----------------------------------------------------------------------

	public function test_thumbnail_routes_require_the_token(): void {
		// Prima dell'enroll: 503 come le altre rotte dati.
		$this->assertSame( 503, $this->get( 'thumbnail', 'tok' )[0] );
		$this->assertSame( 503, $this->post_thumbnail()[0] );

		$this->enrolled();
		foreach ( array( null, 'sbagliato' ) as $token ) {
			$get = $this->get( 'thumbnail', $token );
			$this->assertSame( 401, $get[0] );
			$this->assertSame( 'wphc_unauthorized', $get[2]['code'] );

			$post = $this->post_thumbnail( $token );
			$this->assertSame( 401, $post[0] );
			$this->assertSame( 'wphc_unauthorized', $post[2]['code'] );
		}

		$this->assertSame( array(), $this->download_calls, 'nessun download senza token valido' );
		$this->assertFileDoesNotExist( $this->data_dir . '/thumb.json' );
	}

	public function test_thumbnail_routes_accept_signed_requests_of_protocol_2(): void {
		$this->enroll_ok( 2, 'tok2' );

		// GET firmata.
		$this->assertSame( 200, $this->get( 'thumbnail', 'tok2', true )[0] );

		// POST firmata (corpo vuoto, rotta e metodo nella stringa canonica).
		$timestamp = (string) $this->now;
		$nonce     = $this->nonce();
		$response  = $this->request(
			'POST',
			'thumbnail',
			array(
				'Authorization'    => 'Bearer tok2',
				'X-WPHC-Timestamp' => $timestamp,
				'X-WPHC-Nonce'     => $nonce,
				'X-WPHC-Signature' => WPHC_Lite::sign_request( 'tok2', 'POST', '/health-check/v1/thumbnail', '', $timestamp, $nonce ),
			)
		);
		$this->assertSame( 200, $response[0] );
		$this->assertSame( self::THUMB_URL, $response[2]['thumbnail'] );
	}

	public function test_other_methods_on_thumbnail_are_404(): void {
		$this->enrolled();
		$response = $this->request( 'DELETE', 'thumbnail', array( 'Authorization' => 'Bearer tok' ) );

		$this->assertSame( 404, $response[0] );
		$this->assertSame( 'rest_no_route', $response[2]['code'] );
	}

	public function test_get_thumbnail_status_empty_then_populated_without_downloading(): void {
		$this->enrolled();

		$empty = $this->get( 'thumbnail', 'tok' );
		$this->assertSame( 200, $empty[0] );
		$this->assertSame( array( 'thumbnail' => null, 'generated_at' => null, 'error' => null ), $empty[2] );
		$this->assertSame( array(), $this->download_calls, 'GET non genera' );

		$this->post_thumbnail();
		$this->now += 30;
		$populated = $this->get( 'thumbnail', 'tok' );
		$this->assertSame(
			array( 'thumbnail' => self::THUMB_URL, 'generated_at' => gmdate( 'c', $this->now - 30 ), 'error' => null ),
			$populated[2]
		);
	}

	public function test_get_thumbnail_status_reports_the_last_error(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );
		$this->post_thumbnail();

		$status = $this->get( 'thumbnail', 'tok' );
		$this->assertNull( $status[2]['thumbnail'] );
		$this->assertNull( $status[2]['generated_at'] );
		$this->assertSame( 'wphc_thumb_download_failed', $status[2]['error']['code'] );
		$this->assertSame( array( 'at', 'code', 'message', 'provider' ), array_keys( $status[2]['error'] ) );
	}

	public function test_thumbnail_routes_track_the_access(): void {
		$this->enrolled();
		$this->now += 7;
		$this->get( 'thumbnail', 'tok' );
		$this->assertSame( gmdate( 'c', $this->now ), $this->read_state_file( 'state' )['last_request_at'] );

		$this->now += 7;
		$this->post_thumbnail();
		$this->assertSame( gmdate( 'c', $this->now ), $this->read_state_file( 'state' )['last_request_at'] );
	}

	// -----------------------------------------------------------------------
	// /health
	// -----------------------------------------------------------------------

	public function test_health_generates_the_thumbnail_lazily(): void {
		$this->enrolled();
		$health = $this->get( 'health', 'tok' );

		$this->assertSame( self::THUMB_URL, $health[2]['summary']['thumbnail'] );
		$this->assertNull( $health[2]['summary']['thumbnail_error'] );
		$this->assertFileExists( $this->thumb_dir . '/site-thumb.png' );
	}

	public function test_health_cache_does_not_freeze_the_thumbnail(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );

		// Prima /health: anteprima fallita, il payload finisce nella cache di 60 s.
		$first = $this->get( 'health', 'tok' );
		$this->assertNull( $first[2]['summary']['thumbnail'] );
		$this->assertSame( 'wphc_thumb_download_failed', $first[2]['summary']['thumbnail_error'] );

		// Il pulsante "Rigenera" ha successo: la /health ancora in cache deve mostrare subito l'anteprima.
		$this->download_answer = null;
		$this->post_thumbnail();
		$this->now += 10;
		$cached = $this->get( 'health', 'tok' );
		$this->assertSame( $first[2]['generated_at'], $cached[2]['generated_at'], 'il resto del payload arriva dalla cache' );
		$this->assertSame( self::THUMB_URL, $cached[2]['summary']['thumbnail'] );
		$this->assertNull( $cached[2]['summary']['thumbnail_error'] );

		// E al contrario: se l'immagine sparisce, la cache non la mostra piu'.
		unlink( $this->thumb_dir . '/site-thumb.png' );
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );
		$this->now            += 10;
		$again                 = $this->get( 'health', 'tok' );
		$this->assertSame( $first[2]['generated_at'], $again[2]['generated_at'] );
		$this->assertNull( $again[2]['summary']['thumbnail'] );
	}

	public function test_health_thumbnail_error_is_only_the_code_and_only_without_image(): void {
		$this->enrolled();
		$this->download_answer = array( 'status' => 200, 'body' => self::gif(), 'error' => null );
		$health                = $this->get( 'health', 'tok' );

		$this->assertNull( $health[2]['summary']['thumbnail'] );
		$this->assertSame( 'wphc_thumb_animated_gif', $health[2]['summary']['thumbnail_error'] );

		// Con un'immagine presente un vecchio errore non compare in /health (ma resta su GET /thumbnail).
		$this->download_answer = null;
		$this->post_thumbnail();
		$this->assertNull( $this->get( 'health', 'tok', false, array( 'fresh' => '1' ) )[2]['summary']['thumbnail_error'] );
	}

	public function test_health_with_unwritable_directory_reports_the_error_code(): void {
		$this->lite = $this->make_lite( array( 'thumb_dir' => $this->thumb_dir . '/non-esiste' ) );
		$this->enrolled();
		$health = $this->get( 'health', 'tok' );

		$this->assertSame( 200, $health[0] );
		$this->assertNull( $health[2]['summary']['thumbnail'] );
		$this->assertSame( 'wphc_thumb_not_writable', $health[2]['summary']['thumbnail_error'] );
	}

	// -----------------------------------------------------------------------
	// FORMA CONTRO I GOLDEN DEL PLUGIN
	// -----------------------------------------------------------------------

	public function test_get_status_has_the_golden_shape(): void {
		$this->enrolled();

		$empty_golden = $this->golden( 'thumbnail-status-empty' );
		$empty        = $this->get( 'thumbnail', 'tok' );
		$this->assertSame( $empty_golden['status'], $empty[0] );
		$this->assertSame( $empty_golden['body'], $this->sorted( $empty[2] ) );

		// Popolato: stesse chiavi del golden (anche l'errore ha la forma del plugin).
		$populated_golden = $this->golden( 'thumbnail-status-populated' );
		$this->post_thumbnail();
		$populated = $this->get( 'thumbnail', 'tok' );
		$this->assertSame( array_keys( $populated_golden['body'] ), array_keys( $this->sorted( $populated[2] ) ) );
		$this->assertSame( array( 'at', 'code', 'message', 'provider' ), array_keys( $populated_golden['body']['error'] ) );
	}

	public function test_post_success_has_the_golden_shape(): void {
		$this->enrolled();
		$golden   = $this->golden( 'thumbnail-regenerate-success' );
		$response = $this->post_thumbnail();

		$this->assertSame( $golden['status'], $response[0] );
		$this->assertSame( array_keys( $golden['body'] ), array_keys( $this->sorted( $response[2] ) ) );
		$this->assertNull( $response[2]['error'] );
		$this->assertIsString( $response[2]['thumbnail'] );
	}

	/** @return array<string, array{0: string, 1: array, 2: string}> */
	public function failure_cases(): array {
		return array(
			'animated gif'  => array( 'thumbnail-failure-animated-gif', array( 'status' => 200, 'body' => self::gif(), 'error' => null ), 'wphc_thumb_animated_gif' ),
			'not an image'  => array( 'thumbnail-failure-not-an-image', array( 'status' => 200, 'body' => 'testo', 'error' => null ), 'wphc_thumb_not_an_image' ),
			'http error'    => array( 'thumbnail-failure-http-error', array( 'status' => 500, 'body' => null, 'error' => null ), 'wphc_thumb_download_failed' ),
			'network error' => array( 'thumbnail-failure-network-error', array( 'status' => null, 'body' => null, 'error' => 'timeout' ), 'wphc_thumb_download_failed' ),
		);
	}

	/**
	 * @dataProvider failure_cases
	 */
	public function test_post_failure_has_the_golden_shape( string $golden_name, array $answer, string $code ): void {
		$this->enrolled();
		$golden                = $this->golden( $golden_name );
		$this->download_answer = $answer;
		$response              = $this->post_thumbnail();

		$this->assertSame( $golden['status'], $response[0] );
		$this->assertSame( array( 'error', 'thumbnail' ), array_keys( $this->sorted( $response[2] ) ) );
		$this->assertNull( $response[2]['thumbnail'] );

		// Stesse chiavi, codice e provider del plugin; il messaggio coincide dove non dipende dal trasporto.
		$this->assertSame( array_keys( $golden['body']['error'] ), array_keys( $response[2]['error'] ) );
		$this->assertSame( $golden['body']['error']['code'], $response[2]['error']['code'] );
		$this->assertSame( $code, $response[2]['error']['code'] );
		$this->assertSame( $golden['body']['error']['provider'], $response[2]['error']['provider'] );
		if ( in_array( $code, array( 'wphc_thumb_animated_gif', 'wphc_thumb_not_an_image' ), true ) ) {
			$this->assertSame( $golden['body']['error']['message'], $response[2]['error']['message'] );
		}
	}

	public function test_health_summary_has_the_golden_thumbnail_fields(): void {
		$golden = $this->golden( 'health-first-call' );
		$this->enrolled();
		$this->download_answer = array( 'status' => 500, 'body' => null, 'error' => null );
		$summary               = $this->get( 'health', 'tok' )[2]['summary'];

		$this->assertArrayHasKey( 'thumbnail', $golden['body']['summary'] );
		$this->assertArrayHasKey( 'thumbnail_error', $golden['body']['summary'] );
		$this->assertSame( $golden['body']['summary']['thumbnail'], $summary['thumbnail'] );
		$this->assertSame( $golden['body']['summary']['thumbnail_error'], $summary['thumbnail_error'] );
	}

	/** Ordina le chiavi in modo ricorsivo (i golden sono salvati con le chiavi ordinate). */
	private function sorted( array $data ): array {
		ksort( $data );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->sorted( $value );
			}
		}

		return $data;
	}
}
