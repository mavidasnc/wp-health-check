<?php
/**
 * Guardie delle rotte: nonce anti-replay, firma HMAC delle richieste, rate limit sui fallimenti, obbligo di HTTPS.
 *
 * @package WP_Health_Check
 */

/**
 * Guardie condivise dalle rotte del protocollo v2 (dalla 1.30.0): registro dei nonce anti-replay, verifica
 * della firma HMAC delle richieste operative, rate limit sui tentativi di autenticazione falliti e obbligo
 * di HTTPS. Sono usate da /enroll, /rotate, /revoke, /autologin/token e dalle rotte dati (tramite
 * WPHC_Auth::require_token()).
 *
 * Raccoglie la sezione E del file 1.38.1 (parte delle guardie). Nessun hook. Le scritture sono solo i
 * transient dei nonce e del contatore dei fallimenti.
 *
 * Testo del banner del file 1.38.1 ("PROTOCOLLO V2: FIRMA DEL CENTRO (kid), ANTI-REPLAY, RATE LIMIT, HTTPS"),
 * conservato qui perche' descrive il perche' di queste guardie e delle primitive di WPHC_Crypto:
 *
 * Blocco di funzioni condivise dalla 1.30.0 fra /enroll (v2), le nuove rotte
 * /rotate e /revoke (firmate Ed25519 come /enroll) e le rotte dati esistenti
 * (che aggiungono la firma HMAC anti-replay quando il sito e' su protocollo
 * 2). Nessuna di queste funzioni e' invocata qui: sono solo definite prima
 * del punto in cui servono, per lo stesso ordine logico gia' seguito dal
 * resto del file (helper condivisi prima, callback dopo).
 *
 * Con le classi lo stesso ordine logico e' quello del manifest (src/manifest.php): WPHC_Crypto e
 * WPHC_Guard prima di WPHC_Auth e di WPHC_Enrollment, che le chiamano.
 */
final class WPHC_Guard {

	/**
	 * True se il nonce indicato risulta gia' registrato (quindi la richiesta
	 * e' un replay). Sola lettura: la registrazione (scrittura) avviene solo
	 * DOPO che la firma e' stata verificata, vedi WPHC_Guard::remember_nonce().
	 *
	 * @param string $nonce Nonce fornito dal chiamante.
	 * @return bool True se gia' visto.
	 */
	public static function nonce_seen( $nonce ) {
		return false !== get_transient( WPHC_Options::TRANSIENT_NONCE_PREFIX . hash( 'sha256', (string) $nonce ) );
	}

	/**
	 * Registra un nonce come "usato" per WP_HEALTH_CHECK_NONCE_TTL secondi.
	 * Chiamata SOLO dopo che la firma della richiesta e' gia' stata verificata:
	 * un chiamante non autenticato che prova nonce a caso non puo' quindi
	 * gonfiare wp_options sui siti senza object cache persistente. Chiave
	 * indicizzata sull'hash SHA-256 del nonce, mai il valore in chiaro — stesso
	 * pattern gia' collaudato dal token di autologin.
	 *
	 * @param string $nonce Nonce da registrare.
	 */
	public static function remember_nonce( $nonce ) {
		set_transient( WPHC_Options::TRANSIENT_NONCE_PREFIX . hash( 'sha256', (string) $nonce ), time(), WP_HEALTH_CHECK_NONCE_TTL );
	}

