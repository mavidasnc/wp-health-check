<?php
/**
 * Test di caratterizzazione del self-update dell'agent (POST /update, plugin 1.38.1):
 * SOLO i rami che NON riscrivono il file del plugin.
 *
 * Il ramo positivo ('updated') non e' coperto qui: la pipeline scrive su __FILE__ (il plugin
 * vero) e sara' oggetto di un test e2e su una copia. Tutti i pacchetti di prova hanno sempre
 * un difetto (vedi WPHC_Update_Test_Case::flawed_release()); una guardia nella classe base
 * verifica a fine test che il plugin non sia stato toccato.
 *
 * Ordine REALE della pipeline (da preservare):
 *   pull: release (hub -> API GitHub -> ripiego senza API) -> versione (solo se piu' nuova)
 *         -> probe di scrittura -> download -> [install] integrita' -> firma k2 -> backup...
 *   push: forma del pacchetto -> [install] versione (mai downgrade) -> probe -> integrita' -> firma.
 *
 * Limite di osservabilita': WP_HEALTH_CHECK_RELEASE_URL e' definita vuota nella config dei
 * test e non ha filtri, quindi la fonte hub (prefisso degli asset, ripiego su GitHub se gli
 * asset dell'hub non rispondono) non e' raggiungibile in-process.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-update.php';

/**
 * POST /update: pull, push, fonti della release, cache della versione.
 */
class WPHC_Test_Upd_SelfUpdate extends WPHC_Update_Test_Case {

	/** Tag sempre piu' nuovo della versione corrente (1.38.1). */
	const NEWER_TAG = 'v99.0.0';

	/**
	 * Risposta di POST /update (senza body = pull).
	 *
	 * @return WP_REST_Response
	 */
	private function post_update() {
		return $this->upd( 'POST', '/update' );
	}

	/**
	 * Risposta di POST /update con un pacchetto push.
	 *
	 * @param mixed $package Valore di "package".
	 * @return WP_REST_Response
	 */
	private function post_push( $package ) {
		return $this->upd( 'POST', '/update', array( 'package' => $package ) );
	}

	/**
	 * Messaggio di errore REST.
	 *
	 * @param WP_REST_Response $response Risposta.
	 * @return string
	 */
	private function error_message( WP_REST_Response $response ) {
		$data = $response->get_data();

		return (string) $data['message'];
	}

	// -----------------------------------------------------------------------
	// Pull: esiti sulla versione e sul probe di scrittura
	// -----------------------------------------------------------------------

