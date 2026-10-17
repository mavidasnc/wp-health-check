<?php
/**
 * Aggiornamenti bulk: documento del job, coda su WP-Cron, tick di drain, rotte /update/bulk e payload del webhook di fine job.
 *
 * @package WP_Health_Check
 */

/**
 * Aggiornamenti "bulk" di plugin e temi (oppure del core, in un job esclusivo): la rotta REST autenticata ACCODA
 * un job e WP-Cron lo SMALTISCE elemento per elemento, riusando WPHC_Item_Updater::perform_item_update() e
 * perform_core_update() cosi' come sono. Il documento completo del job vive in un'option non autoloadata, il
 * riassunto (letto da /health) in una piccola option autoloadata, e un transient dedicato impedisce a due tick di
 * sovrapporsi.
 *
 * Raccoglie le sezioni N e O (payload e invio del report di fine job) del file 1.38.1: 32 metodi in un solo
 * file perche' otto prendono `array &$job` per riferimento e vanno spostati insieme (la firma resta identica).
 * Hook serviti (registrati per ora in legacy.php, dal lotto 4 in WPHC_Plugin::boot()): init (ri-armo del tick,
 * TERZO dei quattro callback del plugin) e l'evento cron wphc_bulk_update_tick, che esegue run_tick().
 * route_status() ha effetti collaterali (ripara gli item orfani, puo' finalizzare il job e inviare il webhook).
 */
final class WPHC_Bulk {

	// -----------------------------------------------------------------------
	// AGGIORNAMENTI BULK (plugin/temi, oppure core in job esclusivo) VIA POST /update/bulk + WP-CRON
	// -----------------------------------------------------------------------
	//
	// Modello: la rotta REST autenticata ACCODA un job (mai un update di
	// per se'), WP-Cron si limita a SMALTIRE una coda gia' autorizzata,
	// elemento per elemento, riusando WPHC_Item_Updater::perform_item_update() cosi' com'e'
	// (nessuna modifica al suo comportamento sincrono per singolo elemento).
	// Nessun update parte mai senza un trigger autenticato esplicito - coerente
	// con la scelta architetturale che il sito non ha un cron di auto-update
	// proprio (vedi README, sezione "Architettura e scopo").
	//
	// Storage: il documento completo del job vive in un option NON autoloadato
	// (puo' arrivare a qualche decina di KB con 100 elementi); un secondo
	// option piccolo e fisso, autoloadato, ne tiene il solo riassunto per il
	// campo O(1) esposto da /health. Un transient dedicato (DISTINTO dal lock
	// di update per singolo elemento, che resta interamente dentro
	// WPHC_Item_Updater::perform_item_update()) impedisce a due tick del drain di
	// sovrapporsi.

	/**
	 * Legge il documento completo del job bulk corrente.
	 *
	 * @param bool $fresh True per bypassare l'object cache: necessario nel
	 *                     drain, dove un processo di lunga durata continuerebbe
	 *                     altrimenti a servire una copia in memoria stantia
	 *                     dell'option mentre un altro tick la aggiorna.
	 * @return array|null Il documento, oppure null se non e' mai stato creato un job.
	 */
	private static function get_job( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( WPHC_Options::OPT_BULK_JOB, 'options' );
		}
		$job = get_option( WPHC_Options::OPT_BULK_JOB );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Persiste il documento del job e ne sincronizza il riassunto autoloadato.
	 * Ricalcola sempre 'stall_after_ts' prima di salvare, cosi' resta coerente
	 * con lo stato corrente degli item ad ogni scrittura (vedi
	 * WPHC_Bulk::compute_stall_after_ts()).
	 *
	 * @param array $job Documento completo del job.
	 */
	private static function save_job( array $job ) {
		$job['stall_after_ts'] = self::compute_stall_after_ts( $job );
		update_option( WPHC_Options::OPT_BULK_JOB, $job, false );
		self::sync_summary( $job );
	}

