<?php
/**
 * Test della build (bin/build.php): PHP puro, senza WordPress ne' PHPUnit.
 *
 * Si lancia con `composer run test:build` oppure `php tests/build/run.php`, anche con PHP 7.4.
 * Il file NON si chiama test-*.php di proposito: la suite PHPUnit del plugin cerca file con
 * quel prefisso sotto tests/ e questo script non deve finire nel suo giro (gira senza WordPress).
 *
 * Tutte le prove usano cartelle temporanee in sys_get_temp_dir(): mai src/ e mai mu-plugins/.
 * Le prove sui sorgenti veri (src/) sono di sola lettura e scrivono solo in cartelle temporanee.
 *
 * Esce con codice 0 se tutto passa, 1 altrimenti.
 */

// 1. Ogni warning o notice diventa un'eccezione: una prova non deve passare "con rumore".
error_reporting( E_ALL );
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

// 2. Carica le funzioni della build senza eseguirne la CLI (la guardia in fondo a build.php lo impedisce).
$root = dirname( __DIR__, 2 );
require $root . '/bin/build.php';

/** Eccezione di una asserzione fallita. */
class Build_Test_Failure extends Exception {
}

/** Cartelle temporanee create dalle prove, rimosse a fine script. */
$GLOBALS['build_test_dirs'] = array();

/**
 * Asserzione semplice.
 *
 * @param bool   $condition Condizione attesa vera.
 * @param string $message   Messaggio se falsa.
 */
function t_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new Build_Test_Failure( $message );
	}
}

/**
 * Crea una cartella src/ temporanea con i file indicati e un manifest.
 *
 * @param array $files    nome file => contenuto.
 * @param array $manifest nome file => ruolo (nell'ordine di concatenazione).
 * @return string Percorso della cartella.
 */
function t_make_src( array $files, array $manifest ) {
	$dir = sys_get_temp_dir() . '/wphc-build-test-' . uniqid( '', true );
	mkdir( $dir, 0777, true );
	$GLOBALS['build_test_dirs'][] = $dir;
	foreach ( $files as $name => $content ) {
		file_put_contents( $dir . '/' . $name, $content );
	}
	file_put_contents( $dir . '/manifest.php', "<?php\nreturn " . var_export( $manifest, true ) . ";\n" );
	return $dir;
}

/**
 * Header di prova con la versione indicata.
 *
 * @param string $version Versione.
 * @return string
 */
function t_header( $version = '9.9.9' ) {
	return "<?php\n/**\n * Plugin Name: Plugin di prova\n * Version:     {$version}\n * Author:      Prova\n */\n\ndefined( 'ABSPATH' ) || exit;\n";
}

/**
 * Costanti di prova con la versione indicata.
 *
 * @param string $version Versione.
 * @return string
 */
function t_constants( $version = '9.9.9' ) {
	return "<?php\n\nif ( ! defined( 'WP_HEALTH_CHECK_VERSION' ) ) {\n\tdefine( 'WP_HEALTH_CHECK_VERSION', '{$version}' );\n}\n";
}

/**
 * Sorgenti minimi validi (header, constants, legacy) con un legacy a scelta.
 *
 * @param string $legacy Contenuto di legacy.php (dopo "<?php\n").
 * @return string Cartella src/ temporanea.
 */
function t_simple_src( $legacy ) {
	return t_make_src(
		array(
			'header.php'    => t_header(),
			'constants.php' => t_constants(),
			'legacy.php'    => "<?php\n" . $legacy,
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
			'legacy.php'    => 'legacy',
		)
	);
}

/**
 * Verifica che una funzione lanci RuntimeException con un certo testo.
 *
 * @param callable $fn     Funzione da eseguire.
 * @param string   $needle Testo atteso nel messaggio.
 */
function t_expect_fail( callable $fn, $needle ) {
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		t_assert( false !== strpos( $e->getMessage(), $needle ), 'messaggio inatteso: "' . $e->getMessage() . '" (atteso: "' . $needle . '")' );
		return;
	}
	throw new Build_Test_Failure( 'la build doveva fallire con "' . $needle . '" ma e\' riuscita' );
}

/**
 * Firma INDIPENDENTE dalla build: token non commento e non spazio, con la riga quando il tokenizer la da'.
 *
 * @param string $code Codice.
 * @return array
 */
