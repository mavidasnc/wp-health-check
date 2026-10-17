<?php
/**
 * Utility condivise: normalizzazione degli URL, IP del client e del server, IP pubblico, short-circuit degli update, correlation id.
 *
 * @package WP_Health_Check
 */

/**
 * Utility condivise del plugin, usate da quasi tutte le altre classi: normalizzazione degli URL con la
 * stessa regola del sistema centrale, determinazione dell'IP del chiamante (con o senza proxy fidato),
 * IP del server e IP pubblico di uscita, neutralizzazione temporanea degli short-circuit dei transient di
 * update e identificativo di correlazione delle operazioni di update.
 *
 * Raccoglie, dal file unico 1.38.1, le sezioni C (URL e IP) e I (short-circuit di $wp_filter) e la funzione
 * generate_correlation_id() della sezione M. Nessun hook, nessuno stato proprio.
 */
final class WPHC_Util {

	/**
	 * Normalizza un URL qualsiasi con la STESSA regola del sistema centrale,
	 * cosi' che il confronto lato sito e il calcolo dell'HMAC del token lato
	 * centro operino byte per byte sullo stesso materiale: schema e host in
	 * minuscolo, porta inclusa solo se presente/non standard, path invariato
	 * senza slash finale, niente query ne' fragment. Vedi README.md per
	 * l'esempio numerico completo URL -> token.
	 *
	 * @param string $url URL grezzo da normalizzare.
	 * @return string URL normalizzato, oppure stringa vuota se non parsabile.
	 */
	public static function normalize_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';

