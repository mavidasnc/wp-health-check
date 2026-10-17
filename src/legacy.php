<?php

// Entrambe le famiglie servono: su multisite update_site_option() e' quella
// che effettivamente scrive (i suoi hook update_option_*/add_option_* NON
// scattano li'); su single-site update_site_option() ricade su
// update_option() e sono invece gli hook update_option_*/add_option_* a
// scattare. Registrare solo una famiglia lascerebbe l'altro contesto stantio.
add_action( 'update_site_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
add_action( 'add_site_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
add_action( 'update_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
add_action( 'add_option_auto_update_plugins', array( 'WPHC_Health', 'flush_plugins_detail_cache' ) );
add_action( 'update_site_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
add_action( 'add_site_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
add_action( 'update_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
add_action( 'add_option_auto_update_themes', array( 'WPHC_Health', 'flush_theme_detail_cache' ) );
foreach ( array( 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major' ) as $wphc_core_auto_option ) {
	add_action( "update_site_option_{$wphc_core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
	add_action( "add_site_option_{$wphc_core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
	add_action( "update_option_{$wphc_core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
	add_action( "add_option_{$wphc_core_auto_option}", array( 'WPHC_Health', 'flush_health_cache_only' ) );
}
unset( $wphc_core_auto_option );

// -----------------------------------------------------------------------
// CORS
// -----------------------------------------------------------------------

add_filter( 'rest_pre_serve_request', array( 'WPHC_Cors', 'reassert_headers' ), 20, 3 );

add_filter( 'rest_pre_dispatch', array( 'WPHC_Cors', 'handle_options_preflight' ), 10, 3 );

// -----------------------------------------------------------------------
// REGISTRAZIONE ROTTE REST
// -----------------------------------------------------------------------

add_action( 'rest_api_init', array( 'WPHC_Rest', 'register_routes' ) );

add_action( 'init', array( 'WPHC_Update_Log', 'maybe_install_schema' ) );

add_filter( 'upgrader_pre_install', array( 'WPHC_Update_Log', 'snapshot_version_before_upgrade' ), 10, 2 );

add_action( 'upgrader_process_complete', array( 'WPHC_Update_Log', 'log_wp_initiated_update' ), 10, 2 );

add_action( 'deactivated_plugin', array( 'WPHC_Update_Log', 'log_plugin_deactivation' ), 10, 2 );

add_action( 'delete_plugin', array( 'WPHC_Update_Log', 'snapshot_plugin_before_delete' ) );

add_action( 'deleted_plugin', array( 'WPHC_Update_Log', 'log_plugin_deletion' ), 10, 2 );

add_action( 'init', array( 'WPHC_Update_Log', 'maybe_log_core_version_change' ) );

add_action( 'init', array( 'WPHC_Bulk', 'maybe_rearm' ) );

add_action( 'wphc_bulk_update_tick', array( 'WPHC_Bulk', 'run_tick' ) );

add_action( 'wphc_webhook_retry', array( 'WPHC_Webhook', 'maybe_retry' ) );

add_action( 'init', array( 'WPHC_Autologin', 'maybe_consume' ) );

add_action( 'wp_login', array( 'WPHC_Users', 'record_last_login' ), 10, 2 );

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
function wphc_register_site_health_tab( $tabs ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $tabs;
	}

	$tabs['wp-health-check'] = __( 'WP Health Check', 'wp-health-check' );

	return $tabs;
}
add_filter( 'site_health_navigation_tabs', 'wphc_register_site_health_tab' );

/**
 * Renderizza il contenuto della tab "WP Health Check": versioni installata e
 * disponibile, stato di enrollment, URL da usare per l'enroll, ultimo enroll
 * fallito, ultimo accesso, pulsante di self-update e pulsante di reset.
 *
 * @param string $tab Slug della tab richiesta.
 */
function wphc_render_site_health_tab( $tab ) {
	if ( 'wp-health-check' !== $tab || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_enrolled             = ! empty( get_option( 'wp_health_check_token' ) );
	$signed_site_url         = (string) get_option( 'wp_health_check_site_url', '' );
	$enrolled_at             = get_option( 'wp_health_check_enrolled_at' );
	$enrolled_ip             = get_option( 'wp_health_check_enrolled_ip' );
	$last_request_at         = get_option( 'wp_health_check_last_request_at' );
	$last_request_ip         = get_option( 'wp_health_check_last_request_ip' );
	$trust_proxy             = (bool) get_option( 'wp_health_check_trust_proxy', false );
	$last_enroll_error       = get_option( 'wp_health_check_last_enroll_error' );
	$updates_via_api_enabled = (bool) get_option( 'wp_health_check_updates_enabled', true );
	$restrict_official_only  = (bool) get_option( 'wp_health_check_restrict_official_only', false );
	$thumbnail               = get_option( 'wp_health_check_thumb' );
	$current_secret          = (string) get_option( 'wp_health_check_token', '' );
	$secret_kid              = (string) get_option( 'wp_health_check_secret_kid', '' );
	$secret_rotated_at       = get_option( 'wp_health_check_token_rotated_at' );
	$secret_revoked_at       = get_option( 'wp_health_check_revoked_at' );
	$protocol_version        = (int) get_option( 'wp_health_check_protocol', 1 );
	// $webhook_url_raw e' il SOLO override del sito (campo del form): resta
	// vuoto se il sito non ha impostato nulla, anche quando il default di
	// flotta e' attivo. $webhook_url e' invece l'URL EFFETTIVO (con fallback
	// al default), usato solo per decidere se mostrare il pulsante di test e
	// per il testo informativo sotto il campo.
	$webhook_url_raw   = (string) get_option( 'wp_health_check_webhook_url', '' );
	$webhook_disabled  = (bool) get_option( 'wp_health_check_webhook_disabled', false );
	$webhook_url       = WPHC_Webhook::url();
	$webhook_last      = get_option( 'wp_health_check_webhook_last' );
	$webhook_url_field = $webhook_url_raw;
	$webhook_bad_url   = get_transient( 'wphc_webhook_bad_url_' . get_current_user_id() );
	if ( false !== $webhook_bad_url ) {
		$webhook_url_field = $webhook_bad_url;
		delete_transient( 'wphc_webhook_bad_url_' . get_current_user_id() );
	}

	// Il segreto non viene MAI stampato per intero nell'HTML della pagina
	// (finirebbe in cache di pagina, view-source, screenshot automatici):
	// qui solo una versione mascherata e un fingerprint, sufficienti a
	// verificare "e' il segreto giusto?" senza esporlo. Il valore completo
	// arriva al browser solo in risposta al pulsante "Mostra" (vedi
	// wphc_ajax_reveal_secret()).
	$secret_masked = '';
	if ( '' !== $current_secret ) {
		$secret_masked = ( strlen( $current_secret ) > 10 )
			? substr( $current_secret, 0, 6 ) . str_repeat( '•', 8 ) . substr( $current_secret, -4 )
			: str_repeat( '•', strlen( $current_secret ) );
	}
	$secret_fingerprint = ( '' !== $current_secret ) ? substr( hash( 'sha256', $current_secret ), 0, 12 ) : '';
	$thumb_error        = get_option( 'wp_health_check_thumb_error' );

	$candidates       = WPHC_Util::candidate_site_urls();
	$canonical_home   = WPHC_Util::normalize_site_url();
	$latest_version   = WPHC_Self_Updater::get_latest_version();
	$update_available = ( null !== $latest_version ) && version_compare( $latest_version, WP_HEALTH_CHECK_VERSION, '>' );

	// Conteggi plugin/temi. Siamo in contesto amministrativo: i transient
	// degli update sono quelli completi mantenuti dal cron/admin (premium
	// inclusi), quindi questi numeri rispecchiano la schermata Plugin/Temi.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$all_plugins     = get_plugins();
	$plugins_total   = count( $all_plugins );
	$plugins_active  = count( (array) get_option( 'active_plugins', array() ) );
	$upd_plugins_t   = get_site_transient( 'update_plugins' );
	$plugins_updates = ( is_object( $upd_plugins_t ) && ! empty( $upd_plugins_t->response ) ) ? count( $upd_plugins_t->response ) : 0;

	$all_themes        = wp_get_themes();
	$themes_total      = count( $all_themes );
	$active_theme_name = (string) wp_get_theme()->get( 'Name' );
	$upd_themes_t      = get_site_transient( 'update_themes' );
	$themes_updates    = ( is_object( $upd_themes_t ) && ! empty( $upd_themes_t->response ) ) ? count( $upd_themes_t->response ) : 0;
	?>
	<div class="health-check-body health-check-wp-health-check-tab hide-if-no-js">
		<h2><?php esc_html_e( 'WP Health Check — Fleet Agent', 'wp-health-check' ); ?></h2>

		<?php if ( isset( $_GET['wphc_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag di stato post-redirect, nessuna azione qui. ?>
			<div class="notice notice-success"><p>
				<?php
				$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf(
					/* translators: %s: versione a cui e' stato aggiornato il plugin. */
					esc_html__( 'Plugin aggiornato alla versione %s.', 'wp-health-check' ),
					'<code>' . esc_html( $to ) . '</code>'
				);
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_uptodate'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'Il plugin e\' gia\' aggiornato all\'ultima versione disponibile.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_update_failed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p>
				<?php
				$reason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf(
					/* translators: %s: motivo macchina del fallimento dell'aggiornamento. */
					esc_html__( 'Aggiornamento non riuscito (%s). Nessuna modifica al file del plugin.', 'wp-health-check' ),
					'<code>' . esc_html( $reason ) . '</code>'
				);
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Cache dell\'agent svuotate e aggiornamenti ricontrollati.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_updates_toggled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Preferenza sugli aggiornamenti via API salvata.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_thumb_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Anteprima del sito eliminata: verra\' rigenerata alla prossima chiamata /health.', 'wp-health-check' ); ?></p></div>
		<?php elseif ( isset( $_GET['wphc_thumb_regenerated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( get_option( 'wp_health_check_thumb' ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Anteprima rigenerata con successo.', 'wp-health-check' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Rigenerazione non riuscita: vedi il dettaglio dell\'errore nella riga "Anteprima sito" qui sotto.', 'wp-health-check' ); ?></p></div>
			<?php endif; ?>
		<?php elseif ( isset( $_GET['wphc_webhook_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p>
				<?php
				echo '' === $webhook_url
					? esc_html__( 'Webhook disattivato.', 'wp-health-check' )
					: esc_html__( 'URL del webhook salvato.', 'wp-health-check' );
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_webhook_invalid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error"><p>
				<?php
				$webhook_reason        = isset( $_GET['reason'] ) ? sanitize_key( wp_unslash( $_GET['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$webhook_reason_labels = array(
					'not_https'    => __( 'L\'URL deve usare https.', 'wp-health-check' ),
					'blocked_host' => __( 'L\'host indicato e\' un indirizzo privato o di loopback: non e\' consentito.', 'wp-health-check' ),
					'bad_url'      => __( 'URL non valido.', 'wp-health-check' ),
				);
				$webhook_reason_label  = isset( $webhook_reason_labels[ $webhook_reason ] ) ? $webhook_reason_labels[ $webhook_reason ] : __( 'URL non valido.', 'wp-health-check' );
				echo esc_html( $webhook_reason_label ) . ' <code>' . esc_html( $webhook_reason ) . '</code>';
				?>
			</p></div>
		<?php elseif ( isset( $_GET['wphc_webhook_tested'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( is_array( $webhook_last ) && ! empty( $webhook_last['ok'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Webhook di prova inviato con successo.', 'wp-health-check' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Invio del webhook di prova non riuscito: vedi il dettaglio qui sotto.', 'wp-health-check' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>

		<table class="widefat striped" style="max-width: 800px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Versione plugin installata', 'wp-health-check' ); ?></td>
					<td><code><?php echo esc_html( WP_HEALTH_CHECK_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Ultima versione disponibile', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( null === $latest_version ) : ?>
							<?php esc_html_e( 'non determinabile (GitHub irraggiungibile)', 'wp-health-check' ); ?>
						<?php elseif ( $update_available ) : ?>
							<code><?php echo esc_html( $latest_version ); ?></code>
							<span class="dashicons dashicons-update" style="color:#d63638;"></span>
							<strong><?php esc_html_e( 'aggiornamento disponibile', 'wp-health-check' ); ?></strong>
						<?php else : ?>
							<code><?php echo esc_html( $latest_version ); ?></code> — <?php esc_html_e( 'aggiornato', 'wp-health-check' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Anteprima sito', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( $thumbnail ) : ?>
							<p>
								<img src="<?php echo esc_url( $thumbnail ); ?>" width="200" alt="<?php esc_attr_e( 'Anteprima del sito', 'wp-health-check' ); ?>" style="height:auto;border:1px solid #ccc;">
							</p>
							<form
								method="post"
								action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Eliminare l\'anteprima? Verra\' rigenerata alla prossima chiamata /health.', 'wp-health-check' ) ) ); ?> );"
							>
								<input type="hidden" name="action" value="wphc_delete_thumbnail" />
								<?php wp_nonce_field( 'wphc_delete_thumbnail' ); ?>
								<?php submit_button( __( 'Elimina e rigenera', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
							</form>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'non ancora generata', 'wp-health-check' ); ?></p>
						<?php endif; ?>
						<form
							method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							style="margin-top:6px;"
						>
							<input type="hidden" name="action" value="wphc_regenerate_thumbnail" />
							<?php wp_nonce_field( 'wphc_regenerate_thumbnail' ); ?>
							<?php submit_button( __( 'Rigenera anteprima ora', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
						</form>
						<?php if ( is_array( $thumb_error ) && ! empty( $thumb_error['code'] ) ) : ?>
							<p class="description" style="color:#d63638;">
								<?php
								printf(
									/* translators: 1: codice macchina dell'errore, 2: messaggio diagnostico, 3: data/ora ISO 8601. */
									esc_html__( 'Ultimo errore: %1$s — %2$s (%3$s)', 'wp-health-check' ),
									esc_html( $thumb_error['code'] ),
									esc_html( isset( $thumb_error['message'] ) ? $thumb_error['message'] : '' ),
									esc_html( isset( $thumb_error['at'] ) ? $thumb_error['at'] : '' )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Repository GitHub', 'wp-health-check' ); ?></td>
					<td><code><?php echo esc_html( WP_HEALTH_CHECK_GH_OWNER . '/' . WP_HEALTH_CHECK_GH_REPO ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Stato enrollment', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( $is_enrolled ) : ?>
							<?php esc_html_e( 'Registrato', 'wp-health-check' ); ?>
							<?php if ( $enrolled_at ) : ?>
								<?php
								printf(
									/* translators: 1: data/ora ISO 8601 dell'enroll, 2: IP del chiamante. */
									esc_html__( '(il %1$s da %2$s)', 'wp-health-check' ),
									esc_html( $enrolled_at ),
									esc_html( $enrolled_ip ? $enrolled_ip : '—' )
								);
								?>
							<?php endif; ?>
						<?php else : ?>
							<?php esc_html_e( 'Non registrato (in attesa di /enroll)', 'wp-health-check' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Segreto di flotta', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( ! empty( $secret_revoked_at ) ) : ?>
							<strong style="color:#d63638;"><?php esc_html_e( 'Revocato', 'wp-health-check' ); ?></strong>
							<?php
							printf(
								/* translators: %s: data/ora ISO 8601 della revoca. */
								esc_html__( '(il %s)', 'wp-health-check' ),
								esc_html( $secret_revoked_at )
							);
							?>
						<?php elseif ( '' !== $secret_masked ) : ?>
							<code id="wphc-secret-masked"><?php echo esc_html( $secret_masked ); ?></code>
							<button type="button" class="button button-small" id="wphc-secret-reveal-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wphc_reveal_secret' ) ); ?>">
								<?php esc_html_e( 'Mostra', 'wp-health-check' ); ?>
							</button>
							<input type="text" id="wphc-secret-full" readonly style="display:none;width:26em;" />
							<button type="button" class="button button-small" id="wphc-secret-copy-btn" style="display:none;">
								<?php esc_html_e( 'Copia', 'wp-health-check' ); ?>
							</button>
							<p class="description">
								<?php
								printf(
									/* translators: 1: fingerprint SHA-256 troncato, 2: protocollo (1 o 2), 3: data di rotazione o "mai". */
									esc_html__( 'Fingerprint: %1$s — protocollo: %2$d — ultima rotazione: %3$s.', 'wp-health-check' ),
									esc_html( $secret_fingerprint ),
									(int) $protocol_version,
									esc_html( $secret_rotated_at ? gmdate( 'c', (int) $secret_rotated_at ) : __( 'mai', 'wp-health-check' ) )
								);
								?>
								<?php esc_html_e( 'E\' la credenziale con cui il sistema centrale governa questo sito: chi la legge puo\' impersonarlo fino alla prossima rotazione. La rotazione e la revoca sono operazioni del centro, non disponibili da qui.', 'wp-health-check' ); ?>
							</p>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'URL firmato registrato', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( '' !== $signed_site_url ) : ?>
							<code><?php echo esc_html( $signed_site_url ); ?></code>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'URL esatto che il sistema centrale ha firmato in fase di enroll: e\' la chiave a cui e\' legato il token. Utile a diagnosticare mismatch su siti WPML o con varianti www/non-www.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'URL validi per l\'enroll', 'wp-health-check' ); ?></td>
					<td>
						<ul style="margin:0;">
							<?php foreach ( $candidates as $candidate ) : ?>
								<li>
									<code><?php echo esc_html( $candidate ); ?></code>
									<?php if ( $candidate === $canonical_home ) : ?>
										<em>(<?php esc_html_e( 'principale', 'wp-health-check' ); ?>)</em>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							<?php esc_html_e( 'Il sistema centrale deve firmare il site_url usando UNO di questi URL (confronto tollerante www/non-www). Quello marcato "principale" e\' l\'URL canonico del sito.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Ultimo enroll fallito', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( is_array( $last_enroll_error ) ) : ?>
							<strong><?php echo esc_html( isset( $last_enroll_error['reason'] ) ? $last_enroll_error['reason'] : '' ); ?></strong><br />
							<span class="description">
								<?php echo esc_html( isset( $last_enroll_error['code'] ) ? $last_enroll_error['code'] : '' ); ?>
								<?php if ( ! empty( $last_enroll_error['at'] ) ) : ?>
									— <?php echo esc_html( $last_enroll_error['at'] ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $last_enroll_error['ip'] ) ) : ?>
									— <?php echo esc_html( $last_enroll_error['ip'] ); ?>
								<?php endif; ?>
							</span>
							<?php if ( ! empty( $last_enroll_error['received'] ) ) : ?>
								<br /><?php esc_html_e( 'URL inviato:', 'wp-health-check' ); ?>
								<code><?php echo esc_html( $last_enroll_error['received'] ); ?></code>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Motivo dell\'ultimo tentativo di enroll fallito (azzerato automaticamente al primo enroll riuscito). Utile per capire perche\' un enroll viene rifiutato e con quale URL.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Ultimo accesso registrato', 'wp-health-check' ); ?></td>
					<td>
						<?php if ( $last_request_at ) : ?>
							<?php echo esc_html( $last_request_at ); ?> — <?php echo esc_html( $last_request_ip ? $last_request_ip : '—' ); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Trust proxy (X-Forwarded-For)', 'wp-health-check' ); ?></td>
					<td>
						<?php echo $trust_proxy ? esc_html__( 'Attivo', 'wp-health-check' ) : esc_html__( 'Disattivo', 'wp-health-check' ); ?>
						<p class="description">
							<?php esc_html_e( 'Sola lettura qui: va attivato solo manualmente (wp option update wp_health_check_trust_proxy 1) e solo se un proxy/CDN fidato sovrascrive sempre l\'header, mai di default.', 'wp-health-check' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Riepilogo plugin e temi', 'wp-health-check' ); ?></h3>
		<table class="widefat striped" style="max-width: 800px;">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Totali', 'wp-health-check' ); ?></th>
					<th><?php esc_html_e( 'Attivi', 'wp-health-check' ); ?></th>
					<th><?php esc_html_e( 'Da aggiornare', 'wp-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Plugin', 'wp-health-check' ); ?></strong></td>
					<td><?php echo (int) $plugins_total; ?></td>
					<td><?php echo (int) $plugins_active; ?></td>
					<td>
						<?php echo (int) $plugins_updates; ?>
						<?php if ( $plugins_updates > 0 ) : ?>
							<span class="dashicons dashicons-update" style="color:#d63638;"></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Temi', 'wp-health-check' ); ?></strong></td>
					<td><?php echo (int) $themes_total; ?></td>
					<td>1</td>
					<td>
						<?php echo (int) $themes_updates; ?>
						<?php if ( $themes_updates > 0 ) : ?>
							<span class="dashicons dashicons-update" style="color:#d63638;"></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php
			/* translators: %s: nome del tema attivo. */
			printf( esc_html__( 'Tema attivo: %s. Conteggi degli aggiornamenti letti dai transient del cron (come la bacheca); usa il pulsante "Svuota cache e ricontrolla" se sembrano non aggiornati.', 'wp-health-check' ), '<strong>' . esc_html( $active_theme_name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</p>

		<h3><?php esc_html_e( 'Test degli endpoint', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Esegue una chiamata reale all\'endpoint (loopback lato server, con il bearer token e un cache-buster _cb casuale ad ogni chiamata) e mostra la risposta in una finestra. Il token non viene mai esposto nel browser.', 'wp-health-check' ); ?>
		</p>
		<div id="wphc-endpoint-tester" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wphc_test_endpoint' ) ); ?>">
			<button type="button" class="button wphc-test-btn" data-endpoint="health">GET /health</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="health_fresh">GET /health?fresh=1</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_plugins">GET /detail/plugins</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_plugins_fresh">GET /detail/plugins?fresh=1</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_theme">GET /detail/theme</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_theme_fresh">GET /detail/theme?fresh=1</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_server">GET /detail/server</button>
			<button type="button" class="button wphc-test-btn" data-endpoint="detail_server_fresh">GET /detail/server?fresh=1</button>
		</div>

		<h3><?php esc_html_e( 'Log degli aggiornamenti', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Consultazione dello storico registrato nella tabella di log (stessi dati di GET /update/log), filtrabile per tipo e per origine: distingue gli aggiornamenti avviati via API da quelli fatti dalla bacheca di WordPress, da un auto-update in background o da WP-CLI.', 'wp-health-check' ); ?>
		</p>
		<div id="wphc-log-viewer" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wphc_view_log' ) ); ?>">
			<select id="wphc-log-type">
				<option value=""><?php esc_html_e( 'Tutti i tipi', 'wp-health-check' ); ?></option>
				<option value="plugin"><?php esc_html_e( 'Plugin', 'wp-health-check' ); ?></option>
				<option value="theme"><?php esc_html_e( 'Temi', 'wp-health-check' ); ?></option>
				<option value="core"><?php esc_html_e( 'Core', 'wp-health-check' ); ?></option>
				<option value="token"><?php esc_html_e( 'Token autologin', 'wp-health-check' ); ?></option>
				<option value="login"><?php esc_html_e( 'Accesso autologin', 'wp-health-check' ); ?></option>
			</select>
			<select id="wphc-log-source">
				<option value=""><?php esc_html_e( 'Tutte le origini', 'wp-health-check' ); ?></option>
				<option value="api"><?php esc_html_e( 'API', 'wp-health-check' ); ?></option>
				<option value="wp-admin"><?php esc_html_e( 'wp-admin', 'wp-health-check' ); ?></option>
				<option value="cron"><?php esc_html_e( 'Cron (auto-update)', 'wp-health-check' ); ?></option>
				<option value="wp-cli"><?php esc_html_e( 'WP-CLI', 'wp-health-check' ); ?></option>
			</select>
			<button type="button" class="button" id="wphc-log-view-btn"><?php esc_html_e( 'Visualizza log', 'wp-health-check' ); ?></button>
		</div>

		<div id="wphc-modal" class="wphc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="wphc-modal-title">
			<div class="wphc-modal-box">
				<div class="wphc-modal-head">
					<strong id="wphc-modal-title"></strong>
					<button type="button" class="button-link wphc-modal-close" aria-label="<?php esc_attr_e( 'Chiudi', 'wp-health-check' ); ?>">&times;</button>
				</div>
				<p id="wphc-modal-meta" class="description" style="margin:.5em 0;word-break:break-all;"></p>
				<pre id="wphc-modal-body"></pre>
				<div id="wphc-modal-table" style="display:none;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Data', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Elemento', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Versione', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Esito', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Origine', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'Attore', 'wp-health-check' ); ?></th>
								<th><?php esc_html_e( 'IP', 'wp-health-check' ); ?></th>
							</tr>
						</thead>
						<tbody id="wphc-log-tbody"></tbody>
					</table>
					<p style="margin-top:10px;">
						<button type="button" class="button" id="wphc-log-load-more" style="display:none;"><?php esc_html_e( 'Carica altri 50', 'wp-health-check' ); ?></button>
					</p>
				</div>
			</div>
		</div>

		<style>
			.wphc-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:5vh 16px;box-sizing:border-box;}
			.wphc-modal-box{background:#fff;width:80%;max-height:88vh;overflow:auto;border-radius:4px;padding:14px 20px 20px;box-shadow:0 4px 24px rgba(0,0,0,.3);}
			.wphc-modal-head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #dcdcde;padding-bottom:8px;margin-bottom:6px;}
			.wphc-modal-close{font-size:22px;line-height:1;text-decoration:none;color:#646970;}
			#wphc-modal-body{background:#1d2327;color:#f0f0f1;padding:12px;border-radius:3px;overflow:auto;max-height:60vh;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.5;margin:0;}
			#wphc-modal-table table{font-size:12px;}
			#wphc-modal-table td, #wphc-modal-table th{word-break:break-word;}
			#wphc-endpoint-tester .button{margin:0 6px 6px 0;}
			#wphc-log-viewer{margin-bottom:10px;}
			#wphc-log-viewer select{margin:0 6px 6px 0;}
			.wphc-status-ok{color:#008a20;font-weight:600;}
			.wphc-status-bad{color:#d63638;font-weight:600;}
		</style>

		<script>
			( function () {
				var modal     = document.getElementById( 'wphc-modal' );
				var titleEl   = document.getElementById( 'wphc-modal-title' );
				var metaEl    = document.getElementById( 'wphc-modal-meta' );
				var bodyEl    = document.getElementById( 'wphc-modal-body' );
				var tableWrap = document.getElementById( 'wphc-modal-table' );
				var tbody     = document.getElementById( 'wphc-log-tbody' );

				// mode 'table' mostra la tabella del log e nasconde il <pre> del
				// tester endpoint (e viceversa): la modale e' condivisa fra le due
				// funzionalita', non duplicata.
				function openModal( title, mode ) {
					titleEl.textContent      = title;
					metaEl.textContent       = 'Chiamata in corso…';
					bodyEl.textContent       = '';
					bodyEl.style.display     = ( 'table' === mode ) ? 'none' : '';
					tableWrap.style.display  = ( 'table' === mode ) ? '' : 'none';
					modal.style.display      = 'flex';
				}
				function closeModal() { modal.style.display = 'none'; }
				function prettify( txt ) {
					try { return JSON.stringify( JSON.parse( txt ), null, 2 ); } catch ( e ) { return txt; }
				}
				function escapeHtml( s ) { var d = document.createElement( 'div' ); d.textContent = s; return d.innerHTML; }

				var wrap = document.getElementById( 'wphc-endpoint-tester' );
				if ( wrap ) {
					var nonce = wrap.dataset.nonce;
					wrap.querySelectorAll( '.wphc-test-btn' ).forEach( function ( btn ) {
						btn.addEventListener( 'click', function () {
							openModal( btn.textContent.trim(), 'json' );
							var fd = new FormData();
							fd.append( 'action', 'wphc_test_endpoint' );
							fd.append( 'endpoint', btn.getAttribute( 'data-endpoint' ) );
							fd.append( '_ajax_nonce', nonce );
							fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
								.then( function ( r ) { return r.json(); } )
								.then( function ( res ) {
									if ( res && res.success ) {
										var d = res.data;
										var cls = ( d.status >= 200 && d.status < 300 ) ? 'wphc-status-ok' : 'wphc-status-bad';
										metaEl.innerHTML = 'HTTP <span class="' + cls + '">' + d.status + '</span> · ' + d.took_ms + ' ms<br>' + escapeHtml( d.url );
										bodyEl.textContent = prettify( d.body );
									} else {
										metaEl.textContent = 'Errore';
										bodyEl.textContent = JSON.stringify( res );
									}
								} )
								.catch( function ( e ) { metaEl.textContent = 'Errore di rete'; bodyEl.textContent = String( e ); } );
						} );
					} );
				}

				// Visualizzatore del log: stessa modale, contenuto tabellare
				// costruito con createElement/textContent (mai innerHTML) perche'
				// 'message'/'actor'/'target' sono dati che arrivano dal server.
				var logWrap = document.getElementById( 'wphc-log-viewer' );
				if ( logWrap ) {
					var logNonce    = logWrap.dataset.nonce;
					var typeSelect  = document.getElementById( 'wphc-log-type' );
					var srcSelect   = document.getElementById( 'wphc-log-source' );
					var viewBtn     = document.getElementById( 'wphc-log-view-btn' );
					var loadMoreBtn = document.getElementById( 'wphc-log-load-more' );
					var state       = { type: '', source: '', offset: 0, total: 0, shown: 0 };

					function renderRows( entries ) {
						entries.forEach( function ( entry ) {
							var tr     = document.createElement( 'tr' );
							var vers   = ( entry.version_from || '—' ) + ' → ' + ( entry.version_to || '—' );
							var cells  = [ entry.created_at, entry.type, entry.target, vers, entry.phase, entry.source, entry.actor || '—', entry.ip || '—' ];
							cells.forEach( function ( val ) {
								var td = document.createElement( 'td' );
								td.textContent = val;
								tr.appendChild( td );
							} );
							tbody.appendChild( tr );
						} );
						state.shown += entries.length;
					}

					function loadPage() {
						metaEl.textContent = 'Caricamento…';
						var fd = new FormData();
						fd.append( 'action', 'wphc_view_log' );
						fd.append( 'type', state.type );
						fd.append( 'source', state.source );
						fd.append( 'offset', state.offset );
						fd.append( '_ajax_nonce', logNonce );
						fetch( ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) {
								if ( res && res.success ) {
									state.total = res.data.total;
									renderRows( res.data.entries );
									state.offset += res.data.entries.length;
									metaEl.textContent = state.shown + ' di ' + state.total + ' righe';
									loadMoreBtn.style.display = ( state.offset < state.total ) ? 'inline-block' : 'none';
								} else {
									metaEl.textContent = 'Errore';
								}
							} )
							.catch( function () { metaEl.textContent = 'Errore di rete'; } );
					}

					viewBtn.addEventListener( 'click', function () {
						state.type   = typeSelect.value;
						state.source = srcSelect.value;
						state.offset = 0;
						state.shown  = 0;
						tbody.innerHTML = '';
						openModal( 'Log degli aggiornamenti', 'table' );
						loadPage();
					} );

					loadMoreBtn.addEventListener( 'click', loadPage );
				}

				modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { closeModal(); } } );
				document.querySelector( '.wphc-modal-close' ).addEventListener( 'click', closeModal );
				document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { closeModal(); } } );
			}() );
		</script>

		<script>
			// Pulsanti "Mostra"/"Copia" della riga "Segreto di flotta" (dalla
			// 1.30.0): il valore in chiaro arriva SOLO qui, via AJAX, mai
			// stampato nel markup della pagina.
			( function () {
				var revealBtn = document.getElementById( 'wphc-secret-reveal-btn' );
				if ( ! revealBtn ) {
					return;
				}
				var fullField = document.getElementById( 'wphc-secret-full' );
				var copyBtn   = document.getElementById( 'wphc-secret-copy-btn' );

				revealBtn.addEventListener( 'click', function () {
					var body = new FormData();
					body.append( 'action', 'wphc_reveal_secret' );
					body.append( '_ajax_nonce', revealBtn.dataset.nonce );

					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( ! data.success ) {
								window.alert( ( data.data && data.data.message ) || 'Errore' );
								return;
							}
							fullField.value = data.data.secret;
							fullField.style.display = 'inline-block';
							copyBtn.style.display = 'inline-block';
							revealBtn.style.display = 'none';
						} );
				} );

				if ( copyBtn ) {
					copyBtn.addEventListener( 'click', function () {
						fullField.select();
						if ( navigator.clipboard ) {
							navigator.clipboard.writeText( fullField.value );
						} else {
							document.execCommand( 'copy' );
						}
					} );
				}
			}() );
		</script>

		<h3><?php esc_html_e( 'Aggiornamento del plugin', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Scarica e installa l\'ultima release firmata da GitHub (stesso flusso di POST /update: verifica integrita\' SHA-256, backup e ripristino automatico in caso di errore).', 'wp-health-check' ); ?>
		</p>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Confermi l\'aggiornamento del plugin all\'ultima release pubblicata su GitHub?', 'wp-health-check' ) ) ); ?> );"
		>
			<input type="hidden" name="action" value="wphc_self_update" />
			<?php wp_nonce_field( 'wphc_self_update' ); ?>
			<?php
			if ( $update_available ) {
				/* translators: %s: versione disponibile. */
				$update_label = sprintf( __( 'Aggiorna alla versione %s', 'wp-health-check' ), $latest_version );
				submit_button( $update_label, 'primary', 'submit', false );
			} else {
				submit_button( __( 'Verifica e aggiorna adesso', 'wp-health-check' ), 'secondary', 'submit', false );
			}
			?>
		</form>

		<h3><?php esc_html_e( 'Aggiornamenti plugin, temi e core via API', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Interruttore master: quando spento, le rotte POST /update/plugin, /update/theme e /update/core rifiutano ogni richiesta con 403 (GET /update/log resta sempre leggibile). Di default e\' aggiornabile qualsiasi plugin/tema, non solo quelli ospitati su wordpress.org (vedi checkbox sotto); rollback automatico via temp-backup nativo di WordPress per plugin e temi.', 'wp-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_toggle_updates" />
			<?php wp_nonce_field( 'wphc_toggle_updates' ); ?>
			<p>
				<label>
					<input type="checkbox" name="wphc_updates_enabled" value="1" <?php checked( $updates_via_api_enabled ); ?> />
					<?php esc_html_e( 'Consenti aggiornamenti (plugin, temi, core) via API', 'wp-health-check' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wphc_restrict_official_only" value="1" <?php checked( $restrict_official_only ); ?> />
					<?php esc_html_e( 'Limita gli aggiornamenti ai soli pacchetti ospitati su wordpress.org (esclude i plugin/temi premium)', 'wp-health-check' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Salva', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Webhook di notifica aggiornamenti bulk', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'A fine di ogni job di aggiornamento bulk (POST /update/bulk), se configurato, viene inviata una POST firmata con HMAC-SHA256 sul segreto di flotta (header X-WPHC-*) contenente il dettaglio di cosa e\' stato fatto. Deve essere un URL https; nessun token nella query string e\' necessario, l\'autenticazione e\' nella firma.', 'wp-health-check' ); ?>
		</p>
		<p class="description">
			<?php if ( $webhook_disabled ) : ?>
				<?php esc_html_e( 'Webhook disattivato esplicitamente per questo sito: nessuna notifica viene inviata.', 'wp-health-check' ); ?>
			<?php elseif ( '' !== $webhook_url_raw ) : ?>
				<?php esc_html_e( 'In uso l\'URL impostato qui sotto per questo sito.', 'wp-health-check' ); ?>
			<?php else : ?>
				<?php
				printf(
					/* translators: %s: URL di default della flotta. */
					esc_html__( 'Nessun URL impostato per questo sito: in uso il default di flotta %s. Compila il campo per sovrascriverlo, o spunta "disattiva" per non inviare nulla.', 'wp-health-check' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				);
				?>
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_save_webhook" />
			<?php wp_nonce_field( 'wphc_save_webhook' ); ?>
			<p>
				<input type="url" class="regular-text code" name="wphc_webhook_url" id="wphc-webhook-url"
					value="<?php echo esc_attr( $webhook_url_field ); ?>"
					placeholder="https://hub.esempio.com/wphc/webhook" />
			</p>
			<p>
				<label>
					<input type="checkbox" name="wphc_webhook_disabled" value="1" <?php checked( $webhook_disabled ); ?> />
					<?php esc_html_e( 'Non inviare notifiche webhook per questo sito (ignora anche il default di flotta)', 'wp-health-check' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Salva', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( '' !== $webhook_url ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wphc_test_webhook" />
				<?php wp_nonce_field( 'wphc_test_webhook' ); ?>
				<?php submit_button( __( 'Invia un webhook di prova', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php if ( is_array( $webhook_last ) ) : ?>
			<p class="description" <?php echo empty( $webhook_last['ok'] ) ? 'style="color:#d63638;"' : ''; ?>>
				<?php
				printf(
					/* translators: 1: data/ora, 2: esito, 3: codice HTTP, 4: codice errore macchina, 5: host di destinazione. */
					esc_html__( 'Ultimo invio: %1$s — %2$s (http %3$s%4$s) verso %5$s', 'wp-health-check' ),
					esc_html( $webhook_last['at'] ),
					empty( $webhook_last['ok'] ) ? esc_html__( 'fallito', 'wp-health-check' ) : esc_html__( 'riuscito', 'wp-health-check' ),
					esc_html( (string) $webhook_last['code'] ),
					empty( $webhook_last['error'] ) ? '' : ', ' . esc_html( $webhook_last['error'] ),
					esc_html( (string) $webhook_last['url_host'] )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Nessun webhook inviato finora.', 'wp-health-check' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Svuota cache e ricontrolla aggiornamenti', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Cancella le cache dell\'agent (transient wphc_*: health, dettaglio plugin/tema/server, ultima versione) e forza un ricontrollo COMPLETO degli aggiornamenti di core, plugin e temi. A differenza di ?fresh=1 (che gira via REST), qui siamo in contesto amministrativo: anche i plugin/temi premium vengono ricontrollati correttamente. Usalo se i conteggi o le versioni degli aggiornamenti sembrano sbagliati.', 'wp-health-check' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wphc_clear_caches" />
			<?php wp_nonce_field( 'wphc_clear_caches' ); ?>
			<?php submit_button( __( 'Svuota cache e ricontrolla', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3><?php esc_html_e( 'Reset enrollment', 'wp-health-check' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Cancella token, URL firmato e tutti i metadati di enrollment. Il sito torna allo stato "non registrato" finche\' il sistema centrale non ripete l\'enroll. Equivalente a "wp health-check reset".', 'wp-health-check' ); ?>
		</p>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Confermi il reset dell\'enrollment? Il sito restera\' non registrato finche\' il sistema centrale non ripete l\'enroll.', 'wp-health-check' ) ) ); ?> );"
		>
			<input type="hidden" name="action" value="wphc_reset_enrollment" />
			<?php wp_nonce_field( 'wphc_reset_enrollment' ); ?>
			<?php submit_button( __( 'Resetta enrollment', 'wp-health-check' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
add_action( 'site_health_tab_content', 'wphc_render_site_health_tab' );

/**
 * Handler AJAX (admin) del tester di endpoint della tab Site Health. Fa una
 * richiesta loopback all'endpoint REST richiesto, aggiungendo il bearer token
 * (lato server: non viene mai esposto al browser) e un cache-buster "_cb"
 * casuale, e restituisce status/corpo/latenza da mostrare nella modale. Solo
 * endpoint GET dati in whitelist: nessun effetto collaterale.
 */
function wphc_ajax_test_endpoint() {
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
	$token   = (string) get_option( 'wp_health_check_token', '' );
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
add_action( 'wp_ajax_wphc_test_endpoint', 'wphc_ajax_test_endpoint' );

/**
 * Handler AJAX (admin) del pulsante "Visualizza log" della tab Site Health:
 * legge la stessa tabella di GET /update/log tramite WPHC_Update_Log::get_entries(),
 * ma con una query diretta invece che via loopback REST, perche' qui
 * l'utente e' gia' autenticato in wp-admin e non serve il bearer token.
 */
function wphc_ajax_view_log() {
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
add_action( 'wp_ajax_wphc_view_log', 'wphc_ajax_view_log' );

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
function wphc_ajax_reveal_secret() {
	check_ajax_referer( 'wphc_reveal_secret' );
	if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! current_user_can( 'manage_network' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'wp-health-check' ) ), 403 );
	}

	$secret = (string) get_option( 'wp_health_check_token', '' );
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
add_action( 'wp_ajax_wphc_reveal_secret', 'wphc_ajax_reveal_secret' );

/**
 * Handler di admin-post.php per il pulsante di self-update nella tab Site
 * Health: esegue lo stesso flusso condiviso di POST /update
 * (WPHC_Self_Updater::perform_self_update()) e reindirizza alla tab con l'esito
 * (pattern POST-redirect-GET). Non richiede enrollment: e' un'azione
 * amministrativa, protetta da manage_options + nonce.
 */
function wphc_handle_self_update() {
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
add_action( 'admin_post_wphc_self_update', 'wphc_handle_self_update' );

/**
 * Handler di admin-post.php per il pulsante "Svuota cache e ricontrolla":
 * cancella le cache dell'agent (transient wphc_*) e forza un ricontrollo
 * COMPLETO degli aggiornamenti di core/plugin/temi. Gira in contesto
 * amministrativo (admin-post), dove gli update-checker dei plugin/temi
 * premium sono attivi: a differenza di ?fresh=1 via REST, qui
 * wp_update_plugins()/wp_update_themes() ricostruiscono transient COMPLETI.
 */
function wphc_handle_clear_caches() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_clear_caches' );

	// 1. Cache dei payload dell'agent.
	delete_transient( 'wphc_health_cache' );
	delete_transient( 'wphc_detail_plugins_cache' );
	delete_transient( 'wphc_detail_theme_cache' );
	delete_transient( 'wphc_detail_server_cache' );
	delete_transient( 'wphc_latest_version_cache' );
	delete_transient( 'wphc_public_ip' );
	delete_transient( 'wphc_public_ip_retry_lock' );

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
add_action( 'admin_post_wphc_clear_caches', 'wphc_handle_clear_caches' );

/**
 * Handler di admin-post.php per il pulsante "Elimina e rigenera" della
 * riga "Anteprima sito": elimina l'attachment dal Media Library e le
 * opzioni collegate, cosi' la prossima /health rigenera la thumbnail.
 * Il delete_transient() finale rimuove anche l'eventuale cooldown residuo,
 * per non far attendere l'utente che ha appena chiesto una rigenerazione.
 */
function wphc_handle_delete_thumbnail() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_delete_thumbnail' );

	$attachment_id = (int) get_option( 'wp_health_check_thumb_id' );
	if ( ! $attachment_id ) {
		// Retrocompatibilita' con le thumbnail create dalla 1.27.0, che
		// salvava solo l'URL (wp_health_check_thumb_id non esisteva ancora).
		$attachment_id = attachment_url_to_postid( (string) get_option( 'wp_health_check_thumb' ) );
	}
	if ( $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}

	delete_option( 'wp_health_check_thumb' );
	delete_option( 'wp_health_check_thumb_id' );
	delete_option( 'wp_health_check_thumb_error' );
	delete_option( 'wp_health_check_thumb_at' );
	delete_transient( 'wphc_thumb_retry_lock' );

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
add_action( 'admin_post_wphc_delete_thumbnail', 'wphc_handle_delete_thumbnail' );

/**
 * Handler di admin-post.php per il pulsante "Rigenera anteprima ora" (dalla
 * 1.30.0): a differenza di "Elimina e rigenera" (che si limita a cancellare
 * e demanda la rigenerazione alla prossima /health), questo chiama
 * WPHC_Thumbnail::maybe_generate( true ) sincronamente nella stessa richiesta
 * admin, cosi' un eventuale errore e' visibile subito nella riga "Anteprima
 * sito" invece di scoprirlo solo al prossimo polling.
 */
function wphc_handle_regenerate_thumbnail() {
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
add_action( 'admin_post_wphc_regenerate_thumbnail', 'wphc_handle_regenerate_thumbnail' );

/**
 * Handler di admin-post.php per il pulsante di reset enrollment nella tab
 * Site Health: stessa logica condivisa con "wp health-check reset"
 * (WPHC_Enrollment::reset()), poi redirect alla tab (POST-redirect-GET).
 */
function wphc_handle_reset_enrollment() {
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
add_action( 'admin_post_wphc_reset_enrollment', 'wphc_handle_reset_enrollment' );

/**
 * Handler di admin-post.php per i checkbox "Consenti aggiornamenti (plugin,
 * temi, core) via API" e "Limita gli aggiornamenti ai soli pacchetti
 * ospitati su wordpress.org" nella tab Site Health: stesso form/nonce, unico
 * interruttore master (§5 della specifica) da cui dipendono le rotte
 * POST /update/{plugin,theme,core}, piu' la sotto-opzione che ne restringe
 * l'host del pacchetto (vedi WPHC_Item_Updater::is_package_host_allowed()).
 */
function wphc_handle_toggle_updates() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_toggle_updates' );

	update_option( 'wp_health_check_updates_enabled', ! empty( $_POST['wphc_updates_enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().
	update_option( 'wp_health_check_restrict_official_only', ! empty( $_POST['wphc_restrict_official_only'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

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
add_action( 'admin_post_wphc_toggle_updates', 'wphc_handle_toggle_updates' );

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
function wphc_handle_save_webhook() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_save_webhook' );

	$raw      = isset( $_POST['wphc_webhook_url'] ) ? trim( (string) wp_unslash( $_POST['wphc_webhook_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().
	$disabled = ! empty( $_POST['wphc_webhook_disabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificato sopra da check_admin_referer().

	update_option( 'wp_health_check_webhook_disabled', $disabled );
	if ( $disabled ) {
		// Nessuna notifica deve restare in volo (retry pianificato) quando
		// l'operatore ha appena chiesto di spegnere tutto, a prescindere dal
		// campo URL sotto.
		delete_option( 'wp_health_check_webhook_pending' );
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
	}

	if ( '' === $raw ) {
		delete_option( 'wp_health_check_webhook_url' );
		delete_option( 'wp_health_check_webhook_pending' );
		wp_clear_scheduled_hook( 'wphc_webhook_retry' );
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
		set_transient( 'wphc_webhook_bad_url_' . get_current_user_id(), $raw, 5 * MINUTE_IN_SECONDS );
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

	update_option( 'wp_health_check_webhook_url', $url, false );

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
add_action( 'admin_post_wphc_save_webhook', 'wphc_handle_save_webhook' );

/**
 * Handler di admin-post.php per "Invia un webhook di prova": invio sincrono
 * dell'evento wphc.test, MAI un retry pianificato (a differenza del report di
 * fine job bulk). Unico modo pratico per un admin di verificare che la
 * propria integrazione HMAC sia corretta prima che giri un job reale.
 */
function wphc_handle_test_webhook() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Non autorizzato.', 'wp-health-check' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wphc_test_webhook' );

	$payload = array(
		'schema'        => 'wphc.test/1',
		'event'         => 'wphc.test',
		'site'          => WPHC_Util::normalize_site_url(),
		'agent_version' => WP_HEALTH_CHECK_VERSION,
		'secret_kid'    => (string) get_option( 'wp_health_check_secret_kid', '' ),
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
add_action( 'admin_post_wphc_test_webhook', 'wphc_handle_test_webhook' );
