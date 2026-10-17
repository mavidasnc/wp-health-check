# src/: sorgente del plugin

Il plugin distribuito resta UN solo file, `mu-plugins/wp-health-check.php`, ma da questo passo e' un file
GENERATO: lo produce `bin/build.php` concatenando i file di questa cartella nell'ordine del manifest.
Non va mai modificato a mano: si modifica `src/` e si rigenera (la build e' deterministica, stesso sorgente
uguale SHA-256). Il progetto del refactoring e' in `../../__docs/wp-health-check/refactoring-classi.md`.

## Layout

| File | Ruolo | Contenuto |
|---|---|---|
| `manifest.php` | (non entra nel file distribuito) | elenco ORDINATO `file => ruolo`; la build fallisce se diverge dai file presenti |
| `header.php` | `header` | docblock del plugin (`Plugin Name:`, `Version:` ...) e `defined( 'ABSPATH' ) \|\| exit;` |
| `constants.php` | `constants` | costanti `WP_HEALTH_CHECK_*`, ognuna in un `if ( ! defined() )` (sovrascrivibili da `wp-config.php`); qui sta `WP_HEALTH_CHECK_VERSION` e (dal lotto 3) `WP_HEALTH_CHECK_FILE`, con default `__FILE__`: l'unico file che puo' usare `__FILE__` e `__DIR__` |
| `class-wphc-*.php` | `class` | una sola classe `final` per file (21 file: `WPHC_Options` e altre 20 classi), nessun altro codice top-level; dal lotto 4 comprendono `WPHC_Admin`, `WPHC_Admin_View` (template della tab Site Health) e `WPHC_Plugin` (`boot()`) |
| `class-wphc-cli-command.php` | `cli` | (dal lotto 1) il blocco del comando WP-CLI: guard `if ( defined( 'WP_CLI' ) && WP_CLI )`, classe `WPHC_CLI_Command`, registrazione del comando; ultimo del manifest, come nel file 1.38.1 |
| `boot.php` | `boot` | (dal lotto 4) docblock di file e `WPHC_Plugin::boot();`: l'unica istruzione al livello del file, in coda alle classi (prima del file `cli`) |

Ogni file inizia con `<?php` seguito da un a capo e termina con un a capo; solo LF. La concatenazione toglie il
`<?php` iniziale (e un eventuale `?>` finale) dai file successivi al primo e NON inserisce separatori: e' un
taglio a byte riproducibile.

### Ruoli e regole

- `header`: uno solo, il primo del manifest.
- `class`: la build verifica che il file contenga una sola classe e nessun altro codice al livello del file.
- `header`, `constants`: eccezioni dichiarate, possono avere codice al livello del file.
- `boot`: il file contiene ESATTAMENTE `WPHC_Plugin::boot();` come istruzione al livello del file (la build confronta la sequenza di
  token: spazi, commenti e docblock sono liberi, ogni altra istruzione o un boot dentro `add_action()` fa fallire la build). Il boot
  deve girare all'inclusione del file, come gli hook della 1.38.1; per lo stesso motivo il corpo di `WPHC_Plugin::boot()` non puo'
  contenere come stringa `muplugins_loaded`, `plugins_loaded` o `after_setup_theme` (un hook che ritarderebbe la registrazione).
- `legacy`: ruolo dei lotti 1-3 (il corpo del file 1.38.1 non ancora spostato in classi). Dal lotto 4 `src/legacy.php` non
  esiste piu' e il manifest non lo usa, ma `bin/build.php` accetta ancora il ruolo (un "file con codice libero")
  perche' le sorgenti sintetiche di `tests/build/run.php` lo usano: toglierlo dalla build significa riscrivere quei
  fixture, ed e' un passo di pulizia da fare in un secondo momento.