function t_signature( $code ) {
	$sig = array();
	foreach ( token_get_all( $code ) as $tok ) {
		if ( is_array( $tok ) ) {
			if ( in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$sig[] = $tok[0] . ':' . $tok[2] . ':' . $tok[1];
		} else {
			$sig[] = $tok;
		}
	}
	return $sig;
}

/**
 * Codice di prova per lo strip: commenti di ogni tipo, stringhe e heredoc che sembrano commenti,
 * un template con commento prima del tag di chiusura, la classe del comando WP-CLI e un'altra classe.
 *
 * @return string Contenuto di legacy.php dopo "<?php\n".
 */
function t_strip_sample() {
	return <<<'SAMPLE'

// COMMENTO_RIGA_RIMOSSO
/**
 * DOCBLOCK_FUNZIONE_RIMOSSO
 */
function t_sample( $a ) { // COMMENTO_CODA_RIMOSSO
	# COMMENTO_CANCELLETTO_RIMOSSO
	/* COMMENTO_BLOCCO_RIMOSSO
	   su due righe */
	$url  = 'http://example.com/*tenuto*/ // tenuto';
	$text = <<<TXT
// tenuto nell'heredoc COMMENTO_IN_HEREDOC
/* tenuto nell'heredoc */
{$a}
TXT;
	$raw = <<<'RAW'
# tenuto nel nowdoc // COMMENTO_IN_NOWDOC
RAW;
	$glued = 1/* G */+2;
	return/* R */$url . $text . $raw . $glued;
}

function t_template() {
	?>
	<p>// html tenuto <?php echo 'x'; // COMMENTO_PRIMA_DELLA_CHIUSURA ?>
	</p>
	<?php
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * DOCBLOCK_CLASSE_CLI_TENUTO
	 */
	class WPHC_CLI_Command {

		/**
		 * DOCBLOCK_METODO_CLI_TENUTO
		 *
		 * ## OPTIONS
		 *
		 * [--show]
		 * : Opzione.
		 *
		 * @when after_wp_load
		 */
		public function reset( $args, $assoc_args ) {
			// COMMENTO_INTERNO_CLI_RIMOSSO
			return array( $args, $assoc_args );
		}
	}
}

class T_Other {
	/**
	 * DOCBLOCK_ALTRA_CLASSE_RIMOSSO
	 */
	public function f() {
		return 1; // COMMENTO_ALTRA_CLASSE_RIMOSSO
	}
}

SAMPLE;
}

/**
 * Elenco delle prove: nome => funzione.
 *
 * @var array
 */
$tests = array();

// -----------------------------------------------------------------------------------------------
// Sorgenti veri (src/): la build passa tutti i gate ed e' deterministica.
// -----------------------------------------------------------------------------------------------

$tests['src reale: build dev e strip passano tutti i gate e sono deterministiche'] = function () use ( $root ) {
	$dev_a   = wphc_build( $root . '/src', false );
	$dev_b   = wphc_build( $root . '/src', false );
	$strip_a = wphc_build( $root . '/src', true );
	$strip_b = wphc_build( $root . '/src', true );
	t_assert( $dev_a['code'] === $dev_b['code'], 'due build dev consecutive differiscono' );
	t_assert( $strip_a['code'] === $strip_b['code'], 'due build strip consecutive differiscono' );
	t_assert( $dev_a['sizes']['lines'] === $strip_a['sizes']['lines'], 'dev e strip hanno un numero di righe diverso' );
	t_assert( $strip_a['sizes']['raw'] < $dev_a['sizes']['raw'], 'lo strip non ha ridotto il file' );
	t_assert( false === strpos( $strip_a['code'], "\r" ), 'CR nel file strippato' );
};

$tests['src reale: la build coincide col file committato in mu-plugins (modo da WPHC_BUILD_MODE, default dev; a meno di CRLF)'] = function () use ( $root ) {
	// Stesso modo di "build:check": dev per default, strip se WPHC_BUILD_MODE=strip.
	$dev       = wphc_build( $root . '/src', 'strip' === getenv( 'WPHC_BUILD_MODE' ) );
	$committed = str_replace( "\r\n", "\n", file_get_contents( $root . '/mu-plugins/wp-health-check.php' ) );
	t_assert( $dev['code'] === $committed, 'mu-plugins/wp-health-check.php non e\' allineato a src/ (lanciare composer run build:dev)' );
};

// -----------------------------------------------------------------------------------------------
// Strip: righe, token, header, docblock CLI, stringhe/heredoc, idempotenza.
// -----------------------------------------------------------------------------------------------

$tests['strip: stesso numero di righe e stessi token non commento (controllo indipendente)'] = function () {
	$src   = t_simple_src( t_strip_sample() );
	$dev   = wphc_build( $src, false )['code'];
	$strip = wphc_build( $src, true )['code'];
	t_assert( substr_count( $dev, "\n" ) === substr_count( $strip, "\n" ), 'numero di righe diverso' );
	t_assert( t_signature( $dev ) === t_signature( $strip ), 'token non commento diversi (testo o riga)' );
	t_assert( strlen( $strip ) < strlen( $dev ), 'lo strip non ha tolto nulla' );
};

$tests['strip: i commenti spariscono, header e docblock WPHC_CLI_Command restano'] = function () {
	$strip = wphc_build( t_simple_src( t_strip_sample() ), true )['code'];

	// Commenti che devono sparire (qualunque forma, anche dentro il template e dentro la classe CLI).
	foreach ( array( 'COMMENTO_RIGA_RIMOSSO', 'DOCBLOCK_FUNZIONE_RIMOSSO', 'COMMENTO_CODA_RIMOSSO', 'COMMENTO_CANCELLETTO_RIMOSSO', 'COMMENTO_BLOCCO_RIMOSSO', 'COMMENTO_PRIMA_DELLA_CHIUSURA', 'COMMENTO_INTERNO_CLI_RIMOSSO', 'DOCBLOCK_ALTRA_CLASSE_RIMOSSO', 'COMMENTO_ALTRA_CLASSE_RIMOSSO' ) as $marker ) {
		t_assert( false === strpos( $strip, $marker ), 'commento non rimosso: ' . $marker );
	}

	// Header del plugin conservato.
	t_assert( false !== strpos( $strip, 'Plugin Name: Plugin di prova' ), 'header perso' );
	t_assert( 1 === preg_match( '/Version:\s+9\.9\.9(?:\s|$)/', $strip ), 'riga Version: persa' );

	// Docblock della classe del comando WP-CLI conservati (classe e metodo, con sinossi e @when).
	t_assert( false !== strpos( $strip, 'DOCBLOCK_CLASSE_CLI_TENUTO' ), 'docblock della classe CLI perso' );
	t_assert( false !== strpos( $strip, 'DOCBLOCK_METODO_CLI_TENUTO' ), 'docblock del metodo CLI perso' );
	t_assert( false !== strpos( $strip, '## OPTIONS' ) && false !== strpos( $strip, '@when after_wp_load' ), 'sinossi o @when del comando persi' );

	// Provato con la reflection, come fa WP-CLI: il docblock del metodo e' leggibile dal codice strippato.
	$tmp = sys_get_temp_dir() . '/wphc-build-reflect-' . uniqid( '', true ) . '.php';
	$GLOBALS['build_test_dirs'][] = $tmp;
	file_put_contents( $tmp, str_replace( "defined( 'ABSPATH' ) || exit;", '', $strip ) );
	define( 'WP_CLI', true );
	require $tmp;
	$doc = ( new ReflectionMethod( 'WPHC_CLI_Command', 'reset' ) )->getDocComment();
	t_assert( is_string( $doc ) && false !== strpos( $doc, '@when after_wp_load' ), 'ReflectionMethod::getDocComment() non restituisce il docblock del comando' );
	t_assert( false !== ( new ReflectionClass( 'WPHC_CLI_Command' ) )->getDocComment(), 'docblock della classe non leggibile' );
	t_assert( false === ( new ReflectionMethod( 'T_Other', 'f' ) )->getDocComment(), 'il docblock di un\'altra classe doveva sparire' );
};

$tests['strip: stringhe, heredoc e nowdoc non vengono alterati'] = function () {
	$src   = t_simple_src( t_strip_sample() );
	$dev   = wphc_build( $src, false )['code'];
	$strip = wphc_build( $src, true )['code'];

	// Le stesse righe, byte per byte, devono comparire nel file strippato.
	foreach ( array(
		"\$url  = 'http://example.com/*tenuto*/ // tenuto';",
		"// tenuto nell'heredoc COMMENTO_IN_HEREDOC\n/* tenuto nell'heredoc */\n{\$a}\nTXT;",
		"# tenuto nel nowdoc // COMMENTO_IN_NOWDOC\nRAW;",
		"<p>// html tenuto <?php echo 'x';",
	) as $literal ) {
		t_assert( false !== strpos( $dev, $literal ), 'prova mal costruita, manca in dev: ' . $literal );
		t_assert( false !== strpos( $strip, $literal ), 'alterato dallo strip: ' . $literal );
	}

	// I due commenti "/* */" fra token non separati non incollano i token.
	t_assert( false !== strpos( $strip, '$glued = 1 +2;' ) || false !== strpos( $strip, '$glued = 1+2;' ), 'commento fra "1" e "+2" gestito male' );
	t_assert( false !== strpos( $strip, 'return $url' ), 'commento fra return e $url ha incollato i token' );
};

$tests['strip: idempotenza (due build uguali e strip(strip(x)) == strip(x))'] = function () {
	$src = t_simple_src( t_strip_sample() );
	$a   = wphc_build( $src, true )['code'];
	$b   = wphc_build( $src, true )['code'];
	t_assert( $a === $b, 'due build strip dello stesso sorgente differiscono' );
	t_assert( wphc_build_strip( $a ) === $a, 'lo strip non e\' idempotente' );
};

$tests['strip: un commento "//" con il tag di chiusura di PHP e\' trattato come fa PHP (resta nel file, non incolla)'] = function () {
	// Dentro una funzione, un commento di riga seguito dal tag di chiusura chiude il blocco PHP: lo strip non deve romperlo.
	$src   = t_simple_src( "function t_f() {\n\t?>\n\t<b>x</b><?php echo 1; // fine ?>\n\t<?php\n}\n" );
	$dev   = wphc_build( $src, false )['code'];
	$strip = wphc_build( $src, true )['code'];
	t_assert( t_signature( $dev ) === t_signature( $strip ), 'token diversi dopo lo strip' );
};

// -----------------------------------------------------------------------------------------------
// Gate positivi (non devono scattare a torto).
// -----------------------------------------------------------------------------------------------

$tests['gate (b)/(c): classi in ordine, extends di classe esterna e HTML dentro un metodo sono ammessi'] = function () {
	$src = t_make_src(
		array(
			'header.php'    => t_header(),
			'constants.php' => t_constants(),
			'class-a.php'   => "<?php\n/**\n * Base.\n */\nclass T_Base {\n\tpublic function t() {\n\t\t?>\n\t\t<i>html</i>\n\t\t<?php\n\t}\n}\n",
			'class-b.php'   => "<?php\nfinal class T_Child extends T_Base {\n}\n",
			'class-c.php'   => "<?php\nfinal class T_Ext extends \\WP_Widget {\n}\n",
			'legacy.php'    => "<?php\nfunction t_l() {\n\treturn new T_Child();\n}\n",
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
			'class-a.php'   => 'class',
			'class-b.php'   => 'class',
			'class-c.php'   => 'class',
			'legacy.php'    => 'legacy',
		)
	);
	$result = wphc_build( $src, true );
	t_assert( false !== strpos( $result['code'], 'class T_Child extends T_Base' ), 'classi non concatenate' );
};

// -----------------------------------------------------------------------------------------------
// Gate negativi: ognuno deve far FALLIRE la build con un messaggio chiaro.
// -----------------------------------------------------------------------------------------------

$tests['gate (c): classe che estende una classe dichiarata piu\' avanti'] = function () {
	$src = t_make_src(
		array(
			'header.php'    => t_header(),
			'constants.php' => t_constants(),
			'class-a.php'   => "<?php\nfinal class T_Child extends T_Base {\n}\n",
			'class-b.php'   => "<?php\nclass T_Base {\n}\n",
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
			'class-a.php'   => 'class',
			'class-b.php'   => 'class',
		)
	);
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'estende T_Base dichiarata piu\' avanti'
	);
};

