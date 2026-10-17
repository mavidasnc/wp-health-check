<?php
/**
 * Test del punto di adattamento ai nomi del refactoring: `tests/refactor-map.php`, `cb()`, `call()` e `call_args()`
 * di WPHC_Test_Case. Tutta la suite chiama le funzioni interne del plugin per mezzo di questi tre metodi, quindi
 * se non funzionano con i metodi statici del refactoring (specie quelli `private` e quelli con parametri per
 * riferimento) il refactoring sembrerebbe rotto per ragioni che non sono del plugin.
 *
 * Con il plugin 1.38.1 (funzioni) il ramo "metodo" di cb() non gira mai: per provarlo qui si usa una classe di
 * prova (WPHC_Test_Map_Fixture) aggiunta alla mappa con WPHC_Test_Case::$map_overrides.
 *
 * @package WP_Health_Check
 */

/**
 * Classe di prova con un metodo pubblico, uno privato e uno privato con parametro per riferimento.
 */
final class WPHC_Test_Map_Fixture {

	/**
	 * Metodo pubblico.
	 *
	 * @param int $value Valore.
	 * @return int
	 */
	public static function double_it( $value ) {
		return $value * 2;
	}

	/**
	 * Metodo privato con parametro per riferimento (come i `wphc_bulk_*` con `array &$job`).
	 *
	 * @param array $job Documento, modificato in place.
	 * @param int   $by  Incremento.
	 * @return int Nuovo valore.
	 */
	private static function bump( array &$job, $by ) {
		$job['n'] += $by;

		return $job['n'];
	}

	/**
	 * Metodo d'istanza (non ammesso dalla mappa).
	 *
	 * @return string
	 */
	public function instance_method() {
		return 'x';
	}
}

/**
 * Mappa, cb(), call() e call_args().
 */
class WPHC_Test_Refactor_Map extends WPHC_Test_Case {

	/**
	 * Ripulisce le voci di prova.
	 */
	public function tear_down() {
		self::$map_overrides = array();
		parent::tear_down();
	}

