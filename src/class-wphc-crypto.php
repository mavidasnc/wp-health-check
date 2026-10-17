<?php
/**
 * Primitive crittografiche del protocollo: base64url, firma Ed25519 del centro, messaggi canonici firmati.
 *
 * @package WP_Health_Check
 */

/**
 * Primitive crittografiche del protocollo centro-sito: encoding base64url, verifica della firma Ed25519 del
 * sistema centrale (la chiave dipende da kid e scopo: k1 per le buste, k2 per le release) e costruzione dei
 * messaggi canonici che il centro firma (enroll v1 e buste v2).
 *
 * Raccoglie la sezione E del file 1.38.1 (parte crittografica) e build_enroll_signing_payload() della
 * sezione C. Nessun hook, nessuno stato proprio: dipende solo dalle costanti WP_HEALTH_CHECK_CENTRAL_PUBKEY*.
 */
final class WPHC_Crypto {

	/**
	 * Costruisce il messaggio canonico su cui il centro appone la firma
	 * Ed25519 per /enroll, e su cui il sito la verifica. La concatenazione e'
	 * fissa e ordinata, con "\n" come separatore. dashboard_origin puo'
	 * essere null (dashboard non ancora configurata): in quel caso il suo
	 * "posto" nella concatenazione e' una stringa vuota, non la stringa
	 * letterale "null" (scelta arbitraria ma univoca, documentata in
	 * README affinche' il centro la replichi esattamente).
	 *
	 * @param string      $site_url         URL normalizzato del sito target.
	 * @param string      $token            Token opaco assegnato dal centro.
	 * @param string|null $dashboard_origin Origin della dashboard, o null.
	 * @param int         $issued_at        Timestamp Unix di emissione della busta.
	 * @return string Messaggio canonico da firmare/verificare.
	 */
	public static function build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at ) {
		$origin_component = null === $dashboard_origin ? '' : (string) $dashboard_origin;

		return $site_url . "\n" . $token . "\n" . $origin_component . "\n" . (string) $issued_at;
	}

	/**
	 * Encoding base64url (RFC 4648 §5) senza padding, usato per la firma HMAC
	 * delle richieste operative (X-WPHC-Signature): evita i caratteri '+', '/'
	 * e '=' che altrimenti andrebbero percent-encoded in un header HTTP.
	 *
	 * @param string $binary Dati binari da codificare.
	 * @return string Stringa base64url.
	 */
	public static function base64url_encode( $binary ) {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding di una firma HMAC, non offuscamento.
	}

	/**
	 * Decodifica base64url -> binario, inverso di WPHC_Crypto::base64url_encode().
	 * Ripristina il padding '=' prima di richiamare base64_decode() in modalita'
	 * strict, cosi' un input malformato ritorna false invece di un valore
	 * troncato/silenziosamente sbagliato.
	 *
	 * @param string $data Stringa base64url da decodificare.
	 * @return string|false Dati binari, o false se non decodificabile.
	 */
	public static function base64url_decode( $data ) {
		$data      = strtr( (string) $data, '-_', '+/' );
		$remainder = strlen( $data ) % 4;
		if ( 0 !== $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}

		return base64_decode( $data, true );
	}

	/**
	 * Verifica una firma Ed25519 del sistema centrale, con le stesse tre
	 * guardie gia' corrette che erano inline in WPHC_Enrollment::route_enroll() prima della
	 * 1.30.0: base64_decode(..., true) strict, controllo esplicito delle
	 * lunghezze attese (SODIUM_CRYPTO_SIGN_*), fail-closed su SodiumException.
	 * Condivisa da /enroll, /rotate e /revoke.
	 *
	 * Il kid seleziona quale chiave pubblica usare, ma solo entro lo scopo
	 * dichiarato (dalla 1.35.0, M-8 della review 2026-10-11): le buste del centro
	 * verso il sito ("envelope": enroll, rotate, revoke) si verificano SOLO con
	 * "k1" (WP_HEALTH_CHECK_CENTRAL_PUBKEY); le release ("release") SOLO con "k2"
	 * (WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2). Senza questa separazione bastava
	 * "kid":"k2" nel body per far accettare a una rotta una firma prodotta con la
	 * chiave delle release. Un payload senza kid (buste v1 di /enroll) assume "k1".
	 *
	 * @param string $message       Messaggio canonico firmato.
	 * @param string $signature_b64 Firma Ed25519, base64 standard.
	 * @param string $kid           Identificativo della chiave ("k1" o "k2").
	 * @param string $purpose       "envelope" (default) o "release".
	 * @return bool True se la firma e' valida per quella chiave in quello scopo.
	 */
	public static function verify_central_signature( $message, $signature_b64, $kid = 'k1', $purpose = 'envelope' ) {
		$keys = ( 'release' === $purpose )
			? array( 'k2' => WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2 )
			: array( 'k1' => WP_HEALTH_CHECK_CENTRAL_PUBKEY );

		// Le costanti hanno un valore, ma wp-config.php puo' ridefinirle (anche vuote): fail-closed.
		if ( ! isset( $keys[ $kid ] ) || '' === $keys[ $kid ] ) { // @phpstan-ignore identical.alwaysFalse
			return false;
		}

		$pubkey_raw    = base64_decode( $keys[ $kid ], true );
		$signature_raw = base64_decode( (string) $signature_b64, true );

		if (
			false === $pubkey_raw || false === $signature_raw
			|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pubkey_raw )
			|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature_raw )
		) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature_raw, $message, $pubkey_raw );
		} catch ( SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Costruisce il messaggio canonico firmato per le buste centro->sito diverse
	 * da /enroll v1 (che mantiene il proprio formato storico, vedi
	 * WPHC_Crypto::build_enroll_signing_payload()). Il primo componente e' un prefisso
	 * di dominio letterale ("rotate", "revoke", "enroll") che impedisce di
	 * riusare una busta firmata valida per un'operazione contro un'operazione
	 * diversa: senza di esso i payload di /rotate e /revoke sarebbero
	 * strutturalmente confondibili (entrambi iniziano con site_url).
	 *
	 * @param string   $kind  Prefisso di dominio ("rotate", "revoke", "enroll").
	 * @param string[] $parts Componenti del payload, nell'ordine da firmare.
	 * @return string Messaggio canonico.
	 */
	public static function build_signed_payload( $kind, array $parts ) {
		return $kind . "\n" . implode( "\n", $parts );
	}
}
