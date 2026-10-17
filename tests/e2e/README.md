# Test e2e di wp-health-check: contratto con l'hub e upgrade

Questi test rispondono a due domande, con un sito WordPress vero che esegue il plugin:

1. **Contratto**: l'hub (codice Python invariato) enrolla, ruota, interroga, aggiorna e revoca il sito
   senza modifiche? (`hub_contract.py`)
2. **Upgrade**: un sito gia' enrollato con la 1.38.1 continua a funzionare dopo l'aggiornamento al
   file candidato (oggi una copia con versione alzata, dopo il refactoring la build vera)?
   (`upgrade_test.py`)

In piu', `wpcli_test.py` prova i comandi `wp health-check ...` con la WP-CLI vera, cosa che in
PHPUnit non si puo' fare.

## Come funziona

```
tests/e2e/
  e2e_common.py        libreria: percorsi, firme di test, "hub in miniatura", client di supporto
  hub_contract.py      enroll v1 -> health -> rotate -> grazia -> health v2 -> detail -> push -> revoke
  upgrade_test.py      7 scenari, ciascuno eseguito "senza upgrade" e "con upgrade" e confrontato
  wpcli_test.py        WP-CLI vera nel container
  run_all.py           lancia i tre in sequenza
  support/             mu-plugin di SOLO test caricato nell'ambiente e2e
  fixtures/            wpcli-help-health-check.txt (confronto dell'help di WP-CLI)
  .work/               (ignorata da git) copia di lavoro del plugin montata nel container
../../.wp-env.e2e.json configurazione dell'ambiente wp-env e2e
```

- **L'hub e' il codice vendorizzato** `../hub-cli/_vendor/services/` (`enroll`, `fleet/proxy`,
  `fleet/secrets`, `crypto`), importato dal suo percorso e mai copiato. Gira senza modifiche:
  `enroll_site`, `rotate_site_secret`, `revoke_site_secret`, `_site_request` (con firma HMAC v2 e
  ripiego sul segreto precedente), `fetch_site_health/ping/detail`. Sono sostituiti solo: il
  database Supabase (un falso in memoria, `FakeSupabase`), le due letture di anagrafica
  (`get_fleet_site`, `get_fleet_site_by_url`) e il trasporto, che porta `https://localhost:8890` sulla
  porta HTTP del container. Segreti e chiavi sono di test (k1/k2 derivate da `bytes(range(32))` e
  `bytes(range(32, 64))`, come in `tests/bootstrap.php`); le chiavi di `.wp-env.e2e.json` sono
  verificate a ogni avvio.
- La copia vendorizzata e' piu' vecchia del codice dell'hub (non conosce il push): il pacchetto push
  (`contents_gz_b64`, `sha256`, `sig`, `force`) e' costruito come in
  `hub/services/fleet/agent_release.py:build_push_package`, e la firma della release replica
  `hub/scripts/sign_release.py` (a ogni esecuzione e' confrontata con lo script originale, importato
  dal percorso). Le funzioni pure di firma sono controllate anche contro i vettori di
  `tests/fixtures/contract-vectors.json` (generati dal codice dell'hub).
- **Il plugin sotto test e' una COPIA.** L'ambiente e2e monta la cartella `tests/e2e/.work/mu-plugins/`
  come `wp-content/mu-plugins`: il file reale `mu-plugins/wp-health-check.php` non e' mai montato ne'
  scritto (il plugin scrive su `__FILE__` quando riceve un push valido, quindi il push positivo ha
  senso solo sulla copia). La libreria rifiuta di installare file dentro la cartella reale e, a ogni
  scenario, controlla che accanto al file reale non siano comparsi `.bak` o `.tmp`.
- **La baseline e' la 1.38.1 vera, letta da git** (`git show 1d6725b:mu-plugins/wp-health-check.php`,
  override con `WPHC_BASELINE_REF`), non dal file di lavoro: cosi' non dipende da modifiche
  temporanee o da fine riga locali.
- Il mu-plugin di supporto (`support/wphc-e2e-support.php`) forza HTTPS (`$_SERVER['HTTPS']`), espone il
  namespace `wphc-e2e/v1` (reset, stato, option, cron, throttle, esito del webhook; protetto da una
  chiave fissa, solo locale) e intercetta le POST verso `93.184.216.34` (il finto ricevente del
  webhook, un IP pubblico letterale perche' un nome non risolvibile e' scartato dal validatore URL di
  WordPress). `DISABLE_WP_CRON` e' attivo: i cron si fanno girare esplicitamente, in modo
  deterministico.