- `cli`: al massimo un file, e se esiste e' l'ULTIMA voce del manifest. `WPHC_CLI_Command` non estende
  `WP_CLI_Command`, ha metodi d'istanza e deve esistere solo con WP-CLI attivo, quindi non puo' avere il ruolo
  `class`. Il codice top-level ammesso e' SOLO il guard `if ( defined( 'WP_CLI' ) && WP_CLI ) {`, una
  `class WPHC_CLI_Command {` (senza `final`, `abstract`, `extends`, `implements`) e UNA registrazione che sia
  ESATTAMENTE la sequenza di token `WP_CLI :: add_command ( 'health-check' , 'WPHC_CLI_Command' ) ;` (nessun terzo
  argomento: una closure o un array di opzioni eseguirebbe codice a livello del file); qualunque altra istruzione o
  dichiarazione, dentro o fuori dal guard, fa fallire la build. Lo strip conserva i docblock di questa classe (e di
  nient'altro oltre all'header).
- Il sorgente dei file `class` e `cli` non contiene `defined( 'ABSPATH' ) || exit;`: la guardia sta in `header.php`
  e nel file distribuito vale per tutto. Non scrivere mai la chiamata `WP_CLI::add_command( 'health-check', ... )` in
  un commento: i test di caratterizzazione contano le sue occorrenze nel sorgente (deve essere una).
- Una classe non puo' estendere una classe dichiarata PIU' AVANTI nel file concatenato (le classi esterne di
  WordPress e WP-CLI sono ammesse). Le classi vanno nel manifest in ordine di dipendenza, il boot in fondo.
- Niente `namespace`, niente funzioni o classi dichiarate due volte al livello del file.
- Ordine del manifest (sezione 3.3 del progetto): `header`, `constants`, le classi (in ordine di dipendenza: prima
  `WPHC_Options`, poi le classi che la usano; `WPHC_Admin`, `WPHC_Admin_View` e `WPHC_Plugin` per ultime), `boot.php` e,
  ultimo, il file `cli`.

### Versione

La versione dell'agent sta in due punti che la build tiene allineati: la riga `Version:` dell'header
(`header.php`) e `WP_HEALTH_CHECK_VERSION` (`constants.php`). La build fallisce se non coincidono. Per cambiare
versione si modificano ENTRAMBE le righe; l'header non viene generato per non spostare byte rispetto al
file 1.38.1 (checkpoint zero) e perche' `Plugin Name:`/`Version:` devono restare leggibili anche nel sorgente.

## Comandi

```bash
composer run build         # build con strip (distribuibile): riscrive mu-plugins/wp-health-check.php
composer run build:dev     # build SENZA strip (sviluppo e debug), scritta in build/dev/wp-health-check.php (cartella ignorata da git)
composer run build:check   # confronta la build strip col file committato in mu-plugins/, exit 1 se differisce
composer run test:build    # test della build stessa (PHP puro, senza WordPress)
composer run lint          # phpcs su src/ e bin/ (il file generato e' escluso)
composer run analyse       # phpstan su src/ e bin/ (il file generato e' escluso)
```

Opzioni di `php bin/build.php`: `--no-strip`, `--strip`, `--output <path>` (scrive altrove, creando la cartella se manca:
misure e prove), `--check`, `--src <dir>` (altro albero sorgente, per provare la build).

**Il file committato in `mu-plugins/` e' la build strip, e la dev non ci si scrive.** E' lo strip quello che si firma e si
pubblica (e il suo body di push sta sotto i 128 KiB, la dev no: ha anche il marcatore `WPHC-BUILD`). Per questo
`php bin/build.php` senza opzioni, con `--strip` o `composer run build` scrive `mu-plugins/` senza altri consensi, mentre
`--no-strip` SENZA `--output` esce con codice 2 e non scrive nulla: la dev si produce solo con `--output <path>`, che e' quello
che fa `composer run build:dev` (verso `build/dev/`, ignorata da git). `--check` non scrive e non e' soggetto al rifiuto.

