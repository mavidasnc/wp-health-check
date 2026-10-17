<?php
/**
 * Test di caratterizzazione di POST /update/plugin, /update/theme, /update/core
 * (aggiornamento di software di terze parti, plugin 1.38.1) e di wphc_map_item_update_outcome().
 *
 * Gli upgrader del core sono pilotati con filtri, mai con la rete:
 *  - upgrader_pre_download fa fallire il download (rolled_back, nessun file toccato) oppure
 *    restituisce uno zip locale del plugin "fixture" (WPHC Fixture, creato e rimosso dai test
 *    solo nel container, mai nel repo) per i rami che sostituiscono davvero dei file;
 *  - i transient update_plugins/update_themes/update_core sono costruiti dai test.
 *
 * Ordine REALE dei controlli del preambolo (da preservare):
 *   accesso -> kill-switch (403) -> versione WP >= 6.3 solo plugin/temi -> lock (409)
 *   -> filesystem 'direct' -> elemento (not_found/up_to_date/not_updatable) -> dry-run.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-update.php';

/**
 * Aggiornamento di plugin, temi e core via API.
 */
class WPHC_Test_Upd_ItemUpdate extends WPHC_Update_Test_Case {

	/** Package di un host ufficiale (ammesso anche con "solo wordpress.org"). */
	const OFFICIAL_PACKAGE = 'https://downloads.wordpress.org/plugin/example.9.9.9.zip';

	/**
	 * Plugin esistente nel container usato per i rami senza scrittura di file.
	 *
	 * @var string
	 */
	private $plugin = 'hello.php';

	/**
	 * Tema esistente nel container usato per i rami senza scrittura di file.
	 *
	 * @var string
	 */
	private $theme = 'twentytwentyfive';

	/**
	 * Verifica che plugin e tema "di prova" esistano nel container.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! isset( get_plugins()[ $this->plugin ] ) || ! wp_get_theme( $this->theme )->exists() ) {
			$this->markTestSkipped( 'Servono il plugin hello.php e il tema twentytwentyfive del container wp-env.' );
		}
	}

	/**
	 * Versione installata del plugin di prova.
	 *
	 * @return string
	 */
	private function plugin_version() {
		return get_plugins()[ $this->plugin ]['Version'];
	}

	/**
	 * Messaggio d'errore REST.
	 *
	 * @param WP_REST_Response $response Risposta.
	 * @return string
	 */
	private function error_message( WP_REST_Response $response ) {
		return (string) $response->get_data()['message'];
	}

