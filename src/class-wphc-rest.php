<?php
/**
 * Registrazione delle rotte REST health-check/v1 e lettura dei parametri ?fresh e ?check.
 *
 * @package WP_Health_Check
 */

/**
 * Registrazione delle 22 rotte del namespace health-check/v1 (azione rest_api_init) e helper di lettura
 * dei parametri di richiesta ?fresh=1 e ?check=1.
 *
 * Raccoglie la sezione G del file 1.38.1, request_wants_fresh() della sezione C e request_wants_check()
 * della sezione M. Le rotte non hanno argomenti dichiarati (args): i parametri si leggono nei callback.
 * I callback delle rotte non ancora spostati in classi restano nomi di funzione (stringhe).
 */
final class WPHC_Rest {

	/**
	 * Legge il flag ?fresh=1, l'unico modo previsto per forzare un refresh
	 * (bypassando cache/transient) su /health e sulle rotte /detail.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return bool True se il chiamante ha chiesto esplicitamente dati freschi.
	 */
	public static function request_wants_fresh( WP_REST_Request $request ) {
		return '1' === (string) $request->get_param( 'fresh' );
	}

	/**
	 * Registra tutte le rotte del namespace health-check/v1.
	 *
	 * REGOLA DI PROGETTAZIONE: qui si registrano SOLO le rotte, senza
	 * includere alcun file pesante del core (class-wp-debug-data.php,
	 * plugin.php, update.php). Quei file vengono richiesti dentro le
	 * singole funzioni di callback, solo quando la rotta chiamata ne ha
	 * davvero bisogno: /health e' pensata per il polling frequente e non
	 * deve pagare il costo di include che servono solo a /detail/server.
	 */
	public static function register_routes() {
		register_rest_route(
			'health-check/v1',
			'/enroll',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Enrollment', 'route_enroll' ),
				// Nessun permission_callback basato su token: l'enroll e' il
				// bootstrap stesso del token. L'autenticazione qui e' la
				// firma Ed25519, verificata dentro il callback.
				'permission_callback' => '__return_true',
			)
		);

		// Rotazione del segreto (dalla 1.30.0, §5.B): come /enroll, l'autenticazione
		// e' la firma Ed25519 del centro verificata dentro il callback, non un
		// permission_callback basato su token — il sito non possiede ancora il
		// segreto nuovo nel momento in cui la richiesta arriva.
		register_rest_route(
			'health-check/v1',
			'/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Enrollment', 'route_rotate' ),
				'permission_callback' => '__return_true',
			)
		);

		// Revoca del segreto corrente (dalla 1.30.0, §5.B): stessa autenticazione
		// firmata di /rotate. A differenza di /rotate non consegna nulla di
		// nuovo: azzera lo stato di autenticazione del sito.
		register_rest_route(
			'health-check/v1',
			'/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Enrollment', 'route_revoke' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'health-check/v1',
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_health' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Heartbeat leggero per il polling ad alta frequenza (uptime + latenza):
		// vedi WPHC_Health::route_ping() per il razionale del perche' non riusa /health.
		register_rest_route(
			'health-check/v1',
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_ping' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/plugins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_detail_plugins' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/theme',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_detail_theme' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Health', 'route_detail_server' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/detail/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Users', 'route_detail_users' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_update',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Aggiornamento di software di terze parti (plugin/temi/core), distinto
		// dal self-update dell'agent sopra: vedi wphc_perform_item_update() e
		// wphc_perform_core_update() per il flusso completo.
		register_rest_route(
			'health-check/v1',
			'/update/plugin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_update_plugin',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/update/theme',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_update_theme',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		register_rest_route(
			'health-check/v1',
			'/update/core',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_update_core',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Sola lettura: resta accessibile anche a kill-switch spento (vedi
		// wphc_route_update_log), utile per diagnosticare la flotta a feature
		// disattivata.
		register_rest_route(
			'health-check/v1',
			'/update/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'wphc_route_update_log',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Riconciliazione a posteriori dello stato attivo dei plugin (vedi
		// wphc_perform_reactivate()): rispetta kill-switch e lock come le altre
		// rotte di scrittura, salvo il dry-run (?check=1) che resta sola lettura.
		register_rest_route(
			'health-check/v1',
			'/update/reactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_reactivate',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Aggiornamenti bulk (plugin/temi) asincroni: POST accoda un job
		// autenticato, WP-Cron smaltisce la coda gia' autorizzata (mai un update
		// non richiesto). Vedi wphc_route_update_bulk_enqueue() per il flusso
		// completo. GET legge lo stato del job da un singolo option, piu'
		// economico di una query sulla tabella di log.
		register_rest_route(
			'health-check/v1',
			'/update/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_update_bulk_enqueue',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);
		register_rest_route(
			'health-check/v1',
			'/update/bulk',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'wphc_route_update_bulk_status',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);
		register_rest_route(
			'health-check/v1',
			'/update/bulk/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'wphc_route_update_bulk_cancel',
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Stato/rigenerazione della thumbnail del sito (dalla 1.30.0): stessa
		// autenticazione a bearer delle altre rotte dati. GET restituisce solo lo
		// stato corrente (economico, nessuna chiamata remota); POST forza una
		// rigenerazione ignorando il cooldown, vedi WPHC_Thumbnail::maybe_generate().
		register_rest_route(
			'health-check/v1',
			'/thumbnail',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Thumbnail', 'route_status' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);
		register_rest_route(
			'health-check/v1',
			'/thumbnail',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Thumbnail', 'route_regenerate' ),
				'permission_callback' => array( 'WPHC_Auth', 'require_token' ),
			)
		);

		// Rotta diagnostica: gated su manage_options (autenticazione WordPress,
		// quindi chiamabile con una application password), NON sul bearer token.
		// E' uno strumento per l'operatore: aiuta a capire discrepanze fra i
		// conteggi update visti in admin e via REST.
		register_rest_route(
			'health-check/v1',
			'/debug',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'WPHC_Debug', 'route_debug' ),
				'permission_callback' => array( 'WPHC_Auth', 'debug_permission' ),
			)
		);

		// Autologin: genera un token one-time per aprire wp-admin gia' autenticati
		// (uso tipico: pulsante nella dashboard/app centrale). Gated su
		// manage_options, NON sul bearer token: l'identita' che finira' loggata in
		// wp-admin e' esattamente quella risolta da WordPress per la richiesta
		// (cookie o, tipicamente qui, una Application Password), non un
		// user_id arbitrario passato dal chiamante. Vedi
		// WPHC_Autologin::route_token() e WPHC_Autologin::maybe_consume().
		register_rest_route(
			'health-check/v1',
			'/autologin/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'WPHC_Autologin', 'route_token' ),
				'permission_callback' => array( 'WPHC_Auth', 'debug_permission' ),
			)
		);
	}

	/**
	 * True se la richiesta chiede una simulazione (?check=1): non esegue
	 * alcun update, verifica solo se l'elemento e' aggiornabile (dry-run,
	 * migliora la UX della dashboard che puo' sapere in anticipo cosa e'
	 * aggiornabile senza eseguire nulla).
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return bool
	 */
	public static function request_wants_check( WP_REST_Request $request ) {
		return (bool) $request->get_param( 'check' );
	}
}
