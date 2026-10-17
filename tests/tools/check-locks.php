<?php
/**
 * Confronta le versioni delle dipendenze di TEST nei due composer.lock: quello principale
 * (PHP 8.1, strumenti di sviluppo) e quello della matrice PHP 7.4 (tests/php74).
 *
 * La suite gira su entrambi: se phpunit, le polyfill o la test library di WordPress avessero
 * versioni diverse nei due lock, un verde su 8.1 e un verde su 7.4 non proverebbero la stessa cosa.
 * Esce con codice 1 alla prima differenza.
 *
 * Uso (dalla radice del progetto): composer run test:check-locks
 *
 * @package WP_Health_Check
 */

$root     = dirname( __DIR__, 2 );
$locks    = array(
	'composer.lock'            => $root . '/composer.lock',
	'tests/php74/composer.lock' => $root . '/tests/php74/composer.lock',
);
$packages = array( 'phpunit/phpunit', 'yoast/phpunit-polyfills', 'wp-phpunit/wp-phpunit' );

// Versioni per lock: nome del pacchetto => versione.
$versions = array();
foreach ( $locks as $label => $path ) {
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "Lock illeggibile: {$path}\n" );
		exit( 1 );
	}
	foreach ( array_merge( $data['packages'], isset( $data['packages-dev'] ) ? $data['packages-dev'] : array() ) as $package ) {
		if ( in_array( $package['name'], $packages, true ) ) {
			$versions[ $label ][ $package['name'] ] = $package['version'];
		}
	}
}

// Confronto: ogni pacchetto deve esserci in entrambi i lock con la stessa versione.
$failed = false;
foreach ( $packages as $name ) {
	$found = array();
	foreach ( array_keys( $locks ) as $label ) {
		$found[ $label ] = isset( $versions[ $label ][ $name ] ) ? $versions[ $label ][ $name ] : '(assente)';
	}
	$same = 1 === count( array_unique( $found ) ) && '(assente)' !== reset( $found );
	echo ( $same ? 'OK       ' : 'DIVERSE  ' ) . $name . ': ' . implode( ' | ', $found ) . "\n";
	$failed = $failed || ! $same;
}

exit( $failed ? 1 : 0 );
