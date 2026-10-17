<?php
/**
 * Test di caratterizzazione di POST /enroll, /rotate e /revoke (plugin 1.38.1).
 *
 * Ogni test fissa il comportamento OSSERVABILE (status, codice d'errore, option scritte):
 * il refactoring in classi deve lasciarli identici. I test marcati "SCOMODO" fissano
 * comportamenti poco intuitivi che pero' vanno preservati (o cambiati solo con una
 * decisione esplicita, aggiornando test e README).
 *
 * @package WP_Health_Check
 */

/**
 * Enroll v1/v2, rotazione e revoca del segreto.
 */
class WPHC_Test_Enroll extends WPHC_Test_Case {

	// -----------------------------------------------------------------------
	// Utility locali
	// -----------------------------------------------------------------------

	/**
	 * Numero di righe nella tabella di log update per tipo/target (audit di rotate/revoke).
	 *
	 * @param string $type   Tipo ("token").
	 * @param string $target Target ("rotation" | "revocation").
	 * @return int
	 */
	private function log_rows( $type, $target ) {
		global $wpdb;
		$table = self::call( 'wphc_update_log_table' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND target = %s AND phase = %s", $type, $target, 'completed' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * True se il nonce risulta registrato come gia' visto (transient wphc_nonce_<sha256>).
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	private function nonce_remembered( $nonce ) {
		return false !== get_transient( 'wphc_nonce_' . hash( 'sha256', $nonce ) );
	}

	// -----------------------------------------------------------------------
	// POST /enroll: successo
	// -----------------------------------------------------------------------

	/**
	 * Enroll v1 valido: 200, risposta con site/agent_version e option scritte.
	 */
	public function test_enroll_v1_success() {
		// Prima un tentativo con firma ERRATA: lascia l'errore diagnostico che un enroll riuscito deve cancellare.
		$bad = $this->envelope( 'enroll_v1', array( 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) );
		$this->assertSame( 401, $this->request( 'POST', '/enroll', $bad )->get_status() );
		$this->assertSame( 'wphc_enroll_unauthorized', get_option( 'wp_health_check_last_enroll_error' )['code'] );

		// Lo stato "prima" e' quello dopo il tentativo fallito (con l'errore diagnostico presente).
		$before = $this->plugin_options();
		$body   = $this->envelope(
			'enroll_v1',
			array(
				'token'            => 'tok-golden-v1',
				'dashboard_origin' => 'https://dashboard.example',
			)
		);

		$response = $this->request( 'POST', '/enroll', $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'enrolled'      => true,
				'site'          => $this->own_site_url(),
				'agent_version' => WP_HEALTH_CHECK_VERSION,
			),
			$response->get_data()
		);

		$this->assertSame( 'tok-golden-v1', get_option( 'wp_health_check_token' ) );
		$this->assertSame( $this->own_site_url(), get_option( 'wp_health_check_site_url' ) );
		$this->assertSame( 'https://dashboard.example', get_option( 'wp_health_check_dashboard_origin' ) );
		$this->assertSame( self::CLIENT_IP, get_option( 'wp_health_check_enrolled_ip' ) );
		$this->assertSame( 'k1', get_option( 'wp_health_check_secret_kid' ) );
		// Un enroll v1 NON scrive wp_health_check_protocol: il protocollo resta quello di default (1).
		$this->assertFalse( get_option( 'wp_health_check_protocol' ) );
		$this->assertSame( 1, (int) get_option( 'wp_health_check_protocol', 1 ) );
		// issued_at e' salvato cosi' come firmato (nel database e' una stringa: plugin_options() legge dal DB, non dalla cache).
		$this->assertSame( (string) $body['issued_at'], $this->plugin_options()['wp_health_check_enroll_issued_at'] );
		// L'enroll riuscito cancella l'errore diagnostico del tentativo fallito (il golden lo registra in options_deleted).
		$this->assertFalse( get_option( 'wp_health_check_last_enroll_error' ) );

		$this->assert_golden( 'enroll/v1-success', $response, $before );
	}

	/**
	 * Enroll v2 valido: porta il sito a protocollo 2 e registra il nonce.
	 */
	public function test_enroll_v2_success() {
		$before = $this->plugin_options();
		$body   = $this->envelope(
			'enroll_v2',
			array(
				'token' => 'tok-golden-v2',
				'nonce' => '00112233445566778899aabbccddeeff',
			)
		);

		$response = $this->request( 'POST', '/enroll', $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->own_site_url(), $response->get_data()['site'] );
		$this->assertSame( 'tok-golden-v2', get_option( 'wp_health_check_token' ) );
		$this->assertSame( 2, (int) get_option( 'wp_health_check_protocol' ) );
		$this->assertSame( (string) $body['issued_at'], $this->plugin_options()['wp_health_check_enroll_issued_at'] );
		// Il nonce e' registrato solo DOPO la verifica della firma.
		$this->assertTrue( $this->nonce_remembered( $body['nonce'] ) );

		$this->assert_golden( 'enroll/v2-success', $response, $before );
	}

	/**
	 * dashboard_origin e' l'unico campo opzionale: assente o null e' valido (origin vuota).
	 */
	public function test_enroll_dashboard_origin_is_optional() {
		$body = $this->envelope( 'enroll_v1', array(), 'k1', array( 'dashboard_origin' ) );
		$this->assertArrayNotHasKey( 'dashboard_origin', $body );

		$response = $this->request( 'POST', '/enroll', $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( get_option( 'wp_health_check_dashboard_origin' ) );
	}

	/**
	 * Enroll v1 ripetuto (stesso token): idempotente, nessun controllo anti-replay sul nonce.
	 */
	public function test_enroll_v1_replay_is_accepted_within_window() {
		$body = $this->envelope( 'enroll_v1' );

		$this->assertSame( 200, $this->request( 'POST', '/enroll', $body )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $body )->get_status() );
	}

	// -----------------------------------------------------------------------
	// POST /enroll: freschezza di issued_at
	// -----------------------------------------------------------------------

	/**
	 * La finestra e' +/-300 s sia per v1 sia per v2. Si prova a +/-290 (dentro) e +/-310
	 * (fuori) per non dipendere dal secondo esatto in cui scatta il confine.
	 */
	public function test_enroll_freshness_window() {
		foreach ( array( 'enroll_v1', 'enroll_v2' ) as $type ) {
			foreach ( array( -290, 290 ) as $offset ) {
				$this->reset_plugin_state();
				$response = $this->request( 'POST', '/enroll', $this->envelope( $type, array( 'issued_at' => time() + $offset ) ) );
				$this->assertSame( 200, $response->get_status(), "{$type} issued_at {$offset}s deve essere accettato" );
			}
			foreach ( array( -310, 310 ) as $offset ) {
				$this->reset_plugin_state();
				$response = $this->request( 'POST', '/enroll', $this->envelope( $type, array( 'issued_at' => time() + $offset ) ) );
				$this->assertSame( 401, $response->get_status(), "{$type} issued_at {$offset}s deve essere rifiutato" );
				$this->assertSame( 'wphc_enroll_stale', $this->error_code( $response ) );
				// La busta scaduta conta come fallimento di autenticazione e lascia traccia diagnostica.
				$this->assertSame( 1, $this->throttle_failures() );
				$this->assertSame( 'wphc_enroll_stale', get_option( 'wp_health_check_last_enroll_error' )['code'] );
				$this->assertFalse( get_option( 'wp_health_check_token' ) );
			}
		}
	}

	// -----------------------------------------------------------------------
	// POST /enroll: replay del nonce (v2)
	// -----------------------------------------------------------------------

	/**
	 * Una busta v2 e' single-use: il secondo invio dello stesso nonce e' un replay (401).
	 */
	public function test_enroll_v2_replay_is_rejected() {
		$body = $this->envelope( 'enroll_v2', array( 'nonce' => 'aabbccddeeff00112233445566778899' ) );

		$this->assertSame( 200, $this->request( 'POST', '/enroll', $body )->get_status() );

		$before   = $this->plugin_options();
		$response = $this->request( 'POST', '/enroll', $body );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wphc_enroll_replay', $this->error_code( $response ) );
		$this->assertSame( 1, $this->throttle_failures() );

		$this->assert_golden( 'enroll/v2-replay', $response, $before );
	}

	/**
	 * SCOMODO: il controllo del nonce precede la verifica della firma. Un replay con firma
	 * alterata risponde quindi "replay" (wphc_enroll_replay) e non "unauthorized".
	 */
	public function test_enroll_v2_nonce_is_checked_before_signature() {
		$body = $this->envelope( 'enroll_v2' );
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $body )->get_status() );

