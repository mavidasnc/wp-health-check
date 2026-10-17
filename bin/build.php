<?php
/**
 * Build del plugin: concatena i file di src/ nell'unico mu-plugin distribuito.
 *
 * Utility CLI, ESTERNA al plugin: non viene mai installata su un sito. Legge
 * src/manifest.php (elenco ORDINATO file => ruolo), concatena i file nell'ordine
 * dichiarato e scrive mu-plugins/wp-health-check.php, che resta l'unico file
 * distribuito (asset delle release, firmato k2).
 *
 * Uso:
 *     php bin/build.php                     build con strip dei commenti, scrive mu-plugins/
 *     php bin/build.php --no-strip          build di sviluppo/debug: nessuno strip
 *     php bin/build.php --output <path>     scrive in <path> invece che in mu-plugins/
 *     php bin/build.php --check             confronta il risultato col file committato, exit 1 se differisce
 *     php bin/build.php --src <dir>         usa un altro albero sorgente (prove della build stessa)
 *
 * Modo della build quando non e' indicato (--strip / --no-strip):
 *     - senza --check: strip;
 *     - con --check: variabile d'ambiente WPHC_BUILD_MODE (dev | strip), default dev.
 *
 * Compatibile PHP 7.4+: gira anche nel container di test 7.4.
 *
 * @package WP_Health_Check
 */

// phpcs:disable WordPress -- script CLI puro, fuori dal contesto WordPress: le regole WPCS
// (prefissi globali, escaping dell'output, funzioni alternative, ecc.) non si applicano qui.
// Restano attivi PHPCompatibilityWP (il build deve restare PHP 7.4) e le regole sui docblock.

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Questo script va eseguito da riga di comando: php bin/build.php\n" );
	exit( 1 );
}

/**
 * Limite duro del pacchetto di push (byte di gzip + base64): la build strippata fallisce oltre.
 */
const WPHC_BUILD_PUSH_LIMIT = 131072;

/**
 * Budget di progetto del pacchetto di push: oltre questa soglia la build stampa un avviso.
 */
const WPHC_BUILD_PUSH_BUDGET = 110000;

/**
 * Classe i cui docblock sopravvivono allo strip (WP-CLI li legge via reflection).
 */
const WPHC_BUILD_KEEP_DOCS_CLASS = 'WPHC_CLI_Command';

/**
 * Ruoli ammessi nel manifest.
 *
 * @return string[]
 */
function wphc_build_roles() {
	return array( 'header', 'constants', 'class', 'legacy', 'boot' );
}

/**
 * Tokenizza il codice con un parse completo e normalizza i token in array uniformi.
 *
 * I token a carattere singolo non portano il numero di riga in token_get_all(): qui
 * la riga viene calcolata contando gli a capo dei token precedenti.
 *
 * @param string $code  Codice PHP completo (con il tag di apertura).
 * @param string $label Nome da citare nei messaggi di errore.
 * @return array[] Elenco di array( 'id' => int|string, 'text' => string, 'line' => int ).
 * @throws RuntimeException Se il codice non e' sintatticamente valido.
 */