$tests['gate (b): codice top-level in un file con ruolo class'] = function () {
	foreach ( array(
		"<?php\nadd_action( 'init', 'x' );\nclass T_A {\n}\n"        => 'codice top-level non ammesso',
		"<?php\nclass T_A {\n}\ndefine( 'X', 1 );\n"                  => 'dopo la dichiarazione della classe',
		"<?php\nclass T_A {\n}\nclass T_B {\n}\n"                     => 'dopo la dichiarazione della classe',
		"<?php\nfunction t_f() {\n}\nclass T_A {\n}\n"                => 'codice top-level non ammesso',
		"<?php\n// solo un commento\n"                                => 'esattamente una classe',
	) as $content => $needle ) {
		$src = t_make_src(
			array(
				'header.php'    => t_header(),
				'constants.php' => t_constants(),
				'class-a.php'   => $content,
			),
			array(
				'header.php'    => 'header',
				'constants.php' => 'constants',
				'class-a.php'   => 'class',
			)
		);
		t_expect_fail(
			function () use ( $src ) {
				wphc_build( $src, false );
			},
			$needle
		);
	}
};

$tests['gate (d): versione dell\'header diversa da WP_HEALTH_CHECK_VERSION'] = function () {
	$src = t_make_src(
		array(
			'header.php'    => t_header( '1.0.0' ),
			'constants.php' => t_constants( '1.0.1' ),
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
		)
	);
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, true );
		},
		'versione dell\'header (1.0.0) diversa da WP_HEALTH_CHECK_VERSION (1.0.1)'
	);
};

