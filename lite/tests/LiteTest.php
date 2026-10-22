<?php
/**
 * Comportamento di WPHC Lite: enroll, rotate, revoke, rotte dati, firma v2, rate limit, storage.
 *
 * @package WP_Health_Check_Lite
 */

final class LiteTest extends LiteTestCase {

	// -----------------------------------------------------------------------
	// ENROLL
	// -----------------------------------------------------------------------

	public function test_enroll_v1_success_keeps_protocol_1(): void {
		$response = $this->enroll_ok( 1, 'tok-v1' );

		$this->assertSame( array( 'enrolled' => true, 'site' => self::SITE, 'agent_version' => 'lite-1.1.0' ), $response[2] );

		// Protocollo 1: il Bearer basta, la firma delle richieste non e' richiesta.
		$this->assertSame( 200, $this->get( 'ping', 'tok-v1' )[0] );
		$state = $this->read_state_file( 'state' );
		$this->assertArrayNotHasKey( 'protocol', $state );
		$this->assertSame( 'k1', $state['kid'] );
	}

	public function test_enroll_v2_success_requires_signed_requests(): void {
		$this->enroll_ok( 2, 'tok-v2' );

		$this->assertSame( 2, $this->read_state_file( 'state' )['protocol'] );
		$this->assertSame( 401, $this->get( 'ping', 'tok-v2' )[0], 'protocollo 2: senza firma e\' 401' );
		$this->assertSame( 200, $this->get( 'ping', 'tok-v2', true )[0] );
	}

	public function test_enroll_without_dashboard_origin_and_with_uppercase_kid(): void {
		$body     = $this->enroll_body( 2, 'tok', array( 'dashboard_origin' => '', 'kid' => 'K1' ) );
		$response = $this->request( 'POST', 'enroll', array(), $body );

		$this->assertSame( 200, $response[0], 'sanitize_key porta K1 a k1' );
	}

