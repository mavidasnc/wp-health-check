<?php
/**
 * Autologin one-time: rotta POST /autologin/token e consumo su init (?wphc_autologin=).
 *
 * @package WP_Health_Check
 */

/**
 * Autologin in due passi: la rotta POST /autologin/token (autenticata) genera un token one-time per aprire
 * wp-admin come l'utente corrente, e maybe_consume() (agganciata a init, FUORI dalla REST API) lo consuma
 * con una normale navigazione del browser, imposta il cookie di sessione ed esegue do_action( 'wp_login' ).
 * Le chiavi dei token pendenti sono tracciate in un'option perche' POST /revoke possa invalidarle tutte.
 *
 * Raccoglie la sezione Q del file 1.38.1. Hook servito (registrato per ora in legacy.php, dal lotto 4 in
 * WPHC_Plugin::boot()): init, priorita' 10, QUARTO e ultimo callback del plugin su init (l'ordine conta:
 * maybe_consume() termina sempre con redirect ed exit). I rami di errore e di successo sono coperti dal golden.
 */
final class WPHC_Autologin {

	// -----------------------------------------------------------------------
	// AUTOLOGIN: POST /autologin/token + consumo su init
	// -----------------------------------------------------------------------
	//
	// Pattern a due passi: le Application Password autenticano una singola
	// chiamata REST (Basic Auth), ma non creano una sessione a cookie navigabile
	// nel browser. Per aprire wp-admin gia' autenticati serve quindi generare un
	// token one-time via una chiamata REST autenticata (questa sezione, primo
	// passo), e poi "consumarlo" con una normale navigazione del browser che
	// imposta il cookie di sessione (WPHC_Autologin::maybe_consume(), secondo
	// passo, agganciata su 'init' e volutamente FUORI dalla REST API).

