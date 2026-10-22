<?php
/**
 * Forma delle risposte confrontata con i golden del plugin (tests/fixtures/golden/): se un messaggio o un
 * campo del plugin cambia, qui il lite non resta allineato in silenzio.
 *
 * Per gli errori si confrontano status e corpo esatti (con <SITE_URL> sostituito); per le risposte che
 * dipendono dall'ambiente (ping, detail/server, enroll v2) status e insiemi di chiavi.
 *
 * @package WP_Health_Check_Lite
 */

final class GoldenShapeTest extends LiteTestCase {

	/** @return array Contenuto di un golden del plugin. */
	private function golden( string $name ): array {
		$path = dirname( __DIR__, 2 ) . '/tests/fixtures/golden/' . $name . '.json';

		return json_decode( str_replace( '<SITE_URL>', self::SITE, (string) file_get_contents( $path ) ), true );
	}

	/** Ordina le chiavi in modo ricorsivo (i golden sono salvati con le chiavi ordinate). */
	private static function sorted( array $data ): array {
		ksort( $data );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sorted( $value );
			}
		}

		return $data;
	}

	/** Verifica status e corpo esatti di una risposta contro il golden. */
	private function assert_matches_golden( string $name, array $response ): void {
		$golden = $this->golden( $name );

		$this->assertSame( $golden['status'], $response[0], $name );
		$this->assertSame( self::sorted( $golden['body'] ), self::sorted( $response[2] ), $name );
	}

	public function test_auth_not_enrolled(): void {
		$this->assert_matches_golden( 'auth/not-enrolled', $this->get( 'ping', 'x' ) );
	}

	public function test_auth_unauthorized(): void {
		$this->enroll_ok( 1, 'tok' );

		$this->assert_matches_golden( 'auth/unauthorized', $this->get( 'ping', 'sbagliato' ) );
	}

	public function test_auth_revoked(): void {
		$this->enroll_ok( 1, 'tok' );
		$this->request( 'POST', 'revoke', array(), $this->revoke_body() );

		$this->assert_matches_golden( 'auth/revoked', $this->get( 'ping', 'tok' ) );
	}

	public function test_auth_rate_limited(): void {
		$this->enroll_ok( 1, 'tok' );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->get( 'ping', 'sbagliato' );
		}

		$this->assert_matches_golden( 'auth/rate-limited', $this->get( 'ping', 'sbagliato' ) );
	}

	public function test_enroll_url_mismatch(): void {
		$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( 1, 'tok', array( 'site_url' => 'https://other.example' ) ) );

		$this->assert_matches_golden( 'enroll/url-mismatch', $response );
	}

	public function test_enroll_v2_success_has_the_golden_keys(): void {
		$golden   = $this->golden( 'enroll/v2-success' );
		$response = $this->enroll_ok( 2, 'tok-golden-v2' );

		$this->assertSame( $golden['status'], $response[0] );
		$this->assertSame( array_keys( self::sorted( $golden['body'] ) ), array_keys( self::sorted( $response[2] ) ) );
		$this->assertSame( self::SITE, $response[2]['site'] );
	}

	public function test_ping_has_the_golden_keys(): void {
		$golden = $this->golden( 'read/ping' );
		$this->enroll_ok( 1, 'tok' );
		$response = $this->get( 'ping', 'tok' );

		$this->assertSame( $golden['status'], $response[0] );
		$this->assertSame( array_keys( self::sorted( $golden['body'] ) ), array_keys( self::sorted( $response[2] ) ) );
		$this->assertSame( $golden['body']['status'], $response[2]['status'] );
	}

	public function test_detail_server_has_the_golden_keys(): void {
		$golden = $this->golden( 'read/detail-server' );
		$this->enroll_ok( 1, 'tok' );
		$response = $this->get( 'detail/server', 'tok' );

		$this->assertSame( $golden['status'], $response[0] );
		$this->assertSame( array_keys( self::sorted( $golden['body'] ) ), array_keys( self::sorted( $response[2] ) ) );
		$this->assertSame( array_keys( self::sorted( $golden['body']['server'] ) ), array_keys( self::sorted( $response[2]['server'] ) ) );
		$this->assertSame( $golden['body']['server']['https'], $response[2]['server']['https'] );
	}
}
