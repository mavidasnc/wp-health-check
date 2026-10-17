<?php
/**
 * Test di caratterizzazione degli aggiornamenti bulk (POST/GET /update/bulk, POST /update/bulk/cancel,
 * drain via WP-Cron wphc_bulk_run_tick), plugin 1.38.1.
 *
 * Gli upgrader sono pilotati come in test-item-update.php (download fallito = rolled_back, nessun
 * file toccato; plugin fixture per il successo). Il webhook di fine job e' spento di default nei
 * test di questo file (vedi test-webhook.php per la consegna).
 *
 * Comportamenti sorprendenti fissati (da preservare o cambiare con decisione esplicita):
 *  - job.source e' 'wp-admin' anche se il job nasce da una richiesta REST (wphc_detect_update_source()
 *    distingue solo cron e WP-CLI), e i campi 'dry_run' e 'requested_ip' sono scritti ma mai letti.
 *  - Il limite di tempo per tick (WP_HEALTH_CHECK_BULK_TIME_BUDGET, 20 s) non e' osservabile nei test.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-update.php';

/**
 * Aggiornamenti bulk: accodamento, stato, annullamento, tick del drain, ri-armo.
 */
class WPHC_Test_Upd_Bulk extends WPHC_Update_Test_Case {

	/** Package di un host ufficiale. */
	const OFFICIAL_PACKAGE = 'https://downloads.wordpress.org/plugin/example.9.9.9.zip';

	/** Plugin esistente nel container. */
	const PLUGIN = 'hello.php';

	/**
	 * Verifica che il plugin di prova esista.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! isset( get_plugins()[ self::PLUGIN ] ) ) {
			$this->markTestSkipped( 'Serve il plugin hello.php del container wp-env.' );
		}
	}

	/**
	 * Applica una modifica al documento del job e lo salva.
	 *
	 * @param callable $mutator Funzione che riceve il job per riferimento.
	 */
	private function mutate_job( callable $mutator ) {
		$job = $this->bulk_job();
		call_user_func_array( $mutator, array( &$job ) );
		wphc_bulk_save_job( $job );
	}

