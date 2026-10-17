# Test e2e di wp-health-check: contratto con l'hub e upgrade

Questi test rispondono a tre domande, con un sito WordPress vero che esegue il plugin:

1. **Contratto**: l'hub (codice Python invariato) enrolla, ruota, interroga, aggiorna e revoca il sito
   senza modifiche? (`hub_contract.py`)
2. **Upgrade**: un sito gia' enrollato con la 1.38.1 continua a funzionare dopo l'aggiornamento al
   file candidato (senza `WPHC_CANDIDATE_FILE` una copia della 1.38.1 con versione alzata, con la
   variabile la build del refactoring)? (`upgrade_test.py`, scenari a due fasi)
3. **Self-update del candidato**: il candidato, una volta installato, sa aggiornare se stesso
   (backup, rename atomico, sanity check, invalidazione di opcache) e rifiuta i downgrade, e quando
   il backup non e' possibile lascia il file intatto? (`upgrade_test.py`, blocchi `candidate-self-update`
   e `backup-failed`: negli scenari a due fasi il push positivo lo scrive il codice della 1.38.1, non
   quello del candidato, quindi quei scenari NON provano questa parte)

In piu', `wpcli_test.py` prova i comandi `wp health-check ...` con la WP-CLI vera, cosa che in
PHPUnit non si puo' fare.

## Come funziona