`build:check` confronta il modo indicato dalla variabile `WPHC_BUILD_MODE` (`dev` oppure `strip`, default `strip`) con
`mu-plugins/`; con `WPHC_BUILD_MODE=dev` il confronto ha senso solo insieme a `--output` verso la build dev (il file committato
e' lo strip). Nel confronto gli a capo CRLF della copia di lavoro (`core.autocrlf` su Windows) sono normalizzati a LF: nell'indice
il file e' LF.

### Strip dei commenti

Con lo strip ogni commento diventa i soli a capo che contiene: il numero di righe non cambia e la riga N del
file distribuito e' la riga N della concatenazione, quindi gli stack trace di produzione puntano al posto giusto.
Si conservano il docblock dell'header e i docblock della classe `WPHC_CLI_Command` (WP-CLI li legge via
reflection per sinossi, `## OPTIONS` e `@when`). Stringhe, heredoc e HTML dei template non si toccano. La build
verifica che i token non commento (con la riga) siano identici prima e dopo.

### Gate della build

La build non scrive nulla se uno di questi controlli fallisce: parse completo con `token_get_all( ..., TOKEN_PARSE )`
sul concatenato e sul risultato; file `class` con una sola classe; file `cli` con solo il blocco del comando WP-CLI
(al massimo uno); file `boot` con la sola istruzione `WPHC_Plugin::boot();` e `WPHC_Plugin::boot()` senza hook di ritardo; nessuna classe che estende una classe piu' avanti; versione dell'header uguale a `WP_HEALTH_CHECK_VERSION`;
`__FILE__` e `__DIR__` ammessi SOLO nel file con ruolo `constants` (controllo sui token di ogni altro file, quindi non
scattano per commenti e stringhe: nel file concatenato indicano il mu-plugin, in un file di `src/` indicherebbero il file
sorgente; il codice che ha bisogno del percorso del plugin usa `WP_HEALTH_CHECK_FILE`, vedi sotto); `__CLASS__`,
`__FUNCTION__`, `__METHOD__` e `__LINE__` vietati in OGNI file, `constants.php` e header compresi (non compaiono nella
1.38.1 e il loro valore dipende da dove vive il codice, quindi un uso cambierebbe il comportamento senza che nessun test
se ne accorga); la parola `WPHC-BUILD` assente dai sorgenti e dalla build strip, presente una sola volta, in coda, nella
build dev (vedi «Marcatore della build dev»); nessun `?>` o HTML inline fuori dalle funzioni
(i template dentro i metodi sono ammessi); file che inizia con `<?php` e con header `Plugin Name:`/`Version:`
come lo vogliono WordPress, l'installer e `WPHC_Self_Updater::install_release()`; solo LF; per la build strippata il body JSON
intero del push (`{"package": {...}}`, come lo spedisce l'hub e lo misurano gli e2e: firma da 88 caratteri e
`force: true`; e' una stima: il gzip di PHP e quello di Python differiscono di qualche decina di byte, il margine
esatto lo misura l'e2e) sotto 131072 byte, con avviso oltre il budget di 110000 caratteri di
gzip + base64.

### Marcatore della build dev

La build `--no-strip` (`composer run build:dev`, che scrive in `build/dev/`) aggiunge in
coda al file, DOPO l'ultima riga di codice, il commento `// WPHC-BUILD: dev (non distribuibile)`, senza a capo finale:
il corpo non cambia di una riga (la dev ha lo stesso numero di righe dello strip e gli stessi token non commento). Lo
strip NON lo contiene e la build fallisce se lo trova (anche in una stringa); i sorgenti di `src/` non possono contenere
la parola `WPHC-BUILD`, cosi' il marcatore e' l'unica occorrenza.

**Un file con il marcatore NON va mai firmato ne' pubblicato.** `sign_release.py` sta nel repository dell'hub e non si
tocca da qui: la protezione e' il flusso descritto in «Passaggio finale eseguito» (punto 3), che richiede `grep -L WPHC-BUILD <file>` PRIMA
della firma. Attenzione al senso del comando: `grep -L` stampa il nome dei file che NON contengono la parola, quindi
l'output vuoto significa che il marcatore e' presente (file dev, da non firmare) e l'output uguale al nome del file
significa che il marcatore e' assente (file strip, firmabile).

## phpcs e phpstan

Girano su `src/` e `bin/`, non sul file generato (lo strip toglie le annotazioni `phpcs:ignore`: darebbe
centinaia di falsi errori). Unica esclusione nuova: `Squiz.Commenting.FileComment.WrongStyle` su `constants.php`,
che e' un taglio a byte del file 1.38.1 e inizia con un commento di riga invece che con un docblock di file; si
toglie quando `constants.php` riceve il suo docblock. Dal lotto 4 non c'e' piu' l'esclusione
`Universal.Files.SeparateFunctionsFromOO.Mixed` (nessun file mescola funzioni e classi) e `strict_class_file_names`
vale `true` (ogni classe sta in `class-<nome>.php`).

## Note per chi sposta il codice

- **`WPHC_Cors::maybe_send_headers()` e il backslash.** Nel corpo di questo metodo i riferimenti a `WPHC_Options`
  hanno il backslash iniziale (`\WPHC_Options::OPT_DASHBOARD_ORIGIN`): lo spy `capture_cors()` dei test
  (`tests/class-wphc-helpers-read.php`) legge gli header eseguendo il CORPO del metodo in un altro namespace
  (`WPHC_Cors_Spy`), dove un nome di classe non qualificato non si risolverebbe. In produzione il backslash e'
  equivalente a nessun backslash. Lo spy rifiuta il corpo se trova `self::`, `static::`, `parent::`, `$this` o una
  classe `WPHC_*::` senza il backslash iniziale (commenti esclusi).
- **Banner di sezione.** I banner del file 1.38.1 che descrivevano un blocco sono stati conservati nella classe che
  ha ereditato il codice: «PROTOCOLLO V2» nel docblock di `WPHC_Guard`, «AUTENTICAZIONE DELLE ROTTE DATI» sopra
  `WPHC_Auth::require_token()`, «CALLBACK: POST /enroll|/rotate|/revoke» sopra i tre metodi di `WPHC_Enrollment`,
  «LETTURA ROBUSTA DEI TRANSIENT DI UPDATE» sopra `WPHC_Util::mute_update_shortcircuit()`, «COMANDI WP-CLI» in testa a
  `class-wphc-cli-command.php`.
- **Stato statico (lotto 2).** La `static $cache` della funzione 1.38.1 che calcola lo stato di auto-update del core
  e' la proprieta' statica privata `WPHC_Health::$core_auto_update_state` (default `null`, nessun accessore: la
  leggono e la scrivono solo `core_auto_update_state()` e nient'altro). La semantica e' identica a quella della
  variabile statica di funzione: calcolata una volta per processo PHP, persiste fra un test e l'altro (per questo
  `tests/test-core-auto-update-state.php` gira in `@runInSeparateProcess`).
- **Banner del lotto 2.** I banner «CALLBACK: GET /health | /ping | /detail/plugins | /detail/theme | /detail/server |
  /detail/users | GET|POST /thumbnail», «HELPER: thumbnail del sito (thum.io)», «ROTTA DIAGNOSTICA: GET /debug»,
  «AUTOLOGIN: POST /autologin/token + consumo su init» e «TRACCIAMENTO ULTIMO LOGIN» stanno nella classe che ha
  ereditato il codice, sopra il metodo che seguivano nel file 1.38.1 (per `/detail/users` e' `WPHC_Users::build_summary()`,
  per l'ultimo login `WPHC_Users::record_last_login()`). Il banner «UTILITY CONDIVISE», che apriva `legacy.php`
  e non aveva piu' codice sotto di se', e' stato tolto (titolo generico, nessun testo da conservare).

- **Stato globale (lotto 3).** Le tre chiavi di `$GLOBALS` della 1.38.1 (prefisso `wphc_`) sono proprieta' statiche private di
  `WPHC_Update_Log`: `$version_snapshots` (default `array()`), `$api_update_in_progress` (default `false`) e
  `$deleting_plugins` (default `array()`). Le leggono e scrivono solo i metodi di quella classe, tranne il flag
  dell'update via API, che imposta `WPHC_Item_Updater` (in `perform_item_update()` e `perform_core_update()`, a coppie
  attorno a `upgrade()`) tramite i due metodi aggiunti `WPHC_Update_Log::begin_api_update()` (`true`) ed
  `end_api_update()` (`false`, al posto dell'`unset()`: `empty()` da' lo stesso esito). Se `upgrade()` muore con un
  fatal il flag resta impostato fino a fine richiesta, come con `$GLOBALS`. Nei test li legge e li scrive solo
  l'helper `WPHC_Update_Test_Case::plugin_state()`/`set_api_update_in_progress()`/`reset_update_log_state()` (Reflection).
- **`WP_HEALTH_CHECK_FILE` (D-1, lotto 3).** `WPHC_Self_Updater::install_release()` riscrive il file indicato dalla
  costante `WP_HEALTH_CHECK_FILE` (backup `.bak`, temporaneo `.wphc-new-*.tmp`, `rename()`, rilettura di controllo,
  `opcache_invalidate()`, cancellazione del `.bak`): nella 1.38.1 era `__FILE__`. La costante e' definita in
  `constants.php` con `if ( ! defined() )` e default `__FILE__`, che nel file costruito e' il mu-plugin distribuito:
  in produzione il comportamento non cambia di un byte e `dirname( WP_HEALTH_CHECK_FILE )` e' `WPMU_PLUGIN_DIR`.
  Limiti di una ridefinizione (da `wp-config.php` o dai test): il file indicato deve stare sullo stesso filesystem di
  `WPMU_PLUGIN_DIR`, perche' il temporaneo si crea in `WPMU_PLUGIN_DIR` e poi si fa `rename()` sul percorso della
  costante (fra dispositivi diversi il `rename()` fallisce); `probe_mu_dir_writable()` controlla sempre
  `WPMU_PLUGIN_DIR`, non la cartella del file indicato; la copia non deve mai essere un `.php` nella radice di
  `mu-plugins/` (WordPress la caricherebbe al giro successivo: "Cannot declare class"). Chi puo' scrivere
  `wp-config.php` controlla gia' il sito, quindi la sovrascrivibilita' non apre un vettore nuovo. Non si legge ne'
  `__FILE__` ne' `__DIR__` altrove: i test PHPUnit caricano una copia in `sys_get_temp_dir()` e lasciano il default.
- **Banner del lotto 3.** I banner del file 1.38.1 che precedevano le funzioni spostate stanno sopra il metodo che
  seguivano: «CALLBACK: POST /update» sopra `WPHC_Self_Updater::github_request_args()` (come nel 1.38.1 precede gli
  helper di GitHub e non il callback), «AGGIORNAMENTO PLUGIN/TEMI/CORE VIA API» (con il vincolo di sicurezza «mai da
  dove ne' a quale versione») sopra `WPHC_Update_Log::table()`, «CALLBACK: POST /update/reactivate» sopra
  `WPHC_Item_Updater::route_reactivate()`, «AGGIORNAMENTI BULK (...) VIA POST /update/bulk + WP-CRON» sopra
  `WPHC_Bulk::get_job()`, «CALLBACK: POST|GET /update/bulk, POST /update/bulk/cancel» sopra
  `WPHC_Bulk::route_enqueue()`, «WEBHOOK DI NOTIFICA FIRMATO (a fine job bulk, o su richiesta di prova)» sopra
  `WPHC_Webhook::url()`.
- **Stringa SQL di `WPHC_Update_Log::maybe_install_schema()`.** Le righe interne alla stringa multilinea della
  `CREATE TABLE` NON sono reindentate (la tabulazione fa parte della stringa passata a `dbDelta()`): non lanciare
  `composer run lint:fix` su `class-wphc-update-log.php` senza ricontrollare quelle righe.
- **Accorpamenti rimandati (decisione D-4).** Nel lotto 3 NON si accorpano ne' i due backoff identici
  (`WPHC_Bulk::backoff_for_attempt()` e `WPHC_Webhook::backoff_for_attempt()`: l'helper previsto dal progetto, privato in
  `WPHC_Util`, sarebbe chiamato da due classi e quindi pubblico, un metodo fuori dalla mappa in una classe del lotto 1)
  ne' la regex del tag di release ripetuta in `fetch_latest_release_without_api()` (identica a `is_release_tag()`, ma
  si lascia rimandata per tenere lo spostamento uno a uno, verificabile token per token).

- **Banner del lotto 4.** Il banner «TAB SITE HEALTH: stato e configurazione da wp-admin» (con il paragrafo sulla
  capability) sta sopra `WPHC_Admin::register_tab()`; i commenti liberi che precedevano le registrazioni
  (perche' servono entrambe le famiglie `update_site_option_*` e `update_option_*`, i titoli «CORS» e «REGISTRAZIONE ROTTE
  REST») sono in `WPHC_Plugin::boot()`.
- **Template della tab (lotto 4).** `WPHC_Admin_View::render_tab()` contiene il template HTML della tab Site Health
  (circa 700 righe con `?>` e `<?php`, `<style>` e `<script>` inline) DENTRO il metodo, mai al livello del file. Il prologo
  PHP e' reindentato di una tabulazione; il template, dalla riga dopo il `?>` fino alla riga `<?php` finale inclusa, e'
  copiato verbatim: le tabulazioni e gli spazi sono output, e nemmeno una virgola del testo e' cambiata (le isole PHP
  nel template cambiano solo nei letterali di option, diventati costanti di `WPHC_Options`). Due commenti
  `phpcs:disable`/`phpcs:enable Generic.WhiteSpace.ScopeIndent.Incorrect` racchiudono il template perche' la
  tabulazione di una riga non corrisponde a quella del codice che la circonda. **Non lanciare `composer run lint:fix` su
  `class-wphc-admin-view.php`**: phpcbf reindenterebbe l'HTML e cambierebbe l'output. Se un giorno lo si lancia lo stesso,
  ripetere subito il confronto con `tests/test-admin.php` (golden `admin/tab-*.json`) e il diff dell'HTML (10.3 del progetto).
