# Suite di caratterizzazione di wp-health-check

Test PHPUnit che fissano il comportamento ESTERNO del mu-plugin (versione 1.38.1) e
dimostrano la retrocompatibilita' con l'hub durante il refactoring in classi.
Girano in WordPress reale (test library di WordPress) dentro i container di `wp-env`; in piu'
c'e' una suite e2e (`e2e/`) con un sito WordPress vero pilotato dal codice Python dell'hub.

## Cosa c'e'

| File | Contenuto |
|---|---|
| `bootstrap.php` | carica Composer, la test library e il plugin SOTTO TEST (una copia, vedi "Guardia anti-sovrascrittura"); guardie su costanti, provenienza dei simboli e integrita' dei file |
| `refactor-map.php` | mappa UNICA "funzione 1.38.1 -> metodo del refactoring" (vedi "Convenzione cb() / call() / call_args() e mappa unica") |
| `class-wphc-test-case.php` | classe base: buste firmate, firma HMAC, `request()`, reset dello stato, `cb()`/`call()`, controllo delle chiamate HTTP non mockate |
| `class-wphc-golden.php` | confronto con gli snapshot in `fixtures/golden/` |
| `class-wphc-helpers-read.php` | helper dei test di lettura (GET/POST con Bearer, HTTP mockato, golden normalizzati, spia degli header CORS) |
| `class-wphc-helpers-update.php` | helper dei test di update (pacchetti di release SEMPRE difettosi, tabella di log, bulk, cron lanciato con `do_action`) |
| `class-wphc-helpers-admin.php` | helper dei test di admin e WP-CLI (tab Site Health, handler `admin-post`, `admin-ajax`) |
| `trait-wphc-plugin-file-guard.php` | guardia PER-TEST sul file del plugin sotto test, usata dalle classi base di update e di admin (vedi "Guardia anti-sovrascrittura") |
| `test-contract-vectors.php` | il plugin verifica/produce gli stessi byte dell'hub (vettori); URL del sito di test fissato in modo indipendente |
| `test-enroll.php` | `/enroll` v1 e v2, `/rotate`, `/revoke`, stato dopo la revoca, reset dell'enrollment, candidati URL |
| `test-auth.php` | `wphc_require_token`: ordine dei controlli, throttle, Bearer, HMAC v2, dual-stack, grazia |
| `test-read-routes.php`, `test-misc-read.php`, `test-cors.php`, `test-thumbnail.php` | rotte di lettura (`/health`, `/ping`, `/detail/*`), `/debug`, autologin, ultimo login, CORS, miniatura |
| `test-self-update.php`, `test-item-update.php`, `test-update-log.php`, `test-bulk.php`, `test-webhook.php` | self-update (solo i rami che NON riscrivono il file), update di plugin/temi/core, tabella di log, job bulk, webhook in uscita |
| `test-admin.php`, `test-cli.php` | tab Site Health (HTML, notice, handler, AJAX) e comandi `wp health-check ...` (con WP-CLI simulata in-process) |
| `test-refactor-map.php` | la mappa unica e `cb()` / `call()` / `call_args()`: 157 funzioni, destinazioni uniche, la mappa coincide con le funzioni dichiarate dal plugin (o, a classi, i metodi esistono e sono statici), ramo "metodo privato" e riferimenti provati con una classe di prova |
| `test-hook-registry.php` | registro di TUTTI i callback del plugin agganciati agli hook (priorita', `accepted_args`, ordine, nomi della 1.38.1), delle 22 registrazioni REST (metodi, callback, `permission_callback`), ordine dei quattro callback su `init`, hook dell'upgrader e i 20 hook di flush su `auto_update_*`; golden `fixtures/golden/hooks/registry.json` SENZA maschere |
| `test-throttle-double-dispatch.php` | pin del finding sul throttle (PRESERVATO: bug noto): il contatore dei fallimenti con la fase `rest_post_dispatch` che in-process manca |
| `test-core-auto-update-state.php` | valori di `wphc_core_auto_update_state()` (ha una `static $cache`): un processo PHP per test, vedi "Test con processo separato" |
| `test-plugin-file-constant.php` | `WP_HEALTH_CHECK_FILE` (decisione D-1, dal lotto 3): definita, coincide con il file sotto test, default `__FILE__` (il file che dichiara `WPHC_Self_Updater`), e `install_release()` la usa al posto di `__FILE__`; vale solo per il plugin a classi |
| `phpunit.admin.xml` | esecuzione isolata di `test-admin.php` e `test-cli.php`: `vendor/bin/phpunit -c tests/phpunit.admin.xml` |
| `tools/gen_contract_vectors.py` | genera `fixtures/contract-vectors.json` dal codice dell'hub |
| `tools/check-locks.php` | confronta le versioni di phpunit, polyfills e test library nei due `composer.lock` (`composer run test:check-locks`) |
| `fixtures/contract-vectors.json` | vettori di contratto (GENERATO, versionato) |
| `fixtures/golden/**.json` | snapshot di risposta e option scritte (GENERATI, versionati) |
| `php74/` | composer dedicato alla matrice PHP 7.4 |
| `e2e/` | test end to end con l'hub vero e un sito WordPress vero: vedi `e2e/README.md` (`python -I tests/e2e/run_all.py`) |

Le chiavi Ed25519 dei test derivano da seed fissi (k1 = `bytes(range(32))`, k2 =
`bytes(range(32, 64))`). Le chiavi di produzione non sono mai usate ne' scritte qui:
il bootstrap interrompe tutto se le costanti `WP_HEALTH_CHECK_CENTRAL_PUBKEY[_K2]` non
derivano da quei seed, se `WP_HEALTH_CHECK_RELEASE_URL` non e' vuota o se webhook ed email di
alert non sono su dominio `.invalid`.

## Guardia anti-sovrascrittura del plugin

Il self-update del plugin (`wphc_install_release`) riscrive `__FILE__` (nel plugin a classi `WP_HEALTH_CHECK_FILE`, che nel file distribuito vale lo stesso `__FILE__`) e crea un `.bak` accanto: con le
chiavi k2 di test un ramo positivo riscriverebbe il file vero. Per questo:

- **Il plugin sotto test e' una COPIA.** Per default il bootstrap copia `mu-plugins/wp-health-check.php`
  in `sys_get_temp_dir()/wphc-under-test/wp-health-check.php` (nel container: `/tmp/wphc-under-test/`) a
  ogni avvio, ripulendo prima eventuali `.bak` e `.wphc-new-*.tmp` di run interrotti, e carica quella.
  `WPHC_PLUGIN_FILE` indica un altro file (la build del refactoring); il file del repo in `mu-plugins/`
  e' sempre rifiutato come valore di `WPHC_PLUGIN_FILE`. Un `.bak` preesistente accanto a un file esplicito
  fa fallire l'avvio.
- **La copia NON usa `copy()`.** In Docker, con il repo montato come bind mount da Windows, la `copy()` di
  PHP 8.1 puo' scrivere una copia con una coda di byte NUL (dopo una rebuild del file: la suite cadrebbe con
  un ParseError che non dipende dal plugin). Il bootstrap legge il sorgente con `file_get_contents()`, lo
  scrive con `file_put_contents()`, RILEGGE la copia e confronta lo SHA-256 con quello del sorgente; se
  differisce riprova fino a 3 volte e poi si ferma con un messaggio chiaro. Il runner di default
  (`C:/tmp/wphc-run-phpunit.sh 81`) e' quindi affidabile anche subito dopo `composer run build:dev`, senza
  dover usare `WPHC_PLUGIN_FILE` e un `cp` nel container.
