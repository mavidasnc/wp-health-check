# WP Health Check — Fleet Agent

Must-use plugin WordPress a file singolo per il monitoraggio e il self-update di una
flotta di siti clienti, controllati da un sistema centrale esterno e da una dashboard.

- **Runtime distribuito:** `mu-plugins/wp-health-check.php` — un unico file PHP
  autoconsistente (generato da `src/`, vedi [Sorgente, build e file
  distribuito](#sorgente-build-e-file-distribuito-dalla-1390)), senza dipendenze, compatibile **PHP 7.4+** e **WordPress 6.4+**.
- **Repository:** <https://github.com/mavidasnc/wp-health-check> (pubblico: contiene
  solo codice e chiavi *pubbliche*, mai segreti).

## Indice

1. [Architettura e scopo](#architettura-e-scopo)
2. [Tre scelte di interpretazione del brief](#tre-scelte-di-interpretazione-del-brief)
3. [Il problema del bootstrap e l'enroll firmato](#il-problema-del-bootstrap-e-lenroll-firmato)
4. [Il modello del token](#il-modello-del-token)
5. [Generazione della coppia di chiavi Ed25519](#generazione-della-coppia-di-chiavi-ed25519)
6. [Rotazione e revoca del segreto](#rotazione-e-revoca-del-segreto)
7. [Rotte REST](#rotte-rest)
8. [Autologin: aprire wp-admin già autenticati](#autologin-aprire-wp-admin-già-autenticati)
9. [Tracciamento accessi](#tracciamento-accessi)
10. [Self-update: flusso passo per passo](#self-update-flusso-passo-per-passo)
11. [Aggiornamento di plugin, temi e core via API](#aggiornamento-di-plugin-temi-e-core-via-api)
12. [Aggiornamenti bulk (core/plugin/temi) via API + WP-Cron](#aggiornamenti-bulk-coreplugintemi-via-api--wp-cron)
13. [Requisiti lato GitHub](#requisiti-lato-github)
14. [Caching per-rotta](#caching-per-rotta)
15. [CORS](#cors)
16. [Considerazioni e limiti di sicurezza](#considerazioni-e-limiti-di-sicurezza)
17. [Installazione, enroll, reset, rollback](#installazione-enroll-reset-rollback)
18. [Tab Site Health](#tab-site-health)
19. [Sviluppo locale](#sviluppo-locale)

---

## Architettura e scopo

Il plugin espone, sotto il namespace REST `health-check/v1`, un piccolo set di rotte
che permettono a un sistema centrale esterno di:

- registrare (*enroll*) un sito nella flotta, ottenendo un token di accesso;
- interrogare un sommario di salute economico e ad alta frequenza (`/health`),
  o un heartbeat ancora più leggero per il solo uptime/latenza (`/ping`,
  dalla 1.26.0);
- interrogare dettagli più costosi, on demand (`/detail/plugins`, `/detail/theme`,
  `/detail/server`, `/detail/users`);
- aggiornare il plugin stesso da una release GitHub firmata (`/update`);
- aggiornare, dietro consenso esplicito per sito, un singolo plugin/tema/il core del
  sito da wordpress.org (`/update/plugin`, `/update/theme`, `/update/core`), con
  storico consultabile via `/update/log` e riconciliazione a posteriori dello stato
  attivo dei plugin via `/update/reactivate`;
- aprire `wp-admin`, con un click dalla dashboard, già autenticati come un
  amministratore del sito (`/autologin/token`, vedi
  [Autologin](#autologin-aprire-wp-admin-già-autenticati)).

Il file viene installato **una volta sola**, a mano, via SFTP/SSH, in
`wp-content/mu-plugins/wp-health-check.php`. Da quel momento è identico su tutti i
siti della flotta e può aggiornarsi da solo, ma solo quando il sistema centrale lo
richiede esplicitamente chiamando `/update` — non c'è nessun cron di auto-update
lato sito.

**Vincolo architetturale fondamentale:** il plugin non scrive mai in
`wp-config.php`. La configurazione non segreta (versione, coordinate del repo
GitHub, chiave pubblica del centro) è hardcoded come costanti PHP nel file stesso —
è lo stesso file per l'intera flotta, distribuito via GitHub, quindi quelle
costanti sono pubbliche de facto. Tutto ciò che è specifico del singolo sito (token,
origin della dashboard, timestamp/IP dell'ultimo accesso) vive nelle `wp_options` di
quel sito. **Nessun segreto risiede mai sul sito**: la chiave incorporata nel file è
una chiave *pubblica* Ed25519, utile solo a *verificare* firme, non a produrle.

Perché mu-plugins e non un plugin normale? Perché deve essere sempre attivo, non
disattivabile per errore da un amministratore del sito, e caricato il prima
possibile. WordPress carica automaticamente **solo** i file `.php` che si trovano
nella *radice* di `wp-content/mu-plugins/` (non nelle sue sottocartelle): è per
questo che il plugin distribuito deve restare un file singolo autoconsistente, senza
autoload PSR-4 a runtime (dalla 1.39.0 il sorgente sta in `src/` e il file è generato).

### Sorgente, build e file distribuito (dalla 1.39.0)

Il file che arriva sui siti è sempre UNO, ma dalla 1.39.0 non si scrive più a mano: è
**generato** da `bin/build.php` a partire dai file di `src/`, e quello committato in
`mu-plugins/` è la build con strip, cioè il file che si firma e si pubblica. Il comportamento esterno è
identico a quello della 1.38.1 (stesse rotte, stessi corpi di risposta, stesse option,
stessi hook nello stesso ordine): è stata una riorganizzazione interna, con i bug noti
preservati di proposito e da correggere in passi separati (elenco nella sezione 9 di
[`refactoring-classi.md`](../__docs/wp-health-check/refactoring-classi.md)). La
documentazione di dettaglio della build è in [`src/README.md`](src/README.md), quella dei
test in [`tests/README.md`](tests/README.md) e in [`tests/e2e/README.md`](tests/e2e/README.md).

```
src/
  manifest.php                  elenco ORDINATO file => ruolo (non entra nel file distribuito)
  header.php                    header del plugin (Plugin Name, Version, ...) e guardia ABSPATH
  constants.php                 costanti WP_HEALTH_CHECK_* (ognuna in `if ( ! defined() )`) e WP_HEALTH_CHECK_FILE
  class-wphc-*.php              21 classi `final` con metodi statici, una per file
  boot.php                      WPHC_Plugin::boot(): l'unica istruzione al livello del file
  class-wphc-cli-command.php    WPHC_CLI_Command (solo con WP-CLI attivo), sempre l'ultimo del manifest
```

Le classi, in ordine di dipendenza: `WPHC_Options` (nomi di option, transient e cron
come costanti), `WPHC_Util`, `WPHC_Crypto`, `WPHC_Guard` (HTTPS, throttle, nonce, firma
delle richieste), `WPHC_Auth`, `WPHC_Cors`, `WPHC_Rest` (registrazione delle rotte),
`WPHC_Enrollment` (`/enroll`, `/rotate`, `/revoke`, reset), `WPHC_Health` (`/health`,
`/ping`, `/detail/*`), `WPHC_Debug`, `WPHC_Users`, `WPHC_Thumbnail`, `WPHC_Autologin`,
`WPHC_Update_Log`, `WPHC_Self_Updater`, `WPHC_Item_Updater`, `WPHC_Bulk`, `WPHC_Webhook`,
`WPHC_Admin` e `WPHC_Admin_View` (tab Site Health) e `WPHC_Plugin` (registra tutti gli
hook in `boot()`). Ogni vecchia funzione `wphc_*()` ha un metodo corrispondente: la
mappa completa, usata anche dai test, è `tests/refactor-map.php`. Il plugin non dichiara
più alcuna funzione globale `wphc_*` (nessun wrapper di compatibilità): il codice di
terzi che chiamava una di quelle funzioni, o che faceva `remove_action()` indicando il
nome della funzione come stringa, non funziona più. Restano invariati i nomi che sono
stringhe persistite o pubbliche: option e transient, hook cron (`wphc_bulk_update_tick`,
`wphc_webhook_retry`), azioni `admin_post_wphc_*` e `wp_ajax_wphc_*`, query var
`wphc_autologin`, tabella `wphc_update_log`, user meta `wphc_last_login*`.

**Perché il distribuito resta un file solo.** WordPress carica solo i `.php` nella radice
di `mu-plugins/` (vedi sopra): le classi di `src/` non si caricano con un autoloader ma si
concatenano, senza `namespace`. `composer.json` non ha alcuna sezione `autoload`:
Composer serve soltanto agli strumenti di sviluppo.

**Cosa fa la build.** Concatena i file nell'ordine del manifest (toglie il `<?php`
iniziale dai file successivi al primo, senza separatori) e si rifiuta di scrivere se un
controllo fallisce: parse completo del risultato, una sola classe per file (e nessun altro
codice al livello del file, salvo `header`, `constants`, `boot` e il blocco WP-CLI), nessuna
classe che estenda una classe dichiarata più avanti, versione dell'header uguale a
`WP_HEALTH_CHECK_VERSION`, `__FILE__` e `__DIR__` ammessi solo in `constants.php`
(`__CLASS__`, `__FUNCTION__`, `__METHOD__` e `__LINE__` vietati ovunque), assenza di
`?>` o HTML inline fuori dai metodi, solo LF. La build è deterministica: stesso sorgente,
stesso SHA-256.

**Strip dei commenti.** Il file da pubblicare si costruisce con lo strip: ogni commento
diventa i soli a capo che contiene, quindi la riga N del file distribuito è la riga N
della concatenazione e gli stack trace di produzione puntano al posto giusto (i numeri
sono però diversi da quelli della 1.38.1, perché le classi aggiungono righe). Si
conservano l'header e i docblock di `WPHC_CLI_Command`, che WP-CLI legge via reflection.
La build verifica che i token non commento siano identici prima e dopo. Lo strip serve al
**push dell'hub**: l'intero file viaggia nel body di `POST /update` (gzip e base64 dentro
un JSON) e il body della 1.38.1, circa 130.900 byte, stava a ridosso del limite di 128 KiB di
mod_security; lo strip lo porta a circa 60.700 byte. Il gate fallisce se il body del push
raggiunge 131072 byte e avvisa oltre i 110000 caratteri di gzip più base64. Non vanno
aggiunti codice o commenti a `src/` senza guardare i numeri che stampa la build.

**Marcatore della build di sviluppo.** La build senza strip (`composer run build:dev`)
aggiunge in coda il commento `// WPHC-BUILD: dev (non distribuibile)` e si scrive in
`build/dev/wp-health-check.php`, mai su `mu-plugins/`; la build strip non lo contiene e fallisce se lo trova, e i sorgenti non possono contenere la parola
`WPHC-BUILD`. Un file con il marcatore NON si firma e NON si pubblica mai (vedi la
procedura di release in [Requisiti lato GitHub](#requisiti-lato-github)).

**`WP_HEALTH_CHECK_FILE`.** Il self-update riscrive il file indicato da questa costante
(`WPHC_Self_Updater::install_release()`), definita in `constants.php` come le altre
(solo se non esiste già) con default `__FILE__`, cioè il mu-plugin stesso: in produzione
il comportamento è quello della 1.38.1. Si può ridefinire in `wp-config.php` (lo fanno i
test per non riscrivere il file del repository), ma sposta il bersaglio dell'update: il
file deve stare sullo stesso filesystem di `WPMU_PLUGIN_DIR` (il temporaneo si crea lì e
poi si fa `rename()`), il preflight di scrittura controlla sempre `WPMU_PLUGIN_DIR` e la
copia non deve mai essere un `.php` nella radice di `mu-plugins/`, perché WordPress la
caricherebbe al giro successivo (fatal `Cannot declare class`).

**Comandi.**

| Comando | Cosa fa |
|---|---|
| `composer run build` | build con strip: rigenera `mu-plugins/wp-health-check.php`, il file committato, quello che si firma e si pubblica |
| `composer run build:dev` | build senza strip (con il marcatore di sviluppo) in `build/dev/wp-health-check.php`, per sviluppo e debug; non scrive mai su `mu-plugins/` |
| `composer run build:check` | confronta una build strip nuova con il file committato in `mu-plugins/` (exit 1 se diversi) |
| `composer run test:build` | test della build stessa (PHP puro, senza WordPress) |
| `composer run test` | suite PHPUnit di caratterizzazione dentro wp-env (PHP 8.1 di default) |
| `composer run lint` / `composer run analyse` | phpcs e phpstan su `src/` e `bin/` (il file generato è escluso: lo strip toglie le annotazioni `phpcs:ignore`) |

**Test.** La suite PHPUnit gira in WordPress reale (container `tests-cli` di wp-env) e
fissa il comportamento esterno: test delle rotte, snapshot golden delle risposte e delle
option scritte (`tests/fixtures/golden/`), registro di tutti gli hook con priorità e
ordine, vettori di contratto con l'hub generati dal codice dell'hub. Il plugin sotto
test è una COPIA del file costruito, e il bootstrap rifiuta di partire se
`mu-plugins/` non coincide con una build strip fresca di `src/`: dopo ogni modifica a
`src/` va lanciato `composer run build` e il file rigenerato si committa insieme al sorgente. La matrice copre PHP 7.4, 8.1 e 8.3 (procedura,
runner e container usa-e-getta in [`tests/README.md`](tests/README.md)). Due insiemi
di prove sono aggiuntivi e obbligatori prima di una release: gli e2e, con un sito
WordPress vero e il codice Python vendorizzato dell'hub (`python -I tests/e2e/run_all.py`:
contratto con l'hub, comandi WP-CLI veri), e il **test di upgrade** da un sito enrollato
con la 1.38.1 al file candidato (`tests/e2e/upgrade_test.py`, con `WPHC_CANDIDATE_FILE`
per indicare il candidato). Un golden che cambia è un cambiamento di comportamento da
decidere, non da rigenerare. Il database di wp-env è uno solo: due run di PHPUnit in
parallelo si calpestano, e vanno serializzati (lock descritto in `tests/README.md`).

## Tre scelte di interpretazione del brief

Il documento di specifica conteneva alcuni punti in tensione tra loro. Le ho
risolte così, per trasparenza:

1. **PHP 7.4+ vs "PHP 8.1" nei requisiti tecnici.** Il *runtime* distribuito sui
   siti clienti resta compatibile PHP 7.4+ (richiesto esplicitamente nel ruolo
   iniziale del brief, e coerente con una flotta di hosting eterogenei che non si
   controllano). PHP 8.1 è la baseline dichiarata solo per il *tooling di sviluppo*
   di questo repository (composer, PHPStan, wp-env): `phpcs.xml.dist` verifica
   comunque la compatibilità 7.4+ del runtime con `PHPCompatibilityWP`.
2. **Autoload PSR-4 vs "file singolo autoconsistente".** Il vincolo architetturale è
   ripetuto due volte nel brief ed è motivato tecnicamente (mu-plugins carica solo i
   `.php` in radice): resta prioritario. `composer.json` non dichiara alcun
   autoload: Composer serve solo agli strumenti di sviluppo e `bin/generate-keys.php`
   è uno script autonomo. Il plugin runtime non ha classi autoloaded: dalla 1.39.0 il
   sorgente è in `src/`, ma si concatena in un file solo (vedi [Sorgente, build e file
   distribuito](#sorgente-build-e-file-distribuito-dalla-1390)).
3. **SCSS/BEM.** Il plugin è headless: espone endpoint REST, senza asset frontend
   richiesti nella specifica funzionale originale. Non ho introdotto SCSS/CSS
   speculativi senza nulla da stilare. Da `1.4.0` è stata aggiunta una tab in
   Site Health (vedi [Tab Site Health](#tab-site-health)), la prima UI del
   plugin: si appoggia solo alle classi CSS già caricate da wp-admin
   (`widefat`, `notice`, i bottoni di `submit_button()`), senza introdurre
   alcun asset proprio, perché la superficie è minima (una tabella e due
   form). Se in futuro l'interfaccia crescesse oltre questo, è il momento
   giusto per introdurre asset SCSS con metodologia BEM.

## Il problema del bootstrap e l'enroll firmato

Un sito appena installato non ha ancora un token: come glielo si consegna, se non si
può scrivere in `wp-config.php` e non c'è nessun canale sicuro preesistente?

La rotta `POST /enroll` risolve questo problema con una busta **firmata dal
centro**: il sito non deve fidarsi di *chi* gli parla, ma solo del fatto che il
messaggio porti una firma valida, verificabile con la chiave pubblica già
incorporata nel file al momento del deploy. Non serve autenticazione pregressa
perché la fiducia non viene dal canale di trasporto, ma dalla crittografia a chiave
pubblica: solo chi possiede la chiave privata del centro può produrre una firma che
la chiave pubblica incorporata riconosce come valida.

Il sito **non deriva mai il token**: non possiede il `MASTER_SECRET`. Lo riceve già
calcolato nella busta di enroll e lo conserva in `wp_health_check_token` come
valore opaco.

## Il modello del token

Per scelta di progetto originaria (**protocollo 1**), il token era **per-sito,
derivato e senza rotazione né scadenza**:

```
token = base64url( hmac_sha256( url_normalizzato, MASTER_SECRET ) )
```

- Il calcolo avviene **solo** lato sistema centrale, che è l'unico a custodire
  `MASTER_SECRET`.
- Il centro resta **stateless**: non deve archiviare alcun token, perché può
  ricalcolarlo in ogni momento a partire da un URL, deterministicamente.
- Il sito conserva il token ricevuto come stringa opaca: non lo confronta mai con
  un proprio calcolo, lo usa solo per un confronto byte-per-byte (`hash_equals`)
  con il bearer token ricevuto in ogni richiesta dati.
- **Non c'è scadenza**: il token è valido finché resta uguale al valore salvato in
  `wp_health_check_token`.

Dalla **1.30.0** questo resta il comportamento di default (`wp_health_check_protocol
= 1`), ma un sito può passare al **protocollo 2**: un segreto casuale, non più
derivabile da `MASTER_SECRET`, assegnato dal centro tramite `POST /rotate` e
ruotabile/revocabile per singolo sito senza toccare il resto della flotta. Vedi
[Rotazione e revoca del segreto](#rotazione-e-revoca-del-segreto) e anche la
sezione [Considerazioni di sicurezza](#considerazioni-e-limiti-di-sicurezza).

### `url_normalizzato`, esattamente

Il centro deve ricalcolare **byte per byte** lo stesso valore che calcola il sito
(metodo `WPHC_Util::normalize_site_url()`), quindi la normalizzazione di `home_url()` è
minima e rigida:

1. schema (`http`/`https`) in minuscolo;
2. host in minuscolo;
3. porta inclusa solo se non standard (WordPress la restituisce già così in
   `wp_parse_url()`);
4. path incluso così com'è (case-sensitive: i path di WordPress lo sono);
5. slash finale rimosso con `untrailingslashit()`.

### Esempio numerico URL → token (valori reali, verificati)

| Elemento | Valore |
|---|---|
| `home_url()` grezzo | `https://Esempio.com/blog/` |
| `url_normalizzato` | `https://esempio.com/blog` |
| `MASTER_SECRET` (solo esempio, **non usare in produzione**) | `K7xVq2mZ9pL4wRt8yUbN3cF6hJdS1oGiEaXk0nT5vM2r` |
| `hmac_sha256(url_normalizzato, MASTER_SECRET)` (hex) | `8497fa3002fdd4808a6f6e48720a5089dc477f16013ceb85c27d6b39adea40b2`[^1] |
| `token` = base64url dell'HMAC sopra | `hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI` |

[^1]: nota per chi implementa il centro: l'HMAC-SHA256 produce 32 byte (256 bit);
la rappresentazione esadecimale corretta ha quindi 64 caratteri. La codifica
base64url (RFC 4648 §5, **senza padding**, `+`→`-` e `/`→`_`) di quei 32 byte è la
stringa `token` riportata sopra — verificata con:

```php
$token = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $url_normalizzato, $master_secret, true ) ), '+/', '-_' ), '=' );
```

## Generazione della coppia di chiavi Ed25519

Sul sistema centrale, una tantum:

```bash
php bin/generate-keys.php
```

Lo script (`bin/generate-keys.php`, fuori dal plugin, gira solo lato centro) usa
`sodium_crypto_sign_keypair()` e stampa:

- la **chiave pubblica** in base64 standard, da incollare nel plugin come costante
  `WP_HEALTH_CHECK_CENTRAL_PUBKEY` prima del deploy in flotta;
- la **chiave privata** in base64 standard, da custodire **esclusivamente** lato
  centro (mai nel repository, mai su un sito, mai nei log).

Esempio di output reale (chiavi di esempio, generate per questa documentazione,
**non usare in produzione**):

```
Chiave PUBBLICA (sicura da versionare, va nel plugin):
Sv5OtU9OcTDnpndfMS0gaqiw1lcvwcqth20OGmAIN7E=

Da incollare in mu-plugins/wp-health-check.php:
    define( 'WP_HEALTH_CHECK_CENTRAL_PUBKEY', 'Sv5OtU9OcTDnpndfMS0gaqiw1lcvwcqth20OGmAIN7E=' );

Chiave PRIVATA (SEGRETA — solo lato centro)
[...]
```

Lo script stampa ancora il percorso `mu-plugins/wp-health-check.php`, ma dalla 1.39.0
quel file è generato: per cambiare la chiave si modifica `src/constants.php` e si
rigenera il file con la build (oppure si ridefinisce la costante in `wp-config.php`).

Nel codice la costante `WP_HEALTH_CHECK_CENTRAL_PUBKEY` (kid `k1`) contiene già la
chiave pubblica reale del centro (`src/constants.php`), quindi un sito appena installato
accetta le buste firmate dal centro. Se la costante fosse vuota, per esempio ridefinita
così in `wp-config.php` per un ambiente di prova, ogni tentativo di `/enroll` fallirebbe
la verifica della firma: il comportamento è volutamente *fail closed* (nessun enroll
possibile senza una chiave), non *fail open*.

La costante è il kid **"k1"** e verifica solo le buste del centro verso il sito
(`/enroll`, `/rotate`, `/revoke`); senza `kid` si assume `"k1"` (retrocompatibile con
ogni busta `/enroll` esistente). Dalla 1.35.0 (M-8 della review 2026-10-11)
`WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2` è la chiave **"k2" dedicata alle release** del
plugin: `WPHC_Crypto::verify_central_signature()` accetta `k1` solo per le buste e `k2` solo
per le release (parametro `$purpose`), quindi un `"kid": "k2"` nel body di una rotta
non fa accettare una firma prodotta con la chiave delle release, e chi custodisce
quella chiave non può forgiare buste verso i siti. La coppia delle release è custodita
fuori dall'hub e dal server; la rotazione di una delle due chiavi richiede un
rilascio del plugin con la nuova pubblica.

## Rotazione e revoca del segreto

Dalla 1.30.0 (§5.B dell'analisi di sicurezza), oltre al bootstrap firmato di
`/enroll`, il plugin espone due rotte per gestire il ciclo di vita del segreto di
un sito già registrato — stessa autenticazione di `/enroll` (firma Ed25519 del
centro verificata nel callback, non un token):

- **`POST /rotate`** consegna un segreto nuovo (casuale, non più derivato da
  `MASTER_SECRET`). Il sito sposta l'attuale in `wp_health_check_token_prev` e
  adotta il nuovo come `wp_health_check_token`, aprendo una finestra di grazia di
  `WP_HEALTH_CHECK_ROTATION_GRACE` secondi (900 di default) durante la quale
  `WPHC_Auth::require_token()` accetta **entrambi** i segreti: una rotazione non causa
  downtime per la flotta, perché il centro e il sito non devono allinearsi allo
  stesso istante esatto.
- **`POST /revoke`** cancella l'eventuale segreto precedente
  (`wp_health_check_token_prev`) e la data di rotazione, e scrive
  `wp_health_check_revoked_at`: da quel momento ogni rotta dati risponde
  `403 wphc_revoked`, indipendentemente dal token fornito. Il segreto corrente
  (`wp_health_check_token`) NON viene cancellato, come invece dicevano questa pagina e
  il docblock di `WPHC_Enrollment::route_revoke()` fino alla 1.38.1; lo stato di
  revoca si toglie solo con il reset dell'enrollment (vedi [Reset via
  WP-CLI](#reset-via-wp-cli)). Invalida anche i token di autologin già emessi e non
  ancora consumati.
- Messaggio canonico firmato, con un **prefisso di dominio** che impedisce di
  riusare una busta valida per un'operazione diversa da quella per cui è stata
  emessa (senza di esso i payload di `/rotate` e `/revoke` sarebbero
  strutturalmente confondibili, entrambi iniziano con `site_url`):
  ```
  rotate:  "rotate" \n site_url \n new_token \n nonce \n issued_at \n protocol
  revoke:  "revoke" \n site_url \n nonce \n issued_at
  ```
- Passare a queste rotte alza automaticamente il sito a `wp_health_check_protocol
  = 2`: da quel momento le rotte dati (`/health`, `/detail/*`, `/update*`,
  `/thumbnail`) pretendono anche la firma anti-replay `X-WPHC-*` — vedi
  [Considerazioni di sicurezza](#considerazioni-e-limiti-di-sicurezza).
- Il sito **non può auto-assegnarsi un segreto**: non possiede `MASTER_SECRET` né
  la chiave privata Ed25519, quindi non esiste (e non deve esistere) un pulsante di
  rotazione nella tab Site Health. La rotazione/revoca restano operazioni del
  centro.

## Rotte REST

> Per un reference tecnico compatto di tutte le rotte (parametri, esempi di
> richiesta/risposta verificati, tabelle dei campi e dei codici di errore) vedi
> [../__docs/wp-health-check/API health check.md](../__docs/wp-health-check/API%20health%20check.md). Le sezioni qui sotto restano la spiegazione
> discorsiva con il razionale di ogni scelta.

Namespace: `health-check/v1`. Tutte le rotte gestiscono il preflight `OPTIONS`
senza autenticazione, restituendo solo gli header CORS (vedi [CORS](#cors)).

### `POST /enroll` — bootstrap firmato

Nessun token richiesto: l'autenticazione è la firma Ed25519.

**Payload:**

```json
{
  "site_url": "<url_normalizzato>",
  "token": "<token>",
  "dashboard_origin": "<origin|null>",
  "issued_at": <unix_ts>,
  "signature": "<base64>"
}
```

La firma copre la concatenazione canonica, in quest'ordine esatto, con `"\n"` come
separatore:

```
site_url + "\n" + token + "\n" + dashboard_origin + "\n" + issued_at
```

Nessuno di questi campi può contenere `"\n"` per costruzione: `site_url` è un URL
HTTP valido, `token` è base64url (alfabeto `A-Za-z0-9-_`), `issued_at` è un intero
in base 10. Se `dashboard_origin` è `null` (dashboard non ancora configurata), il
suo "posto" nella concatenazione è la **stringa vuota**, non la stringa letterale
`"null"` — è una convenzione arbitraria ma univoca, e il centro deve replicarla
esattamente.

**Esempio curl completo** (valori reali e verificati matematicamente con
OpenSSL/Ed25519 — chiavi di esempio, non di produzione):

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/enroll' \
  -H 'Content-Type: application/json' \
  -d '{
    "site_url": "https://esempio.com/blog",
    "token": "hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI",
    "dashboard_origin": null,
    "issued_at": 1735689600,
    "signature": "nByXUXT7CjOZromTGSNyA5akd1/71ByYS454BwM2egSoM7upczL5AH+0i0LIk2/5Fx2hdbkjO3mWfmmmoRi6DA=="
  }'
```

Il messaggio effettivamente firmato per produrre quella `signature` (con
`WP_HEALTH_CHECK_CENTRAL_PUBKEY = 'Sv5OtU9OcTDnpndfMS0gaqiw1lcvwcqth20OGmAIN7E='`)
è, byte per byte:

```
https://esempio.com/blog\nhJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI\n\n1735689600
```

(si noti il doppio `\n\n` tra token e `issued_at`: è la rappresentazione vuota di
`dashboard_origin = null`).

**Risposte:**

| Caso | Status | Corpo |
|---|---|---|
| Campo obbligatorio mancante | `400` | `WP_Error` |
| Firma non valida | `401` | messaggio unico e generico (non distingue "firma errata" da altro) |
| `site_url` firmato non tra le varianti canoniche del sito | `403` | `WP_Error` con codice `wphc_enroll_url_mismatch`; `message` riporta l'URL atteso, e `data.expected`/`data.received` lo espongono per la diagnosi |
| Successo | `200` | `{ "enrolled": true, "site": "<site_url firmato>", "agent_version": "1.9.0" }` |

**Confronto URL tollerante.** Il `site_url` firmato non deve combaciare byte per
byte con `home_url()`: verrebbe rifiutato a torto su siti WPML (dove `home_url()`
varia per lingua), dietro reverse proxy, o con varianti www/non-www. Il sito
costruisce quindi un **set di URL canonici candidati** — `home_url()`,
`site_url()`, `network_home_url()`, `network_site_url()`, ciascuno anche nella
variante con/senza `www.` — li normalizza tutti con la stessa regola del centro,
e accetta l'enroll se il `site_url` firmato (normalizzato) è presente nel set.
`site_url()`/`network_*` non sono filtrate per lingua da WPML, quindi il set
contiene sempre l'URL base canonico anche quando `home_url()` porta un prefisso
di lingua. La verifica della firma resta **prima e obbligatoria** (`401` se
fallisce): il confronto URL è puramente un controllo di destinazione, non di
autenticazione. Il sito memorizza **esattamente** il `site_url` firmato ricevuto
(in `wp_health_check_site_url`) e lo restituisce nel campo `site`: è la chiave a
cui il centro ha legato il token e che riuserà identica.

**Protocol 1 (formato storico, sopra).** Un replay dello stesso enroll (stesso URL)
produce sempre lo stesso `token` (derivazione deterministica): riscrive lo stesso
valore, quindi è innocuo e non serve un controllo anti-replay sul nonce (dalla 1.34.0
anche la busta v1 deve però rientrare nella finestra di freschezza di `issued_at`, vedi
sotto).

**Protocol 2 (dalla 1.30.0).** Con `"protocol": 2` nel body, il payload aggiunge
`"nonce"` e la firma copre anche un prefisso di dominio (`"enroll"`) e `protocol`:

```
"enroll" + "\n" + site_url + "\n" + token + "\n" + dashboard_origin + "\n" + nonce + "\n" + issued_at + "\n" + protocol
```

Qui una finestra di freschezza su `issued_at` (`WP_HEALTH_CHECK_REPLAY_WINDOW`,
±300 secondi) e il nonce (indicizzato per hash SHA-256, stesso store usato dalle
rotte firmate `/rotate`/`/revoke` e dalla firma anti-replay delle rotte dati) **sono
obbligatori**, non più un irrobustimento opzionale come lo era per il protocol 1:
con la rotazione introdotta da questa versione, il token non è più derivato
deterministicamente, quindi una busta di enroll vecchia riprodotta potrebbe
riportare il sito a un segreto già ruotato. Vedi
[Rotazione e revoca del segreto](#rotazione-e-revoca-del-segreto).

**Niente downgrade (dalla 1.34.0, A-1 della review 2026-10-03).** Anche la busta
v1 deve rientrare nella finestra di ±300 secondi su `issued_at` (prima una busta
catturata restava valida per sempre), e un sito già al protocol 2 la rifiuta con
`409 wphc_enroll_downgrade`: senza questo controllo chi possedeva una vecchia busta
v1 (log, backup, traffico) riportava il sito al token derivato, che conosceva,
annullando la rotazione. Il controllo segue la verifica della firma, quindi lo
stato del protocollo si rivela solo a chi presenta una busta autentica. L'hub
(dalla 0.211.0) usa da sé la busta v2 per i siti al protocol 2.

**Diagnostica dell'URL mismatch (dalla `1.11.0`).** Quando l'enroll fallisce con
`wphc_enroll_url_mismatch`, oltre a registrare il dettaglio in
`wp_health_check_last_enroll_error` (visibile nella [tab Site
Health](#tab-site-health)), il plugin **invia un'email** a
`WP_HEALTH_CHECK_ALERT_EMAIL` (costante di flotta, default
`maurizio@mavida.com`; stringa vuota per disabilitare) con URL ricevuto, URL
atteso, IP, timestamp e l'elenco completo degli URL validi per l'enroll — così
l'operatore vede subito con quale URL firmare. L'email è **limitata a un invio
all'ora** per sito (transient anti-flood); l'invio usa `wp_mail()` del core.
Questo ramo è raggiungibile solo con **firma valida** (la firma è verificata
prima, `401` altrimenti), quindi l'alert non è un vettore aperto ad attaccanti
anonimi. L'email **non** viene inviata per gli altri fallimenti (firma non
valida, campo mancante): quelli restano solo in `wp_health_check_last_enroll_error`.

### `POST /rotate` — rotazione firmata del segreto (dalla 1.30.0)

Stessa autenticazione di `/enroll` (firma Ed25519, nessun token). Richiede un
enrollment già completato (`503 wphc_not_enrolled` altrimenti).

**Payload:**

```json
{
  "site_url": "<url_normalizzato>",
  "new_token": "<segreto casuale>",
  "nonce": "<hex casuale>",
  "issued_at": <unix_ts>,
  "signature": "<base64>",
  "protocol": 2
}
```

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/rotate' \
  -H 'Content-Type: application/json' \
  -d '{
    "site_url": "https://esempio.com/blog",
    "new_token": "<nuovo-segreto-casuale>",
    "nonce": "<32-hex-casuali>",
    "issued_at": 1735689600,
    "signature": "<base64>",
    "protocol": 2
  }'
```

Sposta il segreto attuale in `wp_health_check_token_prev` (finestra di grazia
`WP_HEALTH_CHECK_ROTATION_GRACE`, 900s), adotta `new_token` come
`wp_health_check_token`, alza `wp_health_check_protocol` a 2. Risposta:
`{ "rotated": true, "site": "...", "protocol": 2, "grace_seconds": 900 }`.

### `POST /revoke` — revoca firmata del segreto (dalla 1.30.0)

```json
{
  "site_url": "<url_normalizzato>",
  "nonce": "<hex casuale>",
  "issued_at": <unix_ts>,
  "signature": "<base64>"
}
```

Cancella l'eventuale `_token_prev` e `_token_rotated_at`, scrive
`wp_health_check_revoked_at` e invalida i token di autologin pendenti. **Non cancella
`wp_health_check_token`**. Da quel momento ogni rotta dati risponde `403 wphc_revoked`,
e lo stato si toglie solo con il reset dell'enrollment. Risposta:
`{ "revoked": true, "site": "..." }`.

### `GET|POST /thumbnail` — stato e rigenerazione della thumbnail (dalla 1.30.0)

Protette dal token (stesso bearer delle rotte dati). `GET` restituisce lo stato
corrente senza alcuna chiamata remota:

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/thumbnail' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{ "thumbnail": "https://esempio.com/blog/wp-content/uploads/...png", "generated_at": "2026-07-30T09:00:00+00:00", "error": null }
```

`POST` forza una rigenerazione ignorando il cooldown di un giorno, restituendo
l'esito o l'errore diagnostico strutturato (`wphc_thumb_uploads_not_writable`,
`wphc_thumb_download_failed`, `wphc_thumb_not_an_image`,
`wphc_thumb_animated_gif`, `wphc_thumb_sideload_failed`):

```json
{ "thumbnail": null, "error": { "code": "wphc_thumb_uploads_not_writable", "message": "...", "provider": "https://image.thum.io/get/noanimate/png/width/400/" } }
```

### `GET /health` — sommario economico

Protetta dal token. Pensata per polling frequente: **non chiama mai**
`WP_Debug_Data::debug_data()` né forza controlli remoti di aggiornamento (a meno di
`?fresh=1`, vedi [Caching](#caching-per-rotta)).

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/health' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

Risposta:

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-08T10:00:00+00:00",
  "fleet_agent_version": "1.0.0",
  "summary": {
    "wp_version": "6.5.3",
    "php_version": "8.1.29",
    "php_memory_limit": "256M",
    "server_ip": "203.0.113.10",
    "server_ip_is_private": false,
    "public_ip": "203.0.113.10",
    "plugin_version": "1.0.0",
    "plugins_total": 18,
    "plugins_active": 14,
    "plugins_updates": 2,
    "themes_total": 3,
    "themes_updates": 0,
    "theme_name": "Astra Child",
    "parent_theme_name": "Astra",
    "core_update": false,
    "core_auto_update": true,
    "core_auto_update_level": "minor",
    "core_auto_update_blocked_by": null,
    "plugins_auto_update_enabled": true,
    "themes_auto_update_enabled": true,
    "bulk_update": null,
    "has_gdpr": true,
    "has_builder": false,
    "has_ecommerce": false,
    "mu_dir_writable": true,
    "updates_checked_at": "2026-07-08T09:12:00+00:00"
  },
  "last_access": {
    "at": "2026-07-08T08:00:00+00:00",
    "ip": "203.0.113.7",
    "enrolled_at": "2026-01-10T09:30:00+00:00"
  },
  "detail_routes": {
    "plugins": "https://esempio.com/blog/wp-json/health-check/v1/detail/plugins",
    "theme": "https://esempio.com/blog/wp-json/health-check/v1/detail/theme",
    "server": "https://esempio.com/blog/wp-json/health-check/v1/detail/server"
  }
}
```

`last_access` espone l'accesso **precedente** a questa chiamata (letto prima di
sovrascriverlo): è un segnale di audit, "chi ha chiamato l'ultima volta prima di
ora".

**IP pubblico del server (dalla 1.29.0).** `summary.server_ip` resta
l'indirizzo letto da `SERVER_ADDR`, che dietro un reverse proxy, un load
balancer o un container può essere un indirizzo di rete interna (es.
`192.168.60.40`), inutile per identificare il server dall'esterno.
`summary.public_ip` espone invece l'IP pubblico di uscita: se `SERVER_ADDR` è
già pubblico viene riusato senza costo aggiuntivo (nessuna chiamata remota,
coerente col contratto economico di questa rotta); altrimenti si risolve una
sola volta tramite il servizio esterno [api.ipify.org](https://www.ipify.org/),
con l'esito persistito in un transient di 7 giorni (la scadenza intercetta un
eventuale cambio IP dopo una migrazione di hosting) e un cooldown di 1 giorno
sui fallimenti, per non ritentare a ogni chiamata. `summary.server_ip_is_private`
è `true` quando i due valori divergono, così la dashboard sa perché mostrarli
entrambi invece di uno solo. Il pulsante "Svuota cache e ricontrolla" nella tab
Site Health cancella anche questi transient, forzando una nuova risoluzione.

**Screenshot del sito (dalla 1.27.0, formato corretto nella 1.28.0).**
`summary.thumbnail` espone l'URL assoluto di uno screenshot PNG (larghezza
400px, altezza proporzionale) della **home pubblica** del sito
(`get_home_url()`, non l'indirizzo WordPress), generato via
[thum.io](https://thum.io/) e caricato nel Media Library. L'URL del servizio
usa le opzioni `noanimate`/`png`: senza queste, thum.io risponde in
streaming con un GIF animato (spinner + render progressivo, pensato per
essere mostrato dal vivo in una pagina, non salvato come file). La
generazione avviene **una sola volta**, alla prima `/health` con l'opzione
`wp_health_check_thumb` vuota; le chiamate successive leggono solo
quell'opzione (O(1), coerente col contratto economico della rotta). Se
thum.io non risponde (o restituisce ancora un GIF), un transient di cooldown
(`wphc_thumb_retry_lock`, 1 giorno) evita che ogni `/health` successiva
ritenti la chiamata remota: `summary.thumbnail` resta `null` fino al
prossimo tentativo. Nella tab Site Health un pulsante "Elimina e rigenera"
cancella l'attachment dal Media Library e le opzioni collegate, forzando
una nuova generazione alla `/health` successiva.

**Stato di auto-update nativo di WordPress (dalla 1.31.0).**
`summary.core_auto_update`/`core_auto_update_level`/`core_auto_update_blocked_by`
riflettono se il *core* si aggiorna da solo, derivati replicando l'ordine di
precedenza del core stesso (`wp_is_file_mod_allowed()` → costante
`AUTOMATIC_UPDATER_DISABLED` → opzioni `auto_update_core_{dev,minor,major}` →
costante `WP_AUTO_UPDATE_CORE` → filtri `allow_{minor,major,dev}_auto_core_updates`),
tutte letture O(1) senza chiamate remote né accesso al filesystem. `level` è
uno tra `none`/`minor`/`major`/`all` (major e minor sono indipendenti: la
combinazione minor-off/major-on esiste ed è riportata come `major`, non
collassata su `all`). Se un sito ha un checkout VCS (`.git`/`.svn`) il core
rifiuterebbe comunque l'auto-update indipendentemente da questi flag: questo
caso non è rilevato qui (richiederebbe una scansione filesystem incompatibile
col contratto O(1) della rotta). `plugins_auto_update_enabled`/
`themes_auto_update_enabled` riflettono invece il gate *globale* per plugin e
temi (`wp_is_auto_update_enabled_for_type()`, corretto per questi due tipi a
differenza del core); lo stato *per singolo elemento* è in `/detail/plugins`
e `/detail/theme`, vedi sotto.

**Stato del job di aggiornamento bulk (dalla 1.31.0).** `summary.bulk_update`
è `null` se non è mai stato accodato un job, altrimenti il riassunto O(1)
dell'ultimo/corrente job di `POST /update/bulk` (vedi
[Aggiornamenti bulk (core/plugin/temi) via API + WP-Cron](#aggiornamenti-bulk-coreplugintemi-via-api--wp-cron)):
`job_id`, `status`, contatori, `stalled` (derivato a lettura, nessuna
scrittura lato sito). Il dettaglio completo, elemento per elemento, è su
`GET /update/bulk`.

**`summary.is_multisite` e `summary.comments_pending` (dalla 1.32.0).** Il
primo è `is_multisite()` a costo zero; il secondo è il numero di commenti in
attesa di moderazione del solo sito arruolato (`wp_count_comments()->moderated`),
una query indicizzata assorbita dalla stessa micro-cache di 60s del resto del
payload — nessun costo aggiuntivo sul vincolo "`/health` deve restare
economico" (vedi sotto).

### `GET /ping` — heartbeat leggero (dalla 1.26.0)

`/health` è già pensata per il polling frequente, ma **non è un ping puro**:
anche a cache calda scrive sempre `wp_health_check_last_request_*` (audit
accessi), e a cache fredda — che per un monitoraggio esterno a cadenza di
minuti è di fatto sempre, visto che il transient dura solo 60s — esegue
`get_plugins()`/`wp_get_themes()`, le uniche due operazioni della rotta con
una vera scansione di filesystem (un file aperto e parsato per ogni
plugin/tema installato).

`/ping` esiste per un caso d'uso diverso e più ristretto: un servizio di
monitoraggio esterno che misura solo **raggiungibilità e tempo di risposta**
a frequenza alta (es. ogni 15-20 minuti), per cui il sommario completo di
`/health` sarebbe uno spreco. Non chiama mai `get_plugins()`/`wp_get_themes()`,
non scrive mai su `wp_options`, non usa alcuna cache (il suo scopo è proprio
misurare il tempo di *questa* chiamata).

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/ping' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "status": "ok",
  "site": "https://esempio.com/blog",
  "agent_version": "1.26.0",
  "generated_at": "2026-07-22T10:00:00+00:00"
}
```

Non sostituisce `/health` (che resta l'unica fonte del sommario: versioni,
conteggi plugin/temi, aggiornamenti disponibili): è complementare, per un
probe più frequente di sola raggiungibilità/latenza.

### `GET /detail/plugins` — elenco completo plugin

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/detail/plugins' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-08T10:00:00+00:00",
  "count": 2,
  "auto_update_enabled_globally": true,
  "plugins": [
    {
      "name": "Akismet Anti-spam",
      "slug": "akismet",
      "file": "akismet/akismet.php",
      "version": "5.3.2",
      "active": true,
      "update_available": false,
      "new_version": null,
      "auto_update": true,
      "auto_update_forced": null
    },
    {
      "name": "WooCommerce",
      "slug": "woocommerce",
      "file": "woocommerce/woocommerce.php",
      "version": "8.9.1",
      "active": true,
      "update_available": true,
      "new_version": "8.9.3",
      "auto_update": false,
      "auto_update_forced": null
    }
  ]
}
```

Il campo `file` (dalla `1.19.0`) è il plugin file, chiave di `get_plugins()`:
è il valore esatto da passare come `plugin` a
[`POST /update/plugin`](#post-updateplugin-post-updatetheme), a differenza di
`slug` (solo la cartella, non univoco per i plugin a file singolo nella
radice di `wp-content/plugins/`).

**Auto-update per plugin (dalla 1.31.0).** `auto_update` riflette lo stato
effettivo (letto da `get_site_option('auto_update_plugins')`, sempre
`get_site_option()` e mai `get_option()`: su multisite è un'opzione di rete
condivisa, e `get_option()` risulterebbe sempre vuota). `auto_update_forced`
distingue: `null` significa che si applica la scelta salvata dall'admin;
`true`/`false` significa che un filtro di terze parti impone lo stato
indipendentemente dalla option (la UI nativa di WordPress in quel caso non
mostra alcun toggle) — informazione che serve a capire se un'azione di
remediation è anche solo possibile. `auto_update_enabled_globally` è il gate
generale (`wp_is_auto_update_enabled_for_type('plugin')`): se spento, ogni
`auto_update: true` per singolo elemento è moot. Le cache 1h di questa rotta
vengono invalidate automaticamente quando l'admin cambia le impostazioni di
auto-update dalla lista plugin nativa di WordPress.

### `GET /detail/theme` — tema attivo e parent

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/detail/theme' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-08T10:00:00+00:00",
  "auto_update_enabled_globally": true,
  "active_theme": {
    "name": "Astra Child",
    "stylesheet": "astra-child",
    "version": "1.2.0",
    "update_available": false,
    "new_version": null,
    "auto_update": false,
    "auto_update_forced": null
  },
  "parent_theme": {
    "name": "Astra",
    "version": "4.6.1",
    "auto_update": false,
    "auto_update_forced": null
  },
  "themes": [
    {
      "name": "Astra Child",
      "stylesheet": "astra-child",
      "version": "1.2.0",
      "active": true,
      "parent": "astra",
      "update_available": false,
      "new_version": null,
      "auto_update": false,
      "auto_update_forced": null
    },
    {
      "name": "Astra",
      "stylesheet": "astra",
      "version": "4.6.1",
      "active": false,
      "parent": null,
      "update_available": true,
      "new_version": "4.6.5",
      "auto_update": false,
      "auto_update_forced": null
    }
  ]
}
```

L'array `themes` elenca **tutti** i temi installati sul sito (non solo l'attivo).
`parent` è lo `stylesheet` del tema parent per i child theme, altrimenti `null`.
I campi `active_theme`/`parent_theme` restano invariati per retrocompatibilità.
`auto_update`/`auto_update_forced` (dalla 1.31.0) seguono la stessa
convenzione di `/detail/plugins` qui sopra, applicata anche al tema parent
(un child theme non eredita lo stato di auto-update del parent: sono due
entry indipendenti in `auto_update_themes`).

### `GET /detail/server` — ambiente server/PHP/database

Unica rotta potenzialmente lenta, isolata: cache 12h, mai chiamata dal polling.

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/detail/server' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-08T10:00:00+00:00",
  "server": {
    "software": "nginx/1.24.0",
    "server_ip": "203.0.113.10",
    "server_ip_is_private": false,
    "public_ip": "203.0.113.10",
    "php_version": "8.1.29",
    "php_sapi": "fpm-fcgi",
    "php_memory_limit": "256M",
    "max_execution_time": "60",
    "max_input_vars": "3000",
    "upload_max_filesize": "64M",
    "post_max_size": "64M",
    "mysql_version": "8.0.36",
    "https": true,
    "extensions": {
      "curl": true,
      "imagick": true,
      "gd": true,
      "mbstring": true,
      "intl": true
    }
  }
}
```

Fonte dichiarata nel brief: `WP_Debug_Data::debug_data()`, sezioni `wp-server` e
`wp-database`. In pratica il plugin usa quella fonte solo per `software` (nome
software server) e `mysql_version`, con fallback nativi (`$_SERVER['SERVER_SOFTWARE']`,
`$wpdb->db_version()`); i valori di configurazione PHP (`memory_limit`,
`max_execution_time`, ecc.) sono letti direttamente con `ini_get()`, perché
`debug_data()` li restituisce già formattati per un umano (es. `"On (64M)"` per gli
upload) e non sono pensati per essere ri-parsati programmaticamente. Il payload è
costruito con un **allowlist esplicito** di campi: i campi marcati `private` da
`WP_Debug_Data` (utente e host del database) non possono finire nella risposta per
costruzione, indipendentemente da come una futura versione del core li chiami.

`server_ip_is_private` e `public_ip` (dalla 1.29.0) hanno la stessa semantica
di `summary.server_ip_is_private`/`summary.public_ip` di `GET /health`: vedi
la sezione "IP pubblico del server" sopra.

### `GET /detail/users` — amministratori del sito

Elenco degli utenti con ruolo `administrator`, per censire dalla dashboard
centrale chi ha accesso pieno al sito. Su multisite include anche i super
admin di rete (deduplicati per `user_login`), che possono avere accesso pieno
senza il ruolo administrator sul singolo blog.

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/detail/users' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-19T08:00:00+00:00",
  "count": 1,
  "users": [
    {
      "id": 1,
      "user_login": "admin",
      "display_name": "Amministratore",
      "email": "admin@esempio.com",
      "registered": "2025-03-10T09:00:00+00:00",
      "last_login": "2026-07-27T07:45:00+00:00",
      "last_login_ip": "203.0.113.7"
    }
  ]
}
```

Nessuna cache transient: a differenza di `/detail/plugins` e `/detail/theme`,
è un dato anagrafico interrogato raramente.

**`registered`, `last_login`, `last_login_ip` (dalla 1.29.0).** `registered`
è `user_registered` (già presente in `wp_users`, nessun costo aggiuntivo).
`last_login`/`last_login_ip` vengono scritti in due user meta
(`wphc_last_login`, `wphc_last_login_ip`) sull'azione `wp_login`; l'IP usa
la stessa fonte di `WPHC_Util::get_client_ip()` già usata per la colonna `ip`
della [tabella di log](#tracciamento-accessi), quindi rispetta
`wp_health_check_trust_proxy`. `last_login` resta `null` finché un
amministratore non effettua un accesso **dopo** l'aggiornamento a questa
versione dell'agent: non va interpretato come "account dormiente" prima che
sia passato abbastanza tempo da escludere semplicemente questo caso.
Deliberatamente **nessuna riga** viene scritta nella tabella di log per un
login ordinario (a differenza dell'audit dell'autologin, `type` =
`token`/`login`): su un sito con accessi frequenti gonfierebbe la tabella
senza aggiungere nulla rispetto alla user meta.

### `POST /update` — self-update da GitHub

Vedi la sezione dedicata [Self-update](#self-update-flusso-passo-per-passo).

Protetta dal token, come `/health` e `/detail/*`.

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/update' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

Risposte possibili:

```json
{ "updated": false, "reason": "up_to_date", "current": "1.0.0", "latest": "1.0.0" }
```
```json
{ "updated": false, "reason": "not_writable" }
```
```json
{ "updated": false, "reason": "integrity_check_failed" }
```
```json
{ "updated": true, "from": "1.34.0", "to": "1.35.0", "signature": "verified" }
```

`signature` (dalla 1.34.0) vale `verified` quando l'aggiornamento riesce: dalla
1.35.0 la firma della release è obbligatoria, quindi lo stato `missing` non esiste
più (una release senza `.sig` risponde `integrity_check_failed`; vedi il passo 6 del
[flusso di self-update](#self-update-flusso-passo-per-passo)).

### `POST /update/plugin`, `POST /update/theme`, `POST /update/core` — aggiornamento software di terze parti

Vedi la sezione dedicata [Aggiornamento di plugin, temi e core via
API](#aggiornamento-di-plugin-temi-e-core-via-api) per il flusso completo. In
sintesi: protette dal token **e** da un kill-switch per sito (spento di
default), aggiornano un singolo plugin/tema/il core esclusivamente da
wordpress.org, mai da una sorgente o versione indicata dal chiamante.

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/update/plugin' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI' \
  -H 'Content-Type: application/json' \
  -d '{ "plugin": "akismet/akismet.php" }'
```

```json
{ "updated": true, "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet", "from": "5.3.2", "to": "5.3.4", "log_id": 1287 }
```

`POST /update/theme` è identica con `{ "theme": "<stylesheet>" }` al posto di
`plugin`. `POST /update/core` non richiede alcun campo nel payload: la
versione target è sempre quella che WordPress stesso ha determinato
disponibile. Tutte e tre accettano `?check=1` per un dry-run (verifica se
l'elemento è aggiornabile, senza eseguire nulla).

### `GET /update/log` — storico degli aggiornamenti

Sola lettura, paginata, **sempre accessibile anche a kill-switch spento**
(protetta solo dal bearer token, come le altre rotte dati). Dalla 1.29.0
accetta anche il filtro `?source=` (`api` | `wp-admin` | `cron` | `wp-cli`,
combinabile con `?type=`), speculare a quello già esistente per `type`.

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/update/log?type=plugin&source=wp-admin&limit=50' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "count": 1,
  "total": 137,
  "entries": [
    {
      "id": 1287, "correlation_id": "a1b2c3d4e5f60718", "created_at": "2026-07-14T10:00:00+00:00",
      "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet",
      "version_from": "5.3.2", "version_to": "5.3.4",
      "phase": "requested", "message": null, "ip": "203.0.113.7",
      "source": "api", "actor": null
    }
  ]
}
```

**`source`/`actor` (dalla 1.29.0).** `source` distingue chi ha avviato
l'operazione: `api` (la stessa rotta REST di update di questo agent),
`wp-admin` (bacheca di WordPress), `cron` (un auto-update in background) o
`wp-cli`. `actor` è lo `user_login` di chi ha agito da wp-admin, `null`
altrove. Le righe scritte prima della 1.29.0 hanno `source = "api"` per
costruzione (valore di default applicato da `dbDelta()` a tutte le righe
storiche) e `actor = null`. Per plugin e temi la rilevazione si aggancia
agli hook `upgrader_pre_install`/`upgrader_process_complete` del core (un
flag di richiesta esclude gli update già loggati dal flusso API, evitando
il doppio log); per il core, che non offre un hook altrettanto affidabile,
si rileva una divergenza fra la versione osservata e l'ultima vista — che
intercetta anche un aggiornamento core fatto via FTP o dal pannello
dell'hosting, non passato da nessun hook di WordPress. A differenza del
pattern a due righe (`requested` + `completed`/`failed`/`rolled_back`) del
flusso API — che prova che un update è stato *avviato* anche se PHP muore a
metà — questi aggiornamenti sono loggati con una sola riga `completed`,
perché l'hook scatta solo a esito già riuscito.

### `POST /update/reactivate` — riconciliazione dello stato attivo dei plugin

La rete di sicurezza già presente in `POST /update/plugin` tenta una
riattivazione immediata subito dopo un update, ma non copre il caso — visto
su alcuni siti clienti — in cui un plugin resta disattivato per un motivo
esterno (plugin di sicurezza/hosting, ritardo del filesystem su hosting
condivisi...) non legato all'update stesso. Questa rotta rileva la
discrepanza a posteriori, confrontando l'ultimo stato "atteso attivo"
registrato in `GET /update/log` con lo stato reale corrente, e tenta la
riattivazione registrando **sempre** una riga di log per il tentativo.

Protetta dal token; l'esecuzione reale richiede inoltre il kill-switch e il
lock anti-concorrenza condivisi con le altre rotte di update. Il dry-run
(`?check=1`) resta invece sola lettura.

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/update/reactivate' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-19T08:05:10+00:00",
  "check": false,
  "discrepancies": 1,
  "reactivated": 1,
  "failed": 0,
  "results": [
    { "target": "akismet/akismet.php", "name": "Akismet", "was_active": true, "currently_active": true, "reactivated": true, "log_id": 1305 }
  ]
}
```

Dettaglio importante: su un tentativo fallito la riga di log viene scritta
con `active = NULL` (non `false`), perché scrivere `false` la renderebbe la
riga più recente e farebbe smettere di rilevare la discrepanza alla chiamata
successiva — vanificando proprio il ritentativo che la rotta esiste per
offrire. Vedi il dettaglio completo in
[../__docs/wp-health-check/API health check.md](../__docs/wp-health-check/API%20health%20check.md#post-updatereactivate).

### `POST /autologin/token` — genera un token one-time di autologin

Protetta da `manage_options` (tipicamente via Application Password), **non**
dal bearer token: vedi la sezione dedicata [Autologin](#autologin-aprire-wp-admin-già-autenticati)
per il razionale completo e il flusso a due passi.

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/autologin/token' \
  -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx'
```

```json
{
  "autologin_url": "https://esempio.com/blog/?wphc_autologin=3f9c2e...b71a",
  "expires_in": 20,
  "user_id": 1,
  "user_login": "admin"
}
```

## Autologin: aprire wp-admin già autenticati

### Perché le Application Password da sole non bastano

Le Application Password di WordPress autenticano **una singola chiamata
REST** (Basic Auth): non chiamano mai `wp_set_auth_cookie()` e non creano
quindi una sessione a cookie navigabile nel browser. Se si apre `wp-admin`
nel browser con un'application password in mano, non succede nulla: quella
pagina si aspetta un cookie di sessione, non un header `Authorization`. Per
far atterrare un click della dashboard dentro `wp-admin` già autenticato
serve quindi un meccanismo diverso, il classico pattern **magic-link**, a due
passi separati.

### Passo 1 — generazione del token (`POST /autologin/token`)

La dashboard chiama `POST /autologin/token` autenticandosi con
un'Application Password dell'amministratore del sito (stesso schema di `GET
/debug`, gated su `manage_options`, non sul bearer token wphc). Il vantaggio
di questo schema è che **l'identità è già risolta dal core di WordPress**
prima ancora che il callback della rotta venga eseguito: non c'è alcuna
tabella di mapping "utente dashboard → utente WP" da mantenere in questo
plugin, l'utente che finirà autenticato in `wp-admin` è esattamente quello
i cui credenziali (Application Password) hanno superato l'autenticazione
REST nativa.

Il callback (`WPHC_Autologin::route_token()`):

1. legge `wp_get_current_user()`, già popolato dal core;
2. genera un token casuale a 256 bit (`random_bytes( 32 )`, CSPRNG — mai
   `rand()`/`uniqid()`);
3. salva un transient con **chiave** l'hash SHA-256 del token (mai il token
   in chiaro) e **valore** un array `{ user_id, correlation_id }`, con TTL
   `WP_HEALTH_CHECK_AUTOLOGIN_TTL` (20 secondi di default) — il
   `correlation_id` permette di collegare la riga di log di questo passo a
   quella scritta al passo 2, quando il token viene consumato;
4. registra un audit minimo in `wp_health_check_last_autologin` (user, IP,
   timestamp) — nessuna tabella dedicata, solo l'ultima richiesta, stesso
   spirito di `WPHC_Auth::record_access()`;
5. **dall'agent 1.25.0**, scrive anche una riga `type: "token"` nella tabella
   di log (`wphc_update_log`, la stessa di `GET /update/log`): `target` è lo
   `user_login`, `name` il `display_name` (fallback `user_login`) — questa
   sì è uno storico consultabile, non un singolo valore sovrascritto;
6. risponde con `autologin_url`, pronto per essere aperto nel browser.

### Passo 2 — consumo del token, fuori dalla REST API

`autologin_url` punta all'home del sito con `?wphc_autologin=<token>`, **non**
a un'altra rotta REST: viene intercettato da `WPHC_Autologin::maybe_consume()`,
agganciata su `init`, prima che qualunque output sia inviato. Questa scelta
(anziché una rotta REST che risponda con un redirect) evita ogni rischio
"headers already sent" nel chiamare `wp_set_auth_cookie()`, e separa con
chiarezza i due mondi del plugin: REST API per chiamate machine-to-machine
con header di autenticazione, navigazione diretta del browser per tutto ciò
che deve impostare un cookie di sessione.

Il flusso, fail-closed su qualunque anomalia (stesso principio di
`WPHC_Auth::require_token()`, nessun dettaglio sul motivo nella risposta):

1. token assente nella query string → nessuna azione (richiesta normale);
2. token presente: lookup del transient per hash, poi **cancellazione
   immediata** (consumo single-use, prima di procedere) — un secondo
   tentativo con lo stesso token trova sempre il transient già scaduto;
3. transient assente/scaduto/già consumato → redirect silenzioso a
   `wp_login_url()`; **dall'agent 1.25.0**, scrive comunque una riga
   `type: "login"`/`phase: "failed"` nel log, con `target` = prefisso
   dell'hash del token (nessuna identità utente è recuperabile a questo
   punto, ma tentativi ripetuti con lo stesso token restano riconoscibili
   senza esporlo in chiaro) e un `correlation_id` nuovo, non collegato ad
   alcuna riga `token` precedente;
4. utente associato non più esistente (cancellato nella finestra dei 20s) →
   stesso redirect; riga `type: "login"`/`phase: "failed"`, stavolta con lo
   stesso `correlation_id` della riga `token` (recuperato dal transient) e
   `target` = user_id (non si dispone più dello `user_login`);
5. token valido → `wp_set_current_user()` + `wp_set_auth_cookie( $user_id, false )`
   (cookie di sessione dalla 1.34.0: prima `true`, cioè 14 giorni)
   + `do_action( 'wp_login', ... )` (così i plugin di audit/2FA che si
   aspettano l'hook di login scattano anche sul magic-login), poi riga
   `type: "login"`/`phase: "completed"` con lo stesso `correlation_id` della
   riga `token` → redirect a `admin_url()`, **fisso**: nessun parametro di
   destinazione accettato dal chiamante, per non introdurre un open redirect.

### Considerazioni di sicurezza specifiche

- **TTL cortissimo (20s) + single-use**: la finestra di replay è
  trascurabile e si chiude comunque a zero dopo il primo utilizzo, anche se
  il token venisse intercettato.
- **Nessun binding all'IP**: la generazione arriva server-to-server dalla
  dashboard (un IP), il consumo dal browser dell'utente (un IP diverso);
  legare il token all'IP romperebbe il flusso per costruzione. Non è
  un'omissione, è una scelta di progetto da non "correggere" in futuro senza
  ripensare l'intero schema.
- **Il token viaggia in query string** (log di accesso del server,
  cronologia del browser): un compromesso accettato, mitigato dal TTL
  cortissimo e dal consumo single-use, sullo stesso spirito del token di
  enroll che viaggia in chiaro nel payload (vedi [Considerazioni di
  sicurezza](#considerazioni-e-limiti-di-sicurezza)).
- **La credenziale che genera il token (Application Password) autentica un
  amministratore su tutta la REST API di WordPress**, non solo su questa
  rotta: va custodita lato dashboard con la stessa cura di una password
  reale (cifrata a riposo, mai nei log). Diversamente dal token wphc — che
  da accesso solo al perimetro di queste rotte — un'Application Password
  rubata vale quanto le credenziali complete dell'amministratore lato REST
  API.
- **Redirect di destinazione fisso** (`admin_url()`): deliberatamente non
  configurabile dal chiamante, per non introdurre una superficie di open
  redirect.
- **Throttle e HTTPS (dalla 1.34.0, A-10 della review 2026-10-03)**: il
  consumo passa da `WPHC_Guard::throttle_check()` come le rotte autenticate, e ogni
  token sconosciuto conta come tentativo fallito, quindi una raffica di GET
  anonime non gonfia più la tabella di log; oltre la soglia o in HTTP si va
  al login senza leggere il token. `POST /autologin/token` richiede HTTPS.
- **Sessione, non "Ricordami"**: il cookie impostato dal consumo è di
  sessione (`remember = false`), così un token da 20 secondi non diventa una
  sessione amministrativa di due settimane.

## Tracciamento accessi

A ogni richiesta dati autenticata con successo (`/health`, `/detail/*`, `/update`)
il plugin registra:

- `wp_health_check_last_request_at`: timestamp ISO 8601;
- `wp_health_check_last_request_ip`: IP del chiamante.

L'ordine è importante: il valore **precedente** viene letto prima di essere
sovrascritto, così `/health` può esporlo come segnale di audit nel corpo della
risposta, e solo dopo viene scritto il valore corrente. Il tracciamento avviene ad
ogni chiamata reale, anche quando il resto del corpo di `/health` è servito dalla
micro-cache: altrimenti, con un polling più frequente del TTL di cache, l'audit
perderebbe la maggior parte delle chiamate davvero ricevute.

**Determinazione dell'IP e caveat proxy:** per default si usa
`$_SERVER['REMOTE_ADDR']`, validato con `filter_var( $ip, FILTER_VALIDATE_IP )`.
Se il sito è dietro un proxy/CDN fidato che sovrascrive sempre l'header, si può
attivare l'opzione `wp_health_check_trust_proxy` (va impostata manualmente, non è
esposta da nessuna rotta di questo plugin): in quel caso l'IP viene letto
dall'**ultimo** valore valido in `X-Forwarded-For` (dalla 1.34.0: un proxy accoda
l'indirizzo del proprio client in fondo, mentre i valori a sinistra li sceglie il
client; con il primo valore il throttle era aggirabile cambiando l'header). Con
la stessa opzione, `WPHC_Guard::require_https()` accetta una richiesta che PHP vede in
HTTP solo se il proxy dichiara `X-Forwarded-Proto: https` (fino alla 1.33.0
l'opzione saltava del tutto il controllo). **`X-Forwarded-For` è un header fornito dal
client e quindi falsificabile a piacere**: è attendibile solo se un proxy fidato lo
sovrascrive sempre prima che la richiesta raggiunga PHP. Va attivato
consapevolmente, mai per default.

## Self-update: flusso passo per passo

Innescato da `POST /update` o dal pulsante "Aggiorna il plugin" nella [tab Site
Health](#tab-site-health) (entrambi usano il metodo condiviso
`WPHC_Self_Updater::perform_self_update()`), mai da un cron lato sito. Ordine rigoroso, ogni
passo che fallisce interrompe il flusso **prima** di toccare il file di produzione:

1. **Autenticazione** via token (già garantita dal `permission_callback`) e
   registrazione dell'accesso.
2. **Interrogazione della release** (`WPHC_Self_Updater::fetch_latest_release()`). Dalla 1.38.0 la
   prima fonte è l'**hub** (`WP_HEALTH_CHECK_RELEASE_URL`, default
   `https://hub.mavida.com/api/v1/fleet/agent-release/latest`): l'hub interroga GitHub
   una sola volta e serve la release alla flotta, così i siti sugli hosting condivisi non
   consumano il limite dell'API per IP. La risposta ha la forma dell'API GitHub
   (`tag_name`, `assets[]`); il tag deve avere la forma di una versione e gli asset
   sono accettati solo se l'URL sta sotto lo stesso percorso dell'URL dell'hub. Se l'hub
   non risponde o la risposta non è valida si passa a GitHub; se invece gli asset
   dell'hub non sono scaricabili si ritenta una volta con la release di GitHub. L'hub
   è solo trasporto, non un perimetro di fiducia: SHA-256 e firma `k2` (punto 6) sono
   verificati su qualunque fonte. Fonte GitHub:
   `GET https://api.github.com/repos/{owner}/{repo}/releases/latest`, con header
   `Accept: application/vnd.github+json` e `User-Agent: wp-health-check-agent/<versione>`
   (GitHub rifiuta con 403 le richieste API senza User-Agent: dalla 1.37.0 lo si
   imposta esplicitamente anche sul download degli asset). Timeout 15s. Se l'API
   risponde con un errore o non è raggiungibile, dalla 1.37.0 si ripiega su
   `https://github.com/{owner}/{repo}/releases/latest`: il redirect (senza seguirlo)
   indica il tag e gli asset hanno URL prevedibili
   (`/releases/download/<tag>/<nome>`). Serve perché l'API non autenticata concede 60
   richieste l'ora per indirizzo IP e sugli hosting condivisi un aggiornamento di massa
   la esaurisce; github.com non ha quel limite. Se anche il ripiego fallisce, errore di
   rete (`wphc_update_network_error`, con l'errore di trasporto nel messaggio) o status
   diverso da 200 (`wphc_update_github_error`, con il codice HTTP nel messaggio):
   `WP_Error` con status 502.
3. **Normalizzazione e confronto versione**: il `tag_name` della release perde un
   eventuale prefisso `v`, poi si confronta con `WP_HEALTH_CHECK_VERSION` via
   `version_compare()`. Se non è più recente: `200 { "updated": false, "reason": "up_to_date", ... }`.
4. **Preflight di scrittura** (`WPHC_Self_Updater::probe_mu_dir_writable()`, eseguito prima di
   scaricare qualunque cosa): si scrive e si rimuove un file temporaneo a nome casuale in
   `WPMU_PLUGIN_DIR` (es. `.wphc-writetest-<rand>`). Se fallisce:
   `200 { "updated": false, "reason": "not_writable" }`, senza aver toccato altro.
5. **Download**: si cerca fra gli `assets` della release quello di nome
   `wp-health-check.php` e si scarica da `browser_download_url`. Timeout 30s.
6. **Verifica di integrità**, prima di toccare qualunque file di produzione:
   - hash atteso recuperato dall'asset affiancato `wp-health-check.php.sha256`
     (supporta sia il formato `sha256sum`, `"<hash>  <nomefile>"`, sia il solo
     hash); dalla 1.34.0 non c'è più il ripiego sulla riga `"sha256: <hash>"`
     nel corpo della release;
   - confronto con `hash('sha256', $contenuto)` via `hash_equals()` (tempo
     costante);
   - il contenuto non deve essere vuoto, deve iniziare con `<?php` e deve
     contenere `"Version: <tag>"` coerente col tag scaricato.
   - **firma del centro (dalla 1.34.0, obbligatoria dalla 1.35.0; A-3 della review
     2026-10-03 e A-1 della review 2026-10-11)**: la release deve avere l'asset
     `wp-health-check.php.sig` (JSON `{tag, sha256, kid, signature}` prodotto da
     `hub/scripts/sign_release.py`) e la firma Ed25519 su `"release\n<tag>\n<sha256>"`
     viene verificata con `WPHC_Crypto::verify_central_signature( ..., 'release' )` e la chiave
     pubblica **`k2`** incorporata, dedicata alle release. Lo SHA-256 da solo
     garantiva l'integrità ma non l'autenticità: sta nella stessa release, quindi chi
     controlla il repository controlla anche l'hash. Una release senza `.sig`, con una
     firma non valida o firmata con un'altra chiave ferma sempre l'aggiornamento. Il
     tag nel messaggio firmato è il `tag_name` GitHub grezzo, con la `v`.
   - Se una qualunque verifica fallisce si risponde
     `200 { "updated": false, "reason": "integrity_check_failed" }`.
7. **Backup**: il file corrente viene copiato in `wp-health-check.php.bak`, per il solo
   ripristino immediato dei punti 8 e 9; dalla 1.36.0 viene rimosso a update riuscito.
8. **Scrittura atomica**: il nuovo contenuto viene scritto in un file temporaneo
   nella *stessa directory* (con estensione neutra, non `.php` — vedi nota sotto),
   poi si esegue `rename()` su `WP_HEALTH_CHECK_FILE` (di default `__FILE__`, cioè il
   mu-plugin stesso). `rename()` sullo stesso filesystem è
   atomico: non lascia mai il file di produzione "a metà scritto".
9. **Sanity check post-scrittura**: si rilegge il file, si verifica che non sia
   vuoto e che inizi con `<?php`. Se qualcosa non torna, si ripristina
   immediatamente dal `.bak` del punto 7.
10. **Invalidazione opcache**: `opcache_invalidate( WP_HEALTH_CHECK_FILE, true )` se
    disponibile; altrimenti la vecchia versione compilata resta in memoria fino al
    riavvio del pool PHP (es. php-fpm).
11. Risposta `200 { "updated": true, "from": "...", "to": "..." }`.

**Push dell'hub (dalla 1.38.0).** `POST /update` con un body JSON
`{"package": {"tag", "sha256", "sig", "contents_gz_b64", "force"}}` salta i punti 2 e 5:
il file arriva compresso (gzip + base64) nel body e il sito non scarica nulla
(`WPHC_Self_Updater::perform_pushed_update()`). Il pacchetto è una sorgente come un'altra: la stessa
pipeline (`WPHC_Self_Updater::install_release()`) ripete confronto di versione, preflight di scrittura,
integrità (SHA-256, `<?php`, `Version:` uguale al tag) e firma `k2` sul messaggio
`"release\n<tag>\n<sha256>"`. L'hub non possiede il seed `k2` e non può fabbricare una
release valida. Utile ai siti che non raggiungono GitHub o l'hub e per la reinstallazione
di riparazione: con `force: true` il sito accetta anche la stessa versione, mentre un
downgrade è sempre rifiutato (`200 { "updated": false, "reason": "up_to_date" }`). Un
pacchetto malformato o oltre i limiti (2 MB di base64, 4 MB decompressi) risponde
`400 wphc_update_push_invalid`. La risposta riporta `"mode": "push"`. Fino alla 1.38.1
il body pesava circa 130.900 byte, a ridosso del limite di 128 KiB di mod_security: su un sito
con quel limite il push veniva rifiutato dal server prima di arrivare al plugin. Dalla
1.39.0 il file distribuito non contiene commenti e il body pesa circa 60.700 byte.

Ogni ramo d'errore restituisce un `WP_Error` con status HTTP coerente e un codice
macchina stabile (`wphc_update_network_error`, `wphc_update_github_error`,
`wphc_update_bad_release`,
`wphc_update_asset_missing`, `wphc_update_download_failed`,
`wphc_update_push_invalid` (solo push),
`wphc_update_backup_failed`, `wphc_update_write_failed`,
`wphc_update_sanity_failed`). Gli esiti vengono anche loggati con `error_log()`,
ma solo se `WP_DEBUG` è attivo.

**Nota tecnica sul file temporaneo (passo 8):** il temporaneo non ha
deliberatamente estensione `.php`. Se il `rename()` fallisse lasciando un file
orfano nella cartella, un file con estensione `.php` in `mu-plugins/` verrebbe
caricato automaticamente da WordPress al giro successivo, ridichiarando tutte le
classi del plugin (`Cannot declare class`) e mandando in fatal error l'intero sito; un'estensione neutra
rende questo scenario innocuo.

**Nota tecnica su `WP_Filesystem`:** il plugin usa funzioni filesystem PHP native
(`file_put_contents`, `copy`, `rename`) invece dell'astrazione `WP_Filesystem` del
core. È una scelta di progetto, non una svista: il file deve restare
autoconsistente e funzionare anche quando `WP_Filesystem` non è inizializzato o
richiederebbe credenziali FTP (scenario comune per un mu-plugin, che gira prima di
molte inizializzazioni dell'admin).

## Aggiornamento di plugin, temi e core via API

Dalla `1.18.0`, oltre al self-update dell'agent, il sistema centrale può
innescare l'aggiornamento di **software di terze parti del sito**: un singolo
plugin, un singolo tema, o il core di WordPress. È una funzionalità
distinta e separata dal self-update: usa le primitive del core
(`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`) invece delle funzioni
filesystem native, perché aggiornare un plugin/tema significa scaricare uno
ZIP, scompattarlo e sostituire un'intera cartella (non un singolo file), con
tutte le routine di maintenance-mode e rollback che il core già sa gestire.

Nasce da un'analisi di fattibilità e sicurezza dedicata (vedi
[../__docs/wp-health-check/plugin-update-via-api-analisi.md](../__docs/wp-health-check/plugin-update-via-api-analisi.md)
e [../__docs/wp-health-check/plugin-update-via-api-specifiche.md](../__docs/wp-health-check/plugin-update-via-api-specifiche.md))
e implementa la sua **Opzione C**: una chiamata REST per singolo elemento,
sincrona, con la dashboard che orchestra e fa polling — evita i timeout e la
maintenance mode orfana di un ipotetico aggiornamento "bulk" in un'unica
richiesta.

### Il vincolo di sicurezza non negoziabile

La richiesta indica **solo quale elemento** aggiornare, **mai da dove né a
quale versione**: nessun campo `package_url`, nessun campo `version` nel
payload. La sorgente del pacchetto è esclusivamente `->update->package` (o
`->download` per il core, vedi sotto), letta dal transient di update che il
core stesso popola interrogando `api.wordpress.org`. Senza questo vincolo, un
token compromesso diventerebbe un vettore di esecuzione di codice remoto
(installazione di uno ZIP arbitrario); con questo vincolo, il rischio
incrementale è paragonabile a quello di un amministratore che clicca
"Aggiorna" in bacheca.

Due controlli aggiuntivi, entrambi indipendenti dal payload:

- **Allowlist dell'host del pacchetto** (`WPHC_Item_Updater::is_package_host_allowed()`):
  **opzionale e spenta di default dalla `1.24.0`** (vedi
  ["Restrizione ai soli pacchetti ufficiali"](#restrizione-ai-soli-pacchetti-ufficiali-opzionale)
  sotto); se accesa per singolo sito, ammette solo `downloads.wordpress.org`
  e `api.wordpress.org`, escludendo i plugin/temi **premium** (che si
  aggiornano da server propri) con `not_updatable`.
- **`sslverify` sempre attivo** (default della WP HTTP API di WordPress): mai
  disabilitato.

### Kill-switch per sito

Interruttore master `wp_health_check_updates_enabled`, **acceso di default**
(dalla `1.19.0`; disattivabile per singolo sito), gestito da un unico
checkbox nella [tab Site Health](#tab-site-health). A interruttore spento,
`POST /update/plugin`, `/update/theme` e `/update/core` rispondono
`403 disabled` **prima** di qualunque altra elaborazione; `GET /update/log`
resta invece sempre leggibile (è sola lettura).

### Restrizione ai soli pacchetti ufficiali (opzionale)

Dalla `1.24.0`, un secondo checkbox nello stesso form della tab Site Health
(opzione `wp_health_check_restrict_official_only`, **spenta di default**)
permette di ripristinare, per singolo sito, la restrizione storica ai soli
pacchetti ospitati su `downloads.wordpress.org`/`api.wordpress.org`: con la
restrizione spenta (default) qualsiasi plugin/tema è aggiornabile via API,
inclusi i premium; con la restrizione accesa, un plugin/tema premium
restituisce sempre `result: "not_updatable"`.

Rendere questa restrizione opzionale (anziché hardcoded, come fino alla
`1.23.0`) non indebolisce il [vincolo di sicurezza non
negoziabile](#il-vincolo-di-sicurezza-non-negoziabile) sopra: l'host
controllato da `WPHC_Item_Updater::is_package_host_allowed()` non è mai un valore fornito
dalla richiesta REST — è sempre quello che il sistema di aggiornamento del
sito **stesso** ha già determinato (il transient del core, o quello di un
update-checker premium già attivo su quel sito). Un token compromesso non
guadagna quindi nessuna capacità nuova togliendo questa restrizione dal
default: non può comunque indicare una sorgente arbitraria. La allowlist era
una scelta editoriale ("i premium non sono supportati in v1"), non una
barriera contro un vettore di attacco — da qui la scelta di renderla
un'opzione per sito anziché un vincolo fisso.

### Flusso comune (plugin e temi)

1. Registrazione accesso, kill-switch, requisito **WordPress ≥ 6.3** (per la
   garanzia di rollback via *temp-backup* nativo introdotto in quella
   versione — sotto 6.3 la rotta risponde `unsupported_wp_version`, scelta
   fail-safe: niente update senza rete di sicurezza), lock anti-concorrenza
   (`wp_health_check_update_lock`, TTL 300s, rilascio garantito anche via
   `register_shutdown_function`), preflight filesystem (`get_filesystem_method()
   === 'direct'`, altrimenti `fs_method_unavailable` — mai raccolte
   credenziali FTP/SSH) e pulizia difensiva di un eventuale `.maintenance`
   orfano (`WPHC_Item_Updater::update_preflight()`).
2. Rilettura affidabile del transient di update (stesso
   `WPHC_Util::mute_update_shortcircuit()`/`WPHC_Util::restore_update_shortcircuit()` già
   usati da `/health`), verifica che l'elemento abbia davvero un update
   disponibile (altrimenti `up_to_date`) e allowlist dell'host del pacchetto
   (altrimenti `not_updatable`).
3. Riga di log `requested` (vedi [schema sotto](#tabella-di-log-degli-aggiornamenti)).
4. Upgrade con skin silenziosa (`Automatic_Upgrader_Skin`, nessun output
   HTML: la richiesta è REST, non una pagina admin) e temp-backup nativo
   attivo di default (WP 6.3+).
5. Sanity check: si rilegge la versione effettivamente installata dopo il
   tentativo. Se coincide con la versione attesa → `completed`. Se l'elemento
   è tornato alla versione di partenza → il temp-backup nativo ha già
   ripristinato con successo → `rolled_back`. Altrimenti lo stato è incerto
   (elemento mancante o a una versione imprevista) → `failed`, da verificare
   manualmente sul sito.
6. Invalidazione opcache dei file dell'elemento, refresh della sola cache
   lista (`wp_clean_plugins_cache( false )`/`wp_clean_themes_cache( false )`)
   e delle cache di `/health` e `/detail/*`, riga di log finale, rilascio
   del lock. Sull'esito `completed` viene inoltre corretta **solo la entry
   dell'elemento appena aggiornato** nel transient `update_plugins`/
   `update_themes` già esistente (rimossa da `->response`, `->checked`
   aggiornato alla nuova versione) — **mai** un
   `delete_site_transient()`/`wp_clean_*_cache( true )` del transient intero
   né una chiamata a `wp_update_plugins()`/`wp_update_themes()`: quest'ultime,
   in contesto REST, ricostruirebbero il transient senza gli aggiornamenti
   dei plugin/temi premium (stesso bug già risolto per `/health` e
   `/detail/plugins` nella `1.13.0`/`1.16.0`), facendo risultare "tutto
   aggiornato" anche per elementi che hanno ancora un update pendente. Su
   `rolled_back`/`failed` il transient non viene toccato: l'update è ancora
   effettivamente pendente, o lo stato è incerto.
7. **Solo per i plugin**, sull'esito `completed`: verifica che un plugin
   già attivo prima dell'update resti attivo dopo
   (`is_plugin_active()`/`is_plugin_active_for_network()` per il caso
   multisite). In teoria `Plugin_Upgrader::upgrade()` non tocca mai
   l'opzione `active_plugins`, ma una disattivazione può comunque avvenire
   per cause esterne (plugin di sicurezza/hosting che disattivano su
   modifica file, un main file rinominato dalla nuova versione...). Se il
   plugin risulta disattivato, si tenta una riattivazione automatica nello
   stesso ambito di prima (`activate_plugin( $target, '', $was_network_active )`);
   se anche questa fallisce, la rotta risponde `updated: true` +
   `result: "reactivation_failed"` + `detail` con il messaggio d'errore
   (stesso pattern "risultato + dettaglio" di `not_updatable`), invece di
   dichiarare un successo pieno che nasconderebbe il problema.

### Core: specificità e avvertenze

Il core **non** usa il temp-backup di plugin/temi: `Core_Upgrader` ha un
proprio percorso di ripristino, una garanzia **diversa e più debole** — per
questo il requisito WP 6.3 non si applica a questo ramo (non ci sarebbe
comunque un temp-backup da richiedere). L'aggiornamento core è l'operazione
più lenta e rischiosa delle tre, e il primo candidato a un'eventuale futura
esecuzione asincrona se i timeout si rivelassero un problema in produzione.

Dopo la sostituzione dei file, il flusso invoca esplicitamente `wp_upgrade()`
per completare le routine di migrazione del database: in un contesto
headless (nessuna sessione admin che visiterebbe
`wp-admin/upgrade.php`) queste non partirebbero altrimenti da sole.

Dopo un update riuscito viene anche forzato un ricontrollo reale con
`wp_version_check( array(), true )`, cosa che invece **non** si fa per
plugin/temi (vedi sopra): per il core non esiste l'equivalente
"update-checker premium" che in contesto REST non si caricherebbe — il
check di versione di WordPress è identico in ogni contesto — quindi qui
forzarlo è sicuro e ripopola subito `update_core`, così
`summary.core_update` torna `false` senza aspettare il prossimo giro di
cron.

Nota tecnica: a differenza di plugin/temi, gli update object del core non
hanno un campo `->package`; il campo equivalente è `->download` (che, per un
update standard, coincide con `->packages->full`, il pacchetto che
`Core_Upgrader::upgrade()` scarica davvero nel percorso che questo plugin
percorre).

### Tabella di log degli aggiornamenti

Ogni operazione avviata via API produce **due righe** nella tabella custom
`{$wpdb->prefix}wphc_update_log` (`id`, `correlation_id`, `created_at`,
`type`, `target`, `name`, `version_from`, `version_to`, `phase`, `message`,
`ip`, `active`, `source`, `actor`), legate dallo stesso `correlation_id`: una
**prima** di toccare qualunque file (`phase = requested`, prova che un
aggiornamento è stato avviato anche se PHP muore a metà), una al termine
(`completed` / `failed` / `rolled_back`). La colonna `active` (dalla
`1.21.0`) registra lo stato attivo osservato in quel momento — `true`/`false`
solo sulle righe `plugin` (vedi [punto 7 del flusso](#flusso-comune-plugin-e-temi)
sopra), sempre `NULL` per temi/core. La tabella non ha un hook di attivazione
dedicato (i mu-plugin non ne hanno): viene creata/allineata con `dbDelta()`
al primo caricamento in cui `wp_health_check_db_version` non combacia con lo
schema atteso. Le righe più vecchie di 90 giorni
(`WP_HEALTH_CHECK_LOG_RETENTION_DAYS`) vengono rimosse con un prune
opportunistico (al massimo una volta al giorno, gate via transient), senza
dipendere da un cron dedicato. Il reset enrollment (WP-CLI o tab Site
Health) **non** cancella questo storico — solo il lock anti-concorrenza —
perché è audit del sito, non stato di enrollment.

**`source`/`actor` (dalla 1.29.0, schema versione 4).** Le colonne
distinguono un aggiornamento avviato via API (`source = "api"`, il pattern a
due righe qui sopra) da uno fatto direttamente da WordPress: `wp-admin`
(bacheca), `cron` (auto-update **nativo** di WordPress in background) o
`wp-cli`, tutti loggati con una **sola** riga `completed` (l'hook che li
rileva scatta a esito già riuscito, non ha senso una riga `requested` che
nessuno chiuderebbe). `actor` è lo `user_login` di chi ha agito da wp-admin,
`null` per cron/wp-cli/api. Vedi il dettaglio del meccanismo di rilevazione
nella sezione [`GET /update/log`](#get-updatelog--storico-degli-aggiornamenti)
sopra.

**Attenzione (dalla 1.31.0):** `source = "cron"` compare anche per gli
elementi drenati da [`POST /update/bulk`](#aggiornamenti-bulk-coreplugintemi-via-api--wp-cron)
— ma a differenza dell'auto-update nativo di WordPress, questi seguono il
pattern a **due righe** (`requested` + terminale) descritto sopra, perché
passano dallo stesso `WPHC_Item_Updater::perform_item_update()` delle rotte sincrone. Per
distinguerli: le righe del drain bulk hanno sempre una riga `requested`
gemella con lo stesso `correlation_id`; le righe dell'auto-update nativo no.

`GET /health` espone tre campi derivati da questa funzionalità nel blocco
`summary`, tutti O(1) (coerenti col contratto economico della rotta):
`updates_via_api_enabled` (stato del kill-switch), `last_update` (oggetto
`{type, target, phase, source, at}` dell'ultima riga di log, letto da
un'opzione autoloaded aggiornata ad ogni update, non da una query alla
tabella — `source` incluso dalla 1.29.0) e `maintenance_stuck` (`true` se
esiste un `.maintenance` più vecchio di 10 minuti: segnala un upgrade
interrotto).

## Aggiornamenti bulk (core/plugin/temi) via API + WP-Cron

Le rotte `POST /update/plugin`/`/update/theme`/`/update/core` qui sopra
aggiornano **un elemento per chiamata** (Opzione C della fase di analisi
originaria): durata contenuta e prevedibile, rollback per singolo elemento,
ritentabile senza rifare tutto. Dalla 1.31.0 si aggiunge un modo per
aggiornare **più elementi in un colpo solo senza tenere aperta una richiesta
HTTP per la durata di tutto il lotto**: la dashboard accoda una lista con
`POST /update/bulk`, e WP-Cron smaltisce la coda — mai il contrario. Nessun
aggiornamento parte mai senza un trigger autenticato esplicito: il sito
**non** ha un cron di auto-update proprio (vedi
[Architettura e scopo](#architettura-e-scopo)), il cron si limita a drenare
un lavoro già autorizzato da una chiamata REST autenticata.

**Il core è supportato dalla 1.32.0, ma solo in un job esclusivo**: un job
che include il core non può contenere anche plugin o temi (e viceversa). È
l'operazione più lenta e rischiosa delle tre, con un rollback più debole di
plugin/temi (nessun temp-backup nativo), e mescolarla ad altro renderebbe
impossibile per il centro isolare l'effetto di un core interrotto a metà da
quello di un plugin aggiornato nello stesso lotto. Il centro accoda prima il
job core, ne attende l'esito, poi accoda plugin/temi separatamente.

### `POST /update/bulk` — accoda un job

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/update/bulk' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI' \
  -H 'Content-Type: application/json' \
  -d '{"items":[{"type":"plugin","target":"akismet/akismet.php"},{"type":"theme","target":"astra"}]}'
```

Forma canonica: `{"items":[{"type":"plugin"|"theme"|"core","target":"..."}]}`
(`target` è facoltativo per il core, forzato comunque alla sentinella
`"core"`). Accettata anche la forma zucchero
`{"plugins":[...],"themes":[...],"core":true}`. Come per le rotte sincrone,
il body indica **solo quali elementi** aggiornare, mai un pacchetto, una
versione o un URL.

Job core-only, con lo zucchero:

```bash
curl -X POST 'https://esempio.com/blog/wp-json/health-check/v1/update/bulk' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI' \
  -H 'Content-Type: application/json' \
  -d '{"core":true}'
```

Un body che mescola `"core":true` (o un item `type:"core"`) con plugin o temi
è rifiutato con **`400 wphc_bulk_core_not_exclusive`**, senza creare alcun
job: va accodato un job separato per il core.

Risposta (`202 Accepted`):

```json
{
  "accepted": true,
  "job_id": "4f9c1ab7e2d05613",
  "status": "queued",
  "total": 2,
  "rejected": [],
  "scheduled_at": "2026-08-13T10:00:00+00:00",
  "cron_disabled": false,
  "poll": {
    "job": "https://esempio.com/blog/wp-json/health-check/v1/update/bulk",
    "log": "https://esempio.com/blog/wp-json/health-check/v1/update/log?source=cron"
  }
}
```

`rejected` elenca gli elementi scartati in validazione (target malformato,
duplicato oltre il limite, tipo non supportato), con un `reason` macchina.
`cron_disabled` rispecchia `DISABLE_WP_CRON`: se `true`, il sito non può
autodrenare la coda e serve pollare `GET /update/bulk` (che finalizza da
solo un job scaduto, vedi sotto) oppure affidarsi a un cron di sistema reale.

Un job già in corso fa rispondere **409** `wphc_bulk_job_in_progress` con lo
stato del job corrente nel body (così il chiamante può passare a fare
polling invece di riprovare l'enqueue). Se il job corrente risulta
**stallato** (`stalled: true`, vedi sotto), `{"force":true}` nel body lo
aborta esplicitamente e ne accoda uno nuovo.

### `GET /update/bulk` — stato del job

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/update/bulk' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-08-13T10:02:00+00:00",
  "job": {
    "job_id": "4f9c1ab7e2d05613",
    "status": "running",
    "abort_reason": null,
    "created_at": "2026-08-13T10:00:00+00:00",
    "started_at": "2026-08-13T10:00:05+00:00",
    "finished_at": null,
    "next_run_at": "2026-08-13T10:02:30+00:00",
    "stalled": false,
    "counters": { "total": 2, "done": 1, "updated": 1, "skipped": 0, "warnings": 0, "failed": 0, "pending": 1 },
    "webhook": { "sent": false, "attempts": 0, "sending_ts": null, "sent_ts": null, "code": null, "last_error": null }
  }
}
```

Aggiungere `?items=0` per omettere il dettaglio elemento-per-elemento e
ricevere solo il riassunto. Questa rotta è più economica di una query alla
tabella di log (un solo `get_option()` non autoloaded) ed è l'unico posto
dove si vedono gli elementi ancora **pendenti** (che non hanno ancora
nessuna riga di log). La cronologia dei tentativi resta comunque
consultabile su `GET /update/log?source=cron`, collegabile tramite
`correlation_id`.

`stalled` è **derivato a lettura**, mai spinto dal sito: `true` quando il
job è attivo ma il momento previsto per il prossimo tick (`next_run_at`) è
passato da più di 10 minuti. Copre `DISABLE_WP_CRON`, un cron di sistema
assente, un host che blocca il loopback di `spawn_cron()` — in tutti questi
casi nulla gira lato sito per aggiornare lo stato, quindi il flag *deve*
poter essere calcolato senza alcuna esecuzione. Chiamare questa rotta
(anche solo per il polling) finalizza da solo un job che ha superato la
scadenza dura di 6 ore dalla creazione.

### `POST /update/bulk/cancel` — interrompe un job attivo

Interrompe il job **tra** un elemento e il successivo (un elemento già
dentro `Plugin_Upgrader::upgrade()` completa comunque). Il webhook di fine
job (sotto) viene comunque inviato, con il report parziale.

### Riprovare gli elementi falliti

Ogni elemento fallito (`failed`/`rolled_back`) viene ritentato fino a un
totale di **4 tentativi** (1 iniziale + 3 retry, come richiesto), con
backoff crescente (0/60/300/900 secondi) — il core segue esattamente le
stesse regole, nessun trattamento speciale sui retry. Una contesa di lock
con un update singolo concorrente (`locked`) **non** consuma un tentativo: è
una deferral, non un fallimento. Esiti come `up_to_date`/`not_found`/`not_updatable`
sono terminali (nulla da ritentare); `reactivation_failed` è un avviso
terminale (i file sono già stati sostituiti con successo, un retry
rileggerebbe solo `up_to_date` — si ripara con [`POST /update/reactivate`](#post-updatereactivate--riconciliazione-dello-stato-attivo-dei-plugin));
kill-switch/versione WP non supportata/filesystem non `direct` sono
verdetti sull'intero sito, non sul singolo elemento, e abortano l'intero job.
Un elemento core marcato `running` viene considerato interrotto (e rimesso
in coda) solo dopo 30 minuti, non i 10 usati per plugin/temi: un download
del pacchetto completo più la sostituzione di migliaia di file può
legittimamente richiedere più tempo.

### Webhook di notifica firmato

A fine job (completato, con errori, abortito o cancellato), se configurato
un URL nella tab Site Health (vedi [Tab Site Health](#tab-site-health)),
viene inviata una `POST` firmata con il dettaglio di cosa è stato fatto.

Firma HMAC-SHA256 speculare (in uscita) allo schema di verifica del
protocollo v2 in ingresso (vedi [Il modello del token](#il-modello-del-token)):

```
stringa_firmata = "POST\n" + evento + "\n" + sha256_hex(body) + "\n" + timestamp + "\n" + nonce
signature       = base64url( HMAC-SHA256( segreto_di_sito, stringa_firmata ) )
```

Header inviati: `X-WPHC-Timestamp`, `X-WPHC-Nonce`, `X-WPHC-Signature`,
`X-WPHC-Event` (`wphc.bulk_update.completed` oppure `wphc.test` per l'invio
di prova), `X-WPHC-Secret-Kid` (per sopravvivere a una rotazione del
segreto), `X-WPHC-Delivery` (l'id del job, da usare come chiave di
idempotenza lato ricevente: la consegna è *at-least-once*, non
*exactly-once*), `X-WPHC-Attempt`. Il corpo (`Content-Type:
application/json`) va codificato **una sola volta**: l'hash nella firma e il
corpo inviato devono essere byte-per-byte identici.

Corpo:

```json
{
  "schema": "wphc.bulk_update.report/1",
  "event": "wphc.bulk_update.completed",
  "site": "https://esempio.com/blog",
  "agent_version": "1.31.0",
  "secret_kid": "k1",
  "generated_at": "2026-08-13T10:04:12+00:00",
  "job": {
    "id": "4f9c1ab7e2d05613",
    "source": "api",
    "status": "completed",
    "abort_reason": null,
    "started_at": "2026-08-13T10:00:05+00:00",
    "finished_at": "2026-08-13T10:04:12+00:00"
  },
  "totals": { "total": 2, "done": 2, "updated": 2, "skipped": 0, "warnings": 0, "failed": 0, "pending": 0 },
  "items": [
    { "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet Anti-spam", "from": "5.3.2", "to": "5.3.3", "result": "updated", "attempts": 1, "log_id": 1234, "finished_at": "2026-08-13T10:01:02+00:00" }
  ],
  "items_truncated": false
}
```

`items[].result` riusa lo stesso vocabolario delle rotte di update singole
(`updated`, `up_to_date`, `not_updatable`, `reactivation_failed`, ecc.):
un solo vocabolario condiviso tra REST, tabella di log e webhook. Nessun
segreto, token o dato utente compare mai nel corpo.

**Consegna e retry.** Solo errori transitori vengono ritentati (errore di
trasporto, HTTP 408/429/5xx): un 4xx diverso significa che il ricevente ha
rifiutato esplicitamente la richiesta, e ripeterla non risolverebbe nulla.
Fino a 3 tentativi totali, con backoff 5 minuti poi 30 minuti, ri-firmando
ogni volta con timestamp e nonce freschi sullo **stesso** corpo (la finestra
di freschezza ±300s del protocollo scadrebbe altrimenti prima di un retry).
Un sito non ancora registrato o revocato non invia nulla — un sito revocato
tace del tutto, stessa scelta di `WPHC_Auth::require_token()` in ingresso.

**Sicurezza.** L'URL deve essere `https` (verificato sia al salvataggio sia
all'invio) e supera `wp_http_validate_url()` (lo stesso validatore che l'HTTP
API di WordPress applica già alle richieste sicure); se l'host è un indirizzo
IP letterale, deve essere pubblico. Questo è un guardrail contro errori e
abusi casuali, **non** un confine di sicurezza assoluto (nessun re-check DNS
all'invio). `sslverify` resta sempre attivo (mai disattivabile) e le
redirezioni non vengono seguite (`redirection: 0`): una 30x non deve
ripostare il corpo firmato altrove.

**Default di flotta (dalla 1.32.0).** Se il campo URL nella tab Site Health
è vuoto, il sito usa automaticamente `https://hub.mavida.com/api/v1/fleet/webhook/bulk-update`
(costante `WP_HEALTH_CHECK_WEBHOOK_DEFAULT_URL`) — nessuna azione richiesta
per i siti già arruolati. Un URL impostato nel campo lo sovrascrive; una
checkbox esplicita "Non inviare notifiche webhook per questo sito" spegne
del tutto l'invio (default incluso), perché da quando esiste un default un
campo vuoto non significa più "spento".

## Requisiti lato GitHub

Ogni release del repository deve fornire:

- un **tag** di versione (es. `v1.1.0` o `1.1.0`, il prefisso `v` viene rimosso in
  fase di confronto);
- un asset binario chiamato esattamente **`wp-health-check.php`** — il file
  completo, pronto per la produzione, con l'header del plugin che dichiara
  `Version: <tag senza "v">`;
- un asset affiancato **`wp-health-check.php.sha256`** con l'hash SHA-256 del file
  sopra (formato `sha256sum` o hash nudo); dalla 1.34.0 la riga `sha256: <hash>`
  nelle note della release non è più letta, e anche il plugin installer pretende
  l'asset `.sha256`;
- un asset **`wp-health-check.php.sig`** con la firma del centro (obbligatorio dalla
  1.35.0), prodotto con la chiave dedicata alle release (`k2`):
  `python scripts/sign_release.py --file ../wp-health-check/mu-plugins/wp-health-check.php --tag vX.Y.Z --key-file <seed k2>`
  (eseguito dalla cartella `hub`). `--tag` deve essere identico al `tag_name` della
  release GitHub, con la `v`: un mismatch (`1.36.0` contro `v1.36.0`) blocca l'update
  (fail-closed). La release 1.35.0 è l'eccezione: va firmata con la chiave storica
  `k1` (`--key-env`, `--kid k1`) perché la 1.34.0, che deve accettarla, non conosce
  `k2`.

### Procedura di release dal sorgente (dalla 1.39.0)

Il file da firmare non è quello che si modifica: si parte da `src/`.

1. **Versione.** Si alza in DUE punti, `Version:` in `src/header.php` e
   `WP_HEALTH_CHECK_VERSION` in `src/constants.php` (la build fallisce se non
   coincidono), e si aggiorna `CHANGELOG.md`.
2. **Verifiche.** `composer run lint`, `composer run analyse`, `composer run test:build`,
   la suite PHPUnit sulla matrice PHP, il diff dei golden vuoto, gli e2e e il test di
   upgrade dalla 1.38.1 (vedi [Sorgente, build e file distribuito](#sorgente-build-e-file-distribuito-dalla-1390)).
3. **Build strip.** Si costruisce il file con `composer run build`, che scrive
   direttamente `mu-plugins/wp-health-check.php` (lo strip è l'unico distribuibile) e si
   leggono i numeri che stampa: righe, byte, gzip più base64, body del push (limite 131072 byte) e SHA-256.
4. **Controllo del marcatore, PRIMA di firmare:** `grep -L WPHC-BUILD
   mu-plugins/wp-health-check.php`. Attenzione al verso: `grep -L` elenca i file che NON
   contengono la parola, quindi se il comando STAMPA il nome del file il marcatore è
   assente e il file è la build strip, firmabile; se l'output è VUOTO il marcatore c'è,
   il file è la build di sviluppo e NON va firmato né pubblicato. `sign_release.py`
   (nel repository dell'hub) non fa questo controllo. Si verifica anche che
   `composer run build:check` dia `CHECK OK`.
5. **Asset e firma.** Si producono `wp-health-check.php.sha256` e `wp-health-check.php.sig`
   come descritto sopra (chiave `k2`, `--tag` identico al tag GitHub con la `v`) e si
   pubblica la release con i tre asset.
6. **Rollout graduale** (consigliato): prima uno o pochi siti di prova, con la verifica
   della versione su `GET /ping` (senza cache), poi la flotta.

## Caching per-rotta

| Rotta | Cache | TTL | Note |
|---|---|---|---|
| `GET /health` | transient `wphc_health_cache` | 60s (opzionale) | Nessuna chiamata remota, mai `debug_data()`. Pensata per polling frequente: vedi sotto il perché. |
| `GET /detail/plugins` | transient `wphc_detail_plugins_cache` | 1h | Legge transient di update già esistenti. |
| `GET /detail/theme` | transient `wphc_detail_theme_cache` | 1h | Idem. |
| `GET /detail/server` | transient `wphc_detail_server_cache` | 12h | Config server cambia raramente; è la chiamata più onerosa. |

`?fresh=1` (su `/health` e su tutte le rotte `/detail/*`) è l'**unico** modo per
bypassare le cache locali del plugin. Svuota la cache del payload wphc e le cache
delle liste plugin/temi (`wp_clean_plugins_cache( false )` /
`wp_clean_themes_cache( false )`) **prima** di ricontare, così `get_plugins()` e
`wp_get_themes()` riscansionano davvero la cartella: garantisce conteggi
(`plugins_total`, `count`, `themes_total`) corretti anche dietro un object cache
persistente (Redis/Memcached) che renda persistente — a torto — il gruppo di
cache `plugins`/`themes`. Se un conteggio sembra sbagliato, interrogare la rotta
con `?fresh=1` è il primo controllo da fare.

**`?fresh=1` NON forza un controllo remoto degli aggiornamenti** (dalla `1.13.0`).
I conteggi e le versioni di aggiornamento (`plugins_updates`, `themes_updates`,
`new_version`, `core_update`) sono sempre letti dai **transient mantenuti dal
cron di WordPress** (`update_plugins`, `update_themes`, `update_core`), gli stessi
che alimentano la schermata Plugin dell'amministratore. Il motivo è importante:
chiamare `wp_update_plugins()`/`wp_update_themes()` da una richiesta REST è
inaffidabile per i **plugin/temi premium**, che si aggiornano da server propri e
in contesto REST non caricano i loro update-checker; peggio, quella chiamata
**sovrascriverebbe** il transient completo mantenuto dal cron con uno incompleto,
riportando conteggi errati (es. `plugins_updates: 0` con 11 aggiornamenti reali) e
corrompendo anche il dato mostrato in bacheca. Il cron gira invece caricando
tutti i plugin, quindi il suo transient include anche i premium. La freschezza
dell'ultimo controllo è esposta in `summary.updates_checked_at`: se è troppo
vecchia, il problema è il WP-Cron del sito, da risolvere lì (non forzando check
dal plugin).

**Short-circuit del transient di update (dalla `1.16.0`).** Alcuni siti
disabilitano i controlli di aggiornamento su frontend/REST con
`add_filter( 'pre_site_transient_update_plugins', '__return_null' )` (e analoghi
per temi/core), spesso per "performance". Quel filtro fa restituire `null` a
`get_site_transient( 'update_plugins' )` fuori dall'admin: senza contromisure,
`/health` e `/detail/*` riporterebbero 0 aggiornamenti anche con update reali,
mentre l'amministratore ne vede correttamente (in admin quei filtri non sono
attivi). Le rotte dati neutralizzano quindi **temporaneamente** solo gli
short-circuit `pre_site_transient_update_*` prima di leggere i transient e li
ripristinano subito dopo (vedi `WPHC_Util::mute_update_shortcircuit()`), lasciando
attive le iniezioni legittime dei plugin premium sui filtri di lettura. La rotta
diagnostica `GET /health-check/v1/debug` (gated `manage_options`) aiuta a
individuare questo e altri casi, confrontando il transient filtrato con quello
grezzo memorizzato ed elencando i callback registrati sui filtri.

**Perché `/health` non chiama mai `WP_Debug_Data::debug_data()`:** quella funzione
introspeziona l'intero ambiente server (versioni PHP, estensioni, dimensioni
directory, in alcuni casi persino test attivi su Imagick/Ghostscript) ed è
relativamente costosa. `/health` è pensata per essere interrogata molto spesso da
un sistema di monitoring: se pagasse quel costo ad ogni chiamata, il polling
frequente diventerebbe insostenibile per il sito. Tutto ciò che è costoso vive
esclusivamente in `/detail/server`, dietro cache lunga, o dietro `?fresh=1` quando
serve davvero un dato fresco.

**Cache "propria" via transient vs cache HTTP/edge — non vanno confuse.** Le
righe sopra descrivono la cache applicativa del plugin (transient, letta e
scritta in PHP). È completamente separata dalla cache HTTP lato server (es.
LiteSpeed Cache, WP Super Cache, W3TC, WP Rocket, una CDN davanti al sito):
quel livello **non deve mai** mettere in cache le risposte di
`health-check/v1`, perché sono autenticate per bearer token e dipendono
dall'`Origin` del chiamante (vedi [CORS](#cors) sotto) — una cache condivisa
che ignori questi due fattori servirebbe la risposta di un chiamante
(incluso l'header CORS con il SUO `Origin`) a chiunque altro. Per questo ogni
rotta invia esplicitamente `nocache_headers()` e definisce la costante
`DONOTCACHEPAGE` (riconosciuta dai principali plugin di page-cache), prima di
qualunque header CORS: vedi `WPHC_Cors::maybe_send_headers()`. Se un sito
sembra restituire lo stesso `Access-Control-Allow-Origin` a prescindere
dall'`Origin` inviato, o CORS funziona in modo incoerente, il primo sospetto
è una cache condivisa che ha già memorizzato una risposta prima di questo fix
(serve un purge della cache lato hosting) o che ignora questi header.

## CORS

Se `wp_health_check_dashboard_origin` è impostata (dall'enroll) e l'header
`Origin` della richiesta combacia **esattamente** con quel valore, il plugin
invia:

```
Access-Control-Allow-Origin: <quell'origin specifico, mai "*">
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, X-WPHC-Timestamp, X-WPHC-Nonce, X-WPHC-Signature
Vary: Origin
```

(gli ultimi tre header sono la firma anti-replay del protocollo 2, dalla 1.30.0 —
vedi [Rotazione e revoca del segreto](#rotazione-e-revoca-del-segreto)).

Se invece `wp_health_check_dashboard_origin` **non è impostata** (sito appena
installato, prima del primo `/enroll`, oppure resettato con
`wp health-check reset`), dalla 1.36.0 (B-21 della review 2026-10-11) **non si
invia alcun header CORS**. Fino alla 1.35.0 si rifletteva qualunque `Origin`
(mai un wildcard letterale `*`), scelta pensata per il setup ma che, in
produzione, dove l'hub non imposta `HEALTHCHECK_DASHBOARD_ORIGIN`, esponeva ogni
sito a qualunque pagina web. Nessun consumatore ne dipendeva: la dashboard parla
con i siti solo tramite l'hub, server-to-server. Il controllo di accesso vero e
proprio resta comunque il bearer token (`/health`, `/detail/*`, `/update`) o la
firma Ed25519 (`/enroll`): CORS qui è difesa in profondità aggiuntiva, non il
perimetro di sicurezza primario.

Le richieste `OPTIONS` (preflight del browser) vengono intercettate a livello di
`rest_pre_dispatch` per l'intero namespace `health-check/v1`, prima del routing
normale: rispondono `200` con i soli header CORS, **senza autenticazione** — il
preflight del browser non include mai `Authorization`.

**Il default del core REST API di WordPress viene neutralizzato per questo
namespace.** WordPress registra di serie `rest_send_cors_headers()` su
`rest_pre_serve_request`, che per *qualunque* rotta REST riflette *qualunque*
`Origin` con `Access-Control-Allow-Credentials: true` — un comportamento
molto più permissivo di quello di questo plugin, e che girerebbe *dopo* la
nostra logica (vanificandola, perché `header()` sostituisce di default un
header già impostato con lo stesso nome). Per questo
`WPHC_Cors::maybe_send_headers()` viene richiamata una seconda volta su
`rest_pre_serve_request` stesso (priorità 20, dopo il 10 di default del
core, solo per le rotte di `health-check/v1`), rimuovendo prima qualunque
header CORS già impostato: è questa seconda chiamata ad avere sempre
l'ultima parola.

## Considerazioni e limiti di sicurezza

**Raggio d'azione di una compromissione del token.** Il token di un sito da
accesso in lettura ai dati di quel sito e alla possibilità di innescarne il
self-update da una release firmata. Non da accesso amministrativo a WordPress (non
c'è login, non ci sono capability utente coinvolte): un token rubato non permette
di installare plugin arbitrari, solo di far scaricare la release *attualmente
pubblicata* sul repository GitHub configurato, che è comunque verificata via
firma/sha256 (vedi sotto). Il raggio d'azione resta quindi limitato al perimetro
di ciò che queste rotte espongono.

Dalla `1.18.0` questo perimetro include anche l'aggiornamento di plugin/temi/core
già installati (vedi [sezione dedicata](#aggiornamento-di-plugin-temi-e-core-via-api)),
disattivabile per singolo sito tramite il kill-switch (acceso di default dalla
`1.19.0`), e **solo** verso la versione che il sistema di aggiornamento del
sito stesso ha già determinato disponibile per quell'elemento (il core via
wordpress.org, oppure — di default dalla `1.24.0`, salvo restrizione
esplicita, vedi [sopra](#restrizione-ai-soli-pacchetti-ufficiali-opzionale) —
un update-checker premium già attivo su quel sito) — mai un'installazione ex
novo di software non presente sul sito, mai una sorgente o versione indicata
dal token compromesso stesso.

**Trasmissione del token nell'enroll.** Il token viaggia in chiaro (via HTTPS) nel
payload di `/enroll`: è protetto in transito da TLS, non da un ulteriore livello di
cifratura applicativa. Chi intercetta il traffico *dopo* aver rotto TLS (scenario
già catastrofico di per sé) potrebbe leggere il token; è un compromesso accettato
per il modello "il centro consegna un valore già calcolato", più semplice e senza
stato rispetto ad alternative come una PoP key o un secondo fattore di conferma.

**Scenario account GitHub compromesso.** Se l'account che pubblica le release sul
repository venisse compromesso, un attaccante potrebbe pubblicare una release
malevola. Le mitigazioni sono su due livelli indipendenti:

1. dalla 1.34.0 l'asset `.sig` porta la firma Ed25519 del centro sull'hash della
   release, prodotta con una chiave privata che non sta su GitHub: chi controlla
   solo l'account può pubblicare un file con un `.sha256` coerente, ma non una
   firma valida (dalla 1.35.0 una release senza `.sig` non passa più);
2. anche a valle di questo, **l'update non è mai automatico**: parte solo quando il
   sistema centrale chiama esplicitamente `/update` su ciascun sito. Un attaccante
   che pubblica una release malevola su GitHub non ha comunque modo di *innescare*
   l'update senza controllare anche il sistema centrale (che possiede il token di
   ogni sito).

La verifica di integrità (sha256 + prefisso `<?php` + coerenza versione) resta
comunque un controllo cieco rispetto al *contenuto* del codice: non sostituisce una
revisione umana delle release pubblicate, che resta responsabilità di chi gestisce
il repository.

**Rotazione e scadenza del token: opt-in dalla 1.30.0.** Sul protocol 1 (default
storico) resta vero quanto sopra: il token è valido finché non viene sostituito da
un nuovo enroll o cancellato con `wp health-check reset`, nessuna scadenza
automatica. Dalla 1.30.0 un sito può passare al protocol 2 (`POST /rotate`/
`POST /revoke`, vedi [sopra](#rotazione-e-revoca-del-segreto)): segreto casuale
ruotabile/revocabile per singolo sito, con una finestra di grazia durante la
rotazione e nessuna finestra durante la revoca. Resta un limite condiviso da
entrambi i protocolli: il segreto vive comunque in chiaro (decifrato) in memoria
sul sito durante ogni verifica, perché il plugin deve poterlo confrontare — nessuna
rotazione elimina questo fatto, lo mitiga.

**Anti-replay e rate limit (dalla 1.30.0).** Le rotte dati pretendono la firma
`X-WPHC-*` quando il sito è su protocol 2 (finestra di freschezza ±300s, nonce
single-use): una richiesta intercettata smette di essere una credenziale valida
per sempre e diventa un artefatto monouso valido pochi minuti. Un rate limit sui
tentativi falliti (10 in 300s, per IP) risponde `429` prima che un attacco a forza
bruta sul bearer diventi pratico. Limite noto: su richieste HTTP reali la
`permission_callback` delle rotte viene valutata più volte per richiesta e la
rivalutazione di una richiesta firmata valida conta come fallimento, quindi una
richiesta v2 valida incrementa il contatore di 1 (di 2 sulle rotte con due handler). È
un difetto preservato dalla 1.39.0 e da correggere in una release separata (analisi in
[finding-throttle-doppia-valutazione.md](../__docs/wp-health-check/finding-throttle-doppia-valutazione.md)). `/enroll`, `/rotate`, `/revoke` e, dalla 1.34.0,
tutte le rotte dati e `/autologin/token` richiedono inoltre HTTPS (dietro un
reverse proxy fidato vale `X-Forwarded-Proto: https`, vedi [Tracciamento
accessi](#tracciamento-accessi)).

## Installazione, enroll, reset, rollback

### Installazione manuale iniziale

1. Le chiavi pubbliche del centro (`WP_HEALTH_CHECK_CENTRAL_PUBKEY`, kid `k1`, e
   `WP_HEALTH_CHECK_CENTRAL_PUBKEY_K2` per le release) sono già incorporate nella release
   (`src/constants.php`): per la flotta di produzione non c'è nulla da incollare. Solo
   per un ambiente di prova con un'altra coppia, generata con
   `php bin/generate-keys.php`, si cambia la costante in `src/constants.php` e si
   rigenera il file con la build (oppure la si ridefinisce in `wp-config.php`).
2. Copiare `wp-health-check.php` via SFTP/SSH in
   `wp-content/mu-plugins/wp-health-check.php` sul sito target. Nessuna
   configurazione aggiuntiva è richiesta: non si tocca `wp-config.php`.
3. Il plugin è ora attivo (i mu-plugins non richiedono attivazione) ma il sito è
   in stato "non registrato": ogni rotta dati risponde `503 wphc_not_enrolled`
   finché non si completa l'enroll.

### Installazione assistita (plugin installer)

In alternativa alla copia manuale via SFTP, la cartella
[`installer/`](installer/) contiene un piccolo **plugin normale**
(`wp-health-check-installer`) che automatizza il primo deploy. Si carica da
**Plugin → Aggiungi nuovo → Carica plugin** con lo ZIP
`wp-health-check-installer.zip` (allegato alle release GitHub) e, **all'attivazione**:

- se `mu-plugins/wp-health-check.php` esiste già ed è alla versione dell'ultima
  release (o più recente) → non fa nulla e lo segnala con una notice; se invece è
  più vecchio lo **sostituisce con l'ultima release** (dalla 1.3.0), con le stesse
  verifiche: è la via per i siti con agent < 1.35.0, che non conoscono la chiave
  `k2` e non possono aggiornarsi da soli (basta ricaricare lo ZIP e riattivare);
- altrimenti verifica/crea la cartella `mu-plugins`, controlla i permessi di
  scrittura, **scarica l'ultima release** di `wp-health-check.php` da GitHub, ne
  verifica lo **SHA-256** e la **firma Ed25519** del centro (chiave `k2`
  incorporata, obbligatoria dalla 1.2.0: senza firma valida l'installazione si
  ferma) e la installa con scrittura atomica (file temporaneo e `rename()`); la
  versione nell'header del file deve coincidere con il tag della release;
- in caso di problema lascia una notice con il **motivo preciso** (permessi,
  download, integrità, scrittura).

Usa funzioni filesystem native (nessuna richiesta di credenziali FTP in
attivazione). Dopo l'installazione l'installer può essere disattivato ed
eliminato: il mu-plugin resta attivo e si auto-aggiorna. Nota: se serve una
chiave pubblica del centro diversa da quella incorporata nella release, dopo
l'installazione va comunque impostata (o si distribuisce una release del
mu-plugin con la chiave già incorporata).

### Procedura di enroll

Dal sistema centrale, calcolare `url_normalizzato` e `token` per il sito (vedi
[Il modello del token](#il-modello-del-token)), firmare la busta con la chiave
privata Ed25519 del centro, e chiamare `POST /enroll` (vedi
[esempio curl completo](#post-enroll--bootstrap-firmato) sopra). Ripetere l'enroll
non è sempre innocuo. Sul protocollo 1 una busta ancora fresca (±300 secondi) riscrive lo
stesso token derivato. Sul protocollo 2 la busta è monouso: lo stesso payload ripetuto è
rifiutato con `401 wphc_enroll_replay`, mentre una busta nuova con un token diverso
sovrascrive il segreto del sito. Su un sito già al protocollo 2 una busta v1 è rifiutata
con `409 wphc_enroll_downgrade`. Dopo una revoca un nuovo enroll riesce ma non toglie
`wp_health_check_revoked_at`: le rotte dati restano in `403 wphc_revoked` fino al reset
dell'enrollment (vedi sotto).

### Reset via WP-CLI

```bash
wp health-check reset
```

Cancella tutte le opzioni di enrollment (`wp_health_check_token`,
`wp_health_check_dashboard_origin`, `wp_health_check_enrolled_at`,
`wp_health_check_enrolled_ip`, `wp_health_check_enroll_issued_at`, e i timestamp di
ultimo accesso). È una utility operativa di re-provisioning/offboarding — ad
esempio quando un sito cambia dominio, o esce dalla flotta — **non** un
meccanismo di scadenza: dopo il reset il sito torna semplicemente allo stato "non
registrato" finché il centro non ripete l'enroll. Stessa identica azione
disponibile anche dal pulsante "Resetta enrollment" nella [tab Site
Health](#tab-site-health), per chi non ha accesso a WP-CLI. Dalla `1.30.0`
cancella anche le opzioni del protocollo v2 (`wp_health_check_token_prev`,
`_token_rotated_at`, `_protocol`, `_revoked_at`, `_secret_kid`,
`_autologin_pending`) e `wp_health_check_last_autologin` (che prima
sopravviveva erroneamente al reset).

Due sottocomandi diagnostici in più, dalla `1.30.0`:

```bash
wp health-check secret          # fingerprint, protocollo, data di rotazione, stato di revoca
wp health-check secret --show   # come sopra, ma stampa anche il segreto in chiaro
wp health-check status          # enrollment, versione, protocollo, ultimo errore di enroll, stato thumbnail
```

### Rollback dopo un self-update

Il flusso ripristina già automaticamente dal backup se la scrittura o il sanity
check del passo 9 falliscono. Dalla 1.36.0 `wp-health-check.php.bak` **non resta**
in `mu-plugins/` dopo un update riuscito (restava per sempre una copia del codice
precedente accanto al plugin): se un update riuscito lascia il sito in uno stato
inatteso, si reinstalla la release precedente, via SFTP/SSH, dall'asset
`wp-health-check.php` della pagina release GitHub del tag voluto, dopo aver
verificato l'hash con l'asset `.sha256`.

Fino alla 1.35.0 il `.bak` restava e bastava
`cp wp-content/mu-plugins/wp-health-check.php.bak wp-content/mu-plugins/wp-health-check.php`.

## Tab Site Health

Da `1.4.0`, in **Strumenti → Salute del sito** compare una tab **"WP Health
Check"** (registrata via i filtri/azioni core `site_health_navigation_tabs` e
`site_health_tab_content`, disponibili da WordPress 5.8), visibile solo agli
utenti con capability `manage_options`. Mostra:

- **versione installata** e **ultima versione disponibile** su GitHub (check
  cachato 1h in un transient; se esiste un aggiornamento viene evidenziato);
- coordinate del repository GitHub configurato;
- stato di enrollment (registrato/non registrato, data e IP dell'enroll);
- il **segreto di flotta** (dalla `1.30.0`): mascherato per default
  (`<primi 6>••••••••<ultimi 4>` più fingerprint SHA-256, protocollo e data di
  rotazione), con un pulsante "Mostra" che lo rivela per intero **solo** in
  risposta a una chiamata AJAX dedicata (mai stampato nel markup della
  pagina), e un pulsante "Copia". Ogni reveal lascia una riga
  `type='token', message='secret_revealed'` nel log degli aggiornamenti. Su
  multisite richiede anche `manage_network` (il segreto è condiviso dalla
  rete, non dal singolo sito). Se revocato, mostra "Revocato" e la data;
- l'**URL firmato registrato** (`wp_health_check_site_url`): la chiave a cui è
  legato il token;
- gli **URL validi per l'enroll** (`WPHC_Util::candidate_site_urls()`, con quello
  "principale" evidenziato): l'elenco esatto degli URL con cui il sistema
  centrale può firmare il `site_url` (confronto tollerante www/non-www, vedi
  [`POST /enroll`](#post-enroll--bootstrap-firmato));
- il **motivo dell'ultimo enroll fallito** (`wp_health_check_last_enroll_error`):
  codice, motivo, URL inviato, timestamp e IP — azzerato automaticamente al
  primo enroll riuscito, per diagnosticare rapidamente perché una busta viene
  rifiutata e con quale URL;
- ultimo accesso autenticato registrato (timestamp e IP);
- stato di `wp_health_check_trust_proxy` (sola lettura: va attivato solo
  manualmente via `wp option update`, mai da qui, vedi [Tracciamento
  accessi](#tracciamento-accessi)).

Tre pulsanti eseguono azioni:

- **Aggiorna il plugin**: innesca il self-update dall'ultima release GitHub —
  esattamente lo stesso flusso di `POST /update` (metodo condiviso
  `WPHC_Self_Updater::perform_self_update()`: verifica integrità SHA-256, backup, scrittura
  atomica, ripristino automatico in caso di errore). Non richiede enrollment
  (è un'azione amministrativa). L'esito è mostrato come avviso (aggiornato alla
  versione X / già aggiornato / errore con il motivo macchina).
- **Svuota cache e ricontrolla aggiornamenti** (dalla `1.13.0`): cancella le
  cache dell'agent (i transient `wphc_*`) e forza un ricontrollo **completo**
  degli aggiornamenti di core/plugin/temi. Gira in contesto amministrativo,
  dove gli update-checker dei plugin/temi **premium** sono attivi, quindi
  ricostruisce transient di update completi — cosa che `?fresh=1` via REST non
  può fare (vedi [Caching](#caching-per-rotta)). È lo strumento da usare quando
  i conteggi o le versioni degli aggiornamenti sembrano sbagliati.
- **Reset enrollment**: equivalente a `wp health-check reset` (stesso metodo
  condiviso `WPHC_Enrollment::reset()`), con conferma prima dell'esecuzione.

Nella riga "Anteprima sito", un pulsante aggiuntivo **"Rigenera anteprima ora"**
(dalla `1.30.0`) chiama sincronamente `WPHC_Thumbnail::maybe_generate( true )`
nella stessa richiesta admin, mostrando subito l'esito o l'errore diagnostico —
a differenza di "Elimina e rigenera" (che demanda la rigenerazione alla
prossima `/health`). **Nessun pulsante di rotazione/revoca del segreto**: il
sito non possiede `MASTER_SECRET` né la chiave privata Ed25519, quindi non può
auto-assegnarsi un segreto; quelle restano operazioni del centro (vedi
[Rotazione e revoca del segreto](#rotazione-e-revoca-del-segreto)).

Un checkbox separato (dalla `1.18.0`), **"Consenti aggiornamenti (plugin, temi,
core) via API"**, governa il kill-switch `wp_health_check_updates_enabled` (vedi
[Aggiornamento di plugin, temi e core via API](#aggiornamento-di-plugin-temi-e-core-via-api)):
acceso di default dalla `1.19.0`, va disattivato esplicitamente per i siti su
cui non si vuole consentire l'aggiornamento via API. Nello stesso form, un
secondo checkbox (dalla `1.24.0`), **"Limita gli aggiornamenti ai soli
pacchetti ospitati su wordpress.org (esclude i plugin/temi premium)"**,
governa `wp_health_check_restrict_official_only` (vedi
[Restrizione ai soli pacchetti ufficiali](#restrizione-ai-soli-pacchetti-ufficiali-opzionale)):
spento di default (qualsiasi plugin/tema è aggiornabile), va acceso
esplicitamente per i siti su cui si vuole escludere i plugin/temi premium
dall'aggiornamento via API.

Un campo separato (dalla `1.31.0`), **"Webhook di notifica aggiornamenti
bulk"**, imposta l'URL (`wp_health_check_webhook_url`, deve essere `https`)
a cui viene inviato il report firmato di fine job al termine di
[`POST /update/bulk`](#aggiornamenti-bulk-coreplugintemi-via-api--wp-cron).
Dalla `1.32.0`, un campo vuoto non significa più "spento": il sito usa il
default di flotta (vedi [Webhook di notifica firmato](#webhook-di-notifica-firmato))
a meno che non sia spuntato il checkbox **"Non inviare notifiche webhook per
questo sito"**, che governa `wp_health_check_webhook_disabled` e disattiva
anche il default. Un pulsante **"Invia un webhook di prova"** (evento
`wphc.test`, mai un retry pianificato) permette di verificare l'integrazione
HMAC del ricevente prima che giri un job reale; l'ultimo esito di consegna
(successo/errore, codice HTTP, host di destinazione) resta visibile sotto il
form.

Tutti i form inviano a `admin-post.php` (pattern standard di WordPress per
processare submission fuori dalla pagina che le genera), protetti da nonce
(`check_admin_referer()`) e dal controllo `manage_options`, con redirect alla
tab dopo l'azione (POST-redirect-GET).

Dalla `1.14.0`, sotto la tabella principale ci sono due sezioni in più:

- **Riepilogo plugin e temi**: una tabella con plugin e temi — totali, attivi e
  da aggiornare. I conteggi sono calcolati in contesto amministrativo (leggendo
  `get_plugins()`, `active_plugins` e i transient degli update mantenuti dal
  cron), quindi rispecchiano esattamente la schermata Plugin/Temi della bacheca.
- **Test degli endpoint**: un pulsante per ciascun endpoint dati GET (`/health`,
  `/detail/plugins`, `/detail/theme`, `/detail/server`, con le rispettive
  varianti `?fresh=1`) che esegue una chiamata reale e mostra la risposta (HTTP
  status, latenza, JSON formattato) in una finestra modale. La chiamata avviene
  via **loopback lato server** (handler AJAX `wphc_test_endpoint`, `manage_options`
  + nonce): il server chiama il proprio endpoint aggiungendo il bearer token e un
  cache-buster `_cb=<random>` casuale ad ogni click. Il token resta lato server e
  non viene mai esposto nel browser. `POST /update` e `POST /enroll` non sono nel
  tester (il primo ha il suo pulsante dedicato con effetti collaterali, il secondo
  richiede una busta firmata dal centro).
- **Log degli aggiornamenti** (dalla `1.29.0`): un pulsante "Visualizza log"
  apre nella stessa modale del tester una tabella con lo storico di
  `{$wpdb->prefix}wphc_update_log`, filtrabile per tipo e per origine
  (`api`/`wp-admin`/`cron`/`wp-cli`) e paginata con "Carica altri 50". A
  differenza del tester degli endpoint, qui non c'è loopback REST: un nuovo
  handler AJAX dedicato (`wphc_view_log`, `manage_options` + nonce) legge la
  tabella direttamente, perché l'utente è già autenticato in wp-admin e non
  serve il bearer token. Le righe sono renderizzate con `textContent`/
  `createElement`, mai `innerHTML`, perché `message`/`actor`/`target` sono
  dati che arrivano dal server.

> **Nota (dalla `1.10.0`):** la sezione per modificare
> `wp_health_check_dashboard_origin` dalla UI è stata rimossa, perché le
> chiamate alla flotta avvengono ora server-to-server (nessun browser, quindi
> CORS non rilevante lato dashboard). L'opzione e la logica CORS restano nel
> plugin (popolate dall'enroll, vedi [CORS](#cors)); semplicemente non si
> modificano più da questa pagina.

A differenza della tab "Informazioni" (sola lettura, alimentata dal filtro
core `debug_information` — la stessa fonte dati di `/detail/server`, vedi
sopra), questa tab consente azioni (update, reset), quindi il controllo di
accesso è `manage_options` e non la sola capability di visualizzare Site
Health (`view_site_health_checks`).

## Sviluppo locale

```bash
composer install           # PHPCS/WPCS, PHPCompatibilityWP, PHPStan, stub WordPress, PHPUnit
composer run build         # rigenera mu-plugins/wp-health-check.php da src/ (con strip)
composer run build:dev     # build senza strip in build/dev/ (debug), non tocca mu-plugins/
composer run build:check   # il file committato coincide con una build strip fresca di src/?
composer run test:build    # test della build stessa
composer run lint          # phpcs su src/ e bin/
composer run analyse       # phpstan su src/ e bin/

npx @wordpress/env start   # wp-env: container di sviluppo e container tests-cli
composer run test          # suite PHPUnit (PHP 8.1) dentro wp-env
python -I tests/e2e/run_all.py   # e2e con un sito WordPress vero (ambiente .wp-env.e2e.json)
```

Si modifica sempre `src/`, mai `mu-plugins/wp-health-check.php` (generato). Dopo ogni
modifica si rilancia `composer run build` e si committa il file rigenerato: il bootstrap di
PHPUnit rifiuta di partire se il file committato non coincide con una build strip fresca. Per la suite di un singolo
file, la matrice PHP, i golden e i vettori di contratto vedi
[`tests/README.md`](tests/README.md); per gli e2e e il test di upgrade
[`tests/e2e/README.md`](tests/e2e/README.md).

Changelog: [CHANGELOG.md](CHANGELOG.md) (formato
[Keep a Changelog](https://keepachangelog.com/it/1.0.0/)).
