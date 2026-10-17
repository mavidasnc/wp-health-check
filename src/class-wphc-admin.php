<?php
/**
 * Tab Site Health del plugin e relativi handler AJAX e admin-post.
 *
 * @package WP_Health_Check
 */

/**
 * Parte amministrativa del plugin: registrazione della tab "WP Health Check" in Strumenti -> Salute del sito, i tre
 * handler AJAX (tester degli endpoint, visualizzatore del log, rivelazione del segreto) e gli otto handler di
 * admin-post.php (self-update, svuotamento cache, thumbnail, reset enrollment, interruttori degli aggiornamenti via
 * API, salvataggio e prova del webhook).
 *
 * Raccoglie la sezione S del file 1.38.1, tranne il template HTML della tab (WPHC_Admin_View::render_tab()).
 * Hook serviti (registrati in WPHC_Plugin::boot()): il filtro site_health_navigation_tabs (register_tab), le azioni
 * wp_ajax_wphc_test_endpoint, wp_ajax_wphc_view_log e wp_ajax_wphc_reveal_secret, e le otto azioni
 * admin_post_wphc_* (una per handler). Ogni handler verifica la capability manage_options e il nonce, poi risponde
 * o reindirizza alla tab (pattern POST-redirect-GET).
 */
final class WPHC_Admin {

	// -----------------------------------------------------------------------
	// TAB SITE HEALTH: stato e configurazione da wp-admin
	// -----------------------------------------------------------------------
	//
	// Aggiunge una tab dedicata in Strumenti -> Salute del sito (disponibile
	// da WordPress 5.8 via i filtri/azioni site_health_navigation_tabs e
	// site_health_tab_content), visibile solo a chi puo' manage_options: qui si
	// puo' anche innescare il self-update e resettare l'enrollment, quindi il
	// controllo di accesso e' piu' stretto della sola capacita' di visualizzare
	// la Salute del sito.

	/**
	 * Registra la tab "WP Health Check" nella pagina Salute del sito.
	 *
	 * @param array<string,string> $tabs Tab gia' registrate (slug => etichetta).
	 * @return array<string,string> Tab con l'eventuale aggiunta.
	 */
	public static function register_tab( $tabs ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $tabs;
		}

		$tabs['wp-health-check'] = __( 'WP Health Check', 'wp-health-check' );

