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
| `legacy.php` | `legacy` | tutto il resto del plugin 1.38.1 (funzioni `wphc_*` e registrazione degli hook): si svuota lotto dopo lotto e sparisce a fine refactoring |
| `class-wphc-*.php` | `class` | (dai lotti 1-4) una sola classe `final` per file, nessun altro codice top-level |
| `class-wphc-cli-command.php` | `cli` | (dal lotto 1) il blocco del comando WP-CLI: guard `if ( defined( 'WP_CLI' ) && WP_CLI )`, classe `WPHC_CLI_Command`, registrazione del comando; ultimo del manifest, come nel file 1.38.1 |
| `boot.php` | `boot` | (lotto 4) `WPHC_Plugin::boot();`, in coda alle classi (prima del file `cli`) |

Ogni file inizia con `<?php` seguito da un a capo e termina con un a capo; solo LF. La concatenazione toglie il
`<?php` iniziale (e un eventuale `?>` finale) dai file successivi al primo e NON inserisce separatori: e' un
taglio a byte riproducibile.

### Ruoli e regole

- `header`: uno solo, il primo del manifest.
- `class`: la build verifica che il file contenga una sola classe e nessun altro codice al livello del file.
- `header`, `constants`, `legacy`, `boot`: eccezioni dichiarate, possono avere codice al livello del file.
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
- Durante i lotti i file di classe entrano nel manifest PRIMA di `legacy.php` (vedi sezioni 3.3 e 7 del progetto).

### Versione

La versione dell'agent sta in due punti che la build tiene allineati: la riga `Version:` dell'header
(`header.php`) e `WP_HEALTH_CHECK_VERSION` (`constants.php`). La build fallisce se non coincidono. Per cambiare
versione si modificano ENTRAMBE le righe; l'header non viene generato per non spostare byte rispetto al
file 1.38.1 (checkpoint zero) e perche' `Plugin Name:`/`Version:` devono restare leggibili anche nel sorgente.

## Comandi

```bash
composer run build         # build con strip dei commenti (distribuibile), scrive mu-plugins/wp-health-check.php
composer run build:dev     # build SENZA strip (sviluppo e debug), scrive mu-plugins/wp-health-check.php
composer run build:check   # confronta la build col file committato in mu-plugins/, exit 1 se differisce
composer run test:build    # test della build stessa (PHP puro, senza WordPress)
composer run lint          # phpcs su src/ e bin/ (il file generato e' escluso)
composer run analyse       # phpstan su src/ e bin/ (il file generato e' escluso)
```

Opzioni di `php bin/build.php`: `--no-strip`, `--strip`, `--output <path>` (scrive altrove: misure e test),
`--check`, `--src <dir>` (altro albero sorgente, per provare la build).

`build:check` confronta il modo indicato dalla variabile `WPHC_BUILD_MODE` (`dev` oppure `strip`, default `dev`):
durante il refactoring il file committato in `mu-plugins/` e' la build `--no-strip`; alla fine del lavoro passera'
allo strip (si imposta `WPHC_BUILD_MODE=strip`, o si cambia il default in `bin/build.php`). Nel confronto gli a
capo CRLF della copia di lavoro (`core.autocrlf` su Windows) sono normalizzati a LF: nell'indice il file e' LF.

### Strip dei commenti

Con lo strip ogni commento diventa i soli a capo che contiene: il numero di righe non cambia e la riga N del
file distribuito e' la riga N della concatenazione, quindi gli stack trace di produzione puntano al posto giusto.
Si conservano il docblock dell'header e i docblock della classe `WPHC_CLI_Command` (WP-CLI li legge via
reflection per sinossi, `## OPTIONS` e `@when`). Stringhe, heredoc e HTML dei template non si toccano. La build
verifica che i token non commento (con la riga) siano identici prima e dopo.

### Gate della build

La build non scrive nulla se uno di questi controlli fallisce: parse completo con `token_get_all( ..., TOKEN_PARSE )`
sul concatenato e sul risultato; file `class` con una sola classe; file `cli` con solo il blocco del comando WP-CLI
(al massimo uno); nessuna classe che estende una classe piu' avanti; versione dell'header uguale a `WP_HEALTH_CHECK_VERSION`;
`__FILE__` e `__DIR__` ammessi SOLO nel file con ruolo `constants` (controllo sui token di ogni altro file, quindi non
scattano per commenti e stringhe: nel file concatenato indicano il mu-plugin, in un file di `src/` indicherebbero il file
sorgente; il codice che ha bisogno del percorso del plugin usa `WP_HEALTH_CHECK_FILE`, vedi sotto); nessun `?>` o HTML inline fuori dalle funzioni
(i template dentro i metodi sono ammessi); file che inizia con `<?php` e con header `Plugin Name:`/`Version:`
come lo vogliono WordPress, l'installer e `WPHC_Self_Updater::install_release()`; solo LF; per la build strippata il body JSON
intero del push (`{"package": {...}}`, come lo spedisce l'hub e lo misurano gli e2e: firma da 88 caratteri e
`force: true`; e' una stima: il gzip di PHP e quello di Python differiscono di qualche decina di byte, il margine
esatto lo misura l'e2e) sotto 131072 byte, con avviso oltre il budget di 110000 caratteri di
gzip + base64.

## phpcs e phpstan

Girano su `src/` e `bin/`, non sul file generato (lo strip toglie le annotazioni `phpcs:ignore`: darebbe
centinaia di falsi errori). Unica esclusione nuova: `Squiz.Commenting.FileComment.WrongStyle` su `constants.php`
e `legacy.php`, che sono tagli a byte del file 1.38.1 e iniziano con un commento di riga invece che con un
docblock di file; si toglie quando `legacy.php` sparisce e `constants.php` riceve il suo docblock.

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

## Checklist del passaggio finale alla build strip (da fare nel commit che porta lo strip in `mu-plugins/`)

Oggi il file committato in `mu-plugins/` e' la build `--no-strip` e `build:check` confronta in modo `dev` per
default. Nel commit che lo sostituisce con la build strip (non prima) vanno cambiati INSIEME:

1. `bin/build.php`, `wphc_build_main()`, ramo `$check`: default `strip` invece di `dev` (e il commento d'uso in testa
   al file, che descrive il modo "con --check").
2. `tests/build/run.php` (righe 282-287 circa): la prova «la build coincide col file committato» sceglie il modo con
   `'strip' === getenv( 'WPHC_BUILD_MODE' )` (dev salvo `strip`); va invertita in `'dev' !== getenv( 'WPHC_BUILD_MODE' )`
   (strip salvo `dev`), e il messaggio di errore («lanciare composer run build:dev») diventa «composer run build».
3. Questo README: la frase di `build:check` ("durante il refactoring il file committato e' la build `--no-strip`") e la
   tabella dei comandi (`composer run build` / `build:dev`).
4. `composer.json` e README di progetto: `composer run build` come comando che rigenera il file distribuito, e
   `wp-health-check/CLAUDE.md` (vincolo "single self-contained file": sorgente in `src/`, file distribuito generato).
5. Rigenerare `mu-plugins/wp-health-check.php` con `composer run build`, controllare `build:check`, `test:build`, la
   suite PHPUnit e gli e2e con quel file come candidato.
