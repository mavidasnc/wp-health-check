<?php
/**
 * Guardia PER-TEST sul file del plugin sotto test.
 *
 * Il self-update (`wphc_install_release`) riscrive `__FILE__` e crea un `.bak` accanto: un test che
 * percorre davvero quel ramo (il pull dall'admin, un push con un pacchetto valido) cambierebbe il
 * file sotto test. Questo trait e' usato da TUTTE le classi base che possono arrivarci (update e admin):
 *
 *  - plugin_guard_arm()     a inizio test: memorizza i byte del file e quali `.bak` / `.tmp` esistono gia';
 *  - plugin_guard_release() a fine test: se i byte sono cambiati li RIPRISTINA, cancella i soli `.bak` e
 *                           `.wphc-new-*.tmp` creati dal test sul file sotto test (mai altri `*.bak`) e
 *                           restituisce l'elenco dei problemi;
 *  - plugin_guard_assert()  fa fallire il test ad alta voce se ci sono problemi (da chiamare DOPO
 *                           parent::tear_down(), cosi' lo stato viene comunque ripulito).
 *
 * Rapporto con la guardia del bootstrap (tests/bootstrap.php): quella e' GLOBALE e gira a fine processo
 * (`register_shutdown_function`): confronta lo SHA-256 del file sotto test e di quello del repo e controlla
 * che non restino `.bak` / `.tmp`, e fa terminare PHPUnit con exit(1) anche se tutti i test sono verdi.
 * Questa invece e' per-test, ripristina e dice QUALE test ha scritto. Le due non si duplicano: se questa
 * ripristina il file, la guardia finale non vede nulla (ed e' giusto, il test e' gia' fallito).
 *
 * @package WP_Health_Check
 */

/**
 * Guardia sul file del plugin, riusabile dalle classi base dei test.
 */
trait WPHC_Plugin_File_Guard {

	/**
	 * Byte del file sotto test all'inizio del test.
	 *
	 * @var string
	 */
	private $guard_plugin_bytes = '';

	/**
	 * File `.bak` / `.tmp` gia' presenti all'inizio del test (non creati dal test: non si toccano).
	 *
	 * @var string[]
	 */
	private $guard_stray_before = array();

	/**
	 * Arma la guardia: da chiamare nel set_up(), prima di qualunque operazione del test.
	 */
	protected function plugin_guard_arm() {
		// 1. Byte del file sotto test (copia di lavoro: mai il file del repo, vedi il bootstrap).
		$this->guard_plugin_bytes = (string) file_get_contents( WPHC_PLUGIN_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// 2. Residui gia' presenti (di un run interrotto o di un altro test): non sono colpa di questo test.
		$this->guard_stray_before = $this->plugin_guard_stray_files();
	}

	/**
	 * Disarma la guardia: ripristina il file, ripulisce i residui creati dal test, restituisce i problemi.
	 * Da chiamare nel tear_down(), prima di parent::tear_down().
	 *
	 * @return string[] Descrizione dei problemi (vuoto = nessuno).
	 */
	protected function plugin_guard_release() {
		clearstatcache();
		$problems = array();

		// 1. File modificato: ripristino prima di ogni altra cosa (il test e' comunque fallito).
		$current = (string) file_get_contents( WPHC_PLUGIN_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $current !== $this->guard_plugin_bytes ) {
			file_put_contents( WPHC_PLUGIN_FILE, $this->guard_plugin_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$problems[] = 'GUARDIA: il test ha modificato il file del plugin sotto test (ripristinato).';
		}

		// 2. Residui creati dal test: SOLO quelli nuovi rispetto all'inizio del test, e solo del file sotto test.
		$leftovers = array_values( array_diff( $this->plugin_guard_stray_files(), $this->guard_stray_before ) );
		foreach ( $leftovers as $file ) {
			if ( is_dir( $file ) ) {
				@rmdir( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( array() !== $leftovers ) {
			$problems[] = 'GUARDIA: file inattesi accanto al plugin (rimossi): ' . implode( ', ', $leftovers );
		}

		return $problems;
	}

	/**
	 * Fa fallire il test se la guardia ha rilevato problemi.
	 *
	 * @param string[] $problems Esito di plugin_guard_release().
	 */
	protected function plugin_guard_assert( array $problems ) {
		if ( array() !== $problems ) {
			$this->fail( implode( ' ', $problems ) );
		}
	}

	/**
	 * `.bak` del file sotto test e `.wphc-new-*.tmp` nella cartella del file sotto test e in WPMU_PLUGIN_DIR.
	 * Il `.bak` e' cercato per NOME ESATTO (`<file sotto test>.bak`): altri `*.bak` della cartella non sono
	 * del plugin e non si cancellano mai.
	 *
	 * @return string[]
	 */
	private function plugin_guard_stray_files() {
		$found = array();

		if ( file_exists( WPHC_PLUGIN_FILE . '.bak' ) ) {
			$found[] = WPHC_PLUGIN_FILE . '.bak';
		}
		$dirs = array_unique( array( dirname( WPHC_PLUGIN_FILE ), rtrim( WPMU_PLUGIN_DIR, '/\\' ) ) );
		foreach ( $dirs as $dir ) {
			$matches = glob( $dir . '/.wphc-new-*.tmp' );
			if ( is_array( $matches ) ) {
				$found = array_merge( $found, $matches );
			}
		}

		return $found;
	}
}