## Avviare l'ambiente

Requisiti: Docker, Node, Python 3.10+ con `pynacl` e `requests` (`pip install pynacl requests`).
Gli script girano con `python -I`; poiche' la modalita' isolata esclude il site-packages
dell'utente, dove di solito stanno le due librerie, la libreria le riaggiunge in coda a `sys.path`.

wp-env su Windows con Node 26: vedi `tests/README.md` (installare `@wordpress/env` con
`--ignore-scripts`; qui si e' usato `C:/tmp/wpenv-cli-e2e`).

```bash
# dalla radice di wp-health-check
mkdir -p tests/e2e/.work/mu-plugins
cp mu-plugins/wp-health-check.php tests/e2e/.work/mu-plugins/        # copia iniziale (gli script la riscrivono)
node <percorso>/@wordpress/env/bin/wp-env start --config=.wp-env.e2e.json
```

L'ambiente e2e e' **separato** da quello dei test unitari: file di configurazione
`.wp-env.e2e.json` (nome del progetto `wp-env-wp-health-check-e2e-<hash>`, container e volumi propri),
sito su `http://localhost:8890`, nessun ambiente `tests` (`"testsEnvironment": false`). Il primo avvio
scarica WordPress e costruisce le immagini (1-2 minuti); dopo una modifica di `.wp-env.e2e.json`
(costanti nel `wp-config.php`) serve `start --config=.wp-env.e2e.json --update`.

## Lanciare i test

```bash
python -I tests/e2e/run_all.py            # tutto
python -I tests/e2e/hub_contract.py       # contratto
python -I tests/e2e/upgrade_test.py       # upgrade; --list per gli scenari, --only <nome>, --verbose
python -I tests/e2e/wpcli_test.py         # WP-CLI vera; --update-fixture riscrive il file di confronto dell'help
```

Gli script si preparano da soli: installano il mu-plugin di supporto e la baseline nella copia,
azzerano lo stato del sito e, alla fine, lo ripuliscono e rimettono la baseline. Non servono altri
passi. Codice d'uscita 0 solo se tutti i controlli passano.

### Provare la build del refactoring

```bash
WPHC_CANDIDATE_FILE=dist/wp-health-check.php python -I tests/e2e/upgrade_test.py
```

`WPHC_CANDIDATE_FILE` indica il file da installare al posto della copia con versione alzata. Deve
dichiarare la stessa versione nell'header `Version:` e nella costante `WP_HEALTH_CHECK_VERSION`. Se
la versione e' piu' alta di quella della baseline il push e' normale; se e' **uguale** (la build
puo' mantenere 1.38.1) il push usa `force`, la reinstallazione di riparazione che il plugin accetta
solo sulla stessa versione; se e' piu' bassa si ferma (il plugin rifiuta i downgrade).
Il file viene firmato con la k2 di test (`.work/candidates/upgrade/` contiene `.php`, `.sha256` e `.sig`
generati come farebbe `hub/scripts/sign_release.py`).

## Gli scenari di upgrade

Ogni scenario costruisce uno stato realistico sulla 1.38.1 e lo esegue due volte con le stesse
operazioni: **controllo** (sempre 1.38.1) e **upgrade** (fase 1 su 1.38.1, push, fase 2 sul
candidato). Le trascrizioni normalizzate (esiti HTTP, codici d'errore, forma delle risposte, option,
cron, log, chiamate al webhook) devono coincidere; in piu' ogni scenario controlla esiti attesi in
assoluto.

| Scenario | Stato di partenza | Cosa deve valere dopo l'upgrade |
|---|---|---|
| `fresh` | sito mai enrollato | l'intero ciclo enroll v1, health, rotate, health firmato, ping funziona |
| `v1` | enroll v1, token HMAC | Bearer valido 200, errato 401, dual-stack con firma 200, firma errata 401 |
| `v2-grace` | ruotato, segreto precedente in grazia | il precedente (con la sua firma) e' ancora 200; decade al primo uso del nuovo; log `grace_window_hit` |
| `v2-grace-scaduta` | ruotato 20 minuti fa | il precedente e' 401 prima e dopo; il nuovo funziona |
| `revocato` | ruotato e revocato | 403 `wphc_revoked` su ogni rotta e con ogni segreto, anche con Bearer errato; la revoca non conta nel throttle; l'hub non contatta il sito |
| `bulk-in-corso` | job bulk di 7 plugin, 5 trattati | il cron riprende il job, nessun elemento resta pendente, il webhook di fine job e' firmato |
| `webhook-retry` | job finito, webhook fallito (500), retry in attesa | il retry pianificato parte con lo stesso corpo, nuova firma e tentativo 2; pendente cancellato |

Come arriva il candidato sul sito dipende dallo scenario: push firmato nel body di `POST /update`
(default, con l'hub vero), push firmato **con le credenziali precedenti** (`v2-grace`: il push col
segreto nuovo chiuderebbe la grazia), sostituzione esterna del file (`revocato`: un sito revocato
rifiuta anche `/update`, quindi l'upgrade e' un deploy manuale o dell'installer).

## Comportamenti scoperti durante la scrittura (da preservare o da cambiare con decisione esplicita)

- **Il throttle conta le richieste valide.** Su richieste HTTP reali la callback di permesso gira due
  volte (la seconda dentro `rest_send_allow_header()` del core, che ne scarta l'esito) e la seconda
  vede il nonce gia' usato: ogni richiesta firmata (protocollo 2) VALIDA aggiunge 1 al contatore dei
  fallimenti e ogni richiesta con Bearer errato ne aggiunge 2. Con la soglia di 10 per IP in 5 minuti
  (la finestra si rinnova a ogni incremento), una decina di richieste valide basta a ricevere 429.
  In-process (PHPUnit) non si vede. I test lo fissano (`hub_contract.py`) e azzerano il contatore
  tra una sequenza e l'altra (`Support.clear_throttle`).
- Dopo un aggiornamento `/health` risponde da cache (60 s) con la vecchia `agent_version`;
  `?fresh=1` e `/ping` riportano subito la nuova.
- Il push firmato col segreto nuovo chiude la grazia del precedente, come qualunque primo uso.

## Pulizia e rapporti con gli altri servizi docker

```bash
# fermare l'ambiente e2e (i dati restano nei volumi)
node <percorso>/@wordpress/env/bin/wp-env stop  --config=.wp-env.e2e.json
# distruggerlo del tutto (container, volumi, cartella di lavoro di wp-env)
node <percorso>/@wordpress/env/bin/wp-env destroy --config=.wp-env.e2e.json
rm -rf tests/e2e/.work tests/e2e/__pycache__
```

Cosa NON toccare e cosa e' sicuro:

- L'ambiente e2e vive nei container `wp-env-wp-health-check-e2e-<hash>-*` (porta 8890, MySQL su porta
  casuale). Si gestisce SOLO con `--config=.wp-env.e2e.json`.
- Il comando senza `--config` e `wp-env destroy` senza `--config` riguardano l'ambiente dei test
  PHPUnit (`wp-env-wp-health-check-232317df-*`, porte 8888/8889): non vanno usati per ripulire l'e2e.
- Gli altri servizi docker del monorepo (`hub-redis`, `hub-tests-*`, immagini `hub:*`, `mavida-edge-*`)
  non sono usati ne' richiesti: l'hub qui e' solo codice Python importato, senza Redis ne' database.
  Non si fanno `docker compose down`, `docker system prune` o `docker volume prune`: potrebbero
  cancellare i loro volumi.
- La rete: l'e2e e' in locale, ma la prima `/health` genera davvero l'anteprima del sito
  (thum.io) e risolve l'IP pubblico (ipify) se il container ha accesso a internet; gli script
  mascherano quei valori nei confronti.

## Cosa non e' coperto

- Il canale "pull" del self-update (il sito scarica la release da hub/GitHub): richiederebbe un finto
  server di release raggiungibile dal container; il push usa la stessa pipeline di installazione
  (`wphc_install_release`) con gli stessi controlli di integrita' e firma.
- Enroll v2 dall'hub vendorizzato (`_persist_v2_enroll_secret` importa `app.crypto`, assente in
  hub-cli): il rinnovo di un sito gia' al protocollo 2 e' coperto da `tests/test-enroll.php`.
- Aggiornamenti reali di plugin/temi/core in un job bulk (servirebbe wordpress.org): gli elementi del
  job si risolvono in `up_to_date`/`not_found`, che esercitano comunque stato, cron, contatori,
  finalizzazione e webhook.
- Transizioni di versione del database (schema della tabella di log): il candidato usa lo stesso
  schema della baseline.
