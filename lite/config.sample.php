<?php
/**
 * Configurazione di WPHC Lite: copiare in config.php (stessa cartella di index.php) SOLO SUL SERVER.
 * config.php non va mai versionato e va protetto dal deploy (vedi README.md).
 *
 * @package WP_Health_Check_Lite
 */

return array(
	// URL ammessi per enroll, rotate e revoke: devono coincidere con quello che l'hub ha registrato per il sito.
	// Le varianti con e senza "www." si aggiungono da sole. Il primo URL e' quello restituito come "site".
	// Mai dedotto dall'header Host.
	'site_urls'   => array(
		'https://www.esempio.com',
	),

	// Cartella dati (token, nonce, contatori, cache): FUORI dalla web root e fuori dal checkout git del sito.
	// Si crea da sola se la cartella genitore e' scrivibile.
	'data_dir'    => dirname( $_SERVER['DOCUMENT_ROOT'] ) . '/wphc-lite-data',

	// true solo se davanti a PHP c'e' un proxy fidato che imposta X-Forwarded-For e X-Forwarded-Proto
	// (valgono il valore piu' a destra). Con false si usano REMOTE_ADDR e HTTPS.
	'trust_proxy' => false,

	// OPZIONALI, per l'anteprima del sito (screenshot da thum.io, file site-thumb.png). Di norma NON servono.
	// thumb_dir: cartella dove PHP scrive l'immagine, di default quella di index.php (public/wphc-lite/).
	// thumb_url: URL pubblico dell'immagine, di default <primo site_urls>/wphc-lite/site-thumb.png.
	// Vanno impostati solo se l'agent sta in una sottocartella diversa da /wphc-lite/ o dietro un altro host.
	// 'thumb_dir' => __DIR__,
	// 'thumb_url' => 'https://www.esempio.com/wphc-lite/site-thumb.png',
);