- **`WPHC_Plugin::boot()` (lotto 4).** Registra tutti gli hook nello stesso ordine in cui comparivano nel file 1.38.1
  (confronto token per token con le istruzioni al livello del file dell'originale; golden `hooks/registry.json`
  identico): 20 hook di flush sulle option `auto_update_*` (il nome dinamico dell'option del core e' la variabile
  locale `$core_auto_option`, senza `unset()`: nel file 1.38.1 era una variabile globale creata e distrutta al livello
  del file), CORS, `rest_api_init`, i quattro callback su `init` nell'ordine schema del log, versione del core, ri-armo
  del bulk, autologin, upgrader, `wp_login`, cron del tick e del retry, tab Site Health, tre azioni AJAX e otto azioni
  admin-post. Gira al caricamento del file (da `boot.php`), prima di `muplugins_loaded`: non va mai spostata su un
  hook di ritardo. Le rotte REST non si registrano qui ma in `WPHC_Rest::register_routes()`.
- **Costanti (lotto 4).** `WPHC_Options` ha 55 costanti, tutte le righe della tabella 1.8: l'ultima entrata e'
  `TRANSIENT_WEBHOOK_BAD_URL_PREFIX` (`wphc_webhook_bad_url_`, usata dalla tab e da `handle_save_webhook()`).
  `'wphc_webhook_retry'` in `handle_save_webhook()` e' `WPHC_Options::CRON_WEBHOOK_RETRY`. Restano letterali i nomi di
  azioni admin-post, AJAX, nonce, campi POST e flag di redirect della tabella 1.7 del progetto.
