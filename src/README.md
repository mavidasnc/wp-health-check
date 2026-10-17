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
| `constants.php` | `constants` | costanti `WP_HEALTH_CHECK_*`, ognuna in un `if ( ! defined() )` (sovrascrivibili da `wp-config.php`); qui sta `WP_HEALTH_CHECK_VERSION` |
| `legacy.php` | `legacy` | tutto il resto del plugin 1.38.1 (funzioni `wphc_*`, registrazione degli hook, comando WP-CLI): si svuota lotto dopo lotto e sparisce a fine refactoring |
| `class-wphc-*.php` | `class` | (dai lotti 1-4) una sola classe `final` per file, nessun altro codice top-level |
| `boot.php` | `boot` | (lotto 4) `WPHC_Plugin::boot();`, in coda alle classi |

Ogni file inizia con `<?php` seguito da un a capo e termina con un a capo; solo LF. La concatenazione toglie il
`<?php` iniziale (e un eventuale `?>` finale) dai file successivi al primo e NON inserisce separatori: e' un
taglio a byte riproducibile.

### Ruoli e regole

- `header`: uno solo, il primo del manifest.
- `class`: la build verifica che il file contenga una sola classe e nessun altro codice al livello del file.
- `header`, `constants`, `legacy`, `boot`: eccezioni dichiarate, possono avere codice al livello del file.
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
sul concatenato e sul risultato; file `class` con una sola classe; nessuna classe che estende una classe piu'
avanti; versione dell'header uguale a `WP_HEALTH_CHECK_VERSION`; nessun `?>` o HTML inline fuori dalle funzioni
(i template dentro i metodi sono ammessi); file che inizia con `<?php` e con header `Plugin Name:`/`Version:`
come lo vogliono WordPress, l'installer e `wphc_install_release()`; solo LF; per la build strippata gzip + base64
sotto 131072 byte (limite del push), con avviso oltre il budget di 110000.

## phpcs e phpstan

Girano su `src/` e `bin/`, non sul file generato (lo strip toglie le annotazioni `phpcs:ignore`: darebbe
centinaia di falsi errori). Unica esclusione nuova: `Squiz.Commenting.FileComment.WrongStyle` su `constants.php`
e `legacy.php`, che sono tagli a byte del file 1.38.1 e iniziano con un commento di riga invece che con un
docblock di file; si toglie quando `legacy.php` sparisce e `constants.php` riceve il suo docblock.