	/**
	 * Nessun lock di update deve restare dopo una richiesta conclusa.
	 */
	private function assert_lock_released() {
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ), 'Il lock di update deve essere rilasciato.' );
	}

	// -----------------------------------------------------------------------
	// Validazione del payload
	// -----------------------------------------------------------------------

	/**
	 * Campo obbligatorio mancante: 400 e nessun lock.
	 */
	public function test_missing_plugin_and_theme_field() {
		$response = $this->upd( 'POST', '/update/plugin', array() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wphc_missing_plugin', $this->error_code( $response ) );
		$this->assertSame( 'Campo "plugin" obbligatorio.', $this->error_message( $response ) );

		// Solo spazi: trim() lo rende vuoto.
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => '   ' ) );
		$this->assertSame( 'wphc_missing_plugin', $this->error_code( $response ) );

		$response = $this->upd( 'POST', '/update/theme', array() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wphc_missing_theme', $this->error_code( $response ) );
		$this->assertSame( 'Campo "theme" obbligatorio.', $this->error_message( $response ) );

		$this->assert_lock_released();
		$this->assertSame( array(), $this->log_rows() );
	}

	/**
	 * Il campo puo' arrivare anche dalla query string (get_param legge body e query).
	 */
	public function test_plugin_field_is_read_from_query_string() {
		$response = $this->upd( 'POST', '/update/plugin', null, array( 'plugin' => 'inesistente/inesistente.php' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['result'] );
	}

	// -----------------------------------------------------------------------
	// Preambolo: kill-switch, versione WP, lock, filesystem
	// -----------------------------------------------------------------------

	/**
	 * Kill-switch spento: 403 wphc_updates_disabled su plugin, tema e core, anche in dry-run.
	 */
	public function test_kill_switch_disabled() {
		$this->disable_api_updates();
		$before = $this->plugin_options();

		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_updates_disabled', $this->error_code( $response ) );
		$this->assertSame( 'Aggiornamenti via API disattivati per questo sito.', $this->error_message( $response ) );
		$this->assert_update_golden( 'update/item/disabled', $response, $before );

		$this->assertSame( 403, $this->upd( 'POST', '/update/theme', array( 'theme' => $this->theme ) )->get_status() );
		$this->assertSame( 403, $this->upd( 'POST', '/update/core' )->get_status() );
		// Anche il dry-run passa dal kill-switch (il preambolo e' comune).
		$this->assertSame( 403, $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ), array( 'check' => '1' ) )->get_status() );
		$this->assert_lock_released();
	}

	/**
	 * Il kill-switch e' un'option: assente vale "acceso" (default true), qualunque valore falso lo spegne.
	 */
	public function test_kill_switch_default_is_enabled() {
		$this->assertFalse( get_option( 'wp_health_check_updates_enabled' ), 'L\'option non esiste di default.' );
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => 'x/x.php' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'not_found', $response->get_data()['result'] );
	}

	/**
	 * Limite di osservabilita': il ramo unsupported_wp_version (WordPress < 6.3, 200 con
	 * {updated:false, result:unsupported_wp_version}) non e' raggiungibile in-process, perche'
	 * get_bloginfo( 'version' ) legge wp_get_wp_version() (statica) e non passa da filtri. Resta
	 * coperta la mappatura dell'esito (test_map_item_update_outcome) e la classificazione del bulk.
	 * Qui si fissa solo che il core NON ha il requisito: senza aggiornamenti e' up_to_date.
	 */
	public function test_core_has_no_wp_version_requirement() {
		$this->assertTrue( version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) );
		$response = $this->upd( 'POST', '/update/core' );

		$this->assertSame( 'up_to_date', $response->get_data()['result'] );
		$this->assert_lock_released();
	}

	/**
	 * Lock gia' preso: 409 wphc_update_locked, e il lock altrui non viene toccato.
	 */
	public function test_locked_returns_409_and_keeps_foreign_lock() {
		set_transient( 'wp_health_check_update_lock', 1, 300 );
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wphc_update_locked', $this->error_code( $response ) );
		$this->assertSame( 'Un altro aggiornamento e\' gia\' in corso.', $this->error_message( $response ) );
		$this->assertSame( 1, (int) get_transient( 'wp_health_check_update_lock' ), 'Il lock di un altro update resta.' );
		$this->assert_update_golden( 'update/item/locked', $response, $before );

		$this->assertSame( 409, $this->upd( 'POST', '/update/theme', array( 'theme' => $this->theme ) )->get_status() );
		$this->assertSame( 409, $this->upd( 'POST', '/update/core' )->get_status() );
		// Il lock vale anche per il dry-run.
		$this->assertSame( 409, $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ), array( 'check' => '1' ) )->get_status() );
	}

	/**
	 * Ordine dei controlli: il kill-switch precede il lock (403, non 409).
	 */
	public function test_kill_switch_wins_over_lock() {
		$this->disable_api_updates();
		set_transient( 'wp_health_check_update_lock', 1, 300 );

		$this->assertSame( 403, $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) )->get_status() );
	}

	/**
	 * Filesystem diverso da 'direct': 200 fs_method_unavailable, e il lock preso dal preflight viene rilasciato.
	 */
	public function test_fs_method_unavailable_releases_lock() {
		$this->force_fs_method( 'ftpext' );
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'fs_method_unavailable',
			),
			$response->get_data()
		);
		$this->assert_lock_released();
		$this->assert_update_golden( 'update/item/fs-method-unavailable', $response, $before );
	}

	/**
	 * Il preambolo registra l'accesso e fa il prune opportunistico del log (una volta al giorno).
	 */
	public function test_preflight_records_access_and_prunes_log() {
		$this->upd( 'POST', '/update/plugin', array( 'plugin' => 'x/x.php' ) );

		$this->assertNotEmpty( get_option( 'wp_health_check_last_request_at' ) );
		$this->assertNotFalse( get_transient( 'wp_health_check_log_pruned_at' ), 'Il prune ha lasciato il gate giornaliero.' );
	}

	// -----------------------------------------------------------------------
	// Elemento: not_found, up_to_date, not_updatable
	// -----------------------------------------------------------------------

	/**
	 * Plugin e tema inesistenti: 200 {updated:false, result:not_found}, nessun log, lock rilasciato.
	 */
	public function test_not_found() {
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => 'nulla/nulla.php' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'not_found',
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/item/not-found', $response, $before );

		$response = $this->upd( 'POST', '/update/theme', array( 'theme' => 'tema-che-non-esiste' ) );
		$this->assertSame( 'not_found', $response->get_data()['result'] );

		$this->assertSame( array(), $this->log_rows(), 'Niente righe di log per un elemento inesistente.' );
		$this->assert_lock_released();
	}

	/**
	 * Nessun aggiornamento nel transient del core: up_to_date con la versione corrente.
	 */
	public function test_up_to_date_for_plugin_and_theme() {
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'up_to_date',
				'current' => $this->plugin_version(),
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/item/up-to-date', $response, $before );

		$response = $this->upd( 'POST', '/update/theme', array( 'theme' => $this->theme ) );
		$this->assertSame( 'up_to_date', $response->get_data()['result'] );
		$this->assertSame( (string) wp_get_theme( $this->theme )->get( 'Version' ), $response->get_data()['current'] );
		$this->assertSame( array(), $this->log_rows() );
		$this->assert_lock_released();
	}

	/**
	 * Pacchetto vuoto (aggiornamento a pagamento senza licenza): not_updatable con il dettaglio.
	 */
	public function test_not_updatable_when_package_is_empty() {
		$this->set_plugin_update( $this->plugin, '9.9.9', '' );
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'not_updatable',
				'detail'  => 'pacchetto non ospitato su wordpress.org',
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/item/not-updatable', $response, $before );
		$this->assertSame( array(), $this->log_rows() );
		$this->assert_lock_released();
	}

	/**
	 * Restrizione "solo wordpress.org": un host diverso e' not_updatable, gli host ufficiali no; senza
	 * la restrizione qualunque host e' ammesso. Il confronto dell'host e' esatto.
	 *
	 * @return array<string,array{0:string,1:bool,2:string}>
	 */
	public function provide_package_hosts() {
		return array(
			'host terzo, restrizione attiva'    => array( 'https://example.com/hello.zip', true, 'not_updatable' ),
			'host terzo, restrizione spenta'    => array( 'https://example.com/hello.zip', false, 'updatable' ),
			'downloads.wordpress.org'           => array( 'https://downloads.wordpress.org/plugin/hello.zip', true, 'updatable' ),
			'api.wordpress.org'                 => array( 'https://api.wordpress.org/x.zip', true, 'updatable' ),
			'sottodominio ingannevole'          => array( 'https://downloads.wordpress.org.evil.test/x.zip', true, 'not_updatable' ),
			'wordpress.org nudo non ammesso'    => array( 'https://wordpress.org/x.zip', true, 'not_updatable' ),
		);
	}

	/**
	 * Allowlist degli host del pacchetto.
	 *
	 * @dataProvider provide_package_hosts
	 *
	 * @param string $package    URL del pacchetto.
	 * @param bool   $restrict   Valore di wp_health_check_restrict_official_only.
	 * @param string $expected   Esito atteso (result).
	 */
	public function test_restrict_official_only( $package, $restrict, $expected ) {
		update_option( 'wp_health_check_restrict_official_only', $restrict );
		$this->set_plugin_update( $this->plugin, '9.9.9', $package );
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ), array( 'check' => '1' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected, $response->get_data()['result'] );
		$this->assertSame( array(), $this->log_rows(), 'Il dry-run non scrive nel log.' );
	}

	// -----------------------------------------------------------------------
	// Dry-run (?check=1)
	// -----------------------------------------------------------------------

	/**
	 * ?check=1: nessun update, nessuna riga di log, lock rilasciato; risposta "updatable" con i dettagli.
	 */
	public function test_check_returns_updatable_for_plugin() {
		$this->set_plugin_update( $this->plugin, '9.9.9', self::OFFICIAL_PACKAGE );
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ), array( 'check' => '1' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'updatable',
				'type'    => 'plugin',
				'target'  => $this->plugin,
				'name'    => get_plugins()[ $this->plugin ]['Name'],
				'current' => $this->plugin_version(),
				'latest'  => '9.9.9',
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/item/check-updatable', $response, $before );
		$this->assertSame( array(), $this->log_rows() );
		$this->assertFalse( get_option( 'wp_health_check_last_update' ), 'Il dry-run non tocca last_update.' );
		$this->assert_lock_released();
	}

	/**
	 * ?check=1 su un tema: le entry di update_themes sono array e il risultato e' lo stesso.
	 */
	public function test_check_returns_updatable_for_theme() {
		$this->set_theme_update( $this->theme, '99.0', self::OFFICIAL_PACKAGE );
		$theme    = wp_get_theme( $this->theme );
		$response = $this->upd( 'POST', '/update/theme', array( 'theme' => $this->theme ), array( 'check' => '1' ) );

		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'updatable',
				'type'    => 'theme',
				'target'  => $this->theme,
				'name'    => (string) $theme->get( 'Name' ),
				'current' => (string) $theme->get( 'Version' ),
				'latest'  => '99.0',
			),
			$response->get_data()
		);
	}

	/**
	 * Comportamento preservato: "check" e' letto con (bool) sulla stringa, quindi "0" e' falso mentre
	 * qualunque altra stringa non vuota (anche "false") e' vera.
	 */
	public function test_check_flag_uses_php_truthiness() {
		$this->set_plugin_update( $this->plugin, '9.9.9', self::OFFICIAL_PACKAGE );
		$this->fail_package_downloads();

		// "false" (stringa) e' un dry-run.
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ), array( 'check' => 'false' ) );
		$this->assertSame( 'updatable', $response->get_data()['result'] );
		$this->assertSame( array(), $this->log_rows() );

		// "0" esegue davvero il tentativo (qui fallisce il download: rolled_back).
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ), array( 'check' => '0' ) );
		$this->assertSame( 'rolled_back', $response->get_data()['result'] );
		$this->assertSame( array( 'requested', 'rolled_back' ), $this->log_phases() );
	}

	// -----------------------------------------------------------------------
	// Esecuzione con upgrader mockato: rolled_back / failed
	// -----------------------------------------------------------------------

	/**
	 * Download fallito: l'elemento resta alla versione originale, quindi 'rolled_back'. Due righe di log
	 * con lo stesso correlation_id (requested + rolled_back), last_update, cache invalidate, lock rilasciato.
	 */
	public function test_plugin_update_failure_is_rolled_back() {
		$this->set_plugin_update( $this->plugin, '9.9.9', self::OFFICIAL_PACKAGE );
		$this->fail_package_downloads( 'download mock fallito' );
		// Cache che l'update deve invalidare.
		set_transient( 'wphc_health_cache', 'x', 300 );
		set_transient( 'wphc_detail_plugins_cache', 'x', 300 );
		set_transient( 'wphc_detail_theme_cache', 'x', 300 );

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => $this->plugin ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['updated'] );
		$this->assertSame( 'rolled_back', $data['result'] );
		$this->assertIsInt( $data['log_id'] );
		$this->assertNotSame( '', (string) $data['detail'] );
		$this->assertSame( array( 'updated', 'result', 'detail', 'log_id' ), array_keys( $data ) );
		$this->assert_update_golden( 'update/item/plugin-rolled-back', $response, $before );

		// Righe di log: stesso correlation_id, versioni, origine "api", attivo registrato in 'requested'.
		$rows = $this->log_rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'requested', 'rolled_back' ), array_column( $rows, 'phase' ) );
		$this->assertSame( $rows[0]['correlation_id'], $rows[1]['correlation_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $rows[0]['correlation_id'] );
		foreach ( $rows as $row ) {
			$this->assertSame( 'plugin', $row['type'] );
			$this->assertSame( $this->plugin, $row['target'] );
			$this->assertSame( $this->plugin_version(), $row['version_from'] );
			$this->assertSame( '9.9.9', $row['version_to'] );
			$this->assertSame( 'api', $row['source'] );
			$this->assertNull( $row['actor'] );
			$this->assertSame( self::CLIENT_IP, $row['ip'] );
		}
		$this->assertNull( $rows[0]['message'] );
		$this->assertSame( $data['detail'], $rows[1]['message'] );
		$this->assertSame( (int) $rows[1]['id'], $data['log_id'] );
		$this->assertSame( '0', $rows[0]['active'], 'Hello Dolly e\' inattivo: active=0 nella riga requested.' );
		$this->assertNull( $rows[1]['active'], 'La riga finale di un fallimento non registra lo stato attivo.' );

		// Ultimo esito, cache invalidate, lock, transient di update lasciato com'e'.
		$last = get_option( 'wp_health_check_last_update' );
		$this->assertSame( 'plugin', $last['type'] );
		$this->assertSame( $this->plugin, $last['target'] );
		$this->assertSame( 'rolled_back', $last['phase'] );
		$this->assertSame( 'api', $last['source'] );
		$this->assertFalse( get_transient( 'wphc_health_cache' ) );
		$this->assertFalse( get_transient( 'wphc_detail_plugins_cache' ) );
		$this->assertFalse( get_transient( 'wphc_detail_theme_cache' ) );
		$this->assertArrayHasKey( $this->plugin, get_site_transient( 'update_plugins' )->response, 'Su rolled_back l\'entry di update resta.' );
		$this->assert_lock_released();
		$this->assertArrayNotHasKey( 'wphc_api_update_in_progress', $GLOBALS, 'Il flag anti doppio log viene tolto.' );
	}

	/**
	 * Stesso comportamento per un tema.
	 */
	public function test_theme_update_failure_is_rolled_back() {
		$this->set_theme_update( $this->theme, '99.0', self::OFFICIAL_PACKAGE );
		$this->fail_package_downloads();
		$response = $this->upd( 'POST', '/update/theme', array( 'theme' => $this->theme ) );

		$this->assertSame( 'rolled_back', $response->get_data()['result'] );
		$rows = $this->log_rows();
		$this->assertSame( array( 'requested', 'rolled_back' ), array_column( $rows, 'phase' ) );
		$this->assertSame( 'theme', $rows[0]['type'] );
		$this->assertNull( $rows[0]['active'], 'Per i temi active e\' sempre NULL.' );
		$this->assertSame( '99.0', $rows[0]['version_to'] );
		$this->assert_lock_released();
	}

	/**
	 * Plugin scomparso durante il tentativo (versione finale non rilevabile): fase 'failed', non 'rolled_back'.
	 */
	public function test_plugin_vanished_during_update_is_failed() {
		$this->install_fixture_plugin( '1.0.0' );
		$this->set_plugin_update( self::FIXTURE_FILE, '2.0.0', self::OFFICIAL_PACKAGE );
		add_filter(
			'upgrader_pre_download',
			static function () {
				// Simula un processo esterno che cancella il plugin mentre l'update e' in corso.
				$file = WP_PLUGIN_DIR . '/' . WPHC_Update_Test_Case::FIXTURE_FILE;
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( dirname( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged

				return new WP_Error( 'wphc_test_download', 'download mock fallito' );
			}
		);
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => self::FIXTURE_FILE ) );

		$this->assertSame( 'failed', $response->get_data()['result'] );
		$this->assertSame( array( 'requested', 'failed' ), $this->log_phases() );
		$this->assertSame( 'failed', get_option( 'wp_health_check_last_update' )['phase'] );
		$this->assert_lock_released();
	}

	// -----------------------------------------------------------------------
	// Esecuzione con sostituzione reale del plugin fixture: updated
	// -----------------------------------------------------------------------

	/**
	 * Update riuscito di un plugin inattivo: risposta, due righe di log (requested/completed), transient di
	 * update corretto chirurgicamente, file sostituito, nessun doppio log dall'hook upgrader_process_complete.
	 */
	public function test_plugin_updated_inactive() {
		$this->install_fixture_plugin( '1.0.0' );
		$this->set_plugin_update( self::FIXTURE_FILE, '2.0.0', self::OFFICIAL_PACKAGE );
		// Un altro plugin con update pendente nel transient: non deve essere toccato dal patch.
		$transient                           = get_site_transient( 'update_plugins' );
		$transient->response['altro/altro.php'] = (object) array( 'new_version' => '5.0' );
		set_site_transient( 'update_plugins', $transient );
		$this->mock_wporg_api();
		$this->serve_package_from( $this->build_fixture_zip( '2.0.0' ) );
		set_transient( 'wphc_health_cache', 'x', 300 );

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => self::FIXTURE_FILE ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( array( 'updated', 'type', 'target', 'name', 'from', 'to', 'log_id' ), array_keys( $data ) );
		$this->assertSame( 'plugin', $data['type'] );
		$this->assertSame( self::FIXTURE_FILE, $data['target'] );
		$this->assertSame( 'WPHC Fixture', $data['name'] );
		$this->assertSame( '1.0.0', $data['from'] );
		$this->assertSame( '2.0.0', $data['to'] );
		$this->assert_update_golden( 'update/item/plugin-updated', $response, $before );

		// File davvero sostituito.
		wp_clean_plugins_cache( false );
		$this->assertSame( '2.0.0', get_plugins()[ self::FIXTURE_FILE ]['Version'] );

		// Log: SOLO le due righe dell'API (l'hook upgrader_process_complete e' escluso dal flag).
		$rows = $this->log_rows();
		$this->assertSame( array( 'requested', 'completed' ), array_column( $rows, 'phase' ) );
		$this->assertSame( $rows[0]['correlation_id'], $rows[1]['correlation_id'] );
		$this->assertSame( 'api', $rows[1]['source'] );
		$this->assertSame( '0', $rows[1]['active'] );
		$this->assertNull( $rows[1]['message'] );
		$this->assertSame( (int) $rows[1]['id'], $data['log_id'] );

		// Transient: tolta SOLO l'entry aggiornata, 'checked' allineato, l'altra entry resta.
		$after = get_site_transient( 'update_plugins' );
		$this->assertArrayNotHasKey( self::FIXTURE_FILE, $after->response );
		$this->assertArrayHasKey( 'altro/altro.php', $after->response );
		$this->assertSame( '2.0.0', $after->checked[ self::FIXTURE_FILE ] );

		$this->assertFalse( get_transient( 'wphc_health_cache' ) );
		$this->assertSame( 'completed', get_option( 'wp_health_check_last_update' )['phase'] );
		$this->assert_lock_released();
	}

	/**
	 * Plugin attivo: Plugin_Upgrader lo disattiva durante l'update e la rete di sicurezza dell'agent lo riattiva
	 * (messaggio sulla riga 'completed'); lo stato attivo e' registrato in entrambe le righe.
	 */
	public function test_plugin_updated_active_stays_active() {
		$this->install_fixture_plugin( '1.0.0' );
		$this->assertNull( activate_plugin( self::FIXTURE_FILE ) );
		$this->set_plugin_update( self::FIXTURE_FILE, '2.0.0', self::OFFICIAL_PACKAGE );
		$this->mock_wporg_api();
		$this->serve_package_from( $this->build_fixture_zip( '2.0.0' ) );

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => self::FIXTURE_FILE ) );

		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertArrayNotHasKey( 'result', $data, 'Esito pieno: nessun result.' );
		$rows = $this->log_rows();
		$this->assertSame( array( 'requested', 'completed' ), array_column( $rows, 'phase' ) );
		$this->assertSame( '1', $rows[0]['active'] );
		$this->assertSame( '1', $rows[1]['active'] );
		$this->assertTrue( is_plugin_active( self::FIXTURE_FILE ) );
		// Plugin_Upgrader disattiva il plugin prima dell'update e la rete di sicurezza dell'agent lo riattiva.
		$this->assertSame( "Plugin disattivato dall'update e riattivato automaticamente.", $rows[1]['message'] );
		$this->assert_update_golden( 'update/item/plugin-updated-reactivated', $response, $before );
		$this->assert_lock_released();
	}

	/**
	 * Plugin che non si riattiva dopo l'update (la nuova versione produce output in attivazione): l'update
	 * e' riuscito (updated:true) ma l'esito e' 'reactivation_failed' con il dettaglio.
	 */
	public function test_plugin_updated_but_reactivation_failed() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_B_DIR );
		// Si marca il plugin come attivo scrivendo l'option, SENZA includerlo: activate_plugin() usa
		// include_once e un file gia' incluso in questo processo non produrrebbe piu' output.
		update_option( 'active_plugins', array( self::FIXTURE_B_FILE ) );
		$this->set_plugin_update( self::FIXTURE_B_FILE, '2.0.0', self::OFFICIAL_PACKAGE );
		$this->mock_wporg_api();
		// La nuova versione stampa dell'output quando il file viene incluso: activate_plugin() la rifiuta
		// (unexpected_output), quindi la riattivazione automatica dell'agent fallisce.
		$this->serve_package_from( $this->build_fixture_zip( '2.0.0', "echo 'output inatteso';", '', self::FIXTURE_B_DIR ) );

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/plugin', array( 'plugin' => self::FIXTURE_B_FILE ) );

		$data = $response->get_data();
		$this->assertTrue( $data['updated'] );
		$this->assertSame( 'reactivation_failed', $data['result'] );
		$this->assertStringStartsWith( 'Plugin aggiornato correttamente ma la riattivazione automatica e\' fallita: ', $data['detail'] );
		$this->assertSame(
			array( 'updated', 'result', 'type', 'target', 'name', 'from', 'to', 'log_id', 'detail' ),
			array_keys( $data )
		);
		$rows = $this->log_rows();
		$this->assertSame( array( 'requested', 'completed' ), array_column( $rows, 'phase' ) );
		$this->assertSame( $data['detail'], $rows[1]['message'] );
		// Comportamento sorprendente preservato: activate_plugin() scrive l'option active_plugins PRIMA di
		// accorgersi dell'output inatteso, quindi per WordPress il plugin risulta attivo, mentre la riga di
		// log (stato letto prima della riattivazione) dice active=0 e la risposta dice reactivation_failed.
		$this->assertTrue( is_plugin_active( self::FIXTURE_B_FILE ) );
		$this->assertSame( '0', $rows[1]['active'] );
		$this->assert_update_golden( 'update/item/plugin-reactivation-failed', $response, $before );
	}

	// -----------------------------------------------------------------------
	// Core
	// -----------------------------------------------------------------------

	/**
	 * Core senza aggiornamenti disponibili: up_to_date con la versione corrente.
	 */
	public function test_core_up_to_date() {
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/core' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'up_to_date',
				'current' => get_bloginfo( 'version' ),
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/item/core-up-to-date', $response, $before );
		$this->assert_lock_released();
	}

	/**
	 * Core: ?check=1 con un aggiornamento disponibile; la restrizione sugli host si applica al campo download.
	 */
	public function test_core_check_and_restrict() {
		$this->set_core_update( '99.0.0', 'https://downloads.wordpress.org/release/wordpress-99.0.0.zip' );
		$response = $this->upd( 'POST', '/update/core', null, array( 'check' => '1' ) );

		$this->assertSame(
			array(
				'updated' => false,
				'result'  => 'updatable',
				'type'    => 'core',
				'target'  => 'core',
				'name'    => 'WordPress',
				'current' => get_bloginfo( 'version' ),
				'latest'  => '99.0.0',
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->log_rows() );

		// Host non ufficiale con la restrizione: not_updatable.
		update_option( 'wp_health_check_restrict_official_only', true );
		$this->set_core_update( '99.0.0', 'https://example.com/wordpress.zip' );
		$response = $this->upd( 'POST', '/update/core', null, array( 'check' => '1' ) );
		$this->assertSame( 'not_updatable', $response->get_data()['result'] );
		$this->assertSame( 'pacchetto non ospitato su wordpress.org', $response->get_data()['detail'] );
		$this->assert_lock_released();
	}

	/**
	 * Core con download fallito: rolled_back (versione invariata), log con type core, option della versione
	 * del core allineata alla versione reale (evita il doppio log al prossimo init).
	 */
	public function test_core_update_failure_is_rolled_back() {
		$this->set_core_update( '99.0.0', 'https://downloads.wordpress.org/release/wordpress-99.0.0.zip' );
		$this->mock_wporg_api();
		$this->fail_package_downloads( 'download core fallito' );
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/core' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['updated'] );
		$this->assertSame( 'rolled_back', $data['result'] );
		$this->assertSame( array( 'updated', 'result', 'detail', 'log_id' ), array_keys( $data ) );
		$this->assert_update_golden( 'update/item/core-rolled-back', $response, $before );

		$rows = $this->log_rows();
		$this->assertSame( array( 'requested', 'rolled_back' ), array_column( $rows, 'phase' ) );
		$this->assertSame( 'core', $rows[0]['type'] );
		$this->assertSame( 'core', $rows[0]['target'] );
		$this->assertSame( 'WordPress', $rows[0]['name'] );
		$this->assertSame( get_bloginfo( 'version' ), $rows[0]['version_from'] );
		$this->assertSame( '99.0.0', $rows[0]['version_to'] );
		$this->assertSame( get_bloginfo( 'version' ), get_option( 'wp_health_check_core_version' ) );
		$this->assertSame( 'core', get_option( 'wp_health_check_last_update' )['type'] );
		$this->assert_lock_released();
	}

	// -----------------------------------------------------------------------
	// wphc_map_item_update_outcome(): mappatura dell'esito interno in risposta REST
	// -----------------------------------------------------------------------

	/**
	 * Esiti interni e risposta attesa (array = dati 200, stringa = codice WP_Error con status).
	 *
	 * @return array<string,array{0:array,1:mixed}>
	 */
	public function provide_outcomes() {
		return array(
			'403 disabled'                => array( array( 'result' => 'disabled', 'http' => 403 ), array( 'wphc_updates_disabled', 403 ) ),
			'409 locked'                  => array( array( 'result' => 'locked', 'http' => 409 ), array( 'wphc_update_locked', 409 ) ),
			'updated'                     => array(
				array( 'result' => 'updated', 'type' => 'plugin', 'target' => 'a/a.php', 'name' => 'A', 'from' => '1', 'to' => '2', 'log_id' => 7, 'http' => 200 ),
				array( 'updated' => true, 'type' => 'plugin', 'target' => 'a/a.php', 'name' => 'A', 'from' => '1', 'to' => '2', 'log_id' => 7 ),
			),
			'updatable'                   => array(
				array( 'result' => 'updatable', 'type' => 'theme', 'target' => 't', 'name' => 'T', 'current' => '1', 'latest' => '2', 'http' => 200 ),
				array( 'updated' => false, 'result' => 'updatable', 'type' => 'theme', 'target' => 't', 'name' => 'T', 'current' => '1', 'latest' => '2' ),
			),
			'reactivation_failed'         => array(
				array( 'result' => 'reactivation_failed', 'type' => 'plugin', 'target' => 'a/a.php', 'name' => 'A', 'from' => '1', 'to' => '2', 'log_id' => 8, 'detail' => 'boom', 'http' => 200 ),
				array( 'updated' => true, 'result' => 'reactivation_failed', 'type' => 'plugin', 'target' => 'a/a.php', 'name' => 'A', 'from' => '1', 'to' => '2', 'log_id' => 8, 'detail' => 'boom' ),
			),
			'up_to_date con current'      => array(
				array( 'result' => 'up_to_date', 'current' => '1.2', 'http' => 200 ),
				array( 'updated' => false, 'result' => 'up_to_date', 'current' => '1.2' ),
			),
			'rolled_back con detail/log'  => array(
				array( 'result' => 'rolled_back', 'detail' => 'd', 'log_id' => 3, 'http' => 200 ),
				array( 'updated' => false, 'result' => 'rolled_back', 'detail' => 'd', 'log_id' => 3 ),
			),
			'chiavi extra ignorate'       => array(
				array( 'result' => 'not_updatable', 'detail' => 'x', 'type' => 'plugin', 'name' => 'ignorato', 'http' => 200 ),
				array( 'updated' => false, 'result' => 'not_updatable', 'detail' => 'x' ),
			),
			'http assente vale 200'       => array( array( 'result' => 'not_found' ), array( 'updated' => false, 'result' => 'not_found' ) ),
			'http 502 e\' un esito generico' => array(
				array( 'result' => 'fs_method_unavailable', 'http' => 502 ),
				array( 'updated' => false, 'result' => 'fs_method_unavailable' ),
			),
			'log_id null non e\' esposto'  => array(
				array( 'result' => 'failed', 'log_id' => null, 'http' => 200 ),
				array( 'updated' => false, 'result' => 'failed' ),
			),
		);
	}

	/**
	 * Mappatura dell'esito.
	 *
	 * @dataProvider provide_outcomes
	 *
	 * @param array $outcome  Esito interno.
	 * @param mixed $expected Dati attesi oppure array( codice, status ) per un WP_Error.
	 */
	public function test_map_item_update_outcome( array $outcome, $expected ) {
		$mapped = wphc_map_item_update_outcome( $outcome );

		if ( isset( $expected[1] ) && is_int( $expected[1] ) ) {
			$this->assertInstanceOf( WP_Error::class, $mapped );
			$this->assertSame( $expected[0], $mapped->get_error_code() );
			$this->assertSame( array( 'status' => $expected[1] ), $mapped->get_error_data() );
			return;
		}

		$this->assertInstanceOf( WP_REST_Response::class, $mapped );
		$this->assertSame( 200, $mapped->get_status() );
		$this->assertSame( $expected, $mapped->get_data() );
	}

	/**
	 * Lo status 403/409 dell'esito prevale sul campo "result" (la mappatura guarda prima http).
	 */
	public function test_map_prefers_http_over_result() {
		$mapped = wphc_map_item_update_outcome(
			array(
				'result' => 'updated',
				'http'   => 409,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $mapped );
		$this->assertSame( 'wphc_update_locked', $mapped->get_error_code() );
	}
}
