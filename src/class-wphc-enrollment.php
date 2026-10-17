<?php
/**
 * Enrollment del sito: rotte /enroll, /rotate e /revoke, reset, errore diagnostico e alert di URL mismatch.
 *
 * @package WP_Health_Check
 */

/**
 * Enrollment del sito presso il sistema centrale: consegna del segreto (/enroll, v1 e v2), rotazione
 * (/rotate) e revoca (/revoke), tutte autenticate con la firma Ed25519 del centro; reset dell'enrollment
 * (comando WP-CLI e pulsante della tab Site Health); registrazione dell'ultimo errore di enroll e
 * email di avviso in caso di URL mismatch.
 *
 * Raccoglie la sezione H del file 1.38.1 e, dalla sezione C, reset_enrollment(), record_enroll_error() e
 * send_enroll_mismatch_alert(). Rotte pubbliche: l'ordine dei controlli (throttle, HTTPS, corpo, campi,
 * freschezza, nonce, firma, URL) e i codici d'errore sono parte del contratto con l'hub.
 */
final class WPHC_Enrollment {

	/**
	 * Cancella tutte le opzioni di enrollment di questo sito. Condivisa dal
	 * comando WP-CLI "wp health-check reset" e dal pulsante di reset nella tab
	 * Site Health: un'unica lista di opzioni, per evitare che le due strade
	 * finiscano per disallinearsi nel tempo.
	 */
	public static function reset() {
		$options = array(
			WPHC_Options::OPT_TOKEN,
			WPHC_Options::OPT_SITE_URL,
			WPHC_Options::OPT_DASHBOARD_ORIGIN,
			WPHC_Options::OPT_ENROLLED_AT,
			WPHC_Options::OPT_ENROLLED_IP,
			WPHC_Options::OPT_ENROLL_ISSUED_AT,
			WPHC_Options::OPT_LAST_REQUEST_AT,
			WPHC_Options::OPT_LAST_REQUEST_IP,
			WPHC_Options::OPT_LAST_ENROLL_ERROR,
			// Protocollo v2 (dalla 1.30.0): stato di rotazione/revoca/firma.
			WPHC_Options::OPT_TOKEN_PREV,
			WPHC_Options::OPT_TOKEN_ROTATED_AT,
			WPHC_Options::OPT_PROTOCOL,
			WPHC_Options::OPT_REVOKED_AT,
			WPHC_Options::OPT_SECRET_KID,
			WPHC_Options::OPT_AUTOLOGIN_PENDING,
			// wp_health_check_last_autologin sopravviveva erroneamente al reset
			// prima della 1.30.0 (§3.16 dell'analisi di sicurezza): un audit di
			// enrollment resettato non deve conservare l'ultimo autologin del
			// vecchio enrollment.
			WPHC_Options::OPT_LAST_AUTOLOGIN,
		);
		foreach ( $options as $option_name ) {
			delete_option( $option_name );
		}

		// Solo il lock anti-concorrenza (stato transitorio legato al sito): lo
		// storico della tabella wphc_update_log e il kill-switch
		// wp_health_check_updates_enabled sono config/audit del sito, non
		// dell'enrollment, e sopravvivono volutamente a un reset.
		delete_transient( WPHC_Options::TRANSIENT_UPDATE_LOCK );
	}

	/**
	 * Registra l'esito di un tentativo di enroll FALLITO in
	 * wp_health_check_last_enroll_error, per poterlo mostrare nella tab Site
	 * Health e diagnosticare rapidamente il motivo (es. URL inviato sbagliato).
	 * Su enroll riuscito l'opzione va invece cancellata (vedi WPHC_Enrollment::route_enroll).
	 *
	 * NB: /enroll e' pubblica, quindi anche tentativi con firma non valida
	 * finiscono qui: il valore "received" e' sanitizzato in scrittura e va
	 * comunque escapizzato in output (l'URL e' input non autenticato in questo
	 * ramo). L'opzione e' visibile solo a manage_options.
	 *
	 * @param string $code     Codice macchina del fallimento (es. wphc_enroll_url_mismatch).
	 * @param string $reason   Descrizione umana del fallimento.
	 * @param string $received URL inviato nella busta (grezzo), o stringa vuota se non disponibile.
	 */
	private static function record_enroll_error( $code, $reason, $received = '' ) {
		update_option(
			WPHC_Options::OPT_LAST_ENROLL_ERROR,
			array(
				'at'       => gmdate( 'c' ),
				'code'     => $code,
				'reason'   => $reason,
				'received' => sanitize_text_field( $received ),
				'ip'       => WPHC_Util::get_client_ip(),
			),
			false
		);
	}