function wphc_build_tokenize( $code, $label ) {
	try {
		// TOKEN_PARSE esegue il parse completo e lancia ParseError su codice non valido.
		$raw = token_get_all( $code, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		throw new RuntimeException( $label . ': errore di sintassi PHP (riga ' . $e->getLine() . '): ' . $e->getMessage() );
	}

	$tokens = array();
	$line   = 1;
	foreach ( $raw as $tok ) {
		if ( is_array( $tok ) ) {
			// Token con id numerico: testo e riga arrivano dal tokenizer.
			$tokens[] = array(
				'id'   => $tok[0],
				'text' => $tok[1],
				'line' => $tok[2],
			);
			$line     = $tok[2] + substr_count( $tok[1], "\n" );
		} else {
			// Carattere singolo: id e testo coincidono, la riga e' quella corrente.
			$tokens[] = array(
				'id'   => $tok,
				'text' => $tok,
				'line' => $line,
			);
		}
	}
	return $tokens;
}

/**
 * Dice se un token e' "trivia": spazio bianco o commento.
 *
 * @param array $tok Token normalizzato.
 * @return bool
 */
function wphc_build_is_trivia( array $tok ) {
	return T_WHITESPACE === $tok['id'] || T_COMMENT === $tok['id'] || T_DOC_COMMENT === $tok['id'];
}

/**
 * Indice del prossimo token significativo dopo $i, o null.
 *
 * @param array[] $tokens Token normalizzati.
 * @param int     $i      Indice di partenza (escluso).
 * @return int|null
 */
function wphc_build_next_sig( array $tokens, $i ) {
	$n = count( $tokens );
	for ( $k = $i + 1; $k < $n; $k++ ) {
		if ( ! wphc_build_is_trivia( $tokens[ $k ] ) ) {
			return $k;
		}
	}
	return null;
}

/**
 * Indice del token significativo precedente $i, o null.
 *
 * @param array[] $tokens Token normalizzati.
 * @param int     $i      Indice di partenza (escluso).
 * @return int|null
 */
function wphc_build_prev_sig( array $tokens, $i ) {
	for ( $k = $i - 1; $k >= 0; $k-- ) {
		if ( ! wphc_build_is_trivia( $tokens[ $k ] ) ) {
			return $k;
		}
	}
	return null;
}

/**
 * Dice se il token apre un blocco a graffa (graffa semplice o interpolazione in stringa).
 *
 * @param array $tok Token normalizzato.
 * @return bool
 */
function wphc_build_opens_brace( array $tok ) {
	return '{' === $tok['id'] || T_CURLY_OPEN === $tok['id'] || T_DOLLAR_OPEN_CURLY_BRACES === $tok['id'];
}

/**
 * Legge il manifest e i file di src/, applicando i controlli per singolo file.
 *
 * @param string $src_dir Cartella dei sorgenti (contiene manifest.php).
 * @return array[] Elenco ordinato di array( 'file' => string, 'role' => string, 'content' => string ).
 * @throws RuntimeException Se manifest o sorgenti violano le regole della build.
 */
function wphc_build_read_sources( $src_dir ) {
	$manifest_path = $src_dir . '/manifest.php';
	if ( ! is_file( $manifest_path ) ) {
		throw new RuntimeException( 'manifest non trovato: ' . $manifest_path );
	}
	$manifest = include $manifest_path;
	if ( ! is_array( $manifest ) || array() === $manifest ) {
		throw new RuntimeException( 'il manifest deve restituire un array non vuoto file => ruolo' );
	}

	// 1. Ogni voce del manifest: nome file semplice, ruolo noto, file esistente.
	foreach ( $manifest as $file => $role ) {
		if ( ! is_string( $file ) || 1 !== preg_match( '/^[A-Za-z0-9._-]+\.php$/', $file ) ) {
			throw new RuntimeException( 'nome file non valido nel manifest: ' . var_export( $file, true ) );
		}
		if ( ! in_array( $role, wphc_build_roles(), true ) ) {
			throw new RuntimeException( 'ruolo non valido nel manifest per ' . $file . ': ' . var_export( $role, true ) );
		}
		if ( ! is_file( $src_dir . '/' . $file ) ) {
			throw new RuntimeException( 'file del manifest inesistente: ' . $file );
		}
	}

	// 2. Il manifest deve elencare esattamente i file di src/ (manifest.php escluso).
	$on_disk = array();
	foreach ( (array) glob( $src_dir . '/*.php' ) as $path ) {
		$name = basename( $path );
		if ( 'manifest.php' !== $name ) {
			$on_disk[] = $name;
		}
	}
	$missing = array_diff( $on_disk, array_keys( $manifest ) );
	if ( array() !== $missing ) {
		throw new RuntimeException( 'file di src/ non presenti nel manifest: ' . implode( ', ', $missing ) );
	}

	// 3. Il primo file e' l'unico header: dal suo docblock parte il file distribuito.
	$roles = array_values( $manifest );
	if ( 'header' !== $roles[0] || 1 !== count( array_keys( $roles, 'header', true ) ) ) {
		throw new RuntimeException( 'il manifest deve avere un solo file con ruolo header e deve essere il primo' );
	}

	// 4. Controlli sul contenuto di ciascun file.
	$entries = array();
	foreach ( $manifest as $file => $role ) {
		$content = file_get_contents( $src_dir . '/' . $file );
		if ( false !== strpos( $content, "\r" ) ) {
			throw new RuntimeException( $file . ': contiene CR (\\r): i sorgenti devono avere solo LF' );
		}
		if ( 0 !== strpos( $content, "<?php\n" ) ) {
			throw new RuntimeException( $file . ': deve iniziare esattamente con "<?php" seguito da un a capo' );
		}
		if ( "\n" !== substr( $content, -1 ) ) {
			throw new RuntimeException( $file . ': deve terminare con un a capo (la concatenazione non aggiunge separatori)' );
		}
		if ( 'class' === $role ) {
			wphc_build_check_class_file( $file, $content );
		}
		$entries[] = array(
			'file'    => $file,
			'role'    => $role,
			'content' => $content,
		);
	}
	return $entries;
}

/**
 * Gate (b): un file con ruolo class contiene UNA dichiarazione di classe e nessun altro codice top-level.
 *
 * Sono ammessi solo tag di apertura, spazi, commenti, i modificatori final/abstract e
 * (come ultimo token) un eventuale tag di chiusura, che la concatenazione toglie.
 *
 * @param string $file    Nome del file (per i messaggi).
 * @param string $content Contenuto del file.
 * @return void
 * @throws RuntimeException Se il file contiene altro oltre a una classe.
 */
function wphc_build_check_class_file( $file, $content ) {
	$tokens  = wphc_build_tokenize( $content, $file );
	$depth   = 0;
	$classes = 0;
	$in_head = false; // Fra "class" e la graffa di apertura.
	$after   = false; // Dopo la graffa di chiusura della classe.

	foreach ( $tokens as $tok ) {
		if ( $depth > 0 ) {
			// Dentro il corpo della classe: si tiene solo il conto delle graffe.
			if ( wphc_build_opens_brace( $tok ) ) {
				++$depth;
			} elseif ( '}' === $tok['id'] ) {
				--$depth;
				if ( 0 === $depth ) {
					$after = true;
				}
			}
			continue;
		}
		if ( wphc_build_is_trivia( $tok ) || T_OPEN_TAG === $tok['id'] ) {
			continue;
		}
		if ( $after ) {
			if ( T_CLOSE_TAG === $tok['id'] ) {
				continue;
			}
			throw new RuntimeException( $file . ': codice top-level dopo la dichiarazione della classe (riga ' . $tok['line'] . ')' );
		}
		if ( $in_head ) {
			// Intestazione della classe (nome, extends, implements): finisce alla graffa.
			if ( '{' === $tok['id'] ) {
				$depth   = 1;
				$in_head = false;
			}
			continue;
		}
		if ( T_FINAL === $tok['id'] || T_ABSTRACT === $tok['id'] ) {
			continue;
		}
		if ( T_CLASS === $tok['id'] && 0 === $classes ) {
			++$classes;
			$in_head = true;
			continue;
		}
		throw new RuntimeException( $file . ': codice top-level non ammesso in un file di classe (riga ' . $tok['line'] . ': ' . trim( $tok['text'] ) . ')' );
	}
	if ( 1 !== $classes ) {
		throw new RuntimeException( $file . ': un file con ruolo class deve dichiarare esattamente una classe' );
	}
}

/**
 * Concatena i file nell'ordine del manifest.
 *
 * Il primo file resta intero; dai successivi si toglie il "<?php" iniziale (insieme al
 * suo a capo) e l'eventuale "?>" finale. NESSUN separatore: ogni file termina con un a
 * capo (gia' controllato), cosi' il risultato e' un taglio a byte riproducibile.
 *
 * @param array[] $entries Voci restituite da wphc_build_read_sources().
 * @return string
 */
function wphc_build_concat( array $entries ) {
	$out = '';
	foreach ( $entries as $k => $entry ) {
		$content = $entry['content'];
		if ( 0 !== $k ) {
			// Via il tag di apertura (6 byte) e un eventuale tag di chiusura finale con il suo a capo.
			$content = substr( $content, 6 );
			$content = preg_replace( '/\?>\n?\z/', '', $content );
			// Se il tag di chiusura stava in fondo a una riga di codice, si richiude la riga.
			if ( '' !== $content && "\n" !== substr( $content, -1 ) ) {
				$content .= "\n";
			}
		}
		$out .= $content;
	}
	return $out;
}

/**
 * Indici dei docblock che lo strip deve conservare.
 *
 * Sono due gruppi: il primo docblock del file se contiene "Plugin Name:" (header che
 * leggono WordPress, l'installer e la verifica di integrita' dell'update) e, per la
 * classe WPHC_CLI_Command, il docblock che la precede e tutti quelli nel suo corpo
 * (WP-CLI li legge via reflection per sinossi, ## OPTIONS e @when).
 *
 * @param array[] $tokens Token normalizzati del file completo.
 * @return array<int, bool> Mappa indice token => true.
 */
function wphc_build_preserved_docblocks( array $tokens ) {
	$keep = array();
	$n    = count( $tokens );

	// 1. Header del plugin: solo il PRIMO docblock del file, e solo se e' l'header.
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_DOC_COMMENT === $tokens[ $i ]['id'] ) {
			if ( false !== strpos( $tokens[ $i ]['text'], 'Plugin Name:' ) ) {
				$keep[ $i ] = true;
			}
			break;
		}
	}

	// 2. Classe del comando WP-CLI: docblock precedente e docblock interni.
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_CLASS !== $tokens[ $i ]['id'] ) {
			continue;
		}
		$name = wphc_build_next_sig( $tokens, $i );
		if ( null === $name || T_STRING !== $tokens[ $name ]['id'] || WPHC_BUILD_KEEP_DOCS_CLASS !== $tokens[ $name ]['text'] ) {
			continue;
		}

		// Docblock che precede la classe (saltando spazi e modificatori).
		$j = $i - 1;
		while ( $j >= 0 && in_array( $tokens[ $j ]['id'], array( T_WHITESPACE, T_FINAL, T_ABSTRACT ), true ) ) {
			--$j;
		}
		if ( $j >= 0 && T_DOC_COMMENT === $tokens[ $j ]['id'] ) {
			$keep[ $j ] = true;
		}

		// Corpo della classe: dalla graffa di apertura a quella che la chiude.
		$depth = 0;
		$open  = false;
		for ( $k = $name; $k < $n; $k++ ) {
			if ( wphc_build_opens_brace( $tokens[ $k ] ) ) {
				++$depth;
				$open = true;
			} elseif ( '}' === $tokens[ $k ]['id'] ) {
				--$depth;
			}
			if ( $open && T_DOC_COMMENT === $tokens[ $k ]['id'] ) {
				$keep[ $k ] = true;
			}
			if ( $open && 0 === $depth ) {
				break;
			}
		}
	}
	return $keep;
}

