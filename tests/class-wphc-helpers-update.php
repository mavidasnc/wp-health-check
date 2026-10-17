<?php
/**
 * Helper condivisi dai test di caratterizzazione di update, bulk e webhook
 * (test-self-update.php, test-item-update.php, test-update-log.php, test-bulk.php,
 * test-webhook.php).
 *
 * Contiene SOLO supporto:
 *  - una guardia che garantisce che nessun test scriva il file reale del plugin (e che
 *    nessun .bak/.tmp resti in mu-plugins);
 *  - il mock dell'HTTP in uscita (GitHub, hub, webhook) sopra il blocco di rete della
 *    classe base;
 *  - costruttori di pacchetti di release che hanno SEMPRE esattamente un difetto (mai un
 *    pacchetto interamente valido: la pipeline di installazione lo scriverebbe su
 *    __FILE__, cioe' sul plugin vero);
 *  - transient di update finti (plugin, tema, core), un plugin "fixture" per i rami che
 *    sostituiscono davvero dei file, scrub dei campi non deterministici per i golden.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/trait-wphc-plugin-file-guard.php';

/**
 * Base dei test di update/bulk/webhook: enrolled con bearer, rete mockata, guardia sul plugin.
 */
abstract class WPHC_Update_Test_Case extends WPHC_Test_Case {

	// Guardia per-test sul file del plugin sotto test (ripristino + ripulitura dei soli residui del test).
	use WPHC_Plugin_File_Guard;

	/** Token (bearer) del sito di test: protocol 1, basta l'header Authorization. */
	const TOKEN = 'tok-upd-test';

	/** Directory e file del plugin "fixture" installato nel container (mai nel repo). */
	const FIXTURE_DIR  = 'wphc-fixture';
	const FIXTURE_FILE = 'wphc-fixture/wphc-fixture.php';

	/**
	 * Secondo fixture, con un altro percorso: activate_plugin() usa include_once, quindi un file
	 * gia' incluso in questo processo (da un test precedente) non produrrebbe piu' output.
	 */
	const FIXTURE_B_DIR  = 'wphc-fixture-b';
	const FIXTURE_B_FILE = 'wphc-fixture-b/wphc-fixture-b.php';

	/** Terzo fixture (test del log/reactivate), stessa ragione del secondo. */
	const FIXTURE_C_DIR  = 'wphc-fixture-c';
	const FIXTURE_C_FILE = 'wphc-fixture-c/wphc-fixture-c.php';

	/** Quarto fixture (riattivazione fallita nel test del log). */
	const FIXTURE_D_DIR  = 'wphc-fixture-d';
	const FIXTURE_D_FILE = 'wphc-fixture-d/wphc-fixture-d.php';

	/**
	 * Risposte HTTP finte: URL => risposta (array WP, WP_Error o callable).
	 *
	 * @var array<string,mixed>
	 */
	protected $http_routes = array();

	/**
	 * Richieste HTTP in uscita viste dal mock: elenco di array( 'url' =>, 'args' => ).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected $http_calls = array();

	/**
	 * Id massimo della tabella di log all'inizio del test: le righe "del test" sono quelle oltre.
	 *
	 * @var int
	 */
	protected $log_baseline = 0;

	/**
	 * File temporanei da cancellare a fine test.
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	/**
	 * Livello degli output buffer a inizio test: gli upgrader del core ne lasciano uno aperto sul ramo
	 * "download fallito" e PHPUnit segnalerebbe il test come rischioso.
	 *
	 * @var int
	 */
	private $ob_level = 0;

	// -----------------------------------------------------------------------
	// Ciclo di vita e guardia sul plugin
	// -----------------------------------------------------------------------

