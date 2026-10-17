<?php
/**
 * Helper condivisi dai test di caratterizzazione delle rotte di lettura e delle aree
 * collegate (CORS, thumbnail, autologin, debug, ultimo login).
 *
 * Contiene SOLO supporto (nessun test). Estende WPHC_Test_Case senza modificarla:
 *  - chiamate GET/POST con Bearer gia' pronte;
 *  - mock delle richieste HTTP in uscita (thum.io) via `pre_http_request`;
 *  - golden "normalizzati": i valori che dipendono dall'ambiente (versione PHP, memory
 *    limit, plugin e temi installati, ID di utenti e allegati) diventano segnaposto
 *    `<ENV:tipo>`, cosi' lo stesso golden vale su PHP 8.1 e 7.4;
 *  - cattura degli header CORS (vedi "Limite di osservabilita'" in WPHC_Read_Test_Case::capture_cors()).
 *
 * @package WP_Health_Check
 */

namespace WPHC_Cors_Spy {

	/**
	 * Registro degli header "inviati" dalla copia della funzione CORS.
	 */
	final class Log {

		/** @var string[] Argomenti di header() nell'ordine di chiamata. */
		public static $headers = array();

		/** @var array<int,string|null> Argomenti di header_remove() nell'ordine di chiamata. */
		public static $removed = array();

		/**
		 * Azzera il registro.
		 */
		public static function reset() {
			self::$headers = array();
			self::$removed = array();
		}
	}

	/**
	 * Sostituto di header() nel namespace WPHC_Cors_Spy: registra invece di inviare.
	 *
	 * @param string $header  Riga di header.
	 * @param bool   $replace Ignorato.
	 * @param int    $code    Ignorato.
	 */
	function header( $header, $replace = true, $code = 0 ) {
		unset( $replace, $code );
		Log::$headers[] = $header;
	}

	/**
	 * Sostituto di header_remove() nel namespace WPHC_Cors_Spy: registra il nome rimosso.
	 *
	 * @param string|null $name Nome dell'header.
	 */
	function header_remove( $name = null ) {
		Log::$removed[] = $name;
	}
}

namespace {

	/**
	 * Filtro "passante" con un nome di funzione, per i test di /debug (che elenca i callback
	 * registrati su un hook: una stringa, un array statico, un array con oggetto e una Closure).
	 *
	 * @param mixed $value Valore del filtro.
	 * @return mixed Lo stesso valore.
	 */
	function wphc_read_test_passthrough( $value ) {
		return $value;
	}

	/**
	 * Eccezione lanciata dal filtro `wp_redirect` per intercettare i redirect del plugin: dopo
	 * wp_safe_redirect() il plugin chiama exit, che in un test terminerebbe PHPUnit.
	 */
	class WPHC_Test_Redirect extends RuntimeException {

		/** @var string Destinazione del redirect. */
		public $location = '';

		/** @var int Codice HTTP del redirect. */
		public $http_status = 302;
	}

	/**
	 * Base dei test di lettura: stato enrolled, GET/POST con Bearer, HTTP mockato, golden normalizzati.
	 */
	abstract class WPHC_Read_Test_Case extends WPHC_Test_Case {

		/** Token del sito usato nei test (protocollo 1: basta il Bearer). */
		const TOKEN = 'tok-read';

		/** Sottocartella fissa degli upload nei test (evita il volatile "anno/mese"). */
		const UPLOAD_SUBDIR = '/wphc-test';

		/** Base URL fissa degli upload nei test (evita l'URL dipendente dall'ambiente). */
		const UPLOAD_BASEURL = 'https://uploads.invalid';

		/**
		 * Numero di volte che nocache_headers() e' stata invocata (spia di "e' girata la funzione CORS").
		 *
		 * @var int
		 */
		protected $nocache_calls = 0;

		/**
		 * Richieste HTTP viste dal router di mock_http() (URL e argomenti).
		 *
		 * @var array<int,array{url:string,args:array}>
		 */
		protected $http_requests = array();

		// -------------------------------------------------------------------
		// Ciclo di vita
		// -------------------------------------------------------------------