/**
 * Toglie i commenti PRESERVANDO il numero di righe.
 *
 * Ogni commento (T_COMMENT, T_DOC_COMMENT) e' sostituito dai soli a capo che contiene:
 * cosi' la riga N del file distribuito e' la riga N della concatenazione e gli stack
 * trace di produzione puntano al posto giusto. Stringhe e heredoc non sono commenti per
 * il tokenizer e restano intatti. Non si tocca nient'altro (indentazione, righe vuote).
 *
 * Con PHP 7.4 un commento "//" include l'a capo finale, con PHP 8 no: sostituire con
 * "gli a capo contenuti" da' lo stesso identico risultato su entrambe le versioni.
 *
 * @param string $code Codice completo.
 * @return string
 */
function wphc_build_strip( $code ) {
	$tokens = wphc_build_tokenize( $code, 'strip' );
	$keep   = wphc_build_preserved_docblocks( $tokens );
	$out    = '';

	foreach ( $tokens as $i => $tok ) {
		$is_comment = T_COMMENT === $tok['id'] || T_DOC_COMMENT === $tok['id'];
		if ( ! $is_comment || isset( $keep[ $i ] ) ) {
			$out .= $tok['text'];
			continue;
		}

		// Solo gli a capo del commento.
		$replacement = str_repeat( "\n", substr_count( $tok['text'], "\n" ) );

		// Un commento "/* */" su una riga fra due token non separati: uno spazio, per non incollarli.
		if ( '' === $replacement && 0 === strpos( $tok['text'], '/*' ) ) {
			$prev_last  = '' === $out ? '' : substr( $out, -1 );
			$next_first = isset( $tokens[ $i + 1 ] ) ? substr( $tokens[ $i + 1 ]['text'], 0, 1 ) : '';
			if ( '' !== $prev_last && '' !== $next_first && ! ctype_space( $prev_last ) && ! ctype_space( $next_first ) ) {
				$replacement = ' ';
			}
		}
		$out .= $replacement;
	}
	return $out;
}

