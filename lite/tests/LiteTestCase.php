<?php
/**
 * Classe base dei test di WPHC Lite: cartella dati temporanea, orologio iniettato, firme del centro con le
 * chiavi di test dei vettori di contratto e helper per costruire richieste valide.
 *
 * @package WP_Health_Check_Lite
 */

use PHPUnit\Framework\TestCase;

abstract class LiteTestCase extends TestCase {

	/** URL del sito nei test (stesso dei vettori di contratto). */
	const SITE = 'https://example.org';

	/** @var WPHC_Lite Istanza sotto test. */
	protected $lite;

	/** @var int Ora corrente simulata (stesso issued_at fisso dei vettori). */
	protected $now = 1700000000;

	/** @var string Cartella dati temporanea del test. */
	protected $data_dir;

	/** @var int Numero di chiamate al resolver dell'IP pubblico. */
	protected $http_calls = 0;

	/** @var string|null Risposta del resolver dell'IP pubblico. */
	protected $http_answer = null;

	/** @var int Contatore per nonce sempre diversi. */
	private $nonce_counter = 0;

	/** @var string Chiave segreta Ed25519 di test (dal seed k1 dei vettori). */
	private $secret_key;

	/** @var string Chiave pubblica k1 di test in base64. */
	private $public_b64;

	/**
	 * Vettori di contratto generati dal codice dell'hub.
	 *
	 * @return array
	 */
	protected static function vectors(): array {
		$path = dirname( __DIR__, 2 ) . '/tests/fixtures/contract-vectors.json';

		return json_decode( (string) file_get_contents( $path ), true );
	}

	protected function setUp(): void {
		parent::setUp();

		// Chiavi di test k1: il seed dei vettori produce la stessa chiave pubblica dell'hub di test.
		$k1               = self::vectors()['keys']['k1'];
		$this->secret_key = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( base64_decode( $k1['seed_b64'] ) ) );
		$this->public_b64 = $k1['public_b64'];

		// Cartella dati nuova per ogni test, nella tmp del sistema (non sul bind mount).
		$this->data_dir = sys_get_temp_dir() . '/wphc-lite-test-' . bin2hex( random_bytes( 6 ) );

