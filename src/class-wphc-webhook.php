<?php
/**
 * Trasporto del webhook di notifica firmato: URL, firma HMAC, invio, esito e retry (cron).
 *
 * @package WP_Health_Check
 */

/**
 * Trasporto generico del webhook di notifica verso la dashboard: URL configurato (con default di flotta), firma
 * HMAC-SHA256 speculare a quella del protocollo v2 in ingresso, invio con intestazioni X-WPHC-*, registrazione
 * dell'ultimo esito e ripianificazione dei tentativi falliti.
 *
 * Raccoglie la sezione O del file 1.38.1 tranne le due funzioni che conoscono il documento del job bulk
 * (payload e invio del report di fine job), che stanno in WPHC_Bulk. Hook servito (registrato per ora in
 * legacy.php, dal lotto 4 in WPHC_Plugin::boot()): evento cron wphc_webhook_retry, che esegue maybe_retry().
 * dispatch_payload() su un sito revocato cancella l'option pendente e gli eventi di retry.
 */
final class WPHC_Webhook {

	// -----------------------------------------------------------------------
	// WEBHOOK DI NOTIFICA FIRMATO (a fine job bulk, o su richiesta di prova)
	// -----------------------------------------------------------------------
	//
	// Firma HMAC-SHA256 speculare (in uscita) allo schema di verifica del
	// protocollo v2 in ingresso (WPHC_Guard::verify_request_signature()): stessa
	// primitiva, stesso segreto di sito, cosi' il ricevente puo' riusare la
	// stessa logica di verifica gia' scritta per l'ingresso.

	/**
	 * URL del webhook configurato, o stringa vuota se il feature e' spento.
	 * Se il sito non ha impostato un URL proprio, ricade sul default di flotta
	 * (WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL) a meno che l'operatore non abbia
	 * spuntato esplicitamente "disattiva webhook" in wp-admin: un campo vuoto,
	 * da solo, non basta piu' a significare "spento" ora che esiste un default.
	 *
	 * @return string
	 */
	public static function url() {
		$url = (string) get_option( WPHC_Options::OPT_WEBHOOK_URL, '' );
		if ( '' !== $url ) {
			return $url;
		}
		if ( get_option( WPHC_Options::OPT_WEBHOOK_DISABLED, false ) ) {
			return '';
		}
		return WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL;
	}