	/**
	 * Prepara il test: sito enrolled, nessun evento cron del plugin, webhook spento, guardia armata.
	 */
	public function set_up() {
		parent::set_up();
		// La ricerca dell'ultima release (hub, API di GitHub, ripiego senza API) e' una chiamata di sfondo
		// nota: i test di self-update la mockano quando verificano un esito, altrimenti il blocco la fa fallire.
		$this->allow_unmocked_http_hosts( array( 'api.github.com', 'github.com' ) );
		$this->ob_level = ob_get_level();

		// Le classi degli upgrader (anche Language_Pack_Upgrader, agganciata a upgrader_process_complete)
		// devono essere caricate: il plugin le carica da solo solo quando esegue un update, e un test che
		// lancia direttamente l'hook, da solo, altrimenti fallirebbe a seconda dell'ordine di esecuzione.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Guardia sul file del plugin sotto test (vedi tear_down e il trait WPHC_Plugin_File_Guard).
		$this->plugin_guard_arm();

		// Sito enrolled con protocol 1 (basta il bearer).
		$this->seed_enrolled( self::TOKEN, 1 );

		// Nessun evento cron del plugin pendente, nessun flag di update residuo.
		$this->clear_plugin_cron();
		self::reset_update_log_state();

		// Webhook spento di default: i test che lo vogliono impostano un URL (con IP letterale
		// pubblico: wp_http_validate_url() risolve i nomi DNS con gethostbyname()).
		update_option( 'wp_health_check_webhook_disabled', true );

		// Nessuna rete verso api.wordpress.org: gli hook upgrader_process_complete del core lanciano i controlli.
		$this->mock_wporg_api();

		// Id di partenza della tabella di log (AUTO_INCREMENT sopravvive al rollback dei test).
		$this->log_baseline = $this->max_log_id();

		// Rimuove eventuali residui di un fixture di un test precedente morto a meta'.
		$this->remove_fixture_plugin();
	}