	/**
	 * La mappa ha le 157 funzioni del plugin 1.38.1 (tabella 2.2 del progetto), tutte con un array di due stringhe
	 * non vuote e senza metodi ripetuti (due funzioni non possono avere lo stesso metodo di destinazione).
	 */
	public function test_map_has_every_function_and_unique_targets() {
		$map = self::refactor_map();

		$this->assertCount( 157, $map );
		$targets = array();
		foreach ( $map as $function_name => $target ) {
			$this->assertMatchesRegularExpression( '/^wphc_[a-z0-9_]+$/', $function_name );
			$this->assertCount( 2, $target, $function_name );
			$this->assertMatchesRegularExpression( '/^WPHC_[A-Za-z_]+$/', $target[0], $function_name );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $target[1], $function_name );
			$targets[] = $target[0] . '::' . $target[1];
		}
		$this->assertSame( count( $targets ), count( array_unique( $targets ) ), 'metodi di destinazione ripetuti' );
	}

	/**
	 * La mappa coincide con quello che il plugin caricato dichiara davvero, in ogni fase del refactoring
	 * (1.38.1 a funzioni, lotti intermedi misti, plugin tutto a classi): ogni funzione `wphc_*` dichiarata nel
	 * file sotto test e' una chiave della mappa, e per OGNI voce vale esattamente una forma: la funzione
	 * dichiarata nel file sotto test oppure il metodo statico di destinazione, dichiarato nello stesso file.
	 * Una funzione spostata ma rimasta anche in legacy (o un wrapper) fa fallire il test, cosi' come una
	 * funzione accorpata o rinominata senza aggiornare la mappa.
	 */
	public function test_map_matches_what_the_loaded_plugin_declares() {
		$map = self::refactor_map();

		// 1. Funzioni wphc_* dichiarate nel file sotto test (quelle dei test stanno sotto tests/).
		$declared = array();
		foreach ( get_defined_functions()['user'] as $function_name ) {
			if ( 0 !== strpos( $function_name, 'wphc_' ) || 0 === strpos( $function_name, 'wphc_test_' ) || 'wphc_read_test_passthrough' === $function_name ) {
				continue;
			}
			if ( realpath( (string) ( new ReflectionFunction( $function_name ) )->getFileName() ) === WPHC_PLUGIN_FILE ) {
				$declared[] = $function_name;
			}
		}

		// 2. Nessuna funzione del plugin fuori dalla mappa.
		$this->assertSame( array(), array_values( array_diff( $declared, array_keys( $map ) ) ), 'funzioni wphc_* del plugin assenti da tests/refactor-map.php' );

		// 3. Per ogni voce esattamente una forma: funzione nel file sotto test XOR metodo statico nello stesso file.
		foreach ( $map as $function_name => $target ) {
			$is_function = in_array( $function_name, $declared, true );
			$is_method   = class_exists( $target[0], false ) && method_exists( $target[0], $target[1] );
			$this->assertTrue( $is_function xor $is_method, "{$function_name}: deve esistere come funzione OPPURE come {$target[0]}::{$target[1]}, non entrambe ne' nessuna" );
			if ( $is_method ) {
				$method = new ReflectionMethod( $target[0], $target[1] );
				$this->assertTrue( $method->isStatic(), "{$function_name}: {$target[0]}::{$target[1]} non e' statico" );
				$this->assertSame( WPHC_PLUGIN_FILE, realpath( (string) $method->getFileName() ), "{$function_name}: {$target[0]}::{$target[1]} non e' dichiarato nel file sotto test" );
			}
		}
	}

	/**
	 * cb() risolve OGNI chiave della mappa (funzione o metodo): nessuna eccezione.
	 */
	public function test_cb_resolves_every_entry() {
		foreach ( array_keys( self::refactor_map() ) as $function_name ) {
			$this->assertIsCallable( self::cb( $function_name ), $function_name );
		}
	}

	/**
	 * Un nome ne' funzione ne' mappato lancia un'eccezione che rimanda alla mappa (mai un ripiego silenzioso).
	 */
	public function test_cb_throws_for_an_unknown_function() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'tests/refactor-map.php' );
		self::cb( 'wphc_funzione_inesistente' );
	}

	/**
	 * Ramo "metodo pubblico": cb() restituisce l'array classe/metodo (adatto a has_filter()) e call() lo chiama.
	 */
	public function test_cb_returns_the_array_for_a_public_method() {
		self::$map_overrides['wphc_fixture_double'] = array( 'WPHC_Test_Map_Fixture', 'double_it' );

		$this->assertSame( array( 'WPHC_Test_Map_Fixture', 'double_it' ), self::cb( 'wphc_fixture_double' ) );
		$this->assertSame( 14, self::call( 'wphc_fixture_double', 7 ) );
	}

	/**
	 * Ramo "metodo privato": cb() restituisce una Closure chiamabile da fuori, e call_args() conserva il
	 * RIFERIMENTO (il documento passato e' modificato in place), come serve ai `wphc_bulk_*` con `array &$job`.
	 */
	public function test_cb_reaches_a_private_method_and_call_args_keeps_the_reference() {
		self::$map_overrides['wphc_fixture_bump'] = array( 'WPHC_Test_Map_Fixture', 'bump' );

		$this->assertInstanceOf( Closure::class, self::cb( 'wphc_fixture_bump' ) );

		$job    = array( 'n' => 1 );
		$result = self::call_args( 'wphc_fixture_bump', array( &$job, 4 ) );
		$this->assertSame( 5, $result );
		$this->assertSame( array( 'n' => 5 ), $job, 'il riferimento deve essere conservato' );

		// Anche la sintassi diretta sul callable restituito conserva il riferimento.
		$bump = self::cb( 'wphc_fixture_bump' );
		$bump( $job, 10 );
		$this->assertSame( 15, $job['n'] );
	}

	/**
	 * Un metodo d'istanza non e' ammesso: la mappa prevede solo metodi statici.
	 */
	public function test_cb_rejects_a_non_static_method() {
		self::$map_overrides['wphc_fixture_instance'] = array( 'WPHC_Test_Map_Fixture', 'instance_method' );

		$this->expectException( RuntimeException::class );
		self::cb( 'wphc_fixture_instance' );
	}

	/**
	 * Con il plugin a funzioni, le otto funzioni con `array &$job` modificano il documento passato con call_args():
	 * prova su `wphc_bulk_recount()` (richiamata da cb() con la funzione vera).
	 */
	public function test_call_args_keeps_the_reference_for_a_real_by_reference_function() {
		$item_done = self::call( 'wphc_bulk_new_item', 'plugin', 'a/a.php' );
		$item_done['state']  = 'done';
		$item_done['result'] = 'updated';
		$job                 = array(
			'items'    => array( $item_done, self::call( 'wphc_bulk_new_item', 'plugin', 'b/b.php' ) ),
			'counters' => array(),
		);

		self::call_args( 'wphc_bulk_recount', array( &$job ) );

		$this->assertSame( 1, $job['counters']['done'] );
		$this->assertSame( 1, $job['counters']['pending'] );
	}
}