$tests['gate (d)/(f): costante o riga Version mancanti, header senza Plugin Name'] = function () {
	// Costante mancante.
	$src = t_make_src(
		array(
			'header.php'    => t_header(),
			'constants.php' => "<?php\n\n",
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
		)
	);
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'WP_HEALTH_CHECK_VERSION non trovata'
	);

	// Riga Version: mancante nell'header.
	$src = t_make_src(
		array(
			'header.php'    => "<?php\n/**\n * Plugin Name: P\n */\n",
			'constants.php' => t_constants(),
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
		)
	);
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'riga "Version:" non trovata'
	);

	// Header senza Plugin Name.
	$src = t_make_src(
		array(
			'header.php'    => "<?php\n/**\n * Version: 9.9.9\n */\n",
			'constants.php' => t_constants(),
		),
		array(
			'header.php'    => 'header',
			'constants.php' => 'constants',
		)
	);
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'header del plugin'
	);
};

$tests['gate (f)/(g): file prodotto con CR o che non inizia con <?php (chiamata diretta del gate)'] = function () {
	$good   = t_header() . substr( t_constants(), 6 );
	$tokens = wphc_build_tokenize( $good, 'prova' );
	wphc_build_gate_output( $good, $tokens ); // Deve passare.

	t_expect_fail(
		function () use ( $good ) {
			$bad = str_replace( "\n", "\r\n", $good );
			wphc_build_gate_output( $bad, wphc_build_tokenize( $bad, 'prova' ) );
		},
		'contiene CR'
	);
	t_expect_fail(
		function () use ( $good ) {
			$bad = ' ' . $good;
			wphc_build_gate_output( $bad, wphc_build_tokenize( $bad, 'prova' ) );
		},
		'deve iniziare con'
	);
};