/**
 * Firma dei token "veri": (id, testo, riga) di ogni token che non e' spazio ne' commento.
 *
 * Due file con la stessa firma hanno lo stesso codice, riga per riga.
 *
 * @param array[] $tokens Token normalizzati.
 * @return string[]
 */
function wphc_build_signature( array $tokens ) {
	$sig = array();
	foreach ( $tokens as $tok ) {
		if ( ! wphc_build_is_trivia( $tok ) ) {
			$sig[] = $tok['id'] . '|' . $tok['line'] . '|' . $tok['text'];
		}
	}
	return $sig;
}

/**
 * Analisi strutturale del file completo, in un solo passaggio sui token.
 *
 * Tiene una pila degli ambiti aperti: 'F' corpo di funzione/metodo/closure, 'C' corpo di
 * classe, 'B' blocco generico.
 *
 * @param array[] $tokens Token normalizzati del file completo.
 * @return array Chiavi: classes, duplicates, bad_html, namespaces.
 */
function wphc_build_analyse( array $tokens ) {
	$info     = array(
		'classes'    => array(), // Classi con nome: array( name, index, line, parent ).
		'duplicates' => array(), // Messaggi di dichiarazioni doppie al livello del file.
		'bad_html'   => array(), // Righe di tag di chiusura o HTML inline fuori da una funzione.
		'namespaces' => array(), // Righe con "namespace".
	);
	$declared = array();
	$stack    = array();
	$pending  = null; // 'F' o 'C' in attesa della graffa di apertura.
	$paren    = 0;

	foreach ( $tokens as $i => $tok ) {
		$id        = $tok['id'];
		$top_level = ! in_array( 'F', $stack, true ) && ! in_array( 'C', $stack, true );

		if ( T_NAMESPACE === $id ) {
			$info['namespaces'][] = $tok['line'];
		} elseif ( T_FUNCTION === $id ) {
			// Funzione con nome al livello del file: si registra per cercare duplicati.
			if ( $top_level ) {
				$j = wphc_build_next_sig( $tokens, $i );
				if ( null !== $j && '&' === $tokens[ $j ]['id'] ) {
					$j = wphc_build_next_sig( $tokens, $j );
				}
				if ( null !== $j && T_STRING === $tokens[ $j ]['id'] ) {
					wphc_build_register_name( $info, $declared, 'funzione', $tokens[ $j ]['text'], $tok['line'] );
				}
			}
			$pending = 'F';
			$paren   = 0;
		} elseif ( T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id ) {
			$p = wphc_build_prev_sig( $tokens, $i );
			// "Foo::class" non e' una dichiarazione.
			if ( null === $p || T_DOUBLE_COLON !== $tokens[ $p ]['id'] ) {
				$anonymous = null !== $p && T_NEW === $tokens[ $p ]['id'];
				$j         = wphc_build_next_sig( $tokens, $i );
				if ( ! $anonymous && null !== $j && T_STRING === $tokens[ $j ]['id'] ) {
					if ( $top_level ) {
						wphc_build_register_name( $info, $declared, 'classe', $tokens[ $j ]['text'], $tok['line'] );
					}
					if ( T_CLASS === $id ) {
						$info['classes'][] = array(
							'name'   => $tokens[ $j ]['text'],
							'index'  => $i,
							'line'   => $tok['line'],
							'parent' => wphc_build_parent_class( $tokens, $j ),
						);
					}
				}
				$pending = 'C';
				$paren   = 0;
			}
		} elseif ( '(' === $id ) {
			if ( null !== $pending ) {
				++$paren;
			}
		} elseif ( ')' === $id ) {
			if ( null !== $pending ) {
				--$paren;
			}
		} elseif ( ';' === $id ) {
			// Metodo astratto o di interfaccia: niente corpo.
			if ( 'F' === $pending && 0 === $paren ) {
				$pending = null;
			}
		} elseif ( '{' === $id ) {
			if ( null !== $pending && 0 === $paren ) {
				$stack[] = $pending;
				$pending = null;
			} else {
				$stack[] = 'B';
			}
		} elseif ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
			$stack[] = 'B';
		} elseif ( '}' === $id ) {
			array_pop( $stack );
		} elseif ( T_INLINE_HTML === $id || T_CLOSE_TAG === $id ) {
			// Tag di chiusura e HTML sono ammessi solo dentro una funzione o un metodo (i template).
			if ( ! in_array( 'F', $stack, true ) ) {
				$info['bad_html'][] = $tok['line'];
			}
		}
	}
	return $info;
}