		/**
		 * Prepara il test: upload in una cartella fissa e spia su nocache_headers().
		 */
		public function set_up() {
			parent::set_up();
			// Chiamate di sfondo note, non mockate di default: /health cerca l'IP pubblico (ipify) e la
			// miniatura (thum.io), /detail/server il controllo di WordPress.org. Il blocco le fa fallire
			// e le rotte lo gestiscono; i test che le verificano le mockano con mock_http().
			$this->allow_unmocked_http_hosts( array( 'api.ipify.org', 'image.thum.io', 'wordpress.org' ) );

			$this->nocache_calls = 0;
			$this->http_requests = array();
			unset( $_GET['wphc_autologin'], $_SERVER['SERVER_ADDR'], $_SERVER['SERVER_SOFTWARE'] );

			// Spia: wphc_maybe_send_cors_headers() chiama sempre nocache_headers(), che applica il filtro
			// `nocache_headers`. Contare le esecuzioni del filtro dice quante volte e' girata la
			// funzione CORS (gli header veri non sono osservabili in CLI).
			add_filter(
				'nocache_headers',
				function ( $headers ) {
					++$this->nocache_calls;

					return $headers;
				}
			);

			// Cartella di upload deterministica: URL e percorso non dipendono ne' dall'ambiente ne' dalla data.
			add_filter( 'upload_dir', array( $this, 'fixed_upload_dir' ) );
			// wp_upload_dir() tiene il risultato in una variabile static: senza il refresh un valore calcolato
			// prima (da un altro test, senza il filtro) resterebbe in vigore e i filtri aggiunti dopo sarebbero ignorati.
			wp_upload_dir( null, true, true );
			$this->clean_thumbnail_files();
		}

		/**
		 * Ripulisce allegati e file creati dai test di thumbnail.
		 */
		public function tear_down() {
			// Va fatto PRIMA del rollback del database (parent::tear_down), che cancellerebbe la riga
			// dell'allegato lasciando il file sul disco.
			$thumb_id = (int) get_option( 'wp_health_check_thumb_id' );
			if ( $thumb_id ) {
				wp_delete_attachment( $thumb_id, true );
			}
			$this->clean_thumbnail_files();
			unset( $_GET['wphc_autologin'], $_SERVER['SERVER_ADDR'], $_SERVER['SERVER_SOFTWARE'] );

			parent::tear_down();

			// Filtri ripristinati: si riallinea la cache static di wp_upload_dir() al comportamento di default.
			wp_upload_dir( null, true, true );
		}

		/**
		 * Filtro upload_dir: cartella e URL fissi.
		 *
		 * @param array $dirs Valori calcolati da WordPress.
		 * @return array
		 */
		public function fixed_upload_dir( $dirs ) {
			$dirs['subdir']  = self::UPLOAD_SUBDIR;
			$dirs['path']    = $dirs['basedir'] . self::UPLOAD_SUBDIR;
			$dirs['baseurl'] = self::UPLOAD_BASEURL;
			$dirs['url']     = self::UPLOAD_BASEURL . self::UPLOAD_SUBDIR;

			return $dirs;
		}

		/**
		 * Cancella dal disco i file della thumbnail di test (anche residui di esecuzioni interrotte).
		 */
		protected function clean_thumbnail_files() {
			$upload = wp_upload_dir();
			$dir    = $upload['basedir'] . self::UPLOAD_SUBDIR;
			if ( is_dir( $dir ) ) {
				foreach ( (array) glob( $dir . '/wp-health-check-site-thumb*' ) as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- pulizia di file di test.
					}
				}
			}
		}

		/**
		 * Filtro "passante" come metodo statico (per i test di /debug sui tipi di callback elencati).
		 *
		 * @param mixed $value Valore del filtro.
		 * @return mixed
		 */
		public static function static_passthrough( $value ) {
			return $value;
		}

		/**
		 * Filtro "passante" come metodo d'istanza (per i test di /debug sui tipi di callback elencati).
		 *
		 * @param mixed $value Valore del filtro.
		 * @return mixed
		 */
		public function instance_passthrough( $value ) {
			return $value;
		}

		// -------------------------------------------------------------------
		// Richieste
		// -------------------------------------------------------------------

		/**
		 * Porta il sito in stato enrolled (protocollo 1) e restituisce il token.
		 *
		 * @return string
		 */
		protected function enrolled() {
			$this->seed_enrolled( self::TOKEN, 1 );

			return self::TOKEN;
		}