	/**
	 * Aggiunge una chiave di transient di autologin all'elenco di quelle
	 * pendenti (dalla 1.30.0), cosi' POST /revoke puo' invalidarle tutte in
	 * blocco tramite delete_transient() — l'unico modo corretto di rimuovere un
	 * transient anche quando il sito usa un object cache persistente (Redis/
	 * Memcached), dove una DELETE diretta su wp_options non basterebbe.
	 * L'elenco resta volutamente corto (il TTL dei token e' di pochi secondi):
	 * scarta le chiavi piu' vecchie oltre un tetto, per non far crescere
	 * l'opzione se qualcosa lasciasse residui non consumati.
	 *
	 * @param string $key Chiave del transient da tracciare.
	 */
	private static function track_pending( $key ) {
		$pending   = (array) get_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, array() );
		$pending[] = $key;
		if ( count( $pending ) > 20 ) {
			$pending = array_slice( $pending, -20 );
		}
		update_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, $pending, false );
	}

	/**
	 * Rimuove una chiave dall'elenco dei token di autologin pendenti, dopo che
	 * e' stata consumata (o invalidata da una revoca). Controparte di
	 * WPHC_Autologin::track_pending().
	 *
	 * @param string $key Chiave del transient da rimuovere dall'elenco.
	 */
	private static function untrack_pending( $key ) {
		$pending = (array) get_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, array() );
		$pending = array_values( array_diff( $pending, array( $key ) ) );
		if ( empty( $pending ) ) {
			delete_option( WPHC_Options::OPT_AUTOLOGIN_PENDING );
		} else {
			update_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, $pending, false );
		}
	}

	/**
	 * POST /autologin/token — genera un token one-time per aprire wp-admin gia'
	 * autenticati come l'utente corrente. L'identita' e' quella che WordPress
	 * stesso ha gia' risolto per questa richiesta (tipicamente una Application
	 * Password, ma funziona anche con cookie), MAI un user_id passato dal
	 * chiamante: niente mapping da mantenere in questo plugin.
	 *
	 * Il token e' un valore casuale ad alta entropia (32 byte da random_bytes(),
	 * CSPRNG — mai rand()/uniqid()), mai derivato ne' prevedibile. Il transient
	 * che lo custodisce e' indicizzato dall'hash SHA-256 del token, non dal
	 * token in chiaro: chi legge le wp_options non trova il segreto navigabile
	 * in tabella.
	 *
	 * Dalla 1.34.0 (A-10) richiede HTTPS, come gia' dichiarato da
	 * WPHC_Guard::require_https() ma finora non applicato a questa rotta.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente (nessun payload richiesto).
	 * @return WP_REST_Response|WP_Error Esito con l'URL di autologin e la scadenza.
	 */
	public static function route_token( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Cors::maybe_send_headers();

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$user = wp_get_current_user();

		$token          = bin2hex( random_bytes( 32 ) );
		$key            = WPHC_Options::TRANSIENT_AUTOLOGIN_PREFIX . hash( 'sha256', $token );
		$correlation_id = WPHC_Util::generate_correlation_id();

		// Il transient custodisce anche il correlation_id, cosi' la riga di log
		// del consumo (WPHC_Autologin::maybe_consume()) puo' collegarsi a questa
		// riga di richiesta senza dover ricalcolare o esporre nulla in piu'.
		set_transient(
			$key,
			array(
				'user_id'        => $user->ID,
				'correlation_id' => $correlation_id,
			),
			WP_HEALTH_CHECK_AUTOLOGIN_TTL
		);
		// Registrato anche in un piccolo elenco di chiavi pendenti (dalla 1.30.0):
		// e' cio' che permette a POST /revoke di invalidare i token di autologin
		// gia' emessi e non ancora consumati, vedi WPHC_Autologin::track_pending().
		self::track_pending( $key );

		// Audit minimo pre-esistente (non una tabella dedicata): solo l'ultimo
		// autologin richiesto, sullo stesso spirito di WPHC_Auth::record_access().
		// Resta accanto alla riga di log sotto (che invece conserva lo storico).
		update_option(
			WPHC_Options::OPT_LAST_AUTOLOGIN,
			array(
				'user_id' => $user->ID,
				'login'   => $user->user_login,
				'at'      => gmdate( 'c' ),
				'ip'      => WPHC_Util::get_client_ip(),
			),
			false
		);

		// Riga di log dell'audit trail: type='token', target/name identificano
		// l'utente per cui e' stato generato (stesso schema tecnico/leggibile
		// gia' usato per plugin/temi/core, dove target e' l'identificatore e
		// name l'etichetta). version_from/to e active non si applicano: null.
		wphc_log_update_row( $correlation_id, 'token', $user->user_login, $user->display_name ? $user->display_name : $user->user_login, null, null, 'completed' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'wp-health-check: autologin token generato per user #%d (%s)', $user->ID, $user->user_login ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return rest_ensure_response(
			array(
				'autologin_url' => add_query_arg( 'wphc_autologin', $token, home_url( '/' ) ),
				'expires_in'    => WP_HEALTH_CHECK_AUTOLOGIN_TTL,
				'user_id'       => $user->ID,
				'user_login'    => $user->user_login,
			)
		);
	}

	/**
	 * Consuma un token di autologin. Agganciata su 'init' e deliberatamente FUORI
	 * dalla REST API: questo endpoint deve rispondere a una semplice navigazione
	 * del browser (?wphc_autologin=<token> sull'home del sito), non a una
	 * chiamata con header di autenticazione. Girare su 'init', prima che
	 * qualunque output sia inviato, evita ogni rischio "headers already sent"
	 * nel chiamare wp_set_auth_cookie().
	 *
	 * Fail-closed: qualunque anomalia (token assente, scaduto/già consumato,
	 * utente inesistente) porta a un redirect silenzioso al login, senza
	 * distinguere il motivo nella risposta — stesso principio di
	 * WPHC_Auth::require_token() per le rotte dati. Ogni esito (riuscito o fallito)
	 * lascia comunque una riga type='login' nella tabella di log: sul successo
	 * riusa il correlation_id della riga 'token' corrispondente; se il token e'
	 * del tutto sconosciuto/scaduto (transient gia' assente) non c'e' alcuna
	 * identita' recuperabile, quindi si logga con un correlation_id nuovo e
	 * target = prefisso dell'hash del token (permette di riconoscere tentativi
	 * ripetuti con lo stesso token senza esporlo in chiaro); se il token e'
	 * valido ma l'utente e' stato nel frattempo cancellato, si riusa comunque il
	 * correlation_id della riga 'token', con target = user_id (non si dispone
	 * piu' dello user_login).
	 *
	 * Dalla 1.34.0 (A-10, review 2026-10-03):
	 * - stesso throttle per IP delle rotte autenticate: ogni GET anonima con
	 *   ?wphc_autologin= scriveva una riga di log e toccava le options senza
	 *   alcun limite; oltre la soglia si va al login senza scrivere nulla, e
	 *   ogni token sconosciuto conta come tentativo fallito;
	 * - HTTPS obbligatorio: in chiaro il token non viene nemmeno letto;
	 * - cookie di sessione (remember = false) invece di 14 giorni: un token da
	 *   pochi secondi non deve diventare una sessione admin di due settimane.
	 */
	public static function maybe_consume() {
		if ( empty( $_GET['wphc_autologin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- il token stesso e' la credenziale one-time, non serve un nonce di sessione.
			return;
		}

		if ( is_wp_error( WPHC_Guard::throttle_check() ) || is_wp_error( WPHC_Guard::require_https() ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['wphc_autologin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key   = WPHC_Options::TRANSIENT_AUTOLOGIN_PREFIX . hash( 'sha256', $token );

		$data = get_transient( $key );

		// Consumo single-use: il transient viene cancellato SUBITO dopo la
		// lettura, indipendentemente dall'esito, per chiudere la finestra di
		// replay al primo utilizzo anche se l'utente risultasse poi invalido.
		delete_transient( $key );
		self::untrack_pending( $key );

		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			// Token sconosciuto, scaduto o gia' consumato: nessuna identita'
			// recuperabile. target = prefisso dell'hash del token (stesso hash
			// gia' calcolato per la chiave del transient), per poter comunque
			// distinguere tentativi ripetuti con lo stesso token nei log.
			WPHC_Guard::throttle_register_failure();
			wphc_log_update_row(
				WPHC_Util::generate_correlation_id(),
				'login',
				substr( hash( 'sha256', $token ), 0, 16 ),
				__( 'Token sconosciuto o scaduto', 'wp-health-check' ),
				null,
				null,
				'failed',
				__( 'Token assente, scaduto o gia\' consumato.', 'wp-health-check' )
			);
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = get_user_by( 'id', (int) $data['user_id'] );
		if ( ! $user ) {
			wphc_log_update_row(
				$data['correlation_id'],
				'login',
				(string) $data['user_id'],
				__( 'Utente non trovato', 'wp-health-check' ),
				null,
				null,
				'failed',
				__( 'L\'utente e\' stato eliminato dopo la richiesta del token.', 'wp-health-check' )
			);
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		wp_set_current_user( $user->ID );
		// remember = false: cookie di sessione, non i 14 giorni di "Ricordami".
		wp_set_auth_cookie( $user->ID, false );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- si invoca l'hook core "wp_login" (non se ne dichiara uno nuovo), cosi' i plugin di audit/2FA che vi si agganciano reagiscono anche a questo login.
		do_action( 'wp_login', $user->user_login, $user );

		wphc_log_update_row( $data['correlation_id'], 'login', $user->user_login, $user->display_name ? $user->display_name : $user->user_login, null, null, 'completed' );

		wp_safe_redirect( admin_url() );
		exit;
	}
}