/**
 * Registra una dichiarazione al livello del file e segnala i duplicati.
 *
 * @param array  $info     Informazioni dell'analisi (per riferimento: messaggi di duplicato).
 * @param array  $declared Nomi gia' visti (per riferimento).
 * @param string $kind     "funzione" o "classe".
 * @param string $name     Nome dichiarato.
 * @param int    $line     Riga della dichiarazione.
 * @return void
 */
function wphc_build_register_name( array &$info, array &$declared, $kind, $name, $line ) {
	$key = $kind . ':' . strtolower( $name );
	if ( isset( $declared[ $key ] ) ) {
		$info['duplicates'][] = $kind . ' ' . $name . ' dichiarata alle righe ' . $declared[ $key ] . ' e ' . $line;
		return;
	}
	$declared[ $key ] = $line;
}

/**
 * Nome della classe estesa da una dichiarazione, o null.
 *
 * @param array[] $tokens     Token normalizzati.
 * @param int     $name_index Indice del nome della classe dichiarata.
 * @return string|null Nome senza backslash iniziale; null se non c'e' extends o se e' qualificato.
 */
function wphc_build_parent_class( array $tokens, $name_index ) {
	$j = wphc_build_next_sig( $tokens, $name_index );
	if ( null === $j || T_EXTENDS !== $tokens[ $j ]['id'] ) {
		return null;
	}
	// Si concatenano i token fino alla graffa o a "implements": copre T_STRING, T_NS_SEPARATOR e T_NAME_*.
	$name = '';
	$n    = count( $tokens );
	for ( $k = $j + 1; $k < $n; $k++ ) {
		if ( '{' === $tokens[ $k ]['id'] || T_IMPLEMENTS === $tokens[ $k ]['id'] ) {
			break;
		}
		if ( ! wphc_build_is_trivia( $tokens[ $k ] ) ) {
			$name .= $tokens[ $k ]['text'];
		}
	}
	$name = ltrim( $name, '\\' );
	// Un nome ancora qualificato (con namespace) e' per forza una classe esterna.
	return false === strpos( $name, '\\' ) && '' !== $name ? $name : null;
}

/**
 * Controlli strutturali sul file completo: gate (c), (e), duplicati, namespace.
 *
 * @param array[] $tokens Token normalizzati del file completo.
 * @return void
 * @throws RuntimeException Se una regola e' violata.
 */
