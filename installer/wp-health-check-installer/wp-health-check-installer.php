<?php
/**
 * Plugin Name: WP Health Check Installer
 * Description: Installa il must-use plugin wp-health-check.php scaricando l'ultima release firmata da GitHub e verificandone la firma Ed25519. Se il mu-plugin e' gia' presente ma piu' vecchio dell'ultima release lo aggiorna (anche quando il self-update non puo' farlo, es. agent < 1.35.0); se e' gia' aggiornato non fa nulla. In caso di problema lascia una notice con il motivo.
 * Version:     1.3.0
 * Author:      MAVIDA
 * Author URI:  https://mavida.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WP_Health_Check_Installer
 *
 * Questo e' un plugin NORMALE (non un mu-plugin): si installa da
 * Plugin -> Aggiungi nuovo -> Carica plugin, poi si attiva. All'attivazione
 * scarica wp-health-check.php e lo colloca in wp-content/mu-plugins/. Se il
 * mu-plugin c'e' gia' e la release e' piu' recente lo sostituisce (dalla 1.3.0):
 * serve ai siti con agent < 1.35.0, che non sanno verificare le release firmate
 * k2 e quindi non possono aggiornarsi da soli. Dopo di che puo' essere
 * disattivato/eliminato: il mu-plugin resta e si auto-aggiorna da solo.
 */

defined( 'ABSPATH' ) || exit;

/* Coordinate del repository GitHub pubblico da cui arriva il mu-plugin. */
if ( ! defined( 'WPHC_INSTALLER_GH_OWNER' ) ) {
	define( 'WPHC_INSTALLER_GH_OWNER', 'mavidasnc' );
}
if ( ! defined( 'WPHC_INSTALLER_GH_REPO' ) ) {
	define( 'WPHC_INSTALLER_GH_REPO', 'wp-health-check' );
}

/*
 * Chiave pubblica Ed25519 (kid "k2") con cui il centro firma le release del
 * plugin: la stessa WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2 del mu-plugin dalla
 * 1.35.0. E' una chiave pubblica, quindi puo' stare nel file. Senza una firma
 * valida su "release\n<tag>\n<sha256>" l'installazione si ferma: lo SHA-256
 * pubblicato nella stessa release non prova l'autenticita' (A-1, review
 * 2026-10-11).
 */
