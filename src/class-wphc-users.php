<?php
/**
 * Anagrafica degli amministratori (GET /detail/users) e tracciamento dell'ultimo login.
 *
 * @package WP_Health_Check
 */

/**
 * Anagrafica degli utenti amministratori esposta da GET /detail/users e tracciamento di data e IP dell'ultimo
 * accesso di ciascun utente in due user meta (WPHC_Options::META_LAST_LOGIN e META_LAST_LOGIN_IP).
 *
 * Raccoglie, dal file 1.38.1, build_user_summary() e route_detail_users() (sezione K) e record_last_login()
 * (sezione R). Hook servito (registrato per ora in legacy.php): azione wp_login con 2 argomenti, che scatta
 * anche dall'autologin tramite do_action( 'wp_login' ).
 */
final class WPHC_Users {

	// -----------------------------------------------------------------------
	// CALLBACK: GET /detail/users
	// -----------------------------------------------------------------------

	/**
	 * Riga utente esposta da GET /detail/users. Il chiamante deve aver gia'
	 * innescato update_meta_cache('user', ...) per il gruppo di ID coinvolto,
	 * altrimenti le due get_user_meta() qui sotto eseguono una query per utente
	 * invece di leggere dalla cache gia' popolata in blocco.
	 *
	 * @param object|WP_User $user Utente da cui estrarre i campi (con almeno
	 *                             ID, user_login, display_name, user_email,
	 *                             user_registered).
	 * @return array<string, mixed>
	 */
	private static function build_summary( $user ) {
		$last_login    = get_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN, true );
		$last_login_ip = get_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN_IP, true );

		return array(
			'id'            => (int) $user->ID,
			'user_login'    => $user->user_login,
			'display_name'  => $user->display_name,
			'email'         => $user->user_email,
			// user_registered e' gia' salvato in UTC: il suffisso esplicito
			// toglie ogni ambiguita' a strtotime(), come gia' fa
			// wphc_get_update_log_entries() per created_at.
			'registered'    => gmdate( 'c', strtotime( $user->user_registered . ' UTC' ) ),
			// Null finche' l'utente non effettua un accesso DOPO l'aggiornamento
			// a questa versione dell'agent (WPHC_Users::record_last_login() e' nuova
			// dalla 1.29.0): non va letto come "account dormiente" finche' non
			// e' passato abbastanza tempo da escludere semplicemente questo caso.
			'last_login'    => $last_login ? gmdate( 'c', (int) $last_login ) : null,
			'last_login_ip' => $last_login_ip ? $last_login_ip : null,
		);
	}

	/**
	 * Elenco degli amministratori del sito, per censire dalla dashboard
	 * centrale chi ha accesso pieno. Legge il ruolo "administrator" sul blog
	 * corrente; su multisite include anche i super admin di rete (accesso
	 * pieno anche senza il ruolo administrator sul singolo blog), deduplicati
	 * per user_login. Nessuna cache transient: dato anagrafico a bassa
	 * frequenza di interrogazione, a differenza di /detail/plugins e /detail/theme.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response Elenco amministratori.
	 */
	public static function route_detail_users( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Auth::record_access();

		// 'fields' come array di nomi colonna di wp_users (non un WP_User
		// completo): restituisce oggetti gia' limitati ai soli campi richiesti.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login', 'display_name', 'user_email', 'user_registered' ),
			)
		);

		// get_users() con 'fields' come array NON precarica la meta cache
		// dell'utente (a differenza di un WP_User_Query "pieno"): senza questa
		// chiamata, WPHC_Users::build_summary() farebbe una query di meta per
		// ciascun amministratore invece di una sola query in blocco.
		if ( $admins ) {
			update_meta_cache( 'user', wp_list_pluck( $admins, 'ID' ) );
		}

		$users       = array();
		$seen_logins = array();
		foreach ( $admins as $user ) {
			$users[]                          = self::build_summary( $user );
			$seen_logins[ $user->user_login ] = true;
		}

		// Multisite: i super admin di rete non hanno necessariamente il ruolo
		// administrator sul SINGOLO blog (es. non sono mai stati aggiunti come
		// utente di questo sito), ma hanno comunque accesso pieno. get_super_admins()
		// restituisce solo i login: si risolve ciascuno e si aggiunge solo se non
		// gia' incluso sopra.
		if ( is_multisite() ) {
			$extra_users = array();
			foreach ( get_super_admins() as $login ) {
				if ( isset( $seen_logins[ $login ] ) ) {
					continue;
				}
				$user = get_user_by( 'login', $login );
				if ( ! $user ) {
					continue;
				}
				$extra_users[]         = $user;
				$seen_logins[ $login ] = true;
			}

			// Stesso motivo della chiamata sopra: un solo giro di priming per
			// tutto il gruppo di super admin risolti in questo blocco, invece di
			// una query di meta per ciascuno dentro WPHC_Users::build_summary().
			if ( $extra_users ) {
				update_meta_cache( 'user', wp_list_pluck( $extra_users, 'ID' ) );
			}

			foreach ( $extra_users as $user ) {
				$users[] = self::build_summary( $user );
			}
		}

		return rest_ensure_response(
			array(
				'site'         => WPHC_Util::normalize_site_url(),
				'generated_at' => gmdate( 'c' ),
				'count'        => count( $users ),
				'users'        => $users,
			)
		);
	}

	// -----------------------------------------------------------------------
	// TRACCIAMENTO ULTIMO LOGIN
	// -----------------------------------------------------------------------
	//
	// Data/IP dell'ultimo accesso di ciascun utente, esposti da GET /detail/users
	// (dalla 1.29.0). Deliberatamente NESSUNA riga nella tabella di log per un
	// login ordinario: su un sito con accessi frequenti gonfierebbe la tabella
	// senza aggiungere nulla rispetto alla user meta qui sotto. Il type =
	// 'login' della tabella resta riservato all'audit dell'autologin
	// (WPHC_Autologin::maybe_consume() sopra), che e' un evento raro e sensibile.

	/**
	 * Registra data (timestamp Unix, per poter ordinare/confrontare senza
	 * parsing) e IP dell'ultimo accesso riuscito di un utente, in due user meta
	 * dedicate. Agganciata a wp_login, che WordPress invoca gia' con lo
	 * user_login e l'oggetto WP_User risolto; il fallback su get_user_by()
	 * copre i rari casi (pluggable auth custom) in cui il secondo parametro non
	 * arrivasse.
	 *
	 * @param string       $user_login Login dell'utente autenticato.
	 * @param WP_User|null $user       Utente autenticato, se gia' risolto dal chiamante.
	 */
	public static function record_last_login( $user_login, $user = null ) {
		if ( ! ( $user instanceof WP_User ) ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		update_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN, time() );

		// WPHC_Util::get_client_ip() rispetta gia' l'opzione wp_health_check_trust_proxy:
		// stessa fonte usata per la colonna 'ip' della tabella di log.
		$ip = WPHC_Util::get_client_ip();
		if ( '' !== $ip ) {
			update_user_meta( $user->ID, WPHC_Options::META_LAST_LOGIN_IP, $ip );
		}
	}
}