$tests['gate (a): errore di sintassi PHP'] = function () {
	$src = t_simple_src( "function t_x( {\n}\n" );
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'errore di sintassi PHP'
	);
};

$tests['gate (e): HTML inline o tag di chiusura fuori da una funzione'] = function () {
	foreach ( array(
		"?>\n<p>html</p>\n<?php\nfunction t_ok() {\n}\n"                    => 'HTML inline',
		"function t_ok() {\n}\n// commento con chiusura ?>\n<p>x</p>\n"       => 'HTML inline',
		"class T_C {\n\tpublic \$x = 1;\n}\n?>\n\n"                            => 'HTML inline',
	) as $legacy => $needle ) {
		$src = t_simple_src( $legacy );
		t_expect_fail(
			function () use ( $src ) {
				wphc_build( $src, false );
			},
			$needle
		);
	}
};

$tests['gate: namespace e dichiarazioni duplicate'] = function () {
	$src = t_simple_src( "namespace Foo;\nfunction t_a() {\n}\n" );
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'namespace non ammesso'
	);
	$src = t_simple_src( "function t_dup() {\n}\nfunction T_DUP() {\n}\n" );
	t_expect_fail(
		function () use ( $src ) {
			wphc_build( $src, false );
		},
		'dichiarazioni duplicate'
	);
};