- **Guardia a fine run** (`register_shutdown_function` nel bootstrap): ricalcola lo SHA-256 del file sotto
  test e del file del repo e controlla che non esistano `<file sotto test>.bak` e `.wphc-new-*.tmp` in
  `WPMU_PLUGIN_DIR`; in caso contrario scrive su STDERR e termina con `exit(1)`, anche se tutti i test erano
  verdi. Dimostrata con una sonda temporanea (modifica della copia: exit 1 e "plugin sotto test modificato";
  creazione del `.bak`: exit 1 e "esiste ...wp-health-check.php.bak").
- La guardia per-test e' il trait `WPHC_Plugin_File_Guard` (`trait-wphc-plugin-file-guard.php`), usato da
  `WPHC_Update_Test_Case` E da `WPHC_Admin_Test_Case` (prima solo dagli update: `test-admin.php` percorre il pull
  vero fino al download, che si ferma solo perche' il mock risponde 500). Confronta i byte del file sotto test
  prima e dopo ogni test, li RIPRISTINA e fa fallire il test indicando quale ha scritto; cancella solo i
  `<file sotto test>.bak` e i `.wphc-new-*.tmp` creati DAL test (quelli gia' presenti a inizio test e qualunque
  altro `*.bak` non si toccano). La guardia del bootstrap e' l'ultima rete per tutti gli altri file di test. Le due
  non si duplicano: una e' per-test con ripristino, l'altra e' globale a fine processo e se la prima ha gia'
  ripristinato non vede nulla.
- **Provenienza dei simboli**: dopo l'avvio di WordPress ogni funzione `wphc_*` (tranne `wphc_test_*`) e ogni
  classe `WPHC_*` non di test devono essere dichiarate sotto la cartella del file indicato, e il plugin del
  repo non deve essere in `get_included_files()` se il file indicato e' un altro. Il caricamento del plugin
  avviene con un error handler che interrompe (`exit(1)`) su warning e notice: un `define()` senza
  `! defined` in una build, che con le costanti di test gia' presenti darebbe un warning, non passa
  in silenzio.

## 1. Avviare wp-env

Requisiti: Docker, Node, Composer (host con PHP 8.1+).

```bash
composer install            # dipendenze di sviluppo (phpunit, polyfills, wp-phpunit, phpcs, phpstan)
npx @wordpress/env start    # prima volta: scarica e costruisce le immagini (diversi minuti)
```

Configurazione in `.wp-env.json`:

- ambiente `development`: il mu-plugin e' mappato in `wp-content/mu-plugins/`;
- ambiente `tests`: NON ha il mu-plugin in `mu-plugins/` (il bootstrap lo carica a mano: se
  fosse in entrambi i posti verrebbe dichiarato due volte); la radice del progetto e'
  mappata in `wp-content/wphc-dev`; le costanti di test (chiavi k1/k2, `RELEASE_URL` vuoto, webhook
  e email di alert su dominio `.invalid`, `WP_HTTP_BLOCK_EXTERNAL`) sono in `env.tests.config` e
  finiscono nel `wp-tests-config.php` della test library (dopo una modifica serve `wp-env start`
  di nuovo). Il bootstrap le definisce da se' se la config non le contiene. WordPress e' fissato alla 7.1.1.
- wp-env 11.x segnala come deprecate le opzioni `env` e `testsEnvironment`: oggi funzionano;
  se verranno rimosse, spostare l'ambiente di test in un file separato da passare con `--config`.

### Problema noto: `npx @wordpress/env` su Windows con Node 26

`npx @wordpress/env ...` esce con codice 1 senza stampare nulla: la dipendenza nativa
`fs-ext-extra-prebuilt` non ha un binario per Node 26 e cerca di compilarsi con Visual
Studio (assente). Soluzione: installare wp-env senza gli script di installazione.

```bash
mkdir ~/wpenv-cli && cd ~/wpenv-cli && npm init -y
npm install @wordpress/env@11.17.0 --ignore-scripts
# poi, dalla radice del progetto:
node ~/wpenv-cli/node_modules/@wordpress/env/bin/wp-env start
```

In Git Bash, gli argomenti che iniziano con `/` vengono "tradotti" da MSYS: usare
`MSYS_NO_PATHCONV=1` oppure PowerShell. Le variabili d'ambiente dell'host NON arrivano nel
container: vanno impostate dentro `bash -c '...'` (vedi sotto) o con `docker exec -e`.

## 2. Lanciare i test (PHP 8.1, default)

```bash
# Dalla radice del progetto. Comando esatto, equivalente a `composer run test`:
npx @wordpress/env run tests-cli --env-cwd=wp-content/wphc-dev vendor/bin/phpunit

# Un singolo file: un testsuite per file (nomi: admin, auth, bulk, cli, contract-vectors, core-auto-update,
# cors, enroll, hook-registry, item-update, misc-read, read-routes, refactor-map, self-update, throttle,
# thumbnail, update-log, webhook)
npx @wordpress/env run tests-cli --env-cwd=wp-content/wphc-dev vendor/bin/phpunit --testsuite enroll

# Un singolo test (o una classe) con --filter
npx @wordpress/env run tests-cli --env-cwd=wp-content/wphc-dev vendor/bin/phpunit --filter test_rotate_grace_window
```

`phpunit tests/test-enroll.php` NON funziona (il nome del file non coincide con quello della classe,
`WPHC_Test_Enroll`): si usa `--testsuite` oppure `--filter`. Senza opzioni gira la suite completa
(`defaultTestSuite`). `composer run test -- --filter ...` passa gli argomenti a PHPUnit.

Il report di PHPUnit va su stderr (`stderr="true"` in `phpunit.xml.dist`) e il bootstrap apre
un buffer di output: il plugin chiama `header()` direttamente e in CLI PHP altrimenti
darebbe "headers already sent". Conseguenza: gli header inviati con `header()` (Retry-After
del 429, header CORS) NON sono osservabili in-process; i test verificano status e codice
d'errore, non quegli header. Verificato nel container: dopo `header('X: 1')` in CLI
`headers_list()` restituisce un array vuoto (il SAPI CLI non conserva gli header).

`phpunit.xml.dist` converte in eccezioni errori, warning, notice E deprecazioni
(`convertDeprecationsToExceptions="true"`): una deprecazione del plugin (o di una nuova versione
di PHP) fa fallire il test invece di restare invisibile. Con `failOnRisky`, `failOnWarning` e `failOnSkipped`
(attivi anche in `tests/phpunit.admin.xml`, e supportati da PHPUnit 9.6 nei due vendor) un test rischioso, con
avviso o SALTATO rende rosso il run: un test saltato svuoterebbe il gate (il refactoring sembrerebbe verde senza
provare nulla). Le tre condizioni di ambiente che prima facevano `markTestSkipped` (plugin `hello.php`, tema
`twentytwentyfive`, tabella di log InnoDB) ora fanno `fail()` con il messaggio che spiega cosa manca.

### Lock di phpunit (ambiente condiviso)

L'ambiente wp-env e il suo database sono UNO: due `phpunit` in parallelo si calpestano (stesso
database, stessa cartella `/tmp/wphc-under-test`). Chi esegue la suite da piu' agenti o terminali
serializza i run con un lock a directory (atomico anche su Windows) attorno a ogni run:

