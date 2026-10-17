<?php
/**
 * Helper per i test di caratterizzazione di admin e WP-CLI (sezioni S e T del plugin 1.38.1).
 *
 * File di supporto, NON un test (il suffisso della suite e' prefix="test-"): i file
 * test-admin.php e test-cli.php lo caricano con require_once. Non modifica la classe base
 * (class-wphc-test-case.php): la estende.
 *
 * Cosa offre:
 *  - segnali (eccezioni) per intercettare i redirect e i wp_die() senza uscire dal processo;
 *  - esecuzione di un handler admin-post.php o AJAX attraverso gli hook veri (do_action),
 *    cosi' i test non dipendono dai nomi delle funzioni PHP (che il refactoring sposta);
 *  - rendering della tab Site Health con cattura dell'output;
 *  - snapshot (golden) degli esiti degli handler: redirect, option e transient scritti;
 *  - estrazione "strutturale" dell'HTML della tab (titoli, form, campi, id), da confrontare
 *    senza fissare l'intero markup.
 *
 * @package WP_Health_Check
 */

require_once __DIR__ . '/class-wphc-test-case.php';
require_once __DIR__ . '/class-wphc-golden.php';
require_once __DIR__ . '/trait-wphc-plugin-file-guard.php';

/**
 * Segnale lanciato al posto del redirect (filtro wp_redirect): interrompe l'handler prima di exit.
 */
final class WPHC_Redirect_Signal extends RuntimeException {

	/**
	 * URL di destinazione.
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * Codice HTTP del redirect.
	 *
	 * @var int
	 */
	public $status = 302;
}

/**
 * Segnale lanciato al posto di wp_die() (anche la variante AJAX).
 */
final class WPHC_Die_Signal extends RuntimeException {

	/**
	 * Codice HTTP richiesto da wp_die() (args['response']), null se non indicato.
	 *
	 * @var int|null
	 */
	public $response = null;
}

/**
 * Base dei test di admin: estende la classe base con l'esecuzione degli handler.
 */
abstract class WPHC_Admin_Test_Case extends WPHC_Test_Case {

	// Guardia per-test sul file del plugin sotto test: gli handler admin possono arrivare al self-update.
	use WPHC_Plugin_File_Guard;

	/**
	 * Ultimo codice impostato con status_header() durante una cattura (wp_send_json_* lo usa).
	 *
	 * @var int|null
	 */
	protected $last_status_header = null;

	/**
	 * Valore di $_SERVER['REQUEST_METHOD'] prima del test (null se assente): gli handler si
	 * eseguono come POST, ma lo stato globale va riportato com'era per i test che seguono.
	 *
	 * @var string|null
	 */
	protected $original_request_method = null;

	/**
	 * Segnaposto per l'id utente nei nomi dei transient per utente.
	 */
	const UID = '<UID>';

	// -----------------------------------------------------------------------
	// Ciclo di vita
	// -----------------------------------------------------------------------

