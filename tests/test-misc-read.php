<?php
/**
 * Test di caratterizzazione delle aree collegate alle rotte di lettura (plugin 1.38.1):
 *  - autologin: POST /autologin/token (permission manage_options) e consumo di ?wphc_autologin=
 *    su `init` (monouso, TTL di 20 s, tracking dei token pendenti, throttle, HTTPS);
 *  - GET /debug (permission manage_options);
 *  - tracciamento dell'ultimo login (user meta wphc_last_login e wphc_last_login_ip).
 *
 * Il consumo dell'autologin termina con wp_safe_redirect() + exit: per non uccidere PHPUnit i test
 * chiamano wphc_maybe_consume_autologin() direttamente (e' il callback registrato su `init`, che si
 * verifica a parte) e intercettano il redirect con il filtro `wp_redirect`, che lancia un'eccezione
 * prima dell'exit (WPHC_Read_Test_Case::catch_redirect()). Gli header (Set-Cookie, Location) non sono
 * osservabili in CLI: del cookie di sessione si verifica l'azione `set_auth_cookie` del core.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-read.php';

/**
 * Autologin.
 */
class WPHC_Test_Autologin extends WPHC_Read_Test_Case {

	// -----------------------------------------------------------------------
	// Utility locali
	// -----------------------------------------------------------------------