```bash
until mkdir C:/tmp/wphc-phpunit.lock 2>/dev/null; do sleep 5; done
trap 'rmdir C:/tmp/wphc-phpunit.lock' EXIT
# ...qui il comando phpunit (8.1 o 7.4)...
```

Lo script `C:/tmp/wphc-run-phpunit.sh <81|74> [argomenti phpunit]` (fuori dal repo) fa esattamente questo
per il container `tests-cli`; `EXTRA_ENV="-e NOME=valore"` passa variabili d'ambiente al container
(es. `WPHC_GOLDEN_UPDATE=1`, `WPHC_PLUGIN_FILE=...`).

## phpcs e phpstan non coprono `tests/`

`phpcs.xml.dist` e `phpstan.neon.dist` analizzano solo `mu-plugins/` e `bin/` e `tests/` e'
lasciata fuori di proposito: con le regole del plugin (WordPress-Extra + WordPress-Docs +
PrefixAllGlobals) i file di test darebbero 18 errori e 48 avvisi (nomi file `test-*.php`,
prefissi globali, docblock), e phpstan richiederebbe gli stub della test library. Con
`composer run lint` il risultato per `mu-plugins/` e `bin/` non cambia.
Nota: `composer run analyse` con il `memory_limit` di default dell'host (128M) va in crash
anche prima di questa suite; funziona con `vendor/bin/phpstan analyse --memory-limit=4G`.

