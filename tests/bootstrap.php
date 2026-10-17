<?php
/**
 * Bootstrap della suite di caratterizzazione di wp-health-check.
 *
 * Passi:
 *  1. carica l'autoload di Composer (percorso sovrascrivibile con WPHC_VENDOR_DIR,
 *     utile per la matrice PHP 7.4 con un vendor dedicato);
 *  2. individua la test library di WordPress (wp-phpunit) e il file di config;
 *  3. sceglie il file di plugin SOTTO TEST: per default una COPIA in una cartella temporanea
 *     (una per run, rimossa a fine run; mai il file del repo, che il self-update del plugin potrebbe
 *     riscrivere), dopo aver verificato che mu-plugins/wp-health-check.php coincida con la build di
 *     src/ (bin/build.php in un processo figlio; modo da WPHC_BUILD_MODE come "build:check": strip di default,
 *     dev con WPHC_BUILD_MODE=dev) e che la copia coincida con quella build;
 *     oppure il file indicato esplicitamente con WPHC_PLUGIN_FILE, che deve ANCH'ESSO coincidere con la build di
 *     src/ (stesso processo figlio) salvo WPHC_ALLOW_FOREIGN_BUILD=1 (per provare un candidato esterno). Stampa su
 *     STDERR lo SHA-256 del file sotto test. Registra una guardia che a fine run segnala qualunque scrittura sul
 *     plugin o sul file del repo;
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
// carica una COPIA in sys_get_temp_dir()/wphc-under-test-<id>/: una cartella per run (id univoco, quindi
// piu' run in parallelo non si pestano i piedi e non servono pulizie di residui), rimossa a fine run.
// Con WPHC_PLUGIN_FILE si indica invece un file esplicito (es. una copia della build in una cartella scratch):
// e' una copia di lavoro anch'essa, ma il file del repo in mu-plugins/ e' sempre rifiutato. Anche il file esplicito
// deve coincidere con la build di src/ (un candidato diverso darebbe un falso verde: la suite proverebbe codice che
// non e' quello del sorgente), salvo il consenso esplicito WPHC_ALLOW_FOREIGN_BUILD=1.
$wphc_repo_plugin = realpath( $wphc_project_dir . '/mu-plugins/wp-health-check.php' );
if ( false === $wphc_repo_plugin ) {
	fwrite( STDERR, "File del plugin del repo non trovato: {$wphc_project_dir}/mu-plugins/wp-health-check.php\n" );
	exit( 1 );
}

/**
 * Build di src/ (dev o strip, vedi $strip), ottenuta con bin/build.php in un processo figlio.
 *
 * Un processo figlio e non un require_once di bin/build.php: la build definisce funzioni globali `wphc_build_*`
 * che potrebbero collidere con quelle del plugin (nella 1.38.1 esistono wphc_build_signed_payload e simili) e
 * andrebbero escluse dal controllo di provenienza dei simboli. Cosi' il file prodotto e' anche indipendente da
 * ogni lettura del file del repo.
 *
 * @param string $project_dir Radice del progetto.
 * @param bool   $strip       True per la build strip, false per la dev (--no-strip).
 * @return string Contenuto del file prodotto (solo LF).
 */