	/**
	 * Invia a WP_HEALTH_CHECK_ALERT_EMAIL un avviso quando un enroll fallisce per
	 * URL mismatch, con il dettaglio utile a correggere la configurazione: URL
	 * inviato, URL atteso e l'elenco completo degli URL validi con cui firmare.
	 *
	 * Rate-limit: al massimo un'email all'ora per sito (transient), per evitare
	 * un flood se il sistema centrale ritenta l'enroll di frequente. Questo ramo
	 * e' comunque raggiungibile SOLO con una firma Ed25519 valida (la firma e'
	 * verificata prima, vedi WPHC_Enrollment::route_enroll): non e' quindi un vettore aperto
	 * ad attaccanti anonimi. L'invio usa wp_mail() del core, nessuna dipendenza
	 * esterna.
	 *
	 * @param string   $expected   URL canonico atteso dal sito (home normalizzato).
	 * @param string   $received   URL inviato nella busta di enroll (grezzo).
	 * @param string[] $candidates Elenco degli URL validi per l'enroll su questo sito.
	 */
	private static function send_enroll_mismatch_alert( $expected, $received, $candidates ) {
		// is_email() e' false anche per la stringa vuota: copre sia il caso
		// "nessun destinatario configurato" sia quello "indirizzo non valido".
		$to = WP_HEALTH_CHECK_ALERT_EMAIL;
		if ( ! is_email( $to ) ) {
			return;
		}

		// Throttle: non piu' di un avviso all'ora per non trasformare i retry
		// dell'enroll in un flood di email.
		if ( false !== get_transient( WPHC_Options::TRANSIENT_ENROLL_ALERT_SENT ) ) {
			return;
		}

		$site_home = home_url();

		/* translators: %s: URL del sito che ha rifiutato l'enroll. */
		$subject = sprintf( __( '[WP Health Check] Enroll fallito (URL mismatch) su %s', 'wp-health-check' ), $site_home );

		$lines   = array();
		$lines[] = __( 'Un tentativo di enroll e\' stato rifiutato: il site_url firmato dal sistema centrale non coincide con nessuno degli URL canonici del sito.', 'wp-health-check' );
		$lines[] = '';
		$lines[] = __( 'Sito:', 'wp-health-check' ) . ' ' . $site_home;
		$lines[] = __( 'URL ricevuto:', 'wp-health-check' ) . ' ' . $received;
		$lines[] = __( 'URL atteso (principale):', 'wp-health-check' ) . ' ' . $expected;
		$lines[] = __( 'IP chiamante:', 'wp-health-check' ) . ' ' . WPHC_Util::get_client_ip();
		$lines[] = __( 'Quando (UTC):', 'wp-health-check' ) . ' ' . gmdate( 'c' );
		$lines[] = '';
		$lines[] = __( 'URL validi per l\'enroll (il centro deve firmare uno di questi):', 'wp-health-check' );
		foreach ( $candidates as $candidate ) {
			$lines[] = '  - ' . $candidate;
		}
		$lines[] = '';
		$lines[] = __( 'Suggerimento: verificare www/non-www, http/https ed eventuali riscritture del dominio (es. plugin multilingua). Se il plugin e\' aggiornato, il confronto tollera automaticamente le varianti www/non-www.', 'wp-health-check' );

		$sent = wp_mail( $to, $subject, implode( "\n", $lines ) );

		if ( $sent ) {
			set_transient( WPHC_Options::TRANSIENT_ENROLL_ALERT_SENT, gmdate( 'c' ), HOUR_IN_SECONDS );
		}
	}