		return $tabs;
	}

	/**
	 * Handler AJAX (admin) del tester di endpoint della tab Site Health. Fa una
	 * richiesta loopback all'endpoint REST richiesto, aggiungendo il bearer token
	 * (lato server: non viene mai esposto al browser) e un cache-buster "_cb"
	 * casuale, e restituisce status/corpo/latenza da mostrare nella modale. Solo
	 * endpoint GET dati in whitelist: nessun effetto collaterale.
	 */
	public static function ajax_test_endpoint() {
		check_ajax_referer( 'wphc_test_endpoint' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
		}

		$key = isset( $_POST['endpoint'] ) ? sanitize_key( wp_unslash( $_POST['endpoint'] ) ) : '';
		$map = array(
			'health'               => array(
				'path'  => 'health',
				'fresh' => false,
			),
			'health_fresh'         => array(
				'path'  => 'health',
				'fresh' => true,
			),
			'detail_plugins'       => array(
				'path'  => 'detail/plugins',
				'fresh' => false,
			),
			'detail_plugins_fresh' => array(
				'path'  => 'detail/plugins',
				'fresh' => true,
			),
			'detail_theme'         => array(
				'path'  => 'detail/theme',
				'fresh' => false,
			),
			'detail_theme_fresh'   => array(
				'path'  => 'detail/theme',
				'fresh' => true,
			),
			'detail_server'        => array(
				'path'  => 'detail/server',
				'fresh' => false,
			),
			'detail_server_fresh'  => array(
				'path'  => 'detail/server',
				'fresh' => true,
			),
		);
		if ( ! isset( $map[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Endpoint non valido.', 'wp-health-check' ) ), 400 );
		}

		$endpoint = $map[ $key ];

		// Cache-buster casuale ad ogni chiamata + eventuale fresh=1.
		$query_args = array( '_cb' => wp_generate_password( 16, false, false ) );
		if ( $endpoint['fresh'] ) {
			$query_args['fresh'] = '1';
		}
		$url = add_query_arg( $query_args, rest_url( 'health-check/v1/' . $endpoint['path'] ) );

		// Il token resta lato server: viaggia solo nell'header della richiesta
		// loopback, mai verso il browser.
		$headers = array();
		$token   = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);
		$took_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			wp_send_json_success(
				array(
					'url'     => $url,
					'status'  => 0,
					'took_ms' => $took_ms,
					/* translators: %s: messaggio di errore della richiesta loopback. */
					'body'    => sprintf( __( 'Errore loopback: %s', 'wp-health-check' ), $response->get_error_message() ),
				)
			);
		}

		wp_send_json_success(
			array(
				'url'     => $url,
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'took_ms' => $took_ms,
				'body'    => wp_remote_retrieve_body( $response ),
			)
		);
	}

	/**
	 * Handler AJAX (admin) del pulsante "Visualizza log" della tab Site Health:
	 * legge la stessa tabella di GET /update/log tramite WPHC_Update_Log::get_entries(),
	 * ma con una query diretta invece che via loopback REST, perche' qui
	 * l'utente e' gia' autenticato in wp-admin e non serve il bearer token.
	 */
	public static function ajax_view_log() {
		check_ajax_referer( 'wphc_view_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
		}

		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;

		$result = WPHC_Update_Log::get_entries( $type, $source, 50, $offset );

		wp_send_json_success(
			array(
				'entries' => $result['entries'],
				'total'   => $result['total'],
			)
		);
	}

	/**
	 * Handler AJAX (admin) del pulsante "Mostra" nella riga "Segreto di flotta"
	 * (dalla 1.30.0): l'unico punto in cui il segreto in chiaro raggiunge il
	 * browser, e solo in risposta a questa chiamata esplicita — mai stampato
	 * nell'HTML della pagina, per non finire in cache di pagina, view-source o
	 * screenshot automatici. Su multisite richiede anche manage_network: su una
	 * rete il segreto e' condiviso da tutti i siti, non del singolo sito, quindi
	 * la sola capacita' locale manage_options non basta. Ogni reveal lascia una
	 * riga nella tabella di log (type='token', message='secret_revealed').
	 */
	public static function ajax_reveal_secret() {
		check_ajax_referer( 'wphc_reveal_secret' );
		if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
		}

		$secret = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
		if ( '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'Nessun segreto registrato.', 'wp-health-check' ) ), 404 );
		}

		$user = wp_get_current_user();
		WPHC_Update_Log::log_row(
			WPHC_Util::generate_correlation_id(),
			'token',
			'reveal',
			$user->display_name ? $user->display_name : $user->user_login,
			null,
			null,
			'completed',
			'secret_revealed',
			null,
			'wp-admin',
			$user->user_login
		);

		wp_send_json_success( array( 'secret' => $secret ) );
	}

	/**
	 * Handler di admin-post.php per il pulsante di self-update nella tab Site
	 * Health: esegue lo stesso flusso condiviso di POST /update
	 * (WPHC_Self_Updater::perform_self_update()) e reindirizza alla tab con l'esito
	 * (pattern POST-redirect-GET). Non richiede enrollment: e' un'azione
	 * amministrativa, protetta da manage_options + nonce.
	 */
	public static function handle_self_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_self_update' );

		$outcome       = WPHC_Self_Updater::perform_self_update();
		$redirect_args = array( 'tab' => 'wp-health-check' );

		switch ( $outcome['result'] ) {
			case 'updated':
				$redirect_args['wphc_updated'] = '1';
				$redirect_args['to']           = $outcome['to'];
				break;

			case 'up_to_date':
				$redirect_args['wphc_uptodate'] = '1';
				break;

			case 'error':
				$redirect_args['wphc_update_failed'] = '1';
				$redirect_args['reason']             = $outcome['code'];
				break;

			default: // esiti non riusciti ma non erronei: not_writable / integrity_check_failed.
				$redirect_args['wphc_update_failed'] = '1';
				$redirect_args['reason']             = $outcome['result'];
				break;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'site-health.php' ) ) );
		exit;
	}

	/**
	 * Handler di admin-post.php per il pulsante "Svuota cache e ricontrolla":
	 * cancella le cache dell'agent (transient wphc_*) e forza un ricontrollo
	 * COMPLETO degli aggiornamenti di core/plugin/temi. Gira in contesto
	 * amministrativo (admin-post), dove gli update-checker dei plugin/temi
	 * premium sono attivi: a differenza di ?fresh=1 via REST, qui
	 * wp_update_plugins()/wp_update_themes() ricostruiscono transient COMPLETI.
	 */
	public static function handle_clear_caches() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_clear_caches' );

		// 1. Cache dei payload dell'agent.
		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_PLUGINS_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_THEME_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_DETAIL_SERVER_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE );
		delete_transient( WPHC_Options::TRANSIENT_PUBLIC_IP );
		delete_transient( WPHC_Options::TRANSIENT_PUBLIC_IP_RETRY_LOCK );

		// 2. Ricontrollo completo degli aggiornamenti. wp_clean_*_cache( true )
		// svuota sia la lista sia il transient degli update, cosi' wp_update_*()
		// ricostruisce da zero (in contesto admin, quindi con i premium inclusi).
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_clean_plugins_cache( true );
		wp_clean_themes_cache( true );
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'          => 'wp-health-check',
					'wphc_cleared' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Handler di admin-post.php per il pulsante "Elimina e rigenera" della
	 * riga "Anteprima sito": elimina l'attachment dal Media Library e le
	 * opzioni collegate, cosi' la prossima /health rigenera la thumbnail.
	 * Il delete_transient() finale rimuove anche l'eventuale cooldown residuo,
	 * per non far attendere l'utente che ha appena chiesto una rigenerazione.
	 */
	public static function handle_delete_thumbnail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_delete_thumbnail' );

		$attachment_id = (int) get_option( WPHC_Options::OPT_THUMB_ID );
		if ( ! $attachment_id ) {
			// Retrocompatibilita' con le thumbnail create dalla 1.27.0, che
			// salvava solo l'URL (wp_health_check_thumb_id non esisteva ancora).
			$attachment_id = attachment_url_to_postid( (string) get_option( WPHC_Options::OPT_THUMB ) );
		}
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		delete_option( WPHC_Options::OPT_THUMB );
		delete_option( WPHC_Options::OPT_THUMB_ID );
		delete_option( WPHC_Options::OPT_THUMB_ERROR );
		delete_option( WPHC_Options::OPT_THUMB_AT );
		delete_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                => 'wp-health-check',
					'wphc_thumb_deleted' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Handler di admin-post.php per il pulsante "Rigenera anteprima ora" (dalla
	 * 1.30.0): a differenza di "Elimina e rigenera" (che si limita a cancellare
	 * e demanda la rigenerazione alla prossima /health), questo chiama
	 * WPHC_Thumbnail::maybe_generate( true ) sincronamente nella stessa richiesta
	 * admin, cosi' un eventuale errore e' visibile subito nella riga "Anteprima
	 * sito" invece di scoprirlo solo al prossimo polling.
	 */
	public static function handle_regenerate_thumbnail() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_regenerate_thumbnail' );

		WPHC_Thumbnail::maybe_generate( true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                    => 'wp-health-check',
					'wphc_thumb_regenerated' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Handler di admin-post.php per il pulsante di reset enrollment nella tab
	 * Site Health: stessa logica condivisa con "wp health-check reset"
	 * (WPHC_Enrollment::reset()), poi redirect alla tab (POST-redirect-GET).
	 */
	public static function handle_reset_enrollment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_reset_enrollment' );

		WPHC_Enrollment::reset();

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'        => 'wp-health-check',
					'wphc_reset' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Handler di admin-post.php per i checkbox "Consenti aggiornamenti (plugin,
	 * temi, core) via API" e "Limita gli aggiornamenti ai soli pacchetti
	 * ospitati su wordpress.org" nella tab Site Health: stesso form/nonce, unico
	 * interruttore master (§5 della specifica) da cui dipendono le rotte
	 * POST /update/{plugin,theme,core}, piu' la sotto-opzione che ne restringe
	 * l'host del pacchetto (vedi WPHC_Item_Updater::is_package_host_allowed()).
	 */
	public static function handle_toggle_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_toggle_updates' );

		update_option( WPHC_Options::OPT_UPDATES_ENABLED, ! empty( $_POST['wphc_updates_enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().
		update_option( WPHC_Options::OPT_RESTRICT_OFFICIAL_ONLY, ! empty( $_POST['wphc_restrict_official_only'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                  => 'wp-health-check',
					'wphc_updates_toggled' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Handler di admin-post.php per il campo "Webhook di notifica aggiornamenti
	 * bulk". Pipeline di validazione: esc_url_raw() normalizza (non valida),
	 * wp_http_validate_url() e' il gate vero (lo stesso validatore che l'HTTP
	 * API di WP applica gia' alle richieste sicure, quindi si rifiuta esattamente
	 * cio' che il trasporto rifiuterebbe comunque), poi scheme https esplicito
	 * (il validatore da solo accetta anche http) e, se l'host e' un IP letterale,
	 * WPHC_Util::ip_is_public() (riusata, non riscritta). Un valore rifiutato non
	 * sovrascrive mai un URL funzionante gia' salvato.
	 *
	 * Gestisce anche la checkbox "disattiva webhook", indipendente dal campo
	 * URL: da quando esiste un default di flotta (WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL),
	 * un campo vuoto non significa piu' "spento" ma "usa il default", quindi
	 * serve un modo esplicito di dire "niente notifiche per questo sito".
	 */
	public static function handle_save_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_save_webhook' );

		$raw      = isset( $_POST['wphc_webhook_url'] ) ? trim( (string) wp_unslash( $_POST['wphc_webhook_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().
		$disabled = ! empty( $_POST['wphc_webhook_disabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

		update_option( WPHC_Options::OPT_WEBHOOK_DISABLED, $disabled );
		if ( $disabled ) {
			// Nessuna notifica deve restare in volo (retry pianificato) quando
			// l'operatore ha appena chiesto di spegnere tutto, a prescindere dal
			// campo URL sotto.
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			wp_clear_scheduled_hook( WPHC_Options::CRON_WEBHOOK_RETRY );
		}

		if ( '' === $raw ) {
			delete_option( WPHC_Options::OPT_WEBHOOK_URL );
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			wp_clear_scheduled_hook( WPHC_Options::CRON_WEBHOOK_RETRY );
			wp_safe_redirect(
				add_query_arg(
					array(
						'tab'                => 'wp-health-check',
						'wphc_webhook_saved' => '1',
					),
					admin_url( 'site-health.php' )
				)
			);
			exit;
		}

		$url    = esc_url_raw( $raw );
		$reason = '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			$reason = 'bad_url';
		} elseif ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			$reason = 'not_https';
		} elseif ( ! WPHC_Webhook::url_is_allowed( $url ) ) {
			$reason = 'blocked_host';
		}

		if ( '' !== $reason ) {
			// Il valore rifiutato viaggia in un transient per utente, non nella
			// query string (contenuto riflesso): serve solo a ripresentarlo nel
			// campo dopo il redirect.
			set_transient( WPHC_Options::TRANSIENT_WEBHOOK_BAD_URL_PREFIX . get_current_user_id(), $raw, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect(
				add_query_arg(
					array(
						'tab'                  => 'wp-health-check',
						'wphc_webhook_invalid' => '1',
						'reason'               => $reason,
					),
					admin_url( 'site-health.php' )
				)
			);
			exit;
		}

		update_option( WPHC_Options::OPT_WEBHOOK_URL, $url, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                => 'wp-health-check',
					'wphc_webhook_saved' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}

	/**
	 * Handler di admin-post.php per "Invia un webhook di prova": invio sincrono
	 * dell'evento wphc.test, MAI un retry pianificato (a differenza del report di
	 * fine job bulk). Unico modo pratico per un admin di verificare che la
	 * propria integrazione HMAC sia corretta prima che giri un job reale.
	 */
	public static function handle_test_webhook() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wphc_test_webhook' );

		$payload = array(
			'schema'        => 'wphc.test/1',
			'event'         => 'wphc.test',
			'site'          => WPHC_Util::normalize_site_url(),
			'agent_version' => WP_HEALTH_CHECK_VERSION,
			'secret_kid'    => (string) get_option( WPHC_Options::OPT_SECRET_KID, '' ),
			'generated_at'  => gmdate( 'c' ),
		);
		WPHC_Webhook::dispatch_payload( 'wphc.test', $payload, 'test-' . WPHC_Util::generate_correlation_id(), 1 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'                 => 'wp-health-check',
					'wphc_webhook_tested' => '1',
				),
				admin_url( 'site-health.php' )
			)
		);
		exit;
	}
}
