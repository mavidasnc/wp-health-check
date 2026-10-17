<?php
/**
 * Autenticazione delle rotte: permission_callback con bearer e firma, tracciamento accessi, permesso di /debug.
 *
 * @package WP_Health_Check
 */

/**
 * Autenticazione delle rotte REST: permission_callback condiviso dalle rotte dati (bearer token, finestra
 * di grazia del segreto precedente, firma anti-replay del protocollo v2), tracciamento dell'ultimo accesso
 * autenticato e permission_callback delle rotte /debug e /autologin/token (manage_options).
 *
 * Raccoglie la sezione F del file 1.38.1, record_access() della sezione C e debug_permission() della
 * sezione P. require_token() ha effetti collaterali (header CORS, transient, riga di log, cancellazione
 * del segreto precedente) e l'ordine dei suoi controlli decide quale errore vince: non va modificato.
 */
final class WPHC_Auth {

	/**
	 * Registra l'accesso corrente autenticato e restituisce il valore
	 * PRECEDENTE (prima della sovrascrittura). L'ordine e' importante: va
	 * letto il vecchio valore prima di aggiornarlo, cosi' /health puo'
	 * esporre l'accesso precedente come segnale di audit ("chi ha chiamato
	 * l'ultima volta, prima di questa chiamata").
	 *
	 * Va invocata come primissimo passo di ogni rotta autenticata con
	 * successo (health, detail/*, update), PRIMA di qualunque controllo di
	 * cache: il tracciamento accessi deve avvenire ad ogni richiesta reale,
	 * indipendentemente dal fatto che il corpo della risposta sia servito
	 * da cache o ricalcolato.
	 *
	 * Durante wp_doing_cron() (es. il drain degli aggiornamenti bulk, che
	 * chiama il preflight una volta per elemento) NON scrive: un job da N
	 * elementi sovrascriverebbe altrimenti l'audit "ultimo accesso" con un IP
	 * di loopback/vuoto per N volte, cancellando il segnale reale dell'ultima
	 * chiamata autenticata da parte del centro. Stessa scelta gia' applicata a
	 * /ping. I valori restituiti restano quelli correnti, invariati.
	 *
	 * @return array{at: string|null, ip: string|null} Timestamp/IP dell'accesso precedente.
	 */
	public static function record_access() {
		$previous_at = get_option( WPHC_Options::OPT_LAST_REQUEST_AT );
		$previous_ip = get_option( WPHC_Options::OPT_LAST_REQUEST_IP );

		if ( wp_doing_cron() ) {
			return array(
				'at' => $previous_at ? $previous_at : null,
				'ip' => $previous_ip ? $previous_ip : null,
			);
		}

		update_option( WPHC_Options::OPT_LAST_REQUEST_AT, gmdate( 'c' ), false );
		update_option( WPHC_Options::OPT_LAST_REQUEST_IP, WPHC_Util::get_client_ip(), false );

		return array(
			'at' => $previous_at ? $previous_at : null,
			'ip' => $previous_ip ? $previous_ip : null,
		);
	}

	// -----------------------------------------------------------------------
	// AUTENTICAZIONE DELLE ROTTE DATI
	// -----------------------------------------------------------------------