	/**
	 * POST /update/bulk grezzo.
	 *
	 * @param array $body Body.
	 * @return WP_REST_Response
	 */
	private function post_bulk( array $body ) {
		return $this->upd( 'POST', '/update/bulk', $body );
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
	 * Autoload dell'option nel database.
	 *
	 * @param string $name Nome.
	 * @return string
	 */
	private function option_autoload( $name ) {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Prepara un aggiornamento disponibile per hello.php e un download che fallisce (esito rolled_back).
	 */
	private function prepare_failing_plugin_update() {
		$this->set_plugin_update( self::PLUGIN, '9.9.9', self::OFFICIAL_PACKAGE );
		$this->fail_package_downloads( 'download mock fallito' );
	}

	// -----------------------------------------------------------------------
	// POST /update/bulk: accodamento
	// -----------------------------------------------------------------------

	/**
	 * 202 con la forma completa della risposta, job in coda, riassunto autoloaded, tick pianificato.
	 */
	public function test_enqueue_accepts_with_full_response_shape() {
		$before   = $this->plugin_options();
		$response = $this->post_bulk(
			array(
				'items' => array(
					array(
						'type'   => 'plugin',
						'target' => self::PLUGIN,
					),
					array(
						'type'   => 'theme',
						'target' => 'twentytwentyfive',
					),
				),
			)
		);

		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'accepted', 'job_id', 'status', 'total', 'rejected', 'scheduled_at', 'cron_disabled', 'poll' ), array_keys( $data ) );
		$this->assertTrue( $data['accepted'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $data['job_id'] );
		$this->assertSame( 'queued', $data['status'] );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( array(), $data['rejected'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['scheduled_at'] );
		$this->assertSame( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, $data['cron_disabled'] );
		$this->assertSame(
			array(
				'job' => rest_url( 'health-check/v1/update/bulk' ),
				'log' => rest_url( 'health-check/v1/update/log' ) . '?source=cron',
			),
			$data['poll']
		);

		// Documento del job: non autoloaded; riassunto: autoloaded.
		$job = $this->bulk_job();
		$this->assertSame( $data['job_id'], $job['job_id'] );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame(
			array( 'job_id', 'status', 'abort_reason', 'created_ts', 'started_ts', 'finished_ts', 'next_run_ts', 'ticks', 'source', 'requested_ip', 'agent_version', 'dry_run', 'items', 'counters', 'webhook', 'stall_after_ts' ),
			array_keys( $job )
		);
		// Preservato: in contesto REST la sorgente e' 'wp-admin'; requested_ip e dry_run non sono mai letti.
		$this->assertSame( 'wp-admin', $job['source'] );
		$this->assertSame( self::CLIENT_IP, $job['requested_ip'] );
		$this->assertFalse( $job['dry_run'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $job['agent_version'] );
		$this->assertSame( array( 'total' => 2, 'done' => 0, 'updated' => 0, 'skipped' => 0, 'warnings' => 0, 'failed' => 0, 'pending' => 2 ), $job['counters'] );
		$this->assertSame( $job['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE, $job['stall_after_ts'] );
		$this->assertContains( $this->option_autoload( 'wp_health_check_bulk_job' ), array( 'no', 'off', 'auto-off' ), "Il job completo non e' autoloaded." );
		$this->assertContains( $this->option_autoload( 'wp_health_check_bulk_status' ), array( 'yes', 'on', 'auto-on', 'auto' ), 'Il riassunto e\' autoloaded.' );
		$this->assertSame(
			array( 'job_id', 'status', 'abort_reason', 'total', 'done', 'updated', 'skipped', 'warnings', 'failed', 'created_at', 'finished_at', 'next_run_ts', 'stall_after_ts' ),
			array_keys( get_option( 'wp_health_check_bulk_status' ) )
		);

		// Un solo evento cron singolo, immediato.
		$when = wp_next_scheduled( 'wphc_bulk_update_tick' );
		$this->assertIsInt( $when );
		$this->assertLessThanOrEqual( time(), $when );
		$this->assertFalse( wp_get_scheduled_event( 'wphc_bulk_update_tick' )->schedule, 'L\'evento e\' singolo, mai ricorrente.' );

		// Golden: il documento del job e il riassunto, con la risposta (URL di poll dipendenti dall'ambiente).
		$response->set_data( array_merge( $data, array( 'poll' => '<POLL>' ) ) );
		$this->assert_update_golden( 'update/bulk/enqueue-202', $response, $before );
	}

	/**
	 * Forma {plugins, themes}, forma mista e core-only {core:true}.
	 */
	public function test_enqueue_request_shapes() {
		$data = $this->enqueue_bulk(
			array(
				'plugins' => array( self::PLUGIN, 'akismet/akismet.php' ),
				'themes'  => array( 'twentytwentyfive' ),
			)
		);
		$this->assertSame( 3, $data['total'] );
		$items = $this->bulk_job()['items'];
		$this->assertSame(
			array( 'plugin|hello.php', 'plugin|akismet/akismet.php', 'theme|twentytwentyfive' ),
			array_map(
				static function ( $item ) {
					return $item['type'] . '|' . $item['target'];
				},
				$items
			)
		);

		// Forma mista: prima items[], poi plugins, themes.
		$this->mutate_job(
			static function ( &$job ) {
				$job['status'] = 'completed';
			}
		);
		$data = $this->enqueue_bulk(
			array(
				'items'   => array(
					array(
						'type'   => 'theme',
						'target' => 't1',
					),
				),
				'plugins' => array( 'p/p.php' ),
			)
		);
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 't1', $this->bulk_job()['items'][0]['target'] );

		// Core-only con {core:true}: item con target sentinella e nome noto.
		$this->mutate_job(
			static function ( &$job ) {
				$job['status'] = 'completed';
			}
		);
		$before   = $this->plugin_options();
		$response = $this->post_bulk( array( 'core' => true ) );
		$this->assertSame( 202, $response->get_status() );
		$item = $this->bulk_job()['items'][0];
		$this->assertSame( 'core', $item['type'] );
		$this->assertSame( 'core', $item['target'] );
		$this->assertSame( 'WordPress', $item['name'] );
		$response->set_data( array_merge( $response->get_data(), array( 'poll' => '<POLL>' ) ) );
		$this->assert_update_golden( 'update/bulk/enqueue-core', $response, $before );
	}

	/**
	 * Un item core nella forma canonica non ha bisogno del campo target.
	 */
	public function test_enqueue_core_item_without_target() {
		$data = $this->enqueue_bulk( array( 'items' => array( array( 'type' => 'core' ) ) ) );

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'core', $this->bulk_job()['items'][0]['target'] );
	}

	/**
	 * Core + plugin/temi nello stesso job: 400 wphc_bulk_core_not_exclusive con la ragione.
	 */
	public function test_enqueue_core_must_be_exclusive() {
		$before   = $this->plugin_options();
		$response = $this->post_bulk(
			array(
				'core'    => true,
				'plugins' => array( self::PLUGIN ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wphc_bulk_core_not_exclusive', $this->error_code( $response ) );
		$this->assertSame( array( 'status' => 400, 'reason' => 'core_must_be_exclusive' ), $response->get_data()['data'] );
		$this->assertNull( $this->bulk_job(), 'Nessun job creato.' );
		$this->assert_update_golden( 'update/bulk/enqueue-core-not-exclusive', $response, $before );
	}

	/**
	 * Nessun elemento valido: 400 wphc_bulk_no_valid_items (anche con body vuoto o non oggetto).
	 */
	public function test_enqueue_no_valid_items() {
		$before   = $this->plugin_options();
		$response = $this->post_bulk( array( 'plugins' => array( '../etc/passwd', 'senza-estensione' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wphc_bulk_no_valid_items', $this->error_code( $response ) );
		$this->assertSame( 'Nessun elemento valido da aggiornare.', $this->error_message( $response ) );
		$this->assert_update_golden( 'update/bulk/enqueue-no-valid-items', $response, $before );

		$this->assertSame( 'wphc_bulk_no_valid_items', $this->error_code( $this->upd( 'POST', '/update/bulk', array() ) ) );
		$this->assertSame( 'wphc_bulk_no_valid_items', $this->error_code( $this->upd( 'POST', '/update/bulk', '[1,2,3]' ) ) );
		$this->assertNull( $this->bulk_job() );
	}

	/**
	 * Rifiuti elencati nella risposta quando almeno un elemento e' valido.
	 */
	public function test_enqueue_reports_rejected_items() {
		$data = $this->enqueue_bulk(
			array(
				'items' => array(
					array(
						'type'   => 'plugin',
						'target' => self::PLUGIN,
					),
					array(
						'type'   => 'widget',
						'target' => 'x',
					),
					array(
						'type'   => 'plugin',
						'target' => '../x.php',
					),
				),
			)
		);

		$this->assertSame( 1, $data['total'] );
		$this->assertSame(
			array(
				array( 'type' => 'widget', 'target' => 'x', 'reason' => 'invalid_type' ),
				array( 'type' => 'plugin', 'target' => '../x.php', 'reason' => 'invalid_target' ),
			),
			$data['rejected']
		);
	}

	/**
	 * Kill-switch spento: 403; filesystem non 'direct': 200 con accepted:false.
	 */
	public function test_enqueue_preflight_kill_switch_and_filesystem() {
		$this->disable_api_updates();
		$before   = $this->plugin_options();
		$response = $this->post_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_updates_disabled', $this->error_code( $response ) );
		$this->assert_update_golden( 'update/bulk/enqueue-disabled', $response, $before );
		$this->assertNull( $this->bulk_job() );

		update_option( 'wp_health_check_updates_enabled', 1 );
		$this->force_fs_method( 'ftpext' );
		$before   = $this->plugin_options();
		$response = $this->post_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'accepted' => false,
				'result'   => 'fs_method_unavailable',
			),
			$response->get_data()
		);
		$this->assertNull( $this->bulk_job() );
		$this->assert_update_golden( 'update/bulk/enqueue-fs-unavailable', $response, $before );
	}

	/**
	 * L'accodamento non prende il lock degli update singoli: un lock altrui non lo blocca e non viene toccato.
	 */
	public function test_enqueue_ignores_the_update_lock() {
		set_transient( 'wp_health_check_update_lock', 1, 300 );
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );

		$this->assertSame( 1, (int) get_transient( 'wp_health_check_update_lock' ) );
	}

	/**
	 * Job gia' attivo: 409 wphc_bulk_job_in_progress con i dettagli; "force" non basta se non e' stallato.
	 */
	public function test_enqueue_conflict_with_active_job() {
		$first = $this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );

		$before   = $this->plugin_options();
		$response = $this->post_bulk( array( 'plugins' => array( 'altro/altro.php' ) ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wphc_bulk_job_in_progress', $this->error_code( $response ) );
		$this->assertSame( 'Un job di aggiornamento bulk e\' gia\' in corso.', $this->error_message( $response ) );
		$info = $response->get_data()['data'];
		$this->assertSame( array( 'status', 'job_id', 'job_status', 'total', 'done', 'stalled', 'next_run_at' ), array_keys( $info ) );
		$this->assertSame( $first['job_id'], $info['job_id'] );
		$this->assertSame( 'queued', $info['job_status'] );
		$this->assertSame( 1, $info['total'] );
		$this->assertSame( 0, $info['done'] );
		$this->assertFalse( $info['stalled'] );
		$this->assert_update_golden( 'update/bulk/enqueue-in-progress', $response, $before );

		// force su un job NON stallato: sempre 409.
		$response = $this->post_bulk(
			array(
				'plugins' => array( 'altro/altro.php' ),
				'force'   => true,
			)
		);
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $first['job_id'], $this->bulk_job()['job_id'] );
	}

	/**
	 * Un job terminato non blocca un nuovo accodamento.
	 */
	public function test_enqueue_after_finished_job() {
		$first = $this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['status'] = 'completed';
			}
		);
		$second = $this->enqueue_bulk( array( 'plugins' => array( 'altro/altro.php' ) ) );

		$this->assertNotSame( $first['job_id'], $second['job_id'] );
	}

	/**
	 * force su un job stallato: lo abortisce ('replaced_by_new_job', con webhook di fine job) e accoda il nuovo.
	 */
	public function test_enqueue_force_replaces_stalled_job() {
		update_option( 'wp_health_check_webhook_url', 'https://8.8.8.8/hook' );
		$this->mock_http( array( 'https://8.8.8.8/hook' => $this->http_response( 200, 'ok' ) ) );

		$first = $this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['next_run_ts'] = time() - 2 * WP_HEALTH_CHECK_BULK_STALL_GRACE;
			}
		);

		// Senza force: 409 e stalled:true.
		$response = $this->post_bulk( array( 'plugins' => array( 'altro/altro.php' ) ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertTrue( $response->get_data()['data']['stalled'] );

		$second = $this->enqueue_bulk(
			array(
				'plugins' => array( 'altro/altro.php' ),
				'force'   => true,
			)
		);
		$this->assertNotSame( $first['job_id'], $second['job_id'] );
		$this->assertSame( $second['job_id'], $this->bulk_job()['job_id'] );

		// Il job sostituito e' stato notificato come abortito.
		$hook_calls = array_values(
			array_filter(
				$this->http_calls,
				static function ( $call ) {
					return 'https://8.8.8.8/hook' === $call['url'];
				}
			)
		);
		$this->assertCount( 1, $hook_calls );
		$payload = json_decode( $hook_calls[0]['args']['body'], true );
		$this->assertSame( $first['job_id'], $payload['job']['id'] );
		$this->assertSame( 'aborted', $payload['job']['status'] );
		$this->assertSame( 'replaced_by_new_job', $payload['job']['abort_reason'] );
		$this->assertSame( array( null ), array_column( $payload['items'], 'result' ), "L'item pendente del job sostituito non ha un esito." );
		$this->assertSame( 1, $payload['totals']['failed'] );
	}

	// -----------------------------------------------------------------------
	// wphc_bulk_normalize_items()
	// -----------------------------------------------------------------------

	/**
	 * Normalizzazione: validazione di tipo e target, duplicati, trim, core e voci malformate.
	 *
	 * @return array<string,array{0:mixed,1:string[],2:array,3:string|null}>
	 */
	public function provide_normalization() {
		return array(
			'plugin valido'                  => array( array( 'plugins' => array( 'a/a.php', 'b.php' ) ), array( 'plugin|a/a.php', 'plugin|b.php' ), array(), null ),
			'trim del target'                => array( array( 'plugins' => array( '  a/a.php  ' ) ), array( 'plugin|a/a.php' ), array(), null ),
			'duplicati silenziosi'           => array(
				array(
					'items'   => array( array( 'type' => 'plugin', 'target' => 'a/a.php' ) ),
					'plugins' => array( 'a/a.php' ),
				),
				array( 'plugin|a/a.php' ),
				array(),
				null,
			),
			'stesso target tipi diversi'     => array(
				array(
					'plugins' => array( 'x' ),
					'themes'  => array( 'x' ),
				),
				array( 'theme|x' ),
				array( array( 'type' => 'plugin', 'target' => 'x', 'reason' => 'invalid_target' ) ),
				null,
			),
			'tipo non valido'                => array(
				array( 'items' => array( array( 'type' => 'mu-plugin', 'target' => 'a.php' ) ) ),
				array(),
				array( array( 'type' => 'mu-plugin', 'target' => 'a.php', 'reason' => 'invalid_type' ) ),
				null,
			),
			'plugin senza .php'              => array( array( 'plugins' => array( 'a/a' ) ), array(), array( array( 'type' => 'plugin', 'target' => 'a/a', 'reason' => 'invalid_target' ) ), null ),
			'plugin con due livelli'         => array( array( 'plugins' => array( 'a/b/c.php' ) ), array(), array( array( 'type' => 'plugin', 'target' => 'a/b/c.php', 'reason' => 'invalid_target' ) ), null ),
			'plugin con punti doppi'         => array( array( 'plugins' => array( 'a/..b.php' ) ), array(), array( array( 'type' => 'plugin', 'target' => 'a/..b.php', 'reason' => 'invalid_target' ) ), null ),
			'plugin con caratteri strani'    => array( array( 'plugins' => array( 'a b/c.php' ) ), array(), array( array( 'type' => 'plugin', 'target' => 'a b/c.php', 'reason' => 'invalid_target' ) ), null ),
			'tema con slash'                 => array( array( 'themes' => array( 'a/b' ) ), array(), array( array( 'type' => 'theme', 'target' => 'a/b', 'reason' => 'invalid_target' ) ), null ),
			'target vuoto'                   => array( array( 'plugins' => array( '   ' ) ), array(), array( array( 'type' => 'plugin', 'target' => '', 'reason' => 'invalid_target' ) ), null ),
			'core valido'                    => array( array( 'core' => true ), array( 'core|core' ), array(), null ),
			'core con target errato'         => array(
				array( 'items' => array( array( 'type' => 'core', 'target' => 'altro' ) ) ),
				array(),
				array( array( 'type' => 'core', 'target' => 'altro', 'reason' => 'invalid_target' ) ),
				null,
			),
			'core duplicato'                 => array(
				array(
					'items' => array( array( 'type' => 'core' ) ),
					'core'  => true,
				),
				array( 'core|core' ),
				array(),
				null,
			),
			'core misto'                     => array( array( 'core' => true, 'themes' => array( 't' ) ), array(), array(), 'core_must_be_exclusive' ),
			'core misto in items'            => array(
				array( 'items' => array( array( 'type' => 'core' ), array( 'type' => 'plugin', 'target' => 'a.php' ) ) ),
				array(),
				array(),
				'core_must_be_exclusive',
			),
			'voci senza type ignorate'       => array( array( 'items' => array( array( 'target' => 'a.php' ), 'stringa', 5 ) ), array(), array(), null ),
			'voce senza target ignorata'     => array( array( 'items' => array( array( 'type' => 'plugin' ) ) ), array(), array(), null ),
			'items non e\' un array'         => array( array( 'items' => 'a.php' ), array(), array(), null ),
			'plugins non e\' un array'       => array( array( 'plugins' => 'a.php' ), array(), array(), null ),
			'core falsy ignorato'            => array( array( 'core' => 0 ), array(), array(), null ),
			'core truthy: qualunque valore'  => array( array( 'core' => 'false' ), array( 'core|core' ), array(), null ),
			'raw non e\' un array'           => array( 'stringa', array(), array(), null ),
			'raw null'                       => array( null, array(), array(), null ),
		);
	}

	/**
	 * Verifica la normalizzazione.
	 *
	 * @dataProvider provide_normalization
	 *
	 * @param mixed       $raw      Body.
	 * @param string[]    $items    Elementi attesi (tipo|target).
	 * @param array       $rejected Rifiuti attesi.
	 * @param string|null $error    Errore atteso.
	 */
	public function test_normalize_items( $raw, array $items, array $rejected, $error ) {
		$result = wphc_bulk_normalize_items( $raw );

		$this->assertSame( $error, $result['error'] );
		$this->assertSame( $rejected, $result['rejected'] );
		$this->assertSame(
			$items,
			array_map(
				static function ( $item ) {
					return $item['type'] . '|' . $item['target'];
				},
				$result['items']
			)
		);
		// Ogni elemento nasce 'pending' con i contatori a zero.
		foreach ( $result['items'] as $item ) {
			$this->assertSame( 'pending', $item['state'] );
			$this->assertSame( 0, $item['attempts'] );
			$this->assertSame( 0, $item['deferrals'] );
			$this->assertNull( $item['claimed_ts'] );
			$this->assertSame( 'core' === $item['type'] ? 'WordPress' : null, $item['name'] );
		}
	}

	/**
	 * Tetto di 100 elementi: dal 101-esimo in poi 'max_items_exceeded'; i duplicati non contano.
	 */
	public function test_normalize_items_cap() {
		$plugins = array();
		for ( $i = 1; $i <= 103; $i++ ) {
			$plugins[] = "p{$i}/p{$i}.php";
		}
		$plugins[] = 'p1/p1.php'; // Duplicato: scartato in silenzio, non rifiutato.
		$result    = wphc_bulk_normalize_items( array( 'plugins' => $plugins ) );

		$this->assertSame( 100, WP_HEALTH_CHECK_BULK_MAX_ITEMS );
		$this->assertCount( 100, $result['items'] );
		$this->assertSame(
			array(
				array( 'type' => 'plugin', 'target' => 'p101/p101.php', 'reason' => 'max_items_exceeded' ),
				array( 'type' => 'plugin', 'target' => 'p102/p102.php', 'reason' => 'max_items_exceeded' ),
				array( 'type' => 'plugin', 'target' => 'p103/p103.php', 'reason' => 'max_items_exceeded' ),
			),
			$result['rejected']
		);
	}

	// -----------------------------------------------------------------------
	// GET /update/bulk
	// -----------------------------------------------------------------------

	/**
	 * Nessun job mai creato: {site, job:null}.
	 */
	public function test_status_without_job() {
		$before   = $this->plugin_options();
		$response = $this->upd( 'GET', '/update/bulk' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'site' => $this->own_site_url(),
				'job'  => null,
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/bulk/status-none', $response, $before );
	}

	/**
	 * Stato di un job in coda: forma, items inclusi di default, esclusi con ?items=0.
	 */
	public function test_status_of_queued_job_and_items_flag() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );

		$before   = $this->plugin_options();
		$response = $this->upd( 'GET', '/update/bulk' );
		$data     = $response->get_data();

		$this->assertSame( array( 'site', 'generated_at', 'job' ), array_keys( $data ) );
		$this->assertSame(
			array( 'job_id', 'status', 'abort_reason', 'created_at', 'started_at', 'finished_at', 'next_run_at', 'stalled', 'counters', 'webhook', 'items' ),
			array_keys( $data['job'] )
		);
		$this->assertSame( 'queued', $data['job']['status'] );
		$this->assertNull( $data['job']['started_at'] );
		$this->assertFalse( $data['job']['stalled'] );
		$this->assertCount( 1, $data['job']['items'] );
		$this->assert_update_golden( 'update/bulk/status-queued', $response, $before );

		// ?items=0 toglie gli item; qualunque altro valore (anche vuoto o "false") li lascia.
		$no_items = $this->upd( 'GET', '/update/bulk', null, array( 'items' => '0' ) )->get_data();
		$this->assertArrayNotHasKey( 'items', $no_items['job'] );
		foreach ( array( '1', '', 'false' ) as $value ) {
			$this->assertArrayHasKey( 'items', $this->upd( 'GET', '/update/bulk', null, array( 'items' => $value ) )->get_data()['job'], "items={$value}" );
		}
	}

	/**
	 * Il polling dichiara "stalled" un job attivo il cui prossimo giro e' scaduto da oltre il margine.
	 */
	public function test_status_reports_stalled_job() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['next_run_ts'] = time() - WP_HEALTH_CHECK_BULK_STALL_GRACE - 5;
			}
		);

		$this->assertTrue( $this->upd( 'GET', '/update/bulk' )->get_data()['job']['stalled'] );
		$this->assertSame( 'queued', $this->bulk_job()['status'], 'Lo stallo e\' derivato, non cambia lo stato.' );
	}