$tests['gate sui file: CR, tag iniziale, a capo finale, manifest incoerente, ruolo e header'] = function () {
	$base_manifest = array(
		'header.php'    => 'header',
		'constants.php' => 'constants',
	);
	$cases         = array();

	// CR in un sorgente.
	$cases[] = array( array( 'header.php' => t_header(), 'constants.php' => str_replace( "\n", "\r\n", t_constants() ) ), $base_manifest, 'contiene CR' );
	// Tag di apertura non standard.
	$cases[] = array( array( 'header.php' => t_header(), 'constants.php' => "<?php  \n" ), $base_manifest, 'deve iniziare esattamente' );
	// Manca l'a capo finale.
	$cases[] = array( array( 'header.php' => t_header(), 'constants.php' => "<?php\n// x" ), $base_manifest, 'deve terminare con un a capo' );
	// File in src/ non presente nel manifest.
	$cases[] = array( array( 'header.php' => t_header(), 'constants.php' => t_constants(), 'extra.php' => "<?php\n" ), $base_manifest, 'non presenti nel manifest' );
	// File del manifest inesistente.
	$cases[] = array( array( 'header.php' => t_header() ), $base_manifest, 'file del manifest inesistente' );
	// Ruolo non valido.
	$cases[] = array( array( 'header.php' => t_header(), 'constants.php' => t_constants() ), array( 'header.php' => 'header', 'constants.php' => 'costanti' ), 'ruolo non valido' );
	// Header non per primo.
	$cases[] = array( array( 'header.php' => t_header(), 'constants.php' => t_constants() ), array( 'constants.php' => 'constants', 'header.php' => 'header' ), 'primo' );
	// Nome file con percorso.
	$cases[] = array( array( 'header.php' => t_header() ), array( 'header.php' => 'header', '../x.php' => 'legacy' ), 'nome file non valido' );

	foreach ( $cases as $case ) {
		$src = t_make_src( $case[0], $case[1] );
		t_expect_fail(
			function () use ( $src ) {
				wphc_build( $src, false );
			},
			$case[2]
		);
	}
};

$tests['gate (h): il budget del push blocca solo la build strippata, avvisa oltre 110000'] = function () {
	// Dati quasi incomprimibili (generatore deterministico): il gzip pesa quanto l'input.
	mt_srand( 12345 );
	$make = function ( $bytes ) {
		$data = '';
		for ( $i = 0; $i < $bytes; $i++ ) {
			$data .= chr( mt_rand( 0, 255 ) );
		}
		return "\$t_blob = '" . base64_encode( $data ) . "';\n";
	};

	// Oltre il limite (130 KB di casuale -> base64 sopra 131072): la build strip fallisce, la dev no.
	$big = t_simple_src( $make( 120000 ) );
	t_expect_fail(
		function () use ( $big ) {
			wphc_build( $big, true );
		},
		'supera il limite del push'
	);
	$dev = wphc_build( $big, false );
	t_assert( array() !== $dev['warnings'], 'avviso di budget mancante in dev' );

	// Fra budget e limite: passa con un avviso.
	$mid    = t_simple_src( $make( 90000 ) );
	$result = wphc_build( $mid, true );
	t_assert( $result['sizes']['b64'] > WPHC_BUILD_PUSH_BUDGET && $result['sizes']['b64'] <= WPHC_BUILD_PUSH_LIMIT, 'la prova non cade fra budget e limite: ' . $result['sizes']['b64'] );
	t_assert( 1 === count( $result['warnings'] ), 'avviso di budget mancante' );
};

// -----------------------------------------------------------------------------------------------
// CLI: codici di uscita, --check, --output, WPHC_BUILD_MODE.
// -----------------------------------------------------------------------------------------------

/**
 * Lancia bin/build.php in un processo figlio.
 *
 * @param string   $root Radice del repo.
 * @param string[] $args Argomenti.
 * @param string[] $env  Variabili d'ambiente da aggiungere.
 * @return array array( exit, stdout, stderr ).
 */
function t_run_cli( $root, array $args, array $env = array() ) {
	$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/bin/build.php' );
	foreach ( $args as $arg ) {
		$cmd .= ' ' . escapeshellarg( $arg );
	}
	$proc = proc_open(
		$cmd,
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes,
		$root,
		array_merge( getenv(), $env )
	);
	$out  = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( proc_close( $proc ), $out, $err );
}

