<?php
/**
 * Test di caratterizzazione delle rotte di lettura (plugin 1.38.1): GET /health, /ping,
 * /detail/plugins, /detail/theme, /detail/server e /detail/users.
 *
 * Cosa si fissa, per ogni rotta: forma esatta della risposta (chiavi e ordine), cache
 * (transient, TTL, bypass con ?fresh=1), aggiornamento dell'audit "ultimo accesso" e
 * assenza di chiamate costose (WP_Debug_Data::debug_data(), controllo remoto degli update).
 * I test marcati "PRESERVATO" fissano comportamenti poco intuitivi che il refactoring
 * deve lasciare invariati (o cambiare solo con una decisione esplicita).
 *
 * Autenticazione (Bearer, HMAC, throttle) e inventario delle rotte sono gia' coperti da
 * test-auth.php: qui si parte sempre da un sito enrolled con il Bearer corretto.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-read.php';

/**
 * Rotte di sola lettura protette dal Bearer token.
 */
class WPHC_Test_Read_Routes extends WPHC_Read_Test_Case {

	/**
	 * Chiavi di `summary` di /health, nell'ordine in cui il plugin le produce.
	 *
	 * @var string[]
	 */
	const SUMMARY_KEYS = array(
		'wp_version',
		'php_version',
		'php_memory_limit',
		'server_ip',
		'server_ip_is_private',
		'public_ip',
		'plugin_version',
		'plugins_total',
		'plugins_active',
		'plugins_updates',
		'themes_total',
		'themes_updates',
		'theme_name',
		'parent_theme_name',
		'is_multisite',
		'comments_pending',
		'core_update',
		'core_auto_update',
		'core_auto_update_level',
		'core_auto_update_blocked_by',
		'plugins_auto_update_enabled',
		'themes_auto_update_enabled',
		'bulk_update',
		'has_gdpr',
		'has_builder',
		'has_ecommerce',
		'mu_dir_writable',
		'updates_checked_at',
		'updates_via_api_enabled',
		'restrict_official_only',
		'last_update',
		'maintenance_stuck',
		'thumbnail',
		'thumbnail_error',
	);

	/**
	 * Valori di /health che dipendono dall'ambiente (non dal plugin): nei golden diventano `<ENV:tipo>`.
	 *
	 * @var string[]
	 */
	const HEALTH_ENV_PATHS = array(
		'body.summary.wp_version',
		'body.summary.php_version',
		'body.summary.php_memory_limit',
		'body.summary.plugins_total',
		'body.summary.plugins_active',
		'body.summary.themes_total',
		'body.summary.theme_name',
		'body.summary.parent_theme_name',
		'body.summary.mu_dir_writable',
		// wphc_core_auto_update_state() ha una `static $cache`: il primo valore calcolato nel processo
		// PHP resta per tutti i test successivi e non si puo' azzerare. Dipende quindi dall'ordine di
		// esecuzione (e dai filtri del test che chiama per primo /health): non e' deterministico.
		'body.summary.core_auto_update',
		'body.summary.core_auto_update_level',
		'body.summary.core_auto_update_blocked_by',
	);

	/** Timestamp fisso usato per i transient di update (2023-11-14T22:13:20+00:00). */
	const CHECKED_TS = 1700000000;

	// -----------------------------------------------------------------------
	// Utility locali
	// -----------------------------------------------------------------------

	/**
	 * Scrive un transient di update "come il cron" (site transient con last_checked e response).
	 *
	 * @param string   $name     'update_plugins' | 'update_themes'.
	 * @param string[] $response Chiavi (plugin file / stylesheet) con aggiornamento disponibile.
	 */
	private function seed_update_transient( $name, array $response ) {
		// Il core salva le voci dei plugin come oggetti e quelle dei temi come array.
		$items = array();
		foreach ( $response as $key ) {
			$item          = array(
				'new_version' => '99.0',
				'package'     => '',
			);
			$items[ $key ] = ( 'update_themes' === $name ) ? $item : (object) $item;
		}
		set_site_transient(
			$name,
			(object) array(
				'last_checked' => self::CHECKED_TS,
				'response'     => $items,
				'no_update'    => array(),
			)
		);
	}

	/**
	 * Popola la cache di get_plugins() con un elenco finto, deterministico su qualunque ambiente
	 * (get_plugins() restituisce la cache se presente: nessuna scansione del disco).
	 */
	private function seed_fake_plugins() {
		$base = array(
			'PluginURI'   => '',
			'Description' => '',
			'Author'      => '',
			'AuthorURI'   => '',
			'TextDomain'  => '',
			'DomainPath'  => '',
			'Network'     => false,
			'RequiresWP'  => '',
			'RequiresPHP' => '',
			'UpdateURI'   => '',
			'Title'       => '',
			'AuthorName'  => '',
		);
		$list = array(
			'fake-one/fake-one.php' => array_merge(
				$base,
				array(
					'Name'    => 'Fake One',
					'Version' => '1.0.0',
				)
			),
			'fake-two/fake-two.php' => array_merge(
				$base,
				array(
					'Name'    => 'Fake Two',
					'Version' => '2.0.0',
				)
			),
			'single.php'            => array_merge(
				$base,
				array(
					'Name'    => 'Single',
					'Version' => '0.1',
				)
			),
		);
		wp_cache_set( 'plugins', array( '' => $list ), 'plugins' );
		update_option( 'active_plugins', array( 'fake-one/fake-one.php' ) );
	}

	/**
	 * Impedisce ogni chiamata remota non prevista: l'IP del server e' pubblico (nessun ipify) e la
	 * thumbnail e' gia' presente (nessun thum.io). Cosi' /health non esce in rete.
	 */
	private function silence_remote_calls() {
		$_SERVER['SERVER_ADDR'] = '8.8.8.8';
		update_option( 'wp_health_check_thumb', 'https://uploads.invalid/thumb.png', false );
	}

	// -----------------------------------------------------------------------
	// GET /health: forma della risposta
	// -----------------------------------------------------------------------