		/**
		 * GET a una rotta dati con il Bearer del sito.
		 *
		 * @param string $route Rotta senza namespace (es. "/health").
		 * @param array  $query Parametri di query string.
		 * @return WP_REST_Response
		 */
		protected function get_route( $route, array $query = array() ) {
			return $this->request( 'GET', $route, null, array( 'Authorization' => 'Bearer ' . self::TOKEN ), $query );
		}

		/**
		 * POST a una rotta dati con il Bearer del sito.
		 *
		 * @param string $route Rotta senza namespace (es. "/thumbnail").
		 * @return WP_REST_Response
		 */
		protected function post_route( $route ) {
			return $this->request( 'POST', $route, null, array( 'Authorization' => 'Bearer ' . self::TOKEN ) );
		}

		/**
		 * Valore di un transient letto direttamente dal DB (cio' che e' stato scritto, non la cache).
		 *
		 * @param string $name Nome senza prefisso `_transient_`.
		 * @return mixed|null null se assente.
		 */
		protected function transient_raw( $name ) {
			global $wpdb;
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return null === $value ? null : maybe_unserialize( $value );
		}

		/**
		 * Secondi residui di un transient (scadenza meno ora), letti dal DB; null se non ha scadenza.
		 *
		 * @param string $name Nome senza prefisso.
		 * @return int|null
		 */
		protected function transient_ttl( $name ) {
			global $wpdb;
			$timeout = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return null === $timeout ? null : (int) $timeout - time();
		}