	/**
	 * Chiude il test. GUARDIA (trait WPHC_Plugin_File_Guard): se i byte del plugin sotto test sono cambiati li
	 * ripristina, ripulisce i soli .bak/.tmp creati dal test e fa fallire il test ad alta voce.
	 */
	public function tear_down() {
		// Chiude gli output buffer lasciati aperti dagli upgrader del core.
		while ( ob_get_level() > $this->ob_level ) {
			ob_end_clean();
		}
		$this->remove_fixture_plugin();
		foreach ( $this->tmp_files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		$this->tmp_files = array();

		// Ripristino e ripulitura PRIMA del tear_down della classe base; il fallimento DOPO.
		$problems = $this->plugin_guard_release();

		parent::tear_down();

		$this->plugin_guard_assert( $problems );
	}

	/**
	 * Proprieta' statica privata di WPHC_Update_Log (plugin a classi), accessibile con la Reflection.
	 *
	 * @param string $name version_snapshots | api_update_in_progress | deleting_plugins.
	 * @return ReflectionProperty
	 */
	private static function update_log_property( $name ) {
		$property = new ReflectionProperty( 'WPHC_Update_Log', $name );
		$property->setAccessible( true ); // Necessario con PHP 7.4 (dalla 8.1 non fa nulla).
		return $property;
	}

	/**
	 * Azzera lo stato di audit del plugin: nel file 1.38.1 sono tre chiavi di $GLOBALS (wphc_version_snapshots,
	 * wphc_api_update_in_progress, wphc_deleting_plugins), nel refactoring sono le proprieta' statiche private di
	 * WPHC_Update_Log. A due modi come cb(): la forma a funzioni se il plugin non ha la classe.
	 */
	protected static function reset_update_log_state() {
		if ( ! class_exists( 'WPHC_Update_Log', false ) ) {
			unset( $GLOBALS['wphc_api_update_in_progress'], $GLOBALS['wphc_version_snapshots'], $GLOBALS['wphc_deleting_plugins'] );
			return;
		}
		self::update_log_property( 'version_snapshots' )->setValue( array() );
		self::update_log_property( 'api_update_in_progress' )->setValue( false );
		self::update_log_property( 'deleting_plugins' )->setValue( array() );
	}

	/**
	 * Vista "alla $GLOBALS" dello stato di audit del plugin: un array con le STESSE chiavi che il file 1.38.1 teneva
	 * in $GLOBALS, cosi' le asserzioni dei test (assertArrayHasKey, assertArrayNotHasKey, lettura di una voce)
	 * restano identiche. Nel plugin a classi le chiavi degli snapshot sono sempre presenti (array, anche vuoto);
	 * il flag dell'update via API compare solo se e' impostato (nel 1.38.1 la chiave veniva tolta con unset()).
	 *
	 * @return array<string,mixed>
	 */
	protected static function plugin_state() {
		if ( ! class_exists( 'WPHC_Update_Log', false ) ) {
			return array_intersect_key( $GLOBALS, array_flip( array( 'wphc_version_snapshots', 'wphc_api_update_in_progress', 'wphc_deleting_plugins' ) ) );
		}
		$state = array(
			'wphc_version_snapshots' => self::update_log_property( 'version_snapshots' )->getValue(),
			'wphc_deleting_plugins'  => self::update_log_property( 'deleting_plugins' )->getValue(),
		);
		if ( self::update_log_property( 'api_update_in_progress' )->getValue() ) {
			$state['wphc_api_update_in_progress'] = true;
		}
		return $state;
	}

	/**
	 * Imposta o toglie il flag "update avviato via API" (nel 1.38.1 $GLOBALS['wphc_api_update_in_progress'] = true
	 * oppure unset(); nelle classi WPHC_Update_Log::begin_api_update() / end_api_update()).
	 *
	 * @param bool $in_progress True per impostarlo, false per toglierlo.
	 */
	protected static function set_api_update_in_progress( $in_progress ) {
		if ( ! class_exists( 'WPHC_Update_Log', false ) ) {
			if ( $in_progress ) {
				$GLOBALS['wphc_api_update_in_progress'] = true;
			} else {
				unset( $GLOBALS['wphc_api_update_in_progress'] );
			}
			return;
		}
		if ( $in_progress ) {
			WPHC_Update_Log::begin_api_update();
		} else {
			WPHC_Update_Log::end_api_update();
		}
	}

	/**
	 * Cancella gli eventi cron del plugin (tick bulk, retry webhook).
	 */
	protected function clear_plugin_cron() {
		wp_clear_scheduled_hook( 'wphc_bulk_update_tick' );
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
	}

	// -----------------------------------------------------------------------
	// HTTP finto
	// -----------------------------------------------------------------------

	/**
	 * Registra le risposte HTTP finte (priorita' 20, sopra il blocco della classe base): gli URL
	 * non elencati restano bloccati (WP_Error) e registrati in $blocked_http_calls.
	 * Un valore puo' essere: array di risposta (vedi http_response()), WP_Error, oppure un
	 * callable( $args, $url ) che restituisce uno dei due. Una chiave che termina con '*' vale come prefisso.
	 *
	 * @param array<string,mixed> $routes URL => risposta.
	 */
	protected function mock_http( array $routes ) {
		$this->http_routes = $routes;
		add_filter( 'pre_http_request', array( $this, 'serve_mock_http' ), 20, 3 );
	}

	/**
	 * Filtro pre_http_request: serve la risposta finta dell'URL, altrimenti lascia il valore del blocco.
	 *
	 * @param mixed  $preempt Valore del filtro (il WP_Error del blocco di rete).
	 * @param array  $args    Argomenti della richiesta.
	 * @param string $url     URL.
	 * @return mixed
	 */
	public function serve_mock_http( $preempt, $args, $url ) {
		$this->http_calls[] = array(
			'url'  => $url,
			'args' => $args,
		);

		foreach ( $this->http_routes as $pattern => $response ) {
			$is_prefix = ( '*' === substr( (string) $pattern, -1 ) );
			$matches   = $is_prefix ? ( 0 === strpos( $url, substr( $pattern, 0, -1 ) ) ) : ( $url === $pattern );
			if ( ! $matches ) {
				continue;
			}

			return is_callable( $response ) ? call_user_func( $response, $args, $url ) : $response;
		}

		return $preempt;
	}

	/**
	 * Risposta HTTP nella forma restituita da wp_remote_get()/wp_remote_post().
	 *
	 * @param int                  $code    Codice HTTP.
	 * @param string               $body    Corpo.
	 * @param array<string,string> $headers Header (chiavi in minuscolo: wp_remote_retrieve_header() cerca la chiave esatta).
	 * @return array
	 */
	protected function http_response( $code, $body = '', array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * URL delle richieste viste dal mock che contengono la sottostringa.
	 *
	 * @param string $needle Sottostringa.
	 * @return string[]
	 */
	protected function http_urls_containing( $needle ) {
		$urls = array();
		foreach ( $this->http_calls as $call ) {
			if ( false !== strpos( $call['url'], $needle ) ) {
				$urls[] = $call['url'];
			}
		}

		return $urls;
	}

	// -----------------------------------------------------------------------
	// Richieste REST
	// -----------------------------------------------------------------------

	/**
	 * Richiesta REST a una rotta dati con il bearer del sito di test.
	 *
	 * @param string            $method GET o POST.
	 * @param string            $route  Rotta senza namespace.
	 * @param array|string|null $body   Body JSON (array) o grezzo.
	 * @param array             $query  Query string.
	 * @return WP_REST_Response
	 */
	protected function upd( $method, $route, $body = null, array $query = array() ) {
		return $this->request( $method, $route, $body, array( 'Authorization' => 'Bearer ' . self::TOKEN ), $query );
	}

	// -----------------------------------------------------------------------
	// Pacchetti di release (self-update): SEMPRE con un difetto
	// -----------------------------------------------------------------------

	/**
	 * Costruisce le parti di una release con ESATTAMENTE un difetto. Non esiste una forma
	 * valida di proposito: una release completamente valida verrebbe scritta su __FILE__ (il
	 * plugin vero) dalla pipeline di installazione.
	 *
	 * Difetti: 'sha' (sha256 che non corrisponde al contenuto), 'version_header' (header
	 * Version diverso dal tag), 'no_php' (contenuto senza "<?php"), 'sig_missing' (firma
	 * assente), 'sig_bad' (firma di un altro messaggio), 'sig_k1' (firma con la chiave k1
	 * invece di k2), 'sig_kid_k1' (firma k2 ma kid dichiarato k1).
	 *
	 * @param string $tag  Tag con la "v" (es. v99.0.0).
	 * @param string $flaw Difetto.
	 * @return array{contents:string,sha256:string,sig:array<string,mixed>}
	 */
	protected function flawed_release( $tag, $flaw ) {
		$known = array( 'sha', 'version_header', 'no_php', 'sig_missing', 'sig_bad', 'sig_k1', 'sig_kid_k1' );
		if ( ! in_array( $flaw, $known, true ) ) {
			throw new InvalidArgumentException( "Difetto sconosciuto: {$flaw} (i pacchetti validi non si costruiscono mai)" );
		}

		$version = ltrim( $tag, 'v' );
		if ( 'version_header' === $flaw ) {
			$version = '0.0.1'; // L'header non corrisponde al tag.
		}
		$prefix   = ( 'no_php' === $flaw ) ? "// non e' un file php\n" : "<?php\n";
		$contents = $prefix . "/**\n * Plugin Name: WPHC finto\n * Version:     {$version}\n */\n";

		$sha = hash( 'sha256', $contents );
		if ( 'sha' === $flaw ) {
			$sha = hash( 'sha256', 'contenuto diverso' ); // Lo sha dichiarato non e' quello del file.
		}

		$signed_message = "release\n" . $tag . "\n" . $sha;
		$sig            = array(
			'kid'       => 'k2',
			'signature' => self::sign_message( $signed_message, 'k2' ),
		);
		if ( 'sig_missing' === $flaw ) {
			$sig = array();
		} elseif ( 'sig_bad' === $flaw ) {
			$sig['signature'] = self::sign_message( "release\naltro\n" . $sha, 'k2' );
		} elseif ( 'sig_k1' === $flaw ) {
			$sig['signature'] = self::sign_message( $signed_message, 'k1' );
		} elseif ( 'sig_kid_k1' === $flaw ) {
			$sig['kid'] = 'k1';
		}

		return array(
			'contents' => $contents,
			'sha256'   => $sha,
			'sig'      => $sig,
		);
	}

	/**
	 * Pacchetto push ({tag, sha256, sig, contents_gz_b64}) con un difetto di installazione.
	 *
	 * @param string $tag  Tag con la "v".
	 * @param string $flaw Difetto (vedi flawed_release()).
	 * @return array<string,mixed>
	 */
	protected function flawed_push_package( $tag, $flaw ) {
		$parts = $this->flawed_release( $tag, $flaw );

		return array(
			'tag'             => $tag,
			'sha256'          => $parts['sha256'],
			'sig'             => $parts['sig'],
			'contents_gz_b64' => base64_encode( gzencode( $parts['contents'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Mock completo del pull da GitHub per un tag: API della release e i tre asset, con un difetto.
	 *
	 * @param string               $tag         Tag con la "v".
	 * @param string               $flaw        Difetto (vedi flawed_release()).
	 * @param array<string,mixed>  $overrides   Risposte che sostituiscono quelle di default (chiave = URL).
	 * @param string[]             $omit_assets Nomi di asset da non elencare nella release dell'API.
	 */
	protected function mock_github_release( $tag, $flaw, array $overrides = array(), array $omit_assets = array() ) {
		$parts  = $this->flawed_release( $tag, $flaw );
		$assets = array();
		foreach ( array( 'wp-health-check.php', 'wp-health-check.php.sha256', 'wp-health-check.php.sig' ) as $name ) {
			if ( ! in_array( $name, $omit_assets, true ) ) {
				$assets[] = array(
					'name'                 => $name,
					'browser_download_url' => self::github_asset_url( $tag, $name ),
				);
			}
		}

		$routes = array(
			self::github_api_url()                                  => $this->http_response(
				200,
				wp_json_encode(
					array(
						'tag_name' => $tag,
						'assets'   => $assets,
					)
				)
			),
			self::github_asset_url( $tag, 'wp-health-check.php' )        => $this->http_response( 200, $parts['contents'] ),
			self::github_asset_url( $tag, 'wp-health-check.php.sha256' ) => $this->http_response( 200, $parts['sha256'] . "  wp-health-check.php
" ),
			self::github_asset_url( $tag, 'wp-health-check.php.sig' )    => $this->http_response( 200, wp_json_encode( $parts['sig'] ) ),
		);

		$this->mock_http( array_merge( $routes, $overrides ) );
	}

	/**
	 * URL di download di un asset di una release su github.com.
	 *
	 * @param string $tag  Tag con la "v".
	 * @param string $name Nome dell'asset.
	 * @return string
	 */
	protected static function github_asset_url( $tag, $name ) {
		return 'https://github.com/' . WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO . '/releases/download/' . $tag . '/' . $name;
	}

	/**
	 * URL dell'API GitHub delle release (prima fonte reale nei test: RELEASE_URL e' vuoto).
	 *
	 * @return string
	 */
	protected static function github_api_url() {
		return 'https://api.github.com/repos/' . WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO . '/releases/latest';
	}

	/**
	 * URL di github.com da cui il ripiego senza API ricava il tag dal redirect.
	 *
	 * @return string
	 */
	protected static function github_latest_page_url() {
		return 'https://github.com/' . WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO . '/releases/latest';
	}

	/**
	 * Fa fallire il test del probe di scrittura di mu-plugins SENZA toccare i permessi: rende
	 * prevedibile il nome del file di prova (filtro random_password) e vi mette sopra una
	 * directory, cosi' file_put_contents() fallisce.
	 *
	 * @return string Percorso della directory creata (da rimuovere con unblock_mu_dir_probe()).
	 */
	protected function block_mu_dir_probe() {
		add_filter(
			'random_password',
			static function () {
				return 'fixedprobe01';
			}
		);
		$blocker = trailingslashit( WPMU_PLUGIN_DIR ) . '.wphc-writetest-fixedprobe01';
		if ( ! is_dir( $blocker ) ) {
			mkdir( $blocker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		return $blocker;
	}

	/**
	 * Rimuove la directory creata da block_mu_dir_probe().
	 *
	 * @param string $blocker Percorso restituito da block_mu_dir_probe().
	 */
	protected function unblock_mu_dir_probe( $blocker ) {
		if ( is_dir( $blocker ) ) {
			rmdir( $blocker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	// -----------------------------------------------------------------------
	// Transient di update finti e upgrader mockati
	// -----------------------------------------------------------------------

	/**
	 * Imposta il transient update_plugins con un aggiornamento disponibile per un plugin.
	 *
	 * @param string $plugin      Plugin file (es. hello.php).
	 * @param string $new_version Versione offerta.
	 * @param string $package     URL del pacchetto ('' = non aggiornabile).
	 */
	protected function set_plugin_update( $plugin, $new_version, $package ) {
		$installed = get_plugins();
		$checked   = array();
		foreach ( $installed as $file => $data ) {
			$checked[ $file ] = $data['Version'];
		}

		$transient               = new stdClass();
		$transient->last_checked = time();
		$transient->checked      = $checked;
		$transient->no_update    = array();
		$transient->response     = array(
			$plugin => (object) array(
				'id'          => 'w.org/plugins/' . dirname( $plugin ),
				'slug'        => dirname( $plugin ),
				'plugin'      => $plugin,
				'new_version' => $new_version,
				'url'         => '',
				'package'     => $package,
			),
		);
		set_site_transient( 'update_plugins', $transient );
	}

	/**
	 * Imposta il transient update_themes con un aggiornamento disponibile (le entry dei temi sono ARRAY).
	 *
	 * @param string $stylesheet  Stylesheet del tema.
	 * @param string $new_version Versione offerta.
	 * @param string $package     URL del pacchetto.
	 */
	protected function set_theme_update( $stylesheet, $new_version, $package ) {
		$transient               = new stdClass();
		$transient->last_checked = time();
		$transient->checked      = array();
		$transient->response     = array(
			$stylesheet => array(
				'theme'       => $stylesheet,
				'new_version' => $new_version,
				'url'         => '',
				'package'     => $package,
			),
		);
		set_site_transient( 'update_themes', $transient );
	}

	/**
	 * Imposta il transient update_core con un aggiornamento 'upgrade' disponibile.
	 *
	 * @param string $version  Versione offerta.
	 * @param string $download URL del pacchetto.
	 */
	protected function set_core_update( $version, $download ) {
		$offer = (object) array(
			'response'        => 'upgrade',
			'download'        => $download,
			'locale'          => 'en_US',
			'packages'        => (object) array(
				'full'        => $download,
				'no_content'  => '',
				'new_bundled' => '',
				'partial'     => '',
				'rollback'    => '',
			),
			'current'         => $version,
			'version'         => $version,
			'php_version'     => '7.4.0',
			'mysql_version'   => '5.0',
			'new_bundled'     => '6.0',
			'partial_version' => '',
		);

		$transient                  = new stdClass();
		$transient->updates         = array( $offer );
		$transient->last_checked    = time();
		$transient->version_checked = get_bloginfo( 'version' );
		set_site_transient( 'update_core', $transient );
	}

	/**
	 * Fa fallire il download di ogni pacchetto (filtro upgrader_pre_download): nessuna rete, nessun
	 * file toccato. L'upgrader del core abbandona PRIMA di qualunque operazione sul filesystem.
	 *
	 * @param string $message Messaggio dell'errore di download.
	 */
	protected function fail_package_downloads( $message = 'download mock fallito' ) {
		add_filter(
			'upgrader_pre_download',
			static function () use ( $message ) {
				return new WP_Error( 'wphc_test_download', $message );
			}
		);
	}

	/**
	 * Fa restituire all'upgrader lo zip locale indicato invece di scaricare (filtro upgrader_pre_download).
	 *
	 * @param string $zip_path Percorso dello zip.
	 */
	protected function serve_package_from( $zip_path ) {
		add_filter(
			'upgrader_pre_download',
			static function () use ( $zip_path ) {
				return $zip_path;
			}
		);
	}

	/**
	 * Forza il metodo del filesystem (filtro filesystem_method): 'direct' o altro.
	 *
	 * @param string $method Metodo.
	 */
	protected function force_fs_method( $method ) {
		add_filter(
			'filesystem_method',
			static function () use ( $method ) {
				return $method;
			}
		);
	}

	/**
	 * Spegne il kill-switch degli update via API. Si scrive 0 e non false: update_option() con false
	 * su un'option inesistente (default false) non scrive nulla, e il kill-switch resterebbe acceso.
	 */
	protected function disable_api_updates() {
		update_option( 'wp_health_check_updates_enabled', 0 );
	}

	/**
	 * Risposte minime di api.wordpress.org per i controlli che il core lancia da solo dopo un update
	 * (hook upgrader_process_complete -> wp_version_check) e dal Core_Upgrader (checksum dei file).
	 * Senza questo mock il core segnala l'errore di rete con un E_USER_WARNING e il test fallisce.
	 */
	protected function mock_wporg_api() {
		$empty_offers = $this->http_response( 200, '{"offers":[],"translations":[]}' );
		$no_checksums = $this->http_response( 200, '{}' );
		// Controlli di plugin e temi: una risposta non 200 fa uscire il core in silenzio, lasciando intatti
		// i transient di update costruiti dai test (con 200 li sostituirebbe con quelli dell'API).
		$no_check = $this->http_response( 500, '' );
		$this->mock_http(
			array(
				'http://api.wordpress.org/core/version-check/*'  => $empty_offers,
				'https://api.wordpress.org/core/version-check/*' => $empty_offers,
				'http://api.wordpress.org/core/checksums/*'      => $no_checksums,
				'https://api.wordpress.org/core/checksums/*'     => $no_checksums,
				'http://api.wordpress.org/plugins/update-check/*'  => $no_check,
				'https://api.wordpress.org/plugins/update-check/*' => $no_check,
				'http://api.wordpress.org/themes/update-check/*'   => $no_check,
				'https://api.wordpress.org/themes/update-check/*'  => $no_check,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Plugin "fixture" (sostituzione reale di file, solo in wp-content/plugins del container)
	// -----------------------------------------------------------------------

	/**
	 * Crea il plugin fixture nella cartella dei plugin del container.
	 *
	 * @param string $version Versione dichiarata.
	 * @param string $slug    Cartella (e nome del file principale) del fixture.
	 * @param string $extra   Codice PHP aggiuntivo nel file principale.
	 */
	protected function install_fixture_plugin( $version, $slug = self::FIXTURE_DIR, $extra = '' ) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		file_put_contents( $dir . '/' . $slug . '.php', self::fixture_source( $version, $extra ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_plugins_cache( false );
	}

	/**
	 * Sorgente del plugin fixture.
	 *
	 * @param string $version Versione.
	 * @param string $extra   Codice PHP aggiuntivo (es. un output che fa fallire activate_plugin()).
	 * @param string $header  Righe aggiuntive dell'header (formato " * Chiave: valore\n").
	 * @return string
	 */
	private static function fixture_source( $version, $extra, $header = '' ) {
		return "<?php\n/**\n * Plugin Name: WPHC Fixture\n * Version: {$version}\n{$header} */\n{$extra}\n";
	}

	/**
	 * Crea lo zip di una nuova versione del fixture e restituisce il percorso (cancellato a fine test).
	 *
	 * @param string $version Versione contenuta nello zip.
	 * @param string $extra   Codice PHP aggiuntivo nel file principale.
	 * @param string $header  Righe aggiuntive dell'header (formato " * Chiave: valore
").
	 * @param string $slug    Cartella del fixture dentro lo zip.
	 * @return string
	 */
	protected function build_fixture_zip( $version, $extra = '', $header = '', $slug = self::FIXTURE_DIR ) {
		$path = sys_get_temp_dir() . '/wphc-fixture-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE ) ) {
			throw new RuntimeException( 'Impossibile creare lo zip del fixture.' );
		}
		$zip->addFromString( $slug . '/' . $slug . '.php', self::fixture_source( $version, $extra, $header ) );
		$zip->close();
		$this->tmp_files[] = $path;

		return $path;
	}

	/**
	 * Rimuove il plugin fixture e i residui dell'upgrader (temp-backup, upgrade).
	 */
	protected function remove_fixture_plugin() {
		$targets = array();
		foreach ( array( self::FIXTURE_DIR, self::FIXTURE_B_DIR, self::FIXTURE_C_DIR, self::FIXTURE_D_DIR ) as $slug ) {
			$targets[] = WP_PLUGIN_DIR . '/' . $slug;
			foreach ( array( WP_CONTENT_DIR . '/upgrade/*' . $slug . '*', WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $slug . '*' ) as $pattern ) {
				$extra = glob( $pattern );
				if ( is_array( $extra ) ) {
					$targets = array_merge( $targets, $extra );
				}
			}
		}
		foreach ( array_unique( $targets ) as $target ) {
			self::delete_tree( $target );
		}
		wp_clean_plugins_cache( false );
	}

	/**
	 * Cancella ricorsivamente un file o una cartella (solo percorsi del fixture).
	 *
	 * @param string $path Percorso.
	 */
	private static function delete_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( (array) $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::delete_tree( $path . '/' . $entry );
			}
		}
		@rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// -----------------------------------------------------------------------
	// Tabella di log
	// -----------------------------------------------------------------------

	/**
	 * Id massimo della tabella di log (0 se vuota).
	 *
	 * @return int
	 */
	protected function max_log_id() {
		global $wpdb;
		$table = self::call( 'wphc_update_log_table' );

		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Righe di log scritte dal test in corso (id oltre la baseline), in ordine di inserimento.
	 *
	 * @param string $where Condizione SQL aggiuntiva senza input utente (es. "type = 'plugin'").
	 * @return array<int,array<string,mixed>>
	 */
	protected function log_rows( $where = '1=1' ) {
		global $wpdb;
		$table = self::call( 'wphc_update_log_table' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND ({$where}) ORDER BY id ASC", $this->log_baseline ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Le sole fasi (phase) delle righe di log del test, nell'ordine.
	 *
	 * @param string $where Condizione SQL aggiuntiva.
	 * @return string[]
	 */
	protected function log_phases( $where = '1=1' ) {
		return array_map(
			static function ( $row ) {
				return $row['phase'];
			},
			$this->log_rows( $where )
		);
	}

	// -----------------------------------------------------------------------
	// Golden con scrub dei campi non deterministici
	// -----------------------------------------------------------------------

	/**
	 * Come assert_golden() ma sostituisce i campi non deterministici: gli id della tabella di log
	 * (AUTO_INCREMENT sopravvive al rollback dei test) e le durate in millisecondi.
	 *
	 * @param string           $name           Nome del golden (sotto golden/).
	 * @param WP_REST_Response $response       Risposta.
	 * @param array            $options_before Option prima della richiesta.
	 * @param string[]         $env_paths      Percorsi (notazione a punti nello snapshot) da rendere indipendenti dall'ambiente.
	 */
	protected function assert_update_golden( $name, WP_REST_Response $response, array $options_before, array $env_paths = array() ) {
		// "Ultimo accesso": e' un timestamp ISO al secondo, scritto da ogni richiesta autenticata. Se il test ha
		// gia' fatto un'altra richiesta nello stesso secondo il valore non cambia e l'option non risulta
		// "scritta", altrimenti si': il golden dipenderebbe dal secondo in cui gira. Lo si esclude (la scrittura
		// dell'accesso e' verificata a parte, con asserzioni dirette).
		$options_after = $this->plugin_options();
		unset( $options_before['wp_health_check_last_request_at'], $options_after['wp_health_check_last_request_at'] );

		$snapshot = WPHC_Golden::capture( $response, $options_before, $options_after );
		// Valori che dipendono dall'ambiente (es. DISABLE_WP_CRON): restano `<ENV:tipo>`, cioe' conta solo il tipo.
		foreach ( $env_paths as $path ) {
			$snapshot = WPHC_Golden::mask_path( $snapshot, explode( '.', $path ) );
		}
		WPHC_Golden::assert_matches( $this, $name, self::scrub( $snapshot ) );
	}

	/**
	 * Sostituisce ricorsivamente gli interi di 'log_id', 'duration_ms' e gli elementi di 'correlations', e
	 * l'`id` SOLO delle voci di `entries[*]` (le righe della tabella di log, AUTO_INCREMENT): un `id` in un
	 * altro punto dello snapshot non e' volatile e resta visibile.
	 *
	 * @param mixed    $value  Valore.
	 * @param string[] $parent Percorso del nodo corrente (chiavi dalla radice), per riconoscere `entries[*].id`.
	 * @return mixed
	 */
	public static function scrub( $value, array $parent = array() ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		// L'`id` e' volatile solo dentro entries[N]: il nodo padre e' un elemento di una lista chiamata `entries`.
		$in_entries = count( $parent ) >= 2 && 'entries' === $parent[ count( $parent ) - 2 ];
		$out        = array();
		foreach ( $value as $key => $item ) {
			if ( 'log_id' === $key && is_int( $item ) ) {
				$out[ $key ] = '<LOG_ID>';
			} elseif ( 'id' === $key && is_int( $item ) && $in_entries ) {
				$out[ $key ] = '<LOG_ID>';
			} elseif ( 'duration_ms' === $key && is_int( $item ) ) {
				$out[ $key ] = '<MS>';
			} elseif ( 'correlations' === $key && is_array( $item ) ) {
				$out[ $key ] = array_fill( 0, count( $item ), '<LOG_ID>' );
			} else {
				$out[ $key ] = self::scrub( $item, array_merge( $parent, array( (string) $key ) ) );
			}
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Bulk
	// -----------------------------------------------------------------------

	/**
	 * Documento del job bulk corrente (lettura fresca) o null.
	 *
	 * @return array|null
	 */
	protected function bulk_job() {
		return self::call( 'wphc_bulk_get_job', true );
	}

	/**
	 * Esegue un tick del drain come farebbe WP-Cron: si lancia l'AZIONE `wphc_bulk_update_tick` (non la
	 * funzione per nome), cosi' il test prova anche che l'hook e' registrato e non dipende dal nome della
	 * funzione che il refactoring sposta. Simula il contesto WP-Cron (niente scrittura di "ultimo accesso") e
	 * il fatto che WP-Cron toglie l'evento singolo prima di lanciarlo (altrimenti l'evento di
	 * accodamento, ancora pianificato, impedirebbe al tick di pianificare il successivo).
	 */
	protected function run_tick() {
		// Il callback del tick deve essere agganciato all'hook cron, con priorita' 10.
		$this->assertSame( 10, has_action( 'wphc_bulk_update_tick', self::cb( 'wphc_bulk_run_tick' ) ), 'il tick del bulk non risulta agganciato a wphc_bulk_update_tick' );
		wp_clear_scheduled_hook( 'wphc_bulk_update_tick' );
		add_filter( 'wp_doing_cron', '__return_true' );
		do_action( 'wphc_bulk_update_tick' );
		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	/**
	 * Lancia l'evento cron del retry del webhook (`wphc_webhook_retry`) invece di chiamare la funzione per nome,
	 * dopo aver verificato che il callback sia agganciato all'hook (priorita' 10).
	 */
	protected function run_webhook_retry() {
		$this->assertSame( 10, has_action( 'wphc_webhook_retry', self::cb( 'wphc_maybe_retry_webhook' ) ), 'il retry del webhook non risulta agganciato a wphc_webhook_retry' );
		do_action( 'wphc_webhook_retry' );
	}

	/**
	 * Azzera i backoff degli item in attesa, cosi' il tick successivo li riprova subito.
	 */
	protected function bulk_rewind_backoff() {
		$job = $this->bulk_job();
		foreach ( $job['items'] as $index => $item ) {
			if ( 'pending' === $item['state'] ) {
				$job['items'][ $index ]['next_after'] = 0;
			}
		}
		self::call( 'wphc_bulk_save_job', $job );
	}

	/**
	 * Accoda un job bulk via REST e ne verifica l'accettazione (202).
	 *
	 * @param array $body Body della richiesta.
	 * @return array Dati della risposta.
	 */
	protected function enqueue_bulk( array $body ) {
		$response = $this->upd( 'POST', '/update/bulk', $body );
		$this->assertSame( 202, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}
}