		return untrailingslashit( $scheme . '://' . $host . $port . $path );
	}

	/**
	 * URL normalizzato del sito corrente, usato come identificativo "site"
	 * nelle risposte e come URL canonico "atteso" nei messaggi di mismatch.
	 * E' semplicemente WPHC_Util::normalize_url() applicato a home_url().
	 *
	 * @return string URL normalizzato (es. "https://esempio.com/blog").
	 */
	public static function normalize_site_url() {
		return self::normalize_url( home_url() );
	}

	/**
	 * Costruisce l'insieme degli URL canonici plausibili di questo sito, usato
	 * per rendere tollerante il confronto in fase di enroll (siti WPML dove
	 * home_url() varia per lingua, reverse proxy, varianti www/non-www).
	 *
	 * Raccoglie home_url(), site_url(), network_home_url() e network_site_url()
	 * (site_url()/network_* non sono filtrate per lingua da WPML, quindi il set
	 * contiene sempre l'URL base canonico anche quando home_url() porta un
	 * prefisso di lingua), genera per ciascuna la variante con/senza "www." sul
	 * primo label dell'host, normalizza tutto e deduplica.
	 *
	 * @return string[] URL normalizzati candidati, senza duplicati.
	 */
	public static function candidate_site_urls() {
		$raw        = array( home_url(), site_url(), network_home_url(), network_site_url() );
		$candidates = array();

		foreach ( $raw as $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}

			$host     = strtolower( $parts['host'] );
			$alt_host = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : 'www.' . $host;
			$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
			$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			$path     = isset( $parts['path'] ) ? $parts['path'] : '';

			foreach ( array( $host, $alt_host ) as $variant_host ) {
				$normalized = self::normalize_url( $scheme . '://' . $variant_host . $port . $path );
				if ( '' !== $normalized ) {
					// Chiave dell'array = dedup automatica delle varianti coincidenti.
					$candidates[ $normalized ] = true;
				}
			}
		}

		return array_keys( $candidates );
	}

	/**
	 * Determina l'IP del chiamante. Per default legge REMOTE_ADDR (l'unico
	 * dato affidabile senza un proxy davanti a PHP). Se l'opzione
	 * wp_health_check_trust_proxy e' attiva, legge invece l'ULTIMO IP valido
	 * di X-Forwarded-For.
	 *
	 * ATTENZIONE (documentato anche in README): X-Forwarded-For e' un header
	 * HTTP fornito dal CLIENT e quindi falsificabile a piacere. E' attendibile
	 * SOLO se davanti a PHP c'e' un proxy/load balancer fidato che lo
	 * sovrascrive sempre (CDN, reverse proxy aziendale). Va attivato
	 * consapevolmente, non di default.
	 *
	 * Dalla 1.34.0 (A-10, review 2026-10-03) si prende l'IP piu' a destra: un
	 * proxy che accoda l'indirizzo del proprio client lo aggiunge in fondo,
	 * mentre i valori a sinistra arrivano dal client e sono quindi scelti da
	 * lui. Con il primo IP chiunque aggirava il throttle cambiando l'header.
	 *
	 * @return string IP valido, oppure stringa vuota se non determinabile.
	 */
	public static function get_client_ip() {
		$trust_proxy = (bool) get_option( WPHC_Options::OPT_TRUST_PROXY, false );

		if ( $trust_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff        = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidates = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
			foreach ( $candidates as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
	}

	/**
	 * Determina l'IP del server WordPress (la macchina che esegue PHP), letto
	 * da SERVER_ADDR. E' l'indirizzo su cui il web server ha accettato la
	 * connessione: dietro un reverse proxy / load balancer puo' essere l'IP
	 * interno del backend e non quello pubblico del sito, e su alcuni SAPI puo'
	 * non essere impostato affatto (in quel caso si restituisce stringa vuota).
	 * Non e' un dato di sicurezza, solo informativo per la dashboard.
	 *
	 * @return string IP valido del server, oppure stringa vuota se non determinabile.
	 */
	public static function get_server_ip() {
		$server_addr = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';

		return filter_var( $server_addr, FILTER_VALIDATE_IP ) ? $server_addr : '';
	}

	/**
	 * Vero se l'IP dato e' un indirizzo pubblico instradabile (non privato, non
	 * riservato, non loopback). Usata sia per decidere se SERVER_ADDR e' gia'
	 * sufficiente, sia per validare la risposta del servizio di risoluzione
	 * esterno prima di persisterla.
	 *
	 * @param string $ip Indirizzo IP da valutare.
	 * @return bool True se l'IP e' valido e pubblico.
	 */
	public static function ip_is_public( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Determina l'IP pubblico di uscita del server, per i casi (dietro reverse
	 * proxy / load balancer / container) in cui SERVER_ADDR e' un indirizzo di
	 * rete interna (es. 192.168.x.x) e quindi inutile per identificare il sito
	 * dall'esterno. Cascata a tre livelli, dal piu' economico al piu' costoso:
	 *
	 * 1. Se SERVER_ADDR e' gia' un IP pubblico, lo si riusa: costo zero, nessuna
	 *    query ne' chiamata remota. E' il caso della maggior parte degli hosting
	 *    condivisi, quindi copre il percorso caldo di /health.
	 * 2. Altrimenti si legge il transient wphc_public_ip (7 giorni): la scadenza
	 *    intercetta un eventuale cambio IP dopo una migrazione di hosting senza
	 *    tenere un dato stantio per sempre.
	 * 3. A transient scaduto, guardia anti-retry-loop (wphc_public_ip_retry_lock,
	 *    1 giorno, stesso pattern di wphc_thumb_retry_lock) e chiamata a un
	 *    servizio "echo IP" esterno (api.ipify.org). L'esito viene validato con
	 *    WPHC_Util::ip_is_public() prima di essere persistito.
	 *
	 * @return string IP pubblico valido, oppure stringa vuota se non determinabile.
	 */
	public static function get_public_ip() {
		$server_ip = self::get_server_ip();
		if ( '' !== $server_ip && self::ip_is_public( $server_ip ) ) {
			return $server_ip;
		}

		$cached = get_transient( WPHC_Options::TRANSIENT_PUBLIC_IP );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( false !== get_transient( WPHC_Options::TRANSIENT_PUBLIC_IP_RETRY_LOCK ) ) {
			return '';
		}

		// Impostato PRIMA del tentativo: se il servizio esterno fallisce, il lock
		// resta e blocca i ritentativi per il resto del TTL indipendentemente
		// dall'esito, evitando un retry-loop ad ogni /health.
		set_transient( WPHC_Options::TRANSIENT_PUBLIC_IP_RETRY_LOCK, time(), DAY_IN_SECONDS );

		$response = wp_remote_get( 'https://api.ipify.org', array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = trim( wp_remote_retrieve_body( $response ) );
		if ( ! self::ip_is_public( $body ) ) {
			return '';
		}

		set_transient( WPHC_Options::TRANSIENT_PUBLIC_IP, $body, 7 * DAY_IN_SECONDS );

		return $body;
	}

	// -----------------------------------------------------------------------
	// LETTURA ROBUSTA DEI TRANSIENT DI UPDATE
	// -----------------------------------------------------------------------

	/**
	 * Neutralizza temporaneamente gli short-circuit "pre_site_transient_update_*".
	 * Alcuni plugin/ottimizzazioni registrano FUORI dall'admin filtri come
	 * add_filter( 'pre_site_transient_update_plugins', '__return_null' ) per
	 * disabilitare i controlli di aggiornamento su frontend/REST: quel filtro fa
	 * si' che get_site_transient( 'update_plugins' ) restituisca null (cortocircuito
	 * del core), quindi in contesto REST i conteggi update risulterebbero 0 anche
	 * con aggiornamenti realmente presenti, a differenza di quanto vede
	 * l'amministratore (dove quei filtri di norma non sono attivi).
	 *
	 * Rimuove solo i filtri "pre_" (il cortocircuito): NON tocca i filtri di
	 * lettura "site_transient_update_*", cosi' le iniezioni legittime dei plugin
	 * premium (es. ACF, Gravity Forms) restano attive. Va sempre seguita da
	 * WPHC_Util::restore_update_shortcircuit() con il valore restituito.
	 *
	 * @return array<string, mixed> Filtri rimossi, da ripristinare.
	 */
	public static function mute_update_shortcircuit() {
		global $wp_filter;
		$hooks = array( 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'pre_site_transient_update_core' );
		$saved = array();
		foreach ( $hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$saved[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- rimozione temporanea e controllata, ripristinata subito da WPHC_Util::restore_update_shortcircuit().
			}
		}
		return $saved;
	}

	/**
	 * Ripristina gli short-circuit rimossi da WPHC_Util::mute_update_shortcircuit().
	 *
	 * @param array<string, mixed> $saved Valore restituito da WPHC_Util::mute_update_shortcircuit().
	 */
	public static function restore_update_shortcircuit( $saved ) {
		global $wp_filter;
		foreach ( $saved as $hook => $obj ) {
			$wp_filter[ $hook ] = $obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ripristino del valore salvato da WPHC_Util::mute_update_shortcircuit().
		}
	}

	/**
	 * Genera un identificativo di correlazione a 16 caratteri esadecimali, per
	 * legare la riga 'requested' e la riga finale della stessa operazione di
	 * update nella tabella di log.
	 *
	 * @return string Correlation id, 16 caratteri hex.
	 */
	public static function generate_correlation_id() {
		return bin2hex( random_bytes( 8 ) );
	}
}