	/**
	 * Il solo polling sblocca un elemento 'running' orfano (claim oltre il timeout): torna 'pending' con backoff,
	 * pianifica un tick; all'ultimo tentativo diventa 'error' e il job si finalizza.
	 */
	public function test_status_polling_reaps_stuck_item() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['status']                     = 'running';
				$job['items'][0]['state']          = 'running';
				$job['items'][0]['claimed_ts']     = time() - WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT - 10;
			}
		);
		$this->clear_plugin_cron();

		$data = $this->upd( 'GET', '/update/bulk' )->get_data();

		$item = $data['job']['items'][0];
		$this->assertSame( 'pending', $item['state'] );
		$this->assertSame( 1, $item['attempts'] );
		$this->assertSame( 'interrupted (timeout/fatal)', $item['last_error'] );
		$this->assertEqualsWithDelta( time() + 60, $item['next_after'], 5, 'Backoff del primo retry: 60 s.' );
		$this->assertIsInt( wp_next_scheduled( 'wphc_bulk_update_tick' ), 'Il polling ripianifica un tick.' );
		$this->assertSame( 'running', $data['job']['status'] );

		// All'ultimo tentativo l'elemento va in errore e il job si finalizza.
		$this->mutate_job(
			static function ( &$job ) {
				$job['items'][0]['state']      = 'running';
				$job['items'][0]['claimed_ts'] = time() - WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT - 10;
				$job['items'][0]['attempts']   = WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS - 1;
			}
		);
		$data = $this->upd( 'GET', '/update/bulk' )->get_data();
		$this->assertSame( 'error', $data['job']['items'][0]['state'] );
		$this->assertSame( 'failed', $data['job']['items'][0]['result'] );
		$this->assertSame( 'completed_with_errors', $data['job']['status'] );
		$this->assertSame( 1, $data['job']['counters']['failed'] );
		$this->assertSame( 1, $data['job']['webhook']['attempts'], 'Il webhook di fine job e\' stato tentato.' );
	}

	/**
	 * Il core ha una soglia piu' larga (1800 s): a 1000 s non e' orfano, a 2000 s si'.
	 */
	public function test_status_core_item_uses_longer_timeout() {
		$this->enqueue_bulk( array( 'core' => true ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['status']                 = 'running';
				$job['items'][0]['state']      = 'running';
				$job['items'][0]['claimed_ts'] = time() - 1000;
			}
		);
		$this->assertSame( 'running', $this->upd( 'GET', '/update/bulk' )->get_data()['job']['items'][0]['state'] );

		$this->mutate_job(
			static function ( &$job ) {
				$job['items'][0]['claimed_ts'] = time() - 2000;
			}
		);
		$this->assertSame( 'pending', $this->upd( 'GET', '/update/bulk' )->get_data()['job']['items'][0]['state'] );
	}

	/**
	 * Scadenza dura del job (6 ore dalla creazione): il polling lo aborta come 'job_timeout' e lo finalizza.
	 */
	public function test_status_job_timeout() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN, 'b/b.php' ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['created_ts'] = time() - 6 * HOUR_IN_SECONDS - 5;
			}
		);

		$data = $this->upd( 'GET', '/update/bulk' )->get_data();

		$this->assertSame( 'aborted', $data['job']['status'] );
		$this->assertSame( 'job_timeout', $data['job']['abort_reason'] );
		$this->assertNotNull( $data['job']['finished_at'] );
		$this->assertSame( 2, $data['job']['counters']['failed'] );
		$this->assertSame( 'job aborted: job_timeout', $data['job']['items'][0]['last_error'] );
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ), 'Tick cancellati alla fine del job.' );
	}

	// -----------------------------------------------------------------------
	// POST /update/bulk/cancel
	// -----------------------------------------------------------------------

	/**
	 * Nessun job attivo: 404 wphc_bulk_no_active_job (anche con job gia' terminato).
	 */
	public function test_cancel_without_active_job() {
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/bulk/cancel' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wphc_bulk_no_active_job', $this->error_code( $response ) );
		$this->assertSame( 'Nessun job di aggiornamento bulk in corso.', $this->error_message( $response ) );
		$this->assert_update_golden( 'update/bulk/cancel-none', $response, $before );

		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['status'] = 'completed';
			}
		);
		$this->assertSame( 404, $this->upd( 'POST', '/update/bulk/cancel' )->get_status() );
	}

	/**
	 * Annullamento: stato 'cancelled', pendenti in errore ('cancelled'), tick cancellati, webhook tentato.
	 */
	public function test_cancel_active_job() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN, 'b/b.php' ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				// Un elemento gia' concluso resta com'e'.
				$job['items'][0]['state']  = 'done';
				$job['items'][0]['result'] = 'up_to_date';
				wphc_bulk_recount( $job );
			}
		);

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/bulk/cancel' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'site', 'job_id', 'status' ), array_keys( $data ) );
		$this->assertSame( 'cancelled', $data['status'] );

		$job = $this->bulk_job();
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertNotNull( $job['finished_ts'] );
		$this->assertSame( 'done', $job['items'][0]['state'] );
		$this->assertSame( 'error', $job['items'][1]['state'] );
		$this->assertSame( 'cancelled', $job['items'][1]['last_error'] );
		$this->assertSame( 1, $job['counters']['failed'] );
		$this->assertSame( 1, $job['webhook']['attempts'], 'Il webhook parte anche per un job annullato.' );
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );
		$this->assertSame( 'cancelled', get_option( 'wp_health_check_bulk_status' )['status'] );
		$response->set_data( $data );
		$this->assert_update_golden( 'update/bulk/cancel-ok', $response, $before );
	}

	// -----------------------------------------------------------------------
	// Tick del drain: esiti
	// -----------------------------------------------------------------------

	/**
	 * Tick con tutti esiti "senza effetto" (not_found, up_to_date, not_updatable): gli item sono 'done', il job
	 * si completa, il tick cancella lo scheduling e tenta il webhook, il lock di drain e' rilasciato.
	 */
	public function test_tick_noop_outcomes_complete_the_job() {
		$this->set_theme_update( 'twentytwentyfive', '99.0', '' ); // Pacchetto vuoto: not_updatable.
		$this->enqueue_bulk(
			array(
				'items' => array(
					array( 'type' => 'plugin', 'target' => 'nulla/nulla.php' ),
					array( 'type' => 'plugin', 'target' => self::PLUGIN ),
					array( 'type' => 'theme', 'target' => 'twentytwentyfive' ),
				),
			)
		);
		set_transient( 'wphc_health_cache', 'x', 300 );

		$this->run_tick();

		$job = $this->bulk_job();
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( 1, $job['ticks'] );
		$this->assertNotNull( $job['started_ts'] );
		$this->assertNotNull( $job['finished_ts'] );
		$this->assertSame( array( 'not_found', 'up_to_date', 'not_updatable' ), array_column( $job['items'], 'result' ) );
		$this->assertSame( array( 'done', 'done', 'done' ), array_column( $job['items'], 'state' ) );
		$this->assertSame( array( 'total' => 3, 'done' => 3, 'updated' => 0, 'skipped' => 3, 'warnings' => 0, 'failed' => 0, 'pending' => 0 ), $job['counters'] );
		// up_to_date: from = versione corrente (campo 'current'); not_updatable: detail -> last_error.
		$this->assertSame( get_plugins()[ self::PLUGIN ]['Version'], $job['items'][1]['from'] );
		$this->assertSame( 'pacchetto non ospitato su wordpress.org', $job['items'][2]['last_error'] );
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );
		$this->assertFalse( get_transient( 'wp_health_check_bulk_lock' ), 'Il mutex di drain e\' rilasciato.' );
		$this->assertFalse( get_transient( 'wphc_health_cache' ) );
		$this->assertSame( 1, $job['webhook']['attempts'] );
		$this->assertSame( array(), $this->log_rows(), 'Gli esiti senza effetto non scrivono nel log.' );

		$this->assertSame( 'completed', get_option( 'wp_health_check_bulk_status' )['status'] );
		$before   = $this->plugin_options();
		$response = $this->upd( 'GET', '/update/bulk' );
		$this->assert_update_golden( 'update/bulk/status-completed', $response, $before );
	}

	/**
	 * Al massimo 5 elementi per tick: con 7 elementi servono due tick; il primo lascia il job 'running' con
	 * un nuovo tick pianificato.
	 */
	public function test_tick_processes_at_most_five_items() {
		$plugins = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$plugins[] = "n{$i}/n{$i}.php";
		}
		$this->enqueue_bulk( array( 'plugins' => $plugins ) );

		$this->run_tick();
		$job = $this->bulk_job();
		$this->assertSame( 5, WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 5, $job['counters']['done'] );
		$this->assertSame( 2, $job['counters']['pending'] );
		$this->assertSame( 'running', get_option( 'wp_health_check_bulk_status' )['status'] );
		$when = wp_next_scheduled( 'wphc_bulk_update_tick' );
		$this->assertIsInt( $when );
		$this->assertEqualsWithDelta( time() + WP_HEALTH_CHECK_BULK_TICK_GAP, $when, 5, 'Prossimo tick tra 30 s.' );
		$this->assertSame( $when, $job['next_run_ts'] );

		$this->run_tick();
		$job = $this->bulk_job();
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( 2, $job['ticks'] );
		$this->assertSame( 7, $job['counters']['done'] );
	}

	/**
	 * Tentativi con backoff: l'esito rolled_back e' un retry (1 iniziale + 3), attesa 60/300/900 s tra i tentativi;
	 * al quarto fallimento l'elemento va in errore e il job termina 'completed_with_errors'.
	 */
	public function test_tick_retry_with_backoff_until_error() {
		$this->prepare_failing_plugin_update();
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );

		$expected_backoff = array( 60, 300, 900 );
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$this->run_tick();
			$job  = $this->bulk_job();
			$item = $job['items'][0];

			$this->assertSame( 'running', $job['status'], "dopo il tentativo {$attempt}" );
			$this->assertSame( 'pending', $item['state'] );
			$this->assertSame( $attempt, $item['attempts'] );
			$this->assertSame( 'rolled_back', $item['result'] );
			$this->assertEqualsWithDelta( time() + $expected_backoff[ $attempt - 1 ], $item['next_after'], 5 );
			$this->assertNotNull( $item['last_error'] );
			$this->assertNull( $item['finished_ts'] );
			$this->assertGreaterThanOrEqual( $item['next_after'] - 900 - 5, $job['next_run_ts'] );

			// Un tick subito dopo non riprova l'elemento (backoff non scaduto).
			$this->run_tick();
			$this->assertSame( $attempt, $this->bulk_job()['items'][0]['attempts'], 'Backoff non scaduto: nessun nuovo tentativo.' );
			$this->bulk_rewind_backoff();
		}

		$this->run_tick();
		$job  = $this->bulk_job();
		$item = $job['items'][0];
		$this->assertSame( 'error', $item['state'] );
		$this->assertSame( WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS, $item['attempts'] );
		$this->assertNotNull( $item['finished_ts'] );
		$this->assertSame( 'completed_with_errors', $job['status'] );
		$this->assertSame( 1, $job['counters']['failed'] );
		$this->assertSame( 1, $job['counters']['done'] );

		// Quattro tentativi reali dell'upgrader: righe di log requested/rolled_back con source 'cron'.
		$this->assertSame(
			array( 'requested', 'rolled_back', 'requested', 'rolled_back', 'requested', 'rolled_back', 'requested', 'rolled_back' ),
			$this->log_phases()
		);
		foreach ( $this->log_rows() as $row ) {
			$this->assertSame( 'cron', $row['source'] );
		}
		$this->assertCount( 4, $item['correlations'], 'Un log_id (riga finale) per tentativo.' );
		$this->assertSame( (int) $this->log_rows( "phase = 'rolled_back'" )[3]['id'], $item['log_id'] );
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );

		$before   = $this->plugin_options();
		$response = $this->upd( 'GET', '/update/bulk' );
		$this->assert_update_golden( 'update/bulk/status-completed-with-errors', $response, $before );
	}

	/**
	 * Contesa di lock con un update singolo: 'deferral', non consuma tentativi, riprova dopo 30 s; al ventesimo
	 * deferral l'elemento va in errore ('failed', 'lock contention').
	 */
	public function test_tick_lock_contention_defers_then_fails() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		set_transient( 'wp_health_check_update_lock', 1, 3600 );

		$this->run_tick();
		$item = $this->bulk_job()['items'][0];
		$this->assertSame( 'pending', $item['state'] );
		$this->assertSame( 1, $item['deferrals'] );
		$this->assertSame( 0, $item['attempts'], 'Un deferral non consuma un tentativo.' );
		$this->assertEqualsWithDelta( time() + WP_HEALTH_CHECK_BULK_TICK_GAP, $item['next_after'], 5 );
		$this->assertSame( 'locked', $item['result'] );

		for ( $i = 2; $i <= WP_HEALTH_CHECK_BULK_MAX_DEFERRALS; $i++ ) {
			$this->bulk_rewind_backoff();
			$this->run_tick();
		}

		$job  = $this->bulk_job();
		$item = $job['items'][0];
		$this->assertSame( 20, WP_HEALTH_CHECK_BULK_MAX_DEFERRALS );
		$this->assertSame( 'error', $item['state'] );
		$this->assertSame( 'failed', $item['result'] );
		$this->assertSame( 'lock contention', $item['last_error'] );
		$this->assertSame( 20, $item['deferrals'] );
		$this->assertSame( 'completed_with_errors', $job['status'] );
	}

	/**
	 * Kill-switch spento durante il job: l'intero job e' abortito ('disabled'); gli elementi pendenti in errore.
	 */
	public function test_tick_job_fatal_aborts_whole_job() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN, 'b/b.php', 'c/c.php' ) ) );
		$this->disable_api_updates();

		$this->run_tick();

		$job = $this->bulk_job();
		$this->assertSame( 'aborted', $job['status'] );
		$this->assertSame( 'disabled', $job['abort_reason'] );
		$this->assertSame( array( 'error', 'error', 'error' ), array_column( $job['items'], 'state' ) );
		$this->assertSame( 'job aborted: disabled', $job['items'][0]['last_error'] );
		$this->assertSame( 3, $job['counters']['failed'] );
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );
		$this->assertSame( 1, $job['webhook']['attempts'] );

		$status = $this->upd( 'GET', '/update/bulk' )->get_data();
		$this->assertSame( 'aborted', $status['job']['status'] );
	}

	/**
	 * Filesystem non 'direct' durante il job: abort con 'fs_method_unavailable'.
	 */
	public function test_tick_fs_method_unavailable_aborts_job() {
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->force_fs_method( 'ftpext' );

		$this->run_tick();

		$this->assertSame( 'fs_method_unavailable', $this->bulk_job()['abort_reason'] );
	}

	/**
	 * Aggiornamento riuscito dentro il tick (plugin fixture): item 'done' result 'updated' con from/to e log_id,
	 * contatore 'updated'; il tick non scrive "ultimo accesso" (contesto cron).
	 */
	public function test_tick_successful_update() {
		$this->install_fixture_plugin( '1.0.0' );
		$this->set_plugin_update( self::FIXTURE_FILE, '2.0.0', self::OFFICIAL_PACKAGE );
		$this->serve_package_from( $this->build_fixture_zip( '2.0.0' ) );
		$this->enqueue_bulk( array( 'plugins' => array( self::FIXTURE_FILE ) ) );
		delete_option( 'wp_health_check_last_request_at' );

		$this->run_tick();

		$job  = $this->bulk_job();
		$item = $job['items'][0];
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( 'done', $item['state'] );
		$this->assertSame( 'updated', $item['result'] );
		$this->assertSame( 'WPHC Fixture', $item['name'] );
		$this->assertSame( '1.0.0', $item['from'] );
		$this->assertSame( '2.0.0', $item['to'] );
		$this->assertSame( array( 'total' => 1, 'done' => 1, 'updated' => 1, 'skipped' => 0, 'warnings' => 0, 'failed' => 0, 'pending' => 0 ), $job['counters'] );
		$rows = $this->log_rows();
		$this->assertSame( array( 'requested', 'completed' ), array_column( $rows, 'phase' ) );
		$this->assertSame( 'cron', $rows[1]['source'] );
		$this->assertSame( (int) $rows[1]['id'], $item['log_id'] );
		$this->assertSame( array( (int) $rows[1]['id'] ), $item['correlations'] );
		$this->assertFalse( get_option( 'wp_health_check_last_request_at' ), 'Il drain in contesto cron non sovrascrive l\'ultimo accesso.' );
	}

	/**
	 * Job core: l'item usa wphc_perform_core_update(); con download fallito e' un retry come gli altri.
	 */
	public function test_tick_core_item_failure_is_retry() {
		$this->mock_wporg_api();
		$this->set_core_update( '99.0.0', 'https://downloads.wordpress.org/release/wordpress-99.0.0.zip' );
		$this->fail_package_downloads();
		$this->enqueue_bulk( array( 'core' => true ) );

		$this->run_tick();

		$item = $this->bulk_job()['items'][0];
		$this->assertSame( 'pending', $item['state'] );
		$this->assertSame( 'rolled_back', $item['result'] );
		$this->assertSame( 1, $item['attempts'] );
		$rows = $this->log_rows();
		$this->assertSame( 'core', $rows[0]['type'] );
		$this->assertSame( 'cron', $rows[0]['source'] );
		$this->assertFalse( get_transient( 'wp_health_check_bulk_lock' ) );
	}

	/**
	 * Il tick abortisce un job scaduto (6 ore) e uno senza job o gia' terminato non fa nulla.
	 */
	public function test_tick_edge_cases() {
		// Nessun job: ritorna senza errori e libera il mutex.
		$this->run_tick();
		$this->assertFalse( get_transient( 'wp_health_check_bulk_lock' ) );

		// Job scaduto.
		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['created_ts'] = time() - 6 * HOUR_IN_SECONDS - 5;
			}
		);
		$this->run_tick();
		$job = $this->bulk_job();
		$this->assertSame( 'aborted', $job['status'] );
		$this->assertSame( 'job_timeout', $job['abort_reason'] );
		$this->assertSame( 1, $job['ticks'] );

		// Job terminato: un altro tick non lo cambia.
		$ticks = $job['ticks'];
		$this->run_tick();
		$this->assertSame( $ticks, $this->bulk_job()['ticks'] );
	}

	/**
	 * Mutex di drain: con il mutex preso il tick esce subito (e non lo rilascia); TTL di 300 s.
	 */
	public function test_tick_respects_drain_mutex() {
		$this->enqueue_bulk( array( 'plugins' => array( 'n/n.php' ) ) );
		set_transient( 'wp_health_check_bulk_lock', 1, 300 );

		$this->run_tick();

		$this->assertSame( 'queued', $this->bulk_job()['status'], 'Nessun lavoro con il mutex altrui.' );
		$this->assertNotFalse( get_transient( 'wp_health_check_bulk_lock' ), 'Il mutex altrui non viene rilasciato.' );

		delete_transient( 'wp_health_check_bulk_lock' );
		$this->assertTrue( wphc_bulk_acquire_lock() );
		$this->assertFalse( wphc_bulk_acquire_lock() );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( '_transient_timeout_wp_health_check_bulk_lock' ), 5 );
		wphc_bulk_release_lock();
		$this->assertFalse( get_transient( 'wp_health_check_bulk_lock' ) );
	}

	/**
	 * Un elemento 'running' orfano viene recuperato all'inizio del tick (poi riprovato se il backoff e' scaduto).
	 */
	public function test_tick_reaps_stuck_item_before_working() {
		$this->enqueue_bulk( array( 'plugins' => array( 'n/n.php' ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['items'][0]['state']      = 'running';
				$job['items'][0]['claimed_ts'] = time() - WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT - 10;
			}
		);

		$this->run_tick();

		$item = $this->bulk_job()['items'][0];
		$this->assertSame( 1, $item['attempts'], 'Il reap consuma un tentativo.' );
		$this->assertSame( 'pending', $item['state'], 'Backoff di 60 s: non riprovato nello stesso tick.' );
		$this->assertSame( 'interrupted (timeout/fatal)', $item['last_error'] );
	}

	// -----------------------------------------------------------------------
	// Funzioni di supporto del drain
	// -----------------------------------------------------------------------

	/**
	 * Classificazione degli esiti (sconosciuto = retry).
	 */
	public function test_classify_result_map() {
		$map = array(
			'updated'                => 'success',
			'up_to_date'             => 'noop',
			'not_found'              => 'noop',
			'not_updatable'          => 'noop',
			'reactivation_failed'    => 'warning',
			'failed'                 => 'retry',
			'rolled_back'            => 'retry',
			'locked'                 => 'defer',
			'disabled'               => 'job_fatal',
			'unsupported_wp_version' => 'job_fatal',
			'fs_method_unavailable'  => 'job_fatal',
			'qualcosa_di_nuovo'      => 'retry',
			''                       => 'retry',
		);
		foreach ( $map as $result => $class ) {
			$this->assertSame( $class, wphc_bulk_classify_result( $result ), "'{$result}'" );
		}
	}

	/**
	 * Tabella di backoff dei tentativi: [0, 60, 300, 900], saturata agli estremi.
	 */
	public function test_backoff_for_attempt() {
		$this->assertSame( array( 0, 60, 300, 900 ), WP_HEALTH_CHECK_BULK_BACKOFF );
		$this->assertSame( 4, WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS );
		foreach ( array( -3 => 0, 0 => 0, 1 => 60, 2 => 300, 3 => 900, 4 => 900, 50 => 900 ) as $attempts => $seconds ) {
			$this->assertSame( $seconds, wphc_bulk_backoff_for_attempt( $attempts ), "tentativo {$attempts}" );
		}
	}

	/**
	 * wphc_bulk_apply_outcome(): effetto di ogni classe di esito sull'elemento e sui contatori.
	 */
	public function test_apply_outcome_per_class() {
		$normalized = wphc_bulk_normalize_items( array( 'plugins' => array( 'a/a.php', 'b/b.php', 'c/c.php', 'd/d.php', 'e/e.php' ) ) );
		$job        = array(
			'status'       => 'running',
			'abort_reason' => null,
			'finished_ts'  => null,
			'items'        => $normalized['items'],
		);
		wphc_bulk_recount( $job );

		// 0: successo con from/to, nome e log_id.
		wphc_bulk_apply_outcome( $job, 0, array( 'result' => 'updated', 'name' => 'A', 'from' => '1', 'to' => '2', 'log_id' => 11 ) );
		$this->assertSame( 'done', $job['items'][0]['state'] );
		$this->assertSame( array( 'A', '1', '2', 11, array( 11 ) ), array( $job['items'][0]['name'], $job['items'][0]['from'], $job['items'][0]['to'], $job['items'][0]['log_id'], $job['items'][0]['correlations'] ) );
		$this->assertSame( 1, $job['counters']['updated'] );

		// 1: noop con current/latest al posto di from/to.
		wphc_bulk_apply_outcome( $job, 1, array( 'result' => 'up_to_date', 'current' => '5' ) );
		$this->assertSame( '5', $job['items'][1]['from'] );
		$this->assertNull( $job['items'][1]['to'] );
		wphc_bulk_apply_outcome( $job, 1, array( 'result' => 'up_to_date', 'latest' => '6' ) );
		$this->assertSame( '6', $job['items'][1]['to'] );
		$this->assertSame( 1, $job['counters']['skipped'] );

		// 2: warning (reactivation_failed) -> done, conta come 'warnings'.
		wphc_bulk_apply_outcome( $job, 2, array( 'result' => 'reactivation_failed', 'detail' => str_repeat( 'x', 300 ) ) );
		$this->assertSame( 'done', $job['items'][2]['state'] );
		$this->assertSame( 1, $job['counters']['warnings'] );
		$this->assertSame( 200, strlen( $job['items'][2]['last_error'] ), 'Il dettaglio e\' troncato a 200 caratteri.' );

		// 3: retry -> pending con attempts 1 e backoff 60.
		wphc_bulk_apply_outcome( $job, 3, array( 'result' => 'failed' ) );
		$this->assertSame( 'pending', $job['items'][3]['state'] );
		$this->assertSame( 1, $job['items'][3]['attempts'] );
		$this->assertEqualsWithDelta( time() + 60, $job['items'][3]['next_after'], 5 );

		// Senza 'result' l'esito vale 'failed' (retry).
		wphc_bulk_apply_outcome( $job, 4, array() );
		$this->assertSame( 'failed', $job['items'][4]['result'] );
		$this->assertSame( 1, $job['items'][4]['attempts'] );

		// log_id falsy non e' registrato.
		wphc_bulk_apply_outcome( $job, 4, array( 'result' => 'failed', 'log_id' => 0 ) );
		$this->assertNull( $job['items'][4]['log_id'] );
		$this->assertSame( array(), $job['items'][4]['correlations'] );
	}

	/**
	 * Un elemento in errore dopo 4 tentativi; un job_fatal abortisce e rende 'error' i pendenti.
	 */
	public function test_apply_outcome_exhaustion_and_job_fatal() {
		$normalized = wphc_bulk_normalize_items( array( 'plugins' => array( 'a/a.php', 'b/b.php' ) ) );
		$job        = array(
			'status'       => 'running',
			'abort_reason' => null,
			'finished_ts'  => null,
			'items'        => $normalized['items'],
		);
		wphc_bulk_recount( $job );

		for ( $i = 0; $i < 3; $i++ ) {
			wphc_bulk_apply_outcome( $job, 0, array( 'result' => 'rolled_back' ) );
			$this->assertSame( 'pending', $job['items'][0]['state'] );
		}
		wphc_bulk_apply_outcome( $job, 0, array( 'result' => 'rolled_back' ) );
		$this->assertSame( 'error', $job['items'][0]['state'] );
		$this->assertNotNull( $job['items'][0]['finished_ts'] );

		wphc_bulk_apply_outcome( $job, 1, array( 'result' => 'unsupported_wp_version' ) );
		$this->assertSame( 'aborted', $job['status'] );
		$this->assertSame( 'unsupported_wp_version', $job['abort_reason'] );
		$this->assertSame( 'error', $job['items'][1]['state'] );
		$this->assertSame( 'job aborted: unsupported_wp_version', $job['items'][1]['last_error'] );
		$this->assertSame( 2, $job['counters']['failed'] );
	}

	/**
	 * Istante di "stallo": calcolato dal claim dell'elemento in corso (con la soglia del suo tipo) oppure da next_run_ts.
	 */
	public function test_stall_after_computation() {
		$plugin = wphc_bulk_new_item( 'plugin', 'a/a.php' );
		$core   = wphc_bulk_new_item( 'core', 'core' );

		$job = array(
			'next_run_ts' => 1000,
			'items'       => array( $plugin ),
		);
		$this->assertSame( 1000 + 600, wphc_bulk_compute_stall_after_ts( $job ) );

		$plugin['state']      = 'running';
		$plugin['claimed_ts'] = 2000;
		$job['items']         = array( $plugin );
		$this->assertSame( 2000 + 600 + 600, wphc_bulk_compute_stall_after_ts( $job ) );

		$core['state']      = 'running';
		$core['claimed_ts'] = 2000;
		$job['items']       = array( $core );
		$this->assertSame( 2000 + 1800 + 600, wphc_bulk_compute_stall_after_ts( $job ) );

		// 'running' senza claim: formula generica.
		$plugin['claimed_ts'] = null;
		$job['items']         = array( $plugin );
		$this->assertSame( 1000 + 600, wphc_bulk_compute_stall_after_ts( $job ) );
	}

	/**
	 * Prossimo ritardo: almeno 30 s, ma non prima del backoff piu' vicino tra i pendenti.
	 */
	public function test_next_delay() {
		$now = time();
		$a   = wphc_bulk_new_item( 'plugin', 'a/a.php' );
		$b   = wphc_bulk_new_item( 'plugin', 'b/b.php' );

		$this->assertSame( 30, wphc_bulk_next_delay( array( 'items' => array() ) ), 'Nessun pendente.' );
		$a['next_after'] = $now + 300;
		$b['next_after'] = $now + 900;
		$delay           = wphc_bulk_next_delay( array( 'items' => array( $a, $b ) ) );
		$this->assertEqualsWithDelta( 300, $delay, 2 );
		$a['next_after'] = $now - 100;
		$this->assertSame( 30, wphc_bulk_next_delay( array( 'items' => array( $a, $b ) ) ), 'Backoff gia\' scaduto: ritardo minimo.' );
		$a['state'] = 'done';
		$this->assertEqualsWithDelta( 900, wphc_bulk_next_delay( array( 'items' => array( $a, $b ) ) ), 2, 'Solo i pendenti contano.' );
	}

	/**
	 * Riassunto per /health: forma, "stalled" derivato a lettura, fallback senza stall_after_ts.
	 */
	public function test_bulk_summary() {
		$this->assertNull( wphc_bulk_summary() );

		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$summary = wphc_bulk_summary();
		$this->assertSame(
			array( 'job_id', 'status', 'total', 'done', 'updated', 'skipped', 'warnings', 'failed', 'created_at', 'finished_at', 'next_run_at', 'stalled', 'abort_reason' ),
			array_keys( $summary )
		);
		$this->assertFalse( $summary['stalled'] );
		$this->assertNull( $summary['finished_at'] );

		// Stato scritto da una versione precedente: senza stall_after_ts vale next_run_ts + margine.
		$status                = get_option( 'wp_health_check_bulk_status' );
		$status['next_run_ts'] = time() - WP_HEALTH_CHECK_BULK_STALL_GRACE - 10;
		unset( $status['stall_after_ts'] );
		update_option( 'wp_health_check_bulk_status', $status, true );
		$this->assertTrue( wphc_bulk_summary()['stalled'] );

		// Un job terminato non e' mai "stalled".
		$status['status'] = 'completed';
		update_option( 'wp_health_check_bulk_status', $status, true );
		$this->assertFalse( wphc_bulk_summary()['stalled'] );
	}

	// -----------------------------------------------------------------------
	// Cron: pianificazione e ri-armo
	// -----------------------------------------------------------------------

	/**
	 * Hook registrati e pianificazione di eventi SINGOLI senza duplicati.
	 */
	public function test_cron_hooks_and_single_event_scheduling() {
		$this->assertSame( 10, has_action( 'wphc_bulk_update_tick', 'wphc_bulk_run_tick' ) );
		$this->assertSame( 10, has_action( 'init', 'wphc_bulk_maybe_rearm' ) );
		$this->assertSame( 10, has_action( 'wphc_webhook_retry', 'wphc_maybe_retry_webhook' ) );

		$this->clear_plugin_cron();
		$when = wphc_bulk_schedule_tick( 100 );
		$this->assertEqualsWithDelta( time() + 100, $when, 2 );
		$this->assertSame( $when, wp_next_scheduled( 'wphc_bulk_update_tick' ) );
		$this->assertFalse( wp_get_scheduled_event( 'wphc_bulk_update_tick' )->schedule );

		// Un secondo schedule non duplica l'evento (resta il primo).
		wphc_bulk_schedule_tick( 5 );
		$this->assertSame( $when, wp_next_scheduled( 'wphc_bulk_update_tick' ) );

		// Ritardo di default: 30 s.
		wphc_bulk_unschedule_ticks();
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );
		$this->assertEqualsWithDelta( time() + 30, wphc_bulk_schedule_tick(), 2 );
	}

	/**
	 * Ri-armo pigro a init: job attivo, nessun tick pianificato, giro scaduto -> ri-pianifica subito e prende
	 * un lock di un minuto; negli altri casi non fa nulla.
	 */
	public function test_maybe_rearm() {
		// Nessun riassunto: niente.
		wphc_bulk_maybe_rearm();
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );

		$this->enqueue_bulk( array( 'plugins' => array( self::PLUGIN ) ) );
		$this->mutate_job(
			static function ( &$job ) {
				$job['status']      = 'running';
				$job['next_run_ts'] = time() - 10;
			}
		);

		// Tick gia' pianificato: niente.
		wphc_bulk_maybe_rearm();
		$this->assertFalse( get_transient( 'wphc_bulk_rearm_lock' ) );

		// Tick perso: ri-armo.
		$this->clear_plugin_cron();
		wphc_bulk_maybe_rearm();
		$this->assertIsInt( wp_next_scheduled( 'wphc_bulk_update_tick' ) );
		$this->assertNotFalse( get_transient( 'wphc_bulk_rearm_lock' ) );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, (int) get_option( '_transient_timeout_wphc_bulk_rearm_lock' ), 5 );

		// Con il lock attivo non ri-arma di nuovo.
		$this->clear_plugin_cron();
		wphc_bulk_maybe_rearm();
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );

		// Giro non ancora scaduto: niente.
		delete_transient( 'wphc_bulk_rearm_lock' );
		$this->mutate_job(
			static function ( &$job ) {
				$job['next_run_ts'] = time() + 500;
			}
		);
		wphc_bulk_maybe_rearm();
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );

		// Job terminato: niente.
		$this->mutate_job(
			static function ( &$job ) {
				$job['status']      = 'completed';
				$job['next_run_ts'] = time() - 10;
			}
		);
		wphc_bulk_maybe_rearm();
		$this->assertFalse( wp_next_scheduled( 'wphc_bulk_update_tick' ) );
	}

	/**
	 * Un job con webhook attivo che si completa nel tick lo marca come inviato.
	 */
	public function test_tick_completion_sends_webhook() {
		update_option( 'wp_health_check_webhook_url', 'https://8.8.8.8/hook' );
		$this->mock_http( array( 'https://8.8.8.8/hook' => $this->http_response( 204, '' ) ) );
		$this->enqueue_bulk( array( 'plugins' => array( 'n/n.php' ) ) );

		$this->run_tick();

		$job = $this->bulk_job();
		$this->assertTrue( $job['webhook']['sent'] );
		$this->assertSame( 204, $job['webhook']['code'] );
		$this->assertNotNull( $job['webhook']['sent_ts'] );
		$this->assertNull( $job['webhook']['last_error'] );
		$this->assertCount( 1, $this->http_urls_containing( '8.8.8.8' ) );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
	}
}