if ( ! defined( 'WPHC_INSTALLER_RELEASE_PUBKEY' ) ) {
	define( 'WPHC_INSTALLER_RELEASE_PUBKEY', 'mGJDfcvmM2lCoaq5+lJaC94mhpvrwjqJIQfjJrC/6Ko=' );
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

	// 1. Versione del mu-plugin gia' presente (stringa vuota se assente o
	// illeggibile: in quel caso si installa/sostituisce comunque).
	$installed_version = '';
	$already_present   = file_exists( $target_file );
	if ( $already_present ) {
		$file_data         = get_file_data( $target_file, array( 'Version' => 'Version' ) );
		$installed_version = (string) $file_data['Version'];
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

	// 4-bis. Mu-plugin gia' presente: si sostituisce solo con una release
	// piu' recente (mai un downgrade, mai una riscrittura inutile).
	if ( '' !== $installed_version && version_compare( $download['version'], $installed_version, '<=' ) ) {
		return array(
			'type'    => 'info',
			'message' => sprintf( 'wp-health-check %s e\' gia\' aggiornato (ultima release: %s): nessuna azione eseguita. Puoi eliminare questo installer.', $installed_version, $download['version'] ),
		);
	}

	// 5. Scrittura con funzioni native (nessuna richiesta di credenziali FTP
	// durante l'attivazione), atomica: prima un temporaneo con estensione neutra
	// (non .php, cosi' WordPress non lo carica mai a meta') nella stessa
	// cartella, poi rename(): un'interruzione non lascia un mu-plugin
	// troncato che andrebbe in fatal a ogni richiesta.
	$temp_file = $target_dir . '/wp-health-check.tmp-' . wp_generate_password( 12, false );
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink
	if ( false === @file_put_contents( $temp_file, $download['contents'] ) || ! @rename( $temp_file, $target_file ) ) {
		@unlink( $temp_file );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink
		return array(
			'type'    => 'error',
			'message' => sprintf( 'La scrittura di %s e\' fallita nonostante i permessi sembrassero corretti (possibile open_basedir, disco pieno o restrizione dell\'hosting).', $target_file ),
		);
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink

	// 6. Con opcache che non ricontrolla i timestamp la vecchia versione
	// resterebbe in memoria: la si invalida esplicitamente.
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $target_file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	if ( $already_present ) {
		return array(
			'type'    => 'success',
			'message' => sprintf( 'wp-health-check aggiornato dalla versione %s alla %s. Puoi disattivare/eliminare questo installer.', '' !== $installed_version ? $installed_version : 'sconosciuta', $download['version'] ),
		);
	}

	return array(
		'type'    => 'success',
		'message' => sprintf( 'wp-health-check %s installato in mu-plugins ed e\' gia\' attivo. Puoi disattivare/eliminare questo installer: il mu-plugin si aggiorna da solo.', $download['version'] ),
	);
}

/**
 * Interroga la release piu' recente su GitHub, scarica l'asset
 * wp-health-check.php e ne verifica integrita' e autenticita' (non vuoto,
 * prefisso "<?php", header del plugin con la versione del tag, hash SHA-256
 * dell'asset affiancato e firma Ed25519 del centro sul tag e sull'hash).
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
	$sig_url   = null;
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
				continue;
			}
			if ( 'wp-health-check.php' === $asset['name'] ) {
				$asset_url = $asset['browser_download_url'];
			} elseif ( 'wp-health-check.php.sha256' === $asset['name'] ) {
				$sha_url = $asset['browser_download_url'];
			} elseif ( 'wp-health-check.php.sig' === $asset['name'] ) {
				$sig_url = $asset['browser_download_url'];
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

	// La versione dichiarata nell'header deve coincidere con il tag della release.
	if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $contents, $header_version ) || $header_version[1] !== $version ) {
		return new WP_Error( 'wphc_installer_integrity', 'la versione nel file non coincide con il tag della release' );
	}

	// Verifica SHA-256 obbligatoria (A-3, review 2026-10-03): senza l'asset
	// affiancato o con un hash illeggibile l'installazione si ferma, invece di
	// installare un file non verificato.
	$expected = '';
	if ( null !== $sha_url ) {
		$sha_response = wp_remote_get( $sha_url, array( 'timeout' => 20 ) );
		if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
			$expected = strtolower( (string) strtok( trim( wp_remote_retrieve_body( $sha_response ) ), " \t\n" ) );
		}
	}
	if ( '' === $expected ) {
		return new WP_Error( 'wphc_installer_integrity', 'hash SHA-256 della release non disponibile: installazione annullata' );
	}
	if ( ! hash_equals( $expected, hash( 'sha256', $contents ) ) ) {
		return new WP_Error( 'wphc_installer_integrity', 'verifica SHA-256 fallita (file corrotto o manomesso)' );
	}

	// Firma Ed25519 del centro (A-1, review 2026-10-11): obbligatoria. Lo
	// SHA-256 sta nella stessa release del file, quindi chi controlla il
	// repository controlla anche l'hash; la firma no.
	$signature_error = wphc_installer_verify_signature( $sig_url, (string) $release['tag_name'], $expected );
	if ( is_wp_error( $signature_error ) ) {
		return $signature_error;
	}

	return array(
		'contents' => $contents,
		'version'  => $version,
	);
}

/**
 * Verifica la firma Ed25519 della release sull'asset .sig: JSON
 * {tag, sha256, kid, signature} con firma su "release\n<tag>\n<sha256>"
 * (stesso formato di hub/scripts/sign_release.py), kid "k2".
 *
 * @param string|null $sig_url  URL dell'asset wp-health-check.php.sig, null se assente.
 * @param string      $tag_name tag_name GitHub grezzo (con l'eventuale "v").
 * @param string      $sha256   SHA-256 gia' verificato del file scaricato.
 * @return true|WP_Error True se la firma e' valida.
 */
function wphc_installer_verify_signature( $sig_url, $tag_name, $sha256 ) {
	if ( null === $sig_url ) {
		return new WP_Error( 'wphc_installer_signature', 'la release non ha la firma (.sig): installazione annullata' );
	}
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return new WP_Error( 'wphc_installer_signature', 'estensione sodium non disponibile: impossibile verificare la firma, installazione annullata' );
	}

	$sig_response = wp_remote_get( $sig_url, array( 'timeout' => 20 ) );
	$sig_data     = null;
	if ( ! is_wp_error( $sig_response ) && 200 === (int) wp_remote_retrieve_response_code( $sig_response ) ) {
		$sig_data = json_decode( wp_remote_retrieve_body( $sig_response ), true );
	}
	if ( ! is_array( $sig_data ) || empty( $sig_data['signature'] ) || ! isset( $sig_data['kid'] ) || 'k2' !== $sig_data['kid'] ) {
		return new WP_Error( 'wphc_installer_signature', 'firma della release assente o non valida: installazione annullata' );
	}

	$pubkey    = base64_decode( WPHC_INSTALLER_RELEASE_PUBKEY, true );
	$signature = base64_decode( (string) $sig_data['signature'], true );
	if ( false === $pubkey || false === $signature || 32 !== strlen( $pubkey ) || 64 !== strlen( $signature ) ) {
		return new WP_Error( 'wphc_installer_signature', 'firma della release non valida: installazione annullata' );
	}

	try {
		$valid = sodium_crypto_sign_verify_detached( $signature, "release\n" . $tag_name . "\n" . $sha256, $pubkey );
	} catch ( Exception $e ) {
		$valid = false;
	}
	if ( ! $valid ) {
		return new WP_Error( 'wphc_installer_signature', 'firma della release non valida (release non prodotta dal centro): installazione annullata' );
	}

	return true;
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