	/**
	 * Crea un amministratore e lo imposta come utente corrente.
	 *
	 * @param array $args Argomenti aggiuntivi di wp_insert_user.
	 * @return int ID dell'utente.
	 */
	private function login_as_admin( array $args = array() ) {
		$user_id = self::factory()->user->create(
			array_merge(
				array(
					'role'         => 'administrator',
					'user_login'   => 'wphc_al_admin',
					'display_name' => 'AL Admin',
				),
				$args
			)
		);
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Emette un token di autologin via REST e lo restituisce (e riporta l'utente corrente ad anonimo,
	 * come il browser che poi apre l'URL).
	 *
	 * @return string Token in chiaro.
	 */
	private function issue_token() {
		$response = $this->request( 'POST', '/autologin/token' );
		$this->assertSame( 200, $response->get_status() );
		parse_str( (string) wp_parse_url( $response->get_data()['autologin_url'], PHP_URL_QUERY ), $query );
		wp_set_current_user( 0 );

		return $query['wphc_autologin'];
	}

	/**
	 * Chiave del transient di un token.
	 *
	 * @param string $token Token in chiaro.
	 * @return string
	 */
	private function transient_key( $token ) {
		return 'wphc_autologin_' . hash( 'sha256', $token );
	}

	/**
	 * Simula la navigazione del browser su /?wphc_autologin=<token> eseguendo il callback di `init`.
	 *
	 * @param string $token Token.
	 * @return WPHC_Test_Redirect|null Redirect avvenuto.
	 */
	private function consume( $token ) {
		$_GET['wphc_autologin'] = $token;

		return $this->catch_redirect(
			static function () {
				call_user_func( self::cb( 'wphc_maybe_consume_autologin' ) );
			}
		);
	}

	// -----------------------------------------------------------------------
	// POST /autologin/token
	// -----------------------------------------------------------------------

	/**
	 * Emissione del token: URL sull'home con il token, scadenza di 20 s, utente = quello risolto da WordPress.
	 * Effetti: transient indicizzato dall'HASH del token (con user e correlation id), chiave aggiunta
	 * all'elenco dei pendenti, audit "ultimo autologin", riga di log type='token'. Non serve l'enroll.
	 */
	public function test_token_issue_and_side_effects() {
		$user_id = $this->login_as_admin();
		$before  = $this->plugin_options();

		$response = $this->request( 'POST', '/autologin/token' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'autologin_url', 'expires_in', 'user_id', 'user_login' ), array_keys( $data ) );
		$this->assertSame( WP_HEALTH_CHECK_AUTOLOGIN_TTL, $data['expires_in'] );
		$this->assertSame( 20, $data['expires_in'] );
		$this->assertSame( $user_id, $data['user_id'] );
		$this->assertSame( 'wphc_al_admin', $data['user_login'] );

		// URL: home + ?wphc_autologin=<64 esadecimali>.
		$prefix = home_url( '/' ) . '?wphc_autologin=';
		$this->assertSame( 0, strpos( $data['autologin_url'], $prefix ) );
		$token = substr( $data['autologin_url'], strlen( $prefix ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );

		// Transient indicizzato dall'hash SHA-256 (il token in chiaro non e' mai in tabella).
		$key = $this->transient_key( $token );
		$this->assertSame( array( 'user_id', 'correlation_id' ), array_keys( get_transient( $key ) ) );
		$this->assertSame( $user_id, get_transient( $key )['user_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', get_transient( $key )['correlation_id'] );
		$ttl = $this->transient_ttl( $key );
		$this->assertGreaterThan( 15, $ttl );
		$this->assertLessThanOrEqual( 20, $ttl );

		// Elenco dei pendenti: contiene la chiave (hash), non il token.
		$options = $this->plugin_options();
		$this->assertSame( array( $key ), $options['wp_health_check_autologin_pending'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $options ) );

		// Audit dell'ultimo autologin richiesto.
		$last = $options['wp_health_check_last_autologin'];
		$this->assertSame( array( 'user_id', 'login', 'at', 'ip' ), array_keys( $last ) );
		$this->assertSame( 'wphc_al_admin', $last['login'] );
		$this->assertSame( self::CLIENT_IP, $last['ip'] );

		// Riga di log type='token': target = login, name = display_name, correlation id del transient.
		$rows = $this->log_rows_by_type( 'token' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wphc_al_admin', $rows[0]['target'] );
		$this->assertSame( 'AL Admin', $rows[0]['name'] );
		$this->assertSame( 'completed', $rows[0]['phase'] );
		$this->assertSame( get_transient( $key )['correlation_id'], $rows[0]['correlation_id'] );
		$this->assertSame( self::CLIENT_IP, $rows[0]['ip'] );

		// La funzione CORS e' eseguita dal callback (non dal permission_callback).
		$this->assertSame( 1, $this->nocache_calls );

		$this->assert_read_golden(
			'read/autologin-token',
			$response,
			$before,
			array(),
			static function ( $snapshot ) {
				// Token, hash e ID utente sono casuali/volatili: si mascherano i soli valori.
				$snapshot['body']['autologin_url'] = preg_replace( '/wphc_autologin=[0-9a-f]{64}/', 'wphc_autologin=<TOKEN>', $snapshot['body']['autologin_url'] );
				$snapshot['body']['user_id']       = '<ID>';
				$snapshot['options_written']['wp_health_check_autologin_pending'] = array_map(
					static function ( $key ) {
						return preg_replace( '/[0-9a-f]{64}$/', '<HASH>', $key );
					},
					$snapshot['options_written']['wp_health_check_autologin_pending']
				);
				$snapshot['options_written']['wp_health_check_last_autologin']['user_id'] = '<ID>';

				return $snapshot;
			}
		);
	}

	/**
	 * La rotta richiede HTTPS: senza, 403 wphc_https_required e nessuna scrittura (ne' transient ne' option).
	 * Il permission_callback (manage_options) passa comunque per primo.
	 */
	public function test_token_requires_https() {
		$this->login_as_admin();
		unset( $_SERVER['HTTPS'] );

		$response = $this->request( 'POST', '/autologin/token' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_https_required', $this->error_code( $response ) );
		$this->assertArrayNotHasKey( 'wp_health_check_autologin_pending', $this->plugin_options() );
		$this->assertSame( array(), $this->log_rows_by_type( 'token' ) );
	}

	/**
	 * Tracking dei pendenti: ogni emissione aggiunge la chiave. PRESERVATO: l'elenco e' tagliato a 20
	 * (le piu' vecchie escono) ma i transient dei token tagliati restano validi fino alla loro scadenza:
	 * un /revoke non li invaliderebbe piu'.
	 */
	public function test_pending_list_is_capped_at_twenty() {
		$this->login_as_admin();

		$tokens = array();
		for ( $i = 0; $i < 22; $i++ ) {
			$response = $this->request( 'POST', '/autologin/token' );
			parse_str( (string) wp_parse_url( $response->get_data()['autologin_url'], PHP_URL_QUERY ), $query );
			$tokens[] = $query['wphc_autologin'];
		}

		$pending = get_option( 'wp_health_check_autologin_pending' );
		$this->assertCount( 20, $pending );
		$this->assertSame( $this->transient_key( $tokens[2] ), $pending[0], 'i primi due sono usciti dall\'elenco' );
		$this->assertSame( $this->transient_key( $tokens[21] ), $pending[19] );
		$this->assertNotContains( $this->transient_key( $tokens[0] ), $pending );
		$this->assertIsArray( get_transient( $this->transient_key( $tokens[0] ) ), 'il token tagliato e\' ancora valido' );
	}

	// -----------------------------------------------------------------------
	// Consumo di ?wphc_autologin=
	// -----------------------------------------------------------------------

	/**
	 * Il callback e' registrato su `init` con priorita' 10.
	 */
	public function test_consume_is_hooked_on_init() {
		$this->assert_hooked( 'init', self::cb( 'wphc_maybe_consume_autologin' ), 10, 1 );
	}

	/**
	 * Consumo riuscito: redirect a wp-admin, utente loggato con cookie di SESSIONE (remember = false:
	 * scadenza del cookie 0, durata di 2 giorni e non 14), token e voce pendente rimossi, azione `wp_login`
	 * eseguita (ultimo login registrato) e riga di log type='login' collegata alla riga 'token'.
	 */
	public function test_consume_success_logs_in_and_burns_the_token() {
		$user_id = $this->login_as_admin();
		$token   = $this->issue_token();
		$key     = $this->transient_key( $token );

		$cookie = array();
		add_action(
			'set_auth_cookie',
			static function ( $auth_cookie, $expire, $expiration, $cookie_user_id ) use ( &$cookie ) {
				unset( $auth_cookie );
				$cookie = array(
					'expire'     => $expire,
					'expiration' => $expiration,
					'user_id'    => $cookie_user_id,
				);
			},
			10,
			4
		);

		$redirect = $this->consume( $token );

		$this->assertInstanceOf( WPHC_Test_Redirect::class, $redirect );
		$this->assertSame( admin_url(), $redirect->location );
		$this->assertSame( 302, $redirect->http_status );
		$this->assertSame( $user_id, get_current_user_id() );

		// Cookie di sessione: nessuna scadenza del cookie (0), durata di 2 giorni.
		$this->assertSame( $user_id, $cookie['user_id'] );
		$this->assertSame( 0, $cookie['expire'] );
		$this->assertEqualsWithDelta( time() + 2 * DAY_IN_SECONDS, $cookie['expiration'], 10 );

		// Monouso: transient cancellato e pendente rimosso (elenco vuoto = option cancellata).
		$this->assertFalse( get_transient( $key ) );
		$this->assertFalse( get_option( 'wp_health_check_autologin_pending' ) );

		// wp_login e' stata eseguita: ultimo login dell'utente registrato.
		$this->assertEqualsWithDelta( time(), (int) get_user_meta( $user_id, 'wphc_last_login', true ), 5 );
		$this->assertSame( self::CLIENT_IP, get_user_meta( $user_id, 'wphc_last_login_ip', true ) );

		// Audit: riga 'login' completed con lo stesso correlation id della riga 'token'.
		$token_rows = $this->log_rows_by_type( 'token' );
		$login_rows = $this->log_rows_by_type( 'login' );
		$this->assertCount( 1, $login_rows );
		$this->assertSame( 'completed', $login_rows[0]['phase'] );
		$this->assertSame( 'wphc_al_admin', $login_rows[0]['target'] );
		$this->assertSame( 'AL Admin', $login_rows[0]['name'] );
		$this->assertSame( $token_rows[0]['correlation_id'], $login_rows[0]['correlation_id'] );
		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * Un secondo uso dello stesso token (replay) e' un fallimento SILENZIOSO: redirect al login, riga di log
	 * 'failed' con target = primi 16 caratteri dell'hash del token, un fallimento sul throttle.
	 */
	public function test_consume_replay_fails_to_login_page() {
		$this->login_as_admin();
		$token = $this->issue_token();
		$this->consume( $token );
		wp_set_current_user( 0 );

		$redirect = $this->consume( $token );

		$this->assertSame( wp_login_url(), $redirect->location );
		$this->assertSame( 0, get_current_user_id() );
		$rows = $this->log_rows_by_type( 'login' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'failed', $rows[1]['phase'] );
		$this->assertSame( substr( hash( 'sha256', $token ), 0, 16 ), $rows[1]['target'] );
		$this->assertSame( 'Token sconosciuto o scaduto', $rows[1]['name'] );
		$this->assertSame( 1, $this->throttle_failures() );
	}

	/**
	 * Token sconosciuto: redirect al login e ogni tentativo conta come fallimento (10 portano al blocco).
	 */
	public function test_consume_unknown_token_counts_toward_throttle() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$redirect = $this->consume( str_repeat( 'a', 64 ) );

			$this->assertSame( wp_login_url(), $redirect->location );
			$this->assertSame( $i, $this->throttle_failures() );
		}
		$this->assertCount( 3, $this->log_rows_by_type( 'login' ) );
	}

	/**
	 * Scadenza: un token oltre il TTL (transient scaduto) e' trattato come sconosciuto.
	 */
	public function test_consume_expired_token_fails() {
		$this->login_as_admin();
		$token = $this->issue_token();
		$key   = $this->transient_key( $token );
		// Il TTL e' di 20 secondi: porto la scadenza nel passato.
		update_option( '_transient_timeout_' . $key, time() - 1, false );

		$redirect = $this->consume( $token );

		$this->assertSame( wp_login_url(), $redirect->location );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 'failed', $this->log_rows_by_type( 'login' )[0]['phase'] );
		$this->assertSame( 1, $this->throttle_failures() );
	}

	/**
	 * Utente cancellato dopo l'emissione: redirect al login e riga 'failed' con target = user_id e il
	 * correlation id della riga 'token'. PRESERVATO: NON conta come fallimento del throttle (solo il token
	 * sconosciuto lo fa).
	 */
	public function test_consume_deleted_user_fails_without_throttle() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user_id = $this->login_as_admin( array( 'user_login' => 'wphc_al_deleted' ) );
		$token   = $this->issue_token();
		wp_delete_user( $user_id );

		$redirect = $this->consume( $token );

		$this->assertSame( wp_login_url(), $redirect->location );
		$rows = $this->log_rows_by_type( 'login' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['phase'] );
		$this->assertSame( (string) $user_id, $rows[0]['target'] );
		$this->assertSame( 'Utente non trovato', $rows[0]['name'] );
		$this->assertSame( $this->log_rows_by_type( 'token' )[0]['correlation_id'], $rows[0]['correlation_id'] );
		$this->assertSame( 0, $this->throttle_failures() );
	}

	/**
	 * Oltre la soglia del throttle si va al login SENZA leggere il token: non viene consumato (il transient
	 * resta) e non si scrive nessuna riga di log.
	 */
	public function test_consume_is_blocked_by_the_throttle_without_burning_the_token() {
		$this->login_as_admin();
		$token = $this->issue_token();
		$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS );

		$redirect = $this->consume( $token );

		$this->assertSame( wp_login_url(), $redirect->location );
		$this->assertIsArray( get_transient( $this->transient_key( $token ) ), 'il token non e\' stato consumato' );
		$this->assertSame( array(), $this->log_rows_by_type( 'login' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Senza HTTPS il token non viene nemmeno letto: redirect al login, token intatto, nessuna riga di log.
	 */
	public function test_consume_requires_https_without_burning_the_token() {
		$this->login_as_admin();
		$token = $this->issue_token();
		unset( $_SERVER['HTTPS'] );

		$redirect = $this->consume( $token );

		$this->assertSame( wp_login_url(), $redirect->location );
		$this->assertIsArray( get_transient( $this->transient_key( $token ) ) );
		$this->assertSame( array(), $this->log_rows_by_type( 'login' ) );
	}

	/**
	 * Parametro assente o "vuoto" per PHP (stringa vuota, "0"): il callback non fa nulla (nessun redirect).
	 */
	public function test_consume_ignores_empty_parameter() {
		foreach ( array( '', '0' ) as $value ) {
			$redirect = $this->consume( $value );

			$this->assertNull( $redirect, var_export( $value, true ) );
		}
		unset( $_GET['wphc_autologin'] );
		$redirect = $this->catch_redirect(
			static function () {
				call_user_func( self::cb( 'wphc_maybe_consume_autologin' ) );
			}
		);
		$this->assertNull( $redirect );
		$this->assertSame( array(), $this->log_rows_by_type( 'login' ) );
	}
}

/**
 * Rotta diagnostica GET /debug.
 */
class WPHC_Test_Debug extends WPHC_Read_Test_Case {

	/**
	 * Prepara lo scenario: transient `update_plugins` memorizzato con due aggiornamenti (a/a.php e
	 * b/b.php), un plugin installato finto (a/a.php), un cortocircuito `pre_` che mente e un filtro di
	 * lettura che tiene solo il primo aggiornamento.
	 *
	 * @return array{pre:callable,read:callable} I due filtri aggiunti.
	 */
	private function seed_debug_scenario() {
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => 1700000000,
				'response'     => array(
					'a/a.php' => (object) array( 'new_version' => '2' ),
					'b/b.php' => (object) array( 'new_version' => '2' ),
				),
				'no_update'    => array(),
			)
		);
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'a/a.php' => array(
						'Name'    => 'A',
						'Version' => '1',
					),
				),
			),
			'plugins'
		);