	/**
	 * Release uguale alla corrente: up_to_date, nessun download degli asset.
	 */
	public function test_pull_up_to_date_same_version() {
		$this->mock_github_release( 'v' . WP_HEALTH_CHECK_VERSION, 'sha' );
		$before   = $this->plugin_options();
		$response = $this->post_update();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'up_to_date',
				'current' => WP_HEALTH_CHECK_VERSION,
				'latest'  => WP_HEALTH_CHECK_VERSION,
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->http_urls_containing( 'releases/download' ), 'Nessun asset scaricato.' );
		$this->assert_update_golden( 'update/self-update/pull-up-to-date', $response, $before );
	}

	/**
	 * Release piu' vecchia: up_to_date e "latest" e' il tag senza "v" (nel pull il downgrade non si tenta).
	 */
	public function test_pull_older_release_is_up_to_date() {
		$this->mock_github_release( 'v1.0.0', 'sha' );
		$response = $this->post_update();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['updated'] );
		$this->assertSame( 'up_to_date', $data['reason'] );
		$this->assertSame( '1.0.0', $data['latest'] );
		$this->assertSame( array(), $this->http_urls_containing( 'releases/download' ) );
	}

	/**
	 * Probe di scrittura fallito: not_writable, e il probe precede il download degli asset.
	 */
	public function test_pull_not_writable_before_any_download() {
		$this->mock_github_release( self::NEWER_TAG, 'sha' );
		$blocker = $this->block_mu_dir_probe();
		try {
			$before   = $this->plugin_options();
			$response = $this->post_update();
		} finally {
			$this->unblock_mu_dir_probe( $blocker );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'not_writable',
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->http_urls_containing( 'releases/download' ), 'Il probe viene PRIMA del download.' );
		$this->assert_update_golden( 'update/self-update/pull-not-writable', $response, $before );
	}

	// -----------------------------------------------------------------------
	// Pull: integrita' e firma (ogni difetto ferma l'aggiornamento prima di scrivere)
	// -----------------------------------------------------------------------

	/**
	 * Difetti del pacchetto.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_flaws() {
		return array(
			'sha256 errato'                   => array( 'sha' ),
			'header Version non coincidente'  => array( 'version_header' ),
			'senza prefisso php'              => array( 'no_php' ),
			'firma assente'                   => array( 'sig_missing' ),
			'firma di un altro messaggio'     => array( 'sig_bad' ),
			'firma con la chiave k1'          => array( 'sig_k1' ),
			'kid k1 dichiarato per la release' => array( 'sig_kid_k1' ),
		);
	}

	/**
	 * Ogni difetto: 200 {updated:false, reason:integrity_check_failed}.
	 *
	 * @dataProvider provide_flaws
	 *
	 * @param string $flaw Difetto.
	 */
	public function test_pull_integrity_check_failed( $flaw ) {
		$this->mock_github_release( self::NEWER_TAG, $flaw );
		$response = $this->post_update();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'integrity_check_failed',
			),
			$response->get_data()
		);
		// Gli asset sono stati scaricati (il rifiuto e' in install, dopo il download).
		$this->assertCount( 3, $this->http_urls_containing( 'releases/download' ) );
	}

	/**
	 * Golden di un rifiuto per sha256 errato.
	 */
	public function test_pull_integrity_golden() {
		$this->mock_github_release( self::NEWER_TAG, 'sha' );
		$before   = $this->plugin_options();
		$response = $this->post_update();

		$this->assert_update_golden( 'update/self-update/pull-integrity-sha', $response, $before );
	}

	/**
	 * Il file .sha256 puo' contenere il solo hash (senza nome file): stessa verifica, stesso esito.
	 */
	public function test_pull_sha256_asset_with_hash_only() {
		$parts = $this->flawed_release( self::NEWER_TAG, 'sig_bad' );
		$this->mock_github_release(
			self::NEWER_TAG,
			'sig_bad',
			array( self::github_asset_url( self::NEWER_TAG, 'wp-health-check.php.sha256' ) => $this->http_response( 200, strtoupper( $parts['sha256'] ) . "\n" ) )
		);
		$response = $this->post_update();

		// Hash maiuscolo nel file: viene portato a minuscolo, lo sha combacia, la firma (difettosa) no.
		$this->assertSame( 'integrity_check_failed', $response->get_data()['reason'] );
	}

	/**
	 * Release senza gli asset .sha256 e .sig: resta null e l'installazione li rifiuta come integrity_check_failed.
	 */
	public function test_pull_release_without_sha_and_sig_assets() {
		$this->mock_github_release( self::NEWER_TAG, 'sha', array(), array( 'wp-health-check.php.sha256', 'wp-health-check.php.sig' ) );
		$response = $this->post_update();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'integrity_check_failed', $response->get_data()['reason'] );
		$this->assertCount( 1, $this->http_urls_containing( 'releases/download' ), 'Solo il file del plugin.' );
	}

	/**
	 * Asset .sha256 e .sig che rispondono con errore: come mancanti, integrity_check_failed.
	 */
	public function test_pull_sha_and_sig_download_errors() {
		$this->mock_github_release(
			self::NEWER_TAG,
			'sha',
			array(
				self::github_asset_url( self::NEWER_TAG, 'wp-health-check.php.sha256' ) => $this->http_response( 404, 'nope' ),
				self::github_asset_url( self::NEWER_TAG, 'wp-health-check.php.sig' ) => new WP_Error( 'x', 'rete' ),
			)
		);

		$this->assertSame( 'integrity_check_failed', $this->post_update()->get_data()['reason'] );
	}

	/**
	 * Release senza l'asset del plugin: 502 wphc_update_asset_missing.
	 */
	public function test_pull_asset_missing() {
		$this->mock_github_release( self::NEWER_TAG, 'sha', array(), array( 'wp-health-check.php' ) );
		$before   = $this->plugin_options();
		$response = $this->post_update();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_asset_missing', $this->error_code( $response ) );
		$this->assertSame( 'Asset wp-health-check.php non trovato nella release.', $this->error_message( $response ) );
		$this->assert_update_golden( 'update/self-update/pull-asset-missing', $response, $before );
	}

	/**
	 * Download del file del plugin fallito (HTTP 500 oppure errore di rete): 502 wphc_update_download_failed.
	 */
	public function test_pull_download_failed() {
		$url = self::github_asset_url( self::NEWER_TAG, 'wp-health-check.php' );

		$this->mock_github_release( self::NEWER_TAG, 'sha', array( $url => $this->http_response( 500, 'boom' ) ) );
		$response = $this->post_update();
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_download_failed', $this->error_code( $response ) );
		$this->assertSame( 'Impossibile scaricare il nuovo file del plugin.', $this->error_message( $response ) );
	}

	/**
	 * Errore di rete sul download del file del plugin: stesso codice.
	 */
	public function test_pull_download_network_error() {
		$url = self::github_asset_url( self::NEWER_TAG, 'wp-health-check.php' );

		$this->mock_github_release( self::NEWER_TAG, 'sha', array( $url => new WP_Error( 'http_request_failed', 'timeout' ) ) );
		$response = $this->post_update();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_download_failed', $this->error_code( $response ) );
	}

	// -----------------------------------------------------------------------
	// Pull: errori nel recupero della release (forma e dettaglio del messaggio)
	// -----------------------------------------------------------------------

	/**
	 * API non raggiungibile e ripiego senza API bloccato: 502 wphc_update_network_error, il messaggio
	 * contiene il dettaglio dell'errore di trasporto tra parentesi.
	 */
	public function test_pull_network_error_with_detail() {
		$this->mock_http( array( self::github_api_url() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ) );
		$before   = $this->plugin_options();
		$response = $this->post_update();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_network_error', $this->error_code( $response ) );
		$this->assertSame( 'Impossibile contattare GitHub. (cURL error 28: Operation timed out)', $this->error_message( $response ) );
		// Ordine delle fonti: l'API, poi il ripiego su github.com (qui bloccato dai test).
		$this->assertSame( array( self::github_api_url(), self::github_latest_page_url() ), array_column( $this->http_calls, 'url' ) );
		$this->assert_update_golden( 'update/self-update/pull-network-error', $response, $before );
	}

	/**
	 * API con HTTP 403 (limite di richieste) e ripiego non disponibile: 502 wphc_update_github_error con "(HTTP 403)".
	 */
	public function test_pull_github_error_with_detail() {
		$this->mock_http( array( self::github_api_url() => $this->http_response( 403, '{"message":"rate limit"}' ) ) );
		$before   = $this->plugin_options();
		$response = $this->post_update();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_github_error', $this->error_code( $response ) );
		$this->assertSame( 'GitHub ha risposto con un errore nel recuperare la release. (HTTP 403)', $this->error_message( $response ) );
		$this->assert_update_golden( 'update/self-update/pull-github-error', $response, $before );
	}

	/**
	 * API con 200 ma corpo senza tag_name: 502 wphc_update_bad_release, messaggio senza dettaglio.
	 */
	public function test_pull_bad_release() {
		$this->mock_http( array( self::github_api_url() => $this->http_response( 200, '{"name":"senza tag"}' ) ) );
		$before   = $this->plugin_options();
		$response = $this->post_update();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_bad_release', $this->error_code( $response ) );
		$this->assertSame( 'Risposta di GitHub non valida.', $this->error_message( $response ) );
		$this->assert_update_golden( 'update/self-update/pull-bad-release', $response, $before );
	}

	/**
	 * Ripiego senza API: con l'API in errore, il redirect di /releases/latest indica il tag.
	 */
	public function test_pull_fallback_without_api_up_to_date() {
		$this->mock_http(
			array(
				self::github_api_url()         => $this->http_response( 403, '' ),
				self::github_latest_page_url() => $this->http_response(
					302,
					'',
					array( 'location' => 'https://github.com/' . WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO . '/releases/tag/v' . WP_HEALTH_CHECK_VERSION )
				),
			)
		);
		$response = $this->post_update();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'up_to_date', $response->get_data()['reason'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $response->get_data()['latest'] );
		// La richiesta a github.com non segue i redirect (serve l'header Location).
		$page_call = $this->http_calls[1];
		$this->assertSame( self::github_latest_page_url(), $page_call['url'] );
		$this->assertSame( 0, $page_call['args']['redirection'] );
	}

	/**
	 * Dati dei redirect non validi per il ripiego: restano l'errore dell'API.
	 *
	 * @return array<string,array{0:array}>
	 */
	public function provide_invalid_fallback_responses() {
		$prefix = 'https://github.com/' . WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO . '/releases/tag/';

		return array(
			'tag non e\' una versione'   => array( array( 302, $prefix . 'latest' ) ),
			'tag a due componenti'       => array( array( 302, $prefix . 'v1.2' ) ),
			'location di un altro repo'  => array( array( 302, 'https://github.com/altro/repo/releases/tag/v9.9.9' ) ),
			'location assente'           => array( array( 302, '' ) ),
			'risposta 200 e non redirect' => array( array( 200, $prefix . 'v9.9.9' ) ),
		);
	}

	/**
	 * Un ripiego non valido lascia l'errore originale dell'API (github_error con HTTP 403).
	 *
	 * @dataProvider provide_invalid_fallback_responses
	 *
	 * @param array $page Codice e Location della pagina github.com.
	 */
	public function test_pull_invalid_fallback_keeps_api_failure( array $page ) {
		$this->mock_http(
			array(
				self::github_api_url()         => $this->http_response( 403, '' ),
				self::github_latest_page_url() => $this->http_response( $page[0], '', array( 'location' => $page[1] ) ),
			)
		);
		$response = $this->post_update();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'wphc_update_github_error', $this->error_code( $response ) );
	}

	/**
	 * Il ripiego senza API produce la release con i tre asset in URL prevedibili.
	 */
	public function test_fetch_without_api_builds_asset_urls() {
		$this->mock_http(
			array(
				self::github_latest_page_url() => $this->http_response(
					302,
					'',
					array( 'location' => 'https://github.com/' . WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO . '/releases/tag/v1.40.0-rc.1' )
				),
			)
		);
		$release = wphc_fetch_latest_release_without_api( 15 );

		$this->assertSame( 'v1.40.0-rc.1', $release['tag_name'] );
		$this->assertSame(
			array( 'wp-health-check.php', 'wp-health-check.php.sha256', 'wp-health-check.php.sig' ),
			array_column( $release['assets'], 'name' )
		);
		$this->assertSame( self::github_asset_url( 'v1.40.0-rc.1', 'wp-health-check.php' ), $release['assets'][0]['browser_download_url'] );
		$this->assertArrayNotHasKey( 'wphc_source', $release, 'Solo la release dell\'hub porta wphc_source.' );
	}

	/**
	 * Con la fonte hub spenta (RELEASE_URL vuota) si va dritti all'API e nessuna richiesta parte verso l'hub.
	 */
	public function test_hub_source_disabled_in_test_environment() {
		$this->assertSame( '', WP_HEALTH_CHECK_RELEASE_URL );
		$this->assertNull( wphc_fetch_latest_release_from_hub( 15 ) );
		$this->assertSame( array(), $this->blocked_http_calls );

		$this->mock_http( array( self::github_api_url() => $this->http_response( 200, '{"tag_name":"v3.0.0"}' ) ) );
		$release = wphc_fetch_latest_release( 15 );
		$this->assertSame( 'v3.0.0', $release['tag_name'] );
		$this->assertArrayNotHasKey( 'wphc_source', $release );
		// Con $skip_hub il risultato non cambia (l'hub e' gia' spento).
		$this->assertSame( 'v3.0.0', wphc_fetch_latest_release( 15, true )['tag_name'] );
	}

	/**
	 * Tutte le richieste verso GitHub portano un User-Agent esplicito (1.37.0): senza GitHub risponde 403.
	 */
	public function test_every_github_request_has_explicit_user_agent() {
		$this->mock_github_release( self::NEWER_TAG, 'sha' );
		$this->post_update();

		$this->assertGreaterThanOrEqual( 4, count( $this->http_calls ) );
		foreach ( $this->http_calls as $call ) {
			$this->assertSame( 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION, $call['args']['headers']['User-Agent'], $call['url'] );
		}
		// L'API chiede il JSON di GitHub.
		$this->assertSame( 'application/vnd.github+json', $this->http_calls[0]['args']['headers']['Accept'] );
		$this->assertSame( 15, $this->http_calls[0]['args']['timeout'] );
	}

	/**
	 * Forma dei tag di release accettati (usata per push, hub e ripiego).
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public function provide_release_tags() {
		return array(
			'con v'                        => array( 'v1.38.0', true ),
			'senza v'                      => array( '1.38.0', true ),
			'pre-release'                  => array( 'v1.2.3-rc.1', true ),
			'build metadata'               => array( 'v1.2.3+build.5', true ),
			'quattro componenti'           => array( '1.2.3.4', true ),
			'due componenti'               => array( 'v1.2', false ),
			'parola'                       => array( 'latest', false ),
			'path traversal'               => array( '../1.2.3', false ),
			'spazio finale'                => array( 'v1.2.3 ', false ),
			// Comportamento preservato: "$" della regex accetta un "\n" finale.
			'newline finale (preservato)'  => array( "v1.2.3\n", true ),
			'non stringa'                  => array( 123, false ),
		);
	}

	/**
	 * Validazione del tag.
	 *
	 * @dataProvider provide_release_tags
	 *
	 * @param mixed $tag      Tag.
	 * @param bool  $expected Atteso.
	 */
	public function test_is_release_tag( $tag, $expected ) {
		$this->assertSame( $expected, wphc_is_release_tag( $tag ) );
	}

	// -----------------------------------------------------------------------
	// Push: pacchetto malformato (400 wphc_update_push_invalid)
	// -----------------------------------------------------------------------

	/**
	 * Pacchetto di base (difettoso per l'installazione, ma ben formato) da cui derivare i malformati.
	 *
	 * @return array<string,mixed>
	 */
	private function base_package() {
		return $this->flawed_push_package( self::NEWER_TAG, 'sha' );
	}

	/**
	 * Pacchetti malformati.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_malformed_packages() {
		return array(
			'package non e\' un array'    => array( 'scalar' ),
			'package null'                => array( 'null' ),
			'tag mancante'                => array( 'no_tag' ),
			'sha256 mancante'             => array( 'no_sha' ),
			'sig mancante'                => array( 'no_sig' ),
			'contents mancante'           => array( 'no_contents' ),
			'tag non e\' una versione'    => array( 'bad_tag' ),
			'sha256 troppo corto'         => array( 'short_sha' ),
			'sha256 non esadecimale'      => array( 'nonhex_sha' ),
			'sha256 non stringa'          => array( 'int_sha' ),
			'sig non e\' un array'        => array( 'sig_string' ),
			'contents non e\' una stringa' => array( 'contents_array' ),
			'base64 oltre 2 MB'           => array( 'b64_too_big' ),
			'base64 non valido'           => array( 'b64_invalid' ),
			'non e\' gzip'                => array( 'not_gzip' ),
			'gzip di contenuto vuoto'     => array( 'gz_empty' ),
			'gz oltre 4 MB'               => array( 'gz_too_big' ),
		);
	}

	/**
	 * Costruisce un pacchetto malformato.
	 *
	 * @param string $kind Tipo.
	 * @return mixed Valore di "package".
	 */
	private function malformed_package( $kind ) {
		$package = $this->base_package();
		switch ( $kind ) {
			case 'scalar':
				return 'stringa';
			case 'null':
				return null;
			case 'no_tag':
			case 'no_sha':
			case 'no_sig':
			case 'no_contents':
				$map = array(
					'no_tag'      => 'tag',
					'no_sha'      => 'sha256',
					'no_sig'      => 'sig',
					'no_contents' => 'contents_gz_b64',
				);
				unset( $package[ $map[ $kind ] ] );
				return $package;
			case 'bad_tag':
				$package['tag'] = 'latest';
				return $package;
			case 'short_sha':
				$package['sha256'] = str_repeat( 'a', 63 );
				return $package;
			case 'nonhex_sha':
				$package['sha256'] = str_repeat( 'z', 64 );
				return $package;
			case 'int_sha':
				$package['sha256'] = 12345;
				return $package;
			case 'sig_string':
				$package['sig'] = 'firma';
				return $package;
			case 'contents_array':
				$package['contents_gz_b64'] = array( 'x' );
				return $package;
			case 'b64_too_big':
				// Un carattere oltre il limite di 2 MB sulla stringa base64.
				$package['contents_gz_b64'] = str_repeat( 'A', 2 * MB_IN_BYTES + 1 );
				return $package;
			case 'b64_invalid':
				$package['contents_gz_b64'] = '!!! non base64 !!!';
				return $package;
			case 'not_gzip':
				$package['contents_gz_b64'] = base64_encode( 'testo non compresso' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return $package;
			case 'gz_empty':
				$package['contents_gz_b64'] = base64_encode( gzencode( '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return $package;
			case 'gz_too_big':
				// 8 MB di zeri: comprime a pochi KB (sotto il limite base64) ma supera di molto i 4 MB decompressi.
				// Comportamento preservato: il limite di gzdecode() e' approssimato (granularita' del buffer
				// zlib): 4 MB + 1 byte passerebbe ancora; molto oltre i 4 MB la decodifica fallisce.
				$package['contents_gz_b64'] = base64_encode( gzencode( str_repeat( "\0", 8 * MB_IN_BYTES ), 9 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return $package;
		}
		throw new InvalidArgumentException( $kind );
	}

	/**
	 * Ogni pacchetto malformato: 400 wphc_update_push_invalid, nessuna richiesta HTTP.
	 *
	 * @dataProvider provide_malformed_packages
	 *
	 * @param string $kind Tipo di malformazione.
	 */
	public function test_push_malformed_package( $kind ) {
		$response = $this->post_push( $this->malformed_package( $kind ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wphc_update_push_invalid', $this->error_code( $response ) );
		$this->assertSame( 'Pacchetto di aggiornamento non valido.', $this->error_message( $response ) );
		$this->assertSame( array(), $this->blocked_http_calls, 'Il push non scarica nulla.' );
	}

	/**
	 * Golden di un pacchetto malformato.
	 */
	public function test_push_malformed_golden() {
		$before   = $this->plugin_options();
		$response = $this->post_push( $this->malformed_package( 'bad_tag' ) );

		$this->assert_update_golden( 'update/self-update/push-invalid', $response, $before );
	}

	/**
	 * Il limite sulla stringa base64 e' di 2 MB esatti: un pacchetto lungo esattamente 2 MB supera
	 * il controllo di dimensione (e cade poi sulla decodifica, sempre 400).
	 */
	public function test_push_base64_limit_is_inclusive() {
		$package                    = $this->base_package();
		$package['contents_gz_b64'] = str_repeat( 'A', 2 * MB_IN_BYTES );
		$response                   = $this->post_push( $package );

		// "AAAA..." e' base64 valido ma non e' gzip: 400 per la decodifica, non per la dimensione.
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wphc_update_push_invalid', $this->error_code( $response ) );
	}

	// -----------------------------------------------------------------------
	// Push: versione, probe e integrita'
	// -----------------------------------------------------------------------

	/**
	 * Push di una release non piu' nuova: up_to_date con "mode":"push", anche se il pacchetto e' difettoso
	 * (la versione si confronta prima di ogni altra verifica).
	 */
	public function test_push_same_version_is_up_to_date() {
		$before   = $this->plugin_options();
		$response = $this->post_push( $this->flawed_push_package( 'v' . WP_HEALTH_CHECK_VERSION, 'sha' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'up_to_date',
				'current' => WP_HEALTH_CHECK_VERSION,
				'latest'  => WP_HEALTH_CHECK_VERSION,
				'mode'    => 'push',
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/self-update/push-up-to-date', $response, $before );
	}

	/**
	 * Il downgrade e' sempre vietato, anche con force.
	 */
	public function test_push_downgrade_is_always_refused() {
		$package          = $this->flawed_push_package( 'v1.0.0', 'sha' );
		$package['force'] = true;
		$response         = $this->post_push( $package );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'up_to_date', $data['reason'] );
		$this->assertSame( '1.0.0', $data['latest'] );
		$this->assertSame( 'push', $data['mode'] );
	}

	/**
	 * "force" con la stessa versione abilita la reinstallazione (allow_same): la pipeline va avanti e si
	 * ferma all'integrita' (il pacchetto ha lo sha256 errato), invece di rispondere up_to_date.
	 */
	public function test_push_force_same_version_reaches_integrity_check() {
		$package          = $this->flawed_push_package( 'v' . WP_HEALTH_CHECK_VERSION, 'sha' );
		$package['force'] = true;
		$before           = $this->plugin_options();
		$response         = $this->post_push( $package );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'integrity_check_failed',
				'mode'    => 'push',
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/self-update/push-force-same-version', $response, $before );
	}

	/**
	 * Comportamento preservato: "force" e' letto con empty(), quindi la stringa "0" e' falsa ma
	 * qualunque altra stringa non vuota (anche "false") e' vera.
	 */
	public function test_push_force_uses_php_truthiness() {
		$tag = 'v' . WP_HEALTH_CHECK_VERSION;

		$package          = $this->flawed_push_package( $tag, 'sha' );
		$package['force'] = '0';
		$this->assertSame( 'up_to_date', $this->post_push( $package )->get_data()['reason'] );

		$package['force'] = 'false';
		$this->assertSame( 'integrity_check_failed', $this->post_push( $package )->get_data()['reason'] );
	}

	/**
	 * Push di una versione nuova con la cartella non scrivibile: not_writable, con "mode":"push".
	 */
	public function test_push_not_writable() {
		$blocker = $this->block_mu_dir_probe();
		try {
			$before   = $this->plugin_options();
			$response = $this->post_push( $this->flawed_push_package( self::NEWER_TAG, 'sha' ) );
		} finally {
			$this->unblock_mu_dir_probe( $blocker );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'not_writable',
				'mode'    => 'push',
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/self-update/push-not-writable', $response, $before );
	}

	/**
	 * Ogni difetto del pacchetto push ferma l'installazione prima di scrivere.
	 *
	 * @dataProvider provide_flaws
	 *
	 * @param string $flaw Difetto.
	 */
	public function test_push_integrity_check_failed( $flaw ) {
		$before   = $this->plugin_options();
		$response = $this->post_push( $this->flawed_push_package( self::NEWER_TAG, $flaw ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'reason'  => 'integrity_check_failed',
				'mode'    => 'push',
			),
			$response->get_data()
		);
		if ( 'sig_bad' === $flaw ) {
			$this->assert_update_golden( 'update/self-update/push-integrity-sig-bad', $response, $before );
		}
	}

	/**
	 * Il tag del pacchetto push puo' non avere la "v": il confronto usa il tag normalizzato.
	 */
	public function test_push_tag_without_v_prefix() {
		$package = $this->flawed_push_package( '99.0.0', 'sha' );
		$data    = $this->post_push( $package )->get_data();

		$this->assertSame( 'integrity_check_failed', $data['reason'] );
	}

	/**
	 * Un body senza la chiave "package" e' un pull (nessun "mode" nella risposta).
	 */
	public function test_body_without_package_key_is_pull() {
		$this->mock_http( array( self::github_api_url() => $this->http_response( 200, '{"tag_name":"v' . WP_HEALTH_CHECK_VERSION . '"}' ) ) );
		$response = $this->upd( 'POST', '/update', array( 'altro' => 1 ) );

		$this->assertArrayNotHasKey( 'mode', $response->get_data() );
		$this->assertSame( 'up_to_date', $response->get_data()['reason'] );
	}

	/**
	 * La rotta registra l'accesso (anche sugli errori).
	 */
	public function test_update_records_access() {
		$this->mock_http( array( self::github_api_url() => $this->http_response( 500, '' ) ) );
		$this->post_update();

		$this->assertNotEmpty( get_option( 'wp_health_check_last_request_at' ) );
		$this->assertSame( self::CLIENT_IP, get_option( 'wp_health_check_last_request_ip' ) );
	}

	// -----------------------------------------------------------------------
	// wphc_get_latest_version: cache di 1 ora
	// -----------------------------------------------------------------------

	/**
	 * Prima chiamata: interroga GitHub, salva il tag senza "v" in un transient di 1 ora; la seconda
	 * chiamata non fa richieste; con $force si reinterroga.
	 */
	public function test_latest_version_is_cached_for_one_hour() {
		$this->mock_http( array( self::github_api_url() => $this->http_response( 200, '{"tag_name":"v2.5.0"}' ) ) );

		$this->assertSame( '2.5.0', wphc_get_latest_version() );
		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( 10, $this->http_calls[0]['args']['timeout'], 'La Site Health usa un timeout di 10 s (il self-update 15 s).' );
		$this->assertSame( '2.5.0', get_transient( 'wphc_latest_version_cache' ) );

		$timeout = (int) get_option( '_transient_timeout_wphc_latest_version_cache' );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $timeout, 5 );

		// Cache calda: nessuna nuova richiesta.
		$this->assertSame( '2.5.0', wphc_get_latest_version() );
		$this->assertCount( 1, $this->http_calls );

		// $force ignora la cache e reinterroga.
		$this->http_routes[ self::github_api_url() ] = $this->http_response( 200, '{"tag_name":"v2.6.0"}' );
		$this->assertSame( '2.6.0', wphc_get_latest_version( true ) );
		$this->assertCount( 2, $this->http_calls );
		$this->assertSame( '2.6.0', get_transient( 'wphc_latest_version_cache' ) );
	}

	/**
	 * Un fallimento e' messo in cache come stringa vuota per 1 ora (per non martellare GitHub): il
	 * risultato e' null e la chiamata successiva non fa richieste.
	 */
	public function test_latest_version_failure_is_cached_as_empty_string() {
		$this->mock_http( array( self::github_api_url() => $this->http_response( 500, '' ) ) );

		$this->assertNull( wphc_get_latest_version() );
		$this->assertSame( '', get_transient( 'wphc_latest_version_cache' ) );
		$calls = count( $this->http_calls );
		$this->assertSame( 2, $calls, 'API e ripiego senza API.' );

		$this->assertNull( wphc_get_latest_version() );
		$this->assertCount( $calls, $this->http_calls, 'Il fallimento in cache non ripete le richieste.' );

		// Una release valida dopo $force sostituisce il fallimento in cache.
		$this->http_routes[ self::github_api_url() ] = $this->http_response( 200, '{"tag_name":"v7.0.0"}' );
		$this->assertSame( '7.0.0', wphc_get_latest_version( true ) );
	}
}
