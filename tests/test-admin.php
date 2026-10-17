<?php
/**
 * Test di caratterizzazione dell'area admin del plugin 1.38.1 (sezione S, righe ~7453-8771):
 * registrazione e rendering della tab Site Health, handler AJAX e handler di admin-post.php.
 *
 * Gli handler si eseguono sempre attraverso gli hook veri (do_action / apply_filters), non
 * chiamando le funzioni per nome: cosi' i test restano validi quando il refactoring le
 * trasformera' in metodi di classe. Gli esiti (redirect, option, transient) sono fissati con
 * golden in tests/fixtures/golden/admin/; l'HTML della tab NON e' fissato per intero: si
 * controllano i punti chiave e la struttura (titoli, form, campi, id).
 *
 * Ordine REALE dei controlli (da preservare):
 *  - admin-post.php: capability manage_options (wp_die 403) -> nonce (wp_die 403);
 *  - AJAX: nonce (wp_die -1, 403) -> capability (JSON 403).
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-helpers-admin.php';

/**
 * Tab Site Health, handler AJAX e handler admin-post.
 */
class WPHC_Test_Admin extends WPHC_Admin_Test_Case {

	/**
	 * Azioni di admin-post.php registrate dal plugin (azione = nome dell'azione del nonce).
	 *
	 * @var string[]
	 */
	const POST_ACTIONS = array(
		'wphc_self_update',
		'wphc_clear_caches',
		'wphc_delete_thumbnail',
		'wphc_regenerate_thumbnail',
		'wphc_reset_enrollment',
		'wphc_toggle_updates',
		'wphc_save_webhook',
		'wphc_test_webhook',
	);

	/**
	 * Azioni AJAX registrate dal plugin (azione = nome dell'azione del nonce).
	 *
	 * @var string[]
	 */
	const AJAX_ACTIONS = array(
		'wphc_test_endpoint',
		'wphc_view_log',
		'wphc_reveal_secret',
	);

