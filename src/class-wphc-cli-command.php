<?php
/**
 * Comandi WP-CLI del fleet agent: wp health-check reset | secret | status.
 *
 * @package WP_Health_Check
 */

// -----------------------------------------------------------------------
// COMANDI WP-CLI: wp health-check reset | secret | status
// -----------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Comandi WP-CLI del fleet agent wp-health-check.
	 *
	 * Definita solo dentro il branch WP_CLI per non caricare la classe
	 * su ogni richiesta HTTP normale, dove non serve mai.
	 */
	class WPHC_CLI_Command {

		/**
		 * Cancella le opzioni di enrollment di questo sito.
		 *
		 * E' una utility operativa di re-provisioning/offboarding (es.
		 * sito che cambia dominio, o va rimosso dalla flotta), NON un
		 * meccanismo di scadenza del token: per progetto il token non
		 * scade mai da solo. Dopo il reset il sito torna nello stato
		 * "non enrolled" (503 su tutte le rotte dati) finche' il sistema
		 * centrale non ripete l'enroll.
		 *
		 * ## EXAMPLES
		 *
		 *     wp health-check reset
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Argomenti posizionali (non usati).
		 * @param array $assoc_args Argomenti nominali (non usati).
		 */
		public function reset( $args, $assoc_args ) {
			unset( $args, $assoc_args );

			WPHC_Enrollment::reset();

			WP_CLI::success( 'Enrollment resettato: il sito e\' tornato allo stato "non registrato".' );
		}

		/**
		 * Mostra fingerprint, protocollo, data di rotazione e stato di revoca
		 * del segreto di flotta corrente, senza aprire wp-admin.
		 *
		 * Con --show stampa il segreto per intero, in chiaro: utile per un
		 * recupero d'emergenza da shell (es. per confrontarlo manualmente con
		 * quanto conservato dal centro), dove non c'e' un browser da cui il
		 * valore potrebbe essere esfiltrato via XSS.
		 *
		 * ## OPTIONS
		 *
		 * [--show]
		 * : Stampa anche il segreto in chiaro, non solo il fingerprint.
		 *
		 * ## EXAMPLES
		 *
		 *     wp health-check secret
		 *     wp health-check secret --show
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Argomenti posizionali (non usati).
		 * @param array $assoc_args Argomenti nominali (--show).
		 */
		public function secret( $args, $assoc_args ) {
			unset( $args );

			$current = (string) get_option( WPHC_Options::OPT_TOKEN, '' );
			if ( '' === $current ) {
				WP_CLI::error( 'Nessun segreto registrato (sito non ancora enrollato).' );
			}

			$revoked_at = get_option( WPHC_Options::OPT_REVOKED_AT );
			$rotated_at = get_option( WPHC_Options::OPT_TOKEN_ROTATED_AT );

			WP_CLI::log( 'Fingerprint: ' . substr( hash( 'sha256', $current ), 0, 12 ) );
			WP_CLI::log( 'Protocollo: ' . (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 ) );
			WP_CLI::log( 'Ultima rotazione: ' . ( $rotated_at ? gmdate( 'c', (int) $rotated_at ) : 'mai' ) );
			WP_CLI::log( 'Revocato: ' . ( $revoked_at ? $revoked_at : 'no' ) );

			if ( ! empty( $assoc_args['show'] ) ) {
				WP_CLI::log( 'Segreto: ' . $current );
			}
		}

		/**
		 * Riepilogo diagnostico dello stato del sito: enrollment, versione,
		 * protocollo, ultimo errore di enroll e stato della thumbnail. Quanto
		 * serve per diagnosticare un sito senza aprire wp-admin.
		 *
		 * ## EXAMPLES
		 *
		 *     wp health-check status
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Argomenti posizionali (non usati).
		 * @param array $assoc_args Argomenti nominali (non usati).
		 */
		public function status( $args, $assoc_args ) {
			unset( $args, $assoc_args );

			$is_enrolled = ! empty( get_option( WPHC_Options::OPT_TOKEN ) );
			$last_error  = get_option( WPHC_Options::OPT_LAST_ENROLL_ERROR );
			$thumb_error = get_option( WPHC_Options::OPT_THUMB_ERROR );

			WP_CLI::log( 'Versione agent: ' . WP_HEALTH_CHECK_VERSION );
			WP_CLI::log( 'Enrollment: ' . ( $is_enrolled ? 'registrato' : 'non registrato' ) );
			WP_CLI::log( 'Protocollo: ' . (int) get_option( WPHC_Options::OPT_PROTOCOL, 1 ) );
			WP_CLI::log( 'Revocato: ' . ( get_option( WPHC_Options::OPT_REVOKED_AT ) ? 'si\'' : 'no' ) );
			WP_CLI::log( 'Ultimo enroll fallito: ' . ( is_array( $last_error ) ? ( $last_error['code'] . ' — ' . $last_error['reason'] ) : 'nessuno' ) );
			WP_CLI::log( 'Thumbnail: ' . ( get_option( WPHC_Options::OPT_THUMB ) ? 'presente' : ( is_array( $thumb_error ) ? ( 'errore: ' . $thumb_error['code'] ) : 'non ancora generata' ) ) );
		}
	}

	WP_CLI::add_command( 'health-check', 'WPHC_CLI_Command' );
}
