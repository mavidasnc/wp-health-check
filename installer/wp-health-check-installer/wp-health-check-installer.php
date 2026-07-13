<?php
/**
 * Plugin Name: WP Health Check Installer
 * Description: Installa il must-use plugin wp-health-check.php scaricando l'ultima release firmata da GitHub. Da attivare una volta sola: se il mu-plugin e' gia' presente non fa nulla. In caso di problema lascia una notice con il motivo.
 * Version:     1.0.0
 * Author:      MAVIDA
 * Author URI:  https://mavida.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WP_Health_Check_Installer
 *
 * Questo e' un plugin NORMALE (non un mu-plugin): si installa da
 * Plugin -> Aggiungi nuovo -> Carica plugin, poi si attiva. All'attivazione
 * scarica wp-health-check.php e lo colloca in wp-content/mu-plugins/. Dopo di
 * che puo' essere disattivato/eliminato: il mu-plugin resta e si auto-aggiorna
 * da solo.
 */

defined( 'ABSPATH' ) || exit;

/* Coordinate del repository GitHub pubblico da cui arriva il mu-plugin. */
if ( ! defined( 'WPHC_INSTALLER_GH_OWNER' ) ) {
	define( 'WPHC_INSTALLER_GH_OWNER', 'mavidasnc' );
}
if ( ! defined( 'WPHC_INSTALLER_GH_REPO' ) ) {
	define( 'WPHC_INSTALLER_GH_REPO', 'wp-health-check' );
}

register_activation_hook( __FILE__, 'wphc_installer_activate' );
add_action( 'admin_notices', 'wphc_installer_admin_notice' );

/**
 * Hook di attivazione: esegue l'installazione e memorizza l'esito in un
 * transient, che viene poi mostrato come admin notice al primo caricamento
 * della bacheca (durante l'attivazione non si puo' stampare nulla).
 */
function wphc_installer_activate() {
	$result = wphc_installer_do_install();
	set_transient( 'wphc_installer_notice', $result, 300 );
}

/**
 * Logica di installazione. Restituisce sempre un array
 * { type: success|info|error, message: string }.
 *
 * @return array{type: string, message: string}
 */
function wphc_installer_do_install() {
	$target_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$target_file = $target_dir . '/wp-health-check.php';

	// 1. Se il mu-plugin e' gia' presente: non fare nulla.
	if ( file_exists( $target_file ) ) {
		return array(
			'type'    => 'info',
			'message' => sprintf( 'wp-health-check e\' gia\' presente (%s): nessuna azione eseguita. Puoi eliminare questo installer.', $target_file ),
		);
	}

	// 2. Crea la cartella mu-plugins se manca.
	if ( ! is_dir( $target_dir ) ) {
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf( 'Impossibile creare la cartella mu-plugins (%s): permessi di scrittura insufficienti sul filesystem.', $target_dir ),
			);
		}
	}

	// 3. Verifica che la cartella sia scrivibile.
	if ( ! wp_is_writable( $target_dir ) ) {
		return array(
			'type'    => 'error',
			'message' => sprintf( 'La cartella mu-plugins (%s) non e\' scrivibile: correggi i permessi (es. 755) e riprova.', $target_dir ),
		);
	}

	// 4. Scarica e verifica l'ultima release da GitHub.
	$download = wphc_installer_fetch_latest_asset();
	if ( is_wp_error( $download ) ) {
		return array(
			'type'    => 'error',
			'message' => 'Download da GitHub non riuscito: ' . $download->get_error_message(),
		);
	}

	// 5. Scrittura del file con funzioni native (nessuna richiesta di
	// credenziali FTP durante l'attivazione).
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === @file_put_contents( $target_file, $download['contents'] ) ) {
		return array(
			'type'    => 'error',
			'message' => sprintf( 'La scrittura di %s e\' fallita nonostante i permessi sembrassero corretti (possibile open_basedir, disco pieno o restrizione dell\'hosting).', $target_file ),
		);
	}

	return array(
		'type'    => 'success',
		'message' => sprintf( 'wp-health-check %s installato in mu-plugins ed e\' gia\' attivo. Puoi disattivare/eliminare questo installer: il mu-plugin si aggiorna da solo.', $download['version'] ),
	);
}

