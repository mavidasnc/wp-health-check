<?php
/**
 * Genera la coppia di chiavi Ed25519 del sistema centrale.
 *
 * Utility CLI, ESTERNA al plugin: gira una tantum sulla macchina/servizio
 * del sistema centrale (non su un sito della flotta), per produrre:
 * - la chiave PUBBLICA, da incollare come costante WP_HEALTH_CHECK_CENTRAL_PUBKEY
 *   nel file mu-plugins/wp-health-check.php prima del deploy in flotta;
 * - la chiave PRIVATA, da custodire esclusivamente lato centro (mai nel
 *   repository, mai su un sito) perche' e' quella che firma le buste di
 *   /enroll e da cui deriva anche il MASTER_SECRET dei token.
 *
 * Uso:
 *     php bin/generate-keys.php
 *
 * @package WP_Health_Check
 */

// phpcs:disable WordPress -- script CLI puro, fuori dal contesto WordPress: le
// regole WPCS (prefissi globali, I18N, ecc.) non si applicano qui.

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Questo script va eseguito da riga di comando: php bin/generate-keys.php\n" );
	exit( 1 );
}

if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "Estensione sodium non disponibile in questo PHP CLI (libsodium e' nel core da PHP 7.2).\n" );
	exit( 1 );
}

$keypair     = sodium_crypto_sign_keypair();
$public_key  = sodium_crypto_sign_publickey( $keypair );
$private_key = sodium_crypto_sign_secretkey( $keypair );

$public_key_b64  = base64_encode( $public_key );
$private_key_b64 = base64_encode( $private_key );

echo "==================================================================\n";
echo " Coppia di chiavi Ed25519 generata.\n";
echo "==================================================================\n\n";

echo "Chiave PUBBLICA (sicura da versionare, va nel plugin):\n";
echo $public_key_b64 . "\n\n";

echo "Da incollare in mu-plugins/wp-health-check.php:\n";
echo "    define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY', '" . $public_key_b64 . "' );\n\n";

echo "------------------------------------------------------------------\n";
echo "Chiave PRIVATA (SEGRETA: custodirla solo lato sistema centrale, mai\n";
echo "nel repository, mai su un sito della flotta, mai in chiaro nei log):\n";
echo $private_key_b64 . "\n";
echo "------------------------------------------------------------------\n";
