<?php
/**
 * Test di caratterizzazione del log degli aggiornamenti (tabella {prefix}wphc_update_log, schema v4),
 * di GET /update/log, di POST /update/reactivate, del prune a 90 giorni e dell'audit
 * degli aggiornamenti non passati dall'API (hook di WordPress), plugin 1.38.1.
 *
 * Isolamento: il test svuota la tabella con DELETE (DML, annullato dal rollback di fine test; si
 * controlla che il motore sia InnoDB). Non si esegue MAI dbDelta/DDL nei test: il ramo di
 * installazione/upgrade dello schema non e' coperto (un DDL fa commit implicito e romperebbe
 * l'isolamento del database condiviso); si fissa lo schema installato e il cortocircuito.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-update.php';

/**
 * Log, /update/log, /update/reactivate, prune e audit via hook.
 */
class WPHC_Test_Upd_UpdateLog extends WPHC_Update_Test_Case {

	/**
	 * Svuota la tabella di log (transazionale) dopo aver verificato che sia InnoDB.
	 */
	public function set_up() {
		parent::set_up();

		// Un `.maintenance` residuo in ABSPATH (di un run interrotto) falserebbe i test sulla manutenzione
		// "bloccata": si toglie solo se e' piu' vecchio di qualche minuto, cosi' non si tocca il file di un
		// aggiornamento in corso in un altro processo.
		$maintenance = self::call( 'wphc_maintenance_file_path' );
		clearstatcache( true, $maintenance );
		if ( is_file( $maintenance ) && time() - (int) filemtime( $maintenance ) > 5 * MINUTE_IN_SECONDS ) {
			unlink( $maintenance ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- pulizia di un residuo di test.
		}

		global $wpdb;
		$table  = self::call( 'wphc_update_log_table' );
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $status ) || 'InnoDB' !== $status['Engine'] ) {
			// Un test saltato svuota il gate (il refactoring sembrerebbe verde senza provare nulla): si fallisce.
			$this->fail( 'La tabella di log non e\' InnoDB: il DELETE non sarebbe annullato dal rollback.' );
		}
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->log_baseline = 0;
	}

	/**
	 * Inserisce una riga di log con valori di default e restituisce l'id.
	 *
	 * @param array<string,mixed> $overrides Campi da sovrascrivere.
	 * @return int
	 */
	private function insert_log( array $overrides = array() ) {
		global $wpdb;

		$row = array_merge(
			array(
				'correlation_id' => bin2hex( random_bytes( 8 ) ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'type'           => 'plugin',
				'target'         => 'a/a.php',
				'name'           => 'A',
				'version_from'   => null,
				'version_to'     => null,
				'phase'          => 'completed',
				'message'        => null,
				'ip'             => self::CLIENT_IP,
				'active'         => null,
				'source'         => 'api',
				'actor'          => null,
			),
			$overrides
		);
		$wpdb->insert( self::call( 'wphc_update_log_table' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Richiesta GET /update/log.
	 *
	 * @param array<string,mixed> $query Parametri.
	 * @return array Dati della risposta (200).
	 */
	private function get_log( array $query = array() ) {
		$response = $this->upd( 'GET', '/update/log', null, $query );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	// -----------------------------------------------------------------------
	// Schema v4
	// -----------------------------------------------------------------------

	/**
	 * Costanti di schema e di conservazione.
	 */
	public function test_log_constants() {
		$this->assertSame( '4', WP_HEALTH_CHECK_DB_VERSION );
		$this->assertSame( 90, WP_HEALTH_CHECK_LOG_RETENTION_DAYS );
		$this->assertSame( 300, WP_HEALTH_CHECK_UPDATE_LOCK_TTL );
		$this->assertSame( 'wphc_update_log', substr( self::call( 'wphc_update_log_table' ), -15 ) );
	}

	/**
	 * Lo schema installato: option di versione a '4', colonne con i loro tipi, indici.
	 */
	public function test_installed_schema_is_v4() {
		global $wpdb;
		$table = self::call( 'wphc_update_log_table' );

		$this->assertSame( '4', get_option( 'wp_health_check_db_version' ) );

		$columns = array();
		foreach ( (array) $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ) as $col ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$columns[ $col['Field'] ] = strtolower( $col['Type'] ) . '|' . $col['Null'] . '|' . ( null === $col['Default'] ? 'NULL' : $col['Default'] ) . '|' . $col['Key'];
		}
		$this->assertSame(
			array(
				'id'             => 'bigint(20) unsigned|NO|NULL|PRI',
				'correlation_id' => 'char(16)|NO|NULL|MUL',
				'created_at'     => 'datetime|NO|NULL|',
				'type'           => 'varchar(10)|NO|NULL|MUL',
				'target'         => 'varchar(191)|NO|NULL|',
				'name'           => 'varchar(191)|NO|NULL|',
				'version_from'   => 'varchar(32)|YES|NULL|',
				'version_to'     => 'varchar(32)|YES|NULL|',
				'phase'          => 'varchar(32)|NO|NULL|',
				'message'        => 'varchar(255)|YES|NULL|',
				'ip'             => 'varchar(45)|YES|NULL|',
				'active'         => 'tinyint(1)|YES|NULL|',
				'source'         => "varchar(10)|NO|api|MUL",
				'actor'          => 'varchar(60)|YES|NULL|',
			),
			$columns
		);

		$indexes = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) as $idx ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$indexes[ $idx['Key_name'] ][ (int) $idx['Seq_in_index'] ] = $idx['Column_name'];
		}
		$this->assertSame(
			array(
				'PRIMARY'           => array( 1 => 'id' ),
				'correlation_id'    => array( 1 => 'correlation_id' ),
				'type_created_at'   => array(
					1 => 'type',
					2 => 'created_at',
				),
				'source_created_at' => array(
					1 => 'source',
					2 => 'created_at',
				),
			),
			$indexes
		);
	}

	/**
	 * Con la versione dello schema aggiornata il controllo e' O(1): nessuna query, niente DDL.
	 */
	public function test_schema_install_is_short_circuited_when_version_matches() {
		$queries = array();
		add_filter(
			'query',
			static function ( $sql ) use ( &$queries ) {
				$queries[] = $sql;

				return $sql;
			}
		);
		self::call( 'wphc_maybe_install_update_log_schema' );

		$this->assertSame( array(), $queries );
		$this->assertSame( '4', get_option( 'wp_health_check_db_version' ) );
	}

	/**
	 * Il controllo dello schema e' agganciato a init; la option di versione e' autoloaded.
	 */
	public function test_schema_check_is_hooked_on_init() {
		global $wpdb;

		$this->assert_hooked( 'init', self::cb( 'wphc_maybe_install_update_log_schema' ), 10, 1 );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wp_health_check_db_version' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotContains( $autoload, array( 'no', 'off', 'auto-off' ) );
	}

	// -----------------------------------------------------------------------
	// Scrittura delle righe
	// -----------------------------------------------------------------------

	/**
	 * wphc_log_update_row(): campi, mappatura di active (null/0/1), default source 'api', IP e data UTC.
	 */
	public function test_log_update_row_fields() {
		$id = self::call( 'wphc_log_update_row', 'aaaaaaaaaaaaaaaa', 'plugin', 'a/a.php', 'Plugin A', '1.0', '2.0', 'requested', null, true );
		$this->assertGreaterThan( 0, $id );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $id, $rows[0]['id'] );
		$this->assertSame( 'aaaaaaaaaaaaaaaa', $rows[0]['correlation_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows[0]['created_at'] );
		$this->assertEqualsWithDelta( time(), strtotime( $rows[0]['created_at'] . ' UTC' ), 5, 'created_at e\' in UTC.' );
		$this->assertSame( array( 'plugin', 'a/a.php', 'Plugin A', '1.0', '2.0', 'requested' ), array( $rows[0]['type'], $rows[0]['target'], $rows[0]['name'], $rows[0]['version_from'], $rows[0]['version_to'], $rows[0]['phase'] ) );
		$this->assertNull( $rows[0]['message'] );
		$this->assertSame( self::CLIENT_IP, $rows[0]['ip'] );
		$this->assertSame( '1', $rows[0]['active'] );
		$this->assertSame( 'api', $rows[0]['source'] );
		$this->assertNull( $rows[0]['actor'] );

		self::call( 'wphc_log_update_row', 'bbbbbbbbbbbbbbbb', 'theme', 't', 'T', null, null, 'completed', 'msg', false, 'cron', 'admin' );
		self::call( 'wphc_log_update_row', 'cccccccccccccccc', 'core', 'core', 'WordPress', null, null, 'completed' );
		$rows = $this->log_rows();
		$this->assertSame( '0', $rows[1]['active'], 'false diventa 0.' );
		$this->assertSame( array( 'msg', 'cron', 'admin' ), array( $rows[1]['message'], $rows[1]['source'], $rows[1]['actor'] ) );
		$this->assertNull( $rows[2]['active'], 'null resta NULL.' );
		$this->assertNull( $rows[2]['version_from'] );
	}

	/**
	 * Correlation id: 16 caratteri esadecimali, diversi a ogni chiamata.
	 */
	public function test_correlation_id_format() {
		$a = self::call( 'wphc_generate_correlation_id' );
		$b = self::call( 'wphc_generate_correlation_id' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a );
		$this->assertNotSame( $a, $b );
	}

	/**
	 * wphc_record_last_update(): forma dell'option (chiavi, ISO 8601 UTC), origine di default 'api'.
	 */
	public function test_record_last_update_shape() {
		self::call( 'wphc_record_last_update', 'plugin', 'a/a.php', 'completed' );
		$last = get_option( 'wp_health_check_last_update' );

		$this->assertSame( array( 'type', 'target', 'phase', 'source', 'at' ), array_keys( $last ) );
		$this->assertSame( 'api', $last['source'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $last['at'] );

		self::call( 'wphc_record_last_update', 'core', 'core', 'failed', 'cron' );
		$this->assertSame( 'cron', get_option( 'wp_health_check_last_update' )['source'] );
	}

	// -----------------------------------------------------------------------
	// GET /update/log
	// -----------------------------------------------------------------------

	/**
	 * Tabella vuota: forma {site, count, total, entries}.
	 */
	public function test_log_route_empty() {
		$before   = $this->plugin_options();
		$response = $this->upd( 'GET', '/update/log' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'site'    => $this->own_site_url(),
				'count'   => 0,
				'total'   => 0,
				'entries' => array(),
			),
			$response->get_data()
		);
		$this->assert_update_golden( 'update/log/empty', $response, $before );
	}

	/**
	 * Forma di una entry, ordine (id decrescente) e golden.
	 */
	public function test_log_route_entry_shape_and_order() {
		$this->insert_log(
			array(
				'type'         => 'plugin',
				'target'       => 'x/x.php',
				'name'         => 'X',
				'version_from' => '1.0',
				'version_to'   => '2.0',
				'phase'        => 'requested',
				'active'       => 1,
			)
		);
		$this->insert_log(
			array(
				'type'    => 'core',
				'target'  => 'core',
				'name'    => 'WordPress',
				'phase'   => 'failed',
				'message' => 'errore',
				'source'  => 'cron',
				'actor'   => 'admin',
				'active'  => 0,
			)
		);

		$before   = $this->plugin_options();
		$response = $this->upd( 'GET', '/update/log' );
		$data     = $response->get_data();

		$this->assertSame( 2, $data['count'] );
		$this->assertSame( 2, $data['total'] );
		// Il piu' recente per primo.
		$this->assertSame( 'core', $data['entries'][0]['type'] );
		$this->assertSame( 'plugin', $data['entries'][1]['type'] );
		$this->assertSame(
			array( 'id', 'correlation_id', 'created_at', 'type', 'target', 'name', 'version_from', 'version_to', 'phase', 'message', 'ip', 'active', 'source', 'actor' ),
			array_keys( $data['entries'][0] )
		);
		$this->assertIsInt( $data['entries'][0]['id'] );
		$this->assertFalse( $data['entries'][0]['active'], 'active=0 diventa false.' );
		$this->assertTrue( $data['entries'][1]['active'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['entries'][0]['created_at'] );
		$this->assertSame( 'cron', $data['entries'][0]['source'] );
		$this->assertSame( 'admin', $data['entries'][0]['actor'] );
		$this->assertNull( $data['entries'][1]['actor'] );
		$this->assertNull( $data['entries'][1]['message'] );
		$this->assert_update_golden( 'update/log/entries', $response, $before );
	}

	/**
	 * Filtri type e source: valori ammessi in AND; valori sconosciuti ignorati (nessun filtro).
	 */
	public function test_log_route_filters() {
		$this->insert_log( array( 'type' => 'plugin', 'source' => 'api' ) );
		$this->insert_log( array( 'type' => 'plugin', 'source' => 'cron' ) );
		$this->insert_log( array( 'type' => 'theme', 'source' => 'cron' ) );
		$this->insert_log( array( 'type' => 'core', 'source' => 'wp-admin' ) );
		$this->insert_log( array( 'type' => 'token', 'source' => 'api' ) );
		$this->insert_log( array( 'type' => 'login', 'source' => 'wp-cli' ) );

		$this->assertSame( 6, $this->get_log()['total'] );
		$this->assertSame( 2, $this->get_log( array( 'type' => 'plugin' ) )['total'] );
		$this->assertSame( 1, $this->get_log( array( 'type' => 'token' ) )['total'] );
		$this->assertSame( 1, $this->get_log( array( 'type' => 'login' ) )['total'] );
		$this->assertSame( 2, $this->get_log( array( 'source' => 'cron' ) )['total'] );
		$this->assertSame( 1, $this->get_log( array( 'source' => 'wp-cli' ) )['total'] );
		$this->assertSame( 1, $this->get_log( array( 'type' => 'plugin', 'source' => 'cron' ) )['total'] );
		$this->assertSame( 0, $this->get_log( array( 'type' => 'theme', 'source' => 'api' ) )['total'] );
		// Valori fuori whitelist: ignorati, come se il filtro non ci fosse.
		$this->assertSame( 6, $this->get_log( array( 'type' => 'bogus' ) )['total'] );
		$this->assertSame( 6, $this->get_log( array( 'source' => 'bogus' ) )['total'] );
		$this->assertSame( 6, $this->get_log( array( 'type' => 'PLUGIN' ) )['total'], 'Il confronto e\' case sensitive.' );
	}

	/**
	 * Paginazione: limite di default 50, massimo 200, valori non validi ricadono sul default; offset non negativo;
	 * "total" non dipende dalla pagina.
	 */
	public function test_log_route_pagination() {
		for ( $i = 1; $i <= 205; $i++ ) {
			$this->insert_log( array( 'target' => 'p' . $i . '/p.php' ) );
		}

		$data = $this->get_log();
		$this->assertSame( 50, $data['count'], 'Default 50.' );
		$this->assertSame( 205, $data['total'] );
		$this->assertSame( 'p205/p.php', $data['entries'][0]['target'] );

		$this->assertSame( 10, $this->get_log( array( 'limit' => 10 ) )['count'] );
		$this->assertSame( 200, $this->get_log( array( 'limit' => 500 ) )['count'], 'Massimo 200.' );
		$this->assertSame( 50, $this->get_log( array( 'limit' => 0 ) )['count'] );
		$this->assertSame( 50, $this->get_log( array( 'limit' => -5 ) )['count'], 'Un limite negativo cade sul default.' );
		$this->assertSame( 50, $this->get_log( array( 'limit' => 'abc' ) )['count'] );

		$page = $this->get_log(
			array(
				'limit'  => 5,
				'offset' => 200,
			)
		);
		$this->assertSame( 5, $page['count'] );
		$this->assertSame( 'p5/p.php', $page['entries'][0]['target'] );
		$this->assertSame( 205, $page['total'] );

		$this->assertSame( 0, $this->get_log( array( 'offset' => 1000 ) )['count'] );
		$neg = $this->get_log(
			array(
				'limit'  => 3,
				'offset' => -10,
			)
		);
		$this->assertSame( 'p205/p.php', $neg['entries'][0]['target'], 'Un offset negativo vale 0.' );
	}

	/**
	 * Righe con source vuota (colonna non popolata): in uscita ricade su 'api'.
	 */
	public function test_log_entry_empty_source_falls_back_to_api() {
		$this->insert_log( array( 'source' => '' ) );

		$this->assertSame( 'api', $this->get_log()['entries'][0]['source'] );
	}

	/**
	 * La lettura del log e' accessibile anche a kill-switch spento (sola lettura) e registra l'accesso.
	 */
	public function test_log_route_works_with_kill_switch_off() {
		$this->disable_api_updates();
		$this->insert_log();

		$this->assertSame( 1, $this->get_log()['total'] );
		$this->assertNotEmpty( get_option( 'wp_health_check_last_request_at' ) );
	}

	// -----------------------------------------------------------------------
	// Prune a 90 giorni
	// -----------------------------------------------------------------------

	/**
	 * Cancella le righe piu' vecchie di 90 giorni e imposta il gate giornaliero; con il gate attivo non fa nulla.
	 */
	public function test_prune_deletes_rows_older_than_retention_once_a_day() {
		$this->insert_log( array( 'target' => 'old/old.php', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ) );
		$this->insert_log( array( 'target' => 'edge-old/x.php', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS - HOUR_IN_SECONDS ) ) );
		$this->insert_log( array( 'target' => 'edge-new/x.php', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ) );
		$this->insert_log( array( 'target' => 'new/new.php', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 89 * DAY_IN_SECONDS ) ) );

		$this->assertFalse( get_transient( 'wp_health_check_log_pruned_at' ) );
		self::call( 'wphc_maybe_prune_update_log' );

		$targets = array_column( $this->log_rows(), 'target' );
		sort( $targets );
		$this->assertSame( array( 'edge-new/x.php', 'new/new.php' ), $targets );

		// Gate di un giorno.
		$this->assertNotFalse( get_transient( 'wp_health_check_log_pruned_at' ) );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, (int) get_option( '_transient_timeout_wp_health_check_log_pruned_at' ), 5 );
		$this->insert_log( array( 'target' => 'old2/old.php', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ) );
		self::call( 'wphc_maybe_prune_update_log' );
		$this->assertContains( 'old2/old.php', array_column( $this->log_rows(), 'target' ), 'Con il gate attivo il prune non riparte.' );

		// Gate scaduto: riparte.
		delete_transient( 'wp_health_check_log_pruned_at' );
		self::call( 'wphc_maybe_prune_update_log' );
		$this->assertNotContains( 'old2/old.php', array_column( $this->log_rows(), 'target' ) );
	}

	// -----------------------------------------------------------------------
	// Lock e manutenzione orfana
	// -----------------------------------------------------------------------

	/**
	 * Il preambolo acquisisce il lock con TTL di 300 s e wphc_release_update_lock() lo libera.
	 */
	public function test_preflight_lock_ttl_and_release() {
		$this->assertTrue( self::call( 'wphc_update_preflight', true ) );

		$this->assertNotFalse( get_transient( 'wp_health_check_update_lock' ) );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( '_transient_timeout_wp_health_check_update_lock' ), 5 );
		// Un secondo preambolo trova il lock.
		$second = self::call( 'wphc_update_preflight', true );
		$this->assertSame( array( 'result' => 'locked', 'http' => 409 ), $second );

		self::call( 'wphc_release_update_lock' );
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ) );
	}

	/**
	 * Un file .maintenance orfano (piu' vecchio di 600 s) viene rimosso, uno recente no.
	 */
	public function test_stale_maintenance_file_is_cleared_but_fresh_one_is_kept() {
		$file = self::call( 'wphc_maintenance_file_path' );
		$this->assertFileDoesNotExist( $file, 'Il container non deve essere in manutenzione.' );

		try {
			file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			self::call( 'wphc_clear_stale_maintenance' );
			$this->assertFileExists( $file, 'Recente: resta.' );

			touch( $file, time() - 700 );
			clearstatcache();
			self::call( 'wphc_clear_stale_maintenance' );
			$this->assertFileDoesNotExist( $file, 'Piu\' vecchio di 600 s: rimosso.' );
		} finally {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	// -----------------------------------------------------------------------
	// Candidati alla riattivazione (derivati dal solo log)
	// -----------------------------------------------------------------------

	/**
	 * Conta solo l'ultima riga NON nulla di 'active' per ogni plugin: active=1 candidato, active=0 chiude,
	 * le righe con active NULL (es. reactivation_failed) vengono ignorate.
	 */
	public function test_reactivation_candidates_use_latest_non_null_row() {
		// A: attivo -> candidato.
		$this->insert_log( array( 'target' => 'a/a.php', 'name' => 'A', 'active' => 1, 'phase' => 'requested' ) );
		// B: attivo poi disattivato volontariamente -> non candidato.
		$this->insert_log( array( 'target' => 'b/b.php', 'name' => 'B', 'active' => 1 ) );
		$this->insert_log( array( 'target' => 'b/b.php', 'name' => 'B', 'active' => 0, 'phase' => 'deactivated' ) );
		// C: attivo, poi una riga con active NULL (riattivazione fallita) -> resta candidato.
		$this->insert_log( array( 'target' => 'c/c.php', 'name' => 'C', 'active' => 1 ) );
		$this->insert_log( array( 'target' => 'c/c.php', 'name' => 'C', 'active' => null, 'phase' => 'reactivation_failed' ) );
		// D: un tema attivo non conta (solo type plugin).
		$this->insert_log( array( 'type' => 'theme', 'target' => 'd', 'name' => 'D', 'active' => 1 ) );

		$targets = array_column( self::call( 'wphc_get_reactivation_candidates' ), 'target' );
		sort( $targets );
		$this->assertSame( array( 'a/a.php', 'c/c.php' ), $targets );
		$this->assertSame( array( 'target', 'name' ), array_keys( self::call( 'wphc_get_reactivation_candidates' )[0] ) );
	}

	// -----------------------------------------------------------------------
	// POST /update/reactivate
	// -----------------------------------------------------------------------

	/**
	 * Dry-run: elenca le discrepanze senza riattivare, senza preambolo (kill-switch e lock non contano),
	 * senza scrivere nel log; la risposta ha la forma completa.
	 */
	public function test_reactivate_dry_run() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );
		$this->insert_log( array( 'target' => self::FIXTURE_C_FILE, 'name' => 'WPHC Fixture', 'active' => 1 ) );
		$this->disable_api_updates();
		set_transient( 'wp_health_check_update_lock', 1, 300 );

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/reactivate', null, array( 'check' => '1' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'site', 'generated_at', 'check', 'discrepancies', 'reactivated', 'failed', 'results' ), array_keys( $data ) );
		$this->assertTrue( $data['check'] );
		$this->assertSame( 1, $data['discrepancies'] );
		$this->assertSame( 0, $data['reactivated'] );
		$this->assertSame( 0, $data['failed'] );
		$this->assertSame(
			array(
				array(
					'target'           => self::FIXTURE_C_FILE,
					'name'             => 'WPHC Fixture',
					'was_active'       => true,
					'currently_active' => false,
				),
			),
			$data['results']
		);
		$this->assertCount( 1, $this->log_rows(), 'Nessuna riga aggiunta dal dry-run.' );
		$this->assertFalse( is_plugin_active( self::FIXTURE_C_FILE ) );
		$this->assertSame( 1, (int) get_transient( 'wp_health_check_update_lock' ), 'Il lock altrui non viene toccato.' );
		$this->assert_update_golden( 'update/reactivate/check', $response, $before );
	}

	/**
	 * Nessuna discrepanza (plugin gia' attivo o nessun candidato): risposta a zero.
	 */
	public function test_reactivate_without_discrepancies() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );
		update_option( 'active_plugins', array( self::FIXTURE_C_FILE ) );
		$this->insert_log( array( 'target' => self::FIXTURE_C_FILE, 'active' => 1 ) );

		$data = $this->upd( 'POST', '/update/reactivate' )->get_data();

		$this->assertSame( 0, $data['discrepancies'] );
		$this->assertSame( array(), $data['results'] );
		$this->assertFalse( $data['check'] );
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ) );
	}

	/**
	 * Riattivazione reale: il plugin torna attivo, riga 'reactivated' (active=1), lock rilasciato.
	 */
	public function test_reactivate_success() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );
		$this->insert_log( array( 'target' => self::FIXTURE_C_FILE, 'name' => 'WPHC Fixture', 'active' => 1 ) );

		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/reactivate' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['discrepancies'] );
		$this->assertSame( 1, $data['reactivated'] );
		$this->assertSame( 0, $data['failed'] );
		$this->assertTrue( $data['results'][0]['reactivated'] );
		$this->assertTrue( $data['results'][0]['currently_active'] );
		$this->assertTrue( is_plugin_active( self::FIXTURE_C_FILE ) );

		$rows = $this->log_rows( "phase <> 'completed'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'reactivated', $rows[0]['phase'] );
		$this->assertSame( '1', $rows[0]['active'] );
		$this->assertSame( '1.0.0', $rows[0]['version_from'] );
		$this->assertNull( $rows[0]['version_to'] );
		$this->assertSame( 'api', $rows[0]['source'] );
		$this->assertSame( (int) $rows[0]['id'], $data['results'][0]['log_id'] );
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ) );
		$this->assert_update_golden( 'update/reactivate/success', $response, $before );
	}

	/**
	 * Riattivazione fallita: riga 'reactivation_failed' con active NULL (cosi' il candidato resta), conteggio failed.
	 */
	public function test_reactivate_failure() {
		// Un plugin che stampa output quando viene incluso: activate_plugin() risponde con un errore.
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_D_DIR, "echo 'output inatteso';" );
		$this->insert_log( array( 'target' => self::FIXTURE_D_FILE, 'name' => 'WPHC Fixture', 'active' => 1 ) );

		$data = $this->upd( 'POST', '/update/reactivate' )->get_data();

		$this->assertSame( 1, $data['discrepancies'] );
		$this->assertSame( 0, $data['reactivated'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertFalse( $data['results'][0]['reactivated'] );
		$this->assertFalse( $data['results'][0]['currently_active'] );
		$this->assertNotSame( '', $data['results'][0]['detail'] );

		$rows = $this->log_rows( "phase = 'reactivation_failed'" );
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]['active'] );
		$this->assertSame( $data['results'][0]['detail'], $rows[0]['message'] );
		// Il candidato resta tale (l'ultima riga non nulla e' ancora active=1).
		$this->assertContains( self::FIXTURE_D_FILE, array_column( self::call( 'wphc_get_reactivation_candidates' ), 'target' ) );
	}

	/**
	 * Plugin sparito dal disco: riga 'deleted' (active=0) e nessun risultato; alla chiamata successiva non e' piu' candidato.
	 */
	public function test_reactivate_closes_candidates_whose_files_are_gone() {
		$this->insert_log( array( 'target' => 'sparito/sparito.php', 'name' => 'Sparito', 'active' => 1 ) );

		$data = $this->upd( 'POST', '/update/reactivate' )->get_data();
		$this->assertSame( 0, $data['discrepancies'] );
		$this->assertSame( array(), $data['results'] );

		$rows = $this->log_rows( "phase = 'deleted'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'sparito/sparito.php', $rows[0]['target'] );
		$this->assertSame( '0', $rows[0]['active'] );
		$this->assertSame( array(), self::call( 'wphc_get_reactivation_candidates' ) );

		// Il dry-run, invece, non scrive la riga 'deleted' (non chiude i candidati).
		$this->insert_log( array( 'target' => 'sparito2/s.php', 'name' => 'S2', 'active' => 1 ) );
		$before_rows = count( $this->log_rows() );
		$this->upd( 'POST', '/update/reactivate', null, array( 'check' => '1' ) );
		$this->assertCount( $before_rows, $this->log_rows() );
	}

	/**
	 * Kill-switch e lock: 403 e 409 con gli stessi codici delle altre rotte di scrittura.
	 */
	public function test_reactivate_kill_switch_and_lock() {
		$this->disable_api_updates();
		$before   = $this->plugin_options();
		$response = $this->upd( 'POST', '/update/reactivate' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_updates_disabled', $this->error_code( $response ) );
		$this->assert_update_golden( 'update/reactivate/disabled', $response, $before );

		update_option( 'wp_health_check_updates_enabled', 1 );
		set_transient( 'wp_health_check_update_lock', 1, 300 );
		$response = $this->upd( 'POST', '/update/reactivate' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wphc_update_locked', $this->error_code( $response ) );
	}

	// -----------------------------------------------------------------------
	// Audit degli aggiornamenti avviati fuori dall'API (hook di WordPress)
	// -----------------------------------------------------------------------

	/**
	 * Fa partire l'hook upgrader_process_complete con un contesto.
	 *
	 * @param array $hook_extra Contesto.
	 */
	private function fire_upgrader_complete( array $hook_extra ) {
		do_action( 'upgrader_process_complete', new stdClass(), $hook_extra );
	}

	/**
	 * Update di un plugin da wp-admin: una sola riga 'completed' con versione precedente (snapshot), nome,
	 * stato attivo, origine 'wp-admin', actor e last_update.
	 */
	public function test_audit_plugin_update_from_wp_admin() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'auditadmin' ) );
		wp_set_current_user( $admin_id );
		update_option( 'active_plugins', array( self::FIXTURE_C_FILE ) );

		// Prima dell'installazione il core chiede lo snapshot (filtro upgrader_pre_install).
		$this->assertTrue( apply_filters( 'upgrader_pre_install', true, array( 'plugin' => self::FIXTURE_C_FILE ) ), 'Il filtro restituisce $response invariato.' );
		// Il file cambia versione.
		$this->install_fixture_plugin( '2.0.0', self::FIXTURE_C_DIR );
		$this->fire_upgrader_complete(
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => self::FIXTURE_C_FILE,
			)
		);

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'completed', $rows[0]['phase'] );
		$this->assertSame( self::FIXTURE_C_FILE, $rows[0]['target'] );
		$this->assertSame( 'WPHC Fixture', $rows[0]['name'] );
		$this->assertSame( '1.0.0', $rows[0]['version_from'] );
		$this->assertSame( '2.0.0', $rows[0]['version_to'] );
		$this->assertSame( '1', $rows[0]['active'] );
		$this->assertSame( 'wp-admin', $rows[0]['source'] );
		$this->assertSame( 'auditadmin', $rows[0]['actor'] );
		$this->assertNull( $rows[0]['message'] );
		$this->assertSame( 'wp-admin', get_option( 'wp_health_check_last_update' )['source'] );
		// Lo snapshot e' consumato.
		$this->assertArrayNotHasKey( self::FIXTURE_C_FILE, $GLOBALS['wphc_version_snapshots']['plugin'] );
	}

	/**
	 * Update in blocco di piu' plugin e temi: una riga per elemento; temi con active NULL; contesto cron -> source 'cron' senza actor.
	 */
	public function test_audit_bulk_updates_and_cron_source() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->fire_upgrader_complete(
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( self::FIXTURE_C_FILE, 'inesistente/inesistente.php' ),
				'bulk'    => true,
			)
		);
		$this->fire_upgrader_complete(
			array(
				'action' => 'update',
				'type'   => 'theme',
				'themes' => array( 'twentytwentyfive' ),
			)
		);
		$this->fire_upgrader_complete(
			array(
				'action' => 'update',
				'type'   => 'theme',
				'theme'  => 'tema-inesistente',
			)
		);

		$rows = $this->log_rows();
		$this->assertCount( 4, $rows );
		$this->assertSame( array( self::FIXTURE_C_FILE, 'inesistente/inesistente.php', 'twentytwentyfive', 'tema-inesistente' ), array_column( $rows, 'target' ) );
		// Plugin inesistente: nome = plugin file, versione NULL; tema inesistente: nome = stylesheet.
		$this->assertSame( 'inesistente/inesistente.php', $rows[1]['name'] );
		$this->assertNull( $rows[1]['version_to'] );
		$this->assertSame( '0', $rows[0]['active'] );
		$this->assertNull( $rows[2]['active'], 'Per i temi active e\' NULL.' );
		$this->assertSame( 'tema-inesistente', $rows[3]['name'] );
		$this->assertNull( $rows[3]['version_to'] );
		foreach ( $rows as $row ) {
			$this->assertSame( 'cron', $row['source'] );
			$this->assertNull( $row['actor'] );
			$this->assertSame( 'completed', $row['phase'] );
			$this->assertNull( $row['version_from'], 'Senza snapshot la versione precedente e\' NULL.' );
		}
	}

	/**
	 * Contesti che NON producono righe: azione diversa da update, tipo diverso, campi mancanti, nessun elemento,
	 * e update avviati dall'API (flag anti doppio log).
	 */
	public function test_audit_ignores_irrelevant_contexts() {
		$this->fire_upgrader_complete( array( 'action' => 'install', 'type' => 'plugin', 'plugin' => 'a/a.php' ) );
		$this->fire_upgrader_complete( array( 'action' => 'update', 'type' => 'core' ) );
		$this->fire_upgrader_complete( array( 'action' => 'update', 'type' => 'translation', 'translations' => array() ) );
		$this->fire_upgrader_complete( array( 'type' => 'plugin', 'plugin' => 'a/a.php' ) );
		$this->fire_upgrader_complete( array( 'action' => 'update', 'type' => 'plugin' ) );
		$this->fire_upgrader_complete( array() );
		$this->assertSame( array(), $this->log_rows() );

		$GLOBALS['wphc_api_update_in_progress'] = true;
		$this->fire_upgrader_complete( array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'a/a.php' ) );
		do_action( 'deactivated_plugin', 'a/a.php', false );
		unset( $GLOBALS['wphc_api_update_in_progress'] );
		$this->assertSame( array(), $this->log_rows(), 'Durante un update dell\'API non si logga ne\' l\'update ne\' la disattivazione.' );
	}

	/**
	 * Disattivazione di un plugin: riga 'deactivated' (active=0) che chiude il candidato alla riattivazione.
	 */
	public function test_audit_plugin_deactivation_closes_reactivation_candidate() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );
		$this->insert_log( array( 'target' => self::FIXTURE_C_FILE, 'name' => 'WPHC Fixture', 'active' => 1 ) );
		$this->assertCount( 1, self::call( 'wphc_get_reactivation_candidates' ) );

		do_action( 'deactivated_plugin', self::FIXTURE_C_FILE, false );

		$rows = $this->log_rows( "phase = 'deactivated'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'WPHC Fixture', $rows[0]['name'] );
		$this->assertSame( '1.0.0', $rows[0]['version_from'] );
		$this->assertNull( $rows[0]['version_to'] );
		$this->assertSame( '0', $rows[0]['active'] );
		$this->assertSame( 'wp-admin', $rows[0]['source'] );
		$this->assertSame( array(), self::call( 'wphc_get_reactivation_candidates' ) );

		// Plugin illeggibile: il nome ricade sul plugin file e la versione e' NULL.
		do_action( 'deactivated_plugin', 'non/esiste.php', false );
		$last = $this->log_rows( "target = 'non/esiste.php'" );
		$this->assertSame( 'non/esiste.php', $last[0]['name'] );
		$this->assertNull( $last[0]['version_from'] );
	}

	/**
	 * Cancellazione di un plugin: nome e versione dallo snapshot preso PRIMA (a 'deleted_plugin' il file non c'e' piu');
	 * se la cancellazione non riesce non si logga ma lo snapshot e' ripulito.
	 */
	public function test_audit_plugin_deletion() {
		$this->install_fixture_plugin( '1.0.0', self::FIXTURE_C_DIR );

		do_action( 'delete_plugin', self::FIXTURE_C_FILE );
		$this->assertArrayHasKey( self::FIXTURE_C_FILE, $GLOBALS['wphc_deleting_plugins'] );
		do_action( 'deleted_plugin', self::FIXTURE_C_FILE, true );

		$rows = $this->log_rows( "phase = 'deleted'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'WPHC Fixture', $rows[0]['name'] );
		$this->assertSame( '1.0.0', $rows[0]['version_from'] );
		$this->assertSame( '0', $rows[0]['active'] );
		$this->assertArrayNotHasKey( self::FIXTURE_C_FILE, $GLOBALS['wphc_deleting_plugins'] );

		// Cancellazione non riuscita: nessuna riga.
		do_action( 'delete_plugin', self::FIXTURE_C_FILE );
		do_action( 'deleted_plugin', self::FIXTURE_C_FILE, false );
		$this->assertCount( 1, $this->log_rows( "phase = 'deleted'" ) );
		$this->assertArrayNotHasKey( self::FIXTURE_C_FILE, $GLOBALS['wphc_deleting_plugins'] );

		// Senza snapshot (file illeggibile): nome = plugin file, versione NULL.
		do_action( 'deleted_plugin', 'altro/altro.php', true );
		$row = $this->log_rows( "target = 'altro/altro.php'" );
		$this->assertSame( 'altro/altro.php', $row[0]['name'] );
		$this->assertNull( $row[0]['version_from'] );
	}

	/**
	 * Cambio di versione del core rilevato a init: la prima volta "semina" l'option senza loggare; una divergenza
	 * produce una riga 'completed' del core (versione vista -> corrente) e aggiorna l'option; poi tace.
	 */
	public function test_audit_core_version_change() {
		$now = (string) get_bloginfo( 'version' );

		// Prima esecuzione: semina.
		self::call( 'wphc_maybe_log_core_version_change' );
		$this->assertSame( $now, get_option( 'wp_health_check_core_version' ) );
		$this->assertSame( array(), $this->log_rows() );
		$this->assertFalse( get_option( 'wp_health_check_last_update' ) );

		// Divergenza.
		update_option( 'wp_health_check_core_version', '6.0.0' );
		self::call( 'wphc_maybe_log_core_version_change' );
		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'core', $rows[0]['type'] );
		$this->assertSame( 'core', $rows[0]['target'] );
		$this->assertSame( 'WordPress', $rows[0]['name'] );
		$this->assertSame( '6.0.0', $rows[0]['version_from'] );
		$this->assertSame( $now, $rows[0]['version_to'] );
		$this->assertSame( 'completed', $rows[0]['phase'] );
		$this->assertNull( $rows[0]['active'] );
		$this->assertSame( 'wp-admin', $rows[0]['source'] );
		$this->assertSame( $now, get_option( 'wp_health_check_core_version' ) );
		$this->assertSame( 'core', get_option( 'wp_health_check_last_update' )['type'] );

		// Stessa versione: nessuna nuova riga.
		self::call( 'wphc_maybe_log_core_version_change' );
		$this->assertCount( 1, $this->log_rows() );
	}

	/**
	 * Origine e autore dell'operazione.
	 */
	public function test_detect_update_source_and_actor() {
		$this->assertSame( 'wp-admin', self::call( 'wphc_detect_update_source' ) );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 'cron', self::call( 'wphc_detect_update_source' ) );
		// Limite: il ramo 'wp-cli' dipende dalla costante WP_CLI, non definibile in-process nei test.
		$this->assertFalse( defined( 'WP_CLI' ) );

		wp_set_current_user( 0 );
		$this->assertNull( self::call( 'wphc_current_actor' ) );
		$user_id = self::factory()->user->create( array( 'user_login' => 'attore' ) );
		wp_set_current_user( $user_id );
		$this->assertSame( 'attore', self::call( 'wphc_current_actor' ) );
	}

	/**
	 * Host del pacchetto ammessi (usato anche dalle rotte di update): senza restrizione tutti.
	 */
	public function test_package_host_allowlist() {
		$this->assertTrue( self::call( 'wphc_is_package_host_allowed', 'https://qualunque.example/x.zip' ) );

		update_option( 'wp_health_check_restrict_official_only', 1 );
		$this->assertTrue( self::call( 'wphc_is_package_host_allowed', 'https://downloads.wordpress.org/x.zip' ) );
		$this->assertTrue( self::call( 'wphc_is_package_host_allowed', 'https://api.wordpress.org/x.zip' ) );
		$this->assertFalse( self::call( 'wphc_is_package_host_allowed', 'https://example.com/x.zip' ) );
		$this->assertFalse( self::call( 'wphc_is_package_host_allowed', '' ) );
	}
}