	public function test_enroll_accepts_www_variant_and_stores_the_received_url(): void {
		$lite     = $this->make_lite( array( 'site_urls' => array( 'https://www.example.org' ) ) );
		$response = $lite->handle( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'site_url' => 'https://example.org/' ) ), $this->server(), array() );

		$this->assertSame( 200, $response[0] );
		$this->assertSame( 'https://example.org/', $response[2]['site'], 'il site_url ricevuto e firmato, non normalizzato' );
	}

	/** @dataProvider provide_stale_protocols */
	public function test_enroll_stale( int $protocol ): void {
		foreach ( array( -301, 301 ) as $skew ) {
			$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( $protocol, 'tok', array( 'issued_at' => $this->now + $skew ) ) );

			$this->assertSame( 401, $response[0] );
			$this->assertSame( 'wphc_enroll_stale', $response[2]['code'] );
		}

		// Esattamente 300 secondi e' ancora dentro la finestra.
		$this->assertSame( 200, $this->request( 'POST', 'enroll', array(), $this->enroll_body( $protocol, 'tok', array( 'issued_at' => $this->now - 300 ) ) )[0] );
	}

	public function provide_stale_protocols(): array {
		return array( 'v1' => array( 1 ), 'v2' => array( 2 ) );
	}

	public function test_enroll_v2_replay(): void {
		$body = $this->enroll_body( 2, 'tok-v2' );

		$this->assertSame( 200, $this->request( 'POST', 'enroll', array(), $body )[0] );
		$replay = $this->request( 'POST', 'enroll', array(), $body );

		$this->assertSame( 401, $replay[0] );
		$this->assertSame( 'wphc_enroll_replay', $replay[2]['code'] );
	}

	public function test_enroll_nonce_is_forgotten_after_ttl(): void {
		$nonce = $this->nonce();
		$this->assertSame( 200, $this->request( 'POST', 'enroll', array(), $this->enroll_body( 2, 'tok', array( 'nonce' => $nonce ) ) )[0] );

		$this->now += 601;
		$this->assertSame( 200, $this->request( 'POST', 'enroll', array(), $this->enroll_body( 2, 'tok', array( 'nonce' => $nonce ) ) )[0] );
	}

	public function test_enroll_bad_signature(): void {
		foreach ( array( 1, 2 ) as $protocol ) {
			$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( $protocol, 'tok', array( 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) ) );

			$this->assertSame( 401, $response[0] );
			$this->assertSame( 'wphc_enroll_unauthorized', $response[2]['code'] );
		}

		// Firma non base64, di lunghezza errata, e kid diverso da k1 (k2 e' la chiave delle release).
		foreach ( array( '@@@', base64_encode( 'corta' ) ) as $bad ) {
			$this->assertSame( 401, $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'signature' => $bad ) ) )[0] );
		}
		$this->assertSame( 401, $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'kid' => 'k2' ) ) )[0] );
	}

	public function test_enroll_tampered_token_is_rejected(): void {
		$body          = json_decode( $this->enroll_body( 1, 'tok-vero' ), true );
		$body['token'] = 'tok-falso';

		$this->assertSame( 401, $this->request( 'POST', 'enroll', array(), (string) json_encode( $body ) )[0] );
	}

	public function test_enroll_downgrade_is_409_and_does_not_count_as_failure(): void {
		$this->enroll_ok( 2, 'tok-v2' );

		// Piu' di 10 tentativi: se contassero come fallimenti arriverebbe il 429.
		for ( $i = 0; $i < 12; $i++ ) {
			$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok-v1' ) );
			$this->assertSame( 409, $response[0] );
			$this->assertSame( 'wphc_enroll_downgrade', $response[2]['code'] );
		}
		$this->assertSame( 200, $this->get( 'ping', 'tok-v2', true )[0], 'il token v2 non e\' stato toccato' );
	}

	public function test_enroll_url_mismatch(): void {
		$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'site_url' => 'https://other.example' ) ) );

		$this->assertSame( 403, $response[0] );
		$this->assertSame( 'wphc_enroll_url_mismatch', $response[2]['code'] );
		$this->assertSame( array( 'status' => 403, 'expected' => self::SITE, 'received' => 'https://other.example' ), $response[2]['data'] );
		$this->assertSame( 'URL atteso: https://example.org — ricevuto: https://other.example', $response[2]['message'] );
	}

	public function test_enroll_missing_fields_and_invalid_body(): void {
		$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'omit' => array( 'token' ) ) ) );
		$this->assertSame( 400, $response[0] );
		$this->assertSame( 'wphc_enroll_missing_field', $response[2]['code'] );
		$this->assertSame( 'Campo obbligatorio mancante: token', $response[2]['message'] );

		// Il nonce e' obbligatorio solo dal protocollo 2.
		$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( 2, 'tok', array( 'omit' => array( 'nonce' ) ) ) );
		$this->assertSame( 400, $response[0] );
		$this->assertSame( 'Campo obbligatorio mancante: nonce', $response[2]['message'] );

		foreach ( array( '', 'non json', '"stringa"' ) as $body ) {
			$response = $this->request( 'POST', 'enroll', array(), $body );
			$this->assertSame( 400, $response[0] );
			$this->assertSame( 'wphc_enroll_invalid_body', $response[2]['code'] );
		}
	}

	public function test_enroll_requires_https_but_trust_proxy_accepts_forwarded_proto(): void {
		$body = $this->enroll_body( 1, 'tok' );

		$response = $this->request( 'POST', 'enroll', array(), $body, array( 'HTTPS' => 'off' ) );
		$this->assertSame( 403, $response[0] );
		$this->assertSame( 'wphc_https_required', $response[2]['code'] );

		// Senza trust_proxy l'header X-Forwarded-Proto non conta.
		$this->assertSame( 403, $this->request( 'POST', 'enroll', array(), $body, array( 'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https' ) )[0] );

		// Con trust_proxy conta il valore piu' a destra.
		$this->lite = $this->make_lite( array( 'trust_proxy' => true ) );
		$this->assertSame( 403, $this->request( 'POST', 'enroll', array(), $body, array( 'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https, http' ) )[0] );
		$this->assertSame( 200, $this->request( 'POST', 'enroll', array(), $body, array( 'HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'http, https' ) )[0] );
	}

	// -----------------------------------------------------------------------
	// ROUTING, SODIUM, STORAGE
	// -----------------------------------------------------------------------

	public function test_unknown_routes_and_wrong_methods_are_404(): void {
		foreach ( array( array( 'GET', 'plugins' ), array( 'GET', 'detail/plugins' ), array( 'POST', 'ping' ), array( 'GET', 'enroll' ), array( 'POST', 'update' ), array( 'GET', '' ), array( 'DELETE', 'health' ) ) as $case ) {
			$response = $this->request( $case[0], $case[1] );

			$this->assertSame( 404, $response[0], $case[0] . ' ' . $case[1] );
			$this->assertSame( 'rest_no_route', $response[2]['code'] );
			$this->assertSame( 404, $response[2]['data']['status'] );
		}

		// Slash iniziali e finali sono ignorati.
		$this->assertSame( 503, $this->request( 'GET', '/ping/' )[0] );
	}

	public function test_missing_sodium_is_503(): void {
		$lite     = $this->make_lite( array( 'sodium' => false ) );
		$response = $lite->handle( 'GET', 'ping', array(), '', $this->server(), array() );

		$this->assertSame( 503, $response[0] );
		$this->assertSame( 'wphc_lite_sodium_missing', $response[2]['code'] );
	}

	public function test_uncreatable_data_dir_is_503_storage(): void {
		// Un percorso "sotto" un file normale non si puo' creare (vale anche per root).
		$file = $this->data_dir . '-file';
		file_put_contents( $file, 'x' );
		$lite = $this->make_lite( array( 'data_dir' => $file . '/dati' ) );

		$response = $lite->handle( 'GET', 'ping', array(), '', $this->server(), array() );
		unlink( $file );

		$this->assertSame( 503, $response[0] );
		$this->assertSame( 'wphc_lite_storage', $response[2]['code'] );
	}

	public function test_data_dir_is_created_and_files_are_private(): void {
		$this->assertDirectoryDoesNotExist( $this->data_dir );
		$this->enroll_ok( 2, 'tok' );

		$this->assertDirectoryExists( $this->data_dir );
		foreach ( array( 'state.json', 'nonces.json', 'lock' ) as $file ) {
			$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->data_dir . '/' . $file ) ), -4 ), $file );
		}
		$this->assertSame( '0700', substr( sprintf( '%o', fileperms( $this->data_dir ) ), -4 ) );
		$this->assertSame( 'tok', $this->read_state_file( 'state' )['token'] );

		// I nonce si conservano come sha256, mai in chiaro (il primo nonce generato dal test e' 0...01).
		$nonces = (string) file_get_contents( $this->data_dir . '/nonces.json' );
		$this->assertStringNotContainsString( sprintf( '%032x', 1 ), $nonces );
		$this->assertStringContainsString( hash( 'sha256', sprintf( '%032x', 1 ) ), $nonces );
	}

	// -----------------------------------------------------------------------
	// AUTENTICAZIONE DELLE ROTTE DATI
	// -----------------------------------------------------------------------

	public function test_data_routes_not_enrolled_is_503(): void {
		foreach ( array( 'ping', 'health', 'detail/server' ) as $route ) {
			$response = $this->get( $route, 'qualunque' );

			$this->assertSame( 503, $response[0], $route );
			$this->assertSame( 'wphc_not_enrolled', $response[2]['code'] );
			$this->assertSame( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', $response[2]['message'] );
		}
		$this->assertSame( 503, $this->request( 'POST', 'rotate' )[0] );
		$this->assertSame( 503, $this->request( 'POST', 'revoke' )[0] );
	}

	public function test_data_routes_token_right_wrong_missing(): void {
		$this->enroll_ok( 1, 'tok-giusto' );

		foreach ( array( 'ping', 'health', 'detail/server' ) as $route ) {
			$this->assertSame( 200, $this->get( $route, 'tok-giusto' )[0], $route );

			foreach ( array( 'tok-sbagliato', null, '' ) as $token ) {
				$response = $this->get( $route, $token );
				$this->assertSame( 401, $response[0], $route );
				$this->assertSame( array( 'code' => 'wphc_unauthorized', 'message' => 'Non autorizzato.', 'data' => array( 'status' => 401 ) ), $response[2] );
			}
		}

		// Schema Bearer case-insensitive, header senza schema rifiutato.
		$this->assertSame( 200, $this->request( 'GET', 'ping', array( 'authorization' => 'bearer tok-giusto' ) )[0] );
		$this->assertSame( 401, $this->request( 'GET', 'ping', array( 'Authorization' => 'tok-giusto' ) )[0] );
	}

	public function test_data_routes_require_https_before_anything_else(): void {
		$response = $this->get( 'ping', 'tok', false, array(), null, array( 'HTTPS' => 'off' ) );

		$this->assertSame( 403, $response[0] );
		$this->assertSame( 'wphc_https_required', $response[2]['code'] );
	}

	public function test_revoke_then_data_routes_are_403_and_token_is_kept(): void {
		$this->enroll_ok( 1, 'tok' );
		$response = $this->request( 'POST', 'revoke', array(), $this->revoke_body() );

		$this->assertSame( 200, $response[0] );
		$this->assertSame( array( 'revoked' => true, 'site' => self::SITE ), $response[2] );

		foreach ( array( 'ping', 'health', 'detail/server' ) as $route ) {
			$response = $this->get( $route, 'tok' );
			$this->assertSame( 403, $response[0] );
			$this->assertSame( 'wphc_revoked', $response[2]['code'] );
			$this->assertSame( 'Segreto revocato dal sistema centrale.', $response[2]['message'] );
		}

		// Come nel plugin: il token non si cancella, si registra revoked_at.
		$state = $this->read_state_file( 'state' );
		$this->assertSame( 'tok', $state['token'] );
		$this->assertSame( gmdate( 'c', $this->now ), $state['revoked_at'] );
	}

	public function test_revoke_errors(): void {
		$this->enroll_ok( 1, 'tok' );

		$response = $this->request( 'POST', 'revoke', array(), $this->revoke_body( array( 'issued_at' => $this->now - 400 ) ) );
		$this->assertSame( array( 401, 'wphc_revoke_stale' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'revoke', array(), $this->revoke_body( array( 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) ) );
		$this->assertSame( array( 401, 'wphc_revoke_unauthorized' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'revoke', array(), $this->revoke_body( array( 'site_url' => 'https://other.example' ) ) );
		$this->assertSame( array( 403, 'wphc_revoke_url_mismatch' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'revoke', array(), 'x' );
		$this->assertSame( array( 400, 'wphc_revoke_invalid_body' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'revoke', array(), (string) json_encode( array( 'site_url' => self::SITE ) ) );
		$this->assertSame( array( 400, 'wphc_revoke_missing_field' ), array( $response[0], $response[2]['code'] ) );

		$body = $this->revoke_body();
		$this->assertSame( 200, $this->request( 'POST', 'revoke', array(), $body )[0] );
		$replay = $this->request( 'POST', 'revoke', array(), $body );
		$this->assertSame( array( 401, 'wphc_revoke_replay' ), array( $replay[0], $replay[2]['code'] ) );
	}

	// -----------------------------------------------------------------------
	// ROTATE E FINESTRA DI GRAZIA
	// -----------------------------------------------------------------------

	public function test_rotate_grace_window_and_close_on_first_use_of_new_token(): void {
		$this->enroll_ok( 2, 'tok-old' );
		$response = $this->request( 'POST', 'rotate', array(), $this->rotate_body( 'tok-new' ) );

		$this->assertSame( 200, $response[0] );
		$this->assertSame( array( 'rotated' => true, 'site' => self::SITE, 'protocol' => 2, 'grace_seconds' => 900 ), $response[2] );

		// Dentro la finestra il vecchio token funziona, firmato con il VECCHIO segreto.
		$this->now += 100;
		$this->assertSame( 200, $this->get( 'ping', 'tok-old', true )[0] );
		$this->assertSame( 401, $this->get( 'ping', 'tok-old', true, array(), 'tok-new' )[0], 'il segreto della firma e\' quello che ha fatto match' );

		// Il primo uso riuscito del nuovo chiude la finestra: il vecchio decade subito.
		$this->assertSame( 200, $this->get( 'ping', 'tok-new', true )[0] );
		$this->assertSame( 401, $this->get( 'ping', 'tok-old', true )[0] );

		$state = $this->read_state_file( 'state' );
		$this->assertArrayNotHasKey( 'token_prev', $state );
		$this->assertArrayNotHasKey( 'token_rotated_at', $state );
	}

	public function test_rotate_old_token_expires_after_grace_seconds(): void {
		$this->enroll_ok( 2, 'tok-old' );
		$this->request( 'POST', 'rotate', array(), $this->rotate_body( 'tok-new' ) );

		$this->now += 900;
		$this->assertSame( 200, $this->get( 'ping', 'tok-old', true )[0], 'a 900 s esatti e\' ancora valido' );

		$this->now += 1;
		$this->assertSame( 401, $this->get( 'ping', 'tok-old', true )[0] );
		$this->assertSame( 200, $this->get( 'ping', 'tok-new', true )[0] );
	}

	public function test_rotate_from_protocol_1_moves_to_protocol_2(): void {
		$this->enroll_ok( 1, 'tok-old' );
		$this->request( 'POST', 'rotate', array(), $this->rotate_body( 'tok-new' ) );

		$this->assertSame( 401, $this->get( 'ping', 'tok-new' )[0], 'dopo la rotazione serve la firma v2' );
		$this->assertSame( 200, $this->get( 'ping', 'tok-new', true )[0] );
		$this->assertSame( 'k1', $this->read_state_file( 'state' )['kid'] );
	}

	public function test_rotate_errors(): void {
		$this->enroll_ok( 2, 'tok' );

		$response = $this->request( 'POST', 'rotate', array(), $this->rotate_body( 'n', array( 'issued_at' => $this->now + 400 ) ) );
		$this->assertSame( array( 401, 'wphc_rotate_stale' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'rotate', array(), $this->rotate_body( 'n', array( 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) ) );
		$this->assertSame( array( 401, 'wphc_rotate_unauthorized' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'rotate', array(), $this->rotate_body( 'n', array( 'site_url' => 'https://other.example' ) ) );
		$this->assertSame( array( 403, 'wphc_rotate_url_mismatch' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'rotate', array(), 'x' );
		$this->assertSame( array( 400, 'wphc_rotate_invalid_body' ), array( $response[0], $response[2]['code'] ) );

		$response = $this->request( 'POST', 'rotate', array(), (string) json_encode( array( 'site_url' => self::SITE ) ) );
		$this->assertSame( array( 400, 'wphc_rotate_missing_field' ), array( $response[0], $response[2]['code'] ) );

		$body = $this->rotate_body( 'tok-2' );
		$this->assertSame( 200, $this->request( 'POST', 'rotate', array(), $body )[0] );
		$replay = $this->request( 'POST', 'rotate', array(), $body );
		$this->assertSame( array( 401, 'wphc_rotate_replay' ), array( $replay[0], $replay[2]['code'] ) );
	}

	// -----------------------------------------------------------------------
	// FIRMA V2 DELLE RICHIESTE
	// -----------------------------------------------------------------------

	public function test_request_signature_replay_skew_and_binding(): void {
		$this->enroll_ok( 2, 'tok' );

		// Stesso nonce due volte: la seconda e' un replay.
		$headers = array(
			'Authorization'    => 'Bearer tok',
			'X-WPHC-Timestamp' => (string) $this->now,
			'X-WPHC-Nonce'     => 'nonce-fisso',
			'X-WPHC-Signature' => WPHC_Lite::sign_request( 'tok', 'GET', '/health-check/v1/ping', '', (string) $this->now, 'nonce-fisso' ),
		);
		$this->assertSame( 200, $this->request( 'GET', 'ping', $headers )[0] );
		$this->assertSame( 401, $this->request( 'GET', 'ping', $headers )[0] );

		// Timestamp fuori finestra (anche se la firma e' coerente).
		$stale = $this->now - 301;
		$nonce = $this->nonce();
		$this->assertSame(
			401,
			$this->request(
				'GET',
				'ping',
				array(
					'Authorization'    => 'Bearer tok',
					'X-WPHC-Timestamp' => (string) $stale,
					'X-WPHC-Nonce'     => $nonce,
					'X-WPHC-Signature' => WPHC_Lite::sign_request( 'tok', 'GET', '/health-check/v1/ping', '', (string) $stale, $nonce ),
				)
			)[0]
		);

		// La firma e' legata alla rotta e al metodo: una firma per /ping non vale per /health.
		$nonce   = $this->nonce();
		$headers = array(
			'Authorization'    => 'Bearer tok',
			'X-WPHC-Timestamp' => (string) $this->now,
			'X-WPHC-Nonce'     => $nonce,
			'X-WPHC-Signature' => WPHC_Lite::sign_request( 'tok', 'GET', '/health-check/v1/ping', '', (string) $this->now, $nonce ),
		);
		$this->assertSame( 401, $this->request( 'GET', 'health', $headers )[0] );

		// Firma con un segreto diverso.
		$this->assertSame( 401, $this->get( 'ping', 'tok', true, array(), 'altro-segreto' )[0] );
	}

	public function test_signature_header_is_verified_even_on_protocol_1(): void {
		$this->enroll_ok( 1, 'tok' );

		$this->assertSame( 200, $this->get( 'ping', 'tok', true )[0] );
		$this->assertSame( 401, $this->get( 'ping', 'tok', true, array(), 'sbagliato' )[0] );
	}

	// -----------------------------------------------------------------------
	// RATE LIMIT
	// -----------------------------------------------------------------------

	public function test_rate_limit_429_after_10_failures_with_retry_after(): void {
		$this->enroll_ok( 1, 'tok' );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 401, $this->get( 'ping', 'sbagliato' )[0], 'fallimento ' . ( $i + 1 ) );
		}

		$response = $this->get( 'ping', 'sbagliato' );
		$this->assertSame( 429, $response[0] );
		$this->assertSame( 'wphc_rate_limited', $response[2]['code'] );
		$this->assertSame( "Troppi tentativi falliti: riprova piu' tardi.", $response[2]['message'] );
		$this->assertSame( array( 'Retry-After' => '300' ), $response[1] );

		// Anche il token giusto e le rotte di enroll sono bloccate (il throttle precede il Bearer).
		$this->assertSame( 429, $this->get( 'ping', 'tok' )[0] );
		$this->assertSame( 429, $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok' ) )[0] );
		$this->assertSame( 429, $this->request( 'POST', 'rotate', array(), $this->rotate_body( 'n' ) )[0] );
		$this->assertSame( 429, $this->request( 'POST', 'revoke', array(), $this->revoke_body() )[0] );

		// Un altro IP non e' toccato.
		$this->assertSame( 200, $this->get( 'ping', 'tok', false, array(), null, array( 'REMOTE_ADDR' => '198.18.0.7' ) )[0] );

		// Passata la finestra tutto riparte.
		$this->now += 301;
		$this->assertSame( 200, $this->get( 'ping', 'tok' )[0] );
	}

	public function test_rate_limit_window_is_renewed_by_each_failure(): void {
		$this->enroll_ok( 1, 'tok' );

		for ( $i = 0; $i < 9; $i++ ) {
			$this->get( 'ping', 'sbagliato' );
		}
		$this->now += 200;
		$this->assertSame( 401, $this->get( 'ping', 'sbagliato' )[0], 'decimo fallimento' );

		// 400 s dal primo fallimento ma solo 200 dall'ultimo: la finestra rinnovata blocca ancora.
		$this->now += 200;
		$this->assertSame( 429, $this->get( 'ping', 'tok' )[0] );

		$this->now += 101;
		$this->assertSame( 200, $this->get( 'ping', 'tok' )[0] );
	}

	public function test_rate_limit_uses_rightmost_forwarded_ip_only_with_trust_proxy(): void {
		$this->lite = $this->make_lite( array( 'trust_proxy' => true ) );
		$this->enroll_ok( 1, 'tok' );

		// Il client cambia il valore di sinistra a ogni richiesta, il proxy accoda sempre lo stesso IP: stesso contatore.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->get( 'ping', 'sbagliato', false, array(), null, array( 'HTTP_X_FORWARDED_FOR' => '10.0.0.' . $i . ', 198.18.0.9' ) );
		}
		$this->assertSame( 429, $this->get( 'ping', 'tok', false, array(), null, array( 'HTTP_X_FORWARDED_FOR' => '10.0.0.99, 198.18.0.9' ) )[0] );
	}

	public function test_no_double_evaluation_valid_signed_requests_never_count_as_failures(): void {
		// Regressione del finding del plugin: ogni richiesta valida v2 lascia il contatore a zero.
		$this->enroll_ok( 2, 'tok' );

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertSame( 200, $this->get( 'health', 'tok', true )[0], 'richiesta ' . ( $i + 1 ) );
			$this->assertSame( 200, $this->get( 'detail/server', 'tok', true )[0] );
			$this->assertSame( 200, $this->get( 'ping', 'tok', true )[0] );
		}

		$this->assertSame( array(), $this->read_state_file( 'rate' ) );
	}

	public function test_failed_enroll_signatures_count_toward_the_rate_limit(): void {
		// Un fallimento di enroll (firma errata) incrementa il contatore.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'signature' => base64_encode( str_repeat( 'x', 64 ) ) ) ) );
		}
		$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok' ) );

		$this->assertSame( 429, $response[0] );
	}

	// -----------------------------------------------------------------------
	// PING, HEALTH, DETAIL
	// -----------------------------------------------------------------------

	public function test_ping_shape_and_no_access_tracking(): void {
		$this->enroll_ok( 1, 'tok' );
		$response = $this->get( 'ping', 'tok' );

		$this->assertSame(
			array( 'status' => 'ok', 'site' => self::SITE, 'agent_version' => 'lite-1.1.0', 'generated_at' => gmdate( 'c', $this->now ) ),
			$response[2]
		);

		// /ping non e' un accesso ai fini dell'audit.
		$this->assertArrayNotHasKey( 'last_request_at', $this->read_state_file( 'state' ) );
	}

	public function test_health_shape_and_last_access(): void {
		$this->enroll_ok( 1, 'tok' );
		$first = $this->get( 'health', 'tok', false, array(), null, array( 'SERVER_ADDR' => '151.101.1.69' ) );

		$this->assertSame( 200, $first[0] );
		$this->assertSame( array( 'site', 'generated_at', 'fleet_agent_version', 'summary', 'last_access', 'detail_routes' ), array_keys( $first[2] ) );
		$this->assertSame( 'lite-1.1.0', $first[2]['fleet_agent_version'] );
		$this->assertSame(
			array( 'php_version', 'php_memory_limit', 'server_ip', 'server_ip_is_private', 'public_ip', 'plugin_version', 'thumbnail', 'thumbnail_error' ),
			array_keys( $first[2]['summary'] )
		);
		$this->assertSame( PHP_VERSION, $first[2]['summary']['php_version'] );
		$this->assertSame( '151.101.1.69', $first[2]['summary']['server_ip'] );
		$this->assertFalse( $first[2]['summary']['server_ip_is_private'] );
		$this->assertSame( '151.101.1.69', $first[2]['summary']['public_ip'] );
		$this->assertSame( 'lite-1.1.0', $first[2]['summary']['plugin_version'] );
		$this->assertSame( array( 'at' => null, 'ip' => null, 'enrolled_at' => gmdate( 'c', $this->now ) ), $first[2]['last_access'] );
		$this->assertSame( array( 'server' => self::SITE . '/wp-json/health-check/v1/detail/server' ), $first[2]['detail_routes'] );
		$this->assertArrayNotHasKey( 'last_status', $first[2]['summary'] );

		// Dopo la scadenza della cache l'accesso PRECEDENTE (il primo) compare in last_access.
		$this->now += 100;
		$second = $this->get( 'health', 'tok' );
		$this->assertSame( array( 'at' => gmdate( 'c', $this->now - 100 ), 'ip' => '203.0.113.10', 'enrolled_at' => gmdate( 'c', $this->now - 100 ) ), $second[2]['last_access'] );
	}

	public function test_health_cache_60_seconds_and_fresh(): void {
		$this->enroll_ok( 1, 'tok' );
		$first = $this->get( 'health', 'tok' )[2]['generated_at'];

		$this->now += 59;
		$this->assertSame( $first, $this->get( 'health', 'tok' )[2]['generated_at'], 'dentro i 60 s: dalla cache' );

		$this->assertSame( gmdate( 'c', $this->now ), $this->get( 'health', 'tok', false, array( 'fresh' => '1' ) )[2]['generated_at'], 'fresh=1 salta la cache' );

		$this->now += 61;
		$this->assertSame( gmdate( 'c', $this->now ), $this->get( 'health', 'tok' )[2]['generated_at'], 'dopo 60 s: ricalcolata' );
	}

	public function test_health_and_detail_track_access_even_from_cache(): void {
		$this->enroll_ok( 1, 'tok' );
		$this->get( 'health', 'tok' );

		$this->now += 5;
		$this->get( 'health', 'tok' );
		$this->assertSame( gmdate( 'c', $this->now ), $this->read_state_file( 'state' )['last_request_at'] );

		$this->now += 5;
		$this->get( 'detail/server', 'tok' );
		$this->assertSame( gmdate( 'c', $this->now ), $this->read_state_file( 'state' )['last_request_at'] );
	}

	public function test_detail_server_shape_and_cache_12_hours(): void {
		$this->enroll_ok( 1, 'tok' );
		$first = $this->get( 'detail/server', 'tok' );

		$this->assertSame( 200, $first[0] );
		$this->assertSame( array( 'site', 'generated_at', 'server' ), array_keys( $first[2] ) );
		$this->assertSame(
			array(
				'software', 'server_ip', 'server_ip_is_private', 'public_ip', 'php_version', 'php_sapi', 'php_memory_limit',
				'max_execution_time', 'max_input_vars', 'upload_max_filesize', 'post_max_size', 'mysql_version', 'https', 'extensions',
			),
			array_keys( $first[2]['server'] )
		);
		$this->assertSame( 'LiteSpeed', $first[2]['server']['software'] );
		$this->assertSame( '', $first[2]['server']['mysql_version'] );
		$this->assertTrue( $first[2]['server']['https'] );
		$this->assertSame( array( 'curl', 'imagick', 'gd', 'mbstring', 'intl' ), array_keys( $first[2]['server']['extensions'] ) );
		$this->assertSame( PHP_SAPI, $first[2]['server']['php_sapi'] );

		$this->now += 43199;
		$this->assertSame( $first[2]['generated_at'], $this->get( 'detail/server', 'tok' )[2]['generated_at'] );
		$this->assertSame( gmdate( 'c', $this->now ), $this->get( 'detail/server', 'tok', false, array( 'fresh' => '1' ) )[2]['generated_at'] );

		$this->now += 43201;
		$this->assertSame( gmdate( 'c', $this->now ), $this->get( 'detail/server', 'tok' )[2]['generated_at'] );
	}

	public function test_detail_server_with_private_server_ip_and_no_server_software(): void {
		$this->enroll_ok( 1, 'tok' );
		$response = $this->get( 'detail/server', 'tok', false, array(), null, array( 'SERVER_ADDR' => '192.168.1.10', 'SERVER_SOFTWARE' => '' ) );

		$this->assertTrue( $response[2]['server']['server_ip_is_private'] );
		$this->assertSame( '192.168.1.10', $response[2]['server']['server_ip'] );
		$this->assertNull( $response[2]['server']['public_ip'], 'ipify non risponde nel test' );
		$this->assertSame( '', $response[2]['server']['software'] );
	}

	// -----------------------------------------------------------------------
	// IP PUBBLICO
	// -----------------------------------------------------------------------

	public function test_public_ip_is_resolved_cached_for_7_days_and_retry_locked_for_1_day(): void {
		$this->enroll_ok( 1, 'tok' );
		$private = array( 'SERVER_ADDR' => '10.0.0.5' );

		// Risolto una volta e poi servito dalla cache (anche con fresh=1).
		$this->http_answer = '151.101.65.69';
		$this->assertSame( '151.101.65.69', $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );
		$this->assertSame( '151.101.65.69', $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );
		$this->assertSame( 1, $this->http_calls );

		// Scaduti i 7 giorni, si richiede di nuovo.
		$this->now        += 604801;
		$this->http_answer = '151.101.1.1';
		$this->assertSame( '151.101.1.1', $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );
		$this->assertSame( 2, $this->http_calls );
	}

	public function test_public_ip_failure_locks_retries_for_one_day(): void {
		$this->enroll_ok( 1, 'tok' );
		$private           = array( 'SERVER_ADDR' => '10.0.0.5' );
		$this->http_answer = null;

		$this->assertNull( $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );
		$this->assertNull( $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );
		$this->assertSame( 1, $this->http_calls, 'dopo un tentativo, lock di 1 giorno' );

		$this->now        += 86401;
		$this->http_answer = '151.101.1.1';
		$this->assertSame( '151.101.1.1', $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );

		// Una risposta non pubblica del servizio non si accetta.
		$this->now        += 604801;
		$this->http_answer = '192.168.0.1';
		$this->assertNull( $this->get( 'health', 'tok', false, array( 'fresh' => '1' ), null, $private )[2]['summary']['public_ip'] );
	}

	// -----------------------------------------------------------------------
	// CONFIGURAZIONE
	// -----------------------------------------------------------------------

	public function test_constructor_requires_site_urls_and_data_dir(): void {
		$this->expectException( InvalidArgumentException::class );
		new WPHC_Lite( array( 'data_dir' => '/tmp/x' ) );
	}

	public function test_constructor_requires_data_dir(): void {
		$this->expectException( InvalidArgumentException::class );
		new WPHC_Lite( array( 'site_urls' => array( self::SITE ) ) );
	}

	public function test_default_public_key_is_the_production_k1(): void {
		$this->assertSame( 'uXzoP9VTpihQ1ipNUMCwITN9wKmJV/VFuYxms5Tt0CE=', WPHC_Lite::DEFAULT_PUBKEY_K1 );
		$this->assertSame( '1.1.0', WPHC_LITE_VERSION );

		// Con la chiave di produzione una busta firmata con la chiave di test non passa.
		$lite     = new WPHC_Lite( array( 'site_urls' => array( self::SITE ), 'data_dir' => $this->data_dir, 'now' => function () {
			return $this->now;
		} ) );
		$response = $lite->handle( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok' ), $this->server(), array() );

		$this->assertSame( 401, $response[0] );
	}
}