function wphc_test_build_from_src( $project_dir, $strip ) {
	$out = sys_get_temp_dir() . '/wphc-ref-build-' . uniqid( '', true ) . '.php';
	$cmd = escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M ' . escapeshellarg( $project_dir . '/bin/build.php' ) . ( $strip ? ' --strip --output ' : ' --no-strip --output ' ) . escapeshellarg( $out ) . ' 2>&1';
	exec( $cmd, $lines, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	$code = 0 === $status && is_file( $out ) ? file_get_contents( $out ) : false;
	if ( is_file( $out ) ) {
		@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	}
	if ( false === $code || '' === $code ) {
		fwrite( STDERR, "Build di riferimento di src/ non riuscita (exit {$status}):\n  " . implode( "\n  ", $lines ) . "\n" );
		exit( 1 );
	}
	return $code;
}

$wphc_cleanup_copy     = null; // Pulizia della cartella della copia (solo in modalita' di default).
$wphc_guard_registered = false; // True quando la guardia di fine run e' registrata (e' lei a chiamare la pulizia).

$wphc_plugin_file = getenv( 'WPHC_PLUGIN_FILE' );
$wphc_explicit    = false !== $wphc_plugin_file && '' !== $wphc_plugin_file;

// 3a. Modo della build di riferimento: e' quello di "build:check", WPHC_BUILD_MODE (dev | strip), strip di default
// (dal passaggio finale il file committato in mu-plugins/ e' la build strip).
$wphc_mode = getenv( 'WPHC_BUILD_MODE' );
$wphc_mode = false === $wphc_mode || '' === $wphc_mode ? 'strip' : $wphc_mode;
if ( ! in_array( $wphc_mode, array( 'dev', 'strip' ), true ) ) {
	fwrite( STDERR, "WPHC_BUILD_MODE non valido: {$wphc_mode} (ammessi: dev, strip).\n" );
	exit( 1 );
}

// Consenso esplicito a provare un file esterno diverso da src/ (ha senso solo con WPHC_PLUGIN_FILE).
$wphc_allow_foreign = '1' === getenv( 'WPHC_ALLOW_FOREIGN_BUILD' );

// La build di riferimento si calcola in un processo figlio, sia per il file del repo sia per un file esplicito; l'unico
// caso in cui non serve e' un file esplicito con il consenso a un file esterno.
$wphc_built     = null;
$wphc_built_sha = null;
if ( ! $wphc_explicit || ! $wphc_allow_foreign ) {
	$wphc_built     = wphc_test_build_from_src( $wphc_project_dir, 'strip' === $wphc_mode );
	$wphc_built_sha = hash( 'sha256', $wphc_built );
	if ( false !== strpos( $wphc_built, "\0" ) ) {
		fwrite( STDERR, "La build {$wphc_mode} di src/ contiene byte NUL.\n" );
		exit( 1 );
	}
}

if ( $wphc_explicit ) {
	// 3a'. File esplicito: deve coincidere BYTE PER BYTE con la build di src/ (nessuna normalizzazione dei fine riga: il
	// file si usa cosi' com'e', e un CRLF cambierebbe i byte provati rispetto a quelli distribuiti), salvo
	// WPHC_ALLOW_FOREIGN_BUILD=1. Un candidato diverso da src/ darebbe un falso verde.
	if ( ! $wphc_allow_foreign ) {
		$wphc_explicit_raw = is_readable( $wphc_plugin_file ) ? file_get_contents( $wphc_plugin_file ) : false;
		if ( false === $wphc_explicit_raw ) {
			fwrite( STDERR, "File del plugin non leggibile: {$wphc_plugin_file}\n" );
			exit( 1 );
		}
		if ( $wphc_explicit_raw !== $wphc_built ) {
			fwrite( STDERR, "WPHC_PLUGIN_FILE ({$wphc_plugin_file}, sha256 " . hash( 'sha256', $wphc_explicit_raw ) . ") non coincide con la build {$wphc_mode} di src/ (sha256 {$wphc_built_sha}): rigenerare il file con `bin/build.php` oppure, per provare un candidato esterno di proposito, impostare WPHC_ALLOW_FOREIGN_BUILD=1.\n" );
			exit( 1 );
		}
	}
} else {
	// 3a''. Il file del repo deve essere la build di src/: un "composer run build" dimenticato darebbe un falso verde
	// (si testerebbe un mu-plugins/ vecchio mentre src/ e' cambiato). Il confronto e' sul testo con gli a capo
	// normalizzati a LF (la copia di lavoro su Windows puo' essere CRLF con core.autocrlf; nell'indice e' LF).
	$wphc_repo_lf = str_replace( "\r\n", "\n", (string) file_get_contents( $wphc_repo_plugin ) );
	if ( $wphc_repo_lf !== $wphc_built ) {
		fwrite( STDERR, 'mu-plugins/wp-health-check.php (sha256 ' . hash( 'sha256', $wphc_repo_lf ) . ") non coincide con la build {$wphc_mode} di src/ (sha256 {$wphc_built_sha}): eseguire `composer run build` (file distribuito = build strip) e rilanciare la suite.\n" );
		exit( 1 );
	}

	// 3b. Copia di lavoro in una cartella propria del run.
	$wphc_copy_dir = sys_get_temp_dir() . '/wphc-under-test-' . uniqid( '', true );
	if ( ! mkdir( $wphc_copy_dir, 0777, true ) && ! is_dir( $wphc_copy_dir ) ) {
		fwrite( STDERR, "Impossibile creare {$wphc_copy_dir}.\n" );
		exit( 1 );
	}
	$wphc_plugin_file = $wphc_copy_dir . '/wp-health-check.php';

	// Pulizia a fine run: toglie tutto cio' che e' nella cartella (anche .bak e .wphc-new-*.tmp lasciati da un
	// install_release) e la cartella. La guardia di fine run la chiama DOPO i propri controlli (che devono ancora
	// vedere il file); la funzione registrata qui sotto copre i soli casi in cui la guardia non e' ancora registrata
	// (uscita anticipata del bootstrap).
	$wphc_cleanup_copy = static function () use ( $wphc_copy_dir ) {
		if ( ! is_dir( $wphc_copy_dir ) ) {
			return;
		}
		foreach ( (array) scandir( $wphc_copy_dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$entry_path = $wphc_copy_dir . '/' . $entry;
			is_dir( $entry_path ) ? @rmdir( $entry_path ) : @unlink( $entry_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		@rmdir( $wphc_copy_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir
	};
	register_shutdown_function(
		static function () use ( &$wphc_guard_registered, $wphc_cleanup_copy ) {
			if ( ! $wphc_guard_registered ) {
				$wphc_cleanup_copy();
			}
		}
	);

	// Scrittura con file_put_contents invece di copy(): in Docker, con il repo montato come bind mount da Windows,
	// la copy() di PHP 8.1 puo' produrre un file con una coda di byte NUL (problema dell'ambiente, non del plugin) e la
	// suite fallirebbe con un ParseError. Dopo ogni scrittura si RILEGGE la copia da disco (clearstatcache) e la si
	// confronta con la build di riferimento, calcolata dal processo figlio e non dalla stessa stringa appena scritta;
	// se differisce, o contiene un byte NUL, si riprova (fino a 3 volte), poi si interrompe con un errore chiaro.
	$wphc_copy_ok = false;
	for ( $wphc_attempt = 1; $wphc_attempt <= 3 && ! $wphc_copy_ok; $wphc_attempt++ ) {
		clearstatcache();
		$wphc_copy_ok = false !== file_put_contents( $wphc_plugin_file, $wphc_built );
		if ( $wphc_copy_ok ) {
			clearstatcache();
			$wphc_copy_read = file_get_contents( $wphc_plugin_file );
			$wphc_copy_ok   = false !== $wphc_copy_read
				&& false === strpos( $wphc_copy_read, "\0" )
				&& hash( 'sha256', $wphc_copy_read ) === $wphc_built_sha
				&& hash_file( 'sha256', $wphc_plugin_file ) === $wphc_built_sha;
		}
	}
	if ( ! $wphc_copy_ok ) {
		fwrite( STDERR, "Impossibile copiare il plugin in {$wphc_plugin_file} (copia assente, con byte NUL o diversa dalla build di src/ dopo 3 tentativi).\n" );
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
	fwrite( STDERR, "WPHC_PLUGIN_FILE punta a mu-plugins/wp-health-check.php del repo: indicare una copia (o non impostare la variabile: la suite ne crea una in " . sys_get_temp_dir() . "/wphc-under-test-<id>).\n" );
	exit( 1 );
}
// Un .bak gia' presente accanto a un file esplicito indica un run precedente andato storto.
if ( file_exists( $wphc_plugin_real . '.bak' ) ) {
	fwrite( STDERR, "Esiste gia' {$wphc_plugin_real}.bak: rimuoverlo prima di rilanciare la suite.\n" );
	exit( 1 );
}
define( 'WPHC_PLUGIN_FILE', $wphc_plugin_real );

// SHA-256 del file sotto test su STDERR (in entrambe le modalita'): permette di riconoscere nei log quale build e'
// stata provata. Con WPHC_PLUGIN_FILE il file coincide comunque con la build di src/, salvo WPHC_ALLOW_FOREIGN_BUILD=1.
// Si stampa UNA volta per run: i test @runInSeparateProcess rilanciano questo bootstrap in un figlio che eredita
// l'ambiente, e PHPUnit tratterebbe qualunque cosa scritta dal figlio su STDERR come un errore del test.
if ( false === getenv( 'WPHC_SHA_PRINTED' ) ) {
	putenv( 'WPHC_SHA_PRINTED=1' );
	fwrite( STDERR, 'Plugin sotto test: ' . WPHC_PLUGIN_FILE . ' sha256 ' . hash_file( 'sha256', WPHC_PLUGIN_FILE ) . ( null !== $wphc_cleanup_copy ? ' (copia della build di src/, uguale a mu-plugins/)' : ( $wphc_allow_foreign ? ' (file indicato da WPHC_PLUGIN_FILE, build ESTERNA ammessa da WPHC_ALLOW_FOREIGN_BUILD=1)' : ' (file indicato da WPHC_PLUGIN_FILE, uguale alla build di src/)' ) ) . PHP_EOL );
}

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

// I test marcati @runInSeparateProcess (tests/test-core-auto-update-state.php) rilanciano QUESTO bootstrap in un
// processo figlio. Senza WP_TESTS_SKIP_INSTALL la test library reinstallerebbe il database (DROP e CREATE delle
// tabelle di WordPress) mentre il processo padre e' fermo in attesa del risultato, perdendo per esempio
// l'option wp_health_check_db_version. Si imposta DOPO l'avvio del padre (che deve installare), cosi' il figlio,
// che eredita l'ambiente, salta l'installazione.
putenv( 'WP_TESTS_SKIP_INSTALL=1' );

// -----------------------------------------------------------------------
// 6. Controllo: il plugin caricato e' proprio quello richiesto
// -----------------------------------------------------------------------
// Mappa unica "funzione 1.38.1 -> metodo del refactoring" (vedi tests/refactor-map.php): serve a
// riconoscere il plugin sia nella forma a funzioni (1.38.1) sia in quella a classi.
$wphc_refactor_map = require __DIR__ . '/refactor-map.php';

// Sentinella: wphc_normalize_url, risolta SEMPRE tramite la mappa (qui la classe base dei test non e' ancora caricata,
// quindi non si puo' usare WPHC_Test_Case::cb()): la funzione se esiste (1.38.1), altrimenti il metodo che la sostituisce.
$wphc_sentinel_name = 'wphc_normalize_url';
if ( function_exists( $wphc_sentinel_name ) ) {
	$wphc_sentinel = new ReflectionFunction( $wphc_sentinel_name );
} elseif ( isset( $wphc_refactor_map[ $wphc_sentinel_name ] ) && method_exists( $wphc_refactor_map[ $wphc_sentinel_name ][0], $wphc_refactor_map[ $wphc_sentinel_name ][1] ) ) {
	$wphc_sentinel = new ReflectionMethod( $wphc_refactor_map[ $wphc_sentinel_name ][0], $wphc_refactor_map[ $wphc_sentinel_name ][1] );
} else {
	fwrite( STDERR, "Il plugin non e' stato caricato (ne' {$wphc_sentinel_name} ne' il metodo che la sostituisce nella mappa tests/refactor-map.php).\n" );
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
// exit(1) cosi' il run risulta rosso anche se tutti i test erano verdi. Dopo i controlli (che devono ancora vedere
// il file) rimuove la cartella della copia di lavoro, prima dell'eventuale exit(1): un exit dentro una funzione di
// chiusura interrompe le funzioni successive.
$wphc_guard_registered = true;
register_shutdown_function(
	static function () use ( $wphc_guard_files, $wphc_cleanup_copy ) {
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
		if ( null !== $wphc_cleanup_copy ) {
			$wphc_cleanup_copy();
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