	/**
	 * Come la classe base, piu' guardia sul file del plugin e superglobali ripulite: gli handler leggono $_POST/$_GET/$_REQUEST.
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin_guard_arm();
		// La tab Site Health controlla l'ultima release su GitHub (API e ripiego senza API): chiamata
		// di sfondo nota che questi test non mockano (il blocco la fa fallire e la tab la gestisce).
		$this->allow_unmocked_http_hosts( array( 'api.github.com', 'github.com' ) );
		$this->original_request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
		$_POST                         = array();
		$_GET                          = array();
		$_REQUEST                      = array();
		wp_set_current_user( 0 );
	}

	/**
	 * Ripristina le superglobali e l'utente corrente.
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		// Ripristina il metodo originale (la test library lo imposta una volta per processo).
		if ( null === $this->original_request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->original_request_method;
		}
		wp_set_current_user( 0 );
		// Ripristino e ripulitura PRIMA del tear_down della classe base; il fallimento DOPO.
		$problems = $this->plugin_guard_release();
		parent::tear_down();
		$this->plugin_guard_assert( $problems );
	}

	// -----------------------------------------------------------------------
	// Utenti
	// -----------------------------------------------------------------------

	/**
	 * Crea un utente con il ruolo indicato e lo imposta come corrente.
	 *
	 * @param string $role Ruolo WordPress ('administrator', 'editor', 'subscriber').
	 * @return int ID dell'utente.
	 */
	protected function login_as( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	// -----------------------------------------------------------------------
	// Cattura di redirect e wp_die()
	// -----------------------------------------------------------------------

	/**
	 * Esegue $callback intercettando redirect, wp_die() e output.
	 *
	 * Esito restituito:
	 *  - type: 'redirect' | 'die' | 'none' (nessuno dei due: l'handler e' tornato normalmente);
	 *  - location, status: per i redirect;
	 *  - message, response: per i wp_die() (response = codice HTTP, null se assente);
	 *  - output: testo stampato dall'handler (corpo JSON degli handler AJAX);
	 *  - status_header: ultimo status_header() chiamato (wp_send_json_error( ..., 403 ));
	 *
	 * @param callable $callback Codice da eseguire.
	 * @return array<string,mixed>
	 */
	protected function capture( callable $callback ) {
		$outcome = array(
			'type'          => 'none',
			'location'      => null,
			'status'        => null,
			'message'       => null,
			'response'      => null,
			'output'        => '',
			'status_header' => null,
		);

		$this->last_status_header = null;

		// 1. wp_die(): il filtro restituisce un callback che lancia un segnale. La test library
		// aggancia il suo handler con priorita' 1: qui si usa 99 per avere l'ultima parola.
		$die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				unset( $title );
				$signal = new WPHC_Die_Signal( is_wp_error( $message ) ? $message->get_error_message() : (string) $message );
				if ( is_array( $args ) && isset( $args['response'] ) ) {
					$signal->response = (int) $args['response'];
				}
				throw $signal;
			};
		};
		add_filter( 'wp_die_handler', $die_handler, 99 );
		add_filter( 'wp_die_ajax_handler', $die_handler, 99 );

		// 2. Redirect: il filtro wp_redirect scatta in wp_redirect() PRIMA di header() ed exit.
		$redirect_filter = static function ( $location, $status ) {
			$signal           = new WPHC_Redirect_Signal( 'redirect' );
			$signal->location = (string) $location;
			$signal->status   = (int) $status;
			throw $signal;
		};
		add_filter( 'wp_redirect', $redirect_filter, 99, 2 );

		// 3. status_header(): lo registra senza inviare nulla (in CLI sarebbe comunque inoffensivo).
		$status_filter = function ( $header, $code ) {
			$this->last_status_header = (int) $code;

			return $header;
		};
		add_filter( 'status_header', $status_filter, 99, 2 );

		// 4. Il buffer assorbe l'output dell'handler (corpo JSON degli handler AJAX).
		ob_start();
		try {
			$callback();
		} catch ( WPHC_Redirect_Signal $signal ) {
			$outcome['type']     = 'redirect';
			$outcome['location'] = $signal->location;
			$outcome['status']   = $signal->status;
		} catch ( WPHC_Die_Signal $signal ) {
			$outcome['type']     = 'die';
			$outcome['message']  = $signal->getMessage();
			$outcome['response'] = $signal->response;
		} finally {
			$outcome['output']        = (string) ob_get_clean();
			$outcome['status_header'] = $this->last_status_header;
			remove_filter( 'wp_die_handler', $die_handler, 99 );
			remove_filter( 'wp_die_ajax_handler', $die_handler, 99 );
			remove_filter( 'wp_redirect', $redirect_filter, 99 );
			remove_filter( 'status_header', $status_filter, 99 );
		}

