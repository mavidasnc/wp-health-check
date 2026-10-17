<?php
/**
 * Plugin Name: WP Health Check (Fleet Agent)
 * Description: Must-use plugin di monitoraggio per una flotta di siti WordPress, con enroll firmato, endpoint REST protetti da token e self-update firmato dalle release di un repository GitHub pubblico.
 * Version:     1.38.1
 * Author:      MAVIDA
 * Author URI:  https://mavida.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-health-check
 *
 * @package WP_Health_Check
 *
 * ARCHITETTURA (perche' il file e' fatto cosi')
 * -----------------------------------------------------------------------
 * Questo file viene installato una sola volta a mano (SFTP/SSH) in
 * wp-content/mu-plugins/wp-health-check.php e da quel momento e' identico
 * per TUTTI i siti della flotta: WordPress carica automaticamente solo i
 * .php nella radice di mu-plugins, quindi deve restare un file singolo e
 * autoconsistente, senza autoload o dipendenze esterne a runtime.
 *
 * Vincolo architetturale fondamentale: il file NON scrive mai in
 * wp-config.php (nessun accesso SSH/SFTP ripetuto per configurare un
 * sito). Le costanti qui sotto sono valori NON segreti, hardcoded e
 * identici per tutta la flotta (sono distribuiti via GitHub, quindi
 * pubblici de facto). Tutto cio' che e' specifico del singolo sito
 * (token, IP dell'ultimo accesso, origin della dashboard...) vive nelle
 * wp_options di quel sito. Nessun segreto risiede mai sul sito: la
 * chiave incorporata qui e' una chiave PUBBLICA Ed25519, sicura da
 * versionare perche' serve solo a verificare firme, non a produrle.
 */

defined( 'ABSPATH' ) || exit;
