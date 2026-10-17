<?php
/**
 * Bootstrap della suite di caratterizzazione di wp-health-check.
 *
 * Passi:
 *  1. carica l'autoload di Composer (percorso sovrascrivibile con WPHC_VENDOR_DIR,
 *     utile per la matrice PHP 7.4 con un vendor dedicato);
 *  2. individua la test library di WordPress (wp-phpunit) e il file di config;
 *  3. sceglie il file di plugin SOTTO TEST: per default una COPIA in una cartella temporanea
 *     (mai il file del repo, che il self-update del plugin potrebbe riscrivere), oppure il file
 *     indicato esplicitamente con WPHC_PLUGIN_FILE (build del refactoring); registra una guardia
 *     che a fine run segnala qualunque scrittura sul plugin o sul file del repo;
 *  4. definisce, se mancano, le costanti di TEST del plugin (chiavi derivate da seed
 *     deterministici, mai quelle di produzione), verifica che siano coerenti e che nessuna
 *     costante possa raggiungere servizi reali (release, webhook, email);
 *  5. aggancia il caricamento del plugin a `muplugins_loaded` (con un error handler severo:
 *     un warning in fase di caricamento interrompe la suite);
 *  6. avvia WordPress e controlla la provenienza dei simboli `wphc_*` / `WPHC_*`.
 *
 * @package WP_Health_Check
 */

// Buffer di output globale: il plugin chiama header()/header_remove() direttamente e, in CLI,
// PHP segnala "headers already sent" non appena e' stato prodotto un qualunque output (la
// test library di WordPress stampa messaggi all'avvio). Finche' c'e' un buffer attivo
// headers_sent() resta false. Il report di PHPUnit va su stderr (stderr="true" in phpunit.xml.dist).
ob_start();

// Directory del progetto (la radice che contiene mu-plugins/, tests/, vendor/).
$wphc_project_dir = dirname( __DIR__ );

// -----------------------------------------------------------------------
// 1. Autoload di Composer
// -----------------------------------------------------------------------
$wphc_vendor_dir = getenv( 'WPHC_VENDOR_DIR' );
if ( false === $wphc_vendor_dir || '' === $wphc_vendor_dir ) {
	$wphc_vendor_dir = $wphc_project_dir . '/vendor';
}
if ( ! is_readable( $wphc_vendor_dir . '/autoload.php' ) ) {
	fwrite( STDERR, "vendor/autoload.php non trovato in {$wphc_vendor_dir}: esegui `composer install`.\n" );
	exit( 1 );
}
require_once $wphc_vendor_dir . '/autoload.php';

// Percorso delle polyfill richieste dalla test library di WordPress.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $wphc_vendor_dir . '/yoast/phpunit-polyfills' );
}