## 3. Matrice PHP 7.4

WordPress 7.1 richiede PHP 7.4 come minimo, quindi il core fissato in `.wp-env.json`
resta lo stesso. Il `composer.json` principale richiede PHP 8.1+ (gli strumenti di sviluppo),
percio' per 7.4 si usa un vendor separato con le sole dipendenze di test, risolto per PHP 7.4.33
(`tests/php74/`, solo file PHP: si installa dall'host).

```bash
composer install --working-dir=tests/php74
composer run test:check-locks    # phpunit, polyfills e test library devono avere la stessa versione nei due lock
```

### Metodo consigliato: container usa-e-getta (non tocca l'ambiente condiviso, che resta su 8.1)

Un container `wordpress:cli-php7.4` collegato alla rete di wp-env, con lo stesso WordPress di test, lo
stesso database e la radice del progetto montata; il vendor di test e' quello di `tests/php74/vendor`.
Il container sparisce a fine run (`--rm`), quindi `/tmp/wphc-under-test` riparte pulito. Script completo
(`C:/tmp/wphc-run-phpunit74.sh`, fuori dal repo; i due percorsi e il nome della rete dipendono dalla
macchina: `<hash>` e' quello della cartella `~/.wp-env/wp-env-wp-health-check-<hash>`):

```bash
#!/bin/bash
# PHP 7.4 senza toccare l'ambiente condiviso (che resta su 8.1): contenitore usa-e-getta con la stessa rete,
# lo stesso WordPress di test e lo stesso database; serializzato con il lock condiviso.
HOME_WP="C:\Users\<utente>\.wp-env\wp-env-wp-health-check-<hash>"
REPO="C:\Users\<utente>\Claude\projects\mavida-saas-ecosystem\wp-health-check"
until mkdir C:/tmp/wphc-phpunit.lock 2>/dev/null; do sleep 5; done
trap 'rmdir C:/tmp/wphc-phpunit.lock' EXIT
MSYS_NO_PATHCONV=1 docker run --rm --network wp-env-wp-health-check-<hash>_default \
  -v "$HOME_WP\tests-WordPress:/var/www/html" \
  -v "$HOME_WP\tests-WordPress-PHPUnit\tests\phpunit:/wordpress-phpunit" \
  -v "$REPO:/var/www/html/wp-content/wphc-dev" \
  -e WP_TESTS_DIR=/wordpress-phpunit -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=password \
  -e WORDPRESS_DB_NAME=tests-wordpress -e WORDPRESS_DB_HOST=tests-mysql \
  -e WPHC_VENDOR_DIR=/var/www/html/wp-content/wphc-dev/tests/php74/vendor \
  -w /var/www/html/wp-content/wphc-dev wordpress:cli-php7.4 \
  php -d memory_limit=512M tests/php74/vendor/bin/phpunit "$@"
```

Si lancia con `bash C:/tmp/wphc-run-phpunit74.sh` (suite completa) o con argomenti di PHPUnit
(`--testsuite enroll`). Verifica della versione: `docker run --rm wordpress:cli-php7.4 php -v`
(PHP 7.4.33). Un avviso `file_put_contents(.phpunit.result.cache): Permission denied` e' innocuo (l'utente del
container non puo' scrivere la cache dei risultati nella cartella montata).

### Metodo alternativo: ambiente wp-env su 7.4

```bash
cp .wp-env.override.example.json .wp-env.override.json   # file ignorato da git
npx @wordpress/env start --update                         # ricostruisce le immagini con PHP 7.4
npx @wordpress/env run tests-cli --env-cwd=wp-content/wphc-dev bash -c \
  'WPHC_VENDOR_DIR=/var/www/html/wp-content/wphc-dev/tests/php74/vendor tests/php74/vendor/bin/phpunit'
```

Per tornare a 8.1: eliminare `.wp-env.override.json` e rilanciare `start --update`.

## 4. Rigenerare vettori e golden

**Vettori di contratto** (richiede Python 3.10+ e `pip install pynacl`; nessuna rete, DB o variabile d'ambiente):

```bash
python -I tests/tools/gen_contract_vectors.py
```

Lo script legge i sorgenti di `../hub/` (sola lettura, con `ast`: esegue le funzioni vere dell'hub senza
importare `requests`, Flask o Supabase, e senza creare `__pycache__` dentro `hub/`) e riscrive
`tests/fixtures/contract-vectors.json`. Il file e' deterministico: due esecuzioni danno gli stessi byte. Va
rigenerato quando cambiano `enroll.py`, `fleet/proxy.py`, `fleet/webhook.py` o `scripts/sign_release.py`
dell'hub (gli hash dei sorgenti sono in `_meta`). Fedelta' ai byte dell'hub:

- i body POST dei vettori HMAC sono prodotti come li produce `_site_request`: `json.dumps(dict, allow_nan=False)`
  (separatori `", "` e `": "`, escape `\uXXXX`); c'e' anche un body con byte UTF-8 grezzi non ASCII;
- le firme delle release sono prodotte da `hub/scripts/sign_release.py::sign_release` su un file temporaneo
  fuori dal repo, con e senza prefisso `v` nel tag, e ogni vettore include il JSON `.sig` (tag, sha256, kid, signature);
- se la copia in `hub-cli/_vendor` delle funzioni usate diverge dall'hub, o se in `proxy.py` non c'e' piu'
  la riga di serializzazione attesa, il generatore esce con codice diverso da 0 senza scrivere nulla.

**Golden snapshot**: confronto automatico a ogni run. Per riscriverli, dopo aver capito
PERCHE' il comportamento e' cambiato (servono a dimostrare che il refactoring non lo cambia):

```bash
EXTRA_ENV="-e WPHC_GOLDEN_UPDATE=1" bash C:/tmp/wphc-run-phpunit.sh 81
# oppure: npx @wordpress/env run tests-cli --env-cwd=wp-content/wphc-dev bash -c 'WPHC_GOLDEN_UPDATE=1 vendor/bin/phpunit'
git diff tests/fixtures/golden        # controllare cosa e' cambiato
```

Procedura controllata: copiare prima `tests/fixtures/golden` in una cartella temporanea, rigenerare, rilanciare
la suite SENZA aggiornamento (deve essere verde) e confrontare i due insiemi: devono cambiare solo i file
attesi. Se un golden cambia per altre ragioni puo' essere instabilita': due rigenerazioni consecutive devono
dare file identici.

Cosa contiene uno snapshot: `status`, `body`, `headers`, `options_written` (nome => valore), `options_deleted` e,
se la richiesta ha scritto option, `options_autoload` (nome => valore GREZZO della colonna `autoload`:
con WordPress 7.x `on`/`off` quando il plugin passa il terzo argomento di `update_option`, `auto` quando lo
omette). Normalizzazione: interi entro un giorno dall'ora corrente diventano `<TS_INT>` e stringhe numeriche
(le option lette dal DB) `<TS_STR>` (due segnaposto distinti, cosi' un cambio di tipo non passa inosservato);
date ISO 8601 `<ISO8601>` SOLO se entro un giorno dall'ora corrente (un "adesso" generato dal plugin; una data lontana e' un valore
fisso del fixture e resta letterale nel golden); stringhe esadecimali lunghe `<HEX>`; URL del sito `<SITE_URL>`; versione `<AGENT_VERSION>`.

## 5. Provare un altro file di plugin (build del refactoring)

```bash
EXTRA_ENV="-e WPHC_PLUGIN_FILE=/var/www/html/wp-content/wphc-dev/dist/wp-health-check.php" bash C:/tmp/wphc-run-phpunit.sh 81
# oppure: npx @wordpress/env run tests-cli --env-cwd=wp-content/wphc-dev bash -c \
#   'WPHC_PLUGIN_FILE=/var/www/html/wp-content/wphc-dev/dist/wp-health-check.php vendor/bin/phpunit'
```

Il bootstrap verifica la provenienza di TUTTI i simboli (funzioni e classi) e che il plugin del repo non sia
incluso. Il file indicato e' usato cosi' com'e' (non viene copiato): se la build scrive su `__FILE__`, la guardia
a fine run se ne accorge.

### Convenzione cb() / call() / call_args() e mappa unica (API di test da riadattare nel refactoring)

Il refactoring non lascia wrapper `wphc_*` permanenti: le funzioni diventano metodi statici
(`WPHC_Util::normalize_url`, `WPHC_Crypto::verify_central_signature`, ...). I test richiamano le funzioni interne
del plugin SOLO tramite l'UNICO punto condiviso `tests/refactor-map.php`: la mappa funzione 1.38.1 ->
`array( classe, metodo )` con TUTTE le 157 funzioni `wphc_*` del file (tabella 2.2 di
`__docs/wp-health-check/refactoring-classi.md`, nello stesso ordine, con la visibilita' prevista a fianco):

- `WPHC_Test_Case::cb( 'wphc_x' )` restituisce il nome della funzione se esiste ancora (plugin 1.38.1), altrimenti il
  metodo che la sostituisce: l'array `array( classe, metodo )` se il metodo e' `public` (valido per
  `call_user_func()`, `has_filter()`, `has_action()`), oppure una `Closure` ottenuta con la Reflection se e' `private`
  o `protected` (chiamabile ma non adatta agli hook, che per definizione sono pubblici). Se non esiste nessuno dei due
  lancia un'eccezione che rimanda alla mappa;
- `WPHC_Test_Case::call( 'wphc_x', ...$args )` fa `call_user_func_array( cb(...), $args )`: gli argomenti arrivano per
  VALORE;
- `WPHC_Test_Case::call_args( 'wphc_x', array( &$job, ... ) )` e' la variante con RIFERIMENTI, obbligatoria per le otto
  funzioni con `array &$job` (`wphc_bulk_recount`, `wphc_bulk_apply_outcome`, `wphc_bulk_abort_job`,
  `wphc_bulk_reap_stuck_items`, `wphc_bulk_maybe_reap`, `wphc_bulk_maybe_finalize`, `wphc_bulk_finish`,
  `wphc_bulk_send_webhook_for_job`): con `call()` il job verrebbe copiato e PHP darebbe un warning (che la suite
  trasforma in errore);
- `WPHC_Test_Case::assert_hooked( $hook, cb(...), $priority, $accepted_args )` verifica priorita' E `accepted_args`;
- il bootstrap legge la stessa mappa per riconoscere il plugin caricato (forma a funzioni o a classi), e il registro
  degli hook (`test-hook-registry.php`) ne usa la mappa inversa per riportare i callback registrati ai nomi della 1.38.1.

Se un nome cambia si aggiorna SOLO `tests/refactor-map.php`. Nessun file di test chiama piu' una funzione `wphc_*` per
nome (verificabile con `grep -nE "(^|[^'\"_a-zA-Z>:$])wphc_[a-z_0-9]+\(" tests/*.php`: restano solo le funzioni di supporto
`wphc_test_*` e `wphc_read_test_passthrough`, e commenti). I cron si lanciano con `do_action( 'wphc_bulk_update_tick' )`
e `do_action( 'wphc_webhook_retry' )` (helper `run_tick()` e `run_webhook_retry()`, che verificano anche la
registrazione del callback), non chiamando `wphc_bulk_run_tick()` / `wphc_maybe_retry_webhook()`.

Funzioni NON ancora mappate: nessuna (la mappa e' completa). Le sostitute dei metodi vanno comunque verificate con la
prima build: una chiave che non risolve fa lanciare a `cb()` un'eccezione esplicita. Metodi `private` nella mappa che i
test usano e che `cb()` rende raggiungibili solo con la Reflection (se la build li rende `public` meglio, ma non e'
necessario): `wphc_bulk_acquire_lock`, `wphc_bulk_apply_outcome`, `wphc_bulk_backoff_for_attempt`,
`wphc_bulk_build_webhook_payload`, `wphc_bulk_classify_result`, `wphc_bulk_compute_stall_after_ts`,
`wphc_bulk_get_job`, `wphc_bulk_new_item`, `wphc_bulk_next_delay`, `wphc_bulk_normalize_items`, `wphc_bulk_recount`,
`wphc_bulk_save_job`, `wphc_bulk_schedule_tick`, `wphc_bulk_send_webhook_for_job`, `wphc_bulk_unschedule_ticks`,
`wphc_core_auto_update_state` (con la sua `static $cache`, che nel refactoring diventa `private static
$core_auto_update_state`: i test sono a processo separato e non dipendono da come e' resa), `wphc_current_actor`,
`wphc_fetch_latest_release`, `wphc_fetch_latest_release_from_hub`, `wphc_fetch_latest_release_without_api`,
`wphc_install_release`, `wphc_is_package_host_allowed`, `wphc_is_release_tag`, `wphc_map_item_update_outcome`,
`wphc_update_preflight`. Un metodo non statico o con firma diversa da quella della funzione fa fallire il test con un
messaggio che rimanda alla mappa.

Stato globale del plugin (lotto 3): i tre `$GLOBALS['wphc_version_snapshots']`, `['wphc_api_update_in_progress']` e
`['wphc_deleting_plugins']` della 1.38.1 sono proprieta' statiche private di `WPHC_Update_Log`. I test non toccano ne'
`$GLOBALS` ne' le proprieta': usano gli helper a due modi di `WPHC_Update_Test_Case` (`tests/class-wphc-helpers-update.php`):
`plugin_state()` (array con le stesse chiavi di `$GLOBALS`, per `assertArrayHasKey`/`assertArrayNotHasKey` e per leggere
una voce), `set_api_update_in_progress( bool )` e `reset_update_log_state()` (chiamato dal `set_up`). Con il plugin a
funzioni leggono e scrivono `$GLOBALS`; con le classi usano la Reflection sulle proprieta' statiche.

Restano fuori dalla mappa per natura (non sono funzioni del plugin): le costanti `WP_HEALTH_CHECK_*` e le stringhe
`'wphc_...'` che sono nomi di azioni admin-post/AJAX, di nonce, di campi POST (`wphc_webhook_url`, `wphc_save_webhook`,
...), di hook cron e di option, che il refactoring NON rinomina (sezione 1.7 del progetto).

### Registro degli hook (`test-hook-registry.php`)

Fotografa, dopo il bootstrap e `rest_get_server()`, ogni callback del plugin agganciato a un hook di
`$GLOBALS['wp_filter']`: e' "del plugin" se la Reflection lo dichiara nel file sotto test (il solo prefisso
`wphc_`/`WPHC_` non basta: le classi di supporto dei test hanno lo stesso prefisso e stanno sotto `tests/`). Per ogni
hook registra l'elenco ORDINATO di `[priorita, callback normalizzato, accepted_args]` (ordine di esecuzione) e per le
rotte `[metodi, callback, permission_callback, nomi degli args]`; il golden `fixtures/golden/hooks/registry.json` non ha
maschere (45 hook, 48 registrazioni, 20 percorsi REST, 22 handler). Un callback che la mappa non conosce fa FALLIRE il
test (mai un ripiego sul callable grezzo). Asserzioni esplicite in piu': i quattro callback su `init`
(3963 schema del log, 4383 versione del core, 5962 ri-armo del bulk, 7410 autologin: righe della 1.38.1), gli hook
dell'upgrader e dell'audit con `accepted_args`, i 20 hook di flush su `auto_update_*` (12 dinamici sul core), le
rotte (22 registrazioni su 20 percorsi, `wphc_require_token` su 17 handler). Il comando `WP_CLI::add_command(
'health-check', ... )` non gira in PHPUnit (`WP_CLI` non e' definita): si verifica nel sorgente (anche in
`test-cli.php`) e con la WP-CLI vera in `tests/e2e/wpcli_test.py`.

Per rigenerare il golden: `EXTRA_ENV="-e WPHC_GOLDEN_UPDATE=1" bash C:/tmp/wphc-run-phpunit.sh 81 --testsuite hook-registry`
e controllare il diff: un cambiamento di priorita', di ordine o di `accepted_args` e' un cambiamento di comportamento
da decidere, non da rigenerare.

### Test con processo separato (`test-core-auto-update-state.php`)

I test marcati `@runInSeparateProcess` (con `@preserveGlobalState disabled`) rilanciano `tests/bootstrap.php` in un
processo figlio: servono quando il codice sotto test ha stato per processo (la `static $cache` di
`wphc_core_auto_update_state()`) o costanti da definire (`AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`).
Il bootstrap imposta `WP_TESTS_SKIP_INSTALL=1` DOPO l'avvio del padre: senza, il figlio farebbe reinstallare il
database alla test library mentre il padre aspetta. Ogni figlio costa circa 5 secondi (rifa' il bootstrap di
WordPress), quindi il file di 12 test pesa circa un minuto: i test sono pochi e solo dove serve.

### Fine riga (`.gitattributes`)

`.gitattributes` impone `* text=auto eol=lf` (e i binari come `binary`): nel repo e nel checkout i file sono sempre
LF anche su Windows con `core.autocrlf=true`. Con CRLF cambierebbero i byte e lo SHA-256 del plugin distribuito
(release firmata) e dei golden. Diagnosi: `git ls-files --eol` (colonne `i/` indice, `w/` copia di lavoro) e, per il
plugin, `tr -d '\r' < mu-plugins/wp-health-check.php | sha256sum` deve coincidere con
`git show HEAD:mu-plugins/wp-health-check.php | sha256sum`. Una copia di lavoro ancora in CRLF (`w/crlf`) non e' un
problema per git (con l'attributo `git diff` non vede differenze) e si normalizza con un nuovo checkout del file.

## Chiamate HTTP in uscita nei test

Ogni test parte con la rete BLOCCATA (`pre_http_request`, `WP_HTTP_BLOCK_EXTERNAL`): la richiesta fallisce con
un `WP_Error` e viene registrata. I test che verificano un esito la mockano con un `pre_http_request` proprio.
A fine test (`tear_down` della classe base) una chiamata che nessun mock ha gestito fa FALLIRE il test, salvo:

- `$this->expect_unmocked_http()` nel test che verifica proprio il fallimento di rete;
- `$this->allow_unmocked_http_hosts( array( 'host' ) )` (in genere nel `set_up` della classe) per le chiamate di
  sfondo note del contesto (es. la tab admin che cerca l'ultima release su GitHub): un host diverso fa comunque
  fallire il test. Oggi: tab admin e update -> `api.github.com`, `github.com`; lettura -> `api.ipify.org`,
  `image.thum.io`, `wordpress.org`.

Il loopback di WP-Cron (`wp-cron.php?doing_wp_cron`) e' ignorato (e' del core).

## Variabili d'ambiente

| Variabile | Effetto |
|---|---|
| `WPHC_PLUGIN_FILE` | file del plugin da caricare invece della copia di `mu-plugins/` (mai il file del repo) |
| `WPHC_VENDOR_DIR` | cartella `vendor` da usare (matrice PHP 7.4) |
| `WPHC_WP_TESTS_DIR` | test library di WordPress (default: wp-phpunit di Composer) |
| `WP_PHPUNIT__TESTS_CONFIG` | config della test library (default: `$WP_TESTS_DIR/wp-tests-config.php` di wp-env) |
| `WPHC_GOLDEN_UPDATE=1` | riscrive i golden invece di confrontarli |

## Limiti dichiarati

- **Multisite non coperto.** `wphc_candidate_site_urls()` include `network_home_url()` e `network_site_url()`, ma la
  suite gira su un sito singolo (`test-enroll.php::test_candidate_urls_with_a_language_prefixed_home_url` copre
  il caso WPML con `home_url` filtrata, non una rete): su multisite il comportamento dei candidati resta non fissato.
- Gli header inviati con `header()` (Retry-After, CORS) non sono osservabili in-process (vedi sopra).
- Il ramo positivo del self-update (`'updated'`) non e' coperto da PHPUnit (scriverebbe `__FILE__`); lo coprono
  gli e2e (`e2e/upgrade_test.py`, push firmato su una copia). Il builder del messaggio firmato del plugin e' pero'
  esercitato davvero da `test-contract-vectors.php::test_release_message_builder_accepts_the_hub_signature`: una
  DIRECTORY al posto del `.bak` fa fallire il backup subito dopo la verifica della firma, quindi il vettore firmato
  dall'hub supera integrita' e firma e la pipeline si ferma con `wphc_update_backup_failed` senza scrivere nulla.
- Su richieste HTTP reali il `permission_callback` `wphc_require_token` gira piu' volte per richiesta (la prima nel
  dispatch, le altre dentro `rest_send_allow_header()`), e la rivalutazione di una richiesta firmata fallisce sul
  nonce gia' usato: vedi `__docs/wp-health-check/finding-throttle-doppia-valutazione.md`. Con `rest_do_request()` il
  fenomeno non si vede, ma `test-throttle-double-dispatch.php` lo riproduce chiamando
  `apply_filters( 'rest_post_dispatch', ... )` con lo stesso oggetto richiesta e FISSA i delta del contatore
  (PRESERVATO: bug noto): GET `/ping` valido v2 +1, GET `/update/bulk` valido v2 +2 (due handler), Bearer errato +2 su
  `/ping` e +3 su `/update/bulk`, OPTIONS 0, protocollo 1 valido 0. Se il bug verra' corretto questi test vanno
  invertiti con decisione esplicita (stessi numeri misurati su HTTP vero in `tests/e2e/hub_contract.py`).

## Comportamenti "scomodi" fissati dai test (da preservare o cambiare con decisione esplicita)

- Dopo `/revoke` un nuovo `/enroll` o `/rotate` riesce, ma `wp_health_check_revoked_at`
  resta e le rotte dati rispondono 403 `wphc_revoked`: sblocca solo `wphc_reset_enrollment()`.
- `/revoke` NON cancella `wp_health_check_token` (il README dice di si): cancella solo
  `token_prev`, `token_rotated_at` e i token di autologin pendenti.
- Il throttle e' un solo contatore per IP condiviso da enroll, rotate, revoke e rotte dati;
  il successo non lo azzera; downgrade 409 e URL mismatch 403 non lo incrementano. La finestra e' di 300 s e
  si RINNOVA a ogni fallimento. Senza un IP valido tutti i chiamanti condividono il contatore dell'IP vuoto.
- Su `/enroll` il throttle e' il primo controllo (prima dell'HTTPS); sulle rotte dati
  vengono prima HTTPS, 503 non enrolled e 403 revocato.
- Su `/enroll` v2 la freschezza di `issued_at` e' controllata PRIMA del nonce, e il nonce prima della firma.
- `/rotate` e `/revoke` registrano il nonce solo a firma e URL verificati: un rifiuto non lo brucia.
- L'email di alert parte solo per l'URL mismatch di `/enroll` (al massimo una all'ora).
- `enroll` salva `site_url` cosi' come firmato (non normalizzato) e un protocol >= 2 e'
  salvato come 2; `/rotate` salva `max(2, protocol)`; `/rotate` e `/revoke` rispondono con il `site` firmato
  identico all'input (nei golden l'URL del sito diventa `<SITE_URL>`: l'uguaglianza e' asserita a parte).
- Con Content-Type JSON un body non valido e' rifiutato dal core (`rest_invalid_json`),
  non dal plugin.
- `normalize_url` di hub e plugin divergono su userinfo, backslash finale, maiuscole non
  ASCII, URL senza schema, URL relativi al protocollo, stringa vuota (`'https:'`) e schemi diversi da http(s):
  elencate in `contract-vectors.json` (`"diverge": true`).