function wphc_build_gate_structure( array $tokens ) {
	$info = wphc_build_analyse( $tokens );

	// Nessun namespace: il file unico non puo' mescolarli (valutazione, sezione 6.1).
	if ( array() !== $info['namespaces'] ) {
		throw new RuntimeException( 'namespace non ammesso (riga ' . $info['namespaces'][0] . ')' );
	}

	// Nessuna funzione o classe dichiarata due volte al livello del file.
	if ( array() !== $info['duplicates'] ) {
		throw new RuntimeException( 'dichiarazioni duplicate: ' . implode( '; ', $info['duplicates'] ) );
	}

	// Gate (c): una classe non puo' estendere una classe dichiarata PIU' AVANTI nel file.
	$positions = array();
	foreach ( $info['classes'] as $class ) {
		$positions[ strtolower( $class['name'] ) ] = $class['index'];
	}
	foreach ( $info['classes'] as $class ) {
		if ( null === $class['parent'] ) {
			continue;
		}
		$parent = strtolower( $class['parent'] );
		// Una classe non dichiarata nel file e' esterna (WordPress, WP-CLI): ammessa.
		if ( isset( $positions[ $parent ] ) && $positions[ $parent ] > $class['index'] ) {
			throw new RuntimeException( 'la classe ' . $class['name'] . ' (riga ' . $class['line'] . ') estende ' . $class['parent'] . ' dichiarata piu\' avanti nel file' );
		}
	}

	// Gate (e): niente tag di chiusura o HTML inline fuori dalle funzioni.
	if ( array() !== $info['bad_html'] ) {
		throw new RuntimeException( 'HTML inline o "?>" fuori da una funzione (riga ' . $info['bad_html'][0] . ')' );
	}
}

/**
 * Versione dichiarata nell'header del plugin (primo docblock del file).
 *
 * @param array[] $tokens Token normalizzati del file completo.
 * @return string|null
 */
function wphc_build_header_version( array $tokens ) {
	foreach ( $tokens as $tok ) {
		if ( T_DOC_COMMENT === $tok['id'] ) {
			// Solo il primo docblock: altre righe "Version:" nel file sono commenti o stringhe.
			if ( 1 === preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]+(\S+)[ \t]*$/m', $tok['text'], $m ) ) {
				return $m[1];
			}
			return null;
		}
	}
	return null;
}

/**
 * Valore della costante WP_HEALTH_CHECK_VERSION, letto dai token del file completo.
 *
 * @param array[] $tokens Token normalizzati del file completo.
 * @return string|null Null se la costante manca o e' definita piu' di una volta.
 */
function wphc_build_constant_version( array $tokens ) {
	$values = array();
	$n      = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_STRING !== $tokens[ $i ]['id'] || 'define' !== strtolower( $tokens[ $i ]['text'] ) ) {
			continue;
		}
		// Forma attesa: define ( 'NOME' , 'valore' ...
		$a = wphc_build_next_sig( $tokens, $i );
		$b = null === $a ? null : wphc_build_next_sig( $tokens, $a );
		$c = null === $b ? null : wphc_build_next_sig( $tokens, $b );
		$d = null === $c ? null : wphc_build_next_sig( $tokens, $c );
		if ( null === $d || '(' !== $tokens[ $a ]['id'] || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $b ]['id'] ) {
			continue;
		}
		if ( "'WP_HEALTH_CHECK_VERSION'" === $tokens[ $b ]['text'] && ',' === $tokens[ $c ]['id'] && T_CONSTANT_ENCAPSED_STRING === $tokens[ $d ]['id'] ) {
			$values[] = substr( $tokens[ $d ]['text'], 1, -1 );
		}
	}
	return 1 === count( $values ) ? $values[0] : null;
}

/**
 * Controlli finali sul file prodotto: gate (a), (d), (f), (g).
 *
 * @param string  $code   File prodotto.
 * @param array[] $tokens Token normalizzati del file prodotto (gate (a) gia' superato dal tokenizer).
 * @return void
 * @throws RuntimeException Se una regola e' violata.
 */
function wphc_build_gate_output( $code, array $tokens ) {
	// Gate (g): solo LF.
	if ( false !== strpos( $code, "\r" ) ) {
		throw new RuntimeException( 'il file prodotto contiene CR (\\r): solo LF ammessi' );
	}

	// Gate (f): il file inizia con <?php e ha l'header che WordPress e l'installer si aspettano.
	if ( 0 !== strpos( $code, '<?php' ) ) {
		throw new RuntimeException( 'il file prodotto deve iniziare con "<?php"' );
	}
	$first_doc = null;
	foreach ( $tokens as $tok ) {
		if ( T_DOC_COMMENT === $tok['id'] ) {
			$first_doc = $tok['text'];
			break;
		}
	}
	if ( null === $first_doc || false === strpos( $first_doc, 'Plugin Name:' ) ) {
		throw new RuntimeException( 'l\'header del plugin ("Plugin Name:") manca o non e\' il primo docblock del file' );
	}

	// Gate (d): la versione dell'header coincide con WP_HEALTH_CHECK_VERSION.
	$header_version   = wphc_build_header_version( $tokens );
	$constant_version = wphc_build_constant_version( $tokens );
	if ( null === $header_version ) {
		throw new RuntimeException( 'riga "Version:" non trovata nell\'header del plugin' );
	}
	if ( null === $constant_version ) {
		throw new RuntimeException( 'costante WP_HEALTH_CHECK_VERSION non trovata (o definita piu\' volte)' );
	}
	if ( $header_version !== $constant_version ) {
		throw new RuntimeException( 'versione dell\'header (' . $header_version . ') diversa da WP_HEALTH_CHECK_VERSION (' . $constant_version . ')' );
	}

	// Gate (f), seconda parte: stessa regex con cui wphc_install_release() verifica il tag.
	if ( 1 !== preg_match( '/Version:\s+' . preg_quote( $constant_version, '/' ) . '(?:\s|$)/', $code ) ) {
		throw new RuntimeException( 'il file prodotto non supera la regex "Version:" dell\'installer' );
	}
}

