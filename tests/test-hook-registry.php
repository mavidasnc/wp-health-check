<?php
/**
 * Registro degli hook, delle rotte REST e dell'ordine di esecuzione del plugin.
 *
 * Perche': il refactoring in classi sposta le registrazioni `add_action`/`add_filter` in
 * `WPHC_Plugin::boot()` e cambia i callback da nomi di funzione ad array `array( 'Classe', 'metodo' )`. Il
 * comportamento osservabile da WordPress (e da altri plugin) dipende da TRE cose che un test per nome non vede:
 *  - la PRIORITA' e gli `accepted_args` di ogni callback;
 *  - l'ORDINE relativo dei callback dello stesso hook e della stessa priorita' (su `init` ce ne sono quattro e
 *    l'ultimo, l'autologin, fa `exit`);
 *  - le rotte REST: metodi, callback e `permission_callback` di ciascun handler.
 *
 * Il test fotografa tutto questo DOPO il bootstrap e `rest_get_server()` e lo confronta con il golden
 * `fixtures/golden/hooks/registry.json` (SENZA maschere: nessun valore e' volatile). I callback sono riportati ai
 * NOMI DELLA 1.38.1 (la mappa inversa di tests/refactor-map.php trasforma `WPHC_X::metodo` nella funzione di
 * partenza): cosi' lo stesso golden vale per la forma a funzioni e per la forma a classi. Un callback che la mappa
 * non conosce FA FALLIRE il test (mai un ripiego sul callable grezzo: nasconderebbe un metodo non mappato).
 *
 * Cosa NON si puo' osservare qui (e dove si osserva):
 *  - `WP_CLI::add_command( 'health-check', 'WPHC_CLI_Command' )`: la costante WP_CLI non e' definita nel processo
 *    PHPUnit, quindi la registrazione non gira. Si verifica nel sorgente (qui sotto e in test-cli.php) e nel
 *    contesto in cui e' osservabile, cioe' con la WP-CLI vera: `tests/e2e/wpcli_test.py`.
 *  - `register_shutdown_function` e la schedulazione cron non sono hook.
 *
 * @package WP_Health_Check
 */

/**
 * Registro di hook e rotte: fotografia confrontata con il golden e asserzioni sui punti delicati.
 */
class WPHC_Test_Hook_Registry extends WPHC_Test_Case {

	/**
	 * Mappa inversa "Classe::metodo" => nome della funzione 1.38.1, dalla mappa unica dei test.
	 *
	 * @return array<string,string>
	 */
	private static function inverse_map() {
		static $inverse = null;
		if ( null === $inverse ) {
			$inverse = array();
			foreach ( self::refactor_map() as $function_name => $target ) {
				$inverse[ $target[0] . '::' . $target[1] ] = $function_name;
			}
		}

		return $inverse;
	}

	/**
	 * Reflection del callback (funzione, metodo statico/d'istanza, Closure), o null se non risolvibile.
	 *
	 * @param mixed $callback Callback registrato.
	 * @return ReflectionFunctionAbstract|null
	 */
	private static function reflect( $callback ) {
		try {
			if ( $callback instanceof Closure ) {
				return new ReflectionFunction( $callback );
			}
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				return new ReflectionMethod( $callback );
			}
			if ( is_string( $callback ) ) {
				return new ReflectionFunction( $callback );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				return new ReflectionMethod( $callback[0], $callback[1] );
			}
			if ( is_object( $callback ) ) {
				return new ReflectionMethod( $callback, '__invoke' );
			}
		} catch ( ReflectionException $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Nome "leggibile" del callback: `funzione`, `Classe::metodo`, `Closure`.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function raw_name( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1];
		}