		$pre  = static function () {
			return (object) array(
				'last_checked' => 1700000000,
				'response'     => array( 'pre/pre.php' => (object) array( 'new_version' => '9' ) ),
			);
		};
		$read = static function ( $value ) {
			if ( is_object( $value ) && ! empty( $value->response ) ) {
				$value->response = array_slice( $value->response, 0, 1, true );
			}

			return $value;
		};
		add_filter( 'pre_site_transient_update_plugins', $pre );
		add_filter( 'site_transient_update_plugins', $read, 12 );

		return array(
			'pre'  => $pre,
			'read' => $read,
		);
	}

	/**
	 * Permission: solo manage_options (autenticazione WordPress). Anonimo 401, utente senza capability 403.
	 */
	public function test_permission_is_manage_options() {
		$this->assertSame( 401, $this->request( 'GET', '/debug' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/debug' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, $this->request( 'GET', '/debug' )->get_status() );
	}

	/**
	 * Forma e valori della risposta: transient FILTRATO (con il cortocircuito `pre_`), GREZZO (senza i filtri
	 * di terze parti) e quello visto da /health (senza il solo `pre_`); sola lettura (nessuna option scritta);
	 * i filtri rimossi per la lettura grezza sono RIPRISTINATI. Non richiede HTTPS ne' l'enroll.
	 */
	public function test_debug_response_shape_and_values() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		unset( $_SERVER['HTTPS'] ); // PRESERVATO: /debug non controlla HTTPS.
		$filters = $this->seed_debug_scenario();
		$before  = $this->plugin_options();

		$response = $this->request( 'GET', '/debug' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'context', 'update_plugins_filtered', 'update_plugins_raw_stored', 'get_plugin_updates', 'health_plugins_updates', 'filters' ),
			array_keys( $data )
		);
		$this->assertSame(
			array( 'is_admin', 'rest_request', 'doing_cron', 'user_id', 'can_update_plugins', 'is_multisite', 'home_url', 'site_url' ),
			array_keys( $data['context'] )
		);
		$this->assertSame( $user_id, $data['context']['user_id'] );
		$this->assertTrue( $data['context']['can_update_plugins'] );
		$this->assertFalse( $data['context']['is_multisite'] );
		$this->assertSame( home_url(), $data['context']['home_url'] );
		$this->assertSame( site_url(), $data['context']['site_url'] );

		// Filtrato: e' il valore del cortocircuito.
		$this->assertSame(
			array(
				'exists'         => true,
				'last_checked'   => '2023-11-14T22:13:20+00:00',
				'response_count' => 1,
				'response_slugs' => array( 'pre/pre.php' ),
			),
			$data['update_plugins_filtered']
		);
		// Grezzo: quello memorizzato, senza filtri di lettura ne' cortocircuito.
		$this->assertSame(
			array(
				'response_count' => 2,
				'response_slugs' => array( 'a/a.php', 'b/b.php' ),
			),
			$data['update_plugins_raw_stored']
		);
		// get_plugin_updates() e /health: senza il solo `pre_` ma CON il filtro di lettura (1 aggiornamento).
		$this->assertSame(
			array(
				'count' => 1,
				'slugs' => array( 'a/a.php' ),
			),
			$data['get_plugin_updates']
		);
		$this->assertSame( 1, $data['health_plugins_updates'] );

		// Sola lettura e filtri ripristinati.
		$this->assertSame( $before, $this->plugin_options() );
		$this->assertSame( 10, has_filter( 'pre_site_transient_update_plugins', $filters['pre'] ) );
		$this->assertSame( 12, has_filter( 'site_transient_update_plugins', $filters['read'] ) );
		$this->assertSame( array( 'site_transient_update_plugins', 'pre_set_site_transient_update_plugins', 'pre_site_transient_update_plugins' ), array_keys( $data['filters'] ) );

		$this->assert_read_golden(
			'read/debug',
			$response,
			$before,
			array( 'body.context.is_admin', 'body.context.rest_request', 'body.context.doing_cron', 'body.context.user_id', 'body.filters' )
		);
	}

	/**
	 * L'elenco dei callback sui filtri indica "priorita': nome" per ogni tipo di callable: funzione (stringa),
	 * metodo statico (Classe::metodo), metodo d'istanza (Classe::metodo), Closure e oggetto invocabile
	 * ("sconosciuto"); le priorita' vengono in ordine crescente.
	 */
	public function test_debug_lists_hook_callbacks_by_type() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$hook = 'pre_set_site_transient_update_plugins';
		add_filter( $hook, 'wphc_read_test_passthrough', 11 );
		add_filter( $hook, array( 'WPHC_Read_Test_Case', 'static_passthrough' ), 12 );
		add_filter( $hook, array( $this, 'instance_passthrough' ), 13 );
		add_filter(
			$hook,
			static function ( $value ) {
				return $value;
			},
			14
		);
		add_filter(
			$hook,
			new class() {
				/**
				 * Oggetto invocabile.
				 *
				 * @param mixed $value Valore.
				 * @return mixed
				 */
				public function __invoke( $value ) {
					return $value;
				}
			},
			15
		);

		$listed = $this->request( 'GET', '/debug' )->get_data()['filters'][ $hook ];

		// Si confrontano solo le voci aggiunte dal test (il core o altri plugin potrebbero averne altre).
		$mine = array_values(
			array_filter(
				$listed,
				static function ( $entry ) {
					return (int) $entry >= 11 && (int) $entry <= 15;
				}
			)
		);
		$this->assertSame(
			array(
				'11: wphc_read_test_passthrough',
				'12: WPHC_Read_Test_Case::static_passthrough',
				'13: ' . get_class( $this ) . '::instance_passthrough',
				'14: Closure',
				'15: sconosciuto',
			),
			$mine
		);
	}
}