```
tests/e2e/
  e2e_common.py        libreria: percorsi, firme di test, "hub in miniatura", client di supporto
  hub_contract.py      enroll v1 -> health -> rotate -> grazia -> health v2 -> detail -> push -> revoke
  upgrade_test.py      7 scenari, ciascuno eseguito "senza upgrade" e "con upgrade" e confrontato,
                       piu' i blocchi candidate-self-update e backup-failed
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
- **Il plugin sotto test** (`e2e_common.plugin_under_test()`) e' il file che gli script installano:
  `WPHC_CANDIDATE_FILE` se impostata, altrimenti la baseline. `hub_contract.py` e `wpcli_test.py` lo
  installano e lo provano (versione attesa ricavata dal file installato; il candidato del push di
  `hub_contract` e' lo stesso file con la versione successiva X.Y.Z+1, quindi il self-update positivo e'
  eseguito dal plugin sotto test). `upgrade_test.py` lo usa come candidato dell'upgrade dalla 1.38.1 e come
  plugin installato nei due blocchi del punto 3. Ogni script stampa all'avvio
  `[script] plugin sotto test: <percorso> versione <v> sha256 <hash>`. Il file deve dichiarare la stessa
  versione nell'header `Version:` e nella costante `define( 'WP_HEALTH_CHECK_VERSION', 'x.y.z' )`: se manca una
  delle due, o non coincidono, lo script si ferma con un messaggio che lo dice (il self-update del plugin
  verifica `Version: <tag>` nel file e `make_candidate` riscrive entrambe le dichiarazioni).
- **Il codice caricato e' verificato, non presunto.** Con opcache attiva (`revalidate_freq=2`) dopo la
  sostituzione ESTERNA del file il sito potrebbe eseguire per qualche secondo il codice vecchio. Dopo ogni
  installazione (`e2e_common.install_and_load`) la libreria invalida opcache (rotta `POST /opcache` del
  supporto) e attende che `/state` riporti `loaded_version` uguale alla versione installata, `loaded_sha256`
  (sha256 del file di `mu-plugins` che il PHP ha incluso) uguale a quello installato, i simboli del plugin
  dichiarati da quel file (`ReflectionFunction` di `wphc_normalize_url`, o `ReflectionClass` per le build a
  classi, che non hanno funzioni globali) e lo script in opcache non piu' vecchio del file. Scaduto il tempo
  lo script FALLISCE con il motivo (nessun ciclo che scade in silenzio).
- Il mu-plugin di supporto (`support/wphc-e2e-support.php`) forza HTTPS (`$_SERVER['HTTPS']`) ed espone il
  namespace `wphc-e2e/v1` (reset, stato, option, cron, throttle, opcache, esito del webhook). Il namespace
  accetta solo chiamanti con `REMOTE_ADDR` di loopback o di una rete privata (127.0.0.0/8, ::1, 10.0.0.0/8,
  172.16.0.0/12, 192.168.0.0/16: il gateway del bridge docker, osservato `172.x.0.1`, cade in 172.16.0.0/12)
  E la chiave fissa nell'header `X-E2E-Key`: un chiamante con indirizzo pubblico e' respinto anche con la chiave giusta.
- **Rete isolata (deny-by-default).** `pre_http_request` risponde a tutto, nessuna richiesta esce dal
  container: le POST verso `93.184.216.34` (il finto ricevente del webhook, un IP pubblico letterale perche' un
  nome non risolvibile e' scartato dal validatore URL di WordPress) sono registrate e ricevono lo status scelto;
  `image.thum.io` risponde con un PNG 1x1 fisso (anteprima), `api.ipify.org` con l'IP fisso `198.51.100.7`
  (non in 192.0.2.0/24, che il core blocca); tutto il resto (wordpress.org, GitHub, ...) fallisce con un
  `WP_Error` e finisce nel campo `http_denied` di `/state`. `WP_HEALTH_CHECK_ALERT_EMAIL` e' sovrascritta
  con `alert@e2e.invalid` in `.wp-env.e2e.json`. `DISABLE_WP_CRON` e' attivo: i cron si fanno girare
  esplicitamente, in modo deterministico, e durante l'evento `wp_doing_cron()` e' VERO (filtro
  temporaneo nella rotta `/cron/run`), come in un WP-Cron reale: senza, `wphc_record_access` e
  `wphc_detect_update_source` si comporterebbero come in una richiesta REST. Lo scenario `bulk-in-corso`
  lo prova con un valore sentinella nell'ultimo accesso, che il tick non deve toccare.

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

Gli script si preparano da soli: installano il mu-plugin di supporto e il plugin sotto test nella copia
(invalidando opcache e attendendo che il PHP lo esegua), azzerano lo stato del sito e, alla fine, lo
ripuliscono e rimettono il plugin sotto test. Non servono altri passi. Codice d'uscita 0 solo se tutti i
controlli passano. `upgrade_test.py` si ferma subito, prima di toccare il sito, se il body del push del
candidato supera 131072 byte (stampa la misura e la differenza rispetto alla baseline; il budget del
progetto di 110.000 caratteri gzip+base64 e' stampato solo come informazione: la baseline lo supera gia').

### Provare la build del refactoring

```bash
WPHC_CANDIDATE_FILE=dist/wp-health-check.php python -I tests/e2e/run_all.py
```

`WPHC_CANDIDATE_FILE` vale per TUTTI e tre gli script (vedi "Il plugin sotto test" sopra): `hub_contract.py`
e `wpcli_test.py` installano quel file e lo provano al posto della 1.38.1 (i docblock letti da WP-CLI via
reflection, l'help, i comandi); `upgrade_test.py` lo usa come candidato dell'upgrade dalla 1.38.1 e come plugin
dei blocchi di self-update. Nel confronto di upgrade, se la versione del candidato e' piu' alta di quella della
baseline il push e' normale; se e' **uguale** (la build puo' mantenere 1.38.1) il push usa `force`, la
reinstallazione di riparazione che il plugin accetta solo sulla stessa versione; se e' piu' bassa si ferma (il
plugin rifiuta i downgrade). Il file viene firmato con la k2 di test (`.work/candidates/upgrade/` contiene
`.php`, `.sha256` e `.sig` generati come farebbe `hub/scripts/sign_release.py`).

Senza la variabile il plugin sotto test e' la baseline: `hub_contract` e `wpcli_test` provano la 1.38.1 (il
candidato del suo push e' la 1.38.2), `upgrade_test` usa come candidato una copia con `Version 1.99.0` e i due
blocchi di self-update partono dalla baseline stessa. Se il file indicato e' identico alla baseline gli
script girano ma NON dimostrano nulla sulla build: serve la build vera.

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

Cosa si confronta. Le osservazioni di `/health` e `/ping` dell'hub registrano la risposta INTERA (tutto il
`summary`, `last_access`, l'errore), non solo le chiavi. Sono tolti o mascherati SOLO: `response_time_ms`
(durata); il numero nel nome del file dell'anteprima (`wp-health-check-site-thumb-103.png`: il suffisso cresce a
ogni esecuzione) e l'id dell'allegato (`wp_health_check_thumb_id`); i timestamp (`updates_checked_at`,
`last_access.at`, `last_access.enrolled_at`, `wp_health_check_*_at`), mascherati da `normalize()` che ne
conserva la presenza, e gli identificatori esadecimali lunghi; la versione dell'agent (`<AGENT_VERSION>`). Con
la rete isolata IP pubblico, errore dell'anteprima e transient sono deterministici e si confrontano come sono
(le vecchie maschere su `wphc_public_ip`, `thumb_at`, `thumb_error` sono state tolte). `server_ip` e'
l'indirizzo del container: cambia solo se l'ambiente viene ricreato, uguale fra le due esecuzioni. La stabilita'
del confronto e' dimostrata dal fatto stesso che controllo (1.38.1) e upgrade (copia della 1.38.1 con versione
alzata) coincidono a ogni esecuzione. Ogni trascrizione include anche la colonna `autoload` GREZZA di tutte le
option `wp_health_check_*` (`on`, `off`, `auto`): un upgrade che la cambiasse altererebbe il carico di ogni
richiesta del sito.

Nei controlli a due fasi, per gli scenari con push il controllo esegue lo stesso `POST /update` autenticato
con un pacchetto della versione corrente (risposta `up_to_date`, nulla scritto): senza, il controllo non
avrebbe l'"ultimo accesso" che l'upgrade ha dal push e `/health` riporta quel campo in `last_access`
(scoperto proprio perche' ora si confronta il summary intero).

Come arriva il candidato sul sito dipende dallo scenario: push firmato nel body di `POST /update`
(default, con l'hub vero), push firmato **con le credenziali precedenti** (`v2-grace`: il push col
segreto nuovo chiuderebbe la grazia), sostituzione esterna del file (`revocato`: un sito revocato
rifiuta anche `/update`, quindi l'upgrade e' un deploy manuale o dell'installer).

### I blocchi di self-update

| Blocco | Cosa dimostra |
|---|---|
| `candidate-self-update` | il candidato INSTALLATO (poi enrollato e ruotato) riceve un push firmato k2 con la versione successiva `X.Y.Z+1` e lo installa da solo: `updated`, `from`, `to`, `signature=verified`, `mode=push`, file uguale al pacchetto byte per byte, nessun `.bak` ne' `.tmp`, `loaded_version` aggiornata (opcache invalidata), `/ping` con la nuova `agent_version` idonea al push secondo l'hub (`_agent_supports_push` / `_PUSH_MIN_AGENT` di `hub/services/fleet/proxy.py`, estratti con `ast`); stessa versione senza `force` = `up_to_date`, con `force` = `updated=true`; downgrade (baseline e candidato originale, con `force`) = `up_to_date` |
| `backup-failed` | con una DIRECTORY `wp-health-check.php.bak` nella copia montata il push firmato valido risponde HTTP 500 `wphc_update_backup_failed`, il file resta invariato byte per byte, nessun `.tmp`; la directory si toglie sempre (`try/finally`) |

Prima di tutto, nel blocco `candidate-self-update`, la baseline 1.38.1 viene spinta con `force` sul candidato: se
il candidato e' piu' nuovo e' un downgrade (`up_to_date`, file invariato); se ha la stessa versione e' una
reinstallazione (`updated=true`, e se i byte differiscono si riporta il candidato prima di proseguire); se e'
piu' vecchio il passo si salta. Se candidato e baseline sono lo STESSO file (default, senza
`WPHC_CANDIDATE_FILE`) il passo e' la reinstallazione forzata alla stessa versione e i passi successivi valgono
ugualmente, perche' il bump a `X.Y.Z+1` rende "piu' vecchi" sia la baseline sia il candidato originale: le
asserzioni sul downgrade restano significative e non dipendono dal confronto fra i due file.

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
- La rete: nessuna richiesta esce dal container (deny-by-default nel mu-plugin di supporto, vedi sopra):
  anteprima e IP pubblico hanno risposte fisse, le altre richieste sono negate e registrate in
  `http_denied` di `/state`.

## Cosa non e' coperto

- Il canale "pull" del self-update (il sito scarica la release da hub/GitHub): richiederebbe un finto
  server di release raggiungibile dal container; il push usa la stessa pipeline di installazione
  (`wphc_install_release`) con gli stessi controlli di integrita' e firma.
- Enroll v2 dall'hub vendorizzato (`_persist_v2_enroll_secret` importa `app.crypto`, assente in
  hub-cli): il rinnovo di un sito gia' al protocollo 2 e' coperto da `tests/test-enroll.php`.
- Aggiornamenti reali di plugin/temi/core in un job bulk (servirebbe wordpress.org): gli elementi del
  job si risolvono in `up_to_date`/`not_found`, che esercitano comunque stato, cron, contatori,
  finalizzazione e webhook.

## Cosa il test NON dimostra

Anche con tutti i controlli verdi restano fuori:

- **WP-Cron reale.** Con `DISABLE_WP_CRON` gli eventi si fanno girare dalla rotta di supporto (in contesto
  cron simulato con `wp_doing_cron()` vero), non dal loopback di WP-Cron ne' da `wp cron event run`.
  Non si prova la pianificazione effettiva dopo l'upgrade, l'orario in cui scattano gli eventi, ne'
  il comportamento con un cron di sistema.
- **L'ordine degli hook su `init` dopo l'upgrade** (schema del log, versione del core, riarmo del bulk,
  autologin) e in generale il registro degli hook: lo fissa la suite PHPUnit (`tests/test-hook-registry.php`),
  non gli e2e.
- **La migrazione di `DB_VERSION`**: il candidato usa lo stesso schema della baseline (la option
  `wp_health_check_db_version` e' preservata dal reset di supporto proprio per non ricreare la tabella); una
  build che cambia schema o versione del database non e' provata da qui.
- **Un webhook pendente di un sito revocato attraverso l'upgrade.** Lo scenario `webhook-retry` copre il
  retry di un sito attivo; la combinazione "retry pendente + revoca + upgrade" no.
- **I rami `wphc_update_write_failed` e `wphc_update_sanity_failed` del self-update**: richiedono un
  `rename()` o una rilettura che falliscono su un filesystem sano e non si riproducono senza modificare il
  plugin. Il ramo `wphc_update_backup_failed` e' invece coperto dal blocco `backup-failed`. Un ramo rotto
  nel candidato si vede solo se il test di quel ramo fallisce (lo si e' provato per `write_failed` con una
  mutazione del candidato in cui il `rename()` fallisce: il blocco `candidate-self-update` diventa rosso).
- **Il canale pull** e gli aggiornamenti veri di plugin/temi/core (sopra).
- **PHP diverso da 8.1** e un sito dietro cache o reverse proxy reale: l'ambiente e2e e' PHP 8.1, Apache,
  WordPress 7.1.1 e HTTPS simulato.
- **Transizioni di versione del database** e dati reali di un sito di produzione.
