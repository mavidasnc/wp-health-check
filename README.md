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
9. [Aggiornamento di plugin, temi e core via API](#aggiornamento-di-plugin-temi-e-core-via-api)
10. [Requisiti lato GitHub](#requisiti-lato-github)
11. [Caching per-rotta](#caching-per-rotta)
12. [CORS](#cors)
13. [Considerazioni e limiti di sicurezza](#considerazioni-e-limiti-di-sicurezza)
14. [Installazione, enroll, reset, rollback](#installazione-enroll-reset-rollback)
15. [Tab Site Health](#tab-site-health)
16. [Sviluppo locale](#sviluppo-locale)

---

## Architettura e scopo

Il plugin espone, sotto il namespace REST `health-check/v1`, un piccolo set di rotte
che permettono a un sistema centrale esterno di:

- registrare (*enroll*) un sito nella flotta, ottenendo un token di accesso;
- interrogare un sommario di salute economico e ad alta frequenza (`/health`);
- interrogare dettagli più costosi, on demand (`/detail/plugins`, `/detail/theme`,
  `/detail/server`);
- aggiornare il plugin stesso da una release GitHub firmata (`/update`);
- aggiornare, dietro consenso esplicito per sito, un singolo plugin/tema/il core del
  sito da wordpress.org (`/update/plugin`, `/update/theme`, `/update/core`), con
  storico consultabile via `/update/log`.

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

> Per un reference tecnico compatto di tutte le rotte (parametri, esempi di
> richiesta/risposta verificati, tabelle dei campi e dei codici di errore) vedi
> [docs/API health check.md](docs/API%20health%20check.md). Le sezioni qui sotto restano la spiegazione
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

Un replay dello stesso enroll (stesso URL) produce sempre lo stesso `token`
(derivazione deterministica): riscrive lo stesso valore, quindi è innocuo e **non è
implementato alcun controllo anti-replay**. Come irrobustimento **opzionale e non
implementato**, si potrebbe imporre una finestra di freschezza su `issued_at` (es.
300 secondi): è igiene dell'handshake, non necessaria per la sicurezza del token.

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
    "plugin_version": "1.0.0",
    "plugins_total": 18,
    "plugins_active": 14,
    "plugins_updates": 2,
    "themes_total": 3,
    "themes_updates": 0,
    "theme_name": "Astra Child",
    "parent_theme_name": "Astra",
    "core_update": false,
    "has_gdpr": true,
    "has_builder": false,
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
      "file": "akismet/akismet.php",
      "version": "5.3.2",
      "active": true,
      "update_available": false,
      "new_version": null
    },
    {
      "name": "WooCommerce",
      "slug": "woocommerce",
      "file": "woocommerce/woocommerce.php",
      "version": "8.9.1",
      "active": true,
      "update_available": true,
      "new_version": "8.9.3"
    }
  ]
}
```

Il campo `file` (dalla `1.19.0`) è il plugin file, chiave di `get_plugins()`:
è il valore esatto da passare come `plugin` a
[`POST /update/plugin`](#post-updateplugin-post-updatetheme), a differenza di
`slug` (solo la cartella, non univoco per i plugin a file singolo nella
radice di `wp-content/plugins/`).

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
  },
  "themes": [
    {
      "name": "Astra Child",
      "stylesheet": "astra-child",
      "version": "1.2.0",
      "active": true,
      "parent": "astra",
      "update_available": false,
      "new_version": null
    },
    {
      "name": "Astra",
      "stylesheet": "astra",
      "version": "4.6.1",
      "active": false,
      "parent": null,
      "update_available": true,
      "new_version": "4.6.5"
    }
  ]
}
```

L'array `themes` elenca **tutti** i temi installati sul sito (non solo l'attivo).
`parent` è lo `stylesheet` del tema parent per i child theme, altrimenti `null`.
I campi `active_theme`/`parent_theme` restano invariati per retrocompatibilità.

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
(protetta solo dal bearer token, come le altre rotte dati).

```bash
curl 'https://esempio.com/blog/wp-json/health-check/v1/update/log?type=plugin&limit=50' \
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
      "phase": "requested", "message": null, "ip": "203.0.113.7"
    }
  ]
}
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

Innescato da `POST /update` o dal pulsante "Aggiorna il plugin" nella [tab Site
Health](#tab-site-health) (entrambi usano la funzione condivisa
`wphc_perform_self_update()`), mai da un cron lato sito. Ordine rigoroso, ogni
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
[docs/plugin-update-via-api-analisi.md](docs/plugin-update-via-api-analisi.md)
e [docs/plugin-update-via-api-specifiche.md](docs/plugin-update-via-api-specifiche.md))
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

- **Allowlist dell'host del pacchetto** (`wphc_is_package_host_allowed()`):
  solo `downloads.wordpress.org` e `api.wordpress.org`. Copre di fatto i
  plugin/temi **premium** (che si aggiornano da server propri): vengono
  sempre rifiutati con `not_updatable`, in v1 non sono aggiornabili via API.
- **`sslverify` sempre attivo** (default della WP HTTP API di WordPress): mai
  disabilitato.

### Kill-switch per sito

Interruttore master `wp_health_check_updates_enabled`, **acceso di default**
(dalla `1.19.0`; disattivabile per singolo sito), gestito da un unico
checkbox nella [tab Site Health](#tab-site-health). A interruttore spento,
`POST /update/plugin`, `/update/theme` e `/update/core` rispondono
`403 disabled` **prima** di qualunque altra elaborazione; `GET /update/log`
resta invece sempre leggibile (è sola lettura).

### Flusso comune (plugin e temi)

1. Registrazione accesso, kill-switch, requisito **WordPress ≥ 6.3** (per la
   garanzia di rollback via *temp-backup* nativo introdotto in quella
   versione — sotto 6.3 la rotta risponde `unsupported_wp_version`, scelta
   fail-safe: niente update senza rete di sicurezza), lock anti-concorrenza
   (`wp_health_check_update_lock`, TTL 300s, rilascio garantito anche via
   `register_shutdown_function`), preflight filesystem (`get_filesystem_method()
   === 'direct'`, altrimenti `fs_method_unavailable` — mai raccolte
   credenziali FTP/SSH) e pulizia difensiva di un eventuale `.maintenance`
   orfano (`wphc_update_preflight()`).
2. Rilettura affidabile del transient di update (stesso
   `wphc_mute_update_shortcircuit()`/`wphc_restore_update_shortcircuit()` già
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
6. Invalidazione opcache dei file dell'elemento, invalidazione delle cache
   liste/transient (incluse quelle di `/health` e `/detail/*`), riga di log
   finale, rilascio del lock.

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

Nota tecnica: a differenza di plugin/temi, gli update object del core non
hanno un campo `->package`; il campo equivalente è `->download` (che, per un
update standard, coincide con `->packages->full`, il pacchetto che
`Core_Upgrader::upgrade()` scarica davvero nel percorso che questo plugin
percorre).

### Tabella di log degli aggiornamenti

Ogni operazione produce **due righe** nella tabella custom
`{$wpdb->prefix}wphc_update_log` (`id`, `correlation_id`, `created_at`,
`type`, `target`, `name`, `version_from`, `version_to`, `phase`, `message`,
`ip`), legate dallo stesso `correlation_id`: una **prima** di toccare
qualunque file (`phase = requested`, prova che un aggiornamento è stato
avviato anche se PHP muore a metà), una al termine (`completed` / `failed` /
`rolled_back`). La tabella non ha un hook di attivazione dedicato (i
mu-plugin non ne hanno): viene creata/allineata con `dbDelta()` al primo
caricamento in cui `wp_health_check_db_version` non combacia con lo schema
atteso. Le righe più vecchie di 90 giorni (`WP_HEALTH_CHECK_LOG_RETENTION_DAYS`)
vengono rimosse con un prune opportunistico (al massimo una volta al giorno,
gate via transient), senza dipendere da un cron dedicato. Il reset
enrollment (WP-CLI o tab Site Health) **non** cancella questo storico — solo
il lock anti-concorrenza — perché è audit del sito, non stato di enrollment.

`GET /health` espone tre campi derivati da questa funzionalità nel blocco
`summary`, tutti O(1) (coerenti col contratto economico della rotta):
`updates_via_api_enabled` (stato del kill-switch), `last_update` (oggetto
`{type, target, phase, at}` dell'ultima riga di log, letto da un'opzione
autoloaded aggiornata ad ogni update, non da una query alla tabella) e
`maintenance_stuck` (`true` se esiste un `.maintenance` più vecchio di 10
minuti: segnala un upgrade interrotto).

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
ripristinano subito dopo (vedi `wphc_mute_update_shortcircuit()`), lasciando
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

**Il default del core REST API di WordPress viene neutralizzato per questo
namespace.** WordPress registra di serie `rest_send_cors_headers()` su
`rest_pre_serve_request`, che per *qualunque* rotta REST riflette *qualunque*
`Origin` con `Access-Control-Allow-Credentials: true` — un comportamento
molto più permissivo di quello di questo plugin, e che girerebbe *dopo* la
nostra logica (vanificandola, perché `header()` sostituisce di default un
header già impostato con lo stesso nome). Per questo
`wphc_maybe_send_cors_headers()` viene richiamata una seconda volta su
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
`1.19.0`), e **solo** verso la versione che wordpress.org ha già pubblicato per
quell'elemento — mai un'installazione ex novo di software non presente sul
sito, mai una sorgente o versione indicata dal token compromesso stesso.

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

### Installazione assistita (plugin installer)

In alternativa alla copia manuale via SFTP, la cartella
[`installer/`](installer/) contiene un piccolo **plugin normale**
(`wp-health-check-installer`) che automatizza il primo deploy. Si carica da
**Plugin → Aggiungi nuovo → Carica plugin** con lo ZIP
`wp-health-check-installer.zip` (allegato alle release GitHub) e, **all'attivazione**:

- se `mu-plugins/wp-health-check.php` esiste già → non fa nulla e lo segnala con
  una notice;
- altrimenti verifica/crea la cartella `mu-plugins`, controlla i permessi di
  scrittura, **scarica l'ultima release** di `wp-health-check.php` da GitHub (con
  verifica SHA-256) e la installa;
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

- **versione installata** e **ultima versione disponibile** su GitHub (check
  cachato 1h in un transient; se esiste un aggiornamento viene evidenziato);
- coordinate del repository GitHub configurato;
- stato di enrollment (registrato/non registrato, data e IP dell'enroll);
- l'**URL firmato registrato** (`wp_health_check_site_url`): la chiave a cui è
  legato il token;
- gli **URL validi per l'enroll** (`wphc_candidate_site_urls()`, con quello
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
  esattamente lo stesso flusso di `POST /update` (funzione condivisa
  `wphc_perform_self_update()`: verifica integrità SHA-256, backup, scrittura
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
- **Reset enrollment**: equivalente a `wp health-check reset` (stessa funzione
  condivisa `wphc_reset_enrollment()`), con conferma prima dell'esecuzione.

Un checkbox separato (dalla `1.18.0`), **"Consenti aggiornamenti (plugin, temi,
core) via API"**, governa il kill-switch `wp_health_check_updates_enabled` (vedi
[Aggiornamento di plugin, temi e core via API](#aggiornamento-di-plugin-temi-e-core-via-api)):
acceso di default dalla `1.19.0`, va disattivato esplicitamente per i siti su
cui non si vuole consentire l'aggiornamento via API.

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
composer install        # PHPCS/WPCS, PHPCompatibilityWP, PHPStan + stub WordPress
composer run lint       # phpcs su mu-plugins/ e bin/
composer run analyse    # phpstan su mu-plugins/ e bin/

npx @wordpress/env start   # wp-env: monta mu-plugins/wp-health-check.php nel sito locale
```

Changelog: [CHANGELOG.md](CHANGELOG.md) (formato
[Keep a Changelog](https://keepachangelog.com/it/1.0.0/)).