		return $outcome;
	}

	/**
	 * Esegue un handler di admin-post.php (hook admin_post_{azione}) come farebbe WordPress.
	 *
	 * @param string            $action Nome dell'azione, uguale al nome dell'azione del nonce.
	 * @param array             $post   Campi POST aggiuntivi.
	 * @param bool|string|null  $nonce  true = nonce valido per $action; false/null = nessun nonce;
	 *                                  stringa = nonce da inviare cosi' com'e'.
	 * @return array<string,mixed> Esito (vedi capture()).
	 */
	protected function run_admin_post( $action, array $post = array(), $nonce = true ) {
		$_POST = $post;
		if ( true === $nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( $action );
		} elseif ( is_string( $nonce ) ) {
			$_POST['_wpnonce'] = $nonce;
		}
		$_POST['action']          = $action;
		$_REQUEST                 = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		return $this->capture(
			static function () use ( $action ) {
				do_action( 'admin_post_' . $action );
			}
		);
	}

	/**
	 * Esegue un handler AJAX (hook wp_ajax_{azione}) con wp_doing_ajax() attivo: senza, il
	 * controllo del nonce fallirebbe con die() e non con wp_die(), uccidendo il processo di test.
	 *
	 * @param string           $action Nome dell'azione AJAX (e del nonce).
	 * @param array            $post   Campi POST aggiuntivi.
	 * @param bool|string|null $nonce  true = nonce valido; false/null = assente; stringa = nonce dato.
	 * @return array<string,mixed> Esito (vedi capture()) con in piu' 'json' (corpo decodificato o null).
	 */
	protected function run_ajax( $action, array $post = array(), $nonce = true ) {
		$_POST = $post;
		if ( true === $nonce ) {
			$_POST['_ajax_nonce'] = wp_create_nonce( $action );
		} elseif ( is_string( $nonce ) ) {
			$_POST['_ajax_nonce'] = $nonce;
		}
		$_POST['action']           = $action;
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		add_filter( 'wp_doing_ajax', '__return_true' );
		try {
			$outcome = $this->capture(
				static function () use ( $action ) {
					do_action( 'wp_ajax_' . $action );
				}
			);
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$outcome['json'] = json_decode( $outcome['output'], true );

		return $outcome;
	}

	// -----------------------------------------------------------------------
	// Redirect: lettura e snapshot
	// -----------------------------------------------------------------------

	/**
	 * Scompone l'URL di un redirect in percorso e parametri di query (ordinati).
	 *
	 * @param string $location URL del redirect.
	 * @return array{path:string,query:array<string,string>}
	 */
	protected function parse_redirect( $location ) {
		$parts = wp_parse_url( (string) $location );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		ksort( $query );

		// Il prefisso del percorso (sottocartella di installazione) non e' contratto.
		$base = (string) wp_parse_url( admin_url(), PHP_URL_PATH ); // es. "/wp-admin/".
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = '/wp-admin/' . substr( $path, strlen( $base ) );
		}

		return array(
			'path'  => $path,
			'query' => $query,
		);
	}

	/**
	 * Transient del plugin presenti nel database (nome senza prefisso => valore). Legge dal DB.
	 *
	 * @return array<string,mixed>
	 */
	protected function plugin_transients() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_transient_wphc_' ) . '%',
				$wpdb->esc_like( '_transient_wp_health_check_' ) . '%'
			),
			ARRAY_A
		);

		$transients = array();
		foreach ( $rows as $row ) {
			$transients[ substr( $row['option_name'], strlen( '_transient_' ) ) ] = maybe_unserialize( $row['option_value'] );
		}

		return $transients;
	}

	/**
	 * Fotografia dello stato prima di un handler: option e transient del plugin.
	 *
	 * @return array{options:array,transients:array}
	 */
	protected function state_before() {
		return array(
			'options'    => $this->plugin_options(),
			'transients' => $this->plugin_transients(),
		);
	}

	/**
	 * Costruisce lo snapshot normalizzato di un handler: esito (redirect o die), option e
	 * transient scritti o cancellati rispetto a $before. I valori volatili (timestamp, id,
	 * durata della richiesta, id utente nei nomi dei transient) sono sostituiti da segnaposto.
	 *
	 * @param array $outcome Esito di capture().
	 * @param array $before  Risultato di state_before().
	 * @return array<string,mixed>
	 */
	protected function handler_snapshot( array $outcome, array $before ) {
		$after = $this->state_before();

		$result = array( 'type' => $outcome['type'] );
		if ( 'redirect' === $outcome['type'] ) {
			$result['status']   = $outcome['status'];
			$result['redirect'] = $this->parse_redirect( $outcome['location'] );
		} elseif ( 'die' === $outcome['type'] ) {
			$result['response'] = $outcome['response'];
			$result['message']  = $outcome['message'];
		}

		$snapshot = array( 'result' => $result );
		foreach ( array( 'options', 'transients' ) as $kind ) {
			$written = array();
			foreach ( $after[ $kind ] as $name => $value ) {
				if ( ! array_key_exists( $name, $before[ $kind ] ) || $before[ $kind ][ $name ] !== $value ) {
					$written[ $this->mask_user_id( $name ) ] = $value;
				}
			}
			$deleted = array();
			foreach ( array_diff( array_keys( $before[ $kind ] ), array_keys( $after[ $kind ] ) ) as $name ) {
				$deleted[] = $this->mask_user_id( $name );
			}
			sort( $deleted );
			$snapshot[ $kind . '_written' ] = $written;
			$snapshot[ $kind . '_deleted' ] = $deleted;
		}

		return $this->mask_durations( WPHC_Golden::normalize( $snapshot ) );
	}

	/**
	 * Sostituisce con un segnaposto l'id numerico alla fine del nome di un transient per utente.
	 *
	 * @param string $name Nome.
	 * @return string
	 */
	private function mask_user_id( $name ) {
		return preg_replace( '/_(\d+)$/', '_' . self::UID, $name );
	}

	/**
	 * Sostituisce con un segnaposto i valori che dipendono dalla macchina o dal caso: la durata (ms)
	 * delle richieste e l'id di consegna del webhook di prova.
	 *
	 * @param mixed $value Valore.
	 * @return mixed
	 */
	private function mask_durations( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = ( 'duration_ms' === $key && is_int( $item ) ) ? '<MS>' : $this->mask_durations( $item );
			}
		} elseif ( is_string( $value ) && 1 === preg_match( '/^test-[0-9a-f]{16}$/', $value ) ) {
			// Id di consegna del webhook di prova: "test-" + correlation id casuale.
			return 'test-' . WPHC_Golden::HEX;
		}

		return $value;
	}

	/**
	 * Confronta lo snapshot di un handler con il golden tests/fixtures/golden/admin/<nome>.json.
	 *
	 * @param string $name    Nome del golden (senza cartella ne' estensione).
	 * @param array  $outcome Esito di capture().
	 * @param array  $before  Risultato di state_before().
	 */
	protected function assert_handler_golden( $name, array $outcome, array $before ) {
		WPHC_Golden::assert_matches( $this, 'admin/' . $name, $this->handler_snapshot( $outcome, $before ) );
	}

	// -----------------------------------------------------------------------
	// Tab Site Health
	// -----------------------------------------------------------------------

	/**
	 * HTML della tab "WP Health Check" (hook site_health_tab_content).
	 *
	 * @param string $tab Slug della tab richiesta.
	 * @return string HTML prodotto.
	 */
	protected function render_tab( $tab = 'wp-health-check' ) {
		// Il template usa submit_button(): in WordPress la carica wp-admin; nei test no.
		require_once ABSPATH . 'wp-admin/includes/template.php';

		ob_start();
		try {
			do_action( 'site_health_tab_content', $tab );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Simula "versione piu' recente disponibile" senza rete: il valore e' letto dalla cache.
	 *
	 * @param string $version Versione (senza "v"); stringa vuota = non determinabile.
	 */
	protected function set_latest_version( $version ) {
		set_transient( 'wphc_latest_version_cache', $version, HOUR_IN_SECONDS );
	}

	/**
	 * Verifica che i tag di struttura principali dell'HTML siano bilanciati (script e style esclusi).
	 *
	 * @param string $html HTML da controllare.
	 */
	protected function assert_balanced_html( $html ) {
		$stripped = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#s', '', $html );
		foreach ( array( 'div', 'form', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'ul', 'li', 'p', 'h2', 'h3' ) as $tag ) {
			$opened = preg_match_all( '#<' . $tag . '(?=[\s>])#i', $stripped );
			$closed = preg_match_all( '#</' . $tag . '>#i', $stripped );
			$this->assertSame( $opened, $closed, "Tag <{$tag}> non bilanciato" );
		}
	}

	/**
	 * Estrae la struttura dell'HTML della tab: titoli, etichette delle righe, form (azione,
	 * campo "action", campi di input, pulsanti), id degli elementi, notice e presenza dei nonce.
	 * Non include i valori variabili (nonce, URL del sito) e non fissa il markup di dettaglio.
	 *
	 * @param string $html HTML della tab.
	 * @return array<string,mixed>
	 */
	protected function tab_structure( $html ) {
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $dom );

		$clean = static function ( $text ) {
			return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		};

		$structure = array(
			'headings' => array(),
			'rows'     => array(),
			'notices'  => array(),
			'forms'    => array(),
			'ids'      => array(),
			'buttons'  => array(),
			'scripts'  => 0,
		);

		foreach ( $xpath->query( '//h2 | //h3' ) as $node ) {
			$structure['headings'][] = $node->nodeName . ': ' . $clean( $node->textContent );
		}
		foreach ( $xpath->query( '//table//tr' ) as $node ) {
			$cells = $xpath->query( './td | ./th', $node );
			if ( $cells->length > 0 ) {
				$structure['rows'][] = $clean( $cells->item( 0 )->textContent );
			}
		}
		foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " notice ")]' ) as $node ) {
			$structure['notices'][] = $clean( $node->getAttribute( 'class' ) ) . ' | ' . $clean( $node->textContent );
		}

		$admin_post = (string) wp_parse_url( admin_url( 'admin-post.php' ), PHP_URL_PATH );
		foreach ( $xpath->query( '//form' ) as $form ) {
			$entry = array(
				'method' => strtolower( $form->getAttribute( 'method' ) ),
				'action' => ( wp_parse_url( $form->getAttribute( 'action' ), PHP_URL_PATH ) === $admin_post ) ? '<admin-post.php>' : $form->getAttribute( 'action' ),
				'fields' => array(),
			);
			foreach ( $xpath->query( './/input | .//select | .//textarea | .//button', $form ) as $field ) {
				$name = $field->getAttribute( 'name' );
				$type = $field->getAttribute( 'type' );
				if ( 'hidden' === $type && 'action' === $name ) {
					$entry['hidden_action'] = $field->getAttribute( 'value' );
					continue;
				}
				// Il campo _wp_http_referer e il nonce variano da richiesta a richiesta: solo il nome. Per le
				// checkbox si registra anche lo stato (`checked` / `unchecked`): e' il valore salvato che il
				// template rende, e un'inversione (es. un `checked()` sbagliato) deve far fallire il golden.
				$state = '';
				if ( 'submit' === $type ) {
					$state = ':' . $field->getAttribute( 'value' );
				} elseif ( 'checkbox' === $type ) {
					$state = $field->hasAttribute( 'checked' ) ? ':checked' : ':unchecked';
				}
				$entry['fields'][] = $field->nodeName . ':' . $type . ':' . $name . $state;
			}
			$structure['forms'][] = $entry;
		}
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$structure['ids'][] = $node->getAttribute( 'id' );
		}
		foreach ( $xpath->query( '//button[not(ancestor::form)]' ) as $node ) {
			$structure['buttons'][] = $clean( $node->getAttribute( 'id' ) . ' ' . $node->getAttribute( 'data-endpoint' ) . ' ' . $node->textContent );
		}
		// I nonce di test/log/reveal viaggiano come data-nonce: solo la presenza.
		$structure['data_nonce_elements'] = array();
		foreach ( $xpath->query( '//*[@data-nonce]' ) as $node ) {
			$structure['data_nonce_elements'][] = $node->getAttribute( 'id' ) . ':' . ( '' !== $node->getAttribute( 'data-nonce' ) ? 'nonce' : 'vuoto' );
		}
		$structure['scripts'] = $xpath->query( '//script' )->length;

		// Azioni AJAX che lo script inline invia (`fd.append( 'action', '...' )`): sono il contratto fra il JavaScript
		// della tab e gli hook `wp_ajax_*` del plugin, e non compaiono in nessun altro punto della struttura.
		preg_match_all( "/append\(\s*'action',\s*'([a-z_]+)'/", $html, $matches );
		$structure['ajax_actions'] = $matches[1];

		// Azione di ciascun nonce: si cerca fra le azioni note (AJAX e admin-post della tab) quella per cui il nonce
		// e' valido. Un nonce creato con un'azione diversa (o rinominata) darebbe 'sconosciuta' e farebbe fallire il golden.
		$candidates = array_unique( array_merge( array( 'wphc_test_endpoint', 'wphc_view_log', 'wphc_reveal_secret' ), $structure['ajax_actions'] ) );
		foreach ( $structure['forms'] as $form_entry ) {
			if ( isset( $form_entry['hidden_action'] ) ) {
				$candidates[] = $form_entry['hidden_action'];
			}
		}
		$structure['nonce_actions'] = array();
		foreach ( $xpath->query( '//*[@data-nonce]' ) as $node ) {
			$nonce_action = 'sconosciuta';
			foreach ( $candidates as $candidate ) {
				if ( false !== wp_verify_nonce( $node->getAttribute( 'data-nonce' ), $candidate ) ) {
					$nonce_action = $candidate;
					break;
				}
			}
			$structure['nonce_actions'][ $node->getAttribute( 'id' ) ] = $nonce_action;
		}

		return $structure;
	}

	/**
	 * Confronta la struttura della tab con il golden admin/<nome>.json.
	 *
	 * @param string $name Nome del golden.
	 * @param string $html HTML della tab.
	 */
	protected function assert_tab_structure_golden( $name, $html ) {
		WPHC_Golden::assert_matches( $this, 'admin/' . $name, WPHC_Golden::normalize( $this->tab_structure( $html ) ) );
	}

	// -----------------------------------------------------------------------
	// Stati del sito
	// -----------------------------------------------------------------------

	/**
	 * Porta il sito in stato "enrolled v1" con il token indicato e metadati di enroll.
	 *
	 * @param string $token Token.
	 */
	protected function seed_enrolled_v1( $token = 'tok-v1-secret-abcdef123456' ) {
		$this->seed_enrolled( $token, 1 );
		update_option( 'wp_health_check_enrolled_at', '2026-10-01T10:00:00+00:00', false );
		update_option( 'wp_health_check_enrolled_ip', '198.51.100.7', false );
	}

	/**
	 * Porta il sito in stato "enrolled v2" ruotato (token corrente, precedente in grazia).
	 *
	 * @param string $token Token corrente.
	 */
	protected function seed_enrolled_v2( $token = 'tok-v2-secret-abcdef123456' ) {
		$this->seed_enrolled( $token, 2 );
		update_option( 'wp_health_check_enrolled_at', '2026-10-01T10:00:00+00:00', false );
		update_option( 'wp_health_check_enrolled_ip', '198.51.100.7', false );
		update_option( 'wp_health_check_token_prev', 'tok-prev-secret', false );
		update_option( 'wp_health_check_token_rotated_at', 1790000000, false );
		update_option( 'wp_health_check_secret_kid', 'k1', false );
	}

	/**
	 * Scrive una riga nella tabella di log degli update (per i test di "Visualizza log").
	 *
	 * @param string $type   Tipo (plugin, theme, core, token, login).
	 * @param string $target Elemento.
	 * @param string $source Origine (api, wp-admin, cron, wp-cli).
	 * @return int ID della riga.
	 */
	protected function seed_log_row( $type, $target, $source ) {
		return self::call( 'wphc_log_update_row', self::call( 'wphc_generate_correlation_id' ), $type, $target, $target, '1.0', '2.0', 'completed', null, null, $source, 'tester' );
	}

	// -----------------------------------------------------------------------
	// HTTP simulato e lettura del log
	// -----------------------------------------------------------------------

	/**
	 * Risposta HTTP nel formato che wp_remote_*() restituirebbe.
	 *
	 * @param int    $code    Codice HTTP.
	 * @param string $body    Corpo.
	 * @param array  $headers Header di risposta.
	 * @return array<string,mixed>
	 */
	protected function http_response( $code, $body = '', array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Testo visibile di un frammento HTML: senza script/style/tag, con le entita' decodificate
	 * (esc_html() trasforma l'apostrofo in &#039;) e gli spazi collassati.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected function visible_text( $html ) {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#s', '', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Ultima riga della tabella di log con il messaggio indicato (array associativo) o null.
	 *
	 * @param string $message Messaggio cercato.
	 * @return array<string,mixed>|null
	 */
	protected function last_log_row_with_message( $message ) {
		global $wpdb;

		$table = self::call( 'wphc_update_log_table' );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE message = %s ORDER BY id DESC LIMIT 1", $message ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