$tests['CLI: --output scrive solo dove richiesto, --check confronta, WPHC_BUILD_MODE sceglie il modo'] = function () use ( $root ) {
	$dir = sys_get_temp_dir() . '/wphc-build-cli-' . uniqid( '', true );
	mkdir( $dir );
	$GLOBALS['build_test_dirs'][] = $dir;
	$dev   = $dir . '/dev.php';
	$strip = $dir . '/strip.php';

	// Scrittura in due modi.
	$r = t_run_cli( $root, array( '--no-strip', '--output', $dev ) );
	t_assert( 0 === $r[0], 'build dev: exit ' . $r[0] . ' ' . $r[2] );
	$r = t_run_cli( $root, array( '--output=' . $strip ) );
	t_assert( 0 === $r[0], 'build strip: exit ' . $r[0] . ' ' . $r[2] );
	t_assert( filesize( $strip ) < filesize( $dev ), 'lo strip di default non e\' attivo' );

	// --check: stesso file -> 0; file diverso -> 1; CRLF normalizzato -> 0 con avviso.
	$r = t_run_cli( $root, array( '--check', '--no-strip', '--output', $dev ) );
	t_assert( 0 === $r[0] && false !== strpos( $r[1], 'CHECK OK' ), '--check su file identico: exit ' . $r[0] );
	$r = t_run_cli( $root, array( '--check', '--strip', '--output', $dev ) );
	t_assert( 1 === $r[0] && false !== strpos( $r[2], 'CHECK FALLITO' ), '--check su file diverso doveva fallire' );
	file_put_contents( $dir . '/crlf.php', str_replace( "\n", "\r\n", file_get_contents( $dev ) ) );
	$r = t_run_cli( $root, array( '--check', '--no-strip', '--output', $dir . '/crlf.php' ) );
	t_assert( 0 === $r[0] && false !== strpos( $r[2], 'CRLF' ), '--check con CRLF: exit ' . $r[0] );

	// WPHC_BUILD_MODE: default dev, strip su richiesta, valore non valido -> 2.
	$r = t_run_cli( $root, array( '--check', '--output', $dev ), array( 'WPHC_BUILD_MODE' => '' ) );
	t_assert( 0 === $r[0], '--check senza flag deve confrontare la build dev (default)' );
	$r = t_run_cli( $root, array( '--check', '--output', $strip ), array( 'WPHC_BUILD_MODE' => 'strip' ) );
	t_assert( 0 === $r[0], '--check con WPHC_BUILD_MODE=strip deve confrontare la build strip' );
	$r = t_run_cli( $root, array( '--check', '--output', $strip ), array( 'WPHC_BUILD_MODE' => 'boh' ) );
	t_assert( 2 === $r[0], 'WPHC_BUILD_MODE non valido doveva dare exit 2' );

	// Un gate che fallisce da CLI: exit 1, messaggio, e il file di destinazione NON viene scritto.
	$bad = t_simple_src( "function t_x( {\n}\n" );
	$out = $dir . '/mai-scritto.php';
	$r   = t_run_cli( $root, array( '--src', $bad, '--output', $out ) );
	t_assert( 1 === $r[0] && false !== strpos( $r[2], 'BUILD FALLITA' ), 'gate da CLI: exit ' . $r[0] );
	t_assert( ! file_exists( $out ), 'la build fallita ha scritto comunque il file' );

	// Uso errato: exit 2.
	$r = t_run_cli( $root, array( '--boh' ) );
	t_assert( 2 === $r[0], 'argomento sconosciuto doveva dare exit 2' );
};

// -----------------------------------------------------------------------------------------------
// Esecuzione
// -----------------------------------------------------------------------------------------------

$failed = 0;
foreach ( $tests as $name => $fn ) {
	try {
		$fn();
		fwrite( STDOUT, 'PASS  ' . $name . "\n" );
	} catch ( Throwable $e ) {
		++$failed;
		fwrite( STDOUT, 'FAIL  ' . $name . "\n      " . get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n" );
	}
}

// Pulizia delle cartelle e dei file temporanei.
foreach ( $GLOBALS['build_test_dirs'] as $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	} elseif ( is_dir( $path ) ) {
		foreach ( (array) glob( $path . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $path );
	}
}

fwrite( STDOUT, "\n" . count( $tests ) . ' prove, ' . $failed . " fallite\n" );
exit( $failed > 0 ? 1 : 0 );