		/**
		 * Righe della tabella di log update per tipo, dalla piu' vecchia (campi essenziali).
		 *
		 * @param string $type 'token' | 'login'.
		 * @return array<int,array<string,string>>
		 */
		protected function log_rows_by_type( $type ) {
			global $wpdb;
			$table = call_user_func( self::cb( 'wphc_update_log_table' ) );

			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT correlation_id, type, target, name, phase, message, ip FROM {$table} WHERE type = %s ORDER BY id", $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// -------------------------------------------------------------------
		// HTTP mockato
		// -------------------------------------------------------------------

		/**
		 * Sostituisce il blocco totale della rete con un router: ogni richiesta e' registrata in
		 * $this->http_requests (e in $this->blocked_http_calls, come fa la classe base) e risolta da
		 * $handler(url, args). Se $handler restituisce null la richiesta fallisce con un WP_Error.
		 *
		 * @param callable $handler function( string $url, array $args ): array|WP_Error|null.
		 */
		protected function mock_http( callable $handler ) {
			// Il filtro della classe base (priorita' 10) ignorerebbe l'esito del mio: va rimosso.
			remove_filter( 'pre_http_request', array( $this, 'block_http_request' ), 10 );
			add_filter(
				'pre_http_request',
				function ( $preempt, $args, $url ) use ( $handler ) {
					unset( $preempt );
					$this->blocked_http_calls[] = $url;
					$this->http_requests[]      = array(
						'url'  => $url,
						'args' => $args,
					);
					$result                     = $handler( $url, $args );

					return null === $result ? new WP_Error( 'wphc_test_http_blocked', 'HTTP non previsto dal test: ' . $url ) : $result;
				},
				10,
				3
			);
		}

		/**
		 * Risposta HTTP nel formato di wp_remote_get().
		 *
		 * @param int         $code     Codice HTTP.
		 * @param string      $body     Corpo.
		 * @param string|null $filename File scritto (richieste con stream => true).
		 * @return array
		 */
		protected static function http_response( $code, $body = '', $filename = null ) {
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => $code,
					'message' => get_status_header_desc( $code ),
				),
				'cookies'  => array(),
				'filename' => $filename,
			);
		}

		/**
		 * Risposta a una richiesta in streaming (download_url): scrive $body nel file richiesto.
		 *
		 * @param array  $args Argomenti della richiesta (stream, filename).
		 * @param int    $code Codice HTTP.
		 * @param string $body Contenuto del file.
		 * @return array
		 */
		protected static function http_stream_response( array $args, $code, $body ) {
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- simula il download in un file temporaneo.
			}

			return self::http_response( $code, '', isset( $args['filename'] ) ? $args['filename'] : null );
		}

		/**
		 * PNG 1x1 valido (getimagesize lo riconosce, WordPress lo accetta come allegato).
		 *
		 * @return string Byte del file.
		 */
		protected static function png_bytes() {
			return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixture binaria.
		}

		/**
		 * GIF 1x1 valido (per il caso "il servizio risponde con un GIF").
		 *
		 * @return string Byte del file.
		 */
		protected static function gif_bytes() {
			return base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixture binaria.
		}

		// -------------------------------------------------------------------
		// Golden normalizzati
		// -------------------------------------------------------------------

		/**
		 * Golden con segnaposto per i valori che dipendono dall'ambiente.
		 *
		 * Lo snapshot e' quello standard (status, body, header, option scritte/cancellate); poi:
		 *  - ogni percorso di $env_paths (notazione a punti nello snapshot, es. "body.summary.php_version")
		 *    diventa `<ENV:tipo>`: il test resta sensibile alla PRESENZA e al TIPO del valore, non al suo contenuto;
		 *  - l'URL home (http, ambiente-dipendente) diventa `<HOME_URL>`;
		 *  - se presente, $after puo' ritoccare ancora lo snapshot (es. ID casuali).
		 *
		 * @param string           $name           Nome del golden (sotto tests/fixtures/golden/).
		 * @param WP_REST_Response $response       Risposta.
		 * @param array            $options_before Option prima della richiesta.
		 * @param string[]         $env_paths      Percorsi da rendere indipendenti dall'ambiente.
		 * @param callable|null    $after          function( array $snapshot ): array.
		 */
		protected function assert_read_golden( $name, WP_REST_Response $response, array $options_before, array $env_paths = array(), $after = null ) {
			$snapshot = WPHC_Golden::capture( $response, $options_before, $this->plugin_options() );

			foreach ( $env_paths as $path ) {
				$snapshot = self::mask_path( $snapshot, explode( '.', $path ) );
			}
			if ( null !== $after ) {
				$snapshot = $after( $snapshot );
			}
			$snapshot = $this->mask_home_url( $snapshot );

			WPHC_Golden::assert_matches( $this, $name, $snapshot );
		}

		/**
		 * Sostituisce con `<ENV:tipo>` il valore a un percorso (errore se il percorso non esiste:
		 * una chiave rinominata nel plugin deve far fallire il test, non passare in silenzio).
		 *
		 * @param array    $data    Dati.
		 * @param string[] $segments Segmenti del percorso.
		 * @return array
		 */
		protected static function mask_path( array $data, array $segments ) {
			$key = array_shift( $segments );
			if ( ! array_key_exists( $key, $data ) ) {
				throw new RuntimeException( "Percorso golden inesistente: {$key}" );
			}
			if ( array() === $segments ) {
				$data[ $key ] = '<ENV:' . gettype( $data[ $key ] ) . '>';
			} else {
				$data[ $key ] = self::mask_path( (array) $data[ $key ], $segments );
			}

			return $data;
		}

		/**
		 * Sostituisce ricorsivamente l'URL home (http o https) con `<HOME_URL>`.
		 *
		 * @param mixed $value Valore.
		 * @return mixed
		 */
		protected function mask_home_url( $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$value[ $key ] = $this->mask_home_url( $item );
				}

				return $value;
			}
			if ( is_string( $value ) ) {
				$home = untrailingslashit( get_home_url() );

				return str_replace( array( $home, set_url_scheme( $home, 'https' ) ), '<HOME_URL>', $value );
			}

			return $value;
		}

		// -------------------------------------------------------------------
		// CORS
		// -------------------------------------------------------------------

		/**
		 * Esegue la logica REALE di wphc_maybe_send_cors_headers() catturando header() e header_remove().
		 *
		 * LIMITE DI OSSERVABILITA': in CLI header() non conserva nulla (headers_list() e' vuoto) e
		 * Xdebug non c'e' nel container. Per leggere gli header si prende il SORGENTE della funzione
		 * dal file di plugin caricato (Reflection: righe di inizio e fine), la si dichiara con un altro
		 * nome nel namespace WPHC_Cors_Spy, dove header()/header_remove() sono sostituiti da funzioni che
		 * registrano. Le chiamate non qualificate a header() dentro un namespace risolvono prima la
		 * funzione del namespace: cosi' gira il codice vero, riga per riga, e non una copia scritta a mano.
		 * Dopo il refactoring il sorgente e' quello del metodo WPHC_Cors::maybe_send_headers (tests/refactor-map.php).
		 * ATTENZIONE: la copia gira in un namespace diverso, quindi ogni riferimento a `self::` o a una classe
		 * non qualificata nel corpo (es. `WPHC_Options::OPT_DASHBOARD_ORIGIN` al posto del letterale
		 * 'wp_health_check_dashboard_origin') risolverebbe nel namespace spia e fallirebbe: andrebbe qualificato
		 * con il backslash iniziale (`\WPHC_Options::...`). L'adattatore va riverificato con la prima build.
		 *
		 * @param string|null $request_origin Origin della richiesta ($_SERVER['HTTP_ORIGIN']); null = nessun header.
		 * @param mixed       $configured     Valore di wp_health_check_dashboard_origin (null = option assente).
		 * @return array{headers:string[],removed:array<int,string|null>} Header inviati e header rimossi.
		 */
		protected function capture_cors( $request_origin, $configured = null ) {
			if ( ! function_exists( 'WPHC_Cors_Spy\\maybe_send_cors_headers' ) ) {
				// Funzione del plugin 1.38.1 oppure, dopo il refactoring, metodo statico che la sostituisce (tests/refactor-map.php).
				$callable   = self::cb( 'wphc_maybe_send_cors_headers' );
				$reflection = is_string( $callable ) ? new ReflectionFunction( $callable ) : new ReflectionMethod( $callable[0], $callable[1] );
				$lines      = file( $reflection->getFileName() );
				$source     = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
				// Si toglie la firma (con gli eventuali modificatori) e la copia prende un nome fisso nel namespace spia.
				$source = preg_replace( '/^\s*(?:(?:public|private|protected|static|final)\s+)*function\s+\w+\s*\(/', 'function maybe_send_cors_headers(', $source, 1, $count );
				if ( 1 !== $count ) {
					throw new RuntimeException( 'Firma della funzione CORS non riconosciuta: aggiornare WPHC_Read_Test_Case::capture_cors().' );
				}
				eval( 'namespace WPHC_Cors_Spy; ' . $source ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- vedi il docblock: esegue il sorgente reale con header() sostituito.
			}

			if ( null === $configured ) {
				delete_option( 'wp_health_check_dashboard_origin' );
			} else {
				update_option( 'wp_health_check_dashboard_origin', $configured );
			}
			if ( null === $request_origin ) {
				unset( $_SERVER['HTTP_ORIGIN'] );
			} else {
				$_SERVER['HTTP_ORIGIN'] = $request_origin;
			}

			\WPHC_Cors_Spy\Log::reset();
			\WPHC_Cors_Spy\maybe_send_cors_headers();

			return array(
				'headers' => \WPHC_Cors_Spy\Log::$headers,
				'removed' => \WPHC_Cors_Spy\Log::$removed,
			);
		}

		// -------------------------------------------------------------------
		// Redirect (autologin)
		// -------------------------------------------------------------------

		/**
		 * Esegue $callback intercettando il redirect del plugin (il plugin chiama exit dopo
		 * wp_safe_redirect: il filtro `wp_redirect` lancia un'eccezione PRIMA di arrivarci).
		 *
		 * @param callable $callback Codice da eseguire.
		 * @return WPHC_Test_Redirect|null Il redirect avvenuto, o null se il codice e' terminato senza redirect.
		 */
		protected function catch_redirect( callable $callback ) {
			$thrower = static function ( $location, $status ) {
				$redirect              = new WPHC_Test_Redirect( 'redirect' );
				$redirect->location    = $location;
				$redirect->http_status = $status;
				throw $redirect;
			};
			add_filter( 'wp_redirect', $thrower, 1, 2 );

			try {
				$callback();
			} catch ( WPHC_Test_Redirect $redirect ) {
				return $redirect;
			} finally {
				remove_filter( 'wp_redirect', $thrower, 1 );
			}

			return null;
		}
	}
}
