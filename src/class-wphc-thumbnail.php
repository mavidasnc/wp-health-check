<?php
/**
 * Anteprima (screenshot) del sito: generazione da thum.io, caching nel Media Library, rotte GET e POST /thumbnail.
 *
 * @package WP_Health_Check
 */

/**
 * Anteprima del sito mostrata dalla dashboard: scarica lo screenshot da thum.io, lo carica nel Media Library
 * (sideload) e ne conserva l'ID e l'esito nelle option wp_health_check_thumb*. Contiene la generazione
 * (chiamata remota lenta, mai invocata direttamente), la logica "genera se manca" con la guardia
 * anti-retry-loop e i due callback delle rotte GET /thumbnail (stato) e POST /thumbnail (rigenerazione).
 *
 * Raccoglie la sezione J del file 1.38.1. Nessun hook proprio: maybe_generate() e' chiamato da /health e
 * dal pulsante della tab Site Health. Gli errori della thumbnail non sono mai un errore della rotta.
 */
final class WPHC_Thumbnail {

	// -----------------------------------------------------------------------
	// HELPER: thumbnail del sito (thum.io)
	// -----------------------------------------------------------------------

	/**
	 * Scarica dal servizio configurato (WP_HEALTH_CHECK_THUMB_SERVICE, thum.io di
	 * default) uno screenshot PNG (larghezza 400, altezza proporzionale) della
	 * home pubblica del sito e lo carica nel Media Library. Operazione remota e
	 * potenzialmente lenta: va chiamata solo tramite WPHC_Thumbnail::maybe_generate(),
	 * mai direttamente, per rispettare la guardia anti-retry-loop.
	 *
	 * Dalla 1.30.0 ritorna un array strutturato invece di un int|null silenzioso:
	 * ogni punto di fallimento (prima tutti indistinguibili, un semplice "non
	 * generata") produce un codice diagnostico esplicito, che
	 * WPHC_Thumbnail::maybe_generate() persiste in wp_health_check_thumb_error e
	 * che GET /thumbnail e la tab Site Health espongono.
	 *
	 * @return array{attachment_id: int|null, error: array{code: string, message: string}|null} Esito della generazione.
	 */
	private static function generate() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Preflight sulla scrivibilita' di uploads/: e' la causa di fallimento
		// piu' frequente e, prima della 1.30.0, non era distinguibile da un
		// fallimento di rete (entrambi tornavano semplicemente null).
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return array(
				'attachment_id' => null,
				'error'         => array(
					'code'    => 'wphc_thumb_uploads_not_writable',
					'message' => (string) $upload_dir['error'],
				),
			);
		}

		// noanimate+png: senza queste due opzioni thum.io risponde in streaming
		// con un GIF animato (spinner + render progressivo), pensato per essere
		// mostrato dal vivo, non per essere salvato come file.
		// get_home_url() (non get_site_url()): la home PUBBLICA del sito, non
		// l'indirizzo WordPress (che puo' differire, es. installazioni in una
		// sottocartella tecnica).
		$service_url = WP_HEALTH_CHECK_THUMB_SERVICE . get_home_url();

		$tmp_file = download_url( $service_url, 20 );
		if ( is_wp_error( $tmp_file ) ) {
			return array(
				'attachment_id' => null,
				'error'         => array(
					'code'    => 'wphc_thumb_download_failed',
					'message' => $tmp_file->get_error_message(),
				),
			);
		}

		// thum.io non garantisce il Content-Type nell'header: si rileva il tipo
		// reale dal file scaricato invece di assumere .png.
		$image_info = getimagesize( $tmp_file );
		if ( false === $image_info ) {
			wp_delete_file( $tmp_file );
			return array(
				'attachment_id' => null,
				'error'         => array(
					'code'    => 'wphc_thumb_not_an_image',
					'message' => __( 'La risposta del servizio di screenshot non e\' un\'immagine valida.', 'wp-health-check' ),
				),
			);
		}
		if ( IMAGETYPE_GIF === $image_info[2] ) {
			// Un GIF qui significa che noanimate/png non hanno avuto effetto
			// (es. sito non ancora renderizzato): si scarta, il cooldown
			// gestira' il retry.
			wp_delete_file( $tmp_file );
			return array(
				'attachment_id' => null,
				'error'         => array(
					'code'    => 'wphc_thumb_animated_gif',
					'message' => __( 'Il servizio ha risposto con un GIF animato invece del PNG atteso.', 'wp-health-check' ),
				),
			);
		}

		$extension = image_type_to_extension( $image_info[2] );
		if ( false === $extension ) {
			$extension = '.png';
		}

		$file_array = array(
			'name'     => 'wp-health-check-site-thumb' . $extension,
			'tmp_name' => $tmp_file,
		);

		// post_id = 0: l'attachment non appartiene a nessun post, e' solo un
		// riferimento tenuto in wp_health_check_thumb/wp_health_check_thumb_id.
		$attachment_id = media_handle_sideload( $file_array, 0, 'WP Health Check site thumbnail' );

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload() ripulisce $tmp_file solo in caso di successo.
			wp_delete_file( $tmp_file );
			return array(
				'attachment_id' => null,
				'error'         => array(
					'code'    => 'wphc_thumb_sideload_failed',
					'message' => $attachment_id->get_error_message(),
				),
			);
		}

		return array(
			'attachment_id' => $attachment_id,
			'error'         => null,
		);
	}

	/**
	 * Ritorna l'URL della thumbnail del sito, generandola alla prima chiamata (o
	 * on-demand con $force=true, vedi POST /thumbnail e il pulsante "Rigenera
	 * anteprima" della tab Site Health). Chiamate successive con l'opzione gia'
	 * valorizzata e $force=false sono O(1) (una sola get_option): /health la
	 * chiama sempre con $force=false, quindi non paga mai il costo di una
	 * rigenerazione nel suo percorso caldo. Un transient di cooldown (1 giorno)
	 * impedisce che un fallimento del servizio trasformi ogni /health in un
	 * nuovo tentativo di chiamata remota; $force=true lo ignora esplicitamente.
	 *
	 * Ogni fallimento e' persistito in wp_health_check_thumb_error (dalla
	 * 1.30.0): mai piu' un null silenzioso senza diagnosi, vedi
	 * WPHC_Thumbnail::generate().
	 *
	 * @param bool $force Se true, ignora cooldown e attachment esistente e rigenera comunque.
	 * @return string|null URL assoluto della thumbnail, o null se non disponibile.
	 */
	public static function maybe_generate( $force = false ) {
		if ( ! $force ) {
			$existing = get_option( WPHC_Options::OPT_THUMB );
			if ( $existing ) {
				return $existing;
			}

			if ( false !== get_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK ) ) {
				return null;
			}
		}

		// Impostato PRIMA del tentativo (anche quando $force=true): se il
		// servizio fallisce, il lock resta per il resto del TTL indipendentemente
		// dall'esito, cosi' anche una rigenerazione forzata non puo' trasformarsi
		// in un ciclo di retry ravvicinati contro il servizio remoto.
		set_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK, time(), DAY_IN_SECONDS );

		if ( $force ) {
			// Rigenerazione esplicita: elimina l'attachment precedente (se
			// esiste) prima di generarne uno nuovo, stessa pulizia gia' fatta dal
			// pulsante admin "Elimina e rigenera", vedi WPHC_Admin::handle_delete_thumbnail().
			$previous_id = (int) get_option( WPHC_Options::OPT_THUMB_ID );
			if ( $previous_id ) {
				wp_delete_attachment( $previous_id, true );
			}
			delete_option( WPHC_Options::OPT_THUMB );
			delete_option( WPHC_Options::OPT_THUMB_ID );
		}

		$result = self::generate();

		if ( ! empty( $result['error'] ) ) {
			update_option(
				WPHC_Options::OPT_THUMB_ERROR,
				array(
					'at'       => gmdate( 'c' ),
					'code'     => $result['error']['code'],
					'message'  => $result['error']['message'],
					'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
				),
				false
			);

			return null;
		}

		$attachment_id = $result['attachment_id'];
		$url           = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			update_option(
				WPHC_Options::OPT_THUMB_ERROR,
				array(
					'at'       => gmdate( 'c' ),
					'code'     => 'wphc_thumb_sideload_failed',
					'message'  => __( 'Attachment creato ma senza un URL recuperabile.', 'wp-health-check' ),
					'provider' => WP_HEALTH_CHECK_THUMB_SERVICE,
				),
				false
			);

			return null;
		}

		// L'ID viene conservato accanto all'URL per permettere l'eliminazione
		// affidabile dell'attachment dal pulsante "Elimina e rigenera" in admin.
		update_option( WPHC_Options::OPT_THUMB, $url, false );
		update_option( WPHC_Options::OPT_THUMB_ID, $attachment_id, false );
		update_option( WPHC_Options::OPT_THUMB_AT, time(), false );
		delete_option( WPHC_Options::OPT_THUMB_ERROR );
		delete_transient( WPHC_Options::TRANSIENT_THUMB_RETRY_LOCK );

		return $url;
	}

	// -----------------------------------------------------------------------
	// CALLBACK: GET|POST /thumbnail
	// -----------------------------------------------------------------------

	/**
	 * GET /thumbnail — stato corrente della thumbnail del sito: URL (se
	 * disponibile), timestamp dell'ultima generazione riuscita ed eventuale
	 * ultimo errore diagnostico completo. Economica quanto /health: legge solo
	 * opzioni gia' mantenute, nessuna chiamata remota (vedi WPHC_Thumbnail::maybe_generate()).
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente (nessun payload richiesto).
	 * @return WP_REST_Response Stato della thumbnail.
	 */
	public static function route_status( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Auth::record_access();

		$thumbnail = get_option( WPHC_Options::OPT_THUMB );
		$error     = get_option( WPHC_Options::OPT_THUMB_ERROR );

		return rest_ensure_response(
			array(
				'thumbnail'    => $thumbnail ? $thumbnail : null,
				'generated_at' => ( $thumbnail && get_option( WPHC_Options::OPT_THUMB_AT ) ) ? gmdate( 'c', (int) get_option( WPHC_Options::OPT_THUMB_AT ) ) : null,
				'error'        => is_array( $error ) ? $error : null,
			)
		);
	}

	/**
	 * POST /thumbnail — rigenera la thumbnail on demand, ignorando il cooldown
	 * di un giorno (ma comunque impostandolo per il tentativo appena fatto, vedi
	 * WPHC_Thumbnail::maybe_generate( true )). Risponde con lo stesso esito o
	 * errore strutturato, cosi' un fallimento e' diagnosticabile immediatamente
	 * invece di scoprirlo solo alla prossima /health.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente (nessun payload richiesto).
	 * @return WP_REST_Response Esito della rigenerazione.
	 */
	public static function route_regenerate( WP_REST_Request $request ) {
		unset( $request );

		WPHC_Auth::record_access();

		$thumbnail = self::maybe_generate( true );
		$error     = get_option( WPHC_Options::OPT_THUMB_ERROR );

		return rest_ensure_response(
			array(
				'thumbnail' => $thumbnail ? $thumbnail : null,
				'error'     => ( ! $thumbnail && is_array( $error ) ) ? $error : null,
			)
		);
	}
}
