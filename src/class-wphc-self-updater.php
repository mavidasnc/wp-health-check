<?php
/**
 * Self-update dell'agent: ricerca della release (hub, GitHub), download, installazione firmata e rotta POST /update.
 *
 * @package WP_Health_Check
 */

/**
 * Auto-aggiornamento dell'agent stesso: ricerca dell'ultima release (prima l'hub, poi l'API di GitHub, poi il
 * redirect di github.com senza API), download degli asset, installazione con verifica di integrita' e firma k2
 * e callback della rotta POST /update (pull, oppure push con il pacchetto spedito dall'hub).
 *
 * Raccoglie la sezione L del file 1.38.1. L'ordine dei passi di install_release() e' VINCOLANTE (versione,
 * preflight di scrittura, integrita', firma, backup, scrittura atomica, sanity check, rollback, opcache, pulizia):
 * ogni passo che fallisce interrompe il flusso PRIMA di toccare il file di produzione.
 *
 * Il file riscritto e' quello della costante WP_HEALTH_CHECK_FILE (src/constants.php, decisione D-1), che nel
 * file distribuito vale __FILE__ del mu-plugin come nella 1.38.1. Il file temporaneo e il probe di scrittura
 * stanno invece in WPMU_PLUGIN_DIR: la costante va tenuta sullo stesso filesystem di quella cartella.
 */
final class WPHC_Self_Updater {

	// -----------------------------------------------------------------------
	// CALLBACK: POST /update
	// -----------------------------------------------------------------------

	/**
	 * Argomenti comuni delle richieste verso GitHub (API, download degli asset).
	 * L'API di GitHub rifiuta con 403 ogni richiesta senza User-Agent: lo si
	 * imposta esplicitamente su tutte le richieste invece di affidarsi al default
	 * di WordPress, che un plugin o un proxy del sito possono alterare con il
	 * filtro http_headers_useragent.
	 *
	 * @param int                  $timeout       Timeout in secondi.
	 * @param array<string,string> $extra_headers Header aggiuntivi.
	 * @return array<string,mixed> Argomenti per wp_remote_get().
	 */
	private static function github_request_args( $timeout, $extra_headers = array() ) {
		return array(
			'timeout' => $timeout,
			'headers' => array_merge(
				array( 'User-Agent' => 'wp-health-check-agent/' . WP_HEALTH_CHECK_VERSION ),
				$extra_headers
			),
		);
	}

