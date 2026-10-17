<?php
/**
 * Costante WP_HEALTH_CHECK_FILE (decisione D-1 del refactoring, dal lotto 3).
 *
 * Nel file 1.38.1 il self-update riscriveva `__FILE__` dentro `wphc_install_release()`. Nel codice a classi lo
 * stesso metodo (`WPHC_Self_Updater::install_release()`) legge la costante `WP_HEALTH_CHECK_FILE`, definita in
 * `src/constants.php` con default `__FILE__`: nel file costruito da `bin/build.php` e' il mu-plugin distribuito,
 * quindi il comportamento di produzione non cambia di un byte. La build ammette `__FILE__` e `__DIR__` solo in
 * quel file (gate (i), vedi `tests/build/run.php`).
 *
 * Qui si prova, sul file sotto test, che la costante e' definita, che coincide col file caricato (il default
 * `__FILE__`) e che `install_release()` la usa al posto di `__FILE__`. NON si prova che la cartella sia
 * WPMU_PLUGIN_DIR: in PHPUnit il plugin sotto test e' una copia in `sys_get_temp_dir()`, quell'uguaglianza vale
 * in produzione e negli e2e (`tests/e2e/upgrade_test.py`, self-update eseguito dal codice nuovo).
 *
 * Il test ha senso solo dal lotto 3 in poi (plugin a classi con `WPHC_Self_Updater`): con il file 1.38.1 la
 * costante non esiste.
 *
 * @package WP_Health_Check
 */

/**
 * La costante del file del plugin e il suo uso nel self-update.
 */
class WPHC_Test_Plugin_File_Constant extends WPHC_Test_Case {

	/**
	 * La costante e' definita ed e' un percorso di file esistente.
	 */
	public function test_constant_is_defined_and_points_to_an_existing_file() {
		$this->assertTrue( defined( 'WP_HEALTH_CHECK_FILE' ), 'WP_HEALTH_CHECK_FILE non e\' definita (src/constants.php)' );
		$this->assertIsString( WP_HEALTH_CHECK_FILE );
		$this->assertFileExists( WP_HEALTH_CHECK_FILE );
	}

	/**
	 * Il bootstrap dei test non la ridefinisce: vale il default `__FILE__` della build, cioe' il file sotto test
	 * (la copia, o il file indicato con WPHC_PLUGIN_FILE).
	 */
	public function test_constant_is_the_plugin_file_under_test() {
		$this->assertSame( realpath( WPHC_PLUGIN_FILE ), realpath( WP_HEALTH_CHECK_FILE ), 'WP_HEALTH_CHECK_FILE non coincide con il file sotto test' );
		$this->assertSame( dirname( WPHC_PLUGIN_FILE ), dirname( WP_HEALTH_CHECK_FILE ), 'la cartella del file del plugin non coincide' );
	}

	/**
	 * Il default e' `__FILE__` del file distribuito: il file da cui PHP ha dichiarato la classe del self-update
	 * (nel file concatenato tutte le classi stanno nello stesso file della costante).
	 */
	public function test_default_is_the_file_that_declares_the_self_updater() {
		$declared_in = ( new ReflectionClass( 'WPHC_Self_Updater' ) )->getFileName();

		$this->assertNotFalse( $declared_in );
		$this->assertSame( realpath( (string) $declared_in ), realpath( WP_HEALTH_CHECK_FILE ), 'WP_HEALTH_CHECK_FILE (default __FILE__) deve essere il file che dichiara WPHC_Self_Updater' );
	}

	/**
	 * `install_release()` legge la costante e non contiene piu' `__FILE__`/`__DIR__` (controllo sui token del
	 * corpo del metodo, nel file sotto test: con lo strip i commenti spariscono e il risultato non cambia).
	 */
	public function test_install_release_uses_the_constant_not_the_magic_constant() {
		$method = new ReflectionMethod( 'WPHC_Self_Updater', 'install_release' );
		$lines  = file( (string) $method->getFileName() );
		$this->assertIsArray( $lines );
		$body = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		$uses_constant = false;
		$magic         = array();
		foreach ( token_get_all( "<?php\n" . $body ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_FILE === $token[0] || T_DIR === $token[0] ) {
				$magic[] = $token[1] . ' (riga ' . $token[2] . ' del corpo)';
			}
			if ( T_STRING === $token[0] && 'WP_HEALTH_CHECK_FILE' === $token[1] ) {
				$uses_constant = true;
			}
		}
		$this->assertSame( array(), $magic, 'install_release() contiene __FILE__ o __DIR__' );
		$this->assertTrue( $uses_constant, 'install_release() non legge WP_HEALTH_CHECK_FILE' );
	}
}
