# WP Health Check — Fleet Agent

Must-use plugin WordPress a file singolo per il monitoraggio e il self-update di una
flotta di siti clienti, controllati da un sistema centrale esterno e da una dashboard.

- **Runtime distribuito:** `mu-plugins/wp-health-check.php` — un unico file PHP
  autoconsistente, senza dipendenze, compatibile **PHP 7.4+** e **WordPress 6.4+**.
- **Repository:** <https://github.com/mavidasnc/wp-health-check> (pubblico: contiene
  solo codice e chiavi *pubbliche*, mai segreti).

## Indice

1. [Architettura e scopo](#architettura-e-scopo)
2. [Tre scelte di interpretazione del brief](#tre-scelte-di-interpretazione-del-brief)
3. [Il problema del bootstrap e l'enroll firmato](#il-problema-del-bootstrap-e-lenroll-firmato)
4. [Il modello del token](#il-modello-del-token)
5. [Generazione della coppia di chiavi Ed25519](#generazione-della-coppia-di-chiavi-ed25519)
6. [Rotte REST](#rotte-rest)
7. [Tracciamento accessi](#tracciamento-accessi)
8. [Self-update: flusso passo per passo](#self-update-flusso-passo-per-passo)
9. [Requisiti lato GitHub](#requisiti-lato-github)
10. [Caching per-rotta](#caching-per-rotta)
11. [CORS](#cors)
12. [Considerazioni e limiti di sicurezza](#considerazioni-e-limiti-di-sicurezza)
13. [Installazione, enroll, reset, rollback](#installazione-enroll-reset-rollback)
14. [Tab Site Health](#tab-site-health)
15. [Sviluppo locale](#sviluppo-locale)

---

## Architettura e scopo

Il plugin espone, sotto il namespace REST `health-check/v1`, un piccolo set di rotte
che permettono a un sistema centrale esterno di:

- registrare (*enroll*) un sito nella flotta, ottenendo un token di accesso;
- interrogare un sommario di salute economico e ad alta frequenza (`/health`);
- interrogare dettagli più costosi, on demand (`/detail/plugins`, `/detail/theme`,
  `/detail/server`);
- aggiornare il plugin stesso da una release GitHub firmata (`/update`).

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
questo che il plugin deve restare un file singolo autoconsistente, senza
autoload PSR-4 a runtime.

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
   `.php` in radice): resta prioritario. L'autoload PSR-4 dichiarato in
   `composer.json` copre solo gli script di tooling del repository (es.
   `bin/generate-keys.php`), mai il plugin runtime, che non ha classi autoloaded.
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

Per scelta di progetto, il token è **per-sito, senza rotazione né scadenza**:

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
  `wp_health_check_token`. Nessuna rotazione, versione o invalidazione automatica è
  implementata — vedi anche la sezione [Considerazioni di sicurezza](#considerazioni-e-limiti-di-sicurezza).

### `url_normalizzato`, esattamente

Il centro deve ricalcolare **byte per byte** lo stesso valore che calcola il sito
(funzione `wphc_normalize_site_url()`), quindi la normalizzazione di `home_url()` è
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

Con il placeholder vuoto di default (`WP_HEALTH_CHECK_CENTRAL_PUBKEY = ''`), ogni
tentativo di `/enroll` fallisce la verifica della firma: è un comportamento
volutamente *fail closed* (nessun enroll possibile finché la chiave reale non è
incorporata), non *fail open*.

## Rotte REST

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
| `site_url` del payload diverso da quello del sito corrente | `403` | impedisce il riuso di una busta destinata a un altro sito |
| Successo | `200` | `{ "enrolled": true, "site": "...", "agent_version": "1.0.0" }` |

Un replay dello stesso enroll (stesso URL) produce sempre lo stesso `token`
(derivazione deterministica): riscrive lo stesso valore, quindi è innocuo e **non è
implementato alcun controllo anti-replay**. Come irrobustimento **opzionale e non
implementato**, si potrebbe imporre una finestra di freschezza su `issued_at` (es.
300 secondi): è igiene dell'handshake, non necessaria per la sicurezza del token.

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
    "plugin_version": "1.0.0",
    "plugins_total": 18,
    "plugins_active": 14,
    "plugins_updates": 2,
    "themes_updates": 0,
    "core_update": false,
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
  "plugins": [
    {
      "name": "Akismet Anti-spam",
      "slug": "akismet",
      "version": "5.3.2",
      "active": true,
      "update_available": false,
      "new_version": null
    },
    {
      "name": "WooCommerce",
      "slug": "woocommerce",
      "version": "8.9.1",
      "active": true,
      "update_available": true,
      "new_version": "8.9.3"
    }
  ]
}
```

### `GET /detail/theme` — tema attivo e parent

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/detail/theme' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

```json
{
  "site": "https://esempio.com/blog",
  "generated_at": "2026-07-08T10:00:00+00:00",
  "active_theme": {
    "name": "Astra Child",
    "stylesheet": "astra-child",
    "version": "1.2.0",
    "update_available": false,
    "new_version": null
  },
  "parent_theme": {
    "name": "Astra",
    "version": "4.6.1"
  }
}
```

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
{ "updated": true, "from": "1.0.0", "to": "1.1.0" }
```

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
esposta da nessuna rotta di questo plugin): in quel caso l'IP viene letto dal primo
valore valido in `X-Forwarded-For`. **`X-Forwarded-For` è un header fornito dal
client e quindi falsificabile a piacere**: è attendibile solo se un proxy fidato lo
sovrascrive sempre prima che la richiesta raggiunga PHP. Va attivato
consapevolmente, mai per default.

## Self-update: flusso passo per passo

Innescato solo da `POST /update`, mai da un cron lato sito. Ordine rigoroso, ogni
passo che fallisce interrompe il flusso **prima** di toccare il file di produzione:

1. **Autenticazione** via token (già garantita dal `permission_callback`) e
   registrazione dell'accesso.
2. **Interrogazione GitHub**:
   `GET https://api.github.com/repos/{owner}/{repo}/releases/latest`, con header
   `Accept: application/vnd.github+json` e `User-Agent: wp-health-check-agent`
   (GitHub rifiuta le richieste API senza User-Agent). Timeout 15s. Errori di rete o
   status diverso da 200 producono un `WP_Error` con status 502.
3. **Normalizzazione e confronto versione**: il `tag_name` della release perde un
   eventuale prefisso `v`, poi si confronta con `WP_HEALTH_CHECK_VERSION` via
   `version_compare()`. Se non è più recente: `200 { "updated": false, "reason": "up_to_date", ... }`.
4. **Preflight di scrittura**: si scrive un file temporaneo a nome casuale in
   `WPMU_PLUGIN_DIR` (es. `.wphc-writetest-<rand>`). Se fallisce:
   `200 { "updated": false, "reason": "not_writable" }`, senza aver toccato altro.
5. **Download**: si cerca fra gli `assets` della release quello di nome
   `wp-health-check.php` e si scarica da `browser_download_url`. Timeout 30s.
6. **Verifica di integrità**, prima di toccare qualunque file di produzione:
   - hash atteso recuperato dall'asset affiancato `wp-health-check.php.sha256`
     (supporta sia il formato `sha256sum`, `"<hash>  <nomefile>"`, sia il solo
     hash), oppure da una riga `"sha256: <hash>"` nel corpo della release come
     fallback;
   - confronto con `hash('sha256', $contenuto)` via `hash_equals()` (tempo
     costante);
   - il contenuto non deve essere vuoto, deve iniziare con `<?php` e deve
     contenere `"Version: <tag>"` coerente col tag scaricato.
   - Se una qualunque verifica fallisce: si cancella il file di test del punto 4
     e si risponde `200 { "updated": false, "reason": "integrity_check_failed" }`.
7. **Backup**: il file corrente viene copiato in `wp-health-check.php.bak`.
8. **Scrittura atomica**: il nuovo contenuto viene scritto in un file temporaneo
   nella *stessa directory* (con estensione neutra, non `.php` — vedi nota sotto),
   poi si esegue `rename()` su `__FILE__`. `rename()` sullo stesso filesystem è
   atomico: non lascia mai il file di produzione "a metà scritto".
9. **Sanity check post-scrittura**: si rilegge il file, si verifica che non sia
   vuoto e che inizi con `<?php`. Se qualcosa non torna, si ripristina
   immediatamente dal `.bak` del punto 7.
10. **Invalidazione opcache**: `opcache_invalidate( __FILE__, true )` se
    disponibile; altrimenti la vecchia versione compilata resta in memoria fino al
    riavvio del pool PHP (es. php-fpm).
11. Il file di test del punto 4 viene cancellato.
12. Risposta `200 { "updated": true, "from": "...", "to": "..." }`.

Ogni ramo d'errore restituisce un `WP_Error` con status HTTP coerente e un codice
macchina stabile (`wphc_update_network_error`, `wphc_update_bad_release`,
`wphc_update_asset_missing`, `wphc_update_download_failed`,
`wphc_update_backup_failed`, `wphc_update_write_failed`,
`wphc_update_sanity_failed`). Gli esiti vengono anche loggati con `error_log()`,
ma solo se `WP_DEBUG` è attivo.

**Nota tecnica sul file temporaneo (passo 8):** il temporaneo non ha
deliberatamente estensione `.php`. Se il `rename()` fallisse lasciando un file
orfano nella cartella, un file con estensione `.php` in `mu-plugins/` verrebbe
caricato automaticamente da WordPress al giro successivo, ridichiarando tutte le
funzioni del plugin e mandando in fatal error l'intero sito; un'estensione neutra
rende questo scenario innocuo.

**Nota tecnica su `WP_Filesystem`:** il plugin usa funzioni filesystem PHP native
(`file_put_contents`, `copy`, `rename`) invece dell'astrazione `WP_Filesystem` del
core. È una scelta di progetto, non una svista: il file deve restare
autoconsistente e funzionare anche quando `WP_Filesystem` non è inizializzato o
richiederebbe credenziali FTP (scenario comune per un mu-plugin, che gira prima di
molte inizializzazioni dell'admin).

## Requisiti lato GitHub

Ogni release del repository deve fornire:

- un **tag** di versione (es. `v1.1.0` o `1.1.0`, il prefisso `v` viene rimosso in
  fase di confronto);
- un asset binario chiamato esattamente **`wp-health-check.php`** — il file
  completo, pronto per la produzione, con l'header del plugin che dichiara
  `Version: <tag senza "v">`;
- un asset affiancato **`wp-health-check.php.sha256`** con l'hash SHA-256 del file
  sopra (formato `sha256sum` o hash nudo), oppure, in alternativa, una riga
  `sha256: <hash>` nel corpo/note della release.

## Caching per-rotta

| Rotta | Cache | TTL | Note |
|---|---|---|---|
| `GET /health` | transient `wphc_health_cache` | 60s (opzionale) | Nessuna chiamata remota, mai `debug_data()`. Pensata per polling frequente: vedi sotto il perché. |
| `GET /detail/plugins` | transient `wphc_detail_plugins_cache` | 1h | Legge transient di update già esistenti. |
| `GET /detail/theme` | transient `wphc_detail_theme_cache` | 1h | Idem. |
| `GET /detail/server` | transient `wphc_detail_server_cache` | 12h | Config server cambia raramente; è la chiamata più onerosa. |

`?fresh=1` (su `/health` e su tutte le rotte `/detail/*`) è l'**unico** modo per
forzare un refresh. Sulle rotte che riportano aggiornamenti, esegue prima le
funzioni di controllo remoto del core (`wp_version_check()`, `wp_update_plugins()`,
`wp_update_themes()`): è il ramo lento del plugin, da usare solo su richiesta
esplicita dell'operatore nella dashboard (drill-down), mai nel polling automatico.

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
qualunque header CORS: vedi `wphc_maybe_send_cors_headers()`. Se un sito
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
Access-Control-Allow-Headers: Authorization, Content-Type
Vary: Origin
```

Se invece `wp_health_check_dashboard_origin` **non è impostata** (sito appena
installato, prima del primo `/enroll`, oppure resettato con
`wp health-check reset`), viene autorizzata **qualunque origin** presente
nell'header `Origin` della richiesta (riflessa nell'`Access-Control-Allow-Origin`
della risposta, mai un wildcard letterale `*`): è una scelta deliberata per non
bloccare le chiamate durante il setup o lo sviluppo, prima che una dashboard sia
stata registrata. Il controllo di accesso vero e proprio resta comunque il
bearer token (`/health`, `/detail/*`, `/update`) o la firma Ed25519 (`/enroll`):
CORS qui è difesa in profondità aggiuntiva, non il perimetro di sicurezza
primario.

Le richieste `OPTIONS` (preflight del browser) vengono intercettate a livello di
`rest_pre_dispatch` per l'intero namespace `health-check/v1`, prima del routing
normale: rispondono `200` con i soli header CORS, **senza autenticazione** — il
preflight del browser non include mai `Authorization`.

## Considerazioni e limiti di sicurezza

**Raggio d'azione di una compromissione del token.** Il token di un sito da
accesso in lettura ai dati di quel sito e alla possibilità di innescarne il
self-update da una release firmata. Non da accesso amministrativo a WordPress (non
c'è login, non ci sono capability utente coinvolte): un token rubato non permette
di installare plugin arbitrari, solo di far scaricare la release *attualmente
pubblicata* sul repository GitHub configurato, che è comunque verificata via
firma/sha256 (vedi sotto). Il raggio d'azione resta quindi limitato al perimetro
di ciò che queste rotte espongono.

**Trasmissione del token nell'enroll.** Il token viaggia in chiaro (via HTTPS) nel
payload di `/enroll`: è protetto in transito da TLS, non da un ulteriore livello di
cifratura applicativa. Chi intercetta il traffico *dopo* aver rotto TLS (scenario
già catastrofico di per sé) potrebbe leggere il token; è un compromesso accettato
per il modello "il centro consegna un valore già calcolato", più semplice e senza
stato rispetto ad alternative come una PoP key o un secondo fattore di conferma.

**Scenario account GitHub compromesso.** Se l'account che pubblica le release sul
repository venisse compromesso, un attaccante potrebbe pubblicare una release
malevola. Le mitigazioni sono su due livelli indipendenti:

1. l'asset `.sha256` (o la riga `sha256:` nelle note di release) dovrebbe essere
   prodotto e pubblicato con un processo separato da quello che compromette
   l'account GitHub stesso (es. CI con chiavi diverse, pubblicazione manuale
   dell'hash da un canale fuori banda);
2. anche a valle di questo, **l'update non è mai automatico**: parte solo quando il
   sistema centrale chiama esplicitamente `/update` su ciascun sito. Un attaccante
   che pubblica una release malevola su GitHub non ha comunque modo di *innescare*
   l'update senza controllare anche il sistema centrale (che possiede il token di
   ogni sito).

La verifica di integrità (sha256 + prefisso `<?php` + coerenza versione) resta
comunque un controllo cieco rispetto al *contenuto* del codice: non sostituisce una
revisione umana delle release pubblicate, che resta responsabilità di chi gestisce
il repository.

**Nessuna rotazione né scadenza del token.** È una scelta di progetto esplicita,
non un'omissione: il token resta valido finché non viene sostituito da un nuovo
enroll o cancellato con `wp health-check reset`. Se in futuro servisse rotazione
periodica, andrebbe introdotta come funzionalità nuova (non è prevista né
predisposta in questa versione).

## Installazione, enroll, reset, rollback

### Installazione manuale iniziale

1. Prima del deploy, generare (o riusare) la coppia di chiavi del centro con
   `php bin/generate-keys.php` e incollare la chiave pubblica in
   `WP_HEALTH_CHECK_CENTRAL_PUBKEY` dentro `mu-plugins/wp-health-check.php`.
2. Copiare `wp-health-check.php` via SFTP/SSH in
   `wp-content/mu-plugins/wp-health-check.php` sul sito target. Nessuna
   configurazione aggiuntiva è richiesta: non si tocca `wp-config.php`.
3. Il plugin è ora attivo (i mu-plugins non richiedono attivazione) ma il sito è
   in stato "non registrato": ogni rotta dati risponde `503 wphc_not_enrolled`
   finché non si completa l'enroll.

### Procedura di enroll

Dal sistema centrale, calcolare `url_normalizzato` e `token` per il sito (vedi
[Il modello del token](#il-modello-del-token)), firmare la busta con la chiave
privata Ed25519 del centro, e chiamare `POST /enroll` (vedi
[esempio curl completo](#post-enroll--bootstrap-firmato) sopra). Ripetere lo stesso
enroll in futuro è innocuo: sovrascrive con lo stesso token.

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
Health](#tab-site-health), per chi non ha accesso a WP-CLI.

### Rollback da `.bak`

Se un self-update lascia il sito in uno stato inatteso nonostante i controlli
(caso limite, dato che il flusso ripristina già automaticamente dal backup se il
sanity check del passo 9 fallisce), il rollback manuale è immediato: via SFTP/SSH,

```bash
cp wp-content/mu-plugins/wp-health-check.php.bak wp-content/mu-plugins/wp-health-check.php
```

Il `.bak` viene sovrascritto ad ogni update riuscito con la versione
immediatamente precedente (non è uno storico multiplo).

## Tab Site Health

Da `1.4.0`, in **Strumenti → Salute del sito** compare una tab **"WP Health
Check"** (registrata via i filtri/azioni core `site_health_navigation_tabs` e
`site_health_tab_content`, disponibili da WordPress 5.8), visibile solo agli
utenti con capability `manage_options`. Mostra:

- versione del plugin e coordinate del repository GitHub configurato;
- stato di enrollment (registrato/non registrato, data e IP dell'enroll);
- ultimo accesso autenticato registrato (timestamp e IP);
- stato di `wp_health_check_trust_proxy` (sola lettura: va attivato solo
  manualmente via `wp option update`, mai da qui, vedi [Tracciamento
  accessi](#tracciamento-accessi));
- un campo per **leggere e modificare** `wp_health_check_dashboard_origin`
  (lasciarlo vuoto riautorizza qualunque origin, vedi [CORS](#cors));
- un pulsante di **reset enrollment**, equivalente a `wp health-check reset`
  (stessa funzione condivisa `wphc_reset_enrollment()`), con conferma prima
  dell'esecuzione.

I due form inviano a `admin-post.php` (pattern standard di WordPress per
processare submission fuori dalla pagina che le genera), protetti da nonce
(`check_admin_referer()`) e dallo stesso controllo `manage_options`, con
redirect alla tab dopo il salvataggio (POST-redirect-GET). L'origin inserita
viene validata (schema `http`/`https`, host presente, nessun path/query):
un valore non valido non viene salvato e mostra un errore, senza toccare
l'opzione esistente.

A differenza della tab "Informazioni" (sola lettura, alimentata dal filtro
core `debug_information` — la stessa fonte dati di `/detail/server`, vedi
sopra), questa tab consente modifiche, quindi il controllo di accesso è
`manage_options` e non la sola capability di visualizzare Site Health
(`view_site_health_checks`).

## Sviluppo locale

```bash
composer install        # PHPCS/WPCS, PHPCompatibilityWP, PHPStan + stub WordPress
composer run lint       # phpcs su mu-plugins/ e bin/
composer run analyse    # phpstan su mu-plugins/ e bin/

npx @wordpress/env start   # wp-env: monta mu-plugins/wp-health-check.php nel sito locale
```

Changelog: [CHANGELOG.md](CHANGELOG.md) (formato
[Keep a Changelog](https://keepachangelog.com/it/1.0.0/)).
