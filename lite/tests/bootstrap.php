<?php
/**
 * Bootstrap dei test di WPHC Lite: nessun WordPress.
 *
 * Il binario phpunit carica gia' il proprio autoload (vendor/ su PHP 8.x, tests/php74/vendor su 7.4): qui si
 * ricorre a vendor/autoload.php solo se PHPUnit non e' ancora disponibile.
 *
 * @package WP_Health_Check_Lite
 */

if ( ! class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
}

// Il file dell'agent: il front controller non parte perche' SCRIPT_FILENAME e' phpunit, non questo file.
require dirname( __DIR__ ) . '/wphc-lite.php';

// Classe base dei test (non finisce con Test.php, quindi PHPUnit non la tratta come suite).
require __DIR__ . '/LiteTestCase.php';
