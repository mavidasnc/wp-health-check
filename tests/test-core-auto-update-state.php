<?php
/**
 * Test di caratterizzazione di `wphc_core_auto_update_state()` (righe 521-587 della 1.38.1), che fissa lo stato
 * dell'auto-update del core riportato da /health nel campo `core_auto_update`.
 *
 * Perche' un file a parte con un processo PHP per test:
 *  - la funzione ha una `static $cache` (nel refactoring `WPHC_Health::$core_auto_update_state`): il primo valore
 *    calcolato nel processo resta per tutti i test successivi, quindi in un processo condiviso solo il primo test
 *    vedrebbe davvero il codice;
 *  - alcuni casi dipendono da costanti (`AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`), che in PHP si
 *    definiscono una volta sola per processo.
 * Ogni test e' quindi `@runInSeparateProcess` (con `@preserveGlobalState disabled`: lo stato globale di
 * WordPress non e' serializzabile). Il processo figlio rilancia tests/bootstrap.php; il bootstrap imposta
 * WP_TESTS_SKIP_INSTALL=1 dopo l'avvio del padre, cosi' il figlio NON reinstalla il database sotto i piedi del padre.
 *
 * Nei golden di /health i campi `core_auto_update*` restano mascherati per ambiente (<ENV:...>): questi test sono
 * l'unico punto in cui i VALORI della funzione sono fissati.
 *
 * @package WP_Health_Check
 */

/**
 * Stato dell'auto-update del core: precedenza fra file_mod, updater disabilitato, option, costante e filtri.
 */
class WPHC_Test_Core_Auto_Update_State extends WPHC_Test_Case {

	/**
	 * Stato calcolato dalla funzione (o dal metodo che la sostituisce).
	 *
	 * @return array{enabled:bool,level:string,blocked_by:string|null}
	 */
	private function state() {
		return self::call( 'wphc_core_auto_update_state' );
	}

	/**
	 * Porta il sito in una condizione "pulita" per questa funzione: nessuna option di auto-update del core, nessun
	 * filtro residuo della test library su automatic_updater_disabled / file_mod_allowed.
	 */
	private function clean_conditions() {
		delete_site_option( 'auto_update_core_dev' );
		delete_site_option( 'auto_update_core_minor' );
		delete_site_option( 'auto_update_core_major' );
		remove_all_filters( 'file_mod_allowed' );
		remove_all_filters( 'automatic_updater_disabled' );
		remove_all_filters( 'allow_minor_auto_core_updates' );
		remove_all_filters( 'allow_major_auto_core_updates' );
		remove_all_filters( 'allow_dev_auto_core_updates' );
	}

	/**
	 * Default (nessuna option, nessun filtro): minor e dev "enabled" per default, major "unset" quindi spento:
	 * livello 'minor', abilitato, nessun blocco.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_default_is_minor_only() {
		$this->clean_conditions();

		$this->assertSame(
			array(
				'enabled'    => true,
				'level'      => 'minor',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * `file_mod_allowed` = false (DISALLOW_FILE_MODS, filtro): spegne tutto, blocked_by 'file_mods', prima di
	 * qualunque altra regola (anche con le option e il filtro automatic_updater_disabled).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_file_mods_disallowed_blocks_everything() {
		$this->clean_conditions();
		update_site_option( 'auto_update_core_major', 'enabled' );
		add_filter( 'file_mod_allowed', '__return_false' );

		$this->assertSame(
			array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => 'file_mods',
			),
			$this->state()
		);
	}

	/**
	 * Filtro `automatic_updater_disabled` vero: spegne tutto con blocked_by 'automatic_updater_disabled' (anche se
	 * le option abiliterebbero il major).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_automatic_updater_disabled_filter_blocks_everything() {
		$this->clean_conditions();
		update_site_option( 'auto_update_core_major', 'enabled' );
		add_filter( 'automatic_updater_disabled', '__return_true' );

		$this->assertSame(
			array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => 'automatic_updater_disabled',
			),
			$this->state()
		);
	}

	/**
	 * La costante AUTOMATIC_UPDATER_DISABLED vera ha lo stesso effetto del filtro (e' il valore di partenza del filtro).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_automatic_updater_disabled_constant_blocks_everything() {
		$this->clean_conditions();
		define( 'AUTOMATIC_UPDATER_DISABLED', true );

		$this->assertSame(
			array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => 'automatic_updater_disabled',
			),
			$this->state()
		);
	}

	/**
	 * Option `auto_update_core_major` = 'enabled': il major si accende e il livello e' 'all'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_major_option_enabled_gives_level_all() {
		$this->clean_conditions();
		update_site_option( 'auto_update_core_major', 'enabled' );

		$this->assertSame(
			array(
				'enabled'    => true,
				'level'      => 'all',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * Filtro `allow_minor_auto_core_updates` = false con il major spento (default): nessun livello, auto-update
	 * non abilitato (non e' un "blocco": blocked_by resta null).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_minor_filter_false_gives_level_none() {
		$this->clean_conditions();
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );

		$this->assertSame(
			array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * Option `auto_update_core_minor` diversa da 'enabled': il minor si spegne (con il major spento: livello none).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_minor_option_disabled_gives_level_none() {
		$this->clean_conditions();
		update_site_option( 'auto_update_core_minor', 'disabled' );

		$this->assertSame(
			array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * La costante WP_AUTO_UPDATE_CORE batte le option: `false` spegne tutto anche con il major 'enabled'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_false_beats_the_options() {
		$this->clean_conditions();
		update_site_option( 'auto_update_core_major', 'enabled' );
		define( 'WP_AUTO_UPDATE_CORE', false );

		$this->assertSame(
			array(
				'enabled'    => false,
				'level'      => 'none',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * Costante 'minor': solo i minor, anche con il major 'enabled' nelle option.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_minor_forces_minor_only() {
		$this->clean_conditions();
		update_site_option( 'auto_update_core_major', 'enabled' );
		define( 'WP_AUTO_UPDATE_CORE', 'minor' );

		$this->assertSame(
			array(
				'enabled'    => true,
				'level'      => 'minor',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * Costante true: tutti gli aggiornamenti, livello 'all'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_true_gives_level_all() {
		$this->clean_conditions();
		define( 'WP_AUTO_UPDATE_CORE', true );

		$this->assertSame(
			array(
				'enabled'    => true,
				'level'      => 'all',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * I filtri allow_*_auto_core_updates battono tutto il resto, costante compresa: `allow_major` vero con la
	 * costante 'minor' porta il livello a 'all'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_major_filter_beats_the_constant() {
		$this->clean_conditions();
		define( 'WP_AUTO_UPDATE_CORE', 'minor' );
		add_filter( 'allow_major_auto_core_updates', '__return_true' );

		$this->assertSame(
			array(
				'enabled'    => true,
				'level'      => 'all',
				'blocked_by' => null,
			),
			$this->state()
		);
	}

	/**
	 * PRESERVATO: il risultato e' calcolato UNA VOLTA per processo (static $cache). Una modifica successiva delle
	 * option o dei filtri non cambia il valore restituito dalle chiamate seguenti. Nei test PHPUnit la cache
	 * persiste fra un test e l'altro (e' il motivo dei processi separati di questo file).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_result_is_cached_for_the_rest_of_the_process() {
		$this->clean_conditions();

		$first = $this->state();
		$this->assertSame( 'minor', $first['level'] );

		// Cambiano option e filtri: la funzione continua a restituire il primo valore.
		update_site_option( 'auto_update_core_major', 'enabled' );
		add_filter( 'file_mod_allowed', '__return_false' );

		$this->assertSame( $first, $this->state() );
	}
}