	/**
	 * Guardia di salvataggio (difesa in profondita', non un confine di
	 * sicurezza assoluto: nessun re-check DNS all'invio, TOCTOU-vulnerabile e
	 * non affidabile su DNS split-horizon legittimo). wp_http_validate_url() e'
	 * lo stesso validatore che l'HTTP API di WP applica gia' alle richieste
	 * sicure; lo scheme https e' verificato a parte perche' quel validatore da
	 * solo accetta anche http; se l'host e' un IP letterale deve essere
	 * pubblico (WPHC_Util::ip_is_public(), gia' esistente - riusata, non riscritta).
	 *
	 * @param string $url URL da validare.
	 * @return bool
	 */
	public static function url_is_allowed( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! WPHC_Util::ip_is_public( $host ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Costruisce la firma HMAC-SHA256 di una POST in uscita. La stringa firmata
	 * usa $event al posto di un path di rotta (che non esiste per un POST in
	 * uscita): fornisce comunque separazione di dominio, mentre l'identita' del
	 * sito viaggia dentro il body firmato ('site'), non in uno slot separato.
	 *
	 * @param string $event     Nome evento (es. 'wphc.bulk_update.completed').
	 * @param string $body_json Corpo JSON gia' codificato UNA sola volta:
	 *                           l'hash e l'invio devono usare esattamente la
	 *                           stessa stringa, mai una ri-codifica separata.
	 * @param string $secret    Segreto di sito (wp_health_check_token).
	 * @return array{timestamp:int,nonce:string,signature:string}
	 */
	public static function build_outbound_signature( $event, $body_json, $secret ) {
		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$canonical = "POST\n{$event}\n" . hash( 'sha256', $body_json ) . "\n{$timestamp}\n{$nonce}";
		$signature = WPHC_Crypto::base64url_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );

		return array(
			'timestamp' => $timestamp,
			'nonce'     => $nonce,
			'signature' => $signature,
		);
	}

	/**
	 * Backoff (secondi) per il tentativo di webhook numero $attempt.
	 *
	 * @param int $attempt Numero di tentativo (1-based).
	 * @return int
	 */
	public static function backoff_for_attempt( $attempt ) {
		$table = WP_HEALTH_CHECK_WEBHOOK_BACKOFF;
		$index = max( 0, min( (int) $attempt, count( $table ) - 1 ) );
		return (int) $table[ $index ];
	}

	/**
	 * Registra l'ultimo esito di consegna del webhook, stesso pattern di
	 * wp_health_check_thumb_error: array con 'at'/codice macchina/messaggio
	 * troncato, mai l'URL completo (solo l'host).
	 *
	 * @param bool        $ok          Esito.
	 * @param int         $code        Codice HTTP (0 se errore di trasporto).
	 * @param string|null $error       Codice macchina dell'errore, null se ok.
	 * @param string      $message     Messaggio (troncato a 500 char).
	 * @param string      $url         URL di destinazione (solo l'host viene salvato).
	 * @param string      $job_id      Id del job (o 'test-...' per un invio di prova).
	 * @param int         $attempt     Numero di tentativo.
	 * @param int|null    $duration_ms Durata della richiesta in millisecondi.
	 */
	private static function record_result( $ok, $code, $error, $message, $url, $job_id, $attempt, $duration_ms ) {
		$retry_at = null;
		if ( ! $ok && in_array( $error, array( 'wphc_webhook_transport_error', 'wphc_webhook_http_error' ), true ) && $attempt < WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
			$retry_at = gmdate( 'c', time() + self::backoff_for_attempt( $attempt ) );
		}

		update_option(
			WPHC_Options::OPT_WEBHOOK_LAST,
			array(
				'at'          => gmdate( 'c' ),
				'ok'          => (bool) $ok,
				'job_id'      => $job_id,
				'attempt'     => $attempt,
				'code'        => $code,
				'error'       => $error,
				'message'     => mb_substr( (string) $message, 0, 500 ),
				'url_host'    => wp_parse_url( $url, PHP_URL_HOST ),
				'duration_ms' => $duration_ms,
				'retry_at'    => $retry_at,
			),
			false
		);
	}

	/**
	 * Invia (o tenta di inviare) una POST firmata verso il webhook configurato.
	 * Gestisce, in ordine, la degradazione a "nessun invio, nessun errore" (non
	 * configurato), a errore esplicito (non enrollato / revocato / URL non
	 * ammesso), poi il trasporto vero e proprio. Un sito revocato tace del
	 * tutto (stessa scelta di WPHC_Auth::require_token() in ingresso) e cancella
	 * qualunque retry pendente.
	 *
	 * @param string $event   Nome evento.
	 * @param array  $payload Corpo del report (verra' codificato una volta sola).
	 * @param string $job_id  Id del job, per idempotenza lato ricevente (header X-WPHC-Delivery).
	 * @param int    $attempt Numero di tentativo (1-based).
	 * @return array{ok:bool,code:int,error:string|null,retryable:bool}
	 */
	public static function dispatch_payload( $event, array $payload, $job_id, $attempt ) {
		$url = self::url();
		if ( '' === $url ) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => null,
				'retryable' => false,
			);
		}