- **Handler admin-post non accorpati (D-4, lotto 4).** I prologhi (capability e nonce) e gli epiloghi (redirect e
  `exit`) degli otto handler restano ripetuti: gli helper `authorize()` e `redirect_to_tab()` previsti dal progetto
  sarebbero metodi fuori mappa e lo spostamento resta verificabile token per token senza eccezioni.
- **`tests/test-plugin-file-constant.php`.** Presuppone il plugin a classi (usa `WPHC_Self_Updater` e
  `WP_HEALTH_CHECK_FILE`): con il file 1.38.1 fallirebbe, e non se ne maschera il fallimento con un `markTestSkipped()`
  perche' il progetto ha `failOnSkipped="true"` e un test saltato in silenzio non proverebbe piu' nulla. Nessuna
  configurazione utile esegue quel test sulla 1.38.1 (la suite gira sempre sul file costruito da `src/`).

## Passaggio finale eseguito (1.39.0): build strip in `mu-plugins/`

Il passaggio e' stato fatto insieme al bump a 1.39.0: il file committato in `mu-plugins/` e' la build STRIP di `src/`
(stesso numero di righe della dev, nessun marcatore, body di push circa 60,7 KB contro il limite di 128 KiB), e tutto
cio' che lo confronta o lo scrive e' coerente con questo. Cosa e' cambiato, per chi cerca il perche':