	/**
	 * Ultima release del plugin su GitHub, nella forma dell'API (tag_name e
	 * assets[] con name e browser_download_url), oppure un array di errore.
	 *
	 * Prima l'API (api.github.com/.../releases/latest). L'API non autenticata
	 * concede 60 richieste l'ora per indirizzo IP: sugli hosting condivisi molti
	 * siti hanno lo stesso IP in uscita e un aggiornamento di massa esaurisce il
	 * limite, con 403 su tutti. Se l'API risponde con un errore o non e'
	 * raggiungibile si ripiega su github.com, che non ha quel limite: il redirect
	 * di /releases/latest indica il tag e gli asset hanno URL prevedibili
	 * (/releases/download/<tag>/<nome>). Gli asset sono comunque verificati dallo
	 * SHA-256 e dalla firma del centro, quindi la fonte del tag non e' un
	 * perimetro di fiducia.
	 *
	 * Dalla 1.38.0 la prima fonte e' l'hub (WP_HEALTH_CHECK_RELEASE_URL): una
	 * release servita dall'hub porta 'wphc_source' => 'hub', cosi' chi scarica
	 * puo' ripiegare su GitHub se gli asset dell'hub non rispondono. Se l'hub non
	 * risponde o la sua risposta non e' valida si prosegue con GitHub.
	 *
	 * @param int  $timeout  Timeout in secondi per ogni richiesta.
	 * @param bool $skip_hub Se true, salta l'hub e interroga solo GitHub.
	 * @return array<string,mixed> La release (con "tag_name"), oppure
	 *                              array( 'code' => ..., 'http' => int, 'detail' => string ).
	 */
	private static function fetch_latest_release( $timeout = 15, $skip_hub = false ) {
		if ( ! $skip_hub ) {
			$from_hub = self::fetch_latest_release_from_hub( $timeout );
			if ( null !== $from_hub ) {
				return $from_hub;
			}
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( WP_HEALTH_CHECK_GH_OWNER ),
			rawurlencode( WP_HEALTH_CHECK_GH_REPO )
		);

		$response = wp_remote_get( $api_url, self::github_request_args( $timeout, array( 'Accept' => 'application/vnd.github+json' ) ) );

		if ( is_wp_error( $response ) ) {
			$failure = array(
				'code'   => 'wphc_update_network_error',
				'http'   => 0,
				'detail' => $response->get_error_message(),
			);
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$failure = array(
				'code'   => 'wphc_update_github_error',
				'http'   => (int) wp_remote_retrieve_response_code( $response ),
				/* translators: %d: codice HTTP restituito da GitHub. */
				'detail' => sprintf( __( 'HTTP %d', 'wp-health-check' ), (int) wp_remote_retrieve_response_code( $response ) ),
			);
		} else {
			$release = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $release ) && ! empty( $release['tag_name'] ) ) {
				return $release;
			}
			$failure = array(
				'code'   => 'wphc_update_bad_release',
				'http'   => 200,
				'detail' => '',
			);
		}

		$fallback = self::fetch_latest_release_without_api( $timeout );
		if ( null !== $fallback ) {
			return $fallback;
		}

		return $failure;
	}

	/**
	 * True se il tag ha la forma di una versione (es. v1.38.0): niente altro
	 * finisce negli URL degli asset o nel messaggio firmato.
	 *
	 * @param mixed $tag Tag da controllare.
	 * @return bool
	 */
	private static function is_release_tag( $tag ) {
		return is_string( $tag ) && 1 === preg_match( '/^v?\d+\.\d+\.\d+(?:[-+.][0-9A-Za-z.-]+)?$/', $tag );
	}

	/**
	 * Release piu' recente servita dall'hub, nella forma dell'API GitHub. Gli
	 * asset sono accettati solo se stanno sotto lo stesso percorso dell'URL
	 * dell'hub (.../agent-release/): una risposta anomala non puo' far scaricare
	 * da altrove. Nessun errore dettagliato: qualunque problema vale "fonte non
	 * disponibile" e il chiamante ripiega su GitHub.
	 *
	 * @param int $timeout Timeout in secondi.
	 * @return array<string,mixed>|null La release (con 'wphc_source' => 'hub'), o null.
	 */
	private static function fetch_latest_release_from_hub( $timeout ) {
		$url = (string) WP_HEALTH_CHECK_RELEASE_URL;
		// La costante e' sovrascrivibile in wp-config.php, anche con stringa vuota.
		if ( '' === $url || false === strrpos( $url, '/' ) ) { // @phpstan-ignore identical.alwaysFalse
			return null;
		}

		$response = wp_remote_get( $url, self::github_request_args( $timeout, array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || ! isset( $release['tag_name'] ) || ! self::is_release_tag( $release['tag_name'] ) ) {
			return null;
		}

		$prefix = substr( $url, 0, strrpos( $url, '/' ) + 1 );
		$assets = array();
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && 0 === strpos( (string) $asset['browser_download_url'], $prefix ) ) {
					$assets[] = array(
						'name'                 => (string) $asset['name'],
						'browser_download_url' => (string) $asset['browser_download_url'],
					);
				}
			}
		}

		return array(
			'tag_name'    => $release['tag_name'],
			'assets'      => $assets,
			'wphc_source' => 'hub',
		);
	}

	/**
	 * Ripiego di WPHC_Self_Updater::fetch_latest_release(): ricava il tag dal redirect di
	 * https://github.com/<owner>/<repo>/releases/latest (302 verso
	 * /releases/tag/<tag>), senza usare l'API. Il tag deve avere la forma di una
	 * versione: niente altro finisce negli URL degli asset.
	 *
	 * @param int $timeout Timeout in secondi.
	 * @return array<string,mixed>|null La release nella forma dell'API, o null se non determinabile.
	 */
	private static function fetch_latest_release_without_api( $timeout ) {
		$owner = rawurlencode( WP_HEALTH_CHECK_GH_OWNER );
		$repo  = rawurlencode( WP_HEALTH_CHECK_GH_REPO );

		$args                = self::github_request_args( $timeout );
		$args['redirection'] = 0; // Serve l'header Location della prima risposta, non la pagina di arrivo.
		$response            = wp_remote_get( sprintf( 'https://github.com/%s/%s/releases/latest', $owner, $repo ), $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $code < 300 || $code >= 400 || '' === $location ) {
			return null;
		}

		$prefix = sprintf( 'https://github.com/%s/%s/releases/tag/', $owner, $repo );
		if ( 0 !== strpos( $location, $prefix ) ) {
			return null;
		}
		$tag = rawurldecode( substr( $location, strlen( $prefix ) ) );
		if ( ! preg_match( '/^v?\d+\.\d+\.\d+(?:[-+.][0-9A-Za-z.-]+)?$/', $tag ) ) {
			return null;
		}

		$base   = sprintf( 'https://github.com/%s/%s/releases/download/%s/', $owner, $repo, rawurlencode( $tag ) );
		$assets = array();
		foreach ( array( 'wp-health-check.php', 'wp-health-check.php.sha256', 'wp-health-check.php.sig' ) as $name ) {
			$assets[] = array(
				'name'                 => $name,
				'browser_download_url' => $base . $name,
			);
		}

		return array(
			'tag_name' => $tag,
			'assets'   => $assets,
		);
	}

	/**
	 * Scarica gli asset di una release: il file del plugin, il suo hash sha256
	 * affiancato e, dalla 1.34.0, la firma del centro (.sig). La sorgente e'
	 * quella indicata negli URL della release (hub o GitHub).
	 *
	 * Solo il download del file del plugin e' un errore qui; un .sha256 o un .sig
	 * mancanti o non scaricabili restano null e li rifiuta WPHC_Self_Updater::install_release()
	 * come integrity_check_failed, come prima del refactor.
	 *
	 * @param array<string,mixed> $release Release nella forma dell'API (tag_name, assets[]).
	 * @return array<string,mixed> array( 'contents' => string, 'sha256' => string|null, 'sig' => array|null ),
	 *                              oppure un esito 'error' (wphc_update_asset_missing / wphc_update_download_failed).
	 */
	private static function download_release_assets( $release ) {
		$asset_url     = null;
		$sha_asset_url = null;
		$sig_asset_url = null;
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( 'wp-health-check.php' === $asset['name'] ) {
					$asset_url = $asset['browser_download_url'];
				} elseif ( 'wp-health-check.php.sha256' === $asset['name'] ) {
					$sha_asset_url = $asset['browser_download_url'];
				} elseif ( 'wp-health-check.php.sig' === $asset['name'] ) {
					$sig_asset_url = $asset['browser_download_url'];
				}
			}
		}

		if ( null === $asset_url ) {
			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_asset_missing',
				'message' => __( 'Asset wp-health-check.php non trovato nella release.', 'wp-health-check' ),
				'http'    => 502,
			);
		}

		$download = wp_remote_get( $asset_url, self::github_request_args( 30 ) );
		if ( is_wp_error( $download ) || 200 !== (int) wp_remote_retrieve_response_code( $download ) ) {
			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_download_failed',
				'message' => __( 'Impossibile scaricare il nuovo file del plugin.', 'wp-health-check' ),
				'http'    => 502,
			);
		}

		// Hash atteso (asset .sha256). Dalla 1.34.0 (A-3) non si legge piu' dal
		// corpo della release ("sha256: <hash>"): era un secondo canale ugualmente
		// controllato da chi controlla la release.
		$expected_sha256 = null;
		if ( null !== $sha_asset_url ) {
			$sha_response = wp_remote_get( $sha_asset_url, self::github_request_args( 30 ) );
			if ( ! is_wp_error( $sha_response ) && 200 === (int) wp_remote_retrieve_response_code( $sha_response ) ) {
				// Il file .sha256 puo' seguire il formato "sha256sum"
				// ("<hash>  <nomefile>") oppure contenere il solo hash.
				$sha_body        = trim( wp_remote_retrieve_body( $sha_response ) );
				$expected_sha256 = strtolower( (string) strtok( $sha_body, " \t\n" ) );
			}
		}

		$sig_data = null;
		if ( null !== $sig_asset_url ) {
			$sig_response = wp_remote_get( $sig_asset_url, self::github_request_args( 30 ) );
			if ( ! is_wp_error( $sig_response ) && 200 === (int) wp_remote_retrieve_response_code( $sig_response ) ) {
				$decoded  = json_decode( wp_remote_retrieve_body( $sig_response ), true );
				$sig_data = is_array( $decoded ) ? $decoded : null;
			}
		}

		return array(
			'contents' => wp_remote_retrieve_body( $download ),
			'sha256'   => $expected_sha256,
			'sig'      => $sig_data,
		);
	}

	/**
	 * Preflight di scrittura: scrive e rimuove un file temporaneo a nome casuale
	 * in WPMU_PLUGIN_DIR (nome casuale per evitare collisioni fra aggiornamenti
	 * concorrenti lanciati su piu' siti in parallelo). Il pull lo esegue PRIMA di
	 * scaricare qualunque cosa, la pipeline di installazione lo ripete.
	 *
	 * @return bool True se la cartella e' scrivibile.
	 */
	private static function probe_mu_dir_writable() {
		$test_file = trailingslashit( WPMU_PLUGIN_DIR ) . '.wphc-writetest-' . wp_generate_password( 12, false, false );

		// Funzioni filesystem PHP native (non WP_Filesystem) per scelta di
		// progetto: vedi WPHC_Self_Updater::install_release(). L'esito e' controllato esplicitamente.
		$written = false !== @file_put_contents( $test_file, 'wphc' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $test_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		return $written;
	}

	/**
	 * Pipeline di installazione di una release, COMUNE al pull (asset scaricati
	 * dal sito) e al push (release spedita dall'hub nel body di POST /update).
	 * Segue rigorosamente l'ordine: confronto versione -> preflight di
	 * scrivibilita' -> verifica integrita' -> verifica firma -> backup ->
	 * scrittura atomica -> sanity check -> pulizia. Ogni passo che fallisce
	 * interrompe il flusso PRIMA di toccare il file di produzione, cosi' un
	 * errore a meta' strada non puo' mai lasciare il sito con un mu-plugin
	 * corrotto o mancante.
	 *
	 * La sorgente dei dati non conta: SHA-256, header Version e firma k2 sono
	 * verificati qui su qualunque provenienza (hub, GitHub, push).
	 *
	 * @param string                   $tag             Tag grezzo della release (con la "v"), cosi' com'e' nel messaggio firmato.
	 * @param string                   $new_contents    Contenuto del nuovo file del plugin.
	 * @param string|null              $expected_sha256 SHA-256 atteso (minuscolo).
	 * @param array<string,mixed>|null $sig_data        JSON della firma ({kid, signature}).
	 * @param bool                     $allow_same      Se true accetta anche la stessa versione (reinstallazione di
	 *                                                  riparazione, solo push con force). Il downgrade e' sempre vietato.
	 * @return array<string, mixed> Esito normalizzato ('updated', 'up_to_date',
	 *         'not_writable', 'integrity_check_failed', 'error': vedi WPHC_Self_Updater::perform_self_update()).
	 */
	private static function install_release( $tag, $new_contents, $expected_sha256, $sig_data, $allow_same = false ) {
		$current_version = WP_HEALTH_CHECK_VERSION;

		// 1. Normalizza il tag (rimuove un eventuale prefisso "v") e confronta:
		// versione piu' recente richiesta, uguale solo con $allow_same, mai inferiore.
		$latest_tag = ltrim( (string) $tag, 'v' );
		$comparison = version_compare( $latest_tag, $current_version );
		if ( $comparison < 0 || ( 0 === $comparison && ! $allow_same ) ) {
			return array(
				'result'  => 'up_to_date',
				'current' => $current_version,
				'latest'  => $latest_tag,
			);
		}

		// 2. Preflight di scrittura: verifica PRIMA di toccare qualunque file
		// che la directory di destinazione sia scrivibile.
		if ( ! self::probe_mu_dir_writable() ) {
			return array( 'result' => 'not_writable' );
		}
		$mu_dir = trailingslashit( WPMU_PLUGIN_DIR );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// Funzioni filesystem PHP native (non WP_Filesystem) per scelta di
		// progetto: questo file deve restare autoconsistente e funzionare
		// anche quando WP_Filesystem non e' inizializzato o richiederebbe
		// credenziali FTP; l'operatore "@" silenzia solo il warning PHP,
		// l'esito e' comunque sempre controllato esplicitamente sotto.

		// 3. Verifica di integrita', PRIMA di toccare qualunque file di
		// produzione: hash atteso, contenuto non vuoto, prefisso "<?php" e
		// coerenza fra la versione dichiarata nel file e il tag della release.
		$integrity_ok = true;
		if ( '' === $new_contents ) {
			$integrity_ok = false;
		} elseif ( 0 !== strpos( $new_contents, '<?php' ) ) {
			$integrity_ok = false;
		} elseif ( ! preg_match( '/Version:\s+' . preg_quote( $latest_tag, '/' ) . '(?:\s|$)/', $new_contents ) ) {
			// L'header del plugin allinea i campi con spazi multipli (es. "Version:     1.0.0"),
			// quindi il confronto non puo' cercare un singolo spazio letterale dopo "Version:".
			$integrity_ok = false;
		} elseif ( empty( $expected_sha256 ) || ! hash_equals( $expected_sha256, hash( 'sha256', $new_contents ) ) ) {
			$integrity_ok = false;
		}

		if ( ! $integrity_ok ) {

			return array( 'result' => 'integrity_check_failed' );
		}

		// 3-bis. Autenticita' (dalla 1.34.0, A-3 della review 2026-10-03; obbligatoria
		// dalla 1.35.0, A-1 della review 2026-10-11): lo SHA-256 sta nella stessa
		// release del file, quindi chi controlla il repo GitHub (o l'hub che la
		// distribuisce) controlla anche l'hash. L'asset .sig contiene la firma
		// Ed25519 del centro (hub/scripts/sign_release.py) su
		// "release\n<tag>\n<sha256>", verificata con la chiave pubblica incorporata
		// delle release (k2): senza la chiave privata non si produce una release
		// valida. Una release senza .sig o con una firma non valida ferma sempre
		// l'aggiornamento: l'autenticita' non e' piu' opzionale per chi pubblica la release.
		if ( ! is_array( $sig_data ) || empty( $sig_data['signature'] ) ) {
			if ( WP_DEBUG ) {
				error_log( sprintf( 'wp-health-check: la release %s non ha la firma (.sig), aggiornamento annullato', (string) $tag ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return array( 'result' => 'integrity_check_failed' );
		}

		$sig_kid        = isset( $sig_data['kid'] ) ? sanitize_key( (string) $sig_data['kid'] ) : 'k2';
		$signed_message = "release\n" . (string) $tag . "\n" . $expected_sha256;
		if ( ! WPHC_Crypto::verify_central_signature( $signed_message, (string) $sig_data['signature'], $sig_kid, 'release' ) ) {
			if ( WP_DEBUG ) {
				error_log( sprintf( 'wp-health-check: firma della release %s non valida, aggiornamento annullato', (string) $tag ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return array( 'result' => 'integrity_check_failed' );
		}
		$signature_status = 'verified';

		// 4. Backup del file corrente, prima di qualsiasi scrittura.
		// Il file del plugin sta nella costante WP_HEALTH_CHECK_FILE (src/constants.php): nel file distribuito e' il
		// mu-plugin stesso, cioe' quello che nel file 1.38.1 era __FILE__ (decisione D-1 del progetto).
		$plugin_file = WP_HEALTH_CHECK_FILE;
		$backup_file = $plugin_file . '.bak';
		if ( false === @copy( $plugin_file, $backup_file ) ) {

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_backup_failed',
				'message' => __( 'Impossibile creare il backup prima di aggiornare.', 'wp-health-check' ),
				'http'    => 500,
			);
		}

		// 5. Scrittura atomica: il temporaneo NON ha estensione .php di
		// proposito. Se il rename() sottostante fallisse e il temporaneo
		// restasse orfano nella cartella, un file con estensione .php in
		// mu-plugins verrebbe caricato automaticamente al giro successivo,
		// ridichiarando tutte le funzioni di questo plugin e rompendo l'intero
		// sito con un fatal error: l'estensione neutra rende questo scenario
		// innocuo. rename() sullo stesso filesystem e' atomico: non lascia
		// mai __FILE__ in uno stato "a meta' scritto".
		$tmp_file = $mu_dir . '.wphc-new-' . wp_generate_password( 12, false, false ) . '.tmp';
		if ( false === @file_put_contents( $tmp_file, $new_contents ) || false === @rename( $tmp_file, $plugin_file ) ) {
			@unlink( $tmp_file );

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_write_failed',
				'message' => __( 'Scrittura del nuovo file fallita.', 'wp-health-check' ),
				'http'    => 500,
			);
		}

		// 6. Sanity check post-scrittura: se qualcosa non torna, ripristina
		// immediatamente dal backup del punto 4.
		$written_contents = @file_get_contents( $plugin_file );
		if ( false === $written_contents || '' === $written_contents || 0 !== strpos( $written_contents, '<?php' ) ) {
			@copy( $backup_file, $plugin_file );

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_sanity_failed',
				'message' => __( 'Verifica post-scrittura fallita: ripristinato il backup precedente.', 'wp-health-check' ),
				'http'    => 500,
			);
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// 7. Invalida l'opcode cache per la nuova versione: senza questo
		// passo, sui SAPI con opcache persistente (es. php-fpm) la vecchia
		// versione compilata resterebbe in uso fino al riavvio del pool.
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $plugin_file, true );
		}

		// 8. Rimuove il backup e invalida la cache della "ultima versione" (ora e'
		// quella installata). Il backup del punto 4 serviva solo al ripristino
		// immediato dei punti 5 e 6 (B-21: restava per sempre in mu-plugins/, una
		// copia del codice precedente).
		@unlink( $backup_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		delete_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE );

		if ( WP_DEBUG ) {
			error_log( sprintf( 'wp-health-check: aggiornato da %1$s a %2$s', $current_version, $latest_tag ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return array(
			'result'    => 'updated',
			'from'      => $current_version,
			'to'        => $latest_tag,
			'signature' => $signature_status,
		);
	}

	/**
	 * Self-update in modalita' pull: il sito individua la release piu' recente
	 * (hub, poi GitHub), ne scarica gli asset e li installa con
	 * WPHC_Self_Updater::install_release(). Ordine: release -> confronto versione -> preflight
	 * di scrittura -> download -> installazione (integrita', firma, backup,
	 * scrittura, sanity).
	 *
	 * Logica CONDIVISA fra la rotta REST POST /update e il pulsante nella tab
	 * Site Health: restituisce un array normalizzato, che ciascun chiamante
	 * mappa nel proprio formato (WP_REST_Response/WP_Error, oppure messaggio
	 * di redirect admin). Non registra l'accesso ne' invia risposte HTTP:
	 * quelle sono responsabilita' del chiamante.
	 *
	 * @return array<string, mixed> Esito normalizzato. La chiave 'result' e' uno di:
	 *         'updated' (con 'from'/'to'), 'up_to_date' (con 'current'/'latest'),
	 *         'not_writable', 'integrity_check_failed', oppure 'error' (con
	 *         'code', 'message', 'http').
	 */
	public static function perform_self_update() {
		// 1. Interroga la release piu' recente (hub e, in ripiego, API di GitHub e
		// redirect senza API: vedi WPHC_Self_Updater::fetch_latest_release()).
		$release = self::fetch_latest_release( 15 );
		if ( ! isset( $release['tag_name'] ) ) {
			$detail = isset( $release['detail'] ) ? ' (' . $release['detail'] . ')' : '';
			if ( 'wphc_update_network_error' === ( isset( $release['code'] ) ? $release['code'] : '' ) ) {
				return array(
					'result'  => 'error',
					'code'    => 'wphc_update_network_error',
					'message' => __( 'Impossibile contattare GitHub.', 'wp-health-check' ) . $detail,
					'http'    => 502,
				);
			}
			if ( 'wphc_update_bad_release' === ( isset( $release['code'] ) ? $release['code'] : '' ) ) {
				return array(
					'result'  => 'error',
					'code'    => 'wphc_update_bad_release',
					'message' => __( 'Risposta di GitHub non valida.', 'wp-health-check' ),
					'http'    => 502,
				);
			}

			return array(
				'result'  => 'error',
				'code'    => 'wphc_update_github_error',
				'message' => __( 'GitHub ha risposto con un errore nel recuperare la release.', 'wp-health-check' ) . $detail,
				'http'    => 502,
			);
		}

		// 2. Gia' aggiornato: nessun download.
		$latest_tag = ltrim( (string) $release['tag_name'], 'v' );
		if ( ! version_compare( $latest_tag, WP_HEALTH_CHECK_VERSION, '>' ) ) {
			return array(
				'result'  => 'up_to_date',
				'current' => WP_HEALTH_CHECK_VERSION,
				'latest'  => $latest_tag,
			);
		}

		// 3. Preflight di scrittura PRIMA di scaricare qualunque cosa.
		if ( ! self::probe_mu_dir_writable() ) {
			return array( 'result' => 'not_writable' );
		}

		// 4. Scarica gli asset. Se la release arriva dall'hub e il download fallisce
		// (asset non serviti), un solo nuovo tentativo con la release di GitHub.
		$assets = self::download_release_assets( $release );
		if ( isset( $assets['result'] ) && isset( $release['wphc_source'] ) && 'hub' === $release['wphc_source'] ) {
			$github_release = self::fetch_latest_release( 15, true );
			if ( isset( $github_release['tag_name'] ) ) {
				$release = $github_release;
				$assets  = self::download_release_assets( $release );
			}
		}
		if ( isset( $assets['result'] ) ) {
			return $assets;
		}

		// 5. Installa (integrita', firma, backup, scrittura atomica, sanity).
		return self::install_release( (string) $release['tag_name'], $assets['contents'], $assets['sha256'], $assets['sig'] );
	}

	/**
	 * Self-update in modalita' push (dalla 1.38.0): l'hub spedisce la release
	 * firmata nel body di POST /update ({"package": {...}}), il sito non scarica
	 * nulla. Serve ai siti che non raggiungono GitHub o l'hub, e alla
	 * reinstallazione di riparazione ("force").
	 *
	 * Il pacchetto e' una sorgente come un'altra: la pipeline di
	 * WPHC_Self_Updater::install_release() verifica SHA-256, header Version e firma k2. L'hub non
	 * possiede il seed k2 e non puo' fabbricare una release valida.
	 *
	 * @param mixed $package Valore di "package" nel body: {tag, sha256, sig, contents_gz_b64, force}.
	 * @return array<string, mixed> Esito normalizzato, come WPHC_Self_Updater::perform_self_update().
	 */
	private static function perform_pushed_update( $package ) {
		$invalid_message = __( 'Pacchetto di aggiornamento non valido.', 'wp-health-check' );
		$invalid         = array(
			'result'  => 'error',
			'code'    => 'wphc_update_push_invalid',
			'message' => $invalid_message,
			'http'    => 400,
		);

		// Forma del pacchetto e limiti di dimensione (il file pesa ~360 KB, ~110 KB compresso).
		if (
			! is_array( $package )
			|| ! isset( $package['tag'], $package['sha256'], $package['sig'], $package['contents_gz_b64'] )
			|| ! self::is_release_tag( $package['tag'] )
			|| ! is_string( $package['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/i', $package['sha256'] )
			|| ! is_array( $package['sig'] )
			|| ! is_string( $package['contents_gz_b64'] ) || strlen( $package['contents_gz_b64'] ) > 2 * MB_IN_BYTES
			|| ! function_exists( 'gzdecode' )
		) {
			return $invalid;
		}

		$compressed = base64_decode( $package['contents_gz_b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$contents   = false === $compressed ? false : @gzdecode( $compressed, 4 * MB_IN_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $contents || '' === $contents ) {
			return $invalid;
		}

		return self::install_release( $package['tag'], $contents, strtolower( $package['sha256'] ), $package['sig'], ! empty( $package['force'] ) );
	}

	/**
	 * Callback della rotta REST POST /update: registra l'accesso, esegue il
	 * self-update e mappa l'esito normalizzato nel formato REST storico
	 * (invariato rispetto alle versioni precedenti). Senza body e' il pull
	 * (il sito scarica la release); con {"package": {...}} e' il push dell'hub
	 * (dalla 1.38.0) e la risposta riporta "mode": "push".
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response|WP_Error Esito dell'update.
	 */
	public static function route_update( WP_REST_Request $request ) {
		// L'autenticazione e' gia' garantita dal permission_callback; qui si
		// registra soltanto l'accesso, come per ogni chiamata dati autenticata.
		WPHC_Auth::record_access();

		$body = $request->get_json_params();
		if ( is_array( $body ) && array_key_exists( 'package', $body ) ) {
			$outcome = self::perform_pushed_update( $body['package'] );
			$mode    = array( 'mode' => 'push' );
		} else {
			$outcome = self::perform_self_update();
			$mode    = array();
		}

		switch ( $outcome['result'] ) {
			case 'error':
				return new WP_Error( $outcome['code'], $outcome['message'], array( 'status' => $outcome['http'] ) );

			case 'updated':
				return rest_ensure_response(
					array_merge(
						array(
							'updated'   => true,
							'from'      => $outcome['from'],
							'to'        => $outcome['to'],
							'signature' => $outcome['signature'],
						),
						$mode
					)
				);

			case 'up_to_date':
				return rest_ensure_response(
					array_merge(
						array(
							'updated' => false,
							'reason'  => 'up_to_date',
							'current' => $outcome['current'],
							'latest'  => $outcome['latest'],
						),
						$mode
					)
				);

			default: // esiti non riusciti ma non erronei: not_writable / integrity_check_failed.
				return rest_ensure_response(
					array_merge(
						array(
							'updated' => false,
							'reason'  => $outcome['result'],
						),
						$mode
					)
				);
		}
	}

	/**
	 * Restituisce l'ultima versione (tag senza "v") pubblicata su GitHub,
	 * cachata 1h in un transient per non interrogare l'API ad ogni render
	 * della tab Site Health. Solo lettura: non tocca il filesystem. Usata dal
	 * pannello admin per mostrare se esiste un aggiornamento.
	 *
	 * @param bool $force Se true, ignora la cache e reinterroga GitHub.
	 * @return string|null Ultima versione disponibile, o null se non determinabile.
	 */
	public static function get_latest_version( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE );
			if ( false !== $cached ) {
				return '' !== $cached ? $cached : null;
			}
		}

		$release = self::fetch_latest_release( 10 );
		$latest  = isset( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], 'v' ) : '';

		// Cache breve anche in caso di fallimento (stringa vuota), per non
		// martellare GitHub ad ogni apertura della tab se l'API e' irraggiungibile.
		set_transient( WPHC_Options::TRANSIENT_LATEST_VERSION_CACHE, $latest, HOUR_IN_SECONDS );

		return '' !== $latest ? $latest : null;
	}
}