/**
 * Misure del file prodotto.
 *
 * @param string $code File prodotto.
 * @return array Chiavi: lines, raw, gzip, b64 (gzip livello 9 + base64, come il pacchetto di push).
 */
function wphc_build_sizes( $code ) {
	$gz = gzencode( $code, 9 );
	return array(
		'lines' => substr_count( $code, "\n" ),
		'raw'   => strlen( $code ),
		'gzip'  => strlen( $gz ),
		'b64'   => strlen( base64_encode( $gz ) ),
	);
}

/**
 * Esegue la build completa, gate compresi, senza scrivere nulla.
 *
 * @param string $src_dir Cartella dei sorgenti.
 * @param bool   $strip   True per togliere i commenti (preservando le righe).
 * @return array Chiavi: code, sizes, warnings.
 * @throws RuntimeException Se un gate fallisce.
 */
function wphc_build( $src_dir, $strip ) {
	$warnings = array();

	// 1. Lettura, controlli per file (CR, tag, ruolo class) e concatenazione.
	$entries = wphc_build_read_sources( $src_dir );
	$joined  = wphc_build_concat( $entries );

	// 2. Gate (a) sul concatenato: parse completo indipendente da "php -l".
	$joined_tokens = wphc_build_tokenize( $joined, 'file concatenato' );

	// 3. Strip e relative prove: stesse righe, stesso codice, docblock voluti presenti.
	$code   = $joined;
	$tokens = $joined_tokens;
	if ( $strip ) {
		$code   = wphc_build_strip( $joined );
		$tokens = wphc_build_tokenize( $code, 'file dopo lo strip' );

		if ( substr_count( $code, "\n" ) !== substr_count( $joined, "\n" ) ) {
			throw new RuntimeException( 'lo strip ha cambiato il numero di righe' );
		}
		if ( wphc_build_signature( $tokens ) !== wphc_build_signature( $joined_tokens ) ) {
			throw new RuntimeException( 'lo strip ha alterato i token non commento (codice o numeri di riga diversi)' );
		}
		// Gli unici docblock rimasti devono essere esattamente quelli da conservare.
		$expected = array();
		foreach ( array_keys( wphc_build_preserved_docblocks( $joined_tokens ) ) as $index ) {
			$expected[] = $joined_tokens[ $index ]['text'];
		}
		$found = array();
		foreach ( $tokens as $tok ) {
			if ( T_DOC_COMMENT === $tok['id'] ) {
				$found[] = $tok['text'];
			}
		}
		if ( $found !== $expected ) {
			throw new RuntimeException( 'dopo lo strip i docblock rimasti non coincidono con quelli da conservare' );
		}
	}

	// 4. Gate strutturali (c), (e), duplicati, namespace e gate sul file prodotto (d), (f), (g).
	wphc_build_gate_structure( $tokens );
	wphc_build_gate_output( $code, $tokens );

	// 5. Gate (h): budget del pacchetto di push. Blocca solo la build strippata (quella distribuibile).
	$sizes = wphc_build_sizes( $code );
	if ( $strip && $sizes['b64'] > WPHC_BUILD_PUSH_LIMIT ) {
		throw new RuntimeException( 'gzip+base64 = ' . $sizes['b64'] . ' byte: supera il limite del push (' . WPHC_BUILD_PUSH_LIMIT . ')' );
	}
	if ( $sizes['b64'] > WPHC_BUILD_PUSH_BUDGET ) {
		$warnings[] = 'gzip+base64 = ' . $sizes['b64'] . ' byte: oltre il budget di progetto (' . WPHC_BUILD_PUSH_BUDGET . ')';
	}

	return array(
		'code'     => $code,
		'sizes'    => $sizes,
		'warnings' => $warnings,
	);
}

/**
 * Entry point CLI.
 *
 * @param string[] $argv Argomenti da riga di comando (il primo e' lo script).
 * @return int Codice di uscita: 0 ok, 1 build fallita o differenza in --check, 2 uso errato.
 */