	/**
	 * Richieste HTTP in uscita registrate dal falso trasporto di un test.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $http_requests = array();

	// -----------------------------------------------------------------------
	// Fornitori di dati
	// -----------------------------------------------------------------------

	/**
	 * Un caso per ogni handler di admin-post.php.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function post_action_provider() {
		$cases = array();
		foreach ( self::POST_ACTIONS as $action ) {
			$cases[ $action ] = array( $action );
		}

		return $cases;
	}

	/**
	 * Un caso per ogni handler AJAX.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function ajax_action_provider() {
		$cases = array();
		foreach ( self::AJAX_ACTIONS as $action ) {
			$cases[ $action ] = array( $action );
		}

		return $cases;
	}

	// -----------------------------------------------------------------------
	// Registrazione degli hook
	// -----------------------------------------------------------------------

	/**
	 * Callback registrati su un hook: priorita' => numero di callback. Si guarda la forma della
	 * registrazione e non il nome della funzione, che il refactoring cambiera'.
	 *
	 * @param string $hook Nome dell'hook.
	 * @return array<int,int>
	 */
	private function callbacks_by_priority( $hook ) {
		global $wp_filter;

		$result = array();
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					// Il core registra su site_health_tab_content un metodo di WP_Site_Health: non e' del plugin.
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WP_Site_Health ) {
						continue;
					}
					$result[ $priority ] = ( isset( $result[ $priority ] ) ? $result[ $priority ] : 0 ) + 1;
				}
			}
		}

		return $result;
	}

	/**
	 * Ogni handler admin-post e AJAX ha UN solo callback a priorita' 10; gli handler AJAX
	 * NON sono registrati per gli utenti anonimi (nessun wp_ajax_nopriv_*) e gli handler di
	 * admin-post nemmeno (nessun admin_post_nopriv_*).
	 */
	public function test_admin_hooks_are_registered_for_logged_in_users_only() {
		foreach ( self::POST_ACTIONS as $action ) {
			$this->assertSame( array( 10 => 1 ), $this->callbacks_by_priority( 'admin_post_' . $action ), "admin_post_{$action}" );
			$this->assertFalse( has_action( 'admin_post_nopriv_' . $action ), "admin_post_nopriv_{$action}" );
		}
		foreach ( self::AJAX_ACTIONS as $action ) {
			$this->assertSame( array( 10 => 1 ), $this->callbacks_by_priority( 'wp_ajax_' . $action ), "wp_ajax_{$action}" );
			$this->assertFalse( has_action( 'wp_ajax_nopriv_' . $action ), "wp_ajax_nopriv_{$action}" );
		}

		$this->assertSame( array( 10 => 1 ), $this->callbacks_by_priority( 'site_health_navigation_tabs' ) );
		$this->assertSame( array( 10 => 1 ), $this->callbacks_by_priority( 'site_health_tab_content' ) );
	}

	// -----------------------------------------------------------------------
	// Registrazione della tab
	// -----------------------------------------------------------------------

	/**
	 * Un amministratore vede la tab "wp-health-check" => "WP Health Check", aggiunta in coda a
	 * quelle esistenti, che restano invariate.
	 */
	public function test_tab_is_added_for_administrators() {
		$this->login_as( 'administrator' );

		$tabs = apply_filters(
			'site_health_navigation_tabs',
			array(
				'site-status' => 'Stato',
				'debug'       => 'Info',
			)
		);

		$this->assertSame(
			array(
				'site-status'     => 'Stato',
				'debug'           => 'Info',
				'wp-health-check' => 'WP Health Check',
			),
			$tabs
		);
	}

	/**
	 * Senza manage_options (editor, abbonato, anonimo) le tab restano quelle di partenza.
	 */
	public function test_tab_is_not_added_without_manage_options() {
		$start = array( 'site-status' => 'Stato' );

		foreach ( array( 'editor', 'subscriber', null ) as $role ) {
			null === $role ? wp_set_current_user( 0 ) : $this->login_as( $role );

			$this->assertSame( $start, apply_filters( 'site_health_navigation_tabs', $start ), (string) $role );
		}
	}

	// -----------------------------------------------------------------------
	// Rendering della tab: accesso
	// -----------------------------------------------------------------------

	/**
	 * La tab produce output solo per lo slug "wp-health-check" e solo per chi ha manage_options.
	 */
	public function test_tab_renders_nothing_for_other_slugs_or_users() {
		$this->login_as( 'administrator' );
		$this->assertSame( '', $this->render_tab( 'site-status' ) );
		$this->assertSame( '', $this->render_tab( '' ) );

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->login_as( $role );
			$this->assertSame( '', $this->render_tab(), $role );
		}
		wp_set_current_user( 0 );
		$this->assertSame( '', $this->render_tab() );
	}

	// -----------------------------------------------------------------------
	// Rendering della tab: stati del sito
	// -----------------------------------------------------------------------

	/**
	 * Sito NON enrollato: nessun segreto, nessun pulsante "Mostra", webhook di flotta di default
	 * (con il test del webhook disponibile) e versione "non determinabile" (GitHub bloccato).
	 */
	public function test_tab_not_enrolled() {
		$this->login_as( 'administrator' );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assertNotSame( '', $html );
		$this->assert_balanced_html( $html );
		$this->assertStringContainsString( 'WP Health Check — Fleet Agent', $text );
		$this->assertStringContainsString( 'Versione plugin installata ' . WP_HEALTH_CHECK_VERSION, $text );
		$this->assertStringContainsString( 'Non registrato (in attesa di /enroll)', $text );
		$this->assertStringContainsString( 'non determinabile (GitHub irraggiungibile)', $text );
		$this->assertStringContainsString( 'Nessun webhook inviato finora.', $text );
		$this->assertStringContainsString( 'in uso il default di flotta ' . WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL, $text );
		$this->assertStringContainsString( 'Disattivo', $text ); // Trust proxy.

		// Nessun segreto: niente riga mascherata ne' pulsanti per mostrarlo/copiarlo.
		$this->assertStringNotContainsString( '<code id="wphc-secret-masked">', $html );
		$this->assertStringNotContainsString( 'id="wphc-secret-reveal-btn"', $html );
		$this->assertStringNotContainsString( 'data-nonce="' . wp_create_nonce( 'wphc_reveal_secret' ) . '"', $html );
		// Il webhook di flotta e' attivo: il pulsante di prova e' presente.
		$this->assertStringContainsString( 'value="wphc_test_webhook"', $html );

		// Nulla esce verso la rete se non le richieste a GitHub per "ultima versione".
		foreach ( $this->blocked_http_calls as $url ) {
			$this->assertMatchesRegularExpression( '#^https://(api\.)?github\.com/#', $url );
		}

		$this->assert_tab_structure_golden( 'tab-not-enrolled', $html );
	}

	/**
	 * Sito enrollato al protocollo 1: segreto MASCHERATO (primi 6 caratteri, 8 pallini, ultimi 4),
	 * fingerprint SHA-256 troncato, "ultima rotazione: mai"; il segreto per intero NON compare mai
	 * nell'HTML (finirebbe in cache di pagina e in view-source).
	 */
	public function test_tab_enrolled_v1_masks_the_secret() {
		$token = 'tok-v1-secret-abcdef123456';
		$this->seed_enrolled_v1( $token );
		$this->set_latest_version( WP_HEALTH_CHECK_VERSION );
		$this->login_as( 'administrator' );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assert_balanced_html( $html );
		$this->assertStringNotContainsString( $token, $html );
		$this->assertStringContainsString( 'tok-v1' . str_repeat( '•', 8 ) . '3456', $html );
		$this->assertStringContainsString( 'Fingerprint: ' . substr( hash( 'sha256', $token ), 0, 12 ) . ' — protocollo: 1 — ultima rotazione: mai.', $text );
		$this->assertStringContainsString( 'Registrato (il 2026-10-01T10:00:00+00:00 da 198.51.100.7)', $text );
		$this->assertStringContainsString( $this->own_site_url(), $text ); // URL firmato registrato.
		$this->assertStringContainsString( WP_HEALTH_CHECK_VERSION . ' — aggiornato', $text );
		// Il pulsante "Mostra" porta un nonce per l'handler AJAX (non il segreto).
		$this->assertStringContainsString( 'data-nonce="' . wp_create_nonce( 'wphc_reveal_secret' ) . '"', $html );
		$this->assertStringContainsString( 'value="Verifica e aggiorna adesso"', $html );

		$this->assert_tab_structure_golden( 'tab-enrolled-v1', $html );
	}

	/**
	 * Un segreto corto (10 caratteri o meno) e' mascherato per intero con TANTI pallini quanti sono i caratteri
	 * (strlen), senza svelare nulla. Le lunghezze sono scelte DIVERSE da 8 (il numero fisso di pallini del ramo
	 * "lungo"): con un segreto di 8 caratteri i due rami darebbero lo stesso HTML e non si distinguerebbero.
	 * Il confine e' a 10 caratteri (mascherato per intero), con 11 si passa al formato 6 + 8 pallini + 4.
	 */
	public function test_tab_short_secret_is_fully_masked() {
		$this->login_as( 'administrator' );

		foreach ( array( 'wxyzq', 'wxyzqrstuv' ) as $secret ) { // 5 e 10 caratteri (lettere non esadecimali: mai nel fingerprint).
			$this->seed_enrolled_v1( $secret );
			$html = $this->render_tab();

			$this->assertStringNotContainsString( $secret, $html, "segreto di {$secret} svelato" );
			$this->assertStringContainsString( '<code id="wphc-secret-masked">' . str_repeat( '•', strlen( $secret ) ) . '</code>', $html, 'pallini = strlen per ' . strlen( $secret ) . ' caratteri' );
		}

		// 11 caratteri: primo ramo "lungo" (6 caratteri, 8 pallini, ultimi 4).
		$this->seed_enrolled_v1( 'wxyzqrstuvw' );
		$this->assertStringContainsString( '<code id="wphc-secret-masked">wxyzqr' . str_repeat( '•', 8 ) . 'tuvw</code>', $this->render_tab() );
	}

	/**
	 * Protocollo 2 ruotato: protocollo 2 e data di rotazione in formato ISO 8601 UTC.
	 */
	public function test_tab_enrolled_v2_shows_rotation() {
		$this->seed_enrolled_v2();
		$this->set_latest_version( '9.9.9' );
		$this->login_as( 'administrator' );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assert_balanced_html( $html );
		$this->assertStringContainsString( 'protocollo: 2 — ultima rotazione: ' . gmdate( 'c', 1790000000 ) . '.', $text );
		// Versione piu' recente disponibile: etichetta del pulsante e segnalazione.
		$this->assertStringContainsString( 'aggiornamento disponibile', $text );
		$this->assertStringContainsString( 'Aggiorna alla versione 9.9.9', $html );
		$this->assertStringNotContainsString( 'tok-prev-secret', $html );

		$this->assert_tab_structure_golden( 'tab-enrolled-v2', $html );
	}

	/**
	 * Sito revocato: la riga del segreto dice "Revocato (il <data>)" e non offre ne' la versione
	 * mascherata ne' i pulsanti per mostrare o copiare il segreto.
	 */
	public function test_tab_revoked() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_revoked_at', '2026-10-05T08:30:00+00:00', false );
		$this->login_as( 'administrator' );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assert_balanced_html( $html );
		$this->assertStringContainsString( 'Revocato (il 2026-10-05T08:30:00+00:00)', $text );
		$this->assertStringNotContainsString( '<code id="wphc-secret-masked">', $html );
		$this->assertStringNotContainsString( '<button type="button" class="button button-small" id="wphc-secret-reveal-btn"', $html );
		$this->assertStringNotContainsString( 'Fingerprint:', $text );

		$this->assert_tab_structure_golden( 'tab-revoked', $html );
	}

	/**
	 * Job bulk in corso e webhook in attesa di retry: la tab non mostra nulla del job, ma non
	 * deve andare in errore con quelle option presenti.
	 */
	public function test_tab_with_bulk_job_in_progress_and_pending_webhook() {
		$this->seed_enrolled_v2();
		$job_id = 'bulk-job-0123456789abcdef';
		update_option(
			'wp_health_check_bulk_job',
			array(
				'job_id'      => $job_id,
				'status'      => 'running',
				'items'       => array(),
				'counters'    => array(
					'total' => 0,
					'done'  => 0,
				),
				'next_run_ts' => time() + 30,
			),
			false
		);
		update_option(
			'wp_health_check_bulk_status',
			array(
				'job_id' => $job_id,
				'status' => 'running',
			),
			true
		);
		update_option(
			'wp_health_check_webhook_pending',
			array(
				'job_id'   => $job_id,
				'event'    => 'wphc.bulk_update.completed',
				'body'     => array(),
				'attempts' => 1,
			),
			false
		);
		wp_schedule_single_event( time() + 60, 'wphc_webhook_retry' );
		$this->login_as( 'administrator' );

		$html = $this->render_tab();

		$this->assertNotSame( '', $html );
		$this->assert_balanced_html( $html );
		$this->assertStringContainsString( 'Stato enrollment', $this->visible_text( $html ) );
		$this->assertStringNotContainsString( $job_id, $html );
	}

	/**
	 * Webhook configurato: URL del sito nel campo, testo "in uso l'URL impostato qui sotto" e
	 * l'ultimo invio con l'host (mai l'URL completo).
	 */
	public function test_tab_with_webhook_configured_and_last_delivery() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_webhook_url', 'https://hooks.example.com/wphc/secret-path', false );
		update_option(
			'wp_health_check_webhook_last',
			array(
				'at'          => '2026-10-10T12:00:00+00:00',
				'ok'          => false,
				'job_id'      => 'job-1',
				'attempt'     => 2,
				'code'        => 502,
				'error'       => 'wphc_webhook_http_error',
				'message'     => 'bad gateway',
				'url_host'    => 'hooks.example.com',
				'duration_ms' => 12,
				'retry_at'    => null,
			),
			false
		);
		$this->login_as( 'administrator' );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assert_balanced_html( $html );
		$this->assertStringContainsString( 'In uso l\'URL impostato qui sotto per questo sito.', $text );
		$this->assertStringContainsString( 'value="https://hooks.example.com/wphc/secret-path"', $html );
		$this->assertStringContainsString( 'Ultimo invio: 2026-10-10T12:00:00+00:00 — fallito (http 502, wphc_webhook_http_error) verso hooks.example.com', $text );
		$this->assertStringContainsString( 'style="color:#d63638;"', $html );
		$this->assertStringContainsString( 'value="wphc_test_webhook"', $html );

		$this->assert_tab_structure_golden( 'tab-webhook-configured', $html );
	}

	/**
	 * Webhook disattivato esplicitamente: testo dedicato e nessun pulsante di prova (l'URL
	 * effettivo e' vuoto). Un URL rifiutato in un salvataggio precedente (transient per utente)
	 * ripopola il campo una volta sola e il transient viene consumato.
	 */
	public function test_tab_webhook_disabled_and_rejected_value_is_shown_once() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_webhook_disabled', true );
		$user_id = $this->login_as( 'administrator' );
		set_transient( 'wphc_webhook_bad_url_' . $user_id, 'http://non-valido.example.com/x', 300 );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assertStringContainsString( 'Webhook disattivato esplicitamente per questo sito: nessuna notifica viene inviata.', $text );
		$this->assertStringNotContainsString( 'value="wphc_test_webhook"', $html );
		$this->assertStringContainsString( 'value="http://non-valido.example.com/x"', $html );
		// Consumato: il secondo rendering non lo mostra piu'.
		$this->assertFalse( get_transient( 'wphc_webhook_bad_url_' . $user_id ) );
		$this->assertStringNotContainsString( 'non-valido.example.com', $this->render_tab() );
	}

	/**
	 * Anteprima del sito, ultimo errore di thumbnail e ultimo enroll fallito.
	 */
	public function test_tab_shows_thumbnail_and_error_details() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_thumb', 'https://example.org/wp-content/uploads/thumb.png', false );
		update_option(
			'wp_health_check_thumb_error',
			array(
				'at'      => '2026-10-02T09:00:00+00:00',
				'code'    => 'wphc_thumb_download_failed',
				'message' => 'timeout',
			),
			false
		);
		update_option(
			'wp_health_check_last_enroll_error',
			array(
				'at'       => '2026-10-03T09:00:00+00:00',
				'code'     => 'wphc_enroll_url_mismatch',
				'reason'   => 'URL non coincidente',
				'received' => 'https://altro.example.com',
				'ip'       => '203.0.113.9',
			),
			false
		);
		$this->login_as( 'administrator' );

		$html = $this->render_tab();
		$text = $this->visible_text( $html );

		$this->assertStringContainsString( '<img src="https://example.org/wp-content/uploads/thumb.png"', $html );
		$this->assertStringContainsString( 'value="wphc_delete_thumbnail"', $html );
		$this->assertStringContainsString( 'Ultimo errore: wphc_thumb_download_failed — timeout (2026-10-02T09:00:00+00:00)', $text );
		$this->assertStringContainsString( 'URL non coincidente', $text );
		$this->assertStringContainsString( 'wphc_enroll_url_mismatch — 2026-10-03T09:00:00+00:00 — 203.0.113.9', $text );
		$this->assertStringContainsString( 'URL inviato: https://altro.example.com', $text );
	}

	/**
	 * Il segreto di enroll mancante ma con URL firmato non genera errori (stato anomalo, no fatal).
	 */
	public function test_tab_with_inconsistent_state_does_not_fail() {
		update_option( 'wp_health_check_site_url', 'https://orfano.example.com', false );
		update_option( 'wp_health_check_protocol', 2, false );
		$this->login_as( 'administrator' );

		$html = $this->render_tab();

		$this->assert_balanced_html( $html );
		$this->assertStringContainsString( 'https://orfano.example.com', $html );
	}

	// -----------------------------------------------------------------------
	// Rendering della tab: notice (flag POST-redirect-GET)
	// -----------------------------------------------------------------------

	/**
	 * Un caso per ogni flag che l'handler mette nel redirect: [parametri GET, testo atteso].
	 *
	 * @return array<string,array{0:array,1:string,2:string}>
	 */
	public static function notice_provider() {
		return array(
			'updated'           => array( array( 'wphc_updated' => '1', 'to' => '1.99.0' ), 'notice-success', 'Plugin aggiornato alla versione 1.99.0.' ),
			'uptodate'          => array( array( 'wphc_uptodate' => '1' ), 'notice-info', 'Il plugin e\' gia\' aggiornato all\'ultima versione disponibile.' ),
			'update failed'     => array( array( 'wphc_update_failed' => '1', 'reason' => 'integrity_check_failed' ), 'notice-error', 'Aggiornamento non riuscito (integrity_check_failed). Nessuna modifica al file del plugin.' ),
			'cleared'           => array( array( 'wphc_cleared' => '1' ), 'notice-success', 'Cache dell\'agent svuotate e aggiornamenti ricontrollati.' ),
			'reset'             => array( array( 'wphc_reset' => '1' ), 'notice-success', 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".' ),
			'updates toggled'   => array( array( 'wphc_updates_toggled' => '1' ), 'notice-success', 'Preferenza sugli aggiornamenti via API salvata.' ),
			'thumb deleted'     => array( array( 'wphc_thumb_deleted' => '1' ), 'notice-success', 'Anteprima del sito eliminata: verra\' rigenerata alla prossima chiamata /health.' ),
			'thumb failed'      => array( array( 'wphc_thumb_regenerated' => '1' ), 'notice-error', 'Rigenerazione non riuscita: vedi il dettaglio dell\'errore nella riga "Anteprima sito" qui sotto.' ),
			'webhook saved'     => array( array( 'wphc_webhook_saved' => '1' ), 'notice-success', 'URL del webhook salvato.' ),
			'webhook not https' => array( array( 'wphc_webhook_invalid' => '1', 'reason' => 'not_https' ), 'notice-error', 'L\'URL deve usare https. not_https' ),
			'webhook blocked'   => array( array( 'wphc_webhook_invalid' => '1', 'reason' => 'blocked_host' ), 'notice-error', 'L\'host indicato e\' un indirizzo privato o di loopback: non e\' consentito. blocked_host' ),
			'webhook bad url'   => array( array( 'wphc_webhook_invalid' => '1', 'reason' => 'bad_url' ), 'notice-error', 'URL non valido. bad_url' ),
			'webhook unknown'   => array( array( 'wphc_webhook_invalid' => '1', 'reason' => 'qualcosa' ), 'notice-error', 'URL non valido. qualcosa' ),
			'webhook test fail' => array( array( 'wphc_webhook_tested' => '1' ), 'notice-error', 'Invio del webhook di prova non riuscito: vedi il dettaglio qui sotto.' ),
		);
	}

	/**
	 * Ogni flag di redirect produce la notice attesa (classe e testo) in cima alla tab.
	 *
	 * @dataProvider notice_provider
	 *
	 * @param array  $query    Parametri GET.
	 * @param string $class    Classe CSS della notice.
	 * @param string $expected Testo atteso.
	 */
	public function test_tab_notices( array $query, $class, $expected ) {
		$this->seed_enrolled_v2();
		$this->login_as( 'administrator' );
		$_GET = $query;

		$html = $this->render_tab();

		$this->assertMatchesRegularExpression( '#<div class="notice ' . preg_quote( $class, '#' ) . '"#', $html );
		$this->assertStringContainsString( $expected, $this->visible_text( $html ) );
		// Una sola notice per richiesta (catena if/elseif).
		$this->assertSame( 1, substr_count( $html, 'class="notice ' ) );
	}

	/**
	 * Le due varianti dipendenti dallo stato: webhook salvato senza URL effettivo, anteprima
	 * rigenerata con successo e webhook di prova riuscito.
	 */
	public function test_tab_notices_that_depend_on_state() {
		$this->seed_enrolled_v2();
		$this->login_as( 'administrator' );

		// Webhook salvato ma disattivato (URL effettivo vuoto): "Webhook disattivato."
		update_option( 'wp_health_check_webhook_disabled', true );
		$_GET = array( 'wphc_webhook_saved' => '1' );
		$this->assertStringContainsString( 'Webhook disattivato.', $this->visible_text( $this->render_tab() ) );

		// Anteprima rigenerata: con l'option della thumbnail la notice e' di successo.
		update_option( 'wp_health_check_thumb', 'https://example.org/t.png', false );
		$_GET = array( 'wphc_thumb_regenerated' => '1' );
		$html = $this->render_tab();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Anteprima rigenerata con successo.', $this->visible_text( $html ) );

		// Webhook di prova riuscito (ultimo invio ok).
		update_option( 'wp_health_check_webhook_last', array( 'ok' => true, 'at' => 'x', 'code' => 200, 'url_host' => 'h', 'error' => null ), false );
		$_GET = array( 'wphc_webhook_tested' => '1' );
		$html = $this->render_tab();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Webhook di prova inviato con successo.', $this->visible_text( $html ) );
	}

	/**
	 * I valori riflessi dalla query string ("to", "reason") sono ripuliti ed escapizzati; con piu'
	 * flag vale il primo della catena (aggiornato prima di svuotato).
	 */
	public function test_tab_notice_escapes_reflected_values_and_first_flag_wins() {
		$this->seed_enrolled_v2();
		$this->login_as( 'administrator' );
		$_GET = array(
			'wphc_updated' => '1',
			'to'           => '<img src=x onerror=alert(1)>1.2.3',
			'wphc_cleared' => '1',
		);

		$html = $this->render_tab();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( 'onerror=', $html );
		$this->assertStringContainsString( 'Plugin aggiornato alla versione', $this->visible_text( $html ) );
		$this->assertStringNotContainsString( 'Cache dell', $this->visible_text( $html ) );
	}

	// -----------------------------------------------------------------------
	// AJAX: controlli di accesso comuni
	// -----------------------------------------------------------------------

	/**
	 * Gli handler AJAX controllano PRIMA il nonce (nessun nonce o nonce di un'altra azione:
	 * wp_die( -1, 403 )) e DOPO la capability (JSON {success:false} con 403 "Non autorizzato.").
	 *
	 * @dataProvider ajax_action_provider
	 *
	 * @param string $action Azione AJAX.
	 */
	public function test_ajax_checks_nonce_then_capability( $action ) {
		$this->seed_enrolled_v2();
		$before = $this->state_before();

		// 1. Amministratore senza nonce, e con il nonce di un'altra azione: -1 con 403.
		$this->login_as( 'administrator' );
		foreach ( array( false, wp_create_nonce( 'altra_azione' ), 'nonce-inventato' ) as $nonce ) {
			$outcome = $this->run_ajax( $action, array(), $nonce );
			$this->assertSame( 'die', $outcome['type'], $action );
			$this->assertSame( '-1', $outcome['message'], $action );
			$this->assertSame( 403, $outcome['response'], $action );
		}

		// 2. Nonce valido ma utente senza manage_options: 403 JSON "Non autorizzato.".
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->login_as( $role );
			$outcome = $this->run_ajax( $action );
			$this->assertSame( 'die', $outcome['type'], "{$action} {$role}" );
			$this->assertSame( 403, $outcome['status_header'], "{$action} {$role}" );
			$this->assertFalse( $outcome['json']['success'], "{$action} {$role}" );
			$this->assertSame( 'Non autorizzato.', $outcome['json']['data']['message'], "{$action} {$role}" );
		}

		// 3. Utente anonimo con il nonce (valido per l'utente 0): stesso 403 JSON.
		wp_set_current_user( 0 );
		$outcome = $this->run_ajax( $action );
		$this->assertSame( 403, $outcome['status_header'] );
		$this->assertSame( 'Non autorizzato.', $outcome['json']['data']['message'] );

		// Nessun effetto collaterale sullo stato del plugin.
		$this->assertSame( $before['options'], $this->plugin_options() );
	}

	// -----------------------------------------------------------------------
	// AJAX: wphc_test_endpoint
	// -----------------------------------------------------------------------

	/**
	 * Falso trasporto HTTP: registra le richieste e risponde con status e corpo dati.
	 *
	 * @param int    $code Codice HTTP.
	 * @param string $body Corpo.
	 */
	private function fake_loopback( $code, $body ) {
		$this->http_requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $code, $body ) {
				unset( $preempt );
				$this->http_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return $this->http_response( $code, $body );
			},
			20,
			3
		);
	}

	/**
	 * Mappa chiave -> (percorso REST, fresh): tutte e otto le chiavi della whitelist.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function endpoint_provider() {
		return array(
			'health'               => array( 'health', 'health', false ),
			'health_fresh'         => array( 'health_fresh', 'health', true ),
			'detail_plugins'       => array( 'detail_plugins', 'detail/plugins', false ),
			'detail_plugins_fresh' => array( 'detail_plugins_fresh', 'detail/plugins', true ),
			'detail_theme'         => array( 'detail_theme', 'detail/theme', false ),
			'detail_theme_fresh'   => array( 'detail_theme_fresh', 'detail/theme', true ),
			'detail_server'        => array( 'detail_server', 'detail/server', false ),
			'detail_server_fresh'  => array( 'detail_server_fresh', 'detail/server', true ),
		);
	}

	/**
	 * Il tester fa una richiesta loopback GET all'endpoint della whitelist, con cache-buster _cb di
	 * 16 caratteri, fresh=1 solo per le varianti "fresh" e il Bearer del sito SOLO nell'header
	 * della richiesta (mai nella risposta al browser).
	 *
	 * @dataProvider endpoint_provider
	 *
	 * @param string $key   Chiave inviata dal browser.
	 * @param string $path  Percorso REST atteso sotto health-check/v1/.
	 * @param bool   $fresh Se deve esserci fresh=1.
	 */
	public function test_ajax_test_endpoint_whitelist( $key, $path, $fresh ) {
		$token = 'tok-ajax-secret-0001';
		$this->seed_enrolled_v1( $token );
		$this->login_as( 'administrator' );
		$this->fake_loopback( 200, '{"status":"ok"}' );

		$outcome = $this->run_ajax( 'wphc_test_endpoint', array( 'endpoint' => $key ) );

		$this->assertTrue( $outcome['json']['success'] );
		$data = $outcome['json']['data'];
		$this->assertSame( 200, $data['status'] );
		$this->assertSame( '{"status":"ok"}', $data['body'] );
		$this->assertIsInt( $data['took_ms'] );
		$this->assertSame( array( 'url', 'status', 'took_ms', 'body' ), array_keys( $data ) );
		// Il token non e' mai restituito al browser.
		$this->assertStringNotContainsString( $token, $outcome['output'] );

		// La richiesta uscita: GET verso il percorso giusto, con Bearer e query corretta.
		$this->assertCount( 1, $this->http_requests );
		$sent  = $this->http_requests[0];
		$parts = wp_parse_url( $sent['url'] );
		parse_str( $parts['query'], $query );
		// Con i permalink "belli" il percorso e' /wp-json/..., con quelli semplici e' ?rest_route=.
		$this->assertStringEndsWith( '/health-check/v1/' . $path, isset( $query['rest_route'] ) ? $query['rest_route'] : $parts['path'] );
		$this->assertSame( 16, strlen( $query['_cb'] ) );
		$this->assertSame( $fresh, isset( $query['fresh'] ) );
		if ( $fresh ) {
			$this->assertSame( '1', $query['fresh'] );
		}
		$this->assertSame( 'Bearer ' . $token, $sent['args']['headers']['Authorization'] );
		$this->assertSame( 20, $sent['args']['timeout'] );
		// Il campo "url" della risposta e' quello richiesto.
		$this->assertSame( $sent['url'], $data['url'] );
	}

	/**
	 * Il cache-buster cambia a ogni chiamata; senza segreto la richiesta parte senza Authorization;
	 * la chiave e' normalizzata da sanitize_key (maiuscole ammesse).
	 */
	public function test_ajax_test_endpoint_cache_buster_and_no_token() {
		$this->login_as( 'administrator' );
		$this->fake_loopback( 401, '{"code":"wphc_not_enrolled"}' );

		$first  = $this->run_ajax( 'wphc_test_endpoint', array( 'endpoint' => 'HEALTH' ) );
		$second = $this->run_ajax( 'wphc_test_endpoint', array( 'endpoint' => 'health' ) );

		$this->assertTrue( $first['json']['success'] );
		$this->assertSame( 401, $first['json']['data']['status'] ); // Lo status del sito e' riportato com'e'.
		$this->assertCount( 2, $this->http_requests );
		$this->assertArrayNotHasKey( 'Authorization', $this->http_requests[0]['args']['headers'] );
		$this->assertNotSame( $first['json']['data']['url'], $second['json']['data']['url'] );
	}

	/**
	 * Errore di trasporto: success true con status 0 e un corpo "Errore loopback: <messaggio>".
	 */
	public function test_ajax_test_endpoint_transport_error() {
		// Il test verifica proprio la gestione dell'errore di trasporto: la chiamata bloccata e' attesa.
		$this->expect_unmocked_http();
		$this->login_as( 'administrator' );

		$outcome = $this->run_ajax( 'wphc_test_endpoint', array( 'endpoint' => 'detail_server' ) );

		$this->assertTrue( $outcome['json']['success'] );
		$this->assertSame( 0, $outcome['json']['data']['status'] );
		$this->assertStringStartsWith( 'Errore loopback: ', $outcome['json']['data']['body'] );
		$this->assertStringContainsString( 'HTTP bloccato dai test', $outcome['json']['data']['body'] );
	}

	/**
	 * Endpoint fuori whitelist o assente: 400 "Endpoint non valido." e nessuna richiesta di rete.
	 */
	public function test_ajax_test_endpoint_rejects_unknown_endpoints() {
		$this->login_as( 'administrator' );
		$this->fake_loopback( 200, '{}' );

		foreach ( array( 'update', 'detail/users', 'enroll', 'health fresh', '' ) as $value ) {
			$outcome = $this->run_ajax( 'wphc_test_endpoint', array( 'endpoint' => $value ) );
			$this->assertSame( 400, $outcome['status_header'], "[{$value}]" );
			$this->assertFalse( $outcome['json']['success'], "[{$value}]" );
			$this->assertSame( 'Endpoint non valido.', $outcome['json']['data']['message'], "[{$value}]" );
		}
		$outcome = $this->run_ajax( 'wphc_test_endpoint' ); // Parametro assente.
		$this->assertSame( 400, $outcome['status_header'] );
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * La chiave e' ripulita con sanitize_key() PRIMA del controllo: i caratteri non ammessi sono
	 * cancellati (non rifiutati), quindi "../health" diventa "health" ed e' accettata. Resta
	 * innocuo perche' il percorso REST arriva dalla whitelist, mai dal valore inviato.
	 */
	public function test_ajax_test_endpoint_key_is_sanitized_before_the_whitelist() {
		$this->login_as( 'administrator' );
		$this->fake_loopback( 200, '{}' );

		$outcome = $this->run_ajax( 'wphc_test_endpoint', array( 'endpoint' => '../health' ) );

		$this->assertTrue( $outcome['json']['success'] );
		$this->assertCount( 1, $this->http_requests );
		$url = urldecode( $this->http_requests[0]['url'] );
		$this->assertStringContainsString( 'health-check/v1/health', $url );
		$this->assertStringNotContainsString( '..', $url );
	}

	// -----------------------------------------------------------------------
	// AJAX: wphc_view_log
	// -----------------------------------------------------------------------

	/**
	 * "Visualizza log": filtri per tipo e origine (whitelist), valori non validi ignorati, forma
	 * delle righe e conteggio totale.
	 */
	public function test_ajax_view_log_filters_and_shape() {
		$this->login_as( 'administrator' );
		$base_token = $this->run_ajax( 'wphc_view_log', array( 'type' => 'token' ) )['json']['data']['total'];
		$base_all   = $this->run_ajax( 'wphc_view_log' )['json']['data']['total'];

		$this->seed_log_row( 'token', 'wphc-admin-test-a', 'wp-admin' );
		$this->seed_log_row( 'plugin', 'wphc-admin-test-b/b.php', 'cron' );

		// Filtro per tipo.
		$token_log = $this->run_ajax( 'wphc_view_log', array( 'type' => 'token' ) );
		$this->assertTrue( $token_log['json']['success'] );
		$this->assertSame( $base_token + 1, $token_log['json']['data']['total'] );
		$this->assertSame( 'wphc-admin-test-a', $token_log['json']['data']['entries'][0]['target'] ); // Piu' recente per prima.
		foreach ( $token_log['json']['data']['entries'] as $entry ) {
			$this->assertSame( 'token', $entry['type'] );
		}

		// Filtro per origine e per tipo insieme (AND).
		$both = $this->run_ajax( 'wphc_view_log', array( 'type' => 'plugin', 'source' => 'cron' ) );
		$this->assertSame( 'wphc-admin-test-b/b.php', $both['json']['data']['entries'][0]['target'] );
		$mismatch = $this->run_ajax( 'wphc_view_log', array( 'type' => 'token', 'source' => 'cron' ) );
		$this->assertNotContains( 'wphc-admin-test-b/b.php', array_column( $mismatch['json']['data']['entries'], 'target' ) );

		// Valori fuori whitelist: ignorati (nessun filtro), non un errore.
		$ignored = $this->run_ajax( 'wphc_view_log', array( 'type' => 'qualunque', 'source' => 'boh' ) );
		$this->assertSame( $base_all + 2, $ignored['json']['data']['total'] );

		// Offset oltre la fine: nessuna riga, totale invariato.
		$beyond = $this->run_ajax( 'wphc_view_log', array( 'offset' => 100000 ) );
		$this->assertSame( array(), $beyond['json']['data']['entries'] );
		$this->assertSame( $base_all + 2, $beyond['json']['data']['total'] );

		// Forma della riga (golden): id, correlation id e data sono volatili.
		$entry                   = $token_log['json']['data']['entries'][0];
		$entry['id']             = '<ID>';
		$entry['correlation_id'] = '<CORRELATION>';
		$entry['created_at']     = WPHC_Golden::ISO;
		WPHC_Golden::assert_matches( $this, 'admin/view-log-entry', WPHC_Golden::normalize( $entry ) );
		$this->assertSame( array( 'entries', 'total' ), array_keys( $token_log['json']['data'] ) );
	}

	// -----------------------------------------------------------------------
	// AJAX: wphc_reveal_secret
	// -----------------------------------------------------------------------

	/**
	 * Senza segreto registrato: 404 "Nessun segreto registrato." e nessuna riga di log.
	 */
	public function test_ajax_reveal_secret_without_secret() {
		$this->login_as( 'administrator' );

		$outcome = $this->run_ajax( 'wphc_reveal_secret' );

		$this->assertSame( 404, $outcome['status_header'] );
		$this->assertFalse( $outcome['json']['success'] );
		$this->assertSame( 'Nessun segreto registrato.', $outcome['json']['data']['message'] );
		$this->assertNull( $this->last_log_row_with_message( 'secret_revealed' ) );
	}

	/**
	 * Con un segreto: lo restituisce in chiaro (l'unico punto in cui raggiunge il browser) e lascia
	 * una riga di audit type=token, target=reveal, source=wp-admin, con l'utente che l'ha chiesto.
	 */
	public function test_ajax_reveal_secret_returns_secret_and_logs_audit_row() {
		$token = 'tok-reveal-secret-9999';
		$this->seed_enrolled_v2( $token );
		$user_id = $this->login_as( 'administrator' );
		$user    = get_userdata( $user_id );
		$before  = $this->state_before();

		$outcome = $this->run_ajax( 'wphc_reveal_secret' );

		$this->assertSame( 'die', $outcome['type'] ); // wp_send_json_success() termina con wp_die().
		$this->assertTrue( $outcome['json']['success'] );
		$this->assertSame( array( 'secret' => $token ), $outcome['json']['data'] );

		$row = $this->last_log_row_with_message( 'secret_revealed' );
		$this->assertIsArray( $row );
		$this->assertSame( 'token', $row['type'] );
		$this->assertSame( 'reveal', $row['target'] );
		$this->assertSame( 'completed', $row['phase'] );
		$this->assertSame( 'wp-admin', $row['source'] );
		$this->assertSame( $user->user_login, $row['actor'] );
		$this->assertSame( $user->display_name ? $user->display_name : $user->user_login, $row['name'] );
		$this->assertSame( self::CLIENT_IP, $row['ip'] );
		$this->assertNull( $row['version_from'] );
		$this->assertNull( $row['version_to'] );
		$this->assertNull( $row['active'] );

		// Lo stato del plugin non cambia (solo la tabella di log).
		$this->assertSame( $before['options'], $this->plugin_options() );

		// Golden della risposta (il segreto di test e' fisso).
		WPHC_Golden::assert_matches( $this, 'admin/reveal-secret-response', WPHC_Golden::normalize( $outcome['json'] ) );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: controlli di accesso comuni
	// -----------------------------------------------------------------------

	/**
	 * Gli handler di admin-post.php controllano PRIMA la capability (wp_die 403 "Non autorizzato.",
	 * anche con un nonce valido) e DOPO il nonce (wp_die 403 del core). Nessun effetto sullo stato.
	 *
	 * @dataProvider post_action_provider
	 *
	 * @param string $action Azione di admin-post.
	 */
	public function test_admin_post_checks_capability_then_nonce( $action ) {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_thumb', 'https://example.org/t.png', false );
		$before = $this->state_before();

		// 1. Senza manage_options: 403 "Non autorizzato." anche con un nonce valido.
		foreach ( array( 'editor', 'subscriber', null ) as $role ) {
			null === $role ? wp_set_current_user( 0 ) : $this->login_as( $role );

			$outcome = $this->run_admin_post( $action, array( 'wphc_webhook_url' => 'https://example.com/x' ) );

			$this->assertSame( 'die', $outcome['type'], "{$action} " . (string) $role );
			$this->assertSame( 403, $outcome['response'], "{$action} " . (string) $role );
			$this->assertSame( 'Non autorizzato.', $outcome['message'], "{$action} " . (string) $role );
		}

		// 2. Amministratore con nonce assente, errato o di un'altra azione: wp_die 403 del core.
		$this->login_as( 'administrator' );
		foreach ( array( false, 'nonce-inventato', wp_create_nonce( 'altra_azione' ) ) as $nonce ) {
			$outcome = $this->run_admin_post( $action, array(), $nonce );

			$this->assertSame( 'die', $outcome['type'], $action );
			$this->assertSame( 403, $outcome['response'], $action );
			$this->assertStringContainsString( 'The link you followed has expired', $outcome['message'], $action );
		}

		$this->assertSame( $before, $this->state_before(), "Stato modificato da {$action}" );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: wphc_self_update (solo rami negativi)
	// -----------------------------------------------------------------------

	/**
	 * Falso GitHub: l'API risponde con la release indicata, ma il download degli asset fallisce
	 * (500): l'aggiornamento si ferma PRIMA di arrivare alla pipeline di installazione, che scrive
	 * su __FILE__ (il file reale del plugin, montato nel container) e crea il .bak accanto. Nei test
	 * PHPUnit il ramo positivo e quelli che attraversano l'installazione NON si percorrono mai: il
	 * self-update riuscito, integrity_check_failed e la scrittura sono coperti solo nell'ambiente
	 * e2e, su una copia del file (tests/e2e).
	 *
	 * @param string $tag Tag della release.
	 */
	private function fake_github_release( $tag ) {
		$this->http_requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $tag ) {
				unset( $args );
				$this->http_requests[] = $url;
				if ( false !== strpos( $url, 'api.github.com' ) ) {
					return $this->http_response(
						200,
						wp_json_encode(
							array(
								'tag_name' => $tag,
								'assets'   => array(
									array(
										'name'                 => 'wp-health-check.php',
										'browser_download_url' => 'https://assets.example.invalid/wp-health-check.php',
									),
									array(
										'name'                 => 'wp-health-check.php.sha256',
										'browser_download_url' => 'https://assets.example.invalid/wp-health-check.php.sha256',
									),
									array(
										'name'                 => 'wp-health-check.php.sig',
										'browser_download_url' => 'https://assets.example.invalid/wp-health-check.php.sig',
									),
								),
							)
						)
					);
				}
				if ( false !== strpos( $url, 'assets.example.invalid' ) ) {
					// Download fallito: l'installazione non parte mai.
					return $this->http_response( 500, 'errore del server' );
				}

				return $preempt;
			},
			20,
			3
		);
	}

	/**
	 * True se si puo' scrivere in WPMU_PLUGIN_DIR: stessa prova del plugin (crea e rimuove un file
	 * temporaneo), rifatta qui per non dipendere dal nome della funzione del plugin.
	 *
	 * @return bool
	 */
	private function mu_dir_is_writable() {
		$probe   = trailingslashit( WPMU_PLUGIN_DIR ) . '.wphc-test-probe-' . wp_generate_password( 8, false, false );
		$written = false !== @file_put_contents( $probe, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
		@unlink( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

		return $written;
	}

	/**
	 * Self-update con GitHub irraggiungibile: redirect alla tab con wphc_update_failed e il codice
	 * dell'errore di rete come reason; nessuna scrittura.
	 */
	public function test_self_update_network_error_redirects_with_reason() {
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_self_update' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$redirect = $this->parse_redirect( $outcome['location'] );
		$this->assertSame( '/wp-admin/site-health.php', $redirect['path'] );
		$this->assertSame(
			array(
				'reason'             => 'wphc_update_network_error',
				'tab'                => 'wp-health-check',
				'wphc_update_failed' => '1',
			),
			$redirect['query']
		);
		$this->assert_handler_golden( 'self-update-network-error', $outcome, $before );
	}

	/**
	 * Release piu' vecchia (o uguale): redirect con wphc_uptodate=1, nessun download degli asset.
	 */
	public function test_self_update_up_to_date_redirects_without_downloading() {
		$this->login_as( 'administrator' );
		$this->fake_github_release( 'v1.0.0' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_self_update' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame(
			array(
				'tab'           => 'wp-health-check',
				'wphc_uptodate' => '1',
			),
			$this->parse_redirect( $outcome['location'] )['query']
		);
		foreach ( $this->http_requests as $url ) {
			$this->assertStringNotContainsString( 'assets.example.invalid', $url );
		}
		$this->assert_handler_golden( 'self-update-up-to-date', $outcome, $before );
	}

	/**
	 * Release piu' nuova ma asset non scaricabili: redirect con wphc_update_failed. Il motivo
	 * dipende solo dalla scrivibilita' di mu-plugins/ nel container: con la cartella scrivibile e'
	 * il codice d'errore del download, altrimenti not_writable (il controllo viene prima del
	 * download). In nessun caso si arriva alla scrittura: il file del plugin resta identico e non
	 * compare nessun backup.
	 */
	public function test_self_update_download_failure_never_reaches_the_installer() {
		$this->login_as( 'administrator' );
		$this->fake_github_release( 'v99.0.0' );
		$hash_before = md5_file( WPHC_PLUGIN_FILE );
		$writable    = $this->mu_dir_is_writable();

		$outcome = $this->run_admin_post( 'wphc_self_update' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$query = $this->parse_redirect( $outcome['location'] )['query'];
		$this->assertSame( '1', $query['wphc_update_failed'] );
		$this->assertSame( $writable ? 'wphc_update_download_failed' : 'not_writable', $query['reason'] );
		$this->assertSame( $hash_before, md5_file( WPHC_PLUGIN_FILE ) );
		$this->assertFileDoesNotExist( WPHC_PLUGIN_FILE . '.bak' );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: wphc_clear_caches
	// -----------------------------------------------------------------------

	/**
	 * "Svuota cache e ricontrolla": cancella i sette transient dell'agent e rimanda alla tab.
	 */
	public function test_clear_caches_deletes_agent_transients() {
		$this->login_as( 'administrator' );
		$names = array(
			'wphc_health_cache',
			'wphc_detail_plugins_cache',
			'wphc_detail_theme_cache',
			'wphc_detail_server_cache',
			'wphc_latest_version_cache',
			'wphc_public_ip',
			'wphc_public_ip_retry_lock',
		);
		foreach ( $names as $name ) {
			set_transient( $name, 'valore', HOUR_IN_SECONDS );
		}
		// Un transient NON dell'elenco resta (es. il contatore del throttle).
		$this->set_throttle_failures( 3 );
		$before = $this->state_before();
		// Il ricontrollo degli aggiornamenti chiama wordpress.org: una risposta 200 con JSON vuoto
		// evita il warning che il core emette (wp_trigger_error) quando la rete e' bloccata.
		$this->fake_loopback(
			200,
			wp_json_encode(
				array(
					'plugins'      => array(),
					'themes'       => array(),
					'translations' => array(),
					'no_update'    => array(),
				)
			)
		);

		$outcome = $this->run_admin_post( 'wphc_clear_caches' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame(
			array(
				'tab'          => 'wp-health-check',
				'wphc_cleared' => '1',
			),
			$this->parse_redirect( $outcome['location'] )['query']
		);
		foreach ( $names as $name ) {
			$this->assertFalse( get_transient( $name ), $name );
		}
		$this->assertSame( 3, $this->throttle_failures() );

		$after = $this->plugin_transients();
		foreach ( $names as $name ) {
			$this->assertArrayNotHasKey( $name, $after );
		}
		$this->assertArrayHasKey( 'wphc_fail_' . hash( 'sha1', self::CLIENT_IP ), $after );
		$this->assertSame( $before['options'], $this->plugin_options() );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: thumbnail
	// -----------------------------------------------------------------------

	/**
	 * Crea un allegato che rappresenta la thumbnail del sito.
	 *
	 * @return array{id:int,url:string}
	 */
	private function create_thumbnail_attachment() {
		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/png',
				'file'           => 'wp-health-check-site-thumb.png',
			)
		);

		return array(
			'id'  => $id,
			'url' => (string) wp_get_attachment_url( $id ),
		);
	}

	/**
	 * "Elimina e rigenera": elimina l'allegato e le quattro option, e il lock di cooldown.
	 */
	public function test_delete_thumbnail_removes_attachment_and_options() {
		$this->login_as( 'administrator' );
		$thumb = $this->create_thumbnail_attachment();
		update_option( 'wp_health_check_thumb', $thumb['url'], false );
		update_option( 'wp_health_check_thumb_id', $thumb['id'], false );
		update_option( 'wp_health_check_thumb_at', time(), false );
		update_option( 'wp_health_check_thumb_error', array( 'code' => 'x' ), false );
		set_transient( 'wphc_thumb_retry_lock', time(), DAY_IN_SECONDS );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_delete_thumbnail' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertNull( get_post( $thumb['id'] ) );
		foreach ( array( 'thumb', 'thumb_id', 'thumb_at', 'thumb_error' ) as $name ) {
			$this->assertFalse( get_option( 'wp_health_check_' . $name ), $name );
		}
		$this->assertFalse( get_transient( 'wphc_thumb_retry_lock' ) );
		$this->assert_handler_golden( 'delete-thumbnail', $outcome, $before );
	}

	/**
	 * Retrocompatibilita' 1.27.0: senza wp_health_check_thumb_id l'allegato si ricava dall'URL.
	 */
	public function test_delete_thumbnail_legacy_url_only() {
		$this->login_as( 'administrator' );
		$thumb = $this->create_thumbnail_attachment();
		update_option( 'wp_health_check_thumb', $thumb['url'], false );

		$outcome = $this->run_admin_post( 'wphc_delete_thumbnail' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertNull( get_post( $thumb['id'] ), 'Allegato non eliminato dalla sola URL' );
		$this->assertFalse( get_option( 'wp_health_check_thumb' ) );
	}

	/**
	 * Senza nessuna thumbnail l'handler non fallisce: redirect con wphc_thumb_deleted.
	 */
	public function test_delete_thumbnail_with_nothing_to_delete() {
		$this->login_as( 'administrator' );

		$outcome = $this->run_admin_post( 'wphc_delete_thumbnail' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( '1', $this->parse_redirect( $outcome['location'] )['query']['wphc_thumb_deleted'] );
	}

	/**
	 * "Rigenera anteprima ora": con la rete bloccata la rigenerazione fallisce in modo
	 * diagnosticabile (option thumb_error con codice e provider), il cooldown resta impostato e la
	 * thumbnail precedente e' rimossa. Il codice dipende dalla scrivibilita' di uploads/.
	 */
	public function test_regenerate_thumbnail_records_the_error() {
		// Il test verifica proprio la gestione dell'errore di trasporto: la chiamata bloccata e' attesa.
		$this->expect_unmocked_http();
		$this->login_as( 'administrator' );
		update_option( 'wp_health_check_thumb', 'https://example.org/vecchia.png', false );

		$outcome = $this->run_admin_post( 'wphc_regenerate_thumbnail' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame(
			array(
				'tab'                    => 'wp-health-check',
				'wphc_thumb_regenerated' => '1',
			),
			$this->parse_redirect( $outcome['location'] )['query']
		);
		$error = get_option( 'wp_health_check_thumb_error' );
		$this->assertIsArray( $error );
		// Codice FISSO (non due varianti): con la rete bloccata e uploads/ scrivibile il fallimento e' il download.
		$this->assertSame( 'wphc_thumb_download_failed', $error['code'] );
		$this->assertSame( WP_HEALTH_CHECK_THUMB_SERVICE, $error['provider'] );
		$this->assertFalse( get_option( 'wp_health_check_thumb' ), 'La thumbnail precedente deve sparire' );
		$this->assertNotFalse( get_transient( 'wphc_thumb_retry_lock' ) );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: reset enrollment
	// -----------------------------------------------------------------------

	/**
	 * Reset: cancella tutte le option di enrollment e il lock di update, ma NON il kill-switch
	 * degli aggiornamenti, ne' il webhook, ne' lo storico (config/audit del sito).
	 */
	public function test_reset_enrollment_clears_enrollment_but_keeps_site_config() {
		$this->login_as( 'administrator' );
		$this->seed_enrolled_v2();
		foreach ( array(
			'wp_health_check_dashboard_origin'  => 'https://dash.example.com',
			'wp_health_check_enroll_issued_at'  => time(),
			'wp_health_check_last_request_at'   => gmdate( 'c' ),
			'wp_health_check_last_request_ip'   => '203.0.113.1',
			'wp_health_check_last_enroll_error' => array( 'code' => 'x' ),
			'wp_health_check_revoked_at'        => gmdate( 'c' ),
			'wp_health_check_autologin_pending' => array( 'k' => time() ),
			'wp_health_check_last_autologin'    => array( 'at' => gmdate( 'c' ) ),
			// Option che NON devono essere toccate.
			'wp_health_check_updates_enabled'   => 0,
			'wp_health_check_webhook_url'       => 'https://hooks.example.com/x',
			'wp_health_check_trust_proxy'       => true,
		) as $name => $value ) {
			update_option( $name, $value, false );
		}
		set_transient( 'wp_health_check_update_lock', time(), 300 );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_reset_enrollment' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame(
			array(
				'tab'        => 'wp-health-check',
				'wphc_reset' => '1',
			),
			$this->parse_redirect( $outcome['location'] )['query']
		);
		$after = $this->plugin_options();
		foreach ( array( 'token', 'site_url', 'protocol', 'token_prev', 'token_rotated_at', 'revoked_at', 'secret_kid', 'enrolled_at', 'enrolled_ip', 'dashboard_origin', 'last_autologin', 'autologin_pending' ) as $name ) {
			$this->assertArrayNotHasKey( 'wp_health_check_' . $name, $after, $name );
		}
		$this->assertSame( array( 'wp_health_check_trust_proxy', 'wp_health_check_updates_enabled', 'wp_health_check_webhook_url' ), array_keys( $after ) );
		$this->assertFalse( get_transient( 'wp_health_check_update_lock' ) );

		$this->assert_handler_golden( 'reset-enrollment', $outcome, $before );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: interruttore degli aggiornamenti via API
	// -----------------------------------------------------------------------

	/**
	 * Caselle spuntate: entrambe le option diventano vere.
	 */
	public function test_toggle_updates_both_checked() {
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post(
			'wphc_toggle_updates',
			array(
				'wphc_updates_enabled'        => '1',
				'wphc_restrict_official_only' => '1',
			)
		);

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertTrue( (bool) get_option( 'wp_health_check_updates_enabled' ) );
		$this->assertTrue( (bool) get_option( 'wp_health_check_restrict_official_only' ) );
		$this->assertSame(
			array(
				'tab'                  => 'wp-health-check',
				'wphc_updates_toggled' => '1',
			),
			$this->parse_redirect( $outcome['location'] )['query']
		);
		$this->assert_handler_golden( 'toggle-updates-on', $outcome, $before );
	}

	/**
	 * Un form senza caselle (HTML: una casella non spuntata non viene inviata) spegne entrambe.
	 */
	public function test_toggle_updates_none_checked_switches_both_off() {
		$this->login_as( 'administrator' );
		update_option( 'wp_health_check_updates_enabled', true );
		update_option( 'wp_health_check_restrict_official_only', true );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_toggle_updates' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertFalse( (bool) get_option( 'wp_health_check_updates_enabled', true ) );
		$this->assertFalse( (bool) get_option( 'wp_health_check_restrict_official_only', true ) );
		$this->assert_handler_golden( 'toggle-updates-off', $outcome, $before );
	}

	/**
	 * COMPORTAMENTO SCOMODO (1.38.1, da preservare o da cambiare con decisione esplicita): su un
	 * sito dove l'option non e' mai stata scritta, togliere la spunta e salvare NON spegne gli
	 * aggiornamenti. update_option( nome, false ) con l'option assente e' un no-op di WordPress
	 * (il valore nuovo false coincide con il "vecchio" false restituito per un'option inesistente),
	 * quindi get_option( nome, true ) continua a restituire il default true.
	 */
	public function test_toggle_updates_off_on_a_fresh_site_does_not_persist() {
		$this->login_as( 'administrator' );
		$this->assertArrayNotHasKey( 'wp_health_check_updates_enabled', $this->plugin_options() );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_toggle_updates' ); // Nessuna casella spuntata.

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( '1', $this->parse_redirect( $outcome['location'] )['query']['wphc_updates_toggled'] );
		$this->assertArrayNotHasKey( 'wp_health_check_updates_enabled', $this->plugin_options() );
		$this->assertTrue( (bool) get_option( 'wp_health_check_updates_enabled', true ), 'Gli aggiornamenti via API restano attivi' );
		$this->assert_handler_golden( 'toggle-updates-off-fresh-site', $outcome, $before );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: salvataggio del webhook
	// -----------------------------------------------------------------------

	/**
	 * URL https valido: salvato in option NON autoloadata, redirect "salvato".
	 */
	public function test_save_webhook_valid_url() {
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_save_webhook', array( 'wphc_webhook_url' => '  https://93.184.216.34/wphc/in  ' ) );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( 'https://93.184.216.34/wphc/in', get_option( 'wp_health_check_webhook_url' ) );
		$this->assertSame( '1', $this->parse_redirect( $outcome['location'] )['query']['wphc_webhook_saved'] );
		global $wpdb;
		$this->assertSame( 'off', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wp_health_check_webhook_url' ) ) ); // phpcs:ignore WordPress.DB
		$this->assert_handler_golden( 'save-webhook-valid', $outcome, $before );
	}

	/**
	 * Campo vuoto: cancella URL, retry in sospeso e cron, torna al default di flotta.
	 */
	public function test_save_webhook_empty_clears_everything() {
		$this->login_as( 'administrator' );
		update_option( 'wp_health_check_webhook_url', 'https://hooks.example.com/old', false );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j', 'event' => 'e', 'body' => array(), 'attempts' => 1 ), false );
		wp_schedule_single_event( time() + 600, 'wphc_webhook_retry' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_save_webhook', array( 'wphc_webhook_url' => '   ' ) );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_url' ) );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assertSame( '1', $this->parse_redirect( $outcome['location'] )['query']['wphc_webhook_saved'] );
		$this->assert_handler_golden( 'save-webhook-empty', $outcome, $before );
	}

	/**
	 * Checkbox "disattiva": spegne tutto, anche con un URL nel campo (che viene comunque salvato).
	 */
	public function test_save_webhook_disabled_checkbox_clears_pending() {
		$this->login_as( 'administrator' );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j', 'event' => 'e', 'body' => array(), 'attempts' => 1 ), false );
		wp_schedule_single_event( time() + 600, 'wphc_webhook_retry' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post(
			'wphc_save_webhook',
			array(
				'wphc_webhook_url'      => 'https://93.184.216.34/wphc/in',
				'wphc_webhook_disabled' => '1',
			)
		);

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertTrue( (bool) get_option( 'wp_health_check_webhook_disabled' ) );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assertSame( 'https://93.184.216.34/wphc/in', get_option( 'wp_health_check_webhook_url' ) );
		$this->assert_handler_golden( 'save-webhook-disabled', $outcome, $before );
	}

	/**
	 * Casi di URL rifiutato: [valore inviato, reason attesa].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function rejected_webhook_provider() {
		return array(
			'http invece di https'      => array( 'http://93.184.216.34/x', 'not_https' ),
			'schema non http'           => array( 'ftp://93.184.216.34/x', 'bad_url' ),
			'senza schema'              => array( '93.184.216.34/x', 'not_https' ),
			'loopback (validatore WP)'  => array( 'https://127.0.0.1/x', 'bad_url' ),
			'rete privata (validatore)' => array( 'https://192.168.1.10/x', 'bad_url' ),
			'link-local (validatore)'   => array( 'https://169.254.169.254/latest', 'bad_url' ),
			'host non risolvibile'      => array( 'https://hooks.invalid/x', 'bad_url' ),
		);
	}

	/**
	 * URL rifiutato: nessun URL salvato (quello esistente resta), il valore inviato viaggia in un
	 * transient per utente (non nella query string) e il redirect porta la reason.
	 *
	 * @dataProvider rejected_webhook_provider
	 *
	 * @param string $value  Valore inviato.
	 * @param string $reason Reason attesa.
	 */
	public function test_save_webhook_rejected_url( $value, $reason ) {
		$user_id = $this->login_as( 'administrator' );
		update_option( 'wp_health_check_webhook_url', 'https://hooks.example.com/esistente', false );

		$outcome = $this->run_admin_post( 'wphc_save_webhook', array( 'wphc_webhook_url' => $value ) );

		$this->assertSame( 'redirect', $outcome['type'] );
		$query = $this->parse_redirect( $outcome['location'] )['query'];
		$this->assertSame( '1', $query['wphc_webhook_invalid'] );
		$this->assertSame( $reason, $query['reason'] );
		$this->assertStringNotContainsString( rawurlencode( $value ), $outcome['location'], 'Il valore rifiutato non deve finire nella query string' );
		$this->assertSame( 'https://hooks.example.com/esistente', get_option( 'wp_health_check_webhook_url' ) );
		$this->assertSame( $value, get_transient( 'wphc_webhook_bad_url_' . $user_id ) );
	}

	/**
	 * Snapshot (golden) dei due rifiuti piu' significativi.
	 */
	public function test_save_webhook_rejected_golden() {
		$this->login_as( 'administrator' );

		$before  = $this->state_before();
		$outcome = $this->run_admin_post( 'wphc_save_webhook', array( 'wphc_webhook_url' => 'http://93.184.216.34/x' ) );
		$this->assert_handler_golden( 'save-webhook-not-https', $outcome, $before );

		$before  = $this->state_before();
		$outcome = $this->run_admin_post( 'wphc_save_webhook', array( 'wphc_webhook_url' => 'https://hooks.invalid/x' ) );
		$this->assert_handler_golden( 'save-webhook-bad-url', $outcome, $before );
	}

	/**
	 * La guardia "blocked_host" (IP letterale non pubblico) e' difesa in profondita' DOPO il
	 * validatore di WordPress, che gia' rifiuta gli IP privati: la si raggiunge solo se il
	 * validatore e' reso permissivo con il filtro http_request_host_is_external (che WordPress
	 * offre proprio per consentire host locali). Senza il filtro lo stesso URL e' "bad_url".
	 */
	public function test_save_webhook_blocked_host_is_the_second_line_of_defence() {
		$user_id = $this->login_as( 'administrator' );
		add_filter( 'http_request_host_is_external', '__return_true' );

		$before  = $this->state_before();
		$outcome = $this->run_admin_post( 'wphc_save_webhook', array( 'wphc_webhook_url' => 'https://10.0.0.1/hook' ) );

		remove_filter( 'http_request_host_is_external', '__return_true' );
		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( 'blocked_host', $this->parse_redirect( $outcome['location'] )['query']['reason'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_url' ) );
		$this->assertSame( 'https://10.0.0.1/hook', get_transient( 'wphc_webhook_bad_url_' . $user_id ) );
		$this->assert_handler_golden( 'save-webhook-blocked-host', $outcome, $before );
	}

	// -----------------------------------------------------------------------
	// admin-post.php: webhook di prova
	// -----------------------------------------------------------------------

	/**
	 * Falso ricevente del webhook: registra la richiesta e risponde con il codice indicato.
	 *
	 * @param int $code Codice HTTP della risposta.
	 */
	private function fake_webhook_receiver( $code ) {
		$this->http_requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $code ) {
				unset( $preempt );
				$this->http_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return $this->http_response( $code, 'ricevuto' );
			},
			20,
			3
		);
	}

	/**
	 * Webhook di prova riuscito: POST firmata (HMAC sul segreto del sito) verso l'URL effettivo,
	 * evento wphc.test, id di consegna "test-...", nessun retry pianificato.
	 */
	public function test_test_webhook_success_sends_signed_post() {
		$token = 'tok-webhook-secret-777';
		$this->seed_enrolled_v2( $token );
		update_option( 'wp_health_check_webhook_url', 'https://93.184.216.34/wphc/in', false );
		$this->login_as( 'administrator' );
		$this->fake_webhook_receiver( 204 );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( '1', $this->parse_redirect( $outcome['location'] )['query']['wphc_webhook_tested'] );

		$this->assertCount( 1, $this->http_requests );
		$sent    = $this->http_requests[0];
		$headers = $sent['args']['headers'];
		$this->assertSame( 'https://93.184.216.34/wphc/in', $sent['url'] );
		$this->assertSame( 'POST', $sent['args']['method'] );
		$this->assertSame( 'wphc.test', $headers['X-WPHC-Event'] );
		$this->assertStringStartsWith( 'test-', $headers['X-WPHC-Delivery'] );
		$this->assertSame( '1', $headers['X-WPHC-Attempt'] );
		$this->assertSame( 'k1', $headers['X-WPHC-Secret-Kid'] );
		$this->assertSame( $this->own_site_url(), $headers['X-WPHC-Site'] );

		// La firma e' quella documentata: HMAC-SHA256 di "POST\n<evento>\n<sha256 body>\n<ts>\n<nonce>".
		$body      = $sent['args']['body'];
		$canonical = "POST\nwphc.test\n" . hash( 'sha256', $body ) . "\n" . $headers['X-WPHC-Timestamp'] . "\n" . $headers['X-WPHC-Nonce'];
		$this->assertSame( self::b64url( hash_hmac( 'sha256', $canonical, $token, true ) ), $headers['X-WPHC-Signature'] );

		$payload = json_decode( $body, true );
		$this->assertSame( 'wphc.test/1', $payload['schema'] );
		$this->assertSame( 'wphc.test', $payload['event'] );
		$this->assertSame( $this->own_site_url(), $payload['site'] );
		$this->assertSame( WP_HEALTH_CHECK_VERSION, $payload['agent_version'] );
		$this->assertSame( 'k1', $payload['secret_kid'] );

		// Esito registrato, nessun retry mai per il test.
		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertTrue( $last['ok'] );
		$this->assertSame( 204, $last['code'] );
		$this->assertSame( '93.184.216.34', $last['url_host'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );

		$this->assert_handler_golden( 'test-webhook-ok', $outcome, $before );
	}

	/**
	 * Il ricevente risponde 500: errore registrato come http_error ma NESSUN retry per il test.
	 */
	public function test_test_webhook_http_error_is_not_retried() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_webhook_url', 'https://93.184.216.34/wphc/in', false );
		$this->login_as( 'administrator' );
		$this->fake_webhook_receiver( 500 );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 500, $last['code'] );
		$this->assertSame( 'wphc_webhook_http_error', $last['error'] );
		$this->assertNotNull( $last['retry_at'] ); // Calcolato dal registratore, ma...
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) ); // ...mai pianificato.
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );

		$this->assert_handler_golden( 'test-webhook-http-error', $outcome, $before );
	}

	/**
	 * Errore di trasporto (rete bloccata): registrato come transport_error, nessun retry.
	 */
	public function test_test_webhook_transport_error() {
		// Il test verifica proprio la gestione dell'errore di trasporto: la chiamata bloccata e' attesa.
		$this->expect_unmocked_http();
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_webhook_url', 'https://93.184.216.34/wphc/in', false );
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'wphc_webhook_transport_error', $last['error'] );
		$this->assertSame( 0, $last['code'] );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assertContains( 'https://93.184.216.34/wphc/in', $this->blocked_http_calls );

		$this->assert_handler_golden( 'test-webhook-transport-error', $outcome, $before );
	}

	/**
	 * Con il solo default di flotta ('webhook.invalid', nome che non si risolve mai) il validatore
	 * di WordPress rifiuta l'URL: errore registrato "url_blocked", nessuna richiesta, nessun retry.
	 */
	public function test_test_webhook_unresolvable_default_url_is_blocked() {
		$this->seed_enrolled_v2();
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( 'wphc_webhook_url_blocked', get_option( 'wp_health_check_webhook_last' )['error'] );
		$this->assertSame( array(), $this->blocked_http_calls );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assert_handler_golden( 'test-webhook-default-url-blocked', $outcome, $before );
	}

	/**
	 * Sito non enrollato: errore registrato (wphc_webhook_not_enrolled), nessuna richiesta di rete.
	 */
	public function test_test_webhook_not_enrolled() {
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$last = get_option( 'wp_health_check_webhook_last' );
		$this->assertSame( 'wphc_webhook_not_enrolled', $last['error'] );
		$this->assertSame( array(), $this->blocked_http_calls );

		$this->assert_handler_golden( 'test-webhook-not-enrolled', $outcome, $before );
	}

	/**
	 * Sito revocato: tace (nessuna richiesta), cancella ogni retry pendente e registra l'errore.
	 */
	public function test_test_webhook_revoked_goes_silent_and_clears_retries() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_revoked_at', '2026-10-05T08:30:00+00:00', false );
		update_option( 'wp_health_check_webhook_pending', array( 'job_id' => 'j', 'event' => 'e', 'body' => array(), 'attempts' => 1 ), false );
		wp_schedule_single_event( time() + 600, 'wphc_webhook_retry' );
		$this->login_as( 'administrator' );
		$before = $this->state_before();

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertSame( 'wphc_webhook_revoked', get_option( 'wp_health_check_webhook_last' )['error'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_pending' ) );
		$this->assertFalse( wp_next_scheduled( 'wphc_webhook_retry' ) );
		$this->assertSame( array(), $this->blocked_http_calls );

		$this->assert_handler_golden( 'test-webhook-revoked', $outcome, $before );
	}

	/**
	 * Webhook disattivato (URL effettivo vuoto): nessun invio e nessun errore registrato; il
	 * redirect e' comunque "tested".
	 */
	public function test_test_webhook_disabled_sends_nothing() {
		$this->seed_enrolled_v2();
		update_option( 'wp_health_check_webhook_disabled', true );
		$this->login_as( 'administrator' );

		$outcome = $this->run_admin_post( 'wphc_test_webhook' );

		$this->assertSame( 'redirect', $outcome['type'] );
		$this->assertFalse( get_option( 'wp_health_check_webhook_last' ) );
		$this->assertSame( array(), $this->blocked_http_calls );
	}
}