	/**
	 * Istante oltre il quale il job va considerato 'stalled' se ancora attivo.
	 * Un item 'running' con CLAIMED_TS valorizzato usa il proprio timeout
	 * (piu' largo per il core, che sostituisce l'intero filesystem del core e
	 * puo' restare in corso ben oltre i 600s usati per plugin/temi) invece del
	 * generico next_run_ts: altrimenti un core update legittimo, in corso da
	 * 10-30 minuti, veniva segnalato 'stalled' mentre il tick era semplicemente
	 * ancora dentro Core_Upgrader::upgrade().
	 *
	 * @param array $job Documento completo del job.
	 * @return int Timestamp Unix.
	 */
	private static function compute_stall_after_ts( array $job ) {
		foreach ( $job['items'] as $item ) {
			if ( 'running' === $item['state'] && null !== $item['claimed_ts'] ) {
				$timeout = ( 'core' === $item['type'] ) ? WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT : WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT;
				return $item['claimed_ts'] + $timeout + WP_HEALTH_CHECK_BULK_STALL_GRACE;
			}
		}
		return $job['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE;
	}

	/**
	 * Riassunto piccolo e fisso, autoloadato: l'unico che /health legge (vedi
	 * WPHC_Bulk::summary()), cosi' la rotta resta O(1) anche con un documento
	 * job di decine di KB.
	 *
	 * @param array $job Documento completo del job.
	 */
	private static function sync_summary( array $job ) {
		update_option(
			WPHC_Options::OPT_BULK_STATUS,
			array(
				'job_id'         => $job['job_id'],
				'status'         => $job['status'],
				'abort_reason'   => $job['abort_reason'],
				'total'          => $job['counters']['total'],
				'done'           => $job['counters']['done'],
				'updated'        => $job['counters']['updated'],
				'skipped'        => $job['counters']['skipped'],
				'warnings'       => $job['counters']['warnings'],
				'failed'         => $job['counters']['failed'],
				'created_at'     => gmdate( 'c', $job['created_ts'] ),
				'finished_at'    => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
				'next_run_ts'    => $job['next_run_ts'],
				'stall_after_ts' => $job['stall_after_ts'],
			),
			true
		);
	}

	/**
	 * Riassunto del job esposto da /health: un solo get_option() autoloadato piu'
	 * un confronto time(), nessuna query - coerente col contratto economico
	 * della rotta. 'stalled' e' derivato a lettura (mai spinto): funziona anche
	 * su un sito dove il cron non gira piu' per definizione.
	 *
	 * @return array|null Null se non e' mai stato creato un job.
	 */
	public static function summary() {
		$status = get_option( WPHC_Options::OPT_BULK_STATUS );
		if ( ! is_array( $status ) ) {
			return null;
		}

		// stall_after_ts assente = riassunto scritto da una versione precedente
		// dell'agent (prima di stall_after_ts): stessa formula di prima, sul
		// solo next_run_ts.
		$stall_after = isset( $status['stall_after_ts'] )
			? (int) $status['stall_after_ts']
			: ( (int) $status['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE );

		$active  = in_array( $status['status'], array( 'queued', 'running' ), true );
		$stalled = $active && ( time() > $stall_after );

		return array(
			'job_id'       => $status['job_id'],
			'status'       => $status['status'],
			'total'        => $status['total'],
			'done'         => $status['done'],
			'updated'      => $status['updated'],
			'skipped'      => $status['skipped'],
			'warnings'     => $status['warnings'],
			'failed'       => $status['failed'],
			'created_at'   => $status['created_at'],
			'finished_at'  => $status['finished_at'],
			'next_run_at'  => gmdate( 'c', (int) $status['next_run_ts'] ),
			'stalled'      => $stalled,
			'abort_reason' => $status['abort_reason'],
		);
	}

	/**
	 * True se il job e' in uno stato attivo (non terminale).
	 *
	 * @param array $job Documento completo del job.
	 * @return bool
	 */
	private static function is_active( array $job ) {
		return in_array( $job['status'], array( 'queued', 'running' ), true );
	}

	/**
	 * Ricalcola i contatori del job dai singoli elementi. Chiamata dopo ogni
	 * modifica agli item, cosi' 'counters' resta sempre coerente con 'items'
	 * senza doverli tenere sincronizzati a mano in piu' punti.
	 *
	 * @param array $job Documento del job, passato per riferimento.
	 */
	private static function recount( array &$job ) {
		$counters = array(
			'total'    => count( $job['items'] ),
			'done'     => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'warnings' => 0,
			'failed'   => 0,
			'pending'  => 0,
		);

		foreach ( $job['items'] as $item ) {
			if ( 'done' === $item['state'] ) {
				++$counters['done'];
				if ( 'updated' === $item['result'] ) {
					++$counters['updated'];
				} elseif ( 'reactivation_failed' === $item['result'] ) {
					++$counters['warnings'];
				} else {
					++$counters['skipped'];
				}
			} elseif ( 'error' === $item['state'] ) {
				++$counters['done'];
				++$counters['failed'];
			} else {
				++$counters['pending'];
			}
		}

		$job['counters'] = $counters;
	}

	/**
	 * Nuovo elemento del job, stato iniziale 'pending'.
	 *
	 * @param string $type   'plugin' | 'theme' | 'core'.
	 * @param string $target Plugin file, stylesheet, oppure 'core' per il core.
	 * @return array
	 */
	private static function new_item( $type, $target ) {
		return array(
			'type'         => $type,
			'target'       => $target,
			// Solo il core ha un nome noto a priori: gli esiti 'up_to_date',
			// 'not_updatable', 'failed' e 'rolled_back' di plugin/temi non lo
			// restituiscono, quindi qui resterebbe null fino al primo tentativo
			// riuscito. Per il core invece e' sempre 'WordPress', e valorizzarlo
			// subito evita un 'name: null' nel payload del webhook proprio nei
			// casi che servono a diagnosticare un fallimento.
			'name'         => ( 'core' === $type ) ? 'WordPress' : null,
			'state'        => 'pending',
			'result'       => null,
			'attempts'     => 0,
			'deferrals'    => 0,
			'next_after'   => 0,
			'claimed_ts'   => null,
			'from'         => null,
			'to'           => null,
			'log_id'       => null,
			'correlations' => array(),
			'last_error'   => null,
			'finished_ts'  => null,
		);
	}

	/**
	 * Valida, deduplica e cappa gli elementi richiesti da POST /update/bulk.
	 * Accetta la forma canonica {"items":[{"type":"plugin","target":"..."}]}
	 * e, come zucchero, {"plugins":[...],"themes":[...],"core":true}. MAI un
	 * pacchetto, una versione o un URL: stesso vincolo non negoziabile delle
	 * rotte di update singole (POST /update/plugin, /update/theme, /update/core).
	 *
	 * Il core e' un item come gli altri (type='core', target sentinella
	 * 'core', sempre lo stesso usato da WPHC_Item_Updater::perform_core_update() per il log),
	 * ma un job non puo' mescolarlo con plugin/temi: e' l'unico item il cui
	 * fallimento non ha un rollback nativo affidabile (il core non ha il
	 * temp-backup di WP 6.3+), quindi il centro deve poterlo accodare, seguire
	 * e valutare da solo prima di mettere mano a qualunque altra cosa sul sito.
	 *
	 * @param array|null $raw Corpo JSON della richiesta.
	 * @return array{items: array, rejected: array, error: string|null} 'error' e'
	 *              valorizzato SOLO per il mix core + plugin/temi ('items' e
	 *              'rejected' restano vuoti in quel caso: non e' una richiesta
	 *              parzialmente valida, va corretta e rimandata).
	 */
	private static function normalize_items( $raw ) {
		$items      = array();
		$rejected   = array();
		$seen       = array();
		$candidates = array();

		if ( is_array( $raw ) ) {
			if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
				foreach ( $raw['items'] as $entry ) {
					// Il core non ha un target scelto dal chiamante: 'target' e'
					// facoltativo solo per questo type, normalizzato piu' sotto.
					if ( is_array( $entry ) && isset( $entry['type'] )
						&& ( isset( $entry['target'] ) || 'core' === $entry['type'] ) ) {
						$candidates[] = array(
							'type'   => (string) $entry['type'],
							'target' => isset( $entry['target'] ) ? (string) $entry['target'] : '',
						);
					}
				}
			}
			if ( isset( $raw['plugins'] ) && is_array( $raw['plugins'] ) ) {
				foreach ( $raw['plugins'] as $target ) {
					$candidates[] = array(
						'type'   => 'plugin',
						'target' => (string) $target,
					);
				}
			}
			if ( isset( $raw['themes'] ) && is_array( $raw['themes'] ) ) {
				foreach ( $raw['themes'] as $target ) {
					$candidates[] = array(
						'type'   => 'theme',
						'target' => (string) $target,
					);
				}
			}
			if ( ! empty( $raw['core'] ) ) {
				$candidates[] = array(
					'type'   => 'core',
					'target' => 'core',
				);
			}
		}

		// Esclusivita': un job core non puo' contenere altro. Controllato PRIMA
		// della validazione dei singoli elementi, sul set intero della richiesta:
		// e' una proprieta' della combinazione, non del singolo item.
		$has_core  = false;
		$has_other = false;
		foreach ( $candidates as $candidate ) {
			if ( 'core' === $candidate['type'] ) {
				$has_core = true;
			} else {
				$has_other = true;
			}
		}
		if ( $has_core && $has_other ) {
			return array(
				'items'    => array(),
				'rejected' => array(),
				'error'    => 'core_must_be_exclusive',
			);
		}

		foreach ( $candidates as $candidate ) {
			$type   = $candidate['type'];
			$target = trim( $candidate['target'] );

			if ( 'core' === $type && '' === $target ) {
				$target = 'core'; // Sentinella: il core non ha un target scelto dal chiamante.
			}

			if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
				$rejected[] = array(
					'type'   => $type,
					'target' => $target,
					'reason' => 'invalid_type',
				);
				continue;
			}

			// Validazione difensiva prima di toccare get_plugins()/wp_get_theme():
			// un plugin file e' "cartella/file.php" o "file.php" alla radice, uno
			// stylesheet e' una singola cartella, il core e' sempre e solo la
			// sentinella 'core'. Nessun ".." in nessuno dei tre.
			if ( 'plugin' === $type ) {
				$pattern = '/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/';
			} elseif ( 'theme' === $type ) {
				$pattern = '/^[A-Za-z0-9._-]+$/';
			} else {
				$pattern = '/^core$/';
			}

			if ( '' === $target || false !== strpos( $target, '..' ) || ! preg_match( $pattern, $target ) ) {
				$rejected[] = array(
					'type'   => $type,
					'target' => $target,
					'reason' => 'invalid_target',
				);
				continue;
			}

			$key = $type . '|' . $target;
			if ( isset( $seen[ $key ] ) ) {
				continue; // Duplicato silenzioso: non e' un errore del chiamante.
			}
			$seen[ $key ] = true;

			if ( count( $items ) >= WP_HEALTH_CHECK_BULK_MAX_ITEMS ) {
				$rejected[] = array(
					'type'   => $type,
					'target' => $target,
					'reason' => 'max_items_exceeded',
				);
				continue;
			}

			$items[] = self::new_item( $type, $target );
		}

		return array(
			'items'    => $items,
			'rejected' => $rejected,
			'error'    => null,
		);
	}

	/**
	 * Acquisisce il mutex di drain. DISTINTO dal lock di update per singolo
	 * elemento (interamente dentro WPHC_Item_Updater::perform_item_update()): serve solo a
	 * impedire che due tick dello stesso hook (WP-Cron pseudo-cron + un
	 * eventuale cron di sistema + spawn_cron() da una visita) processino in
	 * sovrapposizione lo stesso job. Il worst case di una race residua (la
	 * finestra get/set_transient non e' atomica) degrada a un tentativo
	 * duplicato che rilegge il transient core update_plugins/update_themes e
	 * trova up_to_date - mai a corruzione.
	 *
	 * @return bool True se acquisito.
	 */
	private static function acquire_lock() {
		if ( false !== get_transient( WPHC_Options::TRANSIENT_BULK_LOCK ) ) {
			return false;
		}
		set_transient( WPHC_Options::TRANSIENT_BULK_LOCK, 1, WP_HEALTH_CHECK_BULK_LOCK_TTL );
		register_shutdown_function( array( 'WPHC_Bulk', 'release_lock' ) );
		return true;
	}

	/**
	 * Rilascia il mutex di drain. Registrata anche come shutdown function: cosi'
	 * si libera comunque anche se PHP muore a meta' di un tick.
	 */
	public static function release_lock() {
		delete_transient( WPHC_Options::TRANSIENT_BULK_LOCK );
	}

	/**
	 * Schedula il prossimo tick del drain con un singolo evento (MAI un evento
	 * ricorrente): un singolo evento orfano si autodistrugge al primo firing
	 * anche se il mu-plugin viene rimosso, mentre un evento ricorrente
	 * resterebbe per sempre nell'option 'cron' (un mu-plugin non ha un hook di
	 * disattivazione che potrebbe altrimenti ripulirlo).
	 *
	 * @param int|null $delay Secondi da ora. Default WP_HEALTH_CHECK_BULK_TICK_GAP.
	 * @return int Timestamp Unix schedulato.
	 */
	private static function schedule_tick( $delay = null ) {
		if ( null === $delay ) {
			$delay = WP_HEALTH_CHECK_BULK_TICK_GAP;
		}
		$when = time() + max( 0, (int) $delay );
		if ( false === wp_next_scheduled( WPHC_Options::CRON_BULK_TICK ) ) {
			wp_schedule_single_event( $when, WPHC_Options::CRON_BULK_TICK );
		}
		return $when;
	}

	/** Cancella qualunque tick pianificato: chiamata quando il job termina. */
	private static function unschedule_ticks() {
		wp_clear_scheduled_hook( WPHC_Options::CRON_BULK_TICK );
	}

	/**
	 * Calcola il prossimo tick: al piu' presto WP_HEALTH_CHECK_BULK_TICK_GAP
	 * secondi, ma non prima del backoff piu' vicino tra gli elementi ancora
	 * pendenti (cosi' un elemento in attesa di un lungo backoff non causa tick
	 * inutili nel frattempo).
	 *
	 * @param array $job Documento del job.
	 * @return int Secondi da ora.
	 */
	private static function next_delay( array $job ) {
		$now      = time();
		$earliest = null;
		foreach ( $job['items'] as $item ) {
			if ( 'pending' === $item['state'] ) {
				$earliest = ( null === $earliest ) ? $item['next_after'] : min( $earliest, $item['next_after'] );
			}
		}
		if ( null === $earliest ) {
			return WP_HEALTH_CHECK_BULK_TICK_GAP;
		}
		return max( WP_HEALTH_CHECK_BULK_TICK_GAP, $earliest - $now );
	}

	/**
	 * Ri-armo pigro: se un job e' attivo ma non c'e' alcun tick schedulato ed e'
	 * gia' passato il momento previsto, ri-schedula e sveglia il cron. Recupera
	 * un evento cancellato da un altro plugin o da un riavvio, senza violare la
	 * dottrina "nessun update non richiesto": il job era gia' stato autorizzato
	 * da una chiamata REST autenticata, cron/visite si limitano a farlo
	 * avanzare. Costo sui siti idle (la stragrande maggioranza): una sola
	 * lettura di option gia' autoloadata.
	 */
	public static function maybe_rearm() {
		$status = get_option( WPHC_Options::OPT_BULK_STATUS );
		if ( ! is_array( $status ) || ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		if ( false !== wp_next_scheduled( WPHC_Options::CRON_BULK_TICK ) ) {
			return;
		}
		if ( time() < (int) $status['next_run_ts'] ) {
			return;
		}
		if ( false !== get_transient( WPHC_Options::TRANSIENT_BULK_REARM_LOCK ) ) {
			return;
		}
		set_transient( WPHC_Options::TRANSIENT_BULK_REARM_LOCK, 1, MINUTE_IN_SECONDS );

		self::schedule_tick( 0 );
		spawn_cron();
	}

	/**
	 * Trova l'indice del prossimo elemento pronto per un tentativo (pending e
	 * backoff scaduto), oppure null se nessuno e' pronto ora.
	 *
	 * @param array $job Documento del job.
	 * @param int   $now Timestamp Unix corrente.
	 * @return int|null
	 */
	private static function next_item_index( array $job, $now ) {
		foreach ( $job['items'] as $index => $item ) {
			if ( 'pending' === $item['state'] && $item['next_after'] <= $now ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Classifica un $result di WPHC_Item_Updater::perform_item_update() in una delle
	 * categorie di trattamento del drain. Tre dei valori possibili sono
	 * verdetti sull'INTERO SITO (kill-switch, versione WP, filesystem), non
	 * sul singolo elemento: per questi l'intero job va abortito, ripetere
	 * l'elemento 100 volte non servirebbe a nulla.
	 *
	 * @param string $result Valore del campo 'result' restituito dall'esito.
	 * @return string 'success'|'noop'|'warning'|'retry'|'defer'|'job_fatal'.
	 */
	private static function classify_result( $result ) {
		$map = array(
			'updated'                => 'success',
			'up_to_date'             => 'noop',
			'not_found'              => 'noop',
			'not_updatable'          => 'noop',
			'reactivation_failed'    => 'warning',
			'failed'                 => 'retry',
			'rolled_back'            => 'retry',
			'locked'                 => 'defer',
			'disabled'               => 'job_fatal',
			'unsupported_wp_version' => 'job_fatal',
			'fs_method_unavailable'  => 'job_fatal',
		);

		return isset( $map[ $result ] ) ? $map[ $result ] : 'retry';
	}

	/**
	 * Tempo di backoff (secondi) dopo il tentativo numero $attempts.
	 *
	 * @param int $attempts Numero di tentativi gia' effettuati.
	 * @return int
	 */
	private static function backoff_for_attempt( $attempts ) {
		$table = WP_HEALTH_CHECK_BULK_BACKOFF;
		$index = max( 0, min( (int) $attempts, count( $table ) - 1 ) );
		return (int) $table[ $index ];
	}

	/**
	 * Applica l'esito di un tentativo all'elemento e ricalcola i contatori.
	 * Puo' abortire l'intero job (vedi WPHC_Bulk::classify_result()).
	 *
	 * @param array $job     Documento del job, passato per riferimento.
	 * @param int   $index   Indice dell'elemento in $job['items'].
	 * @param array $outcome Esito grezzo di WPHC_Item_Updater::perform_item_update().
	 */
	private static function apply_outcome( array &$job, $index, array $outcome ) {
		$item   = &$job['items'][ $index ];
		$result = isset( $outcome['result'] ) ? $outcome['result'] : 'failed';
		$class  = self::classify_result( $result );

		$item['result'] = $result;
		if ( isset( $outcome['name'] ) ) {
			$item['name'] = $outcome['name'];
		}
		// 'updated'/'reactivation_failed' popolano 'from'/'to' (versione reale
		// prima/dopo); i risultati che non scrivono nulla (up_to_date,
		// not_updatable...) popolano invece 'current'/'latest' — vedi
		// WPHC_Item_Updater::perform_item_update(). Senza questo doppio controllo gli item
		// riusciti del job restavano con from/to sempre null.
		if ( isset( $outcome['from'] ) ) {
			$item['from'] = $outcome['from'];
		} elseif ( isset( $outcome['current'] ) ) {
			$item['from'] = $outcome['current'];
		}
		if ( isset( $outcome['to'] ) ) {
			$item['to'] = $outcome['to'];
		} elseif ( isset( $outcome['latest'] ) ) {
			$item['to'] = $outcome['latest'];
		}
		if ( isset( $outcome['log_id'] ) && $outcome['log_id'] ) {
			$item['log_id']         = $outcome['log_id'];
			$item['correlations'][] = $outcome['log_id'];
		}
		if ( isset( $outcome['detail'] ) ) {
			$item['last_error'] = mb_substr( (string) $outcome['detail'], 0, 200 );
		}

		switch ( $class ) {
			case 'success':
			case 'noop':
			case 'warning':
				$item['state']       = 'done';
				$item['finished_ts'] = time();
				break;

			case 'retry':
				++$item['attempts'];
				if ( $item['attempts'] >= WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS ) {
					$item['state']       = 'error';
					$item['finished_ts'] = time();
				} else {
					$item['state']      = 'pending';
					$item['next_after'] = time() + self::backoff_for_attempt( $item['attempts'] );
				}
				break;

			case 'defer':
				// Contesa di lock con un update singolo concorrente: non consuma
				// un tentativo, altrimenti un sito occupato brucerebbe tutti i
				// retry su un elemento mai davvero provato.
				++$item['deferrals'];
				if ( $item['deferrals'] >= WP_HEALTH_CHECK_BULK_MAX_DEFERRALS ) {
					$item['state']       = 'error';
					$item['result']      = 'failed';
					$item['last_error']  = 'lock contention';
					$item['finished_ts'] = time();
				} else {
					$item['state']      = 'pending';
					$item['next_after'] = time() + WP_HEALTH_CHECK_BULK_TICK_GAP;
				}
				break;

			case 'job_fatal':
				unset( $item );
				self::abort_job( $job, $result );
				return;
		}

		unset( $item );
		self::recount( $job );
	}

	/**
	 * Aborta l'intero job: gli elementi ancora pendenti/in corso diventano
	 * 'error' con un motivo esplicito, cosi' il report finale (e il webhook)
	 * non li mostra come "in sospeso" per sempre.
	 *
	 * @param array  $job    Documento del job, passato per riferimento.
	 * @param string $reason Motivo macchina (es. 'disabled', 'job_timeout').
	 */
	private static function abort_job( array &$job, $reason ) {
		$job['status']       = 'aborted';
		$job['abort_reason'] = $reason;
		$job['finished_ts']  = time();

		foreach ( $job['items'] as &$item ) {
			if ( in_array( $item['state'], array( 'pending', 'running' ), true ) ) {
				$item['state']       = 'error';
				$item['last_error']  = 'job aborted: ' . $reason;
				$item['finished_ts'] = time();
			}
		}
		unset( $item );

		self::recount( $job );
	}

	/**
	 * Rileva elementi 'running' orfani (claimed_ts piu' vecchio del timeout):
	 * un crash PHP a meta' di un tentativo lascia lo stato incoerente
	 * altrimenti per sempre. L'evidenza corroborante esiste gia' nella tabella
	 * di log: una riga 'requested' senza la corrispondente riga terminale e'
	 * esattamente cio' che il pattern a due righe e' pensato per rivelare.
	 *
	 * @param array $job Documento del job, passato per riferimento.
	 * @param int   $now Timestamp Unix corrente.
	 * @return bool True se almeno un elemento e' stato "riparato".
	 */
	private static function reap_stuck_items( array &$job, $now ) {
		$reaped = false;

		foreach ( $job['items'] as &$item ) {
			if ( 'running' !== $item['state'] || null === $item['claimed_ts'] ) {
				continue;
			}
			// Il core ha una soglia piu' larga (vedi WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT):
			// un download di pacchetto completo + migliaia di file + wp_upgrade()
			// supera facilmente i 600s usati per plugin/temi.
			$timeout = ( 'core' === $item['type'] ) ? WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT : WP_HEALTH_CHECK_BULK_ITEM_TIMEOUT;
			if ( ( $now - $item['claimed_ts'] ) <= $timeout ) {
				continue;
			}

			++$item['attempts'];
			$item['last_error'] = 'interrupted (timeout/fatal)';
			if ( $item['attempts'] >= WP_HEALTH_CHECK_BULK_MAX_ATTEMPTS ) {
				$item['state']       = 'error';
				$item['result']      = 'failed';
				$item['finished_ts'] = $now;
			} else {
				$item['state']      = 'pending';
				$item['next_after'] = $now + self::backoff_for_attempt( $item['attempts'] );
			}
			$reaped = true;
		}
		unset( $item );

		if ( $reaped ) {
			self::recount( $job );
		}

		return $reaped;
	}

	/**
	 * Scadenza dura del job (WP_HEALTH_CHECK_BULK_JOB_TTL dalla creazione):
	 * finalizza come 'aborted' se superata. Chiamata sia a inizio tick sia da
	 * GET /update/bulk, cosi' anche un sito col cron morto viene finalizzato
	 * dal solo polling del centro.
	 *
	 * @param array $job Documento del job, passato per riferimento.
	 * @return bool True se il job e' stato abortito per timeout in questa chiamata.
	 */
	private static function maybe_reap( array &$job ) {
		if ( ! self::is_active( $job ) ) {
			return false;
		}
		if ( ( time() - $job['created_ts'] ) < WP_HEALTH_CHECK_BULK_JOB_TTL ) {
			return false;
		}

		self::abort_job( $job, 'job_timeout' );
		return true;
	}

	/**
	 * Determina se il job e' completo (nessun elemento ancora pendente) e, se
	 * si', ne fissa lo stato terminale. Restituisce true SOLO al momento della
	 * transizione, cosi' WPHC_Bulk::run_tick() invia il webhook esattamente una
	 * volta.
	 *
	 * @param array $job Documento del job, passato per riferimento.
	 * @return bool
	 */
	private static function maybe_finalize( array &$job ) {
		if ( 'aborted' === $job['status'] ) {
			return true; // Gia' finalizzato da WPHC_Bulk::abort_job().
		}
		if ( $job['counters']['pending'] > 0 ) {
			return false;
		}

		$job['status']      = ( $job['counters']['failed'] > 0 ) ? 'completed_with_errors' : 'completed';
		$job['finished_ts'] = time();

		return true;
	}

	/**
	 * Finalizza il job: cancella lo scheduling, persiste, invia il webhook
	 * (se non gia' inviato per questo job) e ripersiste col relativo esito.
	 *
	 * @param array $job Documento del job, passato per riferimento.
	 */
	private static function finish( array &$job ) {
		self::unschedule_ticks();
		self::save_job( $job );

		if ( empty( $job['webhook']['sent'] ) ) {
			self::send_webhook_for_job( $job );
			self::save_job( $job );
		}
	}

	/**
	 * Il tick del drain: hook di WP-Cron. Acquisisce il mutex di drain (MAI il
	 * lock di update per singolo elemento, gia' gestito internamente da
	 * WPHC_Item_Updater::perform_item_update() su ogni chiamata sequenziale), processa fino a
	 * WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK elementi entro
	 * WP_HEALTH_CHECK_BULK_TIME_BUDGET secondi (controllato PRIMA di ogni
	 * elemento, mai a meta', sempre almeno un elemento per tick), poi finalizza
	 * o ri-schedula.
	 */
	public static function run_tick() {
		if ( ! self::acquire_lock() ) {
			return;
		}

		$job = self::get_job( true );
		if ( null === $job || ! self::is_active( $job ) ) {
			self::release_lock();
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- il budget di tempo sopra e' il vero governatore, non il limite PHP.
		}
		WPHC_Item_Updater::clear_stale_maintenance();

		$job['status'] = 'running';
		$job['ticks']  = isset( $job['ticks'] ) ? $job['ticks'] + 1 : 1;
		if ( null === $job['started_ts'] ) {
			$job['started_ts'] = time();
		}

		self::reap_stuck_items( $job, time() );

		if ( self::maybe_reap( $job ) ) {
			self::finish( $job );
			self::release_lock();
			return;
		}

		self::save_job( $job );

		$start = microtime( true );
		$done  = 0;
		while ( $done < WP_HEALTH_CHECK_BULK_ITEMS_PER_TICK ) {
			if ( $done > 0 && ( microtime( true ) - $start ) > WP_HEALTH_CHECK_BULK_TIME_BUDGET ) {
				break;
			}

			$job   = self::get_job( true );
			$index = self::next_item_index( $job, time() );
			if ( null === $index ) {
				break;
			}

			$item_type   = $job['items'][ $index ]['type'];
			$item_target = $job['items'][ $index ]['target'];

			$job['items'][ $index ]['state']      = 'running';
			$job['items'][ $index ]['claimed_ts'] = time();
			// Avanza anche next_run_ts: e' il valore che next_run_at rispecchia
			// al centro e che un client vecchio (senza stall_after_ts) usa per
			// calcolare 'stalled' da solo - altrimenti resterebbe fermo
			// all'istante dell'enqueue per tutta la durata dell'item.
			$job['next_run_ts'] = time() + WP_HEALTH_CHECK_BULK_TICK_GAP;
			self::save_job( $job );

			if ( 'core' === $item_type ) {
				// Il mutex di drain (300s) e' molto piu' corto del timeout
				// dell'item core (1800s): senza riestenderlo qui, un secondo
				// tick potrebbe acquisirlo a meta' di Core_Upgrader::upgrade()
				// e tentare un secondo aggiornamento concorrente sullo stesso
				// filesystem. L'item resta comunque 'running' come guardia
				// aggiuntiva (vedi WPHC_Bulk::next_item_index()).
				set_transient( WPHC_Options::TRANSIENT_BULK_LOCK, 1, WP_HEALTH_CHECK_BULK_CORE_ITEM_TIMEOUT );
			}

			$outcome = ( 'core' === $item_type )
				? WPHC_Item_Updater::perform_core_update( false, 'cron' )
				: WPHC_Item_Updater::perform_item_update( $item_type, $item_target, false, 'cron' );

			$job = self::get_job( true );
			self::apply_outcome( $job, $index, $outcome );
			self::save_job( $job );

			if ( 'aborted' === $job['status'] ) {
				break;
			}

			++$done;

			if ( 'core' === $item_type ) {
				// Core_Upgrader ha appena sostituito centinaia di file sotto i
				// piedi di questo stesso processo PHP: classi e funzioni gia'
				// caricate in memoria possono non corrispondere piu' ai file su
				// disco. Si chiude il tick qui, il prossimo riparte da un
				// processo nuovo. Con l'esclusivita' del job core (vedi
				// WPHC_Bulk::normalize_items()) e' di fatto un no-op, ma rende
				// esplicito l'invariante anche se in futuro cambiasse.
				break;
			}
		}

		if ( self::maybe_finalize( $job ) ) {
			self::finish( $job );
		} else {
			$job['next_run_ts'] = self::schedule_tick( self::next_delay( $job ) );
			self::save_job( $job );
		}

		delete_transient( WPHC_Options::TRANSIENT_HEALTH_CACHE );
		self::release_lock();
	}

	/**
	 * Vero solo se il body della richiesta menziona il core e nient'altro.
	 * Euristica DELIBERATAMENTE conservativa sul body grezzo, non una
	 * validazione: in ogni caso ambiguo ritorna false, cosi' il gate WP 6.3
	 * resta applicato. L'autorita' sul contenuto del job resta
	 * WPHC_Bulk::normalize_items(): un body che sostiene di essere core-only ma
	 * e' misto passa comunque questo controllo (non serve il gate 6.3 per un
	 * core update), ma viene rifiutato con 400 subito dopo dalla normalizzazione.
	 *
	 * @param array $body Corpo JSON gia' decodificato della richiesta.
	 * @return bool
	 */
	private static function body_is_core_only( array $body ) {
		$core  = ! empty( $body['core'] );
		$other = ( ! empty( $body['plugins'] ) || ! empty( $body['themes'] ) );

		if ( isset( $body['items'] ) ) {
			if ( ! is_array( $body['items'] ) ) {
				return false;
			}
			foreach ( $body['items'] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['type'] ) && 'core' === $entry['type'] ) {
					$core = true;
				} else {
					$other = true;
				}
			}
		}

		return $core && ! $other;
	}

	/**
	 * Preambolo per POST /update/bulk: accesso, kill-switch, requisito versione
	 * WP, filesystem 'direct'. DELIBERATAMENTE senza il lock di update: l'ENQUEUE
	 * non tocca alcun file, e un lock momentaneo di un altro update in corso non
	 * deve far fallire la sola messa in coda.
	 *
	 * @param bool $requires_wp63 False per un job core-only: il core non usa il
	 *                             temp-backup nativo (vedi WPHC_Item_Updater::perform_core_update()),
	 *                             quindi il requisito WP 6.3 non gli si applica,
	 *                             esattamente come per POST /update/core.
	 * @return true|array True se si puo' procedere, altrimenti array-esito con
	 *                     chiavi 'result' e 'http'.
	 */
	private static function enqueue_preflight( $requires_wp63 = true ) {
		WPHC_Auth::record_access();

		if ( ! get_option( WPHC_Options::OPT_UPDATES_ENABLED, true ) ) {
			return array(
				'result' => 'disabled',
				'http'   => 403,
			);
		}

		if ( $requires_wp63 && version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
			return array(
				'result' => 'unsupported_wp_version',
				'http'   => 200,
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			return array(
				'result' => 'fs_method_unavailable',
				'http'   => 200,
			);
		}

		return true;
	}

	// -----------------------------------------------------------------------
	// CALLBACK: POST|GET /update/bulk, POST /update/bulk/cancel
	// -----------------------------------------------------------------------

	/**
	 * POST /update/bulk: accoda un job di aggiornamento bulk (plugin/temi, oppure
	 * il solo core: i due non si possono mescolare, vedi WPHC_Bulk::normalize_items()).
	 * Il body indica SOLO quali elementi aggiornare, mai un pacchetto/versione/URL
	 * - stesso vincolo non negoziabile delle rotte di update singole.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_enqueue( WP_REST_Request $request ) {
		// Letto prima del preflight (pura decodifica, nessun effetto collaterale)
		// solo per determinare se il requisito WP 6.3 si applica: un job core-only
		// non lo richiede, esattamente come POST /update/core.
		$body      = (array) $request->get_json_params();
		$preflight = self::enqueue_preflight( ! self::body_is_core_only( $body ) );
		if ( true !== $preflight ) {
			if ( 403 === (int) $preflight['http'] ) {
				return new WP_Error( 'wphc_updates_disabled', __( 'Aggiornamenti via API disattivati per questo sito.', 'wp-health-check' ), array( 'status' => 403 ) );
			}
			return rest_ensure_response(
				array(
					'accepted' => false,
					'result'   => $preflight['result'],
				)
			);
		}

		$force       = ! empty( $body['force'] );
		$current_job = self::get_job( true );

		if ( null !== $current_job && self::is_active( $current_job ) ) {
			$summary = self::summary();
			$stalled = is_array( $summary ) && ! empty( $summary['stalled'] );

			if ( ! $force || ! $stalled ) {
				return new WP_Error(
					'wphc_bulk_job_in_progress',
					__( 'Un job di aggiornamento bulk e\' gia\' in corso.', 'wp-health-check' ),
					array(
						'status'      => 409,
						'job_id'      => $current_job['job_id'],
						'job_status'  => $current_job['status'],
						'total'       => $current_job['counters']['total'],
						'done'        => $current_job['counters']['done'],
						'stalled'     => $stalled,
						'next_run_at' => gmdate( 'c', (int) $current_job['next_run_ts'] ),
					)
				);
			}

			// force=true su un job stallato: lo abortiamo esplicitamente prima di
			// accodarne uno nuovo, cosi' non resta mai un documento 'running'
			// orfano mentre un secondo job procede in parallelo.
			self::abort_job( $current_job, 'replaced_by_new_job' );
			self::finish( $current_job );
		}

		$normalized = self::normalize_items( $body );
		if ( ! empty( $normalized['error'] ) ) {
			return new WP_Error(
				'wphc_bulk_core_not_exclusive',
				__( 'Un job che include il core WordPress non puo\' contenere anche plugin o temi: accoda due job separati, in sequenza.', 'wp-health-check' ),
				array(
					'status' => 400,
					'reason' => $normalized['error'],
				)
			);
		}
		if ( empty( $normalized['items'] ) ) {
			return new WP_Error( 'wphc_bulk_no_valid_items', __( 'Nessun elemento valido da aggiornare.', 'wp-health-check' ), array( 'status' => 400 ) );
		}

		$now = time();
		$job = array(
			'job_id'        => WPHC_Util::generate_correlation_id(),
			'status'        => 'queued',
			'abort_reason'  => null,
			'created_ts'    => $now,
			'started_ts'    => null,
			'finished_ts'   => null,
			'next_run_ts'   => $now,
			'ticks'         => 0,
			'source'        => WPHC_Update_Log::detect_update_source(),
			'requested_ip'  => WPHC_Util::get_client_ip(),
			'agent_version' => WP_HEALTH_CHECK_VERSION,
			'dry_run'       => false,
			'items'         => $normalized['items'],
			'counters'      => array(),
			'webhook'       => array(
				'sent'       => false,
				'attempts'   => 0,
				'sending_ts' => null,
				'sent_ts'    => null,
				'code'       => null,
				'last_error' => null,
			),
		);
		self::recount( $job );
		self::save_job( $job );

		self::schedule_tick( 0 );
		spawn_cron();

		return new WP_REST_Response(
			array(
				'accepted'      => true,
				'job_id'        => $job['job_id'],
				'status'        => $job['status'],
				'total'         => $job['counters']['total'],
				'rejected'      => $normalized['rejected'],
				'scheduled_at'  => gmdate( 'c', $now ),
				'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'poll'          => array(
					'job' => rest_url( 'health-check/v1/update/bulk' ),
					'log' => rest_url( 'health-check/v1/update/log' ) . '?source=cron',
				),
			),
			202
		);
	}

	/**
	 * GET /update/bulk: legge il documento del job da un singolo option non
	 * autoloadato - piu' economico di una query sulla tabella di log, e l'unico
	 * modo di conoscere elementi ancora pendenti (che non hanno righe di log).
	 * Chiama anche WPHC_Bulk::maybe_reap(): il polling da centro innesca da solo
	 * la finalizzazione di un job stallato, anche su un sito col cron morto.
	 *
	 * Fa anche il reap degli item 'running' orfani (processo PHP morto a meta'
	 * tentativo): prima di questa chiamata la sola via di sblocco era il tick
	 * successivo, mai garantito su un sito col cron morto, oppure il TTL del job
	 * (6h) via WPHC_Bulk::maybe_reap(). Cosi' il solo polling del centro
	 * sblocca un job orfano entro il timeout dell'item (30 min per il core).
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response
	 */
	public static function route_status( WP_REST_Request $request ) {
		WPHC_Auth::record_access();

		$job = self::get_job( true );
		if ( null === $job ) {
			return rest_ensure_response(
				array(
					'site' => WPHC_Util::normalize_site_url(),
					'job'  => null,
				)
			);
		}

		if ( self::is_active( $job ) && self::reap_stuck_items( $job, time() ) ) {
			if ( self::maybe_finalize( $job ) ) {
				self::finish( $job );
			} else {
				self::save_job( $job );
				self::schedule_tick( 0 );
				spawn_cron();
			}
		}

		if ( self::maybe_reap( $job ) ) {
			self::finish( $job );
		}

		// stall_after_ts assente = documento scritto da una versione precedente
		// dell'agent: stessa formula di prima, sul solo next_run_ts (vedi
		// WPHC_Bulk::summary(), stesso fallback).
		$stall_after = isset( $job['stall_after_ts'] )
			? (int) $job['stall_after_ts']
			: ( (int) $job['next_run_ts'] + WP_HEALTH_CHECK_BULK_STALL_GRACE );

		$show_items = '0' !== (string) $request->get_param( 'items' );

		$payload = array(
			'job_id'       => $job['job_id'],
			'status'       => $job['status'],
			'abort_reason' => $job['abort_reason'],
			'created_at'   => gmdate( 'c', $job['created_ts'] ),
			'started_at'   => $job['started_ts'] ? gmdate( 'c', $job['started_ts'] ) : null,
			'finished_at'  => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
			'next_run_at'  => gmdate( 'c', (int) $job['next_run_ts'] ),
			'stalled'      => self::is_active( $job ) && ( time() > $stall_after ),
			'counters'     => $job['counters'],
			'webhook'      => $job['webhook'],
		);
		if ( $show_items ) {
			$payload['items'] = $job['items'];
		}

		return rest_ensure_response(
			array(
				'site'         => WPHC_Util::normalize_site_url(),
				'generated_at' => gmdate( 'c' ),
				'job'          => $payload,
			)
		);
	}

	/**
	 * POST /update/bulk/cancel: interrompe un job attivo tra un elemento e
	 * l'altro (un elemento gia' dentro Plugin_Upgrader::upgrade() completa
	 * comunque). Il webhook viene inviato anche in questo caso, col report
	 * parziale.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_cancel() {
		$job = self::get_job( true );
		if ( null === $job || ! self::is_active( $job ) ) {
			return new WP_Error( 'wphc_bulk_no_active_job', __( 'Nessun job di aggiornamento bulk in corso.', 'wp-health-check' ), array( 'status' => 404 ) );
		}

		$job['status']      = 'cancelled';
		$job['finished_ts'] = time();
		foreach ( $job['items'] as &$item ) {
			if ( in_array( $item['state'], array( 'pending', 'running' ), true ) ) {
				$item['state']       = 'error';
				$item['last_error']  = 'cancelled';
				$item['finished_ts'] = time();
			}
		}
		unset( $item );
		self::recount( $job );
		self::finish( $job );

		return rest_ensure_response(
			array(
				'site'   => WPHC_Util::normalize_site_url(),
				'job_id' => $job['job_id'],
				'status' => $job['status'],
			)
		);
	}

	/**
	 * Costruisce il report JSON di fine job bulk: un solo vocabolario di
	 * 'result' condiviso con la REST e con la tabella di log
	 * (WPHC_Item_Updater::map_item_update_outcome()), nessun segreto/token/dato utente.
	 *
	 * @param array $job Documento completo del job.
	 * @return array
	 */
	private static function build_webhook_payload( array $job ) {
		$items           = array();
		$items_truncated = false;

		foreach ( $job['items'] as $item ) {
			if ( count( $items ) >= WP_HEALTH_CHECK_WEBHOOK_MAX_ITEMS ) {
				$items_truncated = true;
				break;
			}
			$items[] = array(
				'type'        => $item['type'],
				'target'      => $item['target'],
				'name'        => $item['name'],
				'from'        => $item['from'],
				'to'          => $item['to'],
				'result'      => $item['result'],
				'attempts'    => $item['attempts'],
				'log_id'      => $item['log_id'],
				'finished_at' => $item['finished_ts'] ? gmdate( 'c', $item['finished_ts'] ) : null,
			);
		}

		return array(
			'schema'          => 'wphc.bulk_update.report/1',
			'event'           => 'wphc.bulk_update.completed',
			'site'            => WPHC_Util::normalize_site_url(),
			'agent_version'   => WP_HEALTH_CHECK_VERSION,
			'secret_kid'      => (string) get_option( WPHC_Options::OPT_SECRET_KID, '' ),
			'generated_at'    => gmdate( 'c' ),
			'job'             => array(
				'id'           => $job['job_id'],
				'source'       => $job['source'],
				'status'       => $job['status'],
				'abort_reason' => $job['abort_reason'],
				'started_at'   => $job['started_ts'] ? gmdate( 'c', $job['started_ts'] ) : null,
				'finished_at'  => $job['finished_ts'] ? gmdate( 'c', $job['finished_ts'] ) : null,
			),
			'totals'          => $job['counters'],
			'items'           => $items,
			'items_truncated' => $items_truncated,
		);
	}

	/**
	 * Invia il report di fine job (o pianifica il primo retry) e aggiorna lo
	 * stato 'webhook' del documento. Chiamata da WPHC_Bulk::finish() SOLO dopo
	 * che il job e' gia' stato finalizzato/persistito, cosi' un ricevente lento
	 * non tiene mai occupato il lock di drain per la durata della POST.
	 *
	 * @param array $job Documento del job, passato per riferimento.
	 */
	private static function send_webhook_for_job( array &$job ) {
		++$job['webhook']['attempts'];
		$job['webhook']['sending_ts'] = time();

		$payload = self::build_webhook_payload( $job );
		$result  = WPHC_Webhook::dispatch_payload( 'wphc.bulk_update.completed', $payload, $job['job_id'], $job['webhook']['attempts'] );

		$job['webhook']['code']       = $result['code'];
		$job['webhook']['last_error'] = $result['ok'] ? null : $result['error'];

		if ( $result['ok'] ) {
			$job['webhook']['sent']    = true;
			$job['webhook']['sent_ts'] = time();
			delete_option( WPHC_Options::OPT_WEBHOOK_PENDING );
			return;
		}

		if ( $result['retryable'] && $job['webhook']['attempts'] < WP_HEALTH_CHECK_WEBHOOK_MAX_ATTEMPTS ) {
			update_option(
				WPHC_Options::OPT_WEBHOOK_PENDING,
				array(
					'job_id'   => $job['job_id'],
					'event'    => 'wphc.bulk_update.completed',
					'body'     => $payload,
					'attempts' => $job['webhook']['attempts'],
				),
				false
			);
			wp_schedule_single_event( time() + WPHC_Webhook::backoff_for_attempt( $job['webhook']['attempts'] ), WPHC_Options::CRON_WEBHOOK_RETRY );
		}
	}
}