		return $callback instanceof Closure ? 'Closure' : get_class( $callback );
	}

	/**
	 * Il callback e' del plugin? Si decide dal FILE in cui e' dichiarato (Reflection): e' del plugin se sta nel file
	 * sotto test; un callback dichiarato altrove (core, test library, classi di supporto sotto tests/ che hanno lo
	 * stesso prefisso `WPHC_`, come questa classe agganciata dalla test library) NON lo e'. Il solo prefisso
	 * `wphc_` / `WPHC_` conta quando il file non e' determinabile (codice generato con eval) o quando il file e'
	 * nella stessa cartella del plugin (build in piu' file).
	 *
	 * @param mixed $callback Callback.
	 * @return bool
	 */
	private static function is_plugin_callback( $callback ) {
		$reflection = self::reflect( $callback );
		$file       = ( null !== $reflection && false !== $reflection->getFileName() ) ? realpath( $reflection->getFileName() ) : false;
		$prefixed   = 1 === preg_match( '/^(?:wphc_|WPHC_)/', self::raw_name( $callback ) );

		if ( false === $file ) {
			return $prefixed;
		}
		if ( WPHC_PLUGIN_FILE === $file ) {
			return true;
		}

		return $prefixed && 0 === strpos( $file, dirname( WPHC_PLUGIN_FILE ) . DIRECTORY_SEPARATOR );
	}

	/**
	 * Callback riportato al nome della funzione 1.38.1. Fallisce se non e' riconducibile alla mappa.
	 *
	 * @param mixed $callback Callback del plugin.
	 * @return string
	 */
	private static function normalize_callback( $callback ) {
		$name = self::raw_name( $callback );
		if ( 'Closure' === $name ) {
			throw new RuntimeException( 'Closure registrata dal plugin: il registro riporta solo callback con nome. Normalizzare per ordine o dare un nome al callback.' );
		}
		$map = self::refactor_map();
		if ( isset( $map[ $name ] ) ) {
			return $name; // Funzione 1.38.1 (chiave della mappa).
		}
		$inverse = self::inverse_map();
		if ( isset( $inverse[ $name ] ) ) {
			return $inverse[ $name ];
		}

		throw new RuntimeException( "Il callback {$name} e' del plugin ma non e' nella mappa tests/refactor-map.php (ne' come funzione 1.38.1 ne' come metodo): aggiungerlo alla mappa." );
	}

	/**
	 * Come normalize_callback() ma lascia passare i callback che non sono del plugin (es. `__return_true`).
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function normalize_any( $callback ) {
		return self::is_plugin_callback( $callback ) ? self::normalize_callback( $callback ) : self::raw_name( $callback );
	}

	/**
	 * Registro degli hook: hook => elenco ordinato di array( priorita', callback 1.38.1, accepted_args ), solo con i
	 * callback del plugin, nell'ORDINE DI ESECUZIONE (priorita' crescente, poi ordine di registrazione).
	 *
	 * @return array<string,array<int,array{0:int,1:string,2:int}>>
	 */
	private static function hook_registry() {
		global $wp_filter;

		$registry = array();
		foreach ( $wp_filter as $hook => $wp_hook ) {
			$callbacks = $wp_hook->callbacks;
			ksort( $callbacks, SORT_NUMERIC );
			foreach ( $callbacks as $priority => $entries ) {
				foreach ( $entries as $entry ) {
					if ( self::is_plugin_callback( $entry['function'] ) ) {
						$registry[ $hook ][] = array( (int) $priority, self::normalize_callback( $entry['function'] ), (int) $entry['accepted_args'] );
					}
				}
			}
		}
		ksort( $registry );

		return $registry;
	}

	/**
	 * Rotte REST del plugin: rotta => handler (metodi ordinati, callback, permission_callback, nomi degli args).
	 * L'indice del namespace (`/health-check/v1`) e' aggiunto dal core, non dal plugin: escluso.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function route_registry() {
		$routes = array();
		foreach ( rest_get_server()->get_routes( 'health-check/v1' ) as $route => $handlers ) {
			if ( '/health-check/v1' === $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$methods = array_keys( $handler['methods'] );
				sort( $methods );
				$routes[ $route ][] = array(
					'methods'             => $methods,
					'callback'            => self::normalize_any( $handler['callback'] ),
					'permission_callback' => isset( $handler['permission_callback'] ) ? self::normalize_any( $handler['permission_callback'] ) : null,
					'args'                => array_keys( (array) $handler['args'] ),
				);
			}
		}
		ksort( $routes );

		return $routes;
	}

	/**
	 * Prepara il server REST (rest_api_init) come fa ogni richiesta.
	 */
	public function set_up() {
		parent::set_up();
		rest_get_server();
	}

	// -----------------------------------------------------------------------
	// Golden
	// -----------------------------------------------------------------------

	/**
	 * Fotografia di tutti i callback del plugin agganciati agli hook (priorita', nome 1.38.1, accepted_args, ordine)
	 * e delle rotte REST, confrontata con il golden SENZA maschere.
	 */
	public function test_hook_and_route_registry_matches_the_golden() {
		$snapshot = array(
			'hooks'  => self::hook_registry(),
			'routes' => self::route_registry(),
		);

		WPHC_Golden::assert_matches( $this, 'hooks/registry', $snapshot );
	}

	// -----------------------------------------------------------------------
	// Rotte REST
	// -----------------------------------------------------------------------

	/**
	 * 22 registrazioni `register_rest_route` su 20 percorsi distinti: `/update/bulk` e `/thumbnail` hanno due
	 * handler (GET e POST registrati separatamente). Ogni handler ha un permission_callback del plugin, tranne le tre
	 * rotte firmate dal centro (`/enroll`, `/rotate`, `/revoke`) che usano `__return_true` e controllano da se'.
	 */
	public function test_rest_routes_count_methods_and_permission_callbacks() {
		$routes   = self::route_registry();
		$handlers = 0;
		foreach ( $routes as $route_handlers ) {
			$handlers += count( $route_handlers );
		}
		$this->assertCount( 20, $routes, 'percorsi REST distinti del plugin' );
		$this->assertSame( 22, $handlers, 'handler registrati (register_rest_route)' );

		// Due handler sulle rotte con GET e POST separati.
		$this->assertCount( 2, $routes['/health-check/v1/update/bulk'] );
		$this->assertCount( 2, $routes['/health-check/v1/thumbnail'] );

		// Permission callback: wphc_require_token su 17 handler, __return_true sulle tre buste firmate, i due di debug.
		$permissions = array();
		foreach ( $routes as $route => $route_handlers ) {
			foreach ( $route_handlers as $handler ) {
				$permissions[ $handler['permission_callback'] ][] = $route;
			}
		}
		$this->assertCount( 17, $permissions['wphc_require_token'], 'handler con wphc_require_token' );
		$this->assertSame( array( '/health-check/v1/enroll', '/health-check/v1/revoke', '/health-check/v1/rotate' ), self::sorted( $permissions['__return_true'] ) );
		$this->assertSame( array( '/health-check/v1/autologin/token', '/health-check/v1/debug' ), self::sorted( $permissions['wphc_debug_permission'] ) );
		$this->assertCount( 3, $permissions, 'nessun altro permission_callback' );

		// Ogni handler ha UN metodo e le sole rotte di lettura sono GET.
		foreach ( $routes as $route_handlers ) {
			foreach ( $route_handlers as $handler ) {
				$this->assertCount( 1, $handler['methods'] );
				$this->assertSame( array(), $handler['args'], 'nessun args dichiarato' );
			}
		}
	}

	/**
	 * Ordina un elenco (copia).
	 *
	 * @param string[] $items Elementi.
	 * @return string[]
	 */
	private static function sorted( array $items ) {
		sort( $items );

		return $items;
	}

	// -----------------------------------------------------------------------
	// Ordine di esecuzione su init
	// -----------------------------------------------------------------------

	/**
	 * Quattro callback del plugin su `init`, tutti a priorita' 10, eseguiti nell'ordine di registrazione (righe
	 * della 1.38.1): 3963 schema del log, 4383 versione del core, 5962 ri-armo del bulk, 7410 autologin.
	 * L'ordine conta: la tabella di log deve esistere prima che la versione del core scriva, e l'autologin fa
	 * `exit` quindi deve restare l'ULTIMO. Spostare `boot()` su un altro hook cambierebbe l'ordine rispetto ai
	 * callback degli altri plugin.
	 */
	public function test_init_callbacks_keep_their_order() {
		$registry = self::hook_registry();

		$this->assertSame(
			array(
				array( 10, 'wphc_maybe_install_update_log_schema', 1 ), // Riga 3963.
				array( 10, 'wphc_maybe_log_core_version_change', 1 ),   // Riga 4383.
				array( 10, 'wphc_bulk_maybe_rearm', 1 ),                // Riga 5962.
				array( 10, 'wphc_maybe_consume_autologin', 1 ),         // Riga 7410.
			),
			$registry['init']
		);
	}

	// -----------------------------------------------------------------------
	// Hook dell'upgrader e dell'audit
	// -----------------------------------------------------------------------

	/**
	 * Hook dell'audit degli aggiornamenti: priorita' 10 e accepted_args (righe 4178-4341 della 1.38.1). Il numero di
	 * argomenti decide cosa vede il callback: senza `$extra` (upgrader_process_complete) o senza `$plugin` il log
	 * perderebbe i dati.
	 */
	public function test_upgrader_and_audit_hooks() {
		$registry = self::hook_registry();

		$expected = array(
			'upgrader_pre_install'      => array( array( 10, 'wphc_snapshot_version_before_upgrade', 2 ) ), // Riga 4178.
			'upgrader_process_complete' => array( array( 10, 'wphc_log_wp_initiated_update', 2 ) ),        // Riga 4263.
			'deactivated_plugin'        => array( array( 10, 'wphc_log_plugin_deactivation', 2 ) ),         // Riga 4298.
			'delete_plugin'             => array( array( 10, 'wphc_snapshot_plugin_before_delete', 1 ) ),   // Riga 4318.
			'deleted_plugin'            => array( array( 10, 'wphc_log_plugin_deletion', 2 ) ),             // Riga 4341.
		);
		foreach ( $expected as $hook => $entries ) {
			$this->assertSame( $entries, $registry[ $hook ], $hook );
		}
	}

	/**
	 * I 12 hook di flush della cache di /health sulle option `auto_update_core_{dev,minor,major}` (nome DINAMICO,
	 * riga 669-674 della 1.38.1) piu' gli 8 di plugin e temi (righe 661-668): ciascuno con un solo callback del
	 * plugin, priorita' 10 e un argomento.
	 */
	public function test_auto_update_flush_hooks() {
		$registry = self::hook_registry();

		foreach ( array( 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' ) as $option ) {
			foreach ( array( 'update_site_option_', 'add_site_option_', 'update_option_', 'add_option_' ) as $prefix ) {
				$this->assertSame( array( array( 10, 'wphc_flush_health_cache_only', 1 ) ), $registry[ $prefix . $option ], $prefix . $option );
			}
		}
		foreach ( array(
			'auto_update_plugins' => 'wphc_flush_plugins_detail_cache',
			'auto_update_themes'  => 'wphc_flush_theme_detail_cache',
		) as $option => $function_name ) {
			foreach ( array( 'update_site_option_', 'add_site_option_', 'update_option_', 'add_option_' ) as $prefix ) {
				$this->assertSame( array( array( 10, $function_name, 1 ) ), $registry[ $prefix . $option ], $prefix . $option );
			}
		}

		// Nessun altro hook auto_update_* del plugin oltre a questi 20.
		$flush_hooks = array_filter(
			array_keys( $registry ),
			static function ( $hook ) {
				return 1 === preg_match( '/^(?:update_site_option_|add_site_option_|update_option_|add_option_)auto_update_/', $hook );
			}
		);
		$this->assertCount( 20, $flush_hooks );
	}

	// -----------------------------------------------------------------------
	// WP-CLI (solo sorgente)
	// -----------------------------------------------------------------------

	/**
	 * La registrazione del comando e' nel sorgente del plugin ed e' protetta da `defined( 'WP_CLI' ) && WP_CLI`.
	 * E' l'unica forma osservabile in PHPUnit (WP_CLI non e' definita); l'effetto vero lo prova l'e2e con la
	 * WP-CLI reale (`tests/e2e/wpcli_test.py`, `wp help health-check`).
	 */
	public function test_wp_cli_command_registration_is_in_the_source() {
		$source = (string) file_get_contents( WPHC_PLUGIN_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertFalse( defined( 'WP_CLI' ), 'in PHPUnit WP_CLI non e\' definita: la registrazione non gira' );
		$this->assertSame( 1, preg_match_all( "/WP_CLI::add_command\(\s*'health-check'\s*,\s*'WPHC_CLI_Command'\s*\)/", $source ) );
		$this->assertMatchesRegularExpression( "/defined\(\s*'WP_CLI'\s*\)\s*&&\s*WP_CLI/", $source );
	}
}