1. `bin/build.php`: il default di `--check` e' `strip`; la scrittura dello strip su `mu-plugins/` non richiede consensi
   (la variabile `WPHC_BUILD_ALLOW_STRIP_IN_PLACE` non esiste piu'); in cambio e' rifiutata (exit 2) la build dev su
   `mu-plugins/` senza `--output`, perche' la dev ha il marcatore e non va mai firmata.
2. `composer.json`: `build` scrive lo strip in `mu-plugins/`, `build:dev` scrive la dev in `build/dev/`, `build:check`
   confronta con lo strip.
3. `tests/build/run.php`: la prova «la build coincide col file committato» e il test della CLI seguono il nuovo default
   (strip salvo `WPHC_BUILD_MODE=dev`); la prova «lo strip non si scrive su mu-plugins/ senza consenso» e' sostituita da
   quella opposta (lo strip si scrive, la dev no senza `--output`); ci sono i test dei gate sul ruolo `boot`.
4. `tests/bootstrap.php`: `mu-plugins/` deve coincidere con la build strip di `src/`; anche un `WPHC_PLUGIN_FILE` deve
   coincidere, salvo `WPHC_ALLOW_FOREIGN_BUILD=1` (candidati esterni di proposito). Vedi `tests/README.md`.
5. e2e (`tests/e2e/`): il file sotto test di default e' `mu-plugins/` del repo (letto, mai scritto, verificato uguale
   allo strip di `src/`); la baseline 1.38.1 da git resta lo stato di partenza dell'upgrade.