		$body['signature'] = base64_encode( str_repeat( 'x', 64 ) );
		$response          = $this->request( 'POST', '/enroll', $body );

		$this->assertSame( 'wphc_enroll_replay', $this->error_code( $response ) );
	}

	/**
	 * Un tentativo con firma NON valida non "brucia" il nonce: la stessa busta, firmata
	 * correttamente, viene poi accettata.
	 */
	public function test_enroll_v2_failed_signature_does_not_consume_the_nonce() {
		$nonce = self::new_nonce();

		$bad = $this->envelope( 'enroll_v2', array( 'nonce' => $nonce, 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) );
		$this->assertSame( 401, $this->request( 'POST', '/enroll', $bad )->get_status() );
		$this->assertFalse( $this->nonce_remembered( $nonce ) );

		$good = $this->envelope( 'enroll_v2', array( 'nonce' => $nonce ) );
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $good )->get_status() );
	}

	// -----------------------------------------------------------------------
	// POST /enroll: firma
	// -----------------------------------------------------------------------

	/**
	 * Firma errata: 401 con messaggio generico e nessuna option di enrollment scritta.
	 */
	public function test_enroll_bad_signature_is_unauthorized() {
		$before   = $this->plugin_options();
		$body     = $this->envelope( 'enroll_v1', array( 'token' => 'tok-golden-bad', 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) );
		$response = $this->request( 'POST', '/enroll', $body );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $response ) );
		$this->assertFalse( get_option( 'wp_health_check_token' ) );
		$this->assertSame( 1, $this->throttle_failures() );

		$this->assert_golden( 'enroll/unauthorized', $response, $before );
	}

	/**
	 * Un campo modificato dopo la firma (token) invalida la firma.
	 */
	public function test_enroll_tampered_field_is_unauthorized() {
		foreach ( array( 'enroll_v1', 'enroll_v2' ) as $type ) {
			$body          = $this->envelope( $type );
			$body['token'] = 'altro-token';

			$response = $this->request( 'POST', '/enroll', $body );

			$this->assertSame( 401, $response->get_status(), $type );
			$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $response ), $type );
		}
	}

	/**
	 * Separazione delle chiavi: la chiave delle release (k2) non puo' firmare un enroll,
	 * ne' dichiarando kid "k2" ne' firmando con k2 e dichiarando k1. Un kid sconosciuto e'
	 * rifiutato; "K1" maiuscolo e' accettato perche' sanitize_key() lo porta a "k1".
	 */
	public function test_enroll_kid_and_key_separation() {
		// Firmato con k2, kid k2 nel body.
		$body = $this->envelope( 'enroll_v2', array( 'kid' => 'k2' ), 'k2' );
		$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $this->request( 'POST', '/enroll', $body ) ) );

		// Firmato con k2 ma dichiarato k1 (default).
		$body = $this->envelope( 'enroll_v2', array(), 'k2' );
		$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $this->request( 'POST', '/enroll', $body ) ) );

		// kid sconosciuto.
		$body = $this->envelope( 'enroll_v2', array( 'kid' => 'k9' ) );
		$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $this->request( 'POST', '/enroll', $body ) ) );

		// SCOMODO: "K1" diventa "k1" (sanitize_key) ed e' accettato; il kid salvato e' quello normalizzato.
		$body     = $this->envelope( 'enroll_v1', array( 'kid' => 'K1' ) );
		$response = $this->request( 'POST', '/enroll', $body );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'k1', get_option( 'wp_health_check_secret_kid' ) );
	}

	// -----------------------------------------------------------------------
	// POST /enroll: downgrade
	// -----------------------------------------------------------------------

	/**
	 * Un sito gia' al protocollo 2 rifiuta le buste v1 con 409. SCOMODO: questo ramo NON
	 * incrementa il throttle (a differenza di tutti gli altri rifiuti) ma registra
	 * l'errore diagnostico. Il token del sito resta invariato.
	 */
	public function test_enroll_downgrade_is_rejected_with_409() {
		$v2_token = $this->enroll_via_rest( 2 );
		$before   = $this->plugin_options();

		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'token' => 'tok-golden-downgrade' ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wphc_enroll_downgrade', $this->error_code( $response ) );
		$this->assertSame( $v2_token, get_option( 'wp_health_check_token' ) );
		$this->assertSame( 2, (int) get_option( 'wp_health_check_protocol' ) );
		$this->assertSame( 0, $this->throttle_failures(), 'Il downgrade non incrementa il throttle' );
		$this->assertSame( 'wphc_enroll_downgrade', get_option( 'wp_health_check_last_enroll_error' )['code'] );

		$this->assert_golden( 'enroll/downgrade', $response, $before );
	}

	/**
	 * Il controllo di downgrade segue la firma: una busta v1 con firma errata su un sito v2
	 * risponde 401 (e non 409), cosi' lo stato del protocollo non si rivela a chi non firma.
	 */
	public function test_enroll_downgrade_check_comes_after_signature() {
		$this->enroll_via_rest( 2 );

		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $response ) );
	}

	// -----------------------------------------------------------------------
	// POST /enroll: URL
	// -----------------------------------------------------------------------

	/**
	 * URL firmato diverso da quelli del sito: 403 con data.expected / data.received.
	 * SCOMODO: non incrementa il throttle; invia un'email di alert (una all'ora).
	 */
	public function test_enroll_url_mismatch() {
		reset_phpmailer_instance();
		$before   = $this->plugin_options();
		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => 'https://other.example' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_enroll_url_mismatch', $this->error_code( $response ) );

		$data = $response->get_data();
		$this->assertSame( $this->own_site_url(), $data['data']['expected'] );
		$this->assertSame( 'https://other.example', $data['data']['received'] );
		$this->assertSame( 403, $data['data']['status'] );
		$this->assertStringContainsString( $this->own_site_url(), $data['message'] );
		$this->assertStringContainsString( 'https://other.example', $data['message'] );

		$this->assertFalse( get_option( 'wp_health_check_token' ), 'Nessun token scritto su mismatch' );
		$this->assertSame( 0, $this->throttle_failures(), 'Il mismatch non incrementa il throttle' );
		$this->assertSame( 'wphc_enroll_url_mismatch', get_option( 'wp_health_check_last_enroll_error' )['code'] );

		$this->assert_golden( 'enroll/url-mismatch', $response, $before );
	}

	/**
	 * L'alert via email per URL mismatch: destinatario di flotta, contenuto con URL ricevuto
	 * e atteso, al massimo una email all'ora (transient wphc_enroll_alert_sent).
	 */
	public function test_enroll_url_mismatch_sends_one_alert_per_hour() {
		reset_phpmailer_instance();

		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => 'https://other.example' ) ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( WP_HEALTH_CHECK_ALERT_EMAIL, $mailer->get_recipient( 'to' )->address );
		$this->assertStringContainsString( 'Enroll fallito (URL mismatch)', $mailer->get_sent()->subject );
		$this->assertStringContainsString( 'https://other.example', $mailer->get_sent()->body );
		$this->assertNotFalse( get_transient( 'wphc_enroll_alert_sent' ) );
		// Il rate limit dura UN'ORA (3600 s) dall'invio.
		$this->assertEqualsWithDelta( time() + 3600, (int) get_option( '_transient_timeout_wphc_enroll_alert_sent' ), 5 );

		// Secondo mismatch: nessuna nuova email.
		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => 'https://other.example' ) ) );
		$this->assertCount( 1, $mailer->mock_sent );
	}

	/**
	 * Lo schema fa parte dell'URL: "http://example.org" non corrisponde al sito servito in HTTPS.
	 */
	public function test_enroll_scheme_mismatch_is_rejected() {
		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => 'http://' . $this->site_hostport() ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_enroll_url_mismatch', $this->error_code( $response ) );
	}

	/**
	 * Varianti accettate (confronto tollerante) e relativo effetto: il plugin salva e
	 * restituisce ESATTAMENTE il site_url firmato (non quello normalizzato).
	 */
	public function test_enroll_url_variants_are_accepted_and_stored_verbatim() {
		$hostport = $this->site_hostport();
		$variants = array(
			'https://www.' . $hostport,          // variante www.
			'https://' . $hostport . '/',        // slash finale.
			strtoupper( 'https://' . $hostport ), // maiuscole.
			'https://www.' . $hostport . '/',    // entrambe.
			'https://' . $hostport . '/?x=1',    // query ignorata dalla normalizzazione.
		);

		foreach ( $variants as $variant ) {
			$this->reset_plugin_state();

			$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => $variant ) ) );

			$this->assertSame( 200, $response->get_status(), $variant );
			$this->assertSame( $variant, $response->get_data()['site'], $variant );
			$this->assertSame( $variant, get_option( 'wp_health_check_site_url' ), $variant );
		}
	}

	// -----------------------------------------------------------------------
	// POST /enroll: protocollo
	// -----------------------------------------------------------------------

	/**
	 * SCOMODO: qualunque protocol >= 2 passa per il ramo v2 (il numero e' firmato) ma il
	 * sito salva sempre "2" in wp_health_check_protocol.
	 */
	public function test_enroll_protocol_greater_than_two_is_stored_as_two() {
		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v2', array( 'protocol' => 3 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, (int) get_option( 'wp_health_check_protocol' ) );
	}

	/**
	 * SCOMODO: un nuovo enroll NON azzera lo stato di rotazione/revoca preesistente
	 * (token_prev, token_rotated_at); sovrascrive solo token, site_url, origin e metadati.
	 */
	public function test_enroll_does_not_clear_rotation_state() {
		update_option( 'wp_health_check_token_prev', 'vecchio', false );
		update_option( 'wp_health_check_token_rotated_at', time(), false );

		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v2' ) )->get_status() );

		$this->assertSame( 'vecchio', get_option( 'wp_health_check_token_prev' ) );
		$this->assertNotEmpty( get_option( 'wp_health_check_token_rotated_at' ) );
	}

	// -----------------------------------------------------------------------
	// POST /enroll: input non valido, HTTPS, throttle
	// -----------------------------------------------------------------------

	/**
	 * Body non JSON o non oggetto: 400 e fallimento di autenticazione conteggiato.
	 */
	public function test_enroll_invalid_body() {
		foreach ( array( '', '"stringa"', '123' ) as $raw ) {
			$this->reset_plugin_state();

			$response = $this->request( 'POST', '/enroll', $raw );

			$this->assertSame( 400, $response->get_status(), "body: {$raw}" );
			$this->assertSame( 'wphc_enroll_invalid_body', $this->error_code( $response ) );
			$this->assertSame( 1, $this->throttle_failures() );

			// Diagnostica: codice del rifiuto e nessun URL ricevuto (il body non e' un oggetto).
			$error = get_option( 'wp_health_check_last_enroll_error' );
			$this->assertSame( 'wphc_enroll_invalid_body', $error['code'], "body: {$raw}" );
			$this->assertSame( '', $error['received'], "body: {$raw}" );
		}
	}

	/**
	 * Body non-JSON. Con Content-Type JSON e' il core di WordPress a rifiutarlo (400
	 * rest_invalid_json) PRIMA del callback del plugin: il throttle non e' toccato. Con un
	 * altro Content-Type il body non viene interpretato dal core e risponde il plugin
	 * (400, codice proprio, fallimento conteggiato). Vale per enroll, rotate e revoke.
	 */
	public function test_non_json_body_core_versus_plugin_error() {
		$this->enroll_via_rest( 1 );

		foreach ( array(
			'/enroll' => 'wphc_enroll_invalid_body',
			'/rotate' => 'wphc_rotate_invalid_body',
			'/revoke' => 'wphc_revoke_invalid_body',
		) as $route => $plugin_code ) {
			$this->set_throttle_failures( 0 );
			$response = $this->request( 'POST', $route, 'non-json' );
			$this->assertSame( 400, $response->get_status(), $route );
			$this->assertSame( 'rest_invalid_json', $this->error_code( $response ), $route );
			$this->assertSame( 0, $this->throttle_failures(), $route );

			$response = $this->request( 'POST', $route, 'non-json', array( 'Content-Type' => 'text/plain' ) );
			$this->assertSame( 400, $response->get_status(), $route );
			$this->assertSame( $plugin_code, $this->error_code( $response ), $route );
			$this->assertSame( 1, $this->throttle_failures(), $route );
		}
	}

	/**
	 * Campi obbligatori: site_url, token, issued_at, signature (+ nonce solo per v2).
	 * Un valore null o stringa vuota vale "mancante".
	 */
	public function test_enroll_missing_required_fields() {
		$cases = array(
			array( 'enroll_v1', 'site_url' ),
			array( 'enroll_v1', 'token' ),
			array( 'enroll_v1', 'issued_at' ),
			array( 'enroll_v1', 'signature' ),
			array( 'enroll_v2', 'nonce' ),
			array( 'enroll_v2', 'token' ),
		);
		foreach ( $cases as $case ) {
			list( $type, $field ) = $case;
			$this->reset_plugin_state();

			$body     = $this->envelope( $type, array(), 'k1', array( $field ) );
			$response = $this->request( 'POST', '/enroll', $body );

			$this->assertSame( 400, $response->get_status(), "{$type} senza {$field}" );
			$this->assertSame( 'wphc_enroll_missing_field', $this->error_code( $response ) );
			$this->assertStringContainsString( $field, $response->get_data()['message'] );
			$this->assertSame( 1, $this->throttle_failures() );

			// Diagnostica: codice, campo mancante nel motivo e URL ricevuto (vuoto se e' proprio site_url a mancare).
			$error = get_option( 'wp_health_check_last_enroll_error' );
			$this->assertSame( 'wphc_enroll_missing_field', $error['code'], "{$type} senza {$field}" );
			$this->assertStringContainsString( $field, $error['reason'] );
			$this->assertSame( 'site_url' === $field ? '' : $this->own_site_url(), $error['received'], "{$type} senza {$field}" );
		}

		// Valore vuoto = mancante.
		$body            = $this->envelope( 'enroll_v1' );
		$body['token']   = '';
		$empty_response  = $this->request( 'POST', '/enroll', $body );
		$this->assertSame( 'wphc_enroll_missing_field', $this->error_code( $empty_response ) );
	}

	/**
	 * Il nonce non e' richiesto per una busta v1 (nessun campo "protocol").
	 */
	public function test_enroll_v1_does_not_require_nonce() {
		$body = $this->envelope( 'enroll_v1' );
		$this->assertArrayNotHasKey( 'nonce', $body );
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $body )->get_status() );
	}

	/**
	 * HTTPS obbligatorio: senza HTTPS 403. Con wp_health_check_trust_proxy attivo basta che
	 * il valore piu' a destra di X-Forwarded-Proto sia "https".
	 */
	public function test_enroll_requires_https() {
		unset( $_SERVER['HTTPS'] );

		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_https_required', $this->error_code( $response ) );

		// Header del proxy ignorato senza trust_proxy.
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assertSame( 'wphc_https_required', $this->error_code( $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) ) ) );

		// Con trust_proxy il valore piu' a destra decide.
		update_option( 'wp_health_check_trust_proxy', true );
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) )->get_status() );

		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
		$this->assertSame( 'wphc_https_required', $this->error_code( $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) ) ) );

		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http,https';
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) )->get_status() );
	}

	/**
	 * Throttle su /enroll: il controllo e' il PRIMO (prima dell'HTTPS); con 10 fallimenti gia'
	 * registrati anche una busta valida riceve 429.
	 */
	public function test_enroll_rate_limited_after_ten_failures() {
		$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS );

		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'wphc_rate_limited', $this->error_code( $response ) );
		$this->assertFalse( get_option( 'wp_health_check_token' ) );

		// Anche senza HTTPS la risposta e' 429: il throttle precede il controllo HTTPS.
		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 'wphc_rate_limited', $this->error_code( $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) ) ) );
	}

	/**
	 * Nove fallimenti non bastano: la decima richiesta valida passa (soglia a 10 fallimenti).
	 */
	public function test_enroll_below_threshold_is_not_limited() {
		$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS - 1 );

		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) )->get_status() );
		// SCOMODO: il successo non azzera il contatore.
		$this->assertSame( WP_HEALTH_CHECK_RATE_MAX_FAILS - 1, $this->throttle_failures() );
	}

	// -----------------------------------------------------------------------
	// POST /rotate
	// -----------------------------------------------------------------------

	/**
	 * Rotazione: sposta il token in prev, adotta il nuovo, porta a protocollo 2, registra il
	 * nonce e una riga di audit. La risposta riporta la finestra di grazia (900 s).
	 */
	public function test_rotate_success() {
		$old    = $this->enroll_via_rest( 1, array( 'token' => 'tok-old' ) );
		$before = $this->plugin_options();
		$body   = $this->envelope(
			'rotate',
			array(
				'new_token' => 'tok-golden-rotated',
				'nonce'     => '0123456789abcdef0123456789abcdef',
			)
		);

		$response = $this->request( 'POST', '/rotate', $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'rotated'       => true,
				'site'          => $this->own_site_url(),
				'protocol'      => 2,
				'grace_seconds' => 900,
			),
			$response->get_data()
		);
		$this->assertSame( 'tok-golden-rotated', get_option( 'wp_health_check_token' ) );
		$this->assertSame( $old, get_option( 'wp_health_check_token_prev' ) );
		$this->assertEqualsWithDelta( time(), (int) get_option( 'wp_health_check_token_rotated_at' ), 5 );
		$this->assertSame( 2, (int) get_option( 'wp_health_check_protocol' ) );
		$this->assertSame( 'k1', get_option( 'wp_health_check_secret_kid' ) );
		$this->assertTrue( $this->nonce_remembered( $body['nonce'] ) );
		$this->assertSame( 1, $this->log_rows( 'token', 'rotation' ) );

		$this->assert_golden( 'rotate/success', $response, $before );
	}

	/**
	 * Un sito non ancora enrolled non ruota: 503, senza throttle e senza consumare il nonce.
	 */
	public function test_rotate_requires_enrollment() {
		$body     = $this->envelope( 'rotate' );
		$response = $this->request( 'POST', '/rotate', $body );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wphc_not_enrolled', $this->error_code( $response ) );
		$this->assertSame( 0, $this->throttle_failures() );
		$this->assertFalse( $this->nonce_remembered( $body['nonce'] ) );
	}

	/**
	 * "protocol" assente nel body vale 2 (il valore firmato include comunque "2").
	 */
	public function test_rotate_protocol_defaults_to_two() {
		$this->enroll_via_rest( 1 );

		$response = $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array(), 'k1', array( 'protocol' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['protocol'] );
	}

	/**
	 * SCOMODO: la rotazione salva max(2, protocol), quindi un protocol 3 firmato resta 3
	 * (a differenza dell'enroll v2, che salva sempre 2).
	 */
	public function test_rotate_protocol_three_is_stored_as_three() {
		$this->enroll_via_rest( 1 );

		$response = $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'protocol' => 3 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['protocol'] );
		$this->assertSame( 3, (int) get_option( 'wp_health_check_protocol' ) );
	}

	/**
	 * Errori di /rotate: codice, status e conteggio sul throttle (solo i rifiuti di
	 * autenticazione contano; il mismatch URL no).
	 */
	public function test_rotate_errors() {
		$this->enroll_via_rest( 1 );
		$bad_signature = base64_encode( str_repeat( 'x', 64 ) );

		// Nessuna email di alert: l'alert esiste solo per il mismatch dell'URL di /enroll.
		reset_phpmailer_instance();

		$cases = array(
			'stale'        => array( $this->envelope( 'rotate', array( 'issued_at' => time() - 310 ) ), 401, 'wphc_rotate_stale', 1 ),
			'stale-future' => array( $this->envelope( 'rotate', array( 'issued_at' => time() + 310 ) ), 401, 'wphc_rotate_stale', 1 ),
			'unauthorized' => array( $this->envelope( 'rotate', array( 'signature' => $bad_signature ) ), 401, 'wphc_rotate_unauthorized', 1 ),
			'wrong-key'    => array( $this->envelope( 'rotate', array(), 'k2' ), 401, 'wphc_rotate_unauthorized', 1 ),
			'kid-k2'       => array( $this->envelope( 'rotate', array( 'kid' => 'k2' ), 'k2' ), 401, 'wphc_rotate_unauthorized', 1 ),
			'url-mismatch' => array( $this->envelope( 'rotate', array( 'site_url' => 'https://other.example' ) ), 403, 'wphc_rotate_url_mismatch', 0 ),
			'missing'      => array( $this->envelope( 'rotate', array(), 'k1', array( 'new_token' ) ), 400, 'wphc_rotate_missing_field', 1 ),
			'invalid-body' => array( '"stringa"', 400, 'wphc_rotate_invalid_body', 1 ),
		);

		foreach ( $cases as $label => $case ) {
			list( $body, $status, $code, $failures ) = $case;
			$this->set_throttle_failures( 0 );

			$response = $this->request( 'POST', '/rotate', $body );

			$this->assertSame( $status, $response->get_status(), $label );
			$this->assertSame( $code, $this->error_code( $response ), $label );
			$this->assertSame( $failures, $this->throttle_failures(), "{$label}: throttle" );
			$this->assertEmpty( get_option( 'wp_health_check_token_prev' ), "{$label}: nessuna rotazione applicata" );
			// I rifiuti di /rotate non scrivono l'errore diagnostico di /enroll ne' inviano email.
			$this->assertFalse( get_option( 'wp_health_check_last_enroll_error' ), "{$label}: last_enroll_error" );
			$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, "{$label}: nessuna email" );
			// Un rifiuto non brucia il nonce (si registra solo a firma e URL verificati).
			if ( is_array( $body ) ) {
				$this->assertFalse( $this->nonce_remembered( $body['nonce'] ), "{$label}: nonce non registrato" );
			}
		}
	}

	/**
	 * Replay: lo stesso nonce non ruota due volte.
	 */
	public function test_rotate_replay_is_rejected() {
		$this->enroll_via_rest( 1 );
		$body = $this->envelope( 'rotate' );

		$this->assertSame( 200, $this->request( 'POST', '/rotate', $body )->get_status() );
		$response = $this->request( 'POST', '/rotate', $body );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wphc_rotate_replay', $this->error_code( $response ) );
	}

	/**
	 * Finestra di grazia di 900 s: dopo la rotazione il vecchio token (con la firma HMAC
	 * calcolata con il vecchio segreto) resta valido; scaduta la finestra no. Al primo uso
	 * riuscito del token NUOVO il precedente decade subito, a prescindere dal tempo.
	 */
	public function test_rotate_grace_window() {
		$old = $this->enroll_via_rest( 2, array( 'token' => 'tok-old-v2' ) );
		$this->assertSame( 200, $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'new_token' => 'tok-new-v2' ) ) )->get_status() );

		// Dentro la finestra: il vecchio token e' accettato (protocollo 2: Bearer + HMAC del vecchio segreto).
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', $old, $old )->get_status() );
		// Il token prev e' ancora li' (non e' stato usato quello nuovo).
		$this->assertSame( $old, get_option( 'wp_health_check_token_prev' ) );
		// L'uso del segreto precedente e' tracciato nel log.
		$this->assertSame( 2, $this->log_rows( 'token', 'rotation' ), 'una riga per la rotazione e una per il grace_window_hit' );

		// Scaduta la finestra (901 s dalla rotazione): il vecchio token e' rifiutato.
		update_option( 'wp_health_check_token_rotated_at', time() - ( WP_HEALTH_CHECK_ROTATION_GRACE + 1 ), false );
		$this->assertSame( 401, $this->data_request( 'GET', '/ping', $old, $old )->get_status() );

		// Ripristino la finestra e uso il token NUOVO: il precedente decade subito.
		update_option( 'wp_health_check_token_rotated_at', time(), false );
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', 'tok-new-v2', 'tok-new-v2' )->get_status() );
		$this->assertFalse( get_option( 'wp_health_check_token_prev' ) );
		$this->assertFalse( get_option( 'wp_health_check_token_rotated_at' ) );
		$this->assertSame( 401, $this->data_request( 'GET', '/ping', $old, $old )->get_status() );
	}

	// -----------------------------------------------------------------------
	// POST /revoke
	// -----------------------------------------------------------------------

	/**
	 * Revoca: scrive revoked_at, cancella prev/rotated_at e i token di autologin pendenti,
	 * registra una riga di audit.
	 *
	 * DIFFERENZA CON IL README: il README dice che la revoca "cancella wp_health_check_token";
	 * il codice (1.38.1) NON lo cancella, lo lascia dov'e'. Il test fissa il codice.
	 */
	public function test_revoke_success() {
		$this->enroll_via_rest( 1, array( 'token' => 'tok-to-revoke' ) );
		update_option( 'wp_health_check_token_prev', 'prev-token', false );
		update_option( 'wp_health_check_token_rotated_at', time(), false );
		update_option( 'wp_health_check_autologin_pending', array( 'wphc_test_autologin_a', 'wphc_test_autologin_b' ), false );
		set_transient( 'wphc_test_autologin_a', 'x', 60 );
		set_transient( 'wphc_test_autologin_b', 'y', 60 );

		$before = $this->plugin_options();
		$body   = $this->envelope( 'revoke', array( 'nonce' => 'fedcba9876543210fedcba9876543210' ) );

		$response = $this->request( 'POST', '/revoke', $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'revoked' => true,
				'site'    => $this->own_site_url(),
			),
			$response->get_data()
		);
		$this->assertNotEmpty( get_option( 'wp_health_check_revoked_at' ) );
		$this->assertSame( 'tok-to-revoke', get_option( 'wp_health_check_token' ), 'Il token NON viene cancellato (il README dice il contrario)' );
		$this->assertFalse( get_option( 'wp_health_check_token_prev' ) );
		$this->assertFalse( get_option( 'wp_health_check_token_rotated_at' ) );
		$this->assertFalse( get_option( 'wp_health_check_autologin_pending' ) );
		$this->assertFalse( get_transient( 'wphc_test_autologin_a' ) );
		$this->assertFalse( get_transient( 'wphc_test_autologin_b' ) );
		$this->assertTrue( $this->nonce_remembered( $body['nonce'] ) );
		$this->assertSame( 1, $this->log_rows( 'token', 'revocation' ) );

		$this->assert_golden( 'revoke/success', $response, $before );
	}

	/**
	 * Dopo la revoca ogni rotta dati risponde 403 wphc_revoked, anche con token e firma giusti.
	 */
	public function test_state_after_revoke_data_routes_are_forbidden() {
		$token = $this->enroll_via_rest( 2 );
		$this->assertSame( 200, $this->data_request( 'GET', '/ping', $token, $token )->get_status() );

		$this->assertSame( 200, $this->request( 'POST', '/revoke', $this->envelope( 'revoke' ) )->get_status() );

		$before   = $this->plugin_options();
		$response = $this->data_request( 'GET', '/ping', $token, $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wphc_revoked', $this->error_code( $response ) );
		// SCOMODO: il rifiuto per revoca non incrementa il throttle.
		$this->assertSame( 0, $this->throttle_failures() );

		$this->assert_golden( 'revoke/state-after-revoke', $response, $before );
	}

	/**
	 * SCOMODO (da preservare): dopo /revoke un nuovo /enroll RIESCE (200) ma non cancella
	 * revoked_at, quindi le rotte dati restano 403 wphc_revoked. Sblocca solo un reset
	 * dell'enrollment (wp health-check reset / pulsante nella tab Site Health), che
	 * cancella wp_health_check_revoked_at.
	 */
	public function test_enroll_after_revoke_succeeds_but_data_routes_stay_forbidden() {
		$this->enroll_via_rest( 1 );
		$this->assertSame( 200, $this->request( 'POST', '/revoke', $this->envelope( 'revoke' ) )->get_status() );

		// Nuovo enroll v1 (il sito e' ancora al protocollo 1): riesce.
		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'token' => 'tok-after-revoke' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'tok-after-revoke', get_option( 'wp_health_check_token' ) );
		$this->assertNotEmpty( get_option( 'wp_health_check_revoked_at' ), 'enroll non azzera revoked_at' );

		// ...ma le rotte dati restano vietate.
		$data = $this->data_request( 'GET', '/ping', 'tok-after-revoke' );
		$this->assertSame( 403, $data->get_status() );
		$this->assertSame( 'wphc_revoked', $this->error_code( $data ) );

		// Anche un enroll v2 riesce senza sbloccare.
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v2', array( 'token' => 'tok-v2-after-revoke' ) ) )->get_status() );
		$this->assertSame( 'wphc_revoked', $this->error_code( $this->data_request( 'GET', '/ping', 'tok-v2-after-revoke', 'tok-v2-after-revoke' ) ) );

		// self::call( 'wphc_reset_enrollment' ) e' l'unico sblocco: cancella revoked_at.
		self::call( 'wphc_reset_enrollment' );
		$this->assertFalse( get_option( 'wp_health_check_revoked_at' ) );
		$this->assertSame( 'wphc_not_enrolled', $this->error_code( $this->data_request( 'GET', '/ping', 'tok-after-revoke' ) ) );
	}

	/**
	 * SCOMODO: la revoca lascia il token al suo posto, quindi /rotate (che richiede solo un
	 * token non vuoto) continua a funzionare su un sito revocato, senza sbloccarlo.
	 */
	public function test_rotate_after_revoke_is_accepted_but_site_stays_revoked() {
		$this->enroll_via_rest( 1 );
		$this->assertSame( 200, $this->request( 'POST', '/revoke', $this->envelope( 'revoke' ) )->get_status() );

		$response = $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'new_token' => 'tok-rot-after-revoke' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'wphc_revoked', $this->error_code( $this->data_request( 'GET', '/ping', 'tok-rot-after-revoke', 'tok-rot-after-revoke' ) ) );
	}

	/**
	 * Revoca ripetuta con un nonce nuovo: riesce di nuovo (nessun controllo "gia' revocato").
	 */
	public function test_revoke_twice_is_accepted() {
		$this->enroll_via_rest( 1 );

		$this->assertSame( 200, $this->request( 'POST', '/revoke', $this->envelope( 'revoke' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', '/revoke', $this->envelope( 'revoke' ) )->get_status() );
		$this->assertSame( 2, $this->log_rows( 'token', 'revocation' ) );
	}

	/**
	 * Un sito non enrolled non si revoca: 503.
	 */
	public function test_revoke_requires_enrollment() {
		$response = $this->request( 'POST', '/revoke', $this->envelope( 'revoke' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wphc_not_enrolled', $this->error_code( $response ) );
	}

	/**
	 * Errori di /revoke: stessa logica di /rotate.
	 */
	public function test_revoke_errors() {
		$this->enroll_via_rest( 1 );
		$bad_signature = base64_encode( str_repeat( 'x', 64 ) );

		reset_phpmailer_instance();

		$cases = array(
			'stale'        => array( $this->envelope( 'revoke', array( 'issued_at' => time() - 310 ) ), 401, 'wphc_revoke_stale', 1 ),
			'unauthorized' => array( $this->envelope( 'revoke', array( 'signature' => $bad_signature ) ), 401, 'wphc_revoke_unauthorized', 1 ),
			'wrong-key'    => array( $this->envelope( 'revoke', array(), 'k2' ), 401, 'wphc_revoke_unauthorized', 1 ),
			'url-mismatch' => array( $this->envelope( 'revoke', array( 'site_url' => 'https://other.example' ) ), 403, 'wphc_revoke_url_mismatch', 0 ),
			'missing'      => array( $this->envelope( 'revoke', array(), 'k1', array( 'nonce' ) ), 400, 'wphc_revoke_missing_field', 1 ),
			'invalid-body' => array( '"stringa"', 400, 'wphc_revoke_invalid_body', 1 ),
		);

		foreach ( $cases as $label => $case ) {
			list( $body, $status, $code, $failures ) = $case;
			$this->set_throttle_failures( 0 );

			$response = $this->request( 'POST', '/revoke', $body );

			$this->assertSame( $status, $response->get_status(), $label );
			$this->assertSame( $code, $this->error_code( $response ), $label );
			$this->assertSame( $failures, $this->throttle_failures(), "{$label}: throttle" );
			$this->assertFalse( get_option( 'wp_health_check_revoked_at' ), "{$label}: nessuna revoca applicata" );
			$this->assertFalse( get_option( 'wp_health_check_last_enroll_error' ), "{$label}: last_enroll_error" );
			$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, "{$label}: nessuna email" );
			if ( is_array( $body ) && isset( $body['nonce'] ) ) {
				$this->assertFalse( $this->nonce_remembered( $body['nonce'] ), "{$label}: nonce non registrato" );
			}
		}
	}

	/**
	 * Replay: lo stesso nonce non revoca due volte.
	 */
	public function test_revoke_replay_is_rejected() {
		$this->enroll_via_rest( 1 );
		$body = $this->envelope( 'revoke' );

		$this->assertSame( 200, $this->request( 'POST', '/revoke', $body )->get_status() );
		$response = $this->request( 'POST', '/revoke', $body );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wphc_revoke_replay', $this->error_code( $response ) );
	}

	/**
	 * Un nonce e' condiviso fra enroll, rotate e revoke: usarlo per un'operazione lo brucia
	 * anche per le altre (stesso store di transient).
	 */
	public function test_nonce_is_shared_across_operations() {
		$this->enroll_via_rest( 1 );
		$nonce = self::new_nonce();

		$this->assertSame( 200, $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'nonce' => $nonce ) ) )->get_status() );

		$response = $this->request( 'POST', '/revoke', $this->envelope( 'revoke', array( 'nonce' => $nonce ) ) );
		$this->assertSame( 'wphc_revoke_replay', $this->error_code( $response ) );
	}

	// -----------------------------------------------------------------------
	// HTTPS e throttle su rotate/revoke
	// -----------------------------------------------------------------------

	/**
	 * Rotate e revoke: HTTPS obbligatorio e throttle controllato per primo, come /enroll.
	 */
	public function test_rotate_and_revoke_require_https_and_respect_throttle() {
		$this->enroll_via_rest( 1 );

		foreach ( array(
			'/rotate' => 'rotate',
			'/revoke' => 'revoke',
		) as $route => $type ) {
			$this->set_throttle_failures( WP_HEALTH_CHECK_RATE_MAX_FAILS );
			$this->assertSame( 'wphc_rate_limited', $this->error_code( $this->request( 'POST', $route, $this->envelope( $type ) ) ), $route );

			$this->set_throttle_failures( 0 );
			$https = $_SERVER['HTTPS'];
			unset( $_SERVER['HTTPS'] );
			$this->assertSame( 'wphc_https_required', $this->error_code( $this->request( 'POST', $route, $this->envelope( $type ) ) ), $route );
			$_SERVER['HTTPS'] = $https;
		}
	}

	// -----------------------------------------------------------------------
	// Il nonce non si brucia con un rifiuto (rotate e revoke)
	// -----------------------------------------------------------------------

	/**
	 * /rotate e /revoke registrano il nonce SOLO dopo firma e URL verificati: un tentativo rifiutato
	 * (firma errata, URL non del sito) non lo brucia, e la STESSA busta firmata bene con lo stesso
	 * nonce viene poi accettata (come per /enroll v2, test_enroll_v2_failed_signature_does_not_consume_the_nonce).
	 * Ogni caso riparte da uno stato pulito perche' la busta valida cambia lo stato.
	 */
	public function test_rotate_and_revoke_rejections_do_not_burn_the_nonce() {
		$bad_signature = base64_encode( str_repeat( 'x', 64 ) );

		foreach ( array( 'rotate', 'revoke' ) as $type ) {
			foreach ( array(
				'unauthorized' => array( array( 'signature' => $bad_signature ), 401, "wphc_{$type}_unauthorized" ),
				'url-mismatch' => array( array( 'site_url' => 'https://other.example' ), 403, "wphc_{$type}_url_mismatch" ),
			) as $label => $case ) {
				list( $overrides, $status, $code ) = $case;
				$this->reset_plugin_state();
				$this->enroll_via_rest( 1 );
				$nonce = self::new_nonce();

				// Tentativo rifiutato.
				$response = $this->request( 'POST', "/{$type}", $this->envelope( $type, array_merge( $overrides, array( 'nonce' => $nonce ) ) ) );
				$this->assertSame( $status, $response->get_status(), "{$type} {$label}" );
				$this->assertSame( $code, $this->error_code( $response ), "{$type} {$label}" );
				$this->assertFalse( $this->nonce_remembered( $nonce ), "{$type} {$label}: il nonce non deve essere registrato" );

				// La busta firmata bene con lo STESSO nonce passa.
				$ok = $this->request( 'POST', "/{$type}", $this->envelope( $type, array( 'nonce' => $nonce ) ) );
				$this->assertSame( 200, $ok->get_status(), "{$type} {$label}: busta valida con lo stesso nonce" );
				$this->assertTrue( $this->nonce_remembered( $nonce ), "{$type} {$label}: ora il nonce e' registrato" );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Throttle: finestra rinnovata a ogni fallimento
	// -----------------------------------------------------------------------

	/**
	 * Il contatore del throttle vive 300 s e la finestra si RINNOVA a ogni nuovo fallimento
	 * (set_transient a ogni incremento): un client che sbaglia di continuo non esce mai dal blocco.
	 * Il timeout si retrodata a +60 s (ancora valido: un timeout passato farebbe scadere il contatore
	 * e il test passerebbe per il motivo sbagliato).
	 */
	public function test_throttle_window_is_five_minutes_and_renewed_on_every_failure() {
		$timeout_option = '_transient_timeout_wphc_fail_' . hash( 'sha1', self::CLIENT_IP );
		$bad            = base64_encode( str_repeat( 'x', 64 ) );

		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => $bad ) ) );
		$this->assertSame( 1, $this->throttle_failures() );
		// Contratto numerico indipendente dalla costante del plugin.
		$this->assertSame( 300, WP_HEALTH_CHECK_RATE_WINDOW );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( $timeout_option ), 5 );

		// Retrodata la scadenza (+60 s, ancora nel futuro) e fai fallire di nuovo: torna a ~+300 s.
		update_option( $timeout_option, time() + 60 );
		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => $bad ) ) );
		$this->assertSame( 2, $this->throttle_failures() );
		$this->assertEqualsWithDelta( time() + 300, (int) get_option( $timeout_option ), 5 );
	}

	/**
	 * Senza un IP valido (REMOTE_ADDR assente) il throttle non si disattiva: tutti i chiamanti
	 * "senza IP" condividono un unico contatore, indicizzato sull'hash dell'IP vuoto.
	 */
	public function test_throttle_with_unknown_ip_uses_a_shared_bucket() {
		unset( $_SERVER['REMOTE_ADDR'] );
		$bad = base64_encode( str_repeat( 'x', 64 ) );

		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => $bad ) ) );

		$this->assertSame( 1, (int) get_transient( 'wphc_fail_' . hash( 'sha1', '' ) ) );
		$this->assertSame( 0, $this->throttle_failures(), 'Il contatore dell\'IP di test non e\' toccato' );
		$this->assertSame( '', get_option( 'wp_health_check_last_enroll_error' )['ip'] );

		// Raggiunta la soglia nel contatore condiviso, anche una busta valida riceve 429.
		set_transient( 'wphc_fail_' . hash( 'sha1', '' ), WP_HEALTH_CHECK_RATE_MAX_FAILS, WP_HEALTH_CHECK_RATE_WINDOW );
		$this->assertSame( 'wphc_rate_limited', $this->error_code( $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) ) ) );
	}

	// -----------------------------------------------------------------------
	// IP del chiamante registrato (trust_proxy)
	// -----------------------------------------------------------------------

	/**
	 * Con wp_health_check_trust_proxy l'IP registrato in enrolled_ip (enroll riuscito) e in
	 * last_enroll_error.ip (enroll fallito) e' l'ULTIMO valido di X-Forwarded-For; senza trust_proxy
	 * l'header e' ignorato e vale REMOTE_ADDR.
	 */
	public function test_ip_recorded_with_trust_proxy_is_the_rightmost_forwarded_address() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 203.0.113.55';
		$bad                             = base64_encode( str_repeat( 'x', 64 ) );

		// Senza trust_proxy: REMOTE_ADDR.
		$this->assertSame( 401, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => $bad ) ) )->get_status() );
		$this->assertSame( self::CLIENT_IP, get_option( 'wp_health_check_last_enroll_error' )['ip'] );

		// Con trust_proxy: il valore piu' a destra, sia nell'errore sia nell'enroll riuscito.
		update_option( 'wp_health_check_trust_proxy', true );
		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => $bad ) ) );
		$this->assertSame( '203.0.113.55', get_option( 'wp_health_check_last_enroll_error' )['ip'] );

		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) )->get_status() );
		$this->assertSame( '203.0.113.55', get_option( 'wp_health_check_enrolled_ip' ) );
	}

	// -----------------------------------------------------------------------
	// Enroll: alert email, ordine dei controlli, dashboard_origin
	// -----------------------------------------------------------------------

	/**
	 * L'email di alert parte SOLO per l'URL mismatch: tutti gli altri rifiuti dell'enroll (firma,
	 * busta scaduta, replay, campo mancante, body non valido, downgrade) non inviano nulla e non
	 * impostano il rate limit dell'alert.
	 */
	public function test_other_enroll_rejections_send_no_alert_email() {
		reset_phpmailer_instance();
		$bad = base64_encode( str_repeat( 'x', 64 ) );

		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'signature' => $bad ) ) );
		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'issued_at' => time() - 400 ) ) );
		$this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array(), 'k1', array( 'token' ) ) );
		$this->request( 'POST', '/enroll', '"stringa"', array( 'Content-Type' => 'text/plain' ) );

		$v2 = $this->envelope( 'enroll_v2' );
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $v2 )->get_status() );
		$this->assertSame( 'wphc_enroll_replay', $this->error_code( $this->request( 'POST', '/enroll', $v2 ) ) );
		$this->assertSame( 'wphc_enroll_downgrade', $this->error_code( $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1' ) ) ) );

		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertFalse( get_transient( 'wphc_enroll_alert_sent' ) );
	}

	/**
	 * La freschezza di issued_at e' controllata PRIMA del nonce: una busta v2 scaduta con un nonce
	 * gia' visto risponde "stale", non "replay".
	 */
	public function test_enroll_v2_stale_is_checked_before_the_nonce() {
		$nonce = self::new_nonce();
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v2', array( 'nonce' => $nonce ) ) )->get_status() );
		$this->assertTrue( $this->nonce_remembered( $nonce ) );

		$stale = $this->envelope(
			'enroll_v2',
			array(
				'nonce'     => $nonce,
				'issued_at' => time() - 400,
			)
		);

		$this->assertSame( 'wphc_enroll_stale', $this->error_code( $this->request( 'POST', '/enroll', $stale ) ) );
	}

	/**
	 * Un enroll v2 con dashboard_origin valorizzato: l'origin e' firmata (modificarla dopo la firma
	 * invalida la busta) e viene salvata (non null) in wp_health_check_dashboard_origin.
	 */
	public function test_enroll_v2_with_dashboard_origin_is_signed_and_stored() {
		$tampered                     = $this->envelope( 'enroll_v2', array( 'dashboard_origin' => 'https://dashboard.example' ) );
		$tampered['dashboard_origin'] = 'https://evil.example';
		$this->assertSame( 'wphc_enroll_unauthorized', $this->error_code( $this->request( 'POST', '/enroll', $tampered ) ) );

		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v2', array( 'dashboard_origin' => 'https://dashboard.example' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://dashboard.example', get_option( 'wp_health_check_dashboard_origin' ) );
	}

	// -----------------------------------------------------------------------
	// Candidati URL: home_url filtrata (stile WPML) e variante www
	// -----------------------------------------------------------------------

	/**
	 * Con home_url() che porta un prefisso di lingua (stile WPML, "/en") l'URL canonico della
	 * home cambia ma site_url() no: una busta firmata con wphc_normalize_url( site_url() ) e'
	 * accettata (e' nell'insieme dei candidati) per enroll, rotate e revoke; un URL estraneo e'
	 * rifiutato e /enroll riporta in data.expected la home normalizzata CON il prefisso.
	 *
	 * Limite dichiarato: il multisite non e' coperto (network_home_url()/network_site_url() sono
	 * nell'insieme dei candidati ma qui il sito e' singolo): vedi tests/README.md.
	 */
	public function test_candidate_urls_with_a_language_prefixed_home_url() {
		add_filter(
			'home_url',
			static function ( $url ) {
				return $url . '/en';
			}
		);
		$canonical_home = 'https://' . WP_TESTS_DOMAIN . '/en';
		$base_site      = self::call( 'wphc_normalize_url', site_url() );

		// Il sito di test ha un solo dominio: home con /en, site_url senza.
		$this->assertSame( $canonical_home, self::call( 'wphc_normalize_site_url' ) );
		$this->assertSame( 'https://' . WP_TESTS_DOMAIN, $base_site );

		// Enroll firmato con l'URL base (site_url): accettato.
		$response = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => $base_site ) ) );
		$this->assertSame( 200, $response->get_status() );
		// Anche la variante con il prefisso (home) e' un candidato.
		$this->assertSame( 200, $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => $canonical_home ) ) )->get_status() );

		// Mismatch: data.expected e' la home normalizzata con /en.
		$mismatch = $this->request( 'POST', '/enroll', $this->envelope( 'enroll_v1', array( 'site_url' => 'https://other.example' ) ) );
		$this->assertSame( 403, $mismatch->get_status() );
		$this->assertSame( $canonical_home, $mismatch->get_data()['data']['expected'] );

		// Rotate e revoke: stessi candidati (nel mismatch non c'e' "expected").
		$this->assertSame( 200, $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'site_url' => $base_site ) ) )->get_status() );
		$this->assertSame( 'wphc_rotate_url_mismatch', $this->error_code( $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'site_url' => 'https://other.example' ) ) ) ) );
		$this->assertSame( 'wphc_revoke_url_mismatch', $this->error_code( $this->request( 'POST', '/revoke', $this->envelope( 'revoke', array( 'site_url' => 'https://other.example' ) ) ) ) );
		$this->assertSame( 200, $this->request( 'POST', '/revoke', $this->envelope( 'revoke', array( 'site_url' => $base_site ) ) )->get_status() );
	}

	/**
	 * /rotate e /revoke con una variante www dell'URL rispondono con "site" IDENTICO all'URL
	 * firmato (non normalizzato): il golden sostituisce l'URL del sito con <SITE_URL> e nasconderebbe
	 * la differenza, per questo l'asserzione e' esplicita.
	 */
	public function test_rotate_and_revoke_echo_the_signed_site_url_verbatim() {
		$variant = 'https://www.' . $this->site_hostport() . '/';

		$this->enroll_via_rest( 1 );
		$rotate = $this->request( 'POST', '/rotate', $this->envelope( 'rotate', array( 'site_url' => $variant ) ) );
		$this->assertSame( 200, $rotate->get_status() );
		$this->assertSame( $variant, $rotate->get_data()['site'] );

		$revoke = $this->request( 'POST', '/revoke', $this->envelope( 'revoke', array( 'site_url' => $variant ) ) );
		$this->assertSame( 200, $revoke->get_status() );
		$this->assertSame( $variant, $revoke->get_data()['site'] );
	}

	// -----------------------------------------------------------------------
	// Reset dell'enrollment
	// -----------------------------------------------------------------------

	/**
	 * wphc_reset_enrollment() cancella ESATTAMENTE le 16 option di enrollment (compresa
	 * last_autologin) e il transient del lock di update, e preserva il resto: kill-switch degli
	 * update, configurazione del webhook, trust_proxy e la tabella di log.
	 */
	public function test_reset_enrollment_deletes_exactly_the_enrollment_state() {
		$deleted   = array(
			'wp_health_check_token',
			'wp_health_check_site_url',
			'wp_health_check_dashboard_origin',
			'wp_health_check_enrolled_at',
			'wp_health_check_enrolled_ip',
			'wp_health_check_enroll_issued_at',
			'wp_health_check_last_request_at',
			'wp_health_check_last_request_ip',
			'wp_health_check_last_enroll_error',
			'wp_health_check_token_prev',
			'wp_health_check_token_rotated_at',
			'wp_health_check_protocol',
			'wp_health_check_revoked_at',
			'wp_health_check_secret_kid',
			'wp_health_check_autologin_pending',
			'wp_health_check_last_autologin',
		);
		$preserved = array(
			'wp_health_check_updates_enabled' => '0',
			'wp_health_check_webhook_url'     => 'https://webhook.invalid/x',
			'wp_health_check_trust_proxy'     => '1',
		);
		foreach ( $deleted as $name ) {
			update_option( $name, 'valore', false );
		}
		foreach ( $preserved as $name => $value ) {
			update_option( $name, $value, false );
		}
		set_transient( 'wp_health_check_update_lock', 'x', 60 );
		self::call( 'wphc_log_update_row', 'corr-reset-test', 'token', 'rotation', 'Riga di log di prova', null, null, 'completed' );
		$log_before = $this->log_rows( 'token', 'rotation' );

		self::call( 'wphc_reset_enrollment' );

		foreach ( $deleted as $name ) {
			$this->assertFalse( get_option( $name ), "{$name} deve essere cancellata" );
		}
		foreach ( $preserved as $name => $value ) {
			$this->assertSame( $value, get_option( $name ), "{$name} deve sopravvivere al reset" );
		}
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ) );
		$this->assertSame( $log_before, $this->log_rows( 'token', 'rotation' ), 'La tabella di log non viene toccata' );
		$this->assertSame( 1, $log_before );
	}
}