function wphc_build_main( array $argv ) {
	$root   = dirname( __DIR__ );
	$strip  = null; // Null = non indicato.
	$check  = false;
	$output = null;
	$src    = $root . '/src';

	// 1. Argomenti.
	$args = array_slice( $argv, 1 );
	for ( $i = 0, $count = count( $args ); $i < $count; $i++ ) {
		$arg = $args[ $i ];
		if ( '--no-strip' === $arg ) {
			$strip = false;
		} elseif ( '--strip' === $arg ) {
			$strip = true;
		} elseif ( '--check' === $arg ) {
			$check = true;
		} elseif ( '--output' === $arg || '--src' === $arg ) {
			if ( ! isset( $args[ $i + 1 ] ) ) {
				fwrite( STDERR, 'Manca il valore di ' . $arg . "\n" );
				return 2;
			}
			if ( '--output' === $arg ) {
				$output = $args[ $i + 1 ];
			} else {
				$src = $args[ $i + 1 ];
			}
			++$i;
		} elseif ( 0 === strpos( $arg, '--output=' ) ) {
			$output = substr( $arg, 9 );
		} elseif ( 0 === strpos( $arg, '--src=' ) ) {
			$src = substr( $arg, 6 );
		} else {
			fwrite( STDERR, "Uso: php bin/build.php [--check] [--strip|--no-strip] [--output <path>] [--src <dir>]\n" );
			return 2;
		}
	}

	// 2. Modo: flag esplicito, altrimenti (solo con --check) WPHC_BUILD_MODE, default dev.
	if ( null === $strip ) {
		if ( $check ) {
			$mode = getenv( 'WPHC_BUILD_MODE' );
			$mode = false === $mode || '' === $mode ? 'dev' : $mode;
			if ( ! in_array( $mode, array( 'dev', 'strip' ), true ) ) {
				fwrite( STDERR, 'WPHC_BUILD_MODE non valido: ' . $mode . " (ammessi: dev, strip)\n" );
				return 2;
			}
			$strip = 'strip' === $mode;
		} else {
			$strip = true;
		}
	}
	$target = null === $output ? $root . '/mu-plugins/wp-health-check.php' : $output;

	// 3. Build con tutti i gate.
	try {
		$result = wphc_build( $src, $strip );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, 'BUILD FALLITA: ' . $e->getMessage() . "\n" );
		return 1;
	}
	$code  = $result['code'];
	$sizes = $result['sizes'];

	fwrite( STDOUT, 'Modo:           ' . ( $strip ? 'strip (distribuibile)' : 'dev (--no-strip, NON distribuibile)' ) . "\n" );
	fwrite( STDOUT, 'Righe:          ' . $sizes['lines'] . "\n" );
	fwrite( STDOUT, 'Byte grezzi:    ' . $sizes['raw'] . "\n" );
	fwrite( STDOUT, 'gzip -9:        ' . $sizes['gzip'] . "\n" );
	fwrite( STDOUT, 'gzip + base64:  ' . $sizes['b64'] . ' (limite push ' . WPHC_BUILD_PUSH_LIMIT . ', budget ' . WPHC_BUILD_PUSH_BUDGET . ")\n" );
	fwrite( STDOUT, 'SHA-256:        ' . hash( 'sha256', $code ) . "\n" );
	foreach ( $result['warnings'] as $warning ) {
		fwrite( STDERR, 'ATTENZIONE: ' . $warning . "\n" );
	}

	// 4. --check: confronto col file committato (o con --output). Gli a capo CRLF della copia di
	// lavoro (core.autocrlf su Windows) si normalizzano: nell'indice il file e' sempre LF.
	if ( $check ) {
		if ( ! is_file( $target ) ) {
			fwrite( STDERR, 'CHECK FALLITO: file da confrontare inesistente: ' . $target . "\n" );
			return 1;
		}
		$current = file_get_contents( $target );
		if ( false !== strpos( $current, "\r\n" ) ) {
			fwrite( STDERR, "ATTENZIONE: il file contiene CRLF (copia di lavoro con autocrlf): confronto dopo la normalizzazione a LF.\n" );
			$current = str_replace( "\r\n", "\n", $current );
		}
		if ( $current === $code ) {
			fwrite( STDOUT, 'CHECK OK: ' . $target . " coincide con la build.\n" );
			return 0;
		}
		// Prima riga che differisce, per orientarsi.
		$a   = explode( "\n", $current );
		$b   = explode( "\n", $code );
		$max = max( count( $a ), count( $b ) );
		$at  = 0;
		for ( $k = 0; $k < $max; $k++ ) {
			if ( ( isset( $a[ $k ] ) ? $a[ $k ] : null ) !== ( isset( $b[ $k ] ) ? $b[ $k ] : null ) ) {
				$at = $k + 1;
				break;
			}
		}
		fwrite( STDERR, 'CHECK FALLITO: ' . $target . ' differisce dalla build (prima riga diversa: ' . $at . ', sha256 file ' . hash( 'sha256', $current ) . ").\n" );
		return 1;
	}

	// 5. Scrittura. Solo se tutti i gate sono passati; poi si rilegge il file per sicurezza.
	if ( false === file_put_contents( $target, $code ) || hash_file( 'sha256', $target ) !== hash( 'sha256', $code ) ) {
		fwrite( STDERR, 'BUILD FALLITA: scrittura non riuscita in ' . $target . "\n" );
		return 1;
	}
	fwrite( STDOUT, 'Scritto: ' . $target . "\n" );
	return 0;
}

// Esegue la CLI solo se il file e' lanciato direttamente: i test della build lo includono con require.
if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit( wphc_build_main( $argv ) );
}