	/**
	 * Prima chiamata a /health su un sito pulito: chiavi complete di radice e di `summary`,
	 * versione dell'agent in radice E in summary, last_access vuoto, URL delle rotte di dettaglio.
	 * Le chiamate remote sono SOLO ipify (IP pubblico) e thum.io (thumbnail); fallendo, il codice
	 * d'errore della thumbnail e' in summary e il messaggio completo in un'option.
	 */
	public function test_health_response_shape_and_golden() {
		unset( $_SERVER['SERVER_ADDR'] ); // In CLI non c'e' comunque: lo si esplicita.
		$this->enrolled();
		$before = $this->plugin_options();

		$response = $this->get_route( '/health' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		// Radice: `fleet_agent_version` e' in radice (e anche in summary come plugin_version).
		$this->assertSame( array( 'site', 'generated_at', 'fleet_agent_version', 'summary', 'last_access', 'detail_routes' ), array_keys( $data ) );
		$this->assertSame( $this->own_site_url(), $data['site'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $data['fleet_agent_version'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $data['summary']['plugin_version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['generated_at'] );

		// Summary: tutte e sole queste chiavi, in quest'ordine.
		$this->assertSame( self::SUMMARY_KEYS, array_keys( $data['summary'] ) );
		$summary = $data['summary'];
		foreach ( array( 'server_ip_is_private', 'is_multisite', 'core_update', 'core_auto_update', 'plugins_auto_update_enabled', 'themes_auto_update_enabled', 'has_gdpr', 'has_builder', 'has_ecommerce', 'mu_dir_writable', 'updates_via_api_enabled', 'restrict_official_only', 'maintenance_stuck' ) as $flag ) {
			$this->assertIsBool( $summary[ $flag ], $flag );
		}
		foreach ( array( 'plugins_total', 'plugins_active', 'plugins_updates', 'themes_total', 'themes_updates', 'comments_pending' ) as $counter ) {
			$this->assertIsInt( $summary[ $counter ], $counter );
		}
		$this->assertSame( PHP_VERSION, $summary['php_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $summary['wp_version'] );
		$this->assertNull( $summary['server_ip'], 'senza SERVER_ADDR' );
		$this->assertFalse( $summary['server_ip_is_private'] );
		$this->assertNull( $summary['public_ip'], 'ipify bloccato' );
		$this->assertFalse( $summary['is_multisite'] );
		$this->assertNull( $summary['bulk_update'], 'nessun job bulk mai creato' );
		$this->assertTrue( $summary['updates_via_api_enabled'], 'default: aggiornamenti via API abilitati' );
		$this->assertFalse( $summary['restrict_official_only'], 'default: nessuna restrizione' );
		$this->assertNull( $summary['last_update'] );
		$this->assertFalse( $summary['maintenance_stuck'] );
		$this->assertNull( $summary['updates_checked_at'], 'nessun transient update_plugins' );
		$this->assertNull( $summary['thumbnail'] );
		$this->assertSame( 'wphc_thumb_download_failed', $summary['thumbnail_error'], 'solo il CODICE dell\'errore, non il messaggio' );

		// last_access: al primo accesso non c'e' un precedente; enrolled_at assente = null.
		$this->assertSame(
			array(
				'at'          => null,
				'ip'          => null,
				'enrolled_at' => null,
			),
			$data['last_access']
		);
		$this->assertSame(
			array(
				'plugins' => rest_url( 'health-check/v1/detail/plugins' ),
				'theme'   => rest_url( 'health-check/v1/detail/theme' ),
				'server'  => rest_url( 'health-check/v1/detail/server' ),
			),
			$data['detail_routes']
		);

		// Le uniche richieste in uscita: IP pubblico e thumbnail, in questo ordine.
		$this->assertSame(
			array( 'https://api.ipify.org', WP_HEALTH_CHECK_THUMB_SERVICE . get_home_url() ),
			$this->blocked_http_calls
		);

		$this->assert_read_golden( 'read/health-first-call', $response, $before, self::HEALTH_ENV_PATHS );
	}

	/**
	 * I valori di summary derivano dallo stato del sito: conteggi degli aggiornamenti dai transient
	 * del cron, core update da `update_core`, segnali (GDPR, builder, e-commerce) dai plugin attivi,
	 * flag delle option del plugin, riassunto del job bulk e ultimo aggiornamento.
	 */
	public function test_health_summary_values_come_from_site_state() {
		$this->enrolled();
		$this->silence_remote_calls();
		$this->seed_fake_plugins();

		// Aggiornamenti: 1 plugin, 2 temi, 1 core; ultimo controllo a un timestamp noto.
		$this->seed_update_transient( 'update_plugins', array( 'fake-two/fake-two.php' ) );
		$this->seed_update_transient( 'update_themes', array( 'tema-a', 'tema-b' ) );
		set_site_transient(
			'update_core',
			(object) array(
				'last_checked' => self::CHECKED_TS,
				'updates'      => array(
					(object) array(
						'response' => 'upgrade',
						'current'  => '99.0.0',
						'locale'   => 'en_US',
					),
				),
			)
		);

		// Segnali dai plugin attivi (slug = cartella) e opzioni del plugin.
		update_option( 'active_plugins', array( 'fake-one/fake-one.php', 'cookiebot/cookiebot.php', 'elementor/elementor.php', 'woocommerce/woocommerce.php' ) );
		update_option( 'wp_health_check_updates_enabled', '0', false );
		update_option( 'wp_health_check_restrict_official_only', '1', false );
		$last_update = array(
			'type'   => 'plugin',
			'target' => 'fake-two/fake-two.php',
			'phase'  => 'completed',
		);
		update_option( 'wp_health_check_last_update', $last_update, false );

		// Job bulk in corso e "stalled": il riassunto e' derivato a lettura da stall_after_ts.
		update_option(
			'wp_health_check_bulk_status',
			array(
				'job_id'         => 'job-1',
				'status'         => 'running',
				'total'          => 3,
				'done'           => 1,
				'updated'        => 1,
				'skipped'        => 0,
				'warnings'       => 0,
				'failed'         => 0,
				'created_at'     => '2026-01-01T00:00:00+00:00',
				'finished_at'    => null,
				'next_run_ts'    => self::CHECKED_TS,
				'stall_after_ts' => self::CHECKED_TS + 10,
				'abort_reason'   => null,
			),
			false
		);

		$summary = $this->get_route( '/health' )->get_data()['summary'];

		$this->assertSame( 4, $summary['plugins_active'] );
		$this->assertSame( 1, $summary['plugins_updates'] );
		$this->assertSame( 2, $summary['themes_updates'] );
		$this->assertTrue( $summary['core_update'] );
		$this->assertSame( gmdate( 'c', self::CHECKED_TS ), $summary['updates_checked_at'] );
		$this->assertTrue( $summary['has_gdpr'] );
		$this->assertTrue( $summary['has_builder'] );
		$this->assertTrue( $summary['has_ecommerce'] );
		$this->assertFalse( $summary['updates_via_api_enabled'] );
		$this->assertTrue( $summary['restrict_official_only'] );
		$this->assertSame( $last_update, $summary['last_update'] );
		$this->assertSame( 'https://uploads.invalid/thumb.png', $summary['thumbnail'] );
		$this->assertNull( $summary['thumbnail_error'] );
		$this->assertSame( 'job-1', $summary['bulk_update']['job_id'] );
		$this->assertSame( gmdate( 'c', self::CHECKED_TS ), $summary['bulk_update']['next_run_at'] );
		$this->assertTrue( $summary['bulk_update']['stalled'], 'job attivo oltre stall_after_ts' );
		$this->assertSame(
			array( 'job_id', 'status', 'total', 'done', 'updated', 'skipped', 'warnings', 'failed', 'created_at', 'finished_at', 'next_run_at', 'stalled', 'abort_reason' ),
			array_keys( $summary['bulk_update'] )
		);
	}

	/**
	 * Un aggiornamento core "latest" o "development" non conta come update disponibile; senza
	 * update_core il valore e' false. Un job terminale non e' mai "stalled".
	 */
	public function test_health_core_update_and_stalled_edge_cases() {
		$this->enrolled();
		$this->silence_remote_calls();

		foreach ( array( 'latest', 'development' ) as $response_type ) {
			delete_transient( 'wphc_health_cache' );
			set_site_transient(
				'update_core',
				(object) array(
					'last_checked' => self::CHECKED_TS,
					'updates'      => array(
						(object) array(
							'response' => $response_type,
							'current'  => '1.0',
							'locale'   => 'en_US',
						),
					),
				)
			);
			$this->assertFalse( $this->get_route( '/health' )->get_data()['summary']['core_update'], $response_type );
		}

		delete_transient( 'wphc_health_cache' );
		update_option(
			'wp_health_check_bulk_status',
			array(
				'job_id'         => 'job-2',
				'status'         => 'completed',
				'total'          => 1,
				'done'           => 1,
				'updated'        => 1,
				'skipped'        => 0,
				'warnings'       => 0,
				'failed'         => 0,
				'created_at'     => '2026-01-01T00:00:00+00:00',
				'finished_at'    => '2026-01-01T00:01:00+00:00',
				'next_run_ts'    => self::CHECKED_TS,
				'stall_after_ts' => self::CHECKED_TS + 10,
				'abort_reason'   => null,
			),
			false
		);
		$this->assertFalse( $this->get_route( '/health' )->get_data()['summary']['bulk_update']['stalled'] );
	}

	/**
	 * Uno short-circuit `pre_site_transient_update_plugins` (siti che disabilitano i controlli update
	 * fuori da wp-admin) non azzera i conteggi di /health: il plugin lo rimuove durante la lettura e
	 * lo RIPRISTINA subito dopo.
	 */
	public function test_health_neutralizes_and_restores_update_shortcircuit() {
		$this->enrolled();
		$this->silence_remote_calls();
		$this->seed_update_transient( 'update_plugins', array( 'a/a.php', 'b/b.php' ) );

		// Il cortocircuito dichiara "nessun update" (oggetto vuoto): senza il mute il conteggio sarebbe 0.
		$shortcircuit = static function () {
			return (object) array( 'response' => array() );
		};
		add_filter( 'pre_site_transient_update_plugins', $shortcircuit );

		$summary = $this->get_route( '/health' )->get_data()['summary'];

		$this->assertSame( 2, $summary['plugins_updates'], 'il conteggio ignora il cortocircuito' );
		$this->assertSame( 10, has_filter( 'pre_site_transient_update_plugins', $shortcircuit ), 'il filtro e\' ripristinato' );
	}

	// -----------------------------------------------------------------------
	// GET /health: cache, fresh, audit degli accessi
	// -----------------------------------------------------------------------

	/**
	 * Cache da 60 secondi (transient wphc_health_cache): la seconda richiesta e' servita dal
	 * transient senza ricalcolare nulla (nessuna richiesta HTTP) ma l'audit dell'accesso si aggiorna
	 * comunque. PRESERVATO: il `last_access` della risposta in cache e' quello della risposta che l'ha
	 * generata (stantio), non l'accesso precedente reale.
	 */
	public function test_health_cache_60_seconds_and_stale_last_access() {
		$this->enrolled();
		update_option( 'wp_health_check_last_request_at', '2020-01-01T00:00:00+00:00', false );
		update_option( 'wp_health_check_last_request_ip', '198.51.100.7', false );

		$first = $this->get_route( '/health' )->get_data();

		// Prima risposta: last_access e' l'accesso PRECEDENTE, letto prima di sovrascriverlo.
		$this->assertSame( '2020-01-01T00:00:00+00:00', $first['last_access']['at'] );
		$this->assertSame( '198.51.100.7', $first['last_access']['ip'] );
		$this->assertNotSame( '2020-01-01T00:00:00+00:00', get_option( 'wp_health_check_last_request_at' ), 'l\'accesso corrente e\' stato registrato' );
		$this->assertSame( self::CLIENT_IP, get_option( 'wp_health_check_last_request_ip' ) );

		// Il transient contiene esattamente il payload ed e' valido ~60 s.
		$this->assertSame( $first, $this->transient_raw( 'wphc_health_cache' ) );
		$ttl = $this->transient_ttl( 'wphc_health_cache' );
		$this->assertGreaterThan( 55, $ttl );
		$this->assertLessThanOrEqual( 60, $ttl );

		// Seconda richiesta: servita dalla cache (nessuna HTTP) ma l'audit si aggiorna.
		update_option( 'wp_health_check_last_request_at', '2021-02-02T00:00:00+00:00', false );
		update_option( 'wp_health_check_last_request_ip', '198.51.100.8', false );
		$calls_before = count( $this->blocked_http_calls );

		$second = $this->get_route( '/health' )->get_data();

		$this->assertSame( $first, $second, 'stesso body, compreso generated_at' );
		$this->assertSame( '2020-01-01T00:00:00+00:00', $second['last_access']['at'], 'PRESERVATO: last_access stantio nella risposta in cache' );
		$this->assertCount( $calls_before, $this->blocked_http_calls, 'cache hit: nessun lavoro' );
		$this->assertNotSame( '2021-02-02T00:00:00+00:00', get_option( 'wp_health_check_last_request_at' ), 'l\'audit si aggiorna anche su cache hit' );
		$this->assertSame( self::CLIENT_IP, get_option( 'wp_health_check_last_request_ip' ) );
	}

	/**
	 * ?fresh=1 salta la lettura della cache e SOSTITUISCE il transient con il payload ricalcolato
	 * (non lo cancella prima: l'effetto osservabile e' la sostituzione). Solo il valore stringa "1"
	 * conta: `fresh=true` e `fresh=0` non sono "fresh". ?fresh=1 svuota le cache delle liste plugin/temi
	 * ma NON tocca i transient update_plugins/update_themes ne' chiama wordpress.org.
	 */
	public function test_health_fresh_bypasses_and_replaces_cache() {
		$this->enrolled();
		$this->silence_remote_calls();
		$this->seed_fake_plugins();
		$this->seed_update_transient( 'update_plugins', array( 'fake-two/fake-two.php' ) );

		$sentinel = array( 'sentinel' => 'cache' );
		set_transient( 'wphc_health_cache', $sentinel, 60 );

		// Senza fresh, e con valori diversi da "1": risposta = transient.
		$this->assertSame( $sentinel, $this->get_route( '/health' )->get_data() );
		$this->assertSame( $sentinel, $this->get_route( '/health', array( 'fresh' => 'true' ) )->get_data() );
		$this->assertSame( $sentinel, $this->get_route( '/health', array( 'fresh' => '0' ) )->get_data() );

		// Con fresh=1: payload vero e transient sostituito.
		$data = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data();
		$this->assertArrayHasKey( 'summary', $data );
		$this->assertSame( $data, $this->transient_raw( 'wphc_health_cache' ) );
		$this->assertGreaterThan( 55, $this->transient_ttl( 'wphc_health_cache' ) );

		// La lista dei plugin e' stata riscansionata (il finto plugin non c'e' piu') e il transient
		// degli update e' intatto.
		$this->assertArrayNotHasKey( 'fake-two/fake-two.php', get_plugins() );
		$this->assertSame( self::CHECKED_TS, get_site_transient( 'update_plugins' )->last_checked );
		$this->assertSame( 1, $data['summary']['plugins_updates'] );
		foreach ( $this->blocked_http_calls as $url ) {
			$this->assertStringNotContainsString( 'api.wordpress.org', $url );
		}
	}

	/**
	 * /health non chiama MAI WP_Debug_Data::debug_data() (il suo filtro `debug_information` non
	 * scatta) ne' forza controlli remoti degli update (nessuna richiesta a api.wordpress.org e nessun
	 * wp_update_plugins/wp_version_check), nemmeno con ?fresh=1.
	 */
	public function test_health_never_calls_debug_data_nor_remote_update_checks() {
		$this->enrolled();
		$debug_data_calls = 0;
		add_filter(
			'debug_information',
			static function ( $info ) use ( &$debug_data_calls ) {
				++$debug_data_calls;

				return $info;
			}
		);
		// Se partisse un controllo update scriverebbe questi transient/hook.
		$update_checks = 0;
		add_filter(
			'pre_set_site_transient_update_plugins',
			static function ( $value ) use ( &$update_checks ) {
				++$update_checks;

				return $value;
			}
		);

		$this->get_route( '/health' );
		$this->get_route( '/health', array( 'fresh' => '1' ) );

		$this->assertSame( 0, $debug_data_calls );
		$this->assertSame( 0, $update_checks );
		foreach ( $this->blocked_http_calls as $url ) {
			$this->assertStringNotContainsString( 'wordpress.org', $url );
		}
		// E' cosi' anche per le rotte economiche /ping.
		$this->get_route( '/ping' );
		$this->assertSame( 0, $debug_data_calls );
	}

	/**
	 * Audit "ultimo accesso": /health scrive `_last_request_at` (ISO UTC) e `_last_request_ip` e la
	 * risposta riporta quelli PRECEDENTI; `enrolled_at` viene dall'option omonima.
	 */
	public function test_health_records_access_and_reports_previous() {
		$this->enrolled();
		$this->silence_remote_calls();
		update_option( 'wp_health_check_enrolled_at', '2024-05-05T10:00:00+00:00', false );
		update_option( 'wp_health_check_last_request_at', '2022-03-03T03:03:03+00:00', false );
		update_option( 'wp_health_check_last_request_ip', '198.51.100.9', false );
		$before = $this->plugin_options();

		$response = $this->get_route( '/health' );

		$this->assertSame(
			array(
				'at'          => '2022-03-03T03:03:03+00:00',
				'ip'          => '198.51.100.9',
				'enrolled_at' => '2024-05-05T10:00:00+00:00',
			),
			$response->get_data()['last_access']
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', get_option( 'wp_health_check_last_request_at' ) );
		$this->assertSame( self::CLIENT_IP, get_option( 'wp_health_check_last_request_ip' ) );

		$this->assert_read_golden( 'read/health-access-tracking', $response, $before, self::HEALTH_ENV_PATHS );
	}

	/**
	 * Durante wp_doing_cron() (es. il drain del bulk) l'audit NON si scrive: i valori restituiti
	 * sono quelli correnti, invariati.
	 */
	public function test_record_access_is_skipped_while_doing_cron() {
		$this->enrolled();
		$this->silence_remote_calls();
		update_option( 'wp_health_check_last_request_at', '2022-03-03T03:03:03+00:00', false );
		update_option( 'wp_health_check_last_request_ip', '198.51.100.9', false );
		add_filter( 'wp_doing_cron', '__return_true' );

		$data = $this->get_route( '/health' )->get_data();

		$this->assertSame( '2022-03-03T03:03:03+00:00', $data['last_access']['at'] );
		$this->assertSame( '198.51.100.9', $data['last_access']['ip'] );
		$this->assertSame( '2022-03-03T03:03:03+00:00', get_option( 'wp_health_check_last_request_at' ) );
		$this->assertSame( '198.51.100.9', get_option( 'wp_health_check_last_request_ip' ) );
	}

	/**
	 * Cascata dell'IP pubblico: (1) SERVER_ADDR pubblico, nessuna richiesta; (2) IP privato: ipify, esito
	 * persistito 7 giorni in `wphc_public_ip`; (3) nuova chiamata: dal transient, senza HTTP; (4) risposta di
	 * ipify non pubblica: scartata; (5) lock anti-retry di 1 giorno: nessuna nuova richiesta.
	 */
	public function test_health_server_and_public_ip_cascade() {
		$this->enrolled();
		update_option( 'wp_health_check_thumb', 'https://uploads.invalid/thumb.png', false );

		// 1. IP del server pubblico: usato cosi' com'e', nessuna chiamata remota.
		$_SERVER['SERVER_ADDR'] = '8.8.8.8';
		$summary                = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data()['summary'];
		$this->assertSame( '8.8.8.8', $summary['server_ip'] );
		$this->assertFalse( $summary['server_ip_is_private'] );
		$this->assertSame( '8.8.8.8', $summary['public_ip'] );
		$this->assertSame( array(), $this->blocked_http_calls );

		// 2. IP privato: ipify risponde con un IP pubblico, che viene persistito.
		$_SERVER['SERVER_ADDR'] = '192.168.1.10';
		$this->mock_http(
			static function ( $url ) {
				return 'https://api.ipify.org' === $url ? self::http_response( 200, "8.8.4.4\n" ) : null;
			}
		);
		$summary = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data()['summary'];
		$this->assertSame( '192.168.1.10', $summary['server_ip'] );
		$this->assertTrue( $summary['server_ip_is_private'] );
		$this->assertSame( '8.8.4.4', $summary['public_ip'], 'il corpo e\' ripulito con trim()' );
		$this->assertSame( '8.8.4.4', get_transient( 'wphc_public_ip' ) );
		$this->assertGreaterThan( 6 * DAY_IN_SECONDS, $this->transient_ttl( 'wphc_public_ip' ) );
		$this->assertNotFalse( get_transient( 'wphc_public_ip_retry_lock' ), 'il lock si imposta PRIMA del tentativo' );
		$this->assertCount( 1, $this->blocked_http_calls );

		// 3. Seconda chiamata: dal transient, nessuna nuova richiesta.
		$summary = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data()['summary'];
		$this->assertSame( '8.8.4.4', $summary['public_ip'] );
		$this->assertCount( 1, $this->blocked_http_calls );

		// 4. Transient scaduto e lock rimosso: ipify risponde con un IP privato, che e' scartato.
		delete_transient( 'wphc_public_ip' );
		delete_transient( 'wphc_public_ip_retry_lock' );
		$this->mock_http(
			static function ( $url ) {
				return 'https://api.ipify.org' === $url ? self::http_response( 200, '10.0.0.1' ) : null;
			}
		);
		$summary = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data()['summary'];
		$this->assertNull( $summary['public_ip'] );
		$this->assertFalse( get_transient( 'wphc_public_ip' ) );

		// 5. Lock ancora attivo e nessun transient: nessuna nuova richiesta.
		$calls = count( $this->blocked_http_calls );
		$this->get_route( '/health', array( 'fresh' => '1' ) );
		$this->assertCount( $calls, $this->blocked_http_calls );
	}

	/**
	 * Le tre option di auto-update invalidano le cache giuste: auto_update_plugins svuota la cache di
	 * /detail/plugins e quella di /health; auto_update_themes quella di /detail/theme e di /health;
	 * auto_update_core_* solo quella di /health.
	 */
	public function test_cache_is_invalidated_by_auto_update_options() {
		$seed = function () {
			foreach ( array( 'wphc_health_cache', 'wphc_detail_plugins_cache', 'wphc_detail_theme_cache', 'wphc_detail_server_cache' ) as $name ) {
				set_transient( $name, 'x', HOUR_IN_SECONDS );
			}
		};
		$alive = function () {
			$names = array();
			foreach ( array( 'wphc_health_cache', 'wphc_detail_plugins_cache', 'wphc_detail_theme_cache', 'wphc_detail_server_cache' ) as $name ) {
				if ( false !== get_transient( $name ) ) {
					$names[] = $name;
				}
			}

			return $names;
		};

		$seed();
		update_option( 'auto_update_plugins', array( 'fake-one/fake-one.php' ) );
		$this->assertSame( array( 'wphc_detail_theme_cache', 'wphc_detail_server_cache' ), $alive() );

		$seed();
		update_option( 'auto_update_themes', array( 'tema-a' ) );
		$this->assertSame( array( 'wphc_detail_plugins_cache', 'wphc_detail_server_cache' ), $alive() );

		foreach ( array( 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' ) as $option ) {
			$seed();
			update_option( $option, 'disabled' );
			$this->assertSame( array( 'wphc_detail_plugins_cache', 'wphc_detail_theme_cache', 'wphc_detail_server_cache' ), $alive(), $option );
		}

		// Un'option NON di auto-update non tocca nulla. /detail/server non e' mai invalidata.
		$seed();
		update_option( 'wphc_read_test_unrelated', '1' );
		$this->assertCount( 4, $alive() );
	}

	// -----------------------------------------------------------------------
	// GET /ping
	// -----------------------------------------------------------------------

	/**
	 * /ping: quattro chiavi, nessuna cache, NESSUNA scrittura (non registra l'accesso) e nessuna
	 * richiesta in uscita.
	 */
	public function test_ping_shape_and_no_side_effects() {
		$this->enrolled();
		$before = $this->plugin_options();

		$response = $this->get_route( '/ping' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'status', 'site', 'agent_version', 'generated_at' ), array_keys( $data ) );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( $this->own_site_url(), $data['site'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $data['agent_version'] );
		$this->assertSame( array(), $this->blocked_http_calls );
		$this->assertFalse( get_transient( 'wphc_health_cache' ) );
		$this->assertSame( $before, $this->plugin_options(), '/ping non scrive nessuna option' );

		$this->assert_read_golden( 'read/ping', $response, $before );
	}

	// -----------------------------------------------------------------------
	// GET /detail/plugins
	// -----------------------------------------------------------------------

	/**
	 * /detail/plugins con elenco finto: per ogni plugin le 9 chiavi, slug da cartella o da nome file,
	 * update dal transient del cron, auto_update dalla option; il transient da 1 ora contiene il payload.
	 */
	public function test_detail_plugins_shape_cache_and_golden() {
		$this->enrolled();
		$this->seed_fake_plugins();
		$this->seed_update_transient( 'update_plugins', array( 'fake-two/fake-two.php' ) );
		update_option( 'auto_update_plugins', array( 'fake-two/fake-two.php' ) );
		$before = $this->plugin_options();

		$response = $this->get_route( '/detail/plugins' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'site', 'generated_at', 'count', 'auto_update_enabled_globally', 'plugins' ), array_keys( $data ) );
		$this->assertSame( 3, $data['count'] );
		// Valore LETTERALE (non confrontato con l'helper del core, che sarebbe una tautologia): nel container di
		// test l'auto-update dei plugin e' spento da un filtro della test library, quindi il campo vale false.
		$this->assertFalse( $data['auto_update_enabled_globally'] );
		$this->assertSame(
			array( 'name', 'slug', 'file', 'version', 'active', 'update_available', 'new_version', 'auto_update', 'auto_update_forced' ),
			array_keys( $data['plugins'][0] )
		);

		$by_file = array();
		foreach ( $data['plugins'] as $item ) {
			$by_file[ $item['file'] ] = $item;
		}
		// Slug: cartella del plugin; per un file singolo nella radice, il nome senza estensione.
		$this->assertSame( 'fake-one', $by_file['fake-one/fake-one.php']['slug'] );
		$this->assertSame( 'single', $by_file['single.php']['slug'] );
		$this->assertTrue( $by_file['fake-one/fake-one.php']['active'] );
		$this->assertFalse( $by_file['fake-two/fake-two.php']['active'] );
		$this->assertTrue( $by_file['fake-two/fake-two.php']['update_available'] );
		$this->assertSame( '99.0', $by_file['fake-two/fake-two.php']['new_version'] );
		$this->assertFalse( $by_file['fake-one/fake-one.php']['update_available'] );
		$this->assertNull( $by_file['fake-one/fake-one.php']['new_version'] );
		$this->assertTrue( $by_file['fake-two/fake-two.php']['auto_update'] );
		$this->assertFalse( $by_file['fake-one/fake-one.php']['auto_update'] );
		$this->assertNull( $by_file['fake-two/fake-two.php']['auto_update_forced'], 'null = nessun filtro forza lo stato' );

		// Cache da 1 ora con il payload completo.
		$this->assertSame( $data, $this->transient_raw( 'wphc_detail_plugins_cache' ) );
		$ttl = $this->transient_ttl( 'wphc_detail_plugins_cache' );
		$this->assertGreaterThan( HOUR_IN_SECONDS - 5, $ttl );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $ttl );

		$this->assert_read_golden( 'read/detail-plugins', $response, $before );
	}

	/**
	 * /detail/plugins: la cache vale (sentinella restituita), l'audit si aggiorna anche su cache hit,
	 * ?fresh=1 la salta e la sostituisce rileggendo la cartella dei plugin.
	 */
	public function test_detail_plugins_cache_hit_and_fresh() {
		$this->enrolled();
		$this->seed_fake_plugins();
		set_transient( 'wphc_detail_plugins_cache', array( 'sentinel' => 'plugins' ), HOUR_IN_SECONDS );

		$this->assertSame( array( 'sentinel' => 'plugins' ), $this->get_route( '/detail/plugins' )->get_data() );
		$this->assertNotFalse( get_option( 'wp_health_check_last_request_at' ), 'audit anche su cache hit' );

		$data = $this->get_route( '/detail/plugins', array( 'fresh' => '1' ) )->get_data();
		$this->assertArrayHasKey( 'plugins', $data );
		$this->assertSame( $data, $this->transient_raw( 'wphc_detail_plugins_cache' ) );
		// Rilettura reale (non piu' l'elenco finto): nel container di test e' installato il solo hello.php.
		$this->assertSame( 1, $data['count'] );
		$this->assertSame( array( 'hello.php' ), array_column( $data['plugins'], 'file' ) );
		$this->assertArrayNotHasKey( 'fake-one/fake-one.php', get_plugins() );
	}

	/**
	 * Un filtro `auto_update_plugin` che forza lo stato prevale sulla option: `auto_update_forced` vale
	 * true/false (non null) e `auto_update` segue il filtro.
	 */
	public function test_detail_plugins_forced_auto_update_wins_over_stored() {
		$this->enrolled();
		$this->seed_fake_plugins();
		update_option( 'auto_update_plugins', array( 'fake-two/fake-two.php' ) );
		add_filter(
			'auto_update_plugin',
			static function ( $update, $item ) {
				if ( 'fake-one/fake-one.php' === $item->plugin ) {
					return true; // Forzato acceso benche' non salvato.
				}
				if ( 'fake-two/fake-two.php' === $item->plugin ) {
					return false; // Forzato spento benche' salvato.
				}

				return $update;
			},
			10,
			2
		);

		$by_file = array();
		foreach ( $this->get_route( '/detail/plugins' )->get_data()['plugins'] as $item ) {
			$by_file[ $item['file'] ] = $item;
		}

		$this->assertTrue( $by_file['fake-one/fake-one.php']['auto_update'] );
		$this->assertTrue( $by_file['fake-one/fake-one.php']['auto_update_forced'] );
		$this->assertFalse( $by_file['fake-two/fake-two.php']['auto_update'] );
		$this->assertFalse( $by_file['fake-two/fake-two.php']['auto_update_forced'] );
		$this->assertNull( $by_file['single.php']['auto_update_forced'] );
	}

	/**
	 * `auto_update_enabled_globally` di /detail/plugins e /detail/theme (e i flag omonimi di /health)
	 * seguono wp_is_auto_update_enabled_for_type(): si pilotano con i filtri del core.
	 */
	public function test_auto_update_enabled_globally_follows_core_helper() {
		$this->enrolled();
		$this->silence_remote_calls();
		// Il container di test spegne l'auto-update con un filtro: lo si riaccende con priorita' maggiore.
		add_filter( 'automatic_updater_disabled', '__return_false', 99 );

		$this->assertTrue( $this->get_route( '/detail/plugins', array( 'fresh' => '1' ) )->get_data()['auto_update_enabled_globally'] );
		$this->assertTrue( $this->get_route( '/detail/theme', array( 'fresh' => '1' ) )->get_data()['auto_update_enabled_globally'] );
		$summary = $this->get_route( '/health', array( 'fresh' => '1' ) )->get_data()['summary'];
		$this->assertTrue( $summary['plugins_auto_update_enabled'] );
		$this->assertTrue( $summary['themes_auto_update_enabled'] );

		// I filtri per tipo spengono solo quel tipo.
		add_filter( 'plugins_auto_update_enabled', '__return_false' );
		$this->assertFalse( $this->get_route( '/detail/plugins', array( 'fresh' => '1' ) )->get_data()['auto_update_enabled_globally'] );
		$this->assertTrue( $this->get_route( '/detail/theme', array( 'fresh' => '1' ) )->get_data()['auto_update_enabled_globally'] );
	}

	// -----------------------------------------------------------------------
	// GET /detail/theme
	// -----------------------------------------------------------------------

	/**
	 * /detail/theme: forma del tema attivo, del parent e dell'elenco dei temi; update dal transient del
	 * cron; il transient da 1 ora contiene il payload. I valori che dipendono dai temi installati nel
	 * container sono mascherati nel golden.
	 */
	public function test_detail_theme_shape_cache_and_golden() {
		$this->enrolled();
		$stylesheet = wp_get_theme()->get_stylesheet();
		$this->seed_update_transient( 'update_themes', array( $stylesheet ) );
		update_option( 'auto_update_themes', array( $stylesheet ) );
		$before = $this->plugin_options();

		$response = $this->get_route( '/detail/theme' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'site', 'generated_at', 'auto_update_enabled_globally', 'active_theme', 'parent_theme', 'themes' ), array_keys( $data ) );
		$this->assertSame(
			array( 'name', 'stylesheet', 'version', 'update_available', 'new_version', 'auto_update', 'auto_update_forced' ),
			array_keys( $data['active_theme'] )
		);
		$this->assertSame( $stylesheet, $data['active_theme']['stylesheet'] );
		$this->assertTrue( $data['active_theme']['update_available'] );
		$this->assertSame( '99.0', $data['active_theme']['new_version'] );
		$this->assertTrue( $data['active_theme']['auto_update'] );
		$this->assertNull( $data['active_theme']['auto_update_forced'] );
		$this->assertNotEmpty( $data['themes'] );
		$this->assertSame(
			array( 'name', 'stylesheet', 'version', 'active', 'parent', 'update_available', 'new_version', 'auto_update', 'auto_update_forced' ),
			array_keys( $data['themes'][0] )
		);
		// Esattamente un tema attivo nell'elenco.
		$this->assertCount( 1, array_filter( wp_list_pluck( $data['themes'], 'active' ) ) );
		$this->assertCount( count( wp_get_themes() ), $data['themes'] );

		$this->assertSame( $data, $this->transient_raw( 'wphc_detail_theme_cache' ) );
		$ttl = $this->transient_ttl( 'wphc_detail_theme_cache' );
		$this->assertGreaterThan( HOUR_IN_SECONDS - 5, $ttl );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $ttl );

		$this->assert_read_golden(
			'read/detail-theme',
			$response,
			$before,
			array(
				'body.active_theme.name',
				'body.active_theme.stylesheet',
				'body.active_theme.version',
				'body.parent_theme',
				'body.themes',
			)
		);
	}

	/**
	 * /detail/theme: cache hit (con audit) e ?fresh=1 che la sostituisce.
	 */
	public function test_detail_theme_cache_hit_and_fresh() {
		$this->enrolled();
		set_transient( 'wphc_detail_theme_cache', array( 'sentinel' => 'theme' ), HOUR_IN_SECONDS );

		$this->assertSame( array( 'sentinel' => 'theme' ), $this->get_route( '/detail/theme' )->get_data() );
		$this->assertNotFalse( get_option( 'wp_health_check_last_request_at' ) );

		$data = $this->get_route( '/detail/theme', array( 'fresh' => '1' ) )->get_data();
		$this->assertArrayHasKey( 'active_theme', $data );
		$this->assertSame( $data, $this->transient_raw( 'wphc_detail_theme_cache' ) );
	}

	// -----------------------------------------------------------------------
	// GET /detail/server
	// -----------------------------------------------------------------------

	/**
	 * /detail/server: payload con allowlist esplicita (solo i campi dichiarati), cache da 12 ore.
	 * I valori di ambiente (PHP, MySQL, ini) sono mascherati nel golden.
	 */
	public function test_detail_server_shape_cache_and_golden() {
		$this->enrolled();
		$before = $this->plugin_options();

		$response = $this->get_route( '/detail/server' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'site', 'generated_at', 'server' ), array_keys( $data ) );
		$this->assertSame(
			array(
				'software',
				'server_ip',
				'server_ip_is_private',
				'public_ip',
				'php_version',
				'php_sapi',
				'php_memory_limit',
				'max_execution_time',
				'max_input_vars',
				'upload_max_filesize',
				'post_max_size',
				'mysql_version',
				'https',
				'extensions',
			),
			array_keys( $data['server'] )
		);
		$this->assertSame( array( 'curl', 'imagick', 'gd', 'mbstring', 'intl' ), array_keys( $data['server']['extensions'] ) );
		$this->assertSame( PHP_VERSION, $data['server']['php_version'] );
		$this->assertSame( PHP_SAPI, $data['server']['php_sapi'] );
		$this->assertTrue( $data['server']['https'] );
		$this->assertNull( $data['server']['server_ip'] );
		$this->assertNull( $data['server']['public_ip'] );
		foreach ( $data['server']['extensions'] as $name => $loaded ) {
			$this->assertSame( extension_loaded( $name ), $loaded, $name );
		}

		// Cache da 12 ore.
		$this->assertSame( $data, $this->transient_raw( 'wphc_detail_server_cache' ) );
		$ttl = $this->transient_ttl( 'wphc_detail_server_cache' );
		$this->assertGreaterThan( 12 * HOUR_IN_SECONDS - 5, $ttl );
		$this->assertLessThanOrEqual( 12 * HOUR_IN_SECONDS, $ttl );

		$this->assert_read_golden(
			'read/detail-server',
			$response,
			$before,
			array(
				'body.server.software',
				'body.server.php_version',
				'body.server.php_memory_limit',
				'body.server.max_execution_time',
				'body.server.max_input_vars',
				'body.server.upload_max_filesize',
				'body.server.post_max_size',
				'body.server.mysql_version',
				'body.server.extensions',
			)
		);
	}

	/**
	 * Allowlist: anche se WP_Debug_Data aggiungesse campi privati (utente/host/password del database,
	 * costanti, percorsi), non compaiono nella risposta. Si simula con il filtro `debug_information`.
	 */
	public function test_detail_server_allowlist_never_leaks_private_core_fields() {
		$this->enrolled();
		add_filter(
			'debug_information',
			static function ( $info ) {
				$info['wp-database']['fields']['database_user']     = array(
					'label' => 'Database user',
					'value' => 'SEGRETO_DB_USER',
				);
				$info['wp-database']['fields']['database_host']     = array(
					'label' => 'Database host',
					'value' => 'SEGRETO_DB_HOST',
				);
				$info['wp-database']['fields']['database_password'] = array(
					'label' => 'Database password',
					'value' => 'SEGRETO_DB_PASSWORD',
				);
				$info['wp-server']['fields']['private_field']       = array(
					'label' => 'Private',
					'value' => 'SEGRETO_SERVER',
				);
				$info['wp-constants']['fields']['DB_PASSWORD']      = array(
					'label' => 'DB_PASSWORD',
					'value' => 'SEGRETO_COSTANTE',
				);

				return $info;
			}
		);

		$data = $this->get_route( '/detail/server', array( 'fresh' => '1' ) )->get_data();

		$this->assertStringNotContainsString( 'SEGRETO', wp_json_encode( $data ) );
		$this->assertCount( 14, $data['server'], 'esattamente i 14 campi dell\'allowlist' );
	}

	/**
	 * `software` e `mysql_version` si prendono da debug_data (httpd_software, poi il vecchio server_software;
	 * server_version del database); se mancano, ripiego su $_SERVER['SERVER_SOFTWARE'] e su $wpdb->db_version().
	 */
	public function test_detail_server_software_and_mysql_sources() {
		$this->enrolled();

		// 1. httpd_software ha la precedenza su server_software.
		$filter = static function ( $info ) {
			$info['wp-server']['fields']['httpd_software']['value']   = 'TestHTTPD/1.0';
			$info['wp-server']['fields']['server_software']['value']  = 'Vecchio/0.1';
			$info['wp-database']['fields']['server_version']['value'] = '9.9.9-test';

			return $info;
		};
		add_filter( 'debug_information', $filter );
		$server = $this->get_route( '/detail/server', array( 'fresh' => '1' ) )->get_data()['server'];
		$this->assertSame( 'TestHTTPD/1.0', $server['software'] );
		$this->assertSame( '9.9.9-test', $server['mysql_version'] );

		// 2. httpd_software vuoto: si usa server_software.
		remove_filter( 'debug_information', $filter );
		$filter = static function ( $info ) {
			$info['wp-server']['fields']['httpd_software']['value']  = '';
			$info['wp-server']['fields']['server_software']['value'] = 'Vecchio/0.1';

			return $info;
		};
		add_filter( 'debug_information', $filter );
		$this->assertSame( 'Vecchio/0.1', $this->get_route( '/detail/server', array( 'fresh' => '1' ) )->get_data()['server']['software'] );

		// 3. Nessuno dei due: ripiego sull'header del server e sulla versione di $wpdb.
		remove_filter( 'debug_information', $filter );
		$filter = static function ( $info ) {
			$info['wp-server']['fields']                              = array();
			$info['wp-database']['fields']['server_version']['value'] = '';

			return $info;
		};
		add_filter( 'debug_information', $filter );
		$_SERVER['SERVER_SOFTWARE'] = 'Fallback/2.0';
		$server                     = $this->get_route( '/detail/server', array( 'fresh' => '1' ) )->get_data()['server'];
		$this->assertSame( 'Fallback/2.0', $server['software'] );
		global $wpdb;
		$this->assertSame( (string) $wpdb->db_version(), $server['mysql_version'] );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	/**
	 * Se WP_Debug_Data::debug_data() lancia un'eccezione la rotta NON risponde 500: prosegue con i ripieghi.
	 */
	public function test_detail_server_survives_debug_data_exception() {
		$this->enrolled();
		add_filter(
			'debug_information',
			static function () {
				throw new RuntimeException( 'introspezione fallita' );
			}
		);

		$response = $this->get_route( '/detail/server', array( 'fresh' => '1' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( PHP_VERSION, $response->get_data()['server']['php_version'] );
		global $wpdb;
		$this->assertSame( (string) $wpdb->db_version(), $response->get_data()['server']['mysql_version'] );
	}

	/**
	 * /detail/server: cache hit (con audit; WP_Debug_Data NON viene eseguita) e ?fresh=1 che la sostituisce.
	 */
	public function test_detail_server_cache_hit_and_fresh() {
		$this->enrolled();
		$debug_data_calls = 0;
		add_filter(
			'debug_information',
			static function ( $info ) use ( &$debug_data_calls ) {
				++$debug_data_calls;

				return $info;
			}
		);
		set_transient( 'wphc_detail_server_cache', array( 'sentinel' => 'server' ), 12 * HOUR_IN_SECONDS );

		$this->assertSame( array( 'sentinel' => 'server' ), $this->get_route( '/detail/server' )->get_data() );
		$this->assertSame( 0, $debug_data_calls, 'cache hit: debug_data() non gira' );
		$this->assertNotFalse( get_option( 'wp_health_check_last_request_at' ) );

		$data = $this->get_route( '/detail/server', array( 'fresh' => '1' ) )->get_data();
		$this->assertSame( 1, $debug_data_calls );
		$this->assertArrayHasKey( 'server', $data );
		$this->assertSame( $data, $this->transient_raw( 'wphc_detail_server_cache' ) );
	}

	// -----------------------------------------------------------------------
	// GET /detail/users
	// -----------------------------------------------------------------------

	/**
	 * /detail/users: elenca solo gli amministratori (non editor ne' subscriber), con le 7 chiavi per
	 * utente; last_login/last_login_ip vengono dalle user meta (null se mai registrate) e `count`
	 * coincide con la lunghezza dell'elenco. Nessuna cache transient. Il golden e' ristretto agli
	 * utenti creati dal test (gli ID sono mascherati).
	 */
	public function test_detail_users_lists_only_administrators() {
		$this->enrolled();
		$admin_with_login = self::factory()->user->create(
			array(
				'role'            => 'administrator',
				'user_login'      => 'wphc_admin_login',
				'display_name'    => 'Admin Con Login',
				'user_email'      => 'admin-login@example.org',
				'user_registered' => '2020-01-02 03:04:05',
			)
		);
		self::factory()->user->create(
			array(
				'role'            => 'administrator',
				'user_login'      => 'wphc_admin_nologin',
				'display_name'    => 'Admin Senza Login',
				'user_email'      => 'admin-nologin@example.org',
				'user_registered' => '2021-06-07 08:09:10',
			)
		);
		self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'wphc_editor',
			)
		);
		self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'wphc_subscriber',
			)
		);
		update_user_meta( $admin_with_login, 'wphc_last_login', 1700000000 );
		update_user_meta( $admin_with_login, 'wphc_last_login_ip', '198.51.100.20' );
		$before = $this->plugin_options();

		$response = $this->get_route( '/detail/users' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'site', 'generated_at', 'count', 'users' ), array_keys( $data ) );
		$this->assertSame( count( $data['users'] ), $data['count'] );
		$this->assertSame(
			array( 'id', 'user_login', 'display_name', 'email', 'registered', 'last_login', 'last_login_ip' ),
			array_keys( $data['users'][0] )
		);

		$by_login = array();
		foreach ( $data['users'] as $user ) {
			$by_login[ $user['user_login'] ] = $user;
		}
		$this->assertArrayHasKey( 'wphc_admin_login', $by_login );
		$this->assertArrayHasKey( 'wphc_admin_nologin', $by_login );
		$this->assertArrayNotHasKey( 'wphc_editor', $by_login );
		$this->assertArrayNotHasKey( 'wphc_subscriber', $by_login );

		$with = $by_login['wphc_admin_login'];
		$this->assertSame( $admin_with_login, $with['id'] );
		$this->assertSame( 'Admin Con Login', $with['display_name'] );
		$this->assertSame( 'admin-login@example.org', $with['email'] );
		$this->assertSame( '2020-01-02T03:04:05+00:00', $with['registered'], 'user_registered e\' gia\' UTC' );
		$this->assertSame( '2023-11-14T22:13:20+00:00', $with['last_login'] );
		$this->assertSame( '198.51.100.20', $with['last_login_ip'] );
		$without = $by_login['wphc_admin_nologin'];
		$this->assertNull( $without['last_login'] );
		$this->assertNull( $without['last_login_ip'] );

		// Nessuna cache, ma l'audit dell'accesso si registra.
		$this->assertFalse( get_transient( 'wphc_detail_users_cache' ) );
		$this->assertNotFalse( get_option( 'wp_health_check_last_request_at' ) );

		// Golden ristretto ai due admin creati (ID mascherati, ordine per login).
		$restricted = new WP_REST_Response(
			array_merge(
				$data,
				array(
					'count' => 2,
					'users' => array_map(
						static function ( $user ) {
							$user['id'] = '<ID>';

							return $user;
						},
						array( $with, $without )
					),
				)
			),
			$response->get_status()
		);
		$this->assert_read_golden( 'read/detail-users', $restricted, $before );
	}

	// -----------------------------------------------------------------------
	// Rami di /health e /detail/theme non fissati altrove
	// -----------------------------------------------------------------------

	/**
	 * Segnali del sito (`wphc_detect_site_signals`): ogni slug riconosciuto accende il suo segnale e gli slug
	 * "quasi giusti" NO. Lo slug e' la CARTELLA del plugin (primo segmento di "cartella/file.php"): un plugin a
	 * file singolo nella radice o un nome piu' lungo non corrisponde. Il tema Divi accende `has_builder` (lo slug e'
	 * confrontato in minuscolo con lo stylesheet e con il template del tema attivo; il ramo "template di un child" non
	 * e' raggiungibile senza un tema divi vero installato: per un tema inesistente template e stylesheet coincidono). Elenchi riconosciuti dalla 1.38.1: GDPR iubenda-cookie-law-solution,
	 * cookiebot, cookiebot-manager; builder elementor e tema divi; e-commerce woocommerce, easy-digital-downloads.
	 */
	public function test_health_site_signals_cover_every_recognized_slug_and_ignore_the_rest() {
		$this->enrolled();

		$cases = array(
			'nessun plugin attivo'                  => array( array(), null, array( false, false, false ) ),
			'iubenda'                               => array( array( 'iubenda-cookie-law-solution/iubenda_cookie_law_solution.php' ), null, array( true, false, false ) ),
			'cookiebot-manager'                     => array( array( 'cookiebot-manager/cookiebot-manager.php' ), null, array( true, false, false ) ),
			'easy-digital-downloads'                => array( array( 'easy-digital-downloads/easy-digital-downloads.php' ), null, array( false, false, true ) ),
			'plugin a file singolo nella radice'    => array( array( 'cookiebot.php', 'elementor.php', 'woocommerce.php' ), null, array( false, false, false ) ),
			'slug piu lunghi (nessuna sottostringa)' => array( array( 'cookiebot-extra/a.php', 'elementor-pro/b.php', 'woocommerce-extra/c.php' ), null, array( false, false, false ) ),
			'tema divi'                             => array( array(), array( 'divi', 'divi' ), array( false, true, false ) ),
			'tema qualunque'                        => array( array(), array( 'twentytwentyfive', 'twentytwentyfive' ), array( false, false, false ) ),
		);

		foreach ( $cases as $label => $case ) {
			list( $active_plugins, $theme, $expected ) = $case;

			update_option( 'active_plugins', $active_plugins );
			update_option( 'stylesheet', null === $theme ? 'twentytwentyfive' : $theme[0] );
			update_option( 'template', null === $theme ? 'twentytwentyfive' : $theme[1] );
			delete_transient( 'wphc_health_cache' ); // /health ha una cache di 60 s.

			$summary = $this->get_route( '/health' )->get_data()['summary'];

			$this->assertSame( $expected, array( $summary['has_gdpr'], $summary['has_builder'], $summary['has_ecommerce'] ), $label );
		}
	}

	/**
	 * `comments_pending` e' il numero dei commenti IN ATTESA di moderazione (wp_count_comments()->moderated):
	 * non conta gli approvati.
	 */
	public function test_health_comments_pending_counts_only_moderated_comments() {
		$this->enrolled();
		$summary = $this->get_route( '/health' )->get_data()['summary'];
		$this->assertSame( 0, $summary['comments_pending'] );

		$post_id = self::factory()->post->create();
		self::factory()->comment->create_many( 3, array( 'comment_post_ID' => $post_id, 'comment_approved' => '0' ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => '1' ) );
		wp_cache_flush(); // wp_count_comments() tiene un conteggio nella cache a oggetti.
		delete_transient( 'wphc_health_cache' );

		$summary = $this->get_route( '/health' )->get_data()['summary'];
		$this->assertSame( 3, $summary['comments_pending'] );
	}

	/**
	 * Child theme: /health riporta il NOME del tema parent (`parent_theme_name`), /detail/theme il payload del
	 * parent e, nell'elenco, il campo `parent` solo per il child (null per gli altri temi). Si usano i temi della
	 * test library (`block-theme-child`, che dichiara `Template: block-theme`).
	 */
	public function test_child_theme_reports_its_parent() {
		$this->enrolled();
		switch_theme( 'block-theme-child' );
		$this->assertSame( 'block-theme', get_template(), 'precondizione: il tema attivo e un child' );

		$summary = $this->get_route( '/health' )->get_data()['summary'];
		$this->assertSame( 'Block Theme', $summary['parent_theme_name'] );

		$detail = $this->get_route( '/detail/theme', array( 'fresh' => '1' ) )->get_data();
		$this->assertSame( 'block-theme-child', $detail['active_theme']['stylesheet'] );
		$this->assertSame( array( 'name', 'version', 'auto_update', 'auto_update_forced' ), array_keys( $detail['parent_theme'] ) );
		$this->assertSame( 'Block Theme', $detail['parent_theme']['name'] );
		$this->assertSame( '1.0.0', $detail['parent_theme']['version'] );

		$parents = array_column( $detail['themes'], 'parent', 'stylesheet' );
		$this->assertSame( 'block-theme', $parents['block-theme-child'] );
		$this->assertNull( $parents['block-theme'] );
	}
}