		$this->lite = $this->make_lite();
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->data_dir );
		parent::tearDown();
	}

	/**
	 * Crea un'istanza con la configurazione di test, con eventuali override.
	 *
	 * @param array $overrides Chiavi di configurazione da sostituire.
	 */
	protected function make_lite( array $overrides = array() ): WPHC_Lite {
		$config = array_merge(
			array(
				'site_urls'   => array( self::SITE ),
				'data_dir'    => $this->data_dir,
				'trust_proxy' => false,
				// Ora e rete iniettate: nessun time() e nessuna chiamata a ipify nei test.
				'now'         => function () {
					return $this->now;
				},
				'http_get'    => function ( $url ) {
					++$this->http_calls;

					return $this->http_answer;
				},
			),
			$overrides
		);

		return new WPHC_Lite( $config, $this->public_b64 );
	}

	/** Firma Ed25519 base64 (standard) di un messaggio con la chiave k1 di test. */
	protected function sign( string $message ): string {
		return base64_encode( sodium_crypto_sign_detached( $message, $this->secret_key ) );
	}

	/** Nonce esadecimale sempre nuovo. */
	protected function nonce(): string {
		return sprintf( '%032x', ++$this->nonce_counter );
	}

	/** Server di default: HTTPS, IP chiamante di documentazione, IP server pubblico (niente chiamata a ipify). */
	protected function server( array $extra = array() ): array {
		return array_merge(
			array(
				'HTTPS'           => 'on',
				'REMOTE_ADDR'     => '203.0.113.10',
				'SERVER_ADDR'     => '151.101.1.69',
				'SERVER_SOFTWARE' => 'LiteSpeed',
			),
			$extra
		);
	}

	/**
	 * Esegue una richiesta e restituisce [status, headers, body].
	 *
	 * @return array{0:int,1:array,2:array}
	 */
	protected function request( string $method, string $route, array $headers = array(), string $body = '', array $server = array(), array $query = array() ): array {
		return $this->lite->handle( $method, $route, $headers, $body, $this->server( $server ), $query );
	}

	/** Corpo di un enroll firmato (protocollo 1 o 2). Overrides: campi del JSON, "signature", "omit" (campi da togliere). */
	protected function enroll_body( int $protocol, string $token, array $o = array() ): string {
		$f = array_merge(
			array(
				'site_url'         => self::SITE,
				'token'            => $token,
				'dashboard_origin' => 'https://fleet.example',
				'issued_at'        => $this->now,
				'nonce'            => $this->nonce(),
			),
			$o
		);

		if ( $protocol >= 2 ) {
			$message       = WPHC_Lite::build_signed_payload( 'enroll', array( $f['site_url'], $f['token'], $f['dashboard_origin'], $f['nonce'], (string) $f['issued_at'], (string) $protocol ) );
			$f['protocol'] = $protocol;
		} else {
			$message = WPHC_Lite::build_enroll_signing_payload( $f['site_url'], $f['token'], $f['dashboard_origin'], $f['issued_at'] );
			unset( $f['nonce'] );
		}

		$f['signature'] = $o['signature'] ?? $this->sign( $message );
		foreach ( $o['omit'] ?? array() as $field ) {
			unset( $f[ $field ] );
		}
		unset( $f['omit'] );

		return (string) json_encode( $f );
	}

	/** Corpo di un rotate firmato. */
	protected function rotate_body( string $new_token, array $o = array() ): string {
		$f = array_merge(
			array(
				'site_url'  => self::SITE,
				'new_token' => $new_token,
				'nonce'     => $this->nonce(),
				'issued_at' => $this->now,
				'protocol'  => 2,
			),
			$o
		);
		$message        = WPHC_Lite::build_signed_payload( 'rotate', array( $f['site_url'], $f['new_token'], $f['nonce'], (string) $f['issued_at'], (string) $f['protocol'] ) );
		$f['signature'] = $o['signature'] ?? $this->sign( $message );

		return (string) json_encode( $f );
	}

	/** Corpo di un revoke firmato. */
	protected function revoke_body( array $o = array() ): string {
		$f = array_merge(
			array(
				'site_url'  => self::SITE,
				'nonce'     => $this->nonce(),
				'issued_at' => $this->now,
			),
			$o
		);
		$message        = WPHC_Lite::build_signed_payload( 'revoke', array( $f['site_url'], $f['nonce'], (string) $f['issued_at'] ) );
		$f['signature'] = $o['signature'] ?? $this->sign( $message );

		return (string) json_encode( $f );
	}

	/** Enroll valido: verifica il 200 e restituisce la risposta. */
	protected function enroll_ok( int $protocol, string $token ): array {
		$response = $this->request( 'POST', 'enroll', array(), $this->enroll_body( $protocol, $token ) );
		$this->assertSame( 200, $response[0], 'enroll: ' . json_encode( $response[2] ) );

		return $response;
	}

	/**
	 * GET autenticata. Con $signed aggiunge gli header X-WPHC-* firmati con $secret (default: il token).
	 *
	 * @return array{0:int,1:array,2:array}
	 */
	protected function get( string $route, ?string $token, bool $signed = false, array $query = array(), ?string $secret = null, array $server = array() ): array {
		$headers = array();
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		if ( $signed ) {
			$timestamp = (string) $this->now;
			$nonce     = $this->nonce();

			$headers['X-WPHC-Timestamp'] = $timestamp;
			$headers['X-WPHC-Nonce']     = $nonce;
			$headers['X-WPHC-Signature'] = WPHC_Lite::sign_request( $secret ?? (string) $token, 'GET', '/health-check/v1/' . $route, '', $timestamp, $nonce );
		}

		return $this->request( 'GET', $route, $headers, '', $server, $query );
	}

	/** Legge un file JSON dello stato dalla cartella dati. */
	protected function read_state_file( string $name ): array {
		$path = $this->data_dir . '/' . $name . '.json';

		return is_file( $path ) ? (array) json_decode( (string) file_get_contents( $path ), true ) : array();
	}

	/** Cancella ricorsivamente una cartella temporanea. */
	private function remove_dir( string $dir ): void {
		if ( is_file( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_dir( $dir . '/' . $entry );
			}
		}
		rmdir( $dir );
	}
}
