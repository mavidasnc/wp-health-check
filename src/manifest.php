<?php
/**
 * Manifest della build: elenco ORDINATO dei file di src/ che bin/build.php concatena
 * nell'unico mu-plugin distribuito (mu-plugins/wp-health-check.php).
 *
 * Questo file NON entra nel file distribuito: lo legge solo la build.
 *
 * Formato: array( 'nome-file.php' => 'ruolo' ), nell'ordine di concatenazione.
 * Ogni file di src/ (manifest.php escluso) deve comparire qui e viceversa: la build
 * fallisce se le due liste divergono.
 *
 * Ruoli:
 * - header:    primo file, docblock del plugin (Plugin Name, Version, ...) e guardia ABSPATH.
 *              Ne esiste uno solo e deve essere il primo.
 * - constants: costanti WP_HEALTH_CHECK_* condizionate (sovrascrivibili da wp-config.php).
 *              La versione dell'agent (WP_HEALTH_CHECK_VERSION) sta SOLO qui.
 * - class:     una sola dichiarazione di classe e nessun altro codice top-level (la build lo controlla).
 * - legacy:    corpo del plugin non ancora spostato in classi (funzioni wphc_* e registrazione degli hook).
 *              Sparisce a fine refactoring.
 * - boot:      registrazione degli hook (WPHC_Plugin::boot()), in coda alle classi.
 * - cli:        il blocco del comando WP-CLI: un guard "if ( defined( 'WP_CLI' ) && WP_CLI )" con dentro la classe
 *              WPHC_CLI_Command e la chiamata di registrazione, e nient'altro (la build lo controlla). Al massimo
 *              un file, ultimo del manifest come nel file 1.38.1; i suoi docblock sopravvivono allo strip.
 *
 * Regole di ordinamento: le classi si dichiarano prima di essere usate e il boot sta in fondo;
 * la build rifiuta una classe che estende una classe dichiarata piu' avanti.
 *
 * Durante il refactoring (vedi __docs/wp-health-check/refactoring-classi.md, sezione 3.3 e 7)
 * i file di classe entrano PRIMA di legacy.php.
 *
 * @package WP_Health_Check
 */

return array(
	'header.php'                 => 'header',
	'constants.php'              => 'constants',
	'class-wphc-options.php'     => 'class',
	'class-wphc-util.php'        => 'class',
	'class-wphc-crypto.php'      => 'class',
	'class-wphc-guard.php'       => 'class',
	'class-wphc-cors.php'        => 'class',
	'class-wphc-auth.php'        => 'class',
	'class-wphc-rest.php'        => 'class',
	'class-wphc-enrollment.php'  => 'class',
	'class-wphc-thumbnail.php'   => 'class',
	'class-wphc-health.php'      => 'class',
	'class-wphc-users.php'       => 'class',
	'class-wphc-debug.php'       => 'class',
	'class-wphc-autologin.php'   => 'class',
	'legacy.php'                 => 'legacy',
	'class-wphc-cli-command.php' => 'cli',
);
