<?php
/**
 * Test di caratterizzazione dei comandi WP-CLI del plugin 1.38.1 (sezione T, righe ~8773-8889):
 * `wp health-check reset | secret [--show] | status`.
 *
 * La classe WPHC_CLI_Command esiste SOLO se WP_CLI e' definita (il plugin la dichiara dentro
 * `if ( defined( 'WP_CLI' ) && WP_CLI )`), cioe' non esiste nel processo PHPUnit. Il test la
 * ricava dal file del plugin con il tokenizer di PHP (indipendente dall'indentazione e dalla
 * posizione, quindi valido anche per il file generato dal build) e la dichiara con eval()
 * insieme a una classe WP_CLI finta che registra log, successi ed errori.
 *
 * ATTENZIONE: la classe WP_CLI finta (e WPHC_CLI_Command, dichiarata con eval) restano definite per
 * TUTTO il resto del processo PHPUnit, non solo per questo file: sono dichiarazioni di classe e PHP non
 * permette di rimuoverle. Con la suite completa i file caricati dopo questo vedono quindi una `WP_CLI`
 * che esiste e che NON e' quella vera (nessun test degli altri file deve basarsi sulla sua assenza); la
 * costante `WP_CLI` invece non viene mai definita, quindi il plugin non registra il comando da se'.
 *
 * L'integrazione vera con WP-CLI (help, sinossi, validazione degli argomenti) si prova
 * nell'ambiente e2e: tests/e2e/wpcli_test.py.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-admin.php';

/**
 * Segnale lanciato dalla WP_CLI finta al posto di exit: WP_CLI::error() interrompe il comando.
 */
final class WPHC_CLI_Halt extends RuntimeException {
}

if ( ! class_exists( 'WP_CLI', false ) ) {
	/**
	 * WP_CLI finta: registra cio' che il comando stampa invece di scrivere su STDOUT.
	 * (Stesso nome della classe vera, che nel processo PHPUnit non e' caricata.)
	 */
	class WP_CLI { // phpcs:ignore

		/**
		 * Righe di WP_CLI::log().
		 *
		 * @var string[]
		 */
		public static $logs = array();

		/**
		 * Messaggi di WP_CLI::success().
		 *
		 * @var string[]
		 */
		public static $successes = array();

		/**
		 * Comandi registrati con WP_CLI::add_command() (nome => classe).
		 *
		 * @var array<string,string>
		 */
		public static $commands = array();

		/**
		 * Registra una riga di log.
		 *
		 * @param string $message Messaggio.
		 */
		public static function log( $message ) {
			self::$logs[] = $message;
		}

		/**
		 * Registra un messaggio di successo.
		 *
		 * @param string $message Messaggio.
		 */
		public static function success( $message ) {
			self::$successes[] = $message;
		}

		/**
		 * Come la vera WP_CLI::error(): termina il comando (qui lanciando un segnale).
		 *
		 * @param string $message Messaggio.
		 * @throws WPHC_CLI_Halt Sempre.
		 */
		public static function error( $message ) {
			throw new WPHC_CLI_Halt( $message );
		}

		/**
		 * Registra un comando.
		 *
		 * @param string $name     Nome.
		 * @param string $callable Classe.
		 */
		public static function add_command( $name, $callable ) {
			self::$commands[ $name ] = $callable;
		}
	}
}

/**
 * Comandi WP-CLI del plugin.
 */
class WPHC_Test_Cli extends WPHC_Admin_Test_Case {

	/**
	 * Sorgente della classe (docblock + dichiarazione) ricavato dal file del plugin.
	 *
	 * @var array{source:string,depth:int,guarded:bool}|null
	 */
	private static $class_info = null;

	/**
	 * Ricava dal file del plugin il docblock e la dichiarazione di WPHC_CLI_Command.
	 *
	 * @return array{source:string,depth:int,guarded:bool} Sorgente, profondita' di annidamento
	 *         della dichiarazione e se e' dentro un `if` che cita WP_CLI.
	 */
	private static function extract_class() {
		if ( null !== self::$class_info ) {
			return self::$class_info;
		}

		$tokens = token_get_all( (string) file_get_contents( WPHC_PLUGIN_FILE ) );
		$count  = count( $tokens );
		$depth  = 0;
		$start  = null;
		$doc    = '';

		// 1. Cerca "class WPHC_CLI_Command", tenendo il conto delle graffe aperte (profondita').
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
				++$depth;
			} elseif ( '{' === $token ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
			}