/**
 * Tracciamento dell'ultimo login (user meta).
 */
class WPHC_Test_Last_Login extends WPHC_Read_Test_Case {

	/**
	 * L'azione `wp_login` e' agganciata con priorita' 10 e due argomenti.
	 */
	public function test_hooked_on_wp_login() {
		// Priorita' 10 e DUE argomenti ($user_login, $user): senza il secondo il callback non vedrebbe l'utente.
		$this->assert_hooked( 'wp_login', self::cb( 'wphc_record_last_login' ), 10, 2 );
	}

	/**
	 * wp_login con l'utente: scrive wphc_last_login (timestamp Unix) e wphc_last_login_ip (IP del chiamante).
	 * Ogni nuovo login sovrascrive i valori precedenti.
	 */
	public function test_login_records_time_and_ip() {
		$user = self::factory()->user->create_and_get( array( 'user_login' => 'wphc_ll_user' ) );
		$this->assertSame( '', get_user_meta( $user->ID, 'wphc_last_login', true ) );

		do_action( 'wp_login', $user->user_login, $user );

		$this->assertEqualsWithDelta( time(), (int) get_user_meta( $user->ID, 'wphc_last_login', true ), 5 );
		$this->assertSame( self::CLIENT_IP, get_user_meta( $user->ID, 'wphc_last_login_ip', true ) );

		// Nuovo login da un altro IP: entrambi i valori si aggiornano.
		update_user_meta( $user->ID, 'wphc_last_login', 1000 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
		do_action( 'wp_login', $user->user_login, $user );
		$this->assertEqualsWithDelta( time(), (int) get_user_meta( $user->ID, 'wphc_last_login', true ), 5 );
		$this->assertSame( '198.51.100.30', get_user_meta( $user->ID, 'wphc_last_login_ip', true ) );
	}

	/**
	 * Con il solo login (senza l'oggetto utente) il plugin risolve l'utente da solo; con un login
	 * sconosciuto non scrive nulla e non da' errori.
	 */
	public function test_login_without_user_object_resolves_by_login() {
		$user = self::factory()->user->create_and_get( array( 'user_login' => 'wphc_ll_by_login' ) );

		do_action( 'wp_login', 'wphc_ll_by_login' );
		$this->assertEqualsWithDelta( time(), (int) get_user_meta( $user->ID, 'wphc_last_login', true ), 5 );

		// Secondo argomento non WP_User: stesso ripiego.
		update_user_meta( $user->ID, 'wphc_last_login', 1000 );
		do_action( 'wp_login', 'wphc_ll_by_login', 'non-un-utente' );
		$this->assertEqualsWithDelta( time(), (int) get_user_meta( $user->ID, 'wphc_last_login', true ), 5 );

		// Login inesistente: nessuna scrittura per nessuno.
		global $wpdb;
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'wphc_last_login'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		do_action( 'wp_login', 'wphc_ll_inesistente' );
		$this->assertSame( $count_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'wphc_last_login'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * PRESERVATO: se l'IP del chiamante non e' determinabile (REMOTE_ADDR assente o non valido) la data si
	 * aggiorna ma l'IP NON viene scritto: resta quello del login precedente (stantio).
	 */
	public function test_login_without_ip_keeps_previous_ip() {
		$user = self::factory()->user->create_and_get();
		update_user_meta( $user->ID, 'wphc_last_login', 1000 );
		update_user_meta( $user->ID, 'wphc_last_login_ip', '198.51.100.40' );
		unset( $_SERVER['REMOTE_ADDR'] );

		do_action( 'wp_login', $user->user_login, $user );

		$this->assertEqualsWithDelta( time(), (int) get_user_meta( $user->ID, 'wphc_last_login', true ), 5 );
		$this->assertSame( '198.51.100.40', get_user_meta( $user->ID, 'wphc_last_login_ip', true ) );
	}

	/**
	 * L'IP segue `wp_health_check_trust_proxy`: con il proxy fidato conta l'ULTIMO IP valido di X-Forwarded-For.
	 */
	public function test_login_ip_honours_trust_proxy() {
		$user = self::factory()->user->create_and_get();
		update_option( 'wp_health_check_trust_proxy', true );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 198.51.100.77';

		do_action( 'wp_login', $user->user_login, $user );

		$this->assertSame( '198.51.100.77', get_user_meta( $user->ID, 'wphc_last_login_ip', true ) );
	}
}