	/**
	 * Permission_callback condiviso da /health, /detail/*, /update* e /thumbnail.
	 * Legge il token salvato in wp_health_check_token (assegnato dall'enroll o
	 * dall'ultima rotazione) e lo confronta in tempo costante con il bearer
	 * token fornito. Header mancante e token errato restituiscono lo STESSO
	 * errore (stesso codice, stesso messaggio) per non rivelare a un chiamante
	 * non autenticato quale dei due casi si sia verificato.
	 *
	 * Dalla 1.30.0 (protocollo v2):
	 * - un sito revocato (wp_health_check_revoked_at valorizzata) rifiuta ogni
	 *   chiamata con 403, indipendentemente dal token fornito;
	 * - un rate limit sui fallimenti (WPHC_Guard::throttle_check()) risponde 429 prima
	 *   ancora di leggere l'header Authorization;
	 * - se il token corrente non combacia, si ritenta con wp_health_check_token_prev
	 *   ma SOLO dentro la finestra di grazia WP_HEALTH_CHECK_ROTATION_GRACE dalla
	 *   rotazione: e' cio' che rende una rotazione senza downtime per la flotta;
	 * - se il sito e' su wp_health_check_protocol >= 2, la richiesta deve anche
	 *   portare una firma anti-replay valida (X-WPHC-*, vedi
	 *   WPHC_Guard::verify_request_signature()); se il protocollo e' ancora 1 ma la
	 *   firma e' comunque presente, viene verificata lo stesso (dual-stack: un
	 *   centro aggiornato puo' iniziare a firmare prima che il sito completi la
	 *   rotazione a protocollo 2).
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return true|WP_Error True se autorizzato, altrimenti WP_Error con lo status corretto.
	 */
	public static function require_token( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		// Dalla 1.34.0 (A-10): il Bearer non deve mai viaggiare in chiaro.
		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$stored_token = get_option( WPHC_Options::OPT_TOKEN );
		if ( empty( $stored_token ) ) {
			// Il sito esiste ma non ha mai completato l'enroll: e' uno stato
			// diverso da "token errato", quindi ha un codice HTTP dedicato.
			return new WP_Error(
				'wphc_not_enrolled',
				__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
				array( 'status' => 503 )
			);
		}

		if ( ! empty( get_option( WPHC_Options::OPT_REVOKED_AT ) ) ) {
			return new WP_Error(
				'wphc_revoked',
				__( 'Segreto revocato dal sistema centrale.', 'wp-health-check' ),
				array( 'status' => 403 )
			);
		}

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$unauthorized = new WP_Error(
			'wphc_unauthorized',
			__( 'Non autorizzato.', 'wp-health-check' ),
			array( 'status' => 401 )
		);

		$auth_header = $request->get_header( 'authorization' );
		if ( empty( $auth_header ) || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
			WPHC_Guard::throttle_register_failure();
			return $unauthorized;
		}

		$provided_token = trim( substr( $auth_header, 7 ) );

		// hash_equals: confronto in tempo costante, indispensabile per un
		// segreto (previene timing attack che dedurrebbero il token byte per
		// byte misurando quanto a lungo dura il confronto).
		$prev_token = get_option( WPHC_Options::OPT_TOKEN_PREV );
		$rotated_at = (int) get_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );
		$using_prev = false;

		if ( hash_equals( (string) $stored_token, $provided_token ) ) {
			$using_prev = false;
		} elseif (
			! empty( $prev_token )
			&& $rotated_at > 0
			&& ( time() - $rotated_at ) <= WP_HEALTH_CHECK_ROTATION_GRACE
			&& hash_equals( (string) $prev_token, $provided_token )
		) {
			$using_prev = true;
		} else {
			WPHC_Guard::throttle_register_failure();
			return $unauthorized;
		}

		$protocol      = (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 );
		$signature_hdr = $request->get_header( 'x-wphc-signature' );

		if ( $protocol >= 2 || ! empty( $signature_hdr ) ) {
			$secret_for_signature = $using_prev ? $prev_token : $stored_token;
			$signature_check      = WPHC_Guard::verify_request_signature( $request, (string) $secret_for_signature );
			if ( is_wp_error( $signature_check ) ) {
				WPHC_Guard::throttle_register_failure();
				return $unauthorized;
			}
		}

		if ( $using_prev ) {
			// Riga di log dedicata (§5.B): il segreto precedente e' ancora
			// dentro la finestra di grazia, ma va tracciato che e' stato usato.
			WPHC_Update_Log::log_row( WPHC_Util::generate_correlation_id(), 'token', 'rotation', __( 'Segreto precedente', 'wp-health-check' ), null, null, 'completed', 'grace_window_hit' );
		} elseif ( ! empty( $prev_token ) ) {
			// Primo uso riuscito del segreto NUOVO dopo una rotazione: il
			// precedente decade subito, non solo al tempo (la finestra di
			// grazia si chiude all'evento).
			delete_option( WPHC_Options::OPT_TOKEN_PREV );
			delete_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );
		}

		return true;
	}

	/**
	 * Permission callback della rotta /debug: solo manage_options (autenticazione
	 * WordPress, non bearer token). Chiamabile con una application password.
	 *
	 * @return bool True se l'utente corrente puo' gestire le opzioni.
	 */
	public static function debug_permission() {
		return current_user_can( 'manage_options' );
	}
}
