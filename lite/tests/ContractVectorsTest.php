<?php
/**
 * Vettori di contratto hub <-> agent (tests/fixtures/contract-vectors.json, calcolati dal codice dell'hub).
 *
 * Ed25519 e' deterministico: con il seed di test la firma calcolata qui coincide byte per byte con quella
 * dell'hub. I vettori hanno issued_at fisso 1700000000, quindi l'orologio dell'agent e' iniettato.
 *
 * @package WP_Health_Check_Lite
 */

final class ContractVectorsTest extends LiteTestCase {

	/** @param string $section Sezione dei vettori. */
	private static function cases( string $section ): array {
		$cases = array();
		foreach ( self::vectors()[ $section ] as $index => $vector ) {
			$cases[ $section . '#' . $index ] = array( $vector );
		}

		return $cases;
	}

	public function provide_normalize_url(): array {
		return self::cases( 'normalize_url' );
	}

	public function provide_enroll_v1(): array {
		return self::cases( 'enroll_v1' );
	}

	public function provide_enroll_v2(): array {
		return self::cases( 'enroll_v2' );
	}

	public function provide_request_hmac(): array {
		return self::cases( 'request_hmac' );
	}

	/**
	 * Per i casi in cui hub e plugin divergono (campo "diverge") fa fede il plugin, di cui l'agent replica il contratto.
	 *
	 * @dataProvider provide_normalize_url
	 */
	public function test_normalize_url_matches_the_plugin( array $vector ): void {
		$this->assertSame( $vector['plugin'], WPHC_Lite::normalize_url( $vector['input'] ), $vector['note'] ?? '' );
	}

	/** Le chiavi di test del bootstrap producono le firme dei vettori (verifica dell'harness stesso). */
	public function test_test_key_reproduces_hub_signatures(): void {
		$vectors = self::vectors();

		$this->assertSame( $vectors['rotate'][0]['signature'], $this->sign( $vectors['rotate'][0]['canonical'] ) );
		$this->assertSame( $vectors['revoke'][0]['signature'], $this->sign( $vectors['revoke'][0]['canonical'] ) );
	}

	/**
	 * La busta v1 del vettore e' accettata dall'agent (firma Ed25519 dell'hub), con il suo URL e la sua origin.
	 *
	 * @dataProvider provide_enroll_v1
	 */
	public function test_enroll_v1_vector_is_accepted( array $vector ): void {
		$this->assertSame(
			$vector['canonical'],
			WPHC_Lite::build_enroll_signing_payload( $vector['site_url'], $vector['token'], $vector['dashboard_origin'], $vector['issued_at'] )
		);

		$lite     = $this->make_lite( array( 'site_urls' => array( $vector['site_url'] ) ) );
		$body     = array(
			'site_url'         => $vector['site_url'],
			'token'            => $vector['token'],
			'dashboard_origin' => $vector['dashboard_origin'],
			'issued_at'        => $vector['issued_at'],
			'signature'        => $vector['signature'],
		);
		$response = $lite->handle( 'POST', 'enroll', array(), (string) json_encode( $body ), $this->server(), array() );

		$this->assertSame( 200, $response[0] );
		$this->assertSame( array( 'enrolled' => true, 'site' => $vector['site_url'], 'agent_version' => 'lite-1.0.0' ), $response[2] );
	}

	/**
	 * La busta v2 del vettore e' accettata.
	 *
	 * @dataProvider provide_enroll_v2
	 */
	public function test_enroll_v2_vector_is_accepted( array $vector ): void {
		$this->assertSame(
			$vector['canonical'],
			WPHC_Lite::build_signed_payload( 'enroll', array( $vector['site_url'], $vector['token'], $vector['dashboard_origin'], $vector['nonce'], (string) $vector['issued_at'], (string) $vector['protocol'] ) )
		);

		$body = array(
			'site_url'         => $vector['site_url'],
			'token'            => $vector['token'],
			'dashboard_origin' => $vector['dashboard_origin'],
			'nonce'            => $vector['nonce'],
			'issued_at'        => $vector['issued_at'],
			'protocol'         => $vector['protocol'],
			'signature'        => $vector['signature'],
		);
		$response = $this->request( 'POST', 'enroll', array(), (string) json_encode( $body ) );

		$this->assertSame( 200, $response[0] );
		$this->assertTrue( $response[2]['enrolled'] );
	}