/**
 * Interroga la release piu' recente su GitHub, scarica l'asset
 * wp-health-check.php e ne verifica l'integrita' (non vuoto, prefisso "<?php",
 * header del plugin e, se disponibile, l'hash SHA-256 dell'asset affiancato).
 *
 * @return array{contents: string, version: string}|WP_Error Contenuto e versione, o errore.
 */
function wphc_installer_fetch_latest_asset() {
	$api_url = sprintf(
		'https://api.github.com/repos/%s/%s/releases/latest',
		rawurlencode( WPHC_INSTALLER_GH_OWNER ),
		rawurlencode( WPHC_INSTALLER_GH_REPO )
	);

	$response = wp_remote_get(
		$api_url,
		array(
			'timeout' => 20,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'wp-health-check-installer',
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'wphc_installer_network', 'impossibile contattare GitHub (' . $response->get_error_message() . ')' );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'wphc_installer_github', 'GitHub ha risposto con codice ' . (int) wp_remote_retrieve_response_code( $response ) );
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
		return new WP_Error( 'wphc_installer_release', 'risposta della release non valida' );
	}
	$version = ltrim( (string) $release['tag_name'], 'v' );

	// Individua gli asset: il file del plugin e l'hash affiancato.
	$asset_url = null;
	$sha_url   = null;
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
				continue;
			}
			if ( 'wp-health-check.php' === $asset['name'] ) {
				$asset_url = $asset['browser_download_url'];
			} elseif ( 'wp-health-check.php.sha256' === $asset['name'] ) {
				$sha_url = $asset['browser_download_url'];
			}
		}
	}
	if ( null === $asset_url ) {
		return new WP_Error( 'wphc_installer_asset', 'asset wp-health-check.php non trovato nella release' );
	}

	$download = wp_remote_get( $asset_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $download ) || 200 !== (int) wp_remote_retrieve_response_code( $download ) ) {
		return new WP_Error( 'wphc_installer_download', 'impossibile scaricare il file del plugin' );
	}
	$contents = wp_remote_retrieve_body( $download );

	// Integrita' di base.
	if ( '' === $contents || 0 !== strpos( $contents, '<?php' ) || false === strpos( $contents, 'Plugin Name: WP Health Check' ) ) {
		return new WP_Error( 'wphc_installer_integrity', 'il file scaricato non e\' valido' );
	}

	// Verifica SHA-256 se l'asset affiancato e' disponibile.
	if ( null !== $sha_url ) {
		$sha_response = wp_remote_get( $sha_url, array( 'timeout' => 20 ) );
		if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
			$expected = strtolower( (string) strtok( trim( wp_remote_retrieve_body( $sha_response ) ), " \t\n" ) );
			if ( '' !== $expected && ! hash_equals( $expected, hash( 'sha256', $contents ) ) ) {
				return new WP_Error( 'wphc_installer_integrity', 'verifica SHA-256 fallita (file corrotto o manomesso)' );
			}
		}
	}

	return array(
		'contents' => $contents,
		'version'  => $version,
	);
}

/**
 * Mostra (una sola volta) la notice con l'esito dell'installazione.
 */
function wphc_installer_admin_notice() {
	$notice = get_transient( 'wphc_installer_notice' );
	if ( empty( $notice ) || ! is_array( $notice ) ) {
		return;
	}
	delete_transient( 'wphc_installer_notice' );

	$type    = isset( $notice['type'] ) ? $notice['type'] : 'info';
	$classes = array(
		'success' => 'notice-success',
		'error'   => 'notice-error',
		'info'    => 'notice-info',
	);
	$class   = isset( $classes[ $type ] ) ? $classes[ $type ] : 'notice-info';
	$message = isset( $notice['message'] ) ? $notice['message'] : '';

	printf(
		'<div class="notice %s is-dismissible"><p><strong>WP Health Check Installer:</strong> %s</p></div>',
		esc_attr( $class ),
		esc_html( $message )
	);
}