// -----------------------------------------------------------------------
// 2. Test library di WordPress
// -----------------------------------------------------------------------
// Si usa la test library di wp-phpunit (dipendenza di Composer, fissata alla stessa versione di
// WordPress del core di wp-env). Puo' essere sostituita con WPHC_WP_TESTS_DIR.
// wp-phpunit non sa dove stia il file di configurazione con le credenziali del DB e le costanti
// di test: dentro wp-env c'e' gia' quello della test library del container
// ($WP_TESTS_DIR/wp-tests-config.php, che contiene anche env.tests.config di .wp-env.json) e lo si
// indica con WP_PHPUNIT__TESTS_CONFIG, se non e' gia' impostata.
$wphc_tests_dir = getenv( 'WPHC_WP_TESTS_DIR' );
if ( false === $wphc_tests_dir || '' === $wphc_tests_dir ) {
	$wphc_tests_dir = $wphc_vendor_dir . '/wp-phpunit/wp-phpunit';
}
$wphc_container_dir = getenv( 'WP_TESTS_DIR' );
if (
	false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' )
	&& false !== $wphc_container_dir && is_readable( $wphc_container_dir . '/wp-tests-config.php' )
) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $wphc_container_dir . '/wp-tests-config.php' );
}
if ( ! is_readable( $wphc_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Test library di WordPress non trovata in {$wphc_tests_dir}.\n" );
	exit( 1 );
}

// -----------------------------------------------------------------------
// 3. File del plugin sotto test (B1: la suite non deve poter riscrivere il plugin del repo)
// -----------------------------------------------------------------------
// Il plugin, quando riceve una release valida, riscrive __FILE__ e crea un .bak accanto
// (wphc_install_release). Se __FILE__ fosse il file del repo, un ramo positivo del self-update
// (le chiavi k2 di test firmano release valide) lo sovrascriverebbe. Per questo per default si
// carica una COPIA in sys_get_temp_dir()/wphc-under-test/, ricreata a ogni avvio.
// Con WPHC_PLUGIN_FILE si indica invece un file esplicito (es. la build del refactoring in dist/):
// e' una copia di lavoro anch'essa, ma il file del repo in mu-plugins/ e' sempre rifiutato.
$wphc_repo_plugin = realpath( $wphc_project_dir . '/mu-plugins/wp-health-check.php' );
if ( false === $wphc_repo_plugin ) {
	fwrite( STDERR, "File del plugin del repo non trovato: {$wphc_project_dir}/mu-plugins/wp-health-check.php\n" );
	exit( 1 );
}

$wphc_plugin_file = getenv( 'WPHC_PLUGIN_FILE' );
if ( false === $wphc_plugin_file || '' === $wphc_plugin_file ) {
	// Copia di lavoro: la cartella viene ripulita da residui di run interrotti (.bak, .tmp).
	$wphc_copy_dir = sys_get_temp_dir() . '/wphc-under-test';
	if ( ! is_dir( $wphc_copy_dir ) && ! mkdir( $wphc_copy_dir, 0777, true ) && ! is_dir( $wphc_copy_dir ) ) {
		fwrite( STDERR, "Impossibile creare {$wphc_copy_dir}.\n" );
		exit( 1 );
	}
	foreach ( array( '*.bak', '.wphc-new-*.tmp' ) as $wphc_stale_pattern ) {
		foreach ( (array) glob( $wphc_copy_dir . '/' . $wphc_stale_pattern ) as $wphc_stale_file ) {
			is_dir( $wphc_stale_file ) ? @rmdir( $wphc_stale_file ) : @unlink( $wphc_stale_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	$wphc_plugin_file = $wphc_copy_dir . '/wp-health-check.php';
	if ( ! copy( $wphc_repo_plugin, $wphc_plugin_file ) ) {
		fwrite( STDERR, "Impossibile copiare il plugin in {$wphc_plugin_file}.\n" );
		exit( 1 );
	}
	@chmod( $wphc_plugin_file, 0666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	clearstatcache();
}
$wphc_plugin_real = realpath( $wphc_plugin_file );
if ( false === $wphc_plugin_real ) {
	fwrite( STDERR, "File del plugin non trovato: {$wphc_plugin_file}\n" );
	exit( 1 );
}
// Un file esplicito non puo' essere quello del repo (nemmeno via symlink o percorso relativo).
if ( $wphc_plugin_real === $wphc_repo_plugin ) {
	fwrite( STDERR, "WPHC_PLUGIN_FILE punta a mu-plugins/wp-health-check.php del repo: indicare una copia (o non impostare la variabile: la suite ne crea una in " . sys_get_temp_dir() . "/wphc-under-test).\n" );
	exit( 1 );
}
// Un .bak gia' presente accanto a un file esplicito indica un run precedente andato storto.
if ( file_exists( $wphc_plugin_real . '.bak' ) ) {
	fwrite( STDERR, "Esiste gia' {$wphc_plugin_real}.bak: rimuoverlo prima di rilanciare la suite.\n" );
	exit( 1 );
}
define( 'WPHC_PLUGIN_FILE', $wphc_plugin_real );

// Guardia di integrita' a fine run: ne' il file sotto test ne' quello del repo devono cambiare,
// e accanto al file sotto test non deve comparire un .bak (il segno di una install_release scritta).
// Si calcolano gli hash ORA, prima di qualunque test. La registrazione avviene piu' avanti (dopo
// l'avvio di WordPress) cosi' la funzione gira dopo quelle della test library.
$wphc_guard_files = array(
	'plugin sotto test'  => array( WPHC_PLUGIN_FILE, hash_file( 'sha256', WPHC_PLUGIN_FILE ) ),
	'plugin del repo'    => array( $wphc_repo_plugin, hash_file( 'sha256', $wphc_repo_plugin ) ),
);

// Seed di test (devono coincidere con quelli di tests/tools/gen_contract_vectors.py):
// k1 = bytes(range(32)), come hub/tests/test_enroll_v2.py; k2 = bytes(range(32, 64)).
define( 'WPHC_TEST_SEED_K1', implode( '', array_map( 'chr', range( 0, 31 ) ) ) );
define( 'WPHC_TEST_SEED_K2', implode( '', array_map( 'chr', range( 32, 63 ) ) ) );

/**
 * Chiave pubblica Ed25519 (base64 standard) derivata da un seed.
 *
 * @param string $seed Seed di 32 byte.
 * @return string Chiave pubblica in base64.
 */
function wphc_test_pubkey_from_seed( $seed ) {
	return base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) ) );
}

// -----------------------------------------------------------------------
// 4-5. Caricamento del plugin e avvio di WordPress
// -----------------------------------------------------------------------
require_once $wphc_tests_dir . '/includes/functions.php';

// Il plugin si carica a `muplugins_loaded`, come farebbe WordPress da wp-content/mu-plugins.
// Il file NON deve essere presente anche in wp-content/mu-plugins dell'ambiente di test
// (altrimenti verrebbe dichiarato due volte): .wp-env.json lo mappa solo nell'ambiente development.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Le costanti arrivano normalmente da .wp-env.json (env.tests.config), che wp-env
		// riporta nel wp-tests-config.php della test library: a questo punto (muplugins_loaded)
		// sono gia' definite. Se la config in uso non le contiene (es. wp-phpunit da solo) si
		// definiscono qui; il plugin le protegge con `! defined`, quindi la prima definizione
		// vince. NON si definiscono prima del caricamento della config, altrimenti PHP
		// segnalerebbe una doppia define.
		$defaults = array(
			'WP_HEALTH_CHECK_CENTRAL_PUBKEY'      => wphc_test_pubkey_from_seed( WPHC_TEST_SEED_K1 ),
			'WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2'   => wphc_test_pubkey_from_seed( WPHC_TEST_SEED_K2 ),
			'WP_HEALTH_CHECK_RELEASE_URL'         => '',
			'WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL' => 'https://webhook.invalid/wphc-test',
			'WP_HEALTH_CHECK_ALERT_EMAIL'         => 'alert@example.invalid',
			'WP_HTTP_BLOCK_EXTERNAL'              => true,
		);
		foreach ( $defaults as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		// Guardia di sicurezza: le chiavi in uso DEVONO essere quelle derivate dai seed di test.
		// Se un wp-config definisse le chiavi di produzione (o altro) i test firmerebbero con
		// seed che non corrispondono, o peggio girerebbero contro chiavi reali.
		if (
			WP_HEALTH_CHECK_CENTRAL_PUBKEY !== wphc_test_pubkey_from_seed( WPHC_TEST_SEED_K1 )
			|| WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2 !== wphc_test_pubkey_from_seed( WPHC_TEST_SEED_K2 )
		) {
			fwrite( STDERR, "Le costanti WP_HEALTH_CHECK_CENTRAL_PUBKEY[_K2] non derivano dai seed di test: interrompo." . PHP_EOL );
			exit( 1 );
		}

		// Guardia (M-b): nessuna costante di test deve poter raggiungere un servizio reale. Il
		// RELEASE_URL vuoto spegne la fonte "hub" del self-update; il webhook di default e
		// l'email di alert devono usare il TLD riservato `.invalid` (non risolvibile, non recapitabile).
		$webhook_host = (string) wphc_test_url_host( WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL );
		$alert_domain = (string) substr( strrchr( WP_HEALTH_CHECK_ALERT_EMAIL, '@' ), 1 );
		if (
			'' !== WP_HEALTH_CHECK_RELEASE_URL
			|| '.invalid' !== substr( $webhook_host, -8 )
			|| '.invalid' !== substr( $alert_domain, -8 )
		) {
			fwrite( STDERR, "Le costanti di test devono avere WP_HEALTH_CHECK_RELEASE_URL vuota e webhook ed email su dominio .invalid: interrompo." . PHP_EOL );
			exit( 1 );
		}

		// Caricamento del plugin con un error handler severo: un warning o un notice mentre il file
		// si carica (per esempio un define() senza `! defined`, che con le costanti di test gia'
		// presenti non e' un errore silenzioso di produzione) interrompe tutto. Gli errori
		// silenziati con @ restano silenziati.
		set_error_handler(
			static function ( $errno, $errstr, $errfile, $errline ) {
				if ( ! ( error_reporting() & $errno ) ) {
					return false;
				}
				fwrite( STDERR, "Errore PHP durante il caricamento del plugin ({$errno}): {$errstr} in {$errfile}:{$errline}" . PHP_EOL );
				exit( 1 );
			},
			E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_USER_ERROR | E_RECOVERABLE_ERROR
		);
		require WPHC_PLUGIN_FILE;
		restore_error_handler();
	}
);

/**
 * Host di un URL, senza dipendere da funzioni di WordPress (a muplugins_loaded wp_parse_url esiste,
 * ma questa guardia deve funzionare anche se la test library cambia l'ordine di caricamento).
 *
 * @param string $url URL.
 * @return string Host in minuscolo, o stringa vuota.
 */
function wphc_test_url_host( $url ) {
	$host = parse_url( (string) $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	return is_string( $host ) ? strtolower( $host ) : '';
}

require $wphc_tests_dir . '/includes/bootstrap.php';

// -----------------------------------------------------------------------
// 6. Controllo: il plugin caricato e' proprio quello richiesto
// -----------------------------------------------------------------------
// Mappa unica "funzione 1.38.1 -> metodo del refactoring" (vedi tests/refactor-map.php): serve a
// riconoscere il plugin sia nella forma a funzioni (1.38.1) sia in quella a classi.
$wphc_refactor_map = require __DIR__ . '/refactor-map.php';

if ( function_exists( 'wphc_normalize_url' ) ) {
	$wphc_sentinel = new ReflectionFunction( 'wphc_normalize_url' );
} elseif ( isset( $wphc_refactor_map['wphc_normalize_url'] ) && class_exists( $wphc_refactor_map['wphc_normalize_url'][0], false ) ) {
	$wphc_sentinel = new ReflectionClass( $wphc_refactor_map['wphc_normalize_url'][0] );
} else {
	fwrite( STDERR, "Il plugin non e' stato caricato (ne' wphc_normalize_url ne' il metodo che la sostituisce).\n" );
	exit( 1 );
}
$wphc_loaded_from = realpath( (string) $wphc_sentinel->getFileName() );
if ( $wphc_loaded_from !== WPHC_PLUGIN_FILE ) {
	fwrite( STDERR, "Plugin caricato da {$wphc_loaded_from}, atteso " . WPHC_PLUGIN_FILE . ".\n" );
	exit( 1 );
}

// Provenienza di TUTTI i simboli del plugin (I12): ogni funzione `wphc_*` (tranne `wphc_test_*`) e ogni
// classe `WPHC_*` non di test deve stare sotto la cartella del file indicato. Il file del repo non
// deve essere stato incluso se il file indicato e' un altro. Si controlla qui, prima che i file di
// supporto dei test definiscano i propri simboli (sono tutti sotto tests/).
$wphc_plugin_dir = dirname( WPHC_PLUGIN_FILE ) . DIRECTORY_SEPARATOR;
$wphc_tests_root = realpath( __DIR__ ) . DIRECTORY_SEPARATOR;
$wphc_strays     = array();

foreach ( get_defined_functions()['user'] as $wphc_function ) {
	if ( 0 !== strpos( $wphc_function, 'wphc_' ) || 0 === strpos( $wphc_function, 'wphc_test_' ) ) {
		continue;
	}
	$wphc_file = realpath( (string) ( new ReflectionFunction( $wphc_function ) )->getFileName() );
	if ( false === $wphc_file || 0 !== strpos( $wphc_file, $wphc_plugin_dir ) ) {
		$wphc_strays[] = "funzione {$wphc_function} da {$wphc_file}";
	}
}
foreach ( get_declared_classes() as $wphc_class ) {
	if ( 1 !== preg_match( '/^WPHC[_\\\\]/i', $wphc_class ) ) {
		continue;
	}
	$wphc_file = ( new ReflectionClass( $wphc_class ) )->getFileName();
	$wphc_file = false === $wphc_file ? false : realpath( $wphc_file );
	// Le classi di supporto dei test stanno sotto tests/: non sono del plugin.
	if ( false !== $wphc_file && 0 === strpos( $wphc_file, $wphc_tests_root ) ) {
		continue;
	}
	if ( false === $wphc_file || 0 !== strpos( $wphc_file, $wphc_plugin_dir ) ) {
		$wphc_strays[] = "classe {$wphc_class} da {$wphc_file}";
	}
}
if ( WPHC_PLUGIN_FILE !== $wphc_repo_plugin && in_array( $wphc_repo_plugin, array_map( 'realpath', get_included_files() ), true ) ) {
	$wphc_strays[] = "incluso il plugin del repo {$wphc_repo_plugin}";
}
if ( array() !== $wphc_strays ) {
	fwrite( STDERR, "Simboli del plugin fuori da {$wphc_plugin_dir}:\n  " . implode( "\n  ", $wphc_strays ) . "\n" );
	exit( 1 );
}

// Guardia di integrita' (B1): a fine run nessun file del plugin deve essere cambiato e non deve
// esistere un .bak accanto al plugin sotto test ne' un .wphc-new-*.tmp in mu-plugins. Si ferma con
// exit(1) cosi' il run risulta rosso anche se tutti i test erano verdi.
register_shutdown_function(
	static function () use ( $wphc_guard_files ) {
		clearstatcache();
		$problems = array();

		foreach ( $wphc_guard_files as $label => $info ) {
			list( $path, $hash ) = $info;
			if ( ! is_file( $path ) || hash_file( 'sha256', $path ) !== $hash ) {
				$problems[] = "{$label} modificato: {$path}";
			}
		}
		if ( file_exists( WPHC_PLUGIN_FILE . '.bak' ) ) {
			$problems[] = 'esiste ' . WPHC_PLUGIN_FILE . '.bak (install_release ha scritto)';
		}
		foreach ( (array) glob( rtrim( WPMU_PLUGIN_DIR, '/\\' ) . '/.wphc-new-*.tmp' ) as $tmp ) {
			$problems[] = "temporaneo orfano: {$tmp}";
		}

		if ( array() !== $problems ) {
			fwrite( STDERR, PHP_EOL . 'GUARDIA DEL BOOTSTRAP: la suite ha toccato il plugin.' . PHP_EOL . '  ' . implode( PHP_EOL . '  ', $problems ) . PHP_EOL );
			exit( 1 );
		}
	}
);

// Classi di supporto (non sono test: il suffisso della suite e' prefix="test-").
require_once __DIR__ . '/class-wphc-test-case.php';
require_once __DIR__ . '/class-wphc-golden.php';