Flusso di lavoro attuale:

```bash
# 1. modificare src/ (e, se serve, tests/), poi rigenerare il file distribuito
composer run build            # riscrive mu-plugins/wp-health-check.php (strip)
composer run build:check      # CHECK OK: il file committato coincide con la build
composer run test:build       # test della build (PHP puro, anche con PHP 7.4)
composer run lint && composer run analyse

# 2. suite PHPUnit (8.1 nell'ambiente wp-env; 7.4 e 8.3 in container usa-e-getta) ed e2e: vedi tests/README.md e tests/e2e/README.md
bash tests/tools/run-phpunit-docker.sh 8.3
python -I tests/e2e/run_all.py

# 3. PRIMA di firmare o pubblicare QUALUNQUE file
grep -L WPHC-BUILD mu-plugins/wp-health-check.php   # deve STAMPARE il nome del file
```

**Un file con il marcatore NON va mai firmato ne' pubblicato** (vedi «Marcatore della build dev»). Il controllo del punto 3
sta qui perche' `sign_release.py` (repo dell'hub) non lo fa e non si modifica da questo repository: `grep -L` elenca i file che
NON contengono la parola, quindi un output vuoto significa marcatore presente (file dev, da non firmare) e il nome del file
significa marcatore assente (strip, firmabile). Per una release: versione in `src/header.php` e `src/constants.php` (la
build fallisce se non coincidono), `composer run build`, `build:check` con `CHECK OK`, suite e e2e verdi, `grep -L` come sopra,
e solo allora firma e pubblicazione.
