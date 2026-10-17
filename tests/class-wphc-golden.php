<?php
/**
 * Helper per i "golden snapshot": confronta la risposta REST del plugin (e le option scritte)
 * con un file JSON versionato in tests/fixtures/golden/<nome>.json.
 *
 * Cosa entra nello snapshot:
 *  - status HTTP e body della risposta, normalizzati (timestamp, date ISO 8601, id casuali);
 *  - gli header presenti sull'oggetto WP_REST_Response (NB: gli header inviati con header()
 *    direttamente dal plugin, come Retry-After o i CORS, in CLI non sono osservabili:
 *    non sono nello snapshot);
 *  - le option "wp_health_check_*" scritte o modificate dalla richiesta (nome => valore), con il flag
 *    `autoload` grezzo della colonna del database (chiave `options_autoload`, presente solo se la
 *    richiesta ha scritto qualcosa): fissa cio' che il plugin fa davvero (es. il terzo argomento di
 *    update_option), non cio' che si vorrebbe.
 *
 * Modalita' aggiornamento: con la variabile d'ambiente WPHC_GOLDEN_UPDATE=1 i file vengono
 * (ri)scritti invece di essere confrontati. Si rigenera SOLO dopo aver capito perche' il
 * comportamento e' cambiato: il golden serve a dimostrare che il refactoring non lo cambia.
 *
 * @package WP_Health_Check
 */

/**
 * Cattura, normalizza e confronta gli snapshot.
 */
final class WPHC_Golden {

	/**
	 * Segnaposto dei valori volatili. Interi e stringhe numeriche hanno segnaposto DISTINTI
	 * (TS_INT / TS_STR): cosi' un cambio di tipo (es. un timestamp salvato come intero invece che
	 * come stringa) non passa inosservato.
	 */
	const TS_INT = '<TS_INT>';
	const TS_STR = '<TS_STR>';
	const ISO    = '<ISO8601>';
	const HEX    = '<HEX>';

	/**
	 * Cartella dei golden (tests/fixtures/golden).
	 *
	 * @return string
	 */
	public static function dir() {
		return __DIR__ . '/fixtures/golden';
	}

	/**
	 * Costruisce lo snapshot di una richiesta.
	 *
	 * @param WP_REST_Response $response       Risposta ottenuta.
	 * @param array            $options_before Option del plugin PRIMA della richiesta.
	 * @param array            $options_after  Option del plugin DOPO la richiesta.
	 * @return array Snapshot normalizzato.
	 */
	public static function capture( WP_REST_Response $response, array $options_before, array $options_after ) {
		// Solo le option nuove o cambiate: l'insieme di quelle "scritte" dalla richiesta.
		$written = array();
		foreach ( $options_after as $name => $value ) {
			if ( ! array_key_exists( $name, $options_before ) || $options_before[ $name ] !== $value ) {
				$written[ $name ] = $value;
			}
		}
		// Option sparite: cancellate dalla richiesta (valore null nello snapshot).
		$deleted = array_values( array_diff( array_keys( $options_before ), array_keys( $options_after ) ) );

		$snapshot = array(
			'status'          => $response->get_status(),
			'body'            => $response->get_data(),
			'headers'         => $response->get_headers(),
			'options_written' => $written,
			'options_deleted' => $deleted,
		);
		// Flag autoload (colonna grezza del database) delle sole option scritte.
		if ( array() !== $written ) {
			$snapshot['options_autoload'] = self::autoload_flags( array_keys( $written ) );
		}

		return self::normalize( $snapshot );
	}

	/**
	 * Valore grezzo della colonna `autoload` di ciascuna option, letto dal database (non dalla cache).
	 * Con WordPress 7.x e' 'on' / 'off' (terzo argomento di update_option true / false) oppure
	 * 'auto' / 'auto-on' / 'auto-off' (argomento omesso, scelto da WordPress).
	 *
	 * @param string[] $names Nomi delle option.
	 * @return array<string,string> nome => flag.
	 */
	public static function autoload_flags( array $names ) {
		global $wpdb;

		$flags = array();
		foreach ( $names as $name ) {
			$flags[ $name ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $flags;
	}

	/**
	 * Normalizza ricorsivamente i valori volatili: interi (TS_INT) e stringhe numeriche (TS_STR) vicini all'ora corrente, date ISO 8601
	 * UTC (gmdate('c')), stringhe esadecimali lunghe (id/nonce casuali) e chiavi ordinate.
	 *
	 * @param mixed $value Valore da normalizzare.
	 * @return mixed
	 */
	public static function normalize( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::normalize( $item );
			}
			// Ordine stabile delle chiavi associative (le liste restano nell'ordine originale).
			if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) ) {
				ksort( $out );
			}

			return $out;
		}

		// Intero entro un giorno dall'ora corrente: un timestamp (issued_at, rotated_at, ...).
		if ( is_int( $value ) && abs( $value - time() ) <= DAY_IN_SECONDS ) {
			return self::TS_INT;
		}
		if ( is_string( $value ) ) {
			// L'URL del sito dipende dall'ambiente (wp-env: localhost:8889): lo si sostituisce.
			$value = str_replace( WPHC_Test_Case::call( 'wphc_normalize_site_url' ), '<SITE_URL>', $value );
			// La versione dell'agent cambia a ogni release (e nella build del refactoring): non e' contratto.
			if ( WP_HEALTH_CHECK_VERSION === $value ) {
				return '<AGENT_VERSION>';
			}
			// Le option lette dal database sono stringhe: stesso criterio per un intero scritto come testo,
			// ma con un segnaposto diverso da quello degli interi.
			if ( ctype_digit( $value ) && abs( (int) $value - time() ) <= DAY_IN_SECONDS ) {
				return self::TS_STR;
			}
			// Data ISO 8601 come la produce gmdate('c'): 2026-10-17T10:00:00+00:00.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $value ) ) {
				return self::ISO;
			}
			// Stringa esadecimale di almeno 16 caratteri (nonce, correlation id).
			if ( preg_match( '/^[0-9a-f]{16,}$/', $value ) ) {
				return self::HEX;
			}
		}

		return $value;
	}

	/**
	 * Confronta lo snapshot con il file golden (o lo riscrive in modalita' aggiornamento).
	 *
	 * @param PHPUnit\Framework\TestCase $test     Test in corso (per le asserzioni).
	 * @param string                     $name     Nome del golden, senza estensione (puo' contenere "/").
	 * @param array                      $snapshot Snapshot gia' normalizzato.
	 */
	public static function assert_matches( $test, $name, array $snapshot ) {
		$path    = self::dir() . '/' . $name . '.json';
		$encoded = wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

		if ( '1' === getenv( 'WPHC_GOLDEN_UPDATE' ) ) {
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $encoded );
			// Un golden appena scritto non e' una verifica: il test e' "ok" ma va rieseguito senza update.
			$test->addToAssertionCount( 1 );

			return;
		}

		$test->assertFileExists( $path, "Golden mancante: {$name}. Generalo con WPHC_GOLDEN_UPDATE=1." );
		// Confronto sul JSON decodificato: indifferente ai fine riga del checkout.
		$expected = json_decode( (string) file_get_contents( $path ), true );
		$test->assertSame( $expected, json_decode( $encoded, true ), "Golden diverso: {$name}" );
	}
}
