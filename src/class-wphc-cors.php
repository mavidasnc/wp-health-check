<?php
/**
 * Header CORS e preflight OPTIONS delle rotte health-check/v1.
 *
 * @package WP_Health_Check
 */

/**
 * Header CORS delle rotte del namespace health-check/v1. Con l'origin della dashboard configurata (option
 * wp_health_check_dashboard_origin) viene autorizzata solo quell'origin esatta; senza, nessun header CORS.
 * Dice anche ai plugin di page cache di non mettere in cache la risposta.
 *
 * Raccoglie la sezione D del file 1.38.1. Hook serviti (registrati in
 * WPHC_Plugin::boot()): filtro rest_pre_serve_request con priorita' 20 e 3 argomenti
 * (reassert_headers) e filtro rest_pre_dispatch con 3 argomenti (handle_options_preflight).
 */
final class WPHC_Cors {

	/**
	 * Invia gli header CORS. Se wp_health_check_dashboard_origin e' configurata,
	 * SOLO quell'origin esatta viene autorizzata. Dalla 1.36.0 (B-21 della
	 * review 2026-10-11), se e' vuota (dashboard non registrata via /enroll, o
	 * resettata con wp health-check reset) NON si invia alcun header CORS:
	 * prima si rifletteva qualunque origin, scelta pensata per il setup ma che in
	 * produzione, dove l'hub non imposta HEALTHCHECK_DASHBOARD_ORIGIN, esponeva
	 * ogni sito a qualunque pagina web. Nessun consumatore ne dipende: la
	 * dashboard parla con i siti solo tramite l'hub (server-to-server).
	 * Non viene mai inviato il wildcard letterale "*": l'origin della richiesta
	 * viene riflessa solo se coincide con quella configurata.
	 * L'autenticazione resta comunque affidata al bearer token o alla firma
	 * Ed25519 (vedi WPHC_Auth::require_token()): CORS qui e' difesa in profondita'
	 * aggiuntiva, non il controllo di accesso primario.
	 *
	 * IMPORTANTE #1 (cache): dice ANCHE a WordPress (e ai plugin di page-cache
	 * come LiteSpeed Cache, WP Super Cache, W3TC, WP Rocket...) di non mettere
	 * mai in cache questa risposta. Senza questo, una cache condivisa lato
	 * server puo' salvare la risposta di UN chiamante (con il SUO Origin, o
	 * senza Origin affatto) e riservirla identica a chiunque altro, ignorando
	 * sia l'Origin reale del richiedente sia il bearer token: e' esattamente
	 * la causa di risposte CORS incoerenti osservate in produzione dietro
	 * LiteSpeed Cache. La cache "propria" del plugin resta quella via
	 * transient nelle singole rotte (vedi Caching per-rotta in README.md), non
	 * influenzata da questo.
	 *
	 * IMPORTANTE #2 (default del core): WordPress core registra di serie
	 * rest_send_cors_headers() sul filtro rest_pre_serve_request, che riflette
	 * QUALUNQUE Origin con Access-Control-Allow-Credentials: true, per l'intera
	 * REST API. Quel filtro gira DOPO il dispatch della rotta (quindi dopo la
	 * prima chiamata a questa funzione), e header() di PHP sostituisce di
	 * default un header con lo stesso nome: senza rimuovere prima gli header
	 * eventualmente gia' impostati dal core, la restrizione su
	 * wp_health_check_dashboard_origin sarebbe vanificata. Per questo la
	 * funzione va richiamata una seconda volta, con priorita' piu' alta, su
	 * rest_pre_serve_request stesso: vedi WPHC_Cors::reassert_headers().
	 */
	public static function maybe_send_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Nome imposto dalla convenzione condivisa fra i plugin di
			// page-cache (LiteSpeed Cache, WP Super Cache, W3TC, WP Rocket...):
			// non puo' avere il prefisso wphc_/WP_HEALTH_CHECK, o quei plugin
			// non lo riconoscerebbero piu'.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		nocache_headers();

		// Rimuove eventuali header CORS gia' impostati (tipicamente dal
		// rest_send_cors_headers() di default del core, vedi sopra): questa
		// funzione deve avere sempre l'ultima parola su questi header.
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header_remove( 'Access-Control-Allow-Methods' );
		header_remove( 'Access-Control-Allow-Headers' );
		header_remove( 'Access-Control-Expose-Headers' );

		$request_origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' === $request_origin ) {
			return;
		}

		// Senza origin configurata nessun header CORS (B-21); con origin
		// configurata, solo quella esatta. Il nome della classe e' qualificato con il
		// backslash iniziale: i test di caratterizzazione eseguono il corpo di questo
		// metodo in un altro namespace per leggere gli header (vedi capture_cors() in
		// tests/class-wphc-helpers-read.php) e un nome non qualificato non si
		// risolverebbe. In produzione il backslash e' equivalente a nessun backslash.
		$configured_origin = get_option( \WPHC_Options::OPT_DASHBOARD_ORIGIN );
		if ( empty( $configured_origin ) || $request_origin !== $configured_origin ) {
			return;
		}

		header( 'Access-Control-Allow-Origin: ' . $request_origin );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		// X-WPHC-*: header di firma anti-replay del protocollo v2 (dalla 1.30.0),
		// vedi WPHC_Guard::verify_request_signature().
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature' );
		// Vary: Origin evita che una cache intermedia (CDN/proxy) serva la
		// risposta CORS di un'origin ad un'altra origin diversa.
		header( 'Vary: Origin' );
	}

	/**
	 * Riapplica WPHC_Cors::maybe_send_headers() su rest_pre_serve_request, con
	 * priorita' piu' alta del rest_send_cors_headers() di default del core
	 * (priorita' 10): senza questo, il comportamento permissivo del core
	 * (qualunque Origin, con credenziali) vincerebbe sempre sulle regole di
	 * wp_health_check_dashboard_origin, perche' gira dopo il dispatch della
	 * rotta. Limitata al solo namespace health-check/v1, per non interferire
	 * con le altre rotte REST del sito.
	 *
	 * @param bool            $served  Valore corrente del filtro (non alterato).
	 * @param mixed           $result  Risultato della richiesta (non usato).
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return bool Il valore $served invariato.
	 */
	public static function reassert_headers( $served, $result, $request ) {
		unset( $result );

		if ( 0 !== strpos( $request->get_route(), '/health-check/v1' ) ) {
			return $served;
		}

		self::maybe_send_headers();

		return $served;
	}

	/**
	 * Intercetta le richieste OPTIONS (preflight CORS) dirette al namespace
	 * health-check/v1 PRIMA che WordPress le instradi verso una rotta reale,
	 * e risponde 200 con i soli header CORS, senza alcuna autenticazione:
	 * il preflight del browser non include mai l'header Authorization.
	 *
	 * Agganciato su rest_pre_dispatch: restituire un valore non-null da
	 * questo filtro interrompe il dispatch normale della REST API.
	 *
	 * @param mixed           $result  Risultato corrente (null se non ancora gestito).
	 * @param WP_REST_Server  $server  Istanza del server REST (non usata).
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return mixed Il risultato originale, oppure una WP_REST_Response per le OPTIONS.
	 */
	public static function handle_options_preflight( $result, $server, $request ) {
		unset( $server );

		if ( 'OPTIONS' !== $request->get_method() ) {
			return $result;
		}
		if ( 0 !== strpos( $request->get_route(), '/health-check/v1' ) ) {
			return $result;
		}

		self::maybe_send_headers();

		return new WP_REST_Response( null, 200 );
	}
}