	/** Il rotate del vettore e' accettato su un sito gia' enrollato (enroll v1: il nonce del vettore non e' ancora usato). */
	public function test_rotate_vector_is_accepted(): void {
		$vector = self::vectors()['rotate'][0];
		$this->enroll_ok( 1, 'vecchio-segreto' );

		$this->assertSame(
			$vector['canonical'],
			WPHC_Lite::build_signed_payload( 'rotate', array( $vector['site_url'], $vector['new_token'], $vector['nonce'], (string) $vector['issued_at'], (string) $vector['protocol'] ) )
		);

		$body     = array(
			'site_url'  => $vector['site_url'],
			'new_token' => $vector['new_token'],
			'nonce'     => $vector['nonce'],
			'issued_at' => $vector['issued_at'],
			'protocol'  => $vector['protocol'],
			'signature' => $vector['signature'],
		);
		$response = $this->request( 'POST', 'rotate', array(), (string) json_encode( $body ) );

		$this->assertSame( 200, $response[0] );
		$this->assertSame( array( 'rotated' => true, 'site' => self::SITE, 'protocol' => 2, 'grace_seconds' => 900 ), $response[2] );
	}

	/** Il revoke del vettore e' accettato: poi le rotte dati rispondono 403. */
	public function test_revoke_vector_is_accepted(): void {
		$vector = self::vectors()['revoke'][0];
		$this->enroll_ok( 1, 'segreto' );

		$this->assertSame(
			$vector['canonical'],
			WPHC_Lite::build_signed_payload( 'revoke', array( $vector['site_url'], $vector['nonce'], (string) $vector['issued_at'] ) )
		);

		$body     = array(
			'site_url'  => $vector['site_url'],
			'nonce'     => $vector['nonce'],
			'issued_at' => $vector['issued_at'],
			'signature' => $vector['signature'],
		);
		$response = $this->request( 'POST', 'revoke', array(), (string) json_encode( $body ) );

		$this->assertSame( 200, $response[0] );
		$this->assertSame( array( 'revoked' => true, 'site' => self::SITE ), $response[2] );
		$this->assertSame( 403, $this->get( 'ping', 'segreto' )[0] );
	}

	/**
	 * La firma HMAC di ogni vettore e' quella dell'hub (comprese rotte che il lite non serve: qui si prova solo la primitiva).
	 *
	 * @dataProvider provide_request_hmac
	 */
	public function test_request_hmac_signature_matches_hub( array $vector ): void {
		$this->assertSame(
			$vector['signature'],
			WPHC_Lite::sign_request( $vector['secret'], $vector['method'], $vector['route'], $vector['body'], $vector['timestamp'], $vector['nonce'] )
		);
	}

	/** Il vettore HMAC di /health passa da handle(): la firma dell'hub e' accettata dall'agent (ora fissa = timestamp). */
	public function test_request_hmac_health_vector_end_to_end(): void {
		$vector = self::vectors()['request_hmac'][0];
		$this->assertSame( '/health-check/v1/health', $vector['route'] );

		// Il token dell'enroll e' il segreto del vettore; la richiesta e' firmata dall'hub (header del vettore).
		$this->enroll_ok( 1, $vector['secret'] );
		$response = $this->request(
			'GET',
			'health',
			array(
				'Authorization'    => 'Bearer ' . $vector['secret'],
				'X-WPHC-Timestamp' => $vector['timestamp'],
				'X-WPHC-Nonce'     => $vector['nonce'],
				'X-WPHC-Signature' => $vector['signature'],
			)
		);

		$this->assertSame( 200, $response[0] );
		$this->assertSame( self::SITE, $response[2]['site'] );
	}
}