	// -----------------------------------------------------------------------
	// CALLBACK: POST /enroll
	// -----------------------------------------------------------------------

	/**
	 * Bootstrap firmato: consegna al sito il proprio token la prima volta,
	 * senza toccare wp-config.php. Il sito non deriva mai il token (non
	 * possiede il MASTER_SECRET): lo riceve gia' calcolato e lo conserva
	 * come valore opaco.
	 *
	 * Due formati, distinti dal campo "protocol" del body (default 1 se assente):
	 * - protocol 1 (storico): una ripetizione dell'enroll con lo stesso URL
	 *   produce sempre lo stesso token (derivazione deterministica lato centro),
	 *   quindi un replay su un sito al protocollo 1 riscrive lo stesso valore.
	 *   Dalla 1.34.0 (A-1, review 2026-10-03) anche la busta v1 deve rientrare
	 *   nella finestra di freschezza di issued_at, e un sito gia' al protocollo
	 *   2 la rifiuta con 409 wphc_enroll_downgrade: il replay lo riportava al
	 *   token derivato, annullando la rotazione.
	 * - protocol 2 (dalla 1.30.0, §3.7/§5.B): il token non e' piu' derivato ma
	 *   assegnato dal centro e ruotabile, quindi un replay di una busta vecchia
	 *   potrebbe riportare il sito a un segreto gia' ruotato. Aggiunge percio'
	 *   "nonce" al payload e una finestra di freschezza su "issued_at"
	 *   (+/- WP_HEALTH_CHECK_REPLAY_WINDOW secondi): qui la busta e' quindi
	 *   single-use quanto le rotte /rotate e /revoke.
	 *
	 * @param WP_REST_Request $request Richiesta REST con il payload di enroll.
	 * @return WP_REST_Response|WP_Error Esito dell'enroll.
	 */
	public static function route_enroll( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			WPHC_Guard::throttle_register_failure();
			self::record_enroll_error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), '' );

			return new WP_Error( 'wphc_enroll_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		// URL inviato, se presente: usato solo per diagnostica nel log errori.
		$reported_site_url = isset( $body['site_url'] ) ? (string) $body['site_url'] : '';

		// 1. Presenza dei campi obbligatori (dashboard_origin e' l'unico campo
		// sempre opzionale/nullable; "nonce" e' obbligatorio solo in protocol 2).
		$protocol        = isset( $body['protocol'] ) ? (int) $body['protocol'] : 1;
		$required_fields = array( 'site_url', 'token', 'issued_at', 'signature' );
		if ( $protocol >= 2 ) {
			$required_fields[] = 'nonce';
		}

		foreach ( $required_fields as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				WPHC_Guard::throttle_register_failure();
				/* translators: %s: nome del campo mancante. */
				$reason = sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field );
				self::record_enroll_error( 'wphc_enroll_missing_field', $reason, $reported_site_url );

				return new WP_Error( 'wphc_enroll_missing_field', $reason, array( 'status' => 400 ) );
			}
		}

		$site_url         = (string) $body['site_url'];
		$token            = (string) $body['token'];
		$dashboard_origin = array_key_exists( 'dashboard_origin', $body ) ? $body['dashboard_origin'] : null;
		$issued_at        = (int) $body['issued_at'];
		$signature        = (string) $body['signature'];
		$kid              = isset( $body['kid'] ) ? sanitize_key( (string) $body['kid'] ) : 'k1';
		$nonce            = isset( $body['nonce'] ) ? (string) $body['nonce'] : '';

		// 2. Freschezza per entrambi i protocolli (dalla 1.34.0 anche per v1,
		// A-1 della review 2026-10-03: una busta v1 catturata restava valida per
		// sempre) e anti-replay del nonce, solo per protocol 2.
		if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
			WPHC_Guard::throttle_register_failure();
			self::record_enroll_error( 'wphc_enroll_stale', __( 'Busta di enroll scaduta (issued_at fuori dalla finestra di freschezza).', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_stale', __( 'Richiesta di enroll scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( $protocol >= 2 ) {
			if ( WPHC_Guard::nonce_seen( $nonce ) ) {
				WPHC_Guard::throttle_register_failure();
				self::record_enroll_error( 'wphc_enroll_replay', __( 'Nonce di enroll gia\' utilizzato.', 'wp-health-check' ), $reported_site_url );

				return new WP_Error( 'wphc_enroll_replay', __( 'Richiesta di enroll gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
			}
		}

		// 3. Verifica della firma con la chiave pubblica incorporata nel file
		// (kid "k1" o "k2", vedi WPHC_Crypto::verify_central_signature()). Il messaggio
		// canonico dipende dal protocollo: protocol 1 e' il formato storico
		// (WPHC_Crypto::build_enroll_signing_payload()); protocol 2 aggiunge il prefisso
		// di dominio "enroll", il nonce e il numero di protocollo alla firma.
		$message = ( $protocol >= 2 )
			? WPHC_Crypto::build_signed_payload(
				'enroll',
				array(
					$site_url,
					$token,
					null === $dashboard_origin ? '' : (string) $dashboard_origin,
					$nonce,
					(string) $issued_at,
					(string) $protocol,
				)
			)
			: WPHC_Crypto::build_enroll_signing_payload( $site_url, $token, $dashboard_origin, $issued_at );

		if ( ! WPHC_Crypto::verify_central_signature( $message, $signature, $kid ) ) {
			// Messaggio unico e generico verso il chiamante: non distingue
			// "firma non valida" da "chiave malformata" o altro, per non offrire
			// informazioni utili a chi tenta richieste non autorizzate. La
			// diagnostica interna (tab admin) registra invece un motivo esplicito.
			WPHC_Guard::throttle_register_failure();
			self::record_enroll_error( 'wphc_enroll_unauthorized', __( 'Firma non valida (busta non prodotta dal sistema centrale).', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_unauthorized', __( 'Richiesta di enroll non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		// 3-bis. Niente downgrade (dalla 1.34.0, A-1): un sito gia' al protocollo 2
		// rifiuta le buste v1, che altrimenti lo riporterebbero al token derivato
		// (noto a chi possiede una vecchia busta) annullando la rotazione. Il
		// controllo segue la verifica della firma: lo stato del protocollo si
		// rivela solo a chi presenta una busta autentica del centro.
		if ( $protocol < 2 && (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 ) >= 2 ) {
			self::record_enroll_error( 'wphc_enroll_downgrade', __( 'Busta di enroll v1 rifiutata: il sito e\' gia\' al protocollo 2.', 'wp-health-check' ), $reported_site_url );

			return new WP_Error( 'wphc_enroll_downgrade', __( 'Il sito e\' al protocollo 2: enroll v1 non ammesso.', 'wp-health-check' ), array( 'status' => 409 ) );
		}

		// 4. Il payload firmato deve riguardare uno degli URL canonici di questo
		// sito: impedisce di riusare una busta firmata valida ma destinata a un
		// altro dominio della flotta contro un sito diverso. Il confronto e'
		// TOLLERANTE (set di candidati home/site/network_* x www/non-www,
		// tutti normalizzati) per non fallire su siti WPML, reverse proxy o
		// varianti www/non-www dove l'URL locale differisce da quello canonico
		// che il centro ha firmato. A questo punto la firma e' gia' valida:
		// $site_url e' autenticato (e' cio' che il centro ha firmato), non input
		// arbitrario. NB: il confronto non usa hash_equals perche' un URL non e'
		// un segreto; l'autenticazione e' la firma Ed25519, gia' verificata.
		$received_site_url   = $site_url;                       // grezzo: memorizzato e mostrato in diagnostica.
		$received_normalized = WPHC_Util::normalize_url( $site_url );  // normalizzato: solo per il confronto.
		$candidates          = WPHC_Util::candidate_site_urls();

		if ( ! in_array( $received_normalized, $candidates, true ) ) {
			$canonical_home = WPHC_Util::normalize_site_url();
			if ( WP_DEBUG ) {
				error_log( sprintf( 'wp-health-check: enroll URL mismatch — atteso: %1$s ricevuto: %2$s', $canonical_home, $received_site_url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			$mismatch_message = sprintf(
				/* translators: 1: URL canonico atteso, 2: URL ricevuto nella busta. */
				__( 'URL atteso: %1$s — ricevuto: %2$s', 'wp-health-check' ),
				$canonical_home,
				$received_site_url
			);
			// Registra il dettaglio sul sito (tab Site Health) e avvisa via email
			// l'operatore della flotta: entrambi utili a correggere l'URL usato.
			self::record_enroll_error( 'wphc_enroll_url_mismatch', $mismatch_message, $received_site_url );
			self::send_enroll_mismatch_alert( $canonical_home, $received_site_url, $candidates );

			return new WP_Error(
				'wphc_enroll_url_mismatch',
				$mismatch_message,
				array(
					'status'   => 403,
					'expected' => $canonical_home,
					'received' => $received_site_url,
				)
			);
		}

		// 5. Persistenza in wp_options (mai in wp-config.php). Si memorizza
		// ESATTAMENTE il site_url firmato ricevuto (autenticato dalla firma):
		// e' la chiave a cui il centro ha legato il token e che riusera' identica.
		update_option( WPHC_Options::OPT_TOKEN, $token, false );
		update_option( WPHC_Options::OPT_SITE_URL, $received_site_url, false );
		update_option( WPHC_Options::OPT_DASHBOARD_ORIGIN, $dashboard_origin, false );
		update_option( WPHC_Options::OPT_ENROLLED_AT, gmdate( 'c' ), false );
		update_option( WPHC_Options::OPT_ENROLLED_IP, WPHC_Util::get_client_ip(), false );
		// issued_at e' solo memorizzato come metadato operativo (non usato
		// per alcuna logica di scadenza, che per progetto non esiste).
		update_option( WPHC_Options::OPT_ENROLL_ISSUED_AT, $issued_at, false );
		update_option( WPHC_Options::OPT_SECRET_KID, $kid, false );
		if ( $protocol >= 2 ) {
			// Un enroll v2 riuscito porta gia' il sito a protocollo 2: le rotte
			// dati inizieranno a pretendere la firma anti-replay. Il nonce va
			// registrato solo ORA che la firma e' verificata (stesso principio
			// di WPHC_Guard::verify_request_signature()).
			update_option( WPHC_Options::OPT_PROTOCOL, 2, false );
			WPHC_Guard::remember_nonce( $nonce );
		}
		// Enroll riuscito: azzera l'eventuale ultimo errore diagnostico.
		delete_option( WPHC_Options::OPT_LAST_ENROLL_ERROR );

		// 6. Conferma: si restituisce il site_url firmato realmente registrato.
		return rest_ensure_response(
			array(
				'enrolled'      => true,
				'site'          => $received_site_url,
				'agent_version' => WP_HEALTH_CHECK_VERSION,
			)
		);
	}

	// -----------------------------------------------------------------------
	// CALLBACK: POST /rotate
	// -----------------------------------------------------------------------

	/**
	 * Rotazione firmata del segreto (dalla 1.30.0, §5.B): il centro consegna un
	 * segreto nuovo; il sito sposta l'attuale in wp_health_check_token_prev (per
	 * la finestra di grazia, vedi WPHC_Auth::require_token()) e adotta il nuovo come
	 * wp_health_check_token. Autenticazione identica a /enroll: firma Ed25519
	 * del centro verificata qui dentro, MAI un permission_callback basato sul
	 * token corrente (il sito non deve poter rifiutare la propria rotazione solo
	 * perche' e' proprio il token in uso quello che sta per essere sostituito).
	 *
	 * Messaggio canonico: "rotate" \n site_url \n new_token \n nonce \n
	 * issued_at \n protocol. Richiede un enrollment gia' completato: non e' un
	 * bootstrap, e' un'operazione su un sito gia' noto al centro.
	 *
	 * @param WP_REST_Request $request Richiesta REST con il payload di rotazione.
	 * @return WP_REST_Response|WP_Error Esito della rotazione.
	 */
	public static function route_rotate( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		$current_token = get_option( WPHC_Options::OPT_TOKEN );
		if ( empty( $current_token ) ) {
			return new WP_Error(
				'wphc_not_enrolled',
				__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
				array( 'status' => 503 )
			);
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		foreach ( array( 'site_url', 'new_token', 'nonce', 'issued_at', 'signature' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				WPHC_Guard::throttle_register_failure();
				return new WP_Error(
					'wphc_rotate_missing_field',
					/* translators: %s: nome del campo mancante. */
					sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field ),
					array( 'status' => 400 )
				);
			}
		}

		$site_url  = (string) $body['site_url'];
		$new_token = (string) $body['new_token'];
		$nonce     = (string) $body['nonce'];
		$issued_at = (int) $body['issued_at'];
		$signature = (string) $body['signature'];
		$kid       = isset( $body['kid'] ) ? sanitize_key( (string) $body['kid'] ) : 'k1';
		$protocol  = isset( $body['protocol'] ) ? (int) $body['protocol'] : 2;

		if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_stale', __( 'Richiesta di rotazione scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( WPHC_Guard::nonce_seen( $nonce ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_replay', __( 'Richiesta di rotazione gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		$message = WPHC_Crypto::build_signed_payload( 'rotate', array( $site_url, $new_token, $nonce, (string) $issued_at, (string) $protocol ) );
		if ( ! WPHC_Crypto::verify_central_signature( $message, $signature, $kid ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_rotate_unauthorized', __( 'Richiesta di rotazione non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		// La busta e' valida per QUESTO sito solo se riguarda uno dei suoi URL
		// canonici — stesso confronto tollerante gia' usato da /enroll, per non
		// rifiutare a torto su siti WPML/reverse proxy/varianti www.
		if ( ! in_array( WPHC_Util::normalize_url( $site_url ), WPHC_Util::candidate_site_urls(), true ) ) {
			return new WP_Error(
				'wphc_rotate_url_mismatch',
				__( 'URL della busta di rotazione non corrispondente a questo sito.', 'wp-health-check' ),
				array( 'status' => 403 )
			);
		}

		WPHC_Guard::remember_nonce( $nonce );

		// Sposta l'attuale in _prev (apre la finestra di grazia) e adotta il nuovo.
		update_option( WPHC_Options::OPT_TOKEN_PREV, $current_token, false );
		update_option( WPHC_Options::OPT_TOKEN_ROTATED_AT, time(), false );
		update_option( WPHC_Options::OPT_TOKEN, $new_token, false );
		update_option( WPHC_Options::OPT_PROTOCOL, max( 2, $protocol ), false );
		update_option( WPHC_Options::OPT_SECRET_KID, $kid, false );

		wphc_log_update_row( WPHC_Util::generate_correlation_id(), 'token', 'rotation', __( 'Segreto ruotato dal sistema centrale', 'wp-health-check' ), null, null, 'completed' );

		return rest_ensure_response(
			array(
				'rotated'       => true,
				'site'          => $site_url,
				'protocol'      => (int) get_option( WPHC_Options::OPT_PROTOCOL, 2 ),
				'grace_seconds' => WP_HEALTH_CHECK_ROTATION_GRACE,
			)
		);
	}

	// -----------------------------------------------------------------------
	// CALLBACK: POST /revoke
	// -----------------------------------------------------------------------

	/**
	 * Revoca firmata del segreto corrente (dalla 1.30.0, §5.B): cancella sia il
	 * segreto corrente che l'eventuale precedente e registra il timestamp di
	 * revoca, cosi' WPHC_Auth::require_token() rifiuta ogni chiamata successiva con
	 * 403 indipendentemente dal token fornito. Invalida anche i token di
	 * autologin gia' emessi e non ancora consumati (tramite l'elenco tenuto da
	 * WPHC_Autologin::track_pending()): un segreto compromesso non deve poter
	 * aprire wp-admin tramite un token pendente.
	 *
	 * Autenticazione identica a /enroll e /rotate: firma Ed25519 del centro.
	 * Messaggio canonico: "revoke" \n site_url \n nonce \n issued_at.
	 *
	 * @param WP_REST_Request $request Richiesta REST con il payload di revoca.
	 * @return WP_REST_Response|WP_Error Esito della revoca.
	 */
	public static function route_revoke( WP_REST_Request $request ) {
		WPHC_Cors::maybe_send_headers();

		$throttled = WPHC_Guard::throttle_check();
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$https_check = WPHC_Guard::require_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		if ( empty( get_option( WPHC_Options::OPT_TOKEN ) ) ) {
			return new WP_Error(
				'wphc_not_enrolled',
				__( 'Sito non ancora registrato presso il sistema centrale (enroll mancante).', 'wp-health-check' ),
				array( 'status' => 503 )
			);
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_invalid_body', __( 'Corpo della richiesta non valido o non JSON.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		foreach ( array( 'site_url', 'nonce', 'issued_at', 'signature' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				WPHC_Guard::throttle_register_failure();
				return new WP_Error(
					'wphc_revoke_missing_field',
					/* translators: %s: nome del campo mancante. */
					sprintf( __( 'Campo obbligatorio mancante: %s', 'wp-health-check' ), $field ),
					array( 'status' => 400 )
				);
			}
		}

		$site_url  = (string) $body['site_url'];
		$nonce     = (string) $body['nonce'];
		$issued_at = (int) $body['issued_at'];
		$signature = (string) $body['signature'];
		$kid       = isset( $body['kid'] ) ? sanitize_key( (string) $body['kid'] ) : 'k1';

		if ( abs( time() - $issued_at ) > WP_HEALTH_CHECK_REPLAY_WINDOW ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_stale', __( 'Richiesta di revoca scaduta.', 'wp-health-check' ), array( 'status' => 401 ) );
		}
		if ( WPHC_Guard::nonce_seen( $nonce ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_replay', __( 'Richiesta di revoca gia\' processata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		$message = WPHC_Crypto::build_signed_payload( 'revoke', array( $site_url, $nonce, (string) $issued_at ) );
		if ( ! WPHC_Crypto::verify_central_signature( $message, $signature, $kid ) ) {
			WPHC_Guard::throttle_register_failure();
			return new WP_Error( 'wphc_revoke_unauthorized', __( 'Richiesta di revoca non autorizzata.', 'wp-health-check' ), array( 'status' => 401 ) );
		}

		if ( ! in_array( WPHC_Util::normalize_url( $site_url ), WPHC_Util::candidate_site_urls(), true ) ) {
			return new WP_Error(
				'wphc_revoke_url_mismatch',
				__( 'URL della busta di revoca non corrispondente a questo sito.', 'wp-health-check' ),
				array( 'status' => 403 )
			);
		}

		WPHC_Guard::remember_nonce( $nonce );

		update_option( WPHC_Options::OPT_REVOKED_AT, gmdate( 'c' ), false );
		delete_option( WPHC_Options::OPT_TOKEN_PREV );
		delete_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );

		// Invalida ogni token di autologin gia' emesso e non ancora consumato:
		// delete_transient() (non una query diretta su wp_options) e' l'unico
		// modo corretto di rimuovere un transient anche quando il sito usa un
		// object cache persistente (Redis/Memcached).
		$pending_autologins = (array) get_option( WPHC_Options::OPT_AUTOLOGIN_PENDING, array() );
		foreach ( $pending_autologins as $pending_key ) {
			delete_transient( $pending_key );
		}
		delete_option( WPHC_Options::OPT_AUTOLOGIN_PENDING );

		wphc_log_update_row( WPHC_Util::generate_correlation_id(), 'token', 'revocation', __( 'Segreto revocato dal sistema centrale', 'wp-health-check' ), null, null, 'completed' );

		return rest_ensure_response(
			array(
				'revoked' => true,
				'site'    => $site_url,
			)
		);
	}
}