	/**
	 * Verifica la firma anti-replay di una richiesta operativa (protocollo v2):
	 * header X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature. La stringa
	 * canonica firma il METODO e la ROTTA REST (mai l'URL completo, per restare
	 * compatibile con reverse proxy e varianti www/non-www, come gia' fa
	 * WPHC_Util::candidate_site_urls() per l'enroll), l'hash del corpo, il timestamp e
	 * il nonce. La query string NON entra nella firma (?fresh=1, ?check=1 non
	 * sono sicurezza). Ogni passo che fallisce ritorna lo stesso WP_Error
	 * generico, per non rivelare al chiamante quale controllo sia fallito.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @param string          $secret  Segreto (corrente o precedente) con cui verificare l'HMAC.
	 * @return true|WP_Error True se la firma e' valida, altrimenti WP_Error 401.
	 */
	public static function verify_request_signature( WP_REST_Request $request, $secret ) {
		$unauthorized = new WP_Error( 'wphc_unauthorized', __( 'Non autorizzato.', 'wp-health-check' ), array( 'status' => 401 ) );

		$timestamp_header = $request->get_header( 'x-wphc-timestamp' );
		$nonce            = $request->get_header( 'x-wphc-nonce' );
		$signature        = $request->get_header( 'x-wphc-signature' );

		if ( empty( $timestamp_header ) || empty( $nonce ) || empty( $signature ) ) {
			return $unauthorized;
		}

		if ( abs( time() - (int) $timestamp_header ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
			return $unauthorized;
		}

		if ( self::nonce_seen( $nonce ) ) {
			return $unauthorized;
		}

		$canonical = $request->get_method() . "\n"
			. $request->get_route() . "\n"
			. hash( 'sha256', (string) $request->get_body() ) . "\n"
			. $timestamp_header . "\n"
			. $nonce;

		$expected = WPHC_Crypto::base64url_encode( hash_hmac( 'sha256', $canonical, (string) $secret, true ) );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			return $unauthorized;
		}

		// Registrata SOLO ora: la firma e' gia' valida, quindi il chiamante e'
		// autenticato (vedi il commento di WPHC_Guard::remember_nonce()).
		self::remember_nonce( $nonce );

		return true;
	}

	/**
	 * Rate limit sui tentativi di autenticazione falliti (§3.12): transient
	 * indicizzato sull'hash dell'IP del chiamante, incrementato SOLO dai
	 * fallimenti (WPHC_Guard::throttle_register_failure()), mai dalle richieste
	 * autenticate con successo — per non gonfiare wp_options con traffico
	 * legittimo sui siti privi di object cache persistente.
	 *
	 * @return true|WP_Error True se sotto soglia, altrimenti WP_Error 429.
	 */
	public static function throttle_check() {
		$fails = (int) get_transient( WPHC_Options::TRANSIENT_FAIL_PREFIX . hash( 'sha1', WPHC_Util::get_client_ip() ) );

		if ( $fails >= WP_HEALTH_CHECK_RATE_MAX_FAILS ) {
			header( 'Retry-After: ' . WP_HEALTH_CHECK_RATE_WINDOW );

			return new WP_Error(
				'wphc_rate_limited',
				__( 'Troppi tentativi falliti: riprova piu\' tardi.', 'wp-health-check' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Incrementa il contatore dei tentativi falliti per l'IP del chiamante
	 * corrente. Va invocata da ogni ramo di autenticazione fallita delle rotte
	 * protette da WPHC_Guard::throttle_check() (dati, /rotate, /revoke).
	 */
	public static function throttle_register_failure() {
		$key   = WPHC_Options::TRANSIENT_FAIL_PREFIX . hash( 'sha1', WPHC_Util::get_client_ip() );
		$fails = (int) get_transient( $key );
		set_transient( $key, $fails + 1, WP_HEALTH_CHECK_RATE_WINDOW );
	}

	/**
	 * Impone HTTPS sulle rotte che trasportano credenziali (/enroll, /rotate,
	 * /revoke, /autologin/token e, dalla 1.34.0, le rotte dati protette da
	 * WPHC_Auth::require_token()). Dietro un reverse proxy is_ssl() puo' risultare
	 * falso pur essendo il traffico reale in HTTPS: con il proxy fidato
	 * (wp_health_check_trust_proxy, lo stesso di WPHC_Util::get_client_ip()) vale
	 * l'header X-Forwarded-Proto impostato dal proxy.
	 *
	 * Fino alla 1.33.0 trust_proxy saltava del tutto il controllo (A-10, review
	 * 2026-10-03): ora serve che il proxy dichiari https (valore piu' a destra,
	 * come per X-Forwarded-For).
	 *
	 * @return true|WP_Error True se la richiesta e' su HTTPS.
	 */
	public static function require_https() {
		if ( is_ssl() ) {
			return true;
		}

		if ( (bool) get_option( WPHC_Options::OPT_TRUST_PROXY, false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$protos = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) );
			if ( 'https' === strtolower( (string) end( $protos ) ) ) {
				return true;
			}
		}

		return new WP_Error(
			'wphc_https_required',
			__( 'HTTPS obbligatorio per questa rotta.', 'wp-health-check' ),
			array( 'status' => 403 )
		);
	}
}