		$secret = get_option( WPHC_Options::OPT_TOKEN );
		if ( empty( $secret ) ) {
			self::record_result( false, 0, 'wphc_webhook_not_enrolled', '', $url, $job_id, $attempt, null );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_not_enrolled',
				'retryable' => false,
			);
		}

		if ( get_option( WPHC_Options::OPT_REVOKED_AT ) ) {
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			wp_clear_scheduled_hook( WPHC_Options::CRON_WEBHOOK_RETRY );
			self::record_result( false, 0, 'wphc_webhook_revoked', '', $url, $job_id, $attempt, null );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_revoked',
				'retryable' => false,
			);
		}

		if ( ! self::url_is_allowed( $url ) ) {
			self::record_result( false, 0, 'wphc_webhook_url_blocked', '', $url, $job_id, $attempt, null );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_url_blocked',
				'retryable' => false,
			);
		}

		$body_json = wp_json_encode( $payload );
		$sig       = self::build_outbound_signature( $event, $body_json, $secret );

		$headers = array(
			'Content-Type'      => 'application/json; charset=utf-8',
			'Accept'            => 'application/json',
			'User-Agent'        => 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION,
			'X-WPHC-Timestamp'  => (string) $sig['timestamp'],
			'X-WPHC-Nonce'      => $sig['nonce'],
			'X-WPHC-Signature'  => $sig['signature'],
			'X-WPHC-Event'      => $event,
			'X-WPHC-Secret-Kid' => (string) get_option( WPHC_Options::OPT_SECRET_KID, '' ),
			'X-WPHC-Site'       => WPHC_Util::normalize_site_url(),
			'X-WPHC-Delivery'   => (string) $job_id,
			'X-WPHC-Attempt'    => (string) $attempt,
		);

		$started = microtime( true );
		// wp_safe_remote_post (B-21): oltre all'allowlist sugli IP letterali di
		// WPHC_Webhook::url_is_allowed(), WordPress rifiuta host che risolvono su IP
		// privati o riservati e porte non standard, anche se l'URL usa un nome DNS.
		$response    = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => WP_HEALTH_CHECK_WEBHOOK_TIMEOUT,
				'redirection' => 0, // Una 30x non deve far ripostare il body firmato altrove.
				'sslverify'   => true, // Esplicito: e' anche il default, non abbassarlo MAI.
				'headers'     => $headers,
				'body'        => $body_json,
			)
		);
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			self::record_result( false, 0, 'wphc_webhook_transport_error', $message, $url, $job_id, $attempt, $duration_ms );
			return array(
				'ok'        => false,
				'code'      => 0,
				'error'     => 'wphc_webhook_transport_error',
				'retryable' => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			self::record_result( true, $code, null, '', $url, $job_id, $attempt, $duration_ms );
			return array(
				'ok'        => true,
				'code'      => $code,
				'error'     => null,
				'retryable' => false,
			);
		}

		// Retry solo su errori transitori: un 4xx (a parte 408/429) significa
		// che il ricevente ha rifiutato la richiesta, ripeterla non risolve nulla.
		$retryable = ( 408 === $code || 429 === $code || $code >= 500 );
		$message   = mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		self::record_result( false, $code, 'wphc_webhook_http_error', $message, $url, $job_id, $attempt, $duration_ms );

		return array(
			'ok'        => false,
			'code'      => $code,
			'error'     => 'wphc_webhook_http_error',
			'retryable' => $retryable,
		);
	}

	/**
	 * Cron callback di retry per un webhook fallito. Ri-firma sempre con
	 * timestamp/nonce freschi sullo STESSO body (la finestra di freschezza
	 * ±300s del protocollo scadrebbe altrimenti prima del retry).
	 */
	public static function maybe_retry() {
		$pending = get_option( WPHC_Options::OPT_WEBHOOK_PENDING );
		if ( ! is_array( $pending ) ) {
			return;
		}
		if ( false !== get_transient( WPHC_Options::TRANSIENT_WEBHOOK_RETRY_LOCK ) ) {
			return;
		}
		set_transient( WPHC_Options::TRANSIENT_WEBHOOK_RETRY_LOCK, time(), 5 * MINUTE_IN_SECONDS );

		$attempt = (int) $pending['attempts'] + 1;
		$result  = self::dispatch_payload( $pending['event'], $pending['body'], $pending['job_id'], $attempt );

		if ( $result['ok'] || ! $result['retryable'] || $attempt >= WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
		} else {
			$pending['attempts'] = $attempt;
			update_option( WPHC_Options::OPT_WEBHOOK_PENDING, $pending, false );
			wp_schedule_single_event( time() + self::backoff_for_attempt( $attempt ), WPHC_Options::CRON_WEBHOOK_RETRY );
		}

		delete_transient( WPHC_Options::TRANSIENT_WEBHOOK_RETRY_LOCK );
	}
}