			if ( is_array( $token ) && T_CLASS === $token[0] ) {
				// Il nome e' il primo T_STRING dopo "class".
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
						break;
					}
				}
				if ( isset( $tokens[ $j ] ) && 'WPHC_CLI_Command' === $tokens[ $j ][1] ) {
					$start = $i;
					break;
				}
			}
		}
		if ( null === $start ) {
			throw new RuntimeException( 'WPHC_CLI_Command non trovata nel file del plugin.' );
		}
		$declaration_depth = $depth;

		// 2. Il docblock e' il commento che precede "class" (WP-CLI ne legge la descrizione).
		for ( $k = $start - 1; $k >= 0; $k-- ) {
			if ( is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				continue;
			}
			if ( is_array( $tokens[ $k ] ) && T_DOC_COMMENT === $tokens[ $k ][0] ) {
				$doc = $tokens[ $k ][1];
			}
			break;
		}

		// 3. Raccoglie i token fino alla graffa che chiude il corpo della classe.
		$source = $doc . "\n";
		$level  = 0;
		for ( $i = $start; $i < $count; $i++ ) {
			$token  = $tokens[ $i ];
			$source .= is_array( $token ) ? $token[1] : $token;
			if ( ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) || '{' === $token ) {
				++$level;
			} elseif ( '}' === $token ) {
				--$level;
				if ( 0 === $level ) {
					break;
				}
			}
		}

		// 4. La dichiarazione deve stare dentro `if ( defined( 'WP_CLI' ) && WP_CLI )`: il file,
		// prima della classe, contiene quella condizione.
		$before  = '';
		$limit   = min( $start, $count );
		for ( $i = max( 0, $limit - 80 ); $i < $limit; $i++ ) {
			$before .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
		}

		self::$class_info = array(
			'source'  => $source,
			'depth'   => $declaration_depth,
			'guarded' => 1 === preg_match( "/defined\(\s*'WP_CLI'\s*\)\s*&&\s*WP_CLI/", $before ),
		);

		return self::$class_info;
	}

	/**
	 * Dichiara la classe del comando (una volta per processo) e la istanzia.
	 *
	 * @return object Istanza di WPHC_CLI_Command.
	 */
	private function command() {
		if ( ! class_exists( 'WPHC_CLI_Command', false ) ) {
			$info = self::extract_class();
			eval( $info['source'] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		return new WPHC_CLI_Command();
	}

	/**
	 * Azzera i registri della WP_CLI finta prima di ogni test.
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::$logs      = array();
		WP_CLI::$successes = array();
	}

	// -----------------------------------------------------------------------
	// Dichiarazione e docblock (WP-CLI li legge via reflection)
	// -----------------------------------------------------------------------

	/**
	 * La classe e' dichiarata SOLO dentro `if ( defined( 'WP_CLI' ) && WP_CLI )` (mai su una
	 * richiesta HTTP normale) e il comando e' registrato come "health-check".
	 */
	public function test_class_is_guarded_and_registered_in_the_plugin_file() {
		$info = self::extract_class();

		$this->assertGreaterThan( 0, $info['depth'], 'La classe deve essere annidata in un blocco condizionale' );
		$this->assertTrue( $info['guarded'], "La dichiarazione deve seguire `if ( defined( 'WP_CLI' ) && WP_CLI )`" );
		$this->assertMatchesRegularExpression(
			"/WP_CLI::add_command\(\s*'health-check'\s*,\s*'WPHC_CLI_Command'\s*\)/",
			(string) file_get_contents( WPHC_PLUGIN_FILE )
		);
	}

	/**
	 * Struttura per WP-CLI: nessun `extends` (non estende WP_CLI_Command), metodi pubblici d'istanza
	 * reset/secret/status, tutti con firma ( $args, $assoc_args ).
	 */
	public function test_class_shape() {
		$this->command();
		$class = new ReflectionClass( 'WPHC_CLI_Command' );

		$this->assertFalse( $class->getParentClass(), 'Non deve estendere nulla' );
		$this->assertFalse( $class->isFinal() );

		$methods = array();
		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$this->assertFalse( $method->isStatic(), $method->getName() . ' deve essere un metodo d\'istanza' );
			$params = array_map(
				static function ( $param ) {
					return $param->getName();
				},
				$method->getParameters()
			);
			$this->assertSame( array( 'args', 'assoc_args' ), $params, $method->getName() );
			$methods[] = $method->getName();
		}
		sort( $methods );

		$this->assertSame( array( 'reset', 'secret', 'status' ), $methods );
	}

	/**
	 * WP-CLI ricava descrizione, sinossi e opzioni dai docblock: devono restare (lo strip dei
	 * commenti del build NON deve toccarli). Si fissano la descrizione breve, `@when` e la sezione
	 * OPTIONS di `secret --show`.
	 */
	public function test_docblocks_are_kept_for_wp_cli() {
		$this->command();
		$class = new ReflectionClass( 'WPHC_CLI_Command' );

		// Descrizione del gruppo di comandi (docblock della classe).
		$this->assertStringContainsString( 'Comandi WP-CLI del fleet agent wp-health-check.', (string) $class->getDocComment() );

		$expected = array(
			'reset'  => 'Cancella le opzioni di enrollment di questo sito.',
			'secret' => 'Mostra fingerprint, protocollo, data di rotazione e stato di revoca',
			'status' => 'Riepilogo diagnostico dello stato del sito: enrollment, versione,',
		);
		foreach ( $expected as $name => $summary ) {
			$doc = (string) $class->getMethod( $name )->getDocComment();

			$this->assertStringContainsString( $summary, $doc, $name );
			$this->assertStringContainsString( '## EXAMPLES', $doc, $name );
			$this->assertStringContainsString( '@when after_wp_load', $doc, $name );
			$this->assertStringContainsString( 'wp health-check ' . $name, $doc, $name );
		}

		// Solo `secret` ha opzioni: [--show] con la sua descrizione, nel formato che WP-CLI parsa.
		$secret = (string) $class->getMethod( 'secret' )->getDocComment();
		$this->assertMatchesRegularExpression( '/## OPTIONS\s+\*\s+\*\s+\[--show\]\s+\*\s+: Stampa anche il segreto in chiaro, non solo il fingerprint\./', $secret );
		$this->assertStringContainsString( 'wp health-check secret --show', $secret );
		$this->assertStringNotContainsString( '## OPTIONS', (string) $class->getMethod( 'reset' )->getDocComment() );
		$this->assertStringNotContainsString( '## OPTIONS', (string) $class->getMethod( 'status' )->getDocComment() );
	}

	// -----------------------------------------------------------------------
	// wp health-check reset
	// -----------------------------------------------------------------------

	/**
	 * `reset` cancella l'enrollment (stessa lista del pulsante in wp-admin), lascia la config del
	 * sito e stampa il messaggio di successo; gli argomenti sono ignorati.
	 */
	public function test_reset_clears_enrollment_and_reports_success() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_revoked_at', gmdate( 'c' ), false );
		update_option( 'wp_health_check_webhook_url', 'https://hooks.example.com/x', false );
		update_option( 'wp_health_check_updates_enabled', 0 );
		set_transient( 'wp_health_check_update_lock', time(), 300 );

		$this->command()->reset( array( 'ignorato' ), array( 'anche' => 'questo' ) );

		$this->assertSame( array( 'wp_health_check_updates_enabled', 'wp_health_check_webhook_url' ), array_keys( $this->plugin_options() ) );
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ) );
		$this->assertSame( array( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".' ), WP_CLI::$successes );
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/**
	 * `reset` su un sito gia' non enrollato non e' un errore.
	 */
	public function test_reset_on_a_site_without_enrollment_still_succeeds() {
		$this->command()->reset( array(), array() );

		$this->assertCount( 1, WP_CLI::$successes );
	}

	// -----------------------------------------------------------------------
	// wp health-check secret [--show]
	// -----------------------------------------------------------------------

	/**
	 * Senza segreto: WP_CLI::error( "Nessun segreto registrato (sito non ancora enrollato)." ) e
	 * nessuna riga di log.
	 */
	public function test_secret_without_enrollment_is_an_error() {
		try {
			$this->command()->secret( array(), array() );
			$this->fail( 'WP_CLI::error() doveva interrompere il comando' );
		} catch ( WPHC_CLI_Halt $halt ) {
			$this->assertSame( 'Nessun segreto registrato (sito non ancora enrollato).', $halt->getMessage() );
		}
		$this->assertSame( array(), WP_CLI::$logs );

		// Anche con --show: il controllo viene prima.
		$this->expectException( WPHC_CLI_Halt::class );
		$this->command()->secret( array(), array( 'show' => true ) );
	}

	/**
	 * Protocollo 1, mai ruotato: fingerprint, protocollo, "mai", "no"; il segreto NON e' stampato.
	 */
	public function test_secret_protocol_one_hides_the_secret() {
		$token = 'tok-cli-secret-0001';
		$this->seed_enrolled( $token, 1 );

		$this->command()->secret( array(), array() );

		$this->assertSame(
			array(
				'Fingerprint: ' . substr( hash( 'sha256', $token ), 0, 12 ),
				'Protocollo: 1',
				'Ultima rotazione: mai',
				'Revocato: no',
			),
			WP_CLI::$logs
		);
		$this->assertStringNotContainsString( $token, implode( "\n", WP_CLI::$logs ) );
		$this->assertSame( array(), WP_CLI::$successes );
	}

	/**
	 * `--show` aggiunge in coda la riga "Segreto: <in chiaro>"; con rotazione e revoca le righe
	 * riportano la data ISO 8601 della rotazione e il valore grezzo dell'option di revoca.
	 */
	public function test_secret_show_prints_the_secret_after_rotation_and_revocation() {
		$token = 'tok-cli-secret-0002';
		$this->seed_enrolled_v2( $token );
		update_option( 'wp_health_check_revoked_at', '2026-10-05T08:30:00+00:00', false );

		$this->command()->secret( array(), array( 'show' => true ) );

		$this->assertSame(
			array(
				'Fingerprint: ' . substr( hash( 'sha256', $token ), 0, 12 ),
				'Protocollo: 2',
				'Ultima rotazione: ' . gmdate( 'c', 1790000000 ),
				'Revocato: 2026-10-05T08:30:00+00:00',
				'Segreto: ' . $token,
			),
			WP_CLI::$logs
		);
	}

	/**
	 * Quali valori di --show contano come "attivo": il test e' `! empty()`. In WP-CLI il flag senza
	 * valore arriva come true; `--show=0` come stringa "0" (spento); `--show=false` come stringa
	 * "false", che NON e' vuota e quindi stampa il segreto (comportamento da preservare).
	 */
	public function test_secret_show_flag_truthiness() {
		$this->seed_enrolled( 'tok-cli-secret-0003', 1 );

		$cases = array(
			'true'          => array( true, true ),
			'stringa 1'     => array( '1', true ),
			'stringa false' => array( 'false', true ),
			'stringa si'    => array( 'si', true ),
			'stringa 0'     => array( '0', false ),
			'boolean false' => array( false, false ),
			'stringa vuota' => array( '', false ),
			'assente'       => array( null, false ),
		);
		foreach ( $cases as $label => $case ) {
			list( $value, $prints ) = $case;
			WP_CLI::$logs           = array();
			$assoc                  = null === $value ? array() : array( 'show' => $value );

			$this->command()->secret( array(), $assoc );

			$this->assertCount( $prints ? 5 : 4, WP_CLI::$logs, $label );
		}
	}

	// -----------------------------------------------------------------------
	// wp health-check status
	// -----------------------------------------------------------------------

	/**
	 * Sito non enrollato: sei righe nell'ordine fisso, nessun errore.
	 */
	public function test_status_not_enrolled() {
		$this->command()->status( array(), array() );

		$this->assertSame(
			array(
				'Versione agent: ' . WP_HEALTH_CHECK_VERSION,
				'Enrollment: non registrato',
				'Protocollo: 1',
				'Revocato: no',
				'Ultimo enroll fallito: nessuno',
				'Thumbnail: non ancora generata',
			),
			WP_CLI::$logs
		);
		$this->assertSame( array(), WP_CLI::$successes );
	}

	/**
	 * Sito enrollato, revocato, con un enroll fallito e una thumbnail: ogni riga riflette lo stato.
	 */
	public function test_status_enrolled_revoked_with_errors_and_thumbnail() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_revoked_at', gmdate( 'c' ), false );
		update_option(
			'wp_health_check_last_enroll_error',
			array(
				'code'   => 'wphc_enroll_url_mismatch',
				'reason' => 'URL non coincidente',
			),
			false
		);
		update_option( 'wp_health_check_thumb', 'https://example.org/t.png', false );

		$this->command()->status( array(), array() );

		$this->assertSame(
			array(
				'Versione agent: ' . WP_HEALTH_CHECK_VERSION,
				'Enrollment: registrato',
				'Protocollo: 2',
				'Revocato: si\'',
				'Ultimo enroll fallito: wphc_enroll_url_mismatch — URL non coincidente',
				'Thumbnail: presente',
			),
			WP_CLI::$logs
		);
	}

	/**
	 * Senza thumbnail ma con un errore di generazione: "errore: <codice>".
	 */
	public function test_status_thumbnail_error() {
		update_option( 'wp_health_check_thumb_error', array( 'code' => 'wphc_thumb_download_failed' ), false );

		$this->command()->status( array(), array() );

		$this->assertSame( 'Thumbnail: errore: wphc_thumb_download_failed', WP_CLI::$logs[5] );
	}
}
