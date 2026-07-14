# WP Health Check — API Reference

Reference tecnico delle rotte REST esposte dal fleet agent
(`mu-plugins/wp-health-check.php`). Le rotte `/enroll` → `/update` sono
aggiornate all'agent **1.9.0** (salvo il campo `file` di `/detail/plugins`,
aggiunto con l'agent **1.19.0**); le rotte `/update/plugin`, `/update/theme`,
`/update/core` e `/update/log`, aggiunte con l'agent **1.18.0**, sono
documentate nelle sezioni dedicate in fondo.

Per il razionale di progetto (perché mu-plugin, modello del token, flusso di
self-update, considerazioni di sicurezza) vedi [README.md](../README.md):
questo documento è la sola scheda operativa delle chiamate e delle risposte.

## Indice

1. [Informazioni generali](#informazioni-generali)
2. [Autenticazione](#autenticazione)
3. [Convenzioni comuni](#convenzioni-comuni)
4. [`POST /enroll`](#post-enroll)
5. [`GET /health`](#get-health)
6. [`GET /detail/plugins`](#get-detailplugins)
7. [`GET /detail/theme`](#get-detailtheme)
8. [`GET /detail/server`](#get-detailserver)
9. [`POST /update`](#post-update)
10. [`POST /update/plugin`, `POST /update/theme`](#post-updateplugin-post-updatetheme)
11. [`POST /update/core`](#post-updatecore)
12. [`GET /update/log`](#get-updatelog)
13. [Riferimento codici di errore](#riferimento-codici-di-errore)

---

## Informazioni generali

| | |
|---|---|
| **Base URL** | `https://<sito>/wp-json/health-check/v1` |
| **Namespace REST** | `health-check/v1` |
| **Formato** | JSON in richiesta e risposta (`Content-Type: application/json`) |
| **Versione agent** | `1.9.0` (esposta in `fleet_agent_version` / `agent_version` / `plugin_version`) |
| **Preflight CORS** | Ogni rotta gestisce `OPTIONS` senza autenticazione (solo header CORS, `200`) |

Sintesi delle rotte:

| Metodo | Rotta | Auth | Query | Cache lato agent |
|---|---|---|---|---|
| `POST` | `/enroll` | Firma Ed25519 (nel body) | — | nessuna |
| `GET` | `/health` | Bearer token | `?fresh=1` | transient 60s |
| `GET` | `/detail/plugins` | Bearer token | `?fresh=1` | transient 1h |
| `GET` | `/detail/theme` | Bearer token | `?fresh=1` | transient 1h |
| `GET` | `/detail/server` | Bearer token | `?fresh=1` | transient 12h |
| `POST` | `/update` | Bearer token | — | nessuna |
| `POST` | `/update/plugin` | Bearer token + kill-switch | `?check=1` | nessuna |
| `POST` | `/update/theme` | Bearer token + kill-switch | `?check=1` | nessuna |
| `POST` | `/update/core` | Bearer token + kill-switch | `?check=1` | nessuna |
| `GET` | `/update/log` | Bearer token | `type`, `limit`, `offset` | nessuna |

---

## Autenticazione

Esistono **due meccanismi distinti**, uno per la sola rotta di bootstrap e uno
per tutte le rotte dati.

### `/enroll` — firma Ed25519

La rotta di enroll non richiede alcun token (è il momento in cui il token viene
consegnato). L'autenticazione è una **firma Ed25519** nel corpo della
richiesta, prodotta dal sistema centrale con la propria chiave privata e
verificata dal sito con la chiave pubblica incorporata nel plugin
(`WP_HEALTH_CHECK_CENTRAL_PUBKEY`). Vedi il dettaglio in
[`POST /enroll`](#post-enroll).

### `/health`, `/detail/*`, `/update` — Bearer token

Tutte le rotte dati richiedono l'header:

```
Authorization: Bearer <token>
```

Il token è quello consegnato in fase di enroll, conservato dal sito in
`wp_health_check_token` e confrontato in tempo costante (`hash_equals`). Header
mancante e token errato restituiscono **lo stesso** errore `401`, per non
rivelare quale dei due casi si sia verificato.

| Condizione | Status | `code` |
|---|---|---|
| Sito mai registrato (nessun token salvato) | `503` | `wphc_not_enrolled` |
| Header `Authorization` assente o non `Bearer` | `401` | `wphc_unauthorized` |
| Token errato | `401` | `wphc_unauthorized` |

---

## Convenzioni comuni

**Campi ricorrenti nelle risposte 200:**

- `site` — URL normalizzato del sito (schema/host minuscoli, senza slash
  finale), es. `https://esempio.com`;
- `generated_at` — timestamp ISO 8601 UTC di generazione della risposta.

**Formato degli errori.** Ogni errore è un `WP_Error` serializzato da WordPress:

```json
{
  "code": "wphc_unauthorized",
  "message": "Non autorizzato.",
  "data": { "status": 401 }
}
```

Lo status HTTP della risposta coincide con `data.status`.

**`?fresh=1`.** Su `/health` e su tutte le rotte `/detail/*` bypassa le cache
locali dell'agent (payload wphc + cache delle liste plugin/temi) e rilegge lo
stato corrente del sito. **Non** forza un controllo remoto degli aggiornamenti:
i conteggi/versioni di update vengono sempre letti dai transient mantenuti dal
cron di WordPress (`update_plugins`/`update_themes`/`update_core`), gli stessi
della bacheca — perché forzare `wp_update_plugins()` da REST è inaffidabile per i
plugin/temi premium e sovrascriverebbe il transient completo del cron con uno
incompleto. Freschezza dell'ultimo check in `summary.updates_checked_at`.

**Cache.** Le risposte del namespace `health-check/v1` non vengono mai messe in
cache HTTP/edge (l'agent invia `nocache_headers()` e definisce `DONOTCACHEPAGE`
prima di ogni risposta). La cache indicata nelle tabelle è quella applicativa
dell'agent, via transient, separata dalla cache HTTP.

**CORS.** Se `wp_health_check_dashboard_origin` è configurata, viene
autorizzata solo quell'origin esatta; se è vuota, viene riflessa qualunque
origin della richiesta (mai il wildcard letterale `*`). Vedi la sezione
[CORS del README](../README.md#cors).

> Negli esempi seguenti `esempio.com` è un segnaposto. I payload di risposta
> sono strutturalmente reali (verificati su un sito di produzione con agent
> 1.9.0); host, versioni e conteggi variano per sito.

---

## `POST /enroll`

Bootstrap firmato: consegna al sito il proprio token la prima volta, senza
toccare `wp-config.php`. Idempotente — ripetere lo stesso enroll riscrive lo
stesso valore (nessun controllo anti-replay necessario).

**Auth:** firma Ed25519 nel body (nessun token).

### Payload

```json
{
  "site_url": "https://esempio.com",
  "token": "hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI",
  "dashboard_origin": "https://fleet.esempio.com",
  "issued_at": 1735689600,
  "signature": "<firma base64 standard>"
}
```

| Campo | Tipo | Obbligatorio | Note |
|---|---|---|---|
| `site_url` | string | sì | URL normalizzato del sito target; deve combaciare byte per byte con quello del sito |
| `token` | string | sì | Token opaco calcolato dal centro |
| `dashboard_origin` | string \| null | no | Origin della dashboard per CORS; `null` = nessuna origin configurata |
| `issued_at` | int | sì | Timestamp Unix di emissione |
| `signature` | string | sì | Firma Ed25519 (base64) del messaggio canonico |

La `signature` copre la concatenazione canonica, con `"\n"` come separatore:

```
site_url + "\n" + token + "\n" + dashboard_origin + "\n" + issued_at
```

Se `dashboard_origin` è `null`, il suo posto nella concatenazione è la **stringa
vuota** (non la stringa letterale `"null"`).

### Esempio di richiesta

```bash
curl -X POST 'https://esempio.com/wp-json/health-check/v1/enroll' \
  -H 'Content-Type: application/json' \
  -d '{
    "site_url": "https://esempio.com",
    "token": "hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI",
    "dashboard_origin": "https://fleet.esempio.com",
    "issued_at": 1735689600,
    "signature": "nByXUXT7CjOZromTGSNyA5akd1/71ByYS454BwM2egSoM7upczL5AH+0i0LIk2/5Fx2hdbkjO3mWfmmmoRi6DA=="
  }'
```

### Confronto URL tollerante

Il `site_url` firmato non deve combaciare byte per byte con `home_url()`: il
sito costruisce un **set di URL canonici candidati** — `home_url()`,
`site_url()`, `network_home_url()`, `network_site_url()`, ciascuno anche nella
variante con/senza `www.` — li normalizza tutti con la regola del centro e
accetta l'enroll se il `site_url` firmato normalizzato è nel set. Questo evita
403 spurii su siti WPML (dove `home_url()` varia per lingua), dietro reverse
proxy o con varianti www/non-www. La verifica della firma resta **prima e
obbligatoria**. Il sito memorizza esattamente il `site_url` firmato ricevuto e
lo restituisce nel campo `site`.

### Risposta `200`

```json
{
  "enrolled": true,
  "site": "https://esempio.com",
  "agent_version": "1.9.0"
}
```

Il campo `site` è il `site_url` firmato realmente registrato (la chiave a cui è
legato il token), non necessariamente `home_url()`.

### Errori

| Status | `code` | Caso |
|---|---|---|
| `400` | `wphc_enroll_invalid_body` | Corpo non JSON o non oggetto |
| `400` | `wphc_enroll_missing_field` | Manca `site_url`, `token`, `issued_at` o `signature` |
| `401` | `wphc_enroll_unauthorized` | Firma non valida (messaggio generico) |
| `403` | `wphc_enroll_url_mismatch` | `site_url` firmato non tra le varianti canoniche del sito |

Il `403 wphc_enroll_url_mismatch` include nel corpo l'URL atteso, per diagnosi:

```json
{
  "code": "wphc_enroll_url_mismatch",
  "message": "URL atteso: https://esempio.com — ricevuto: https://altro-dominio.it",
  "data": {
    "status": 403,
    "expected": "https://esempio.com",
    "received": "https://altro-dominio.it"
  }
}
```

---

## `GET /health`

Sommario economico, pensato per il polling frequente. Non chiama mai
`WP_Debug_Data::debug_data()` né forza check remoti. Legge i conteggi di
aggiornamento direttamente dai transient del cron, senza gating per capability
utente (l'autenticazione è il bearer token, non una sessione).

**Auth:** Bearer token. **Query:** `?fresh=1` opzionale. **Cache:** transient 60s.

### Esempio di richiesta

```bash
curl 'https://esempio.com/wp-json/health-check/v1/health' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposta `200`

```json
{
  "site": "https://esempio.com",
  "generated_at": "2026-07-09T08:00:00+00:00",
  "fleet_agent_version": "1.9.0",
  "summary": {
    "wp_version": "7.0",
    "php_version": "8.3.30",
    "php_memory_limit": "2G",
    "server_ip": "203.0.113.10",
    "plugin_version": "1.9.0",
    "plugins_total": 21,
    "plugins_active": 14,
    "plugins_updates": 1,
    "themes_total": 4,
    "themes_updates": 0,
    "theme_name": "miziomon",
    "parent_theme_name": "Blocksy",
    "core_update": false,
    "mu_dir_writable": true,
    "updates_checked_at": "2026-07-09T07:50:53+00:00"
  },
  "last_access": {
    "at": "2026-07-09T07:59:00+00:00",
    "ip": "203.0.113.7",
    "enrolled_at": "2026-07-08T15:41:15+00:00"
  },
  "detail_routes": {
    "plugins": "https://esempio.com/wp-json/health-check/v1/detail/plugins",
    "theme": "https://esempio.com/wp-json/health-check/v1/detail/theme",
    "server": "https://esempio.com/wp-json/health-check/v1/detail/server"
  }
}
```

### Campi

| Campo | Tipo | Note |
|---|---|---|
| `summary.wp_version` | string | Versione di WordPress |
| `summary.php_version` | string | Versione di PHP |
| `summary.php_memory_limit` | string | `memory_limit` di PHP (`ini_get`) |
| `summary.server_ip` | string \| null | IP del server WordPress (`SERVER_ADDR`), `null` se non determinabile |
| `summary.plugin_version` | string | Versione di questo agent |
| `summary.plugins_total` | int | Plugin installati (attivi + inattivi) |
| `summary.plugins_active` | int | Plugin attivi |
| `summary.plugins_updates` | int | Plugin con aggiornamento disponibile |
| `summary.themes_total` | int | Temi installati |
| `summary.themes_updates` | int | Temi con aggiornamento disponibile |
| `summary.theme_name` | string | Nome del tema attivo |
| `summary.parent_theme_name` | string \| null | Nome del tema parent, `null` se il tema attivo non è un child |
| `summary.core_update` | bool | `true` se è disponibile un aggiornamento core |
| `summary.mu_dir_writable` | bool | `true` se `WPMU_PLUGIN_DIR` è scrivibile (self-update possibile) |
| `summary.updates_checked_at` | string \| null | Ultimo check aggiornamenti del cron (ISO 8601) |
| `last_access.at` | string \| null | Timestamp dell'accesso **precedente** (segnale di audit) |
| `last_access.ip` | string \| null | IP dell'accesso precedente |
| `last_access.enrolled_at` | string \| null | Timestamp dell'enroll |
| `detail_routes` | object | URL assoluti delle rotte di dettaglio |

---

## `GET /detail/plugins`

Elenco completo dei plugin installati, con stato di aggiornamento per ciascuno.

**Auth:** Bearer token. **Query:** `?fresh=1` opzionale. **Cache:** transient 1h.

### Esempio di richiesta

```bash
curl 'https://esempio.com/wp-json/health-check/v1/detail/plugins' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposta `200`

```json
{
  "site": "https://esempio.com",
  "generated_at": "2026-07-09T08:04:37+00:00",
  "count": 21,
  "plugins": [
    {
      "name": "Phoenix Media Rename",
      "slug": "phoenix-media-rename",
      "file": "phoenix-media-rename/phoenix-media-rename.php",
      "version": "3.13.2",
      "active": true,
      "update_available": true,
      "new_version": "3.13.3"
    },
    {
      "name": "AI",
      "slug": "ai",
      "file": "ai/ai.php",
      "version": "1.1.0",
      "active": true,
      "update_available": false,
      "new_version": null
    }
  ]
}
```

### Campi (per elemento di `plugins`)

| Campo | Tipo | Note |
|---|---|---|
| `name` | string | Nome del plugin |
| `slug` | string | Cartella del plugin, o nome file senza estensione per i plugin a file singolo |
| `file` | string | Plugin file (chiave di `get_plugins()`, es. `wordpress-seo/wp-seo.php`): valore da passare come `plugin` a `POST /update/plugin` |
| `version` | string | Versione installata |
| `active` | bool | `true` se attivo |
| `update_available` | bool | `true` se esiste un aggiornamento |
| `new_version` | string \| null | Versione disponibile, o `null` |

---

## `GET /detail/theme`

Dettaglio del tema attivo e dell'eventuale tema parent (per i child theme).

**Auth:** Bearer token. **Query:** `?fresh=1` opzionale. **Cache:** transient 1h.

### Esempio di richiesta

```bash
curl 'https://esempio.com/wp-json/health-check/v1/detail/theme' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposta `200`

```json
{
  "site": "https://esempio.com",
  "generated_at": "2026-07-09T08:04:37+00:00",
  "active_theme": {
    "name": "miziomon",
    "stylesheet": "miziomon",
    "version": "1.0",
    "update_available": false,
    "new_version": null
  },
  "parent_theme": {
    "name": "Blocksy",
    "version": "2.1.48"
  }
}
```

`parent_theme` è `null` quando il tema attivo non è un child theme.

### Campi

| Campo | Tipo | Note |
|---|---|---|
| `active_theme.name` | string | Nome del tema attivo |
| `active_theme.stylesheet` | string | Slug (directory) del tema |
| `active_theme.version` | string | Versione installata |
| `active_theme.update_available` | bool | `true` se esiste un aggiornamento |
| `active_theme.new_version` | string \| null | Versione disponibile, o `null` |
| `parent_theme` | object \| null | `null` se non è un child theme |
| `parent_theme.name` | string | Nome del tema parent |
| `parent_theme.version` | string | Versione del tema parent |

---

## `GET /detail/server`

Ambiente server / PHP / database. È la rotta più costosa (usa
`WP_Debug_Data::debug_data()` con un allowlist esplicito di campi), isolata
dietro la cache più lunga e mai chiamata dal polling di `/health`.

**Auth:** Bearer token. **Query:** `?fresh=1` opzionale. **Cache:** transient 12h.

### Esempio di richiesta

```bash
curl 'https://esempio.com/wp-json/health-check/v1/detail/server' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposta `200`

```json
{
  "site": "https://esempio.com",
  "generated_at": "2026-07-09T08:04:38+00:00",
  "server": {
    "software": "LiteSpeed",
    "server_ip": "203.0.113.10",
    "php_version": "8.3.30",
    "php_sapi": "litespeed",
    "php_memory_limit": "2G",
    "max_execution_time": "30",
    "max_input_vars": "1000",
    "upload_max_filesize": "8M",
    "post_max_size": "8M",
    "mysql_version": "10.11.16",
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

### Campi (dentro `server`)

| Campo | Tipo | Note |
|---|---|---|
| `software` | string | Software del server (es. `LiteSpeed`, `nginx/1.24.0`) |
| `server_ip` | string \| null | IP del server WordPress (`SERVER_ADDR`), `null` se non determinabile |
| `php_version` | string | Versione PHP |
| `php_sapi` | string | SAPI PHP (es. `fpm-fcgi`, `litespeed`) |
| `php_memory_limit` | string | `memory_limit` (`ini_get`) |
| `max_execution_time` | string | `max_execution_time` |
| `max_input_vars` | string | `max_input_vars` |
| `upload_max_filesize` | string | `upload_max_filesize` |
| `post_max_size` | string | `post_max_size` |
| `mysql_version` | string | Versione MySQL/MariaDB |
| `https` | bool | `true` se la richiesta è servita in HTTPS |
| `extensions` | object | Presenza (`bool`) delle estensioni `curl`, `imagick`, `gd`, `mbstring`, `intl` |

> I campi marcati `private` da `WP_Debug_Data` (utente e host del database) non
> compaiono mai: il payload è costruito con un allowlist esplicito.

---

## `POST /update`

Self-update dell'agent da una release GitHub firmata. Innescato da questa
chiamata o dal pulsante nella tab Site Health (stessa logica condivisa), mai da
un cron lato sito. Verifica versione → scrivibilità → download
→ integrità (SHA-256 + coerenza versione) → backup → scrittura atomica → sanity
check → invalidazione opcache. Ogni passo che fallisce interrompe il flusso
prima di toccare il file di produzione.

**Auth:** Bearer token. **Query:** —. **Cache:** nessuna.

### Esempio di richiesta

```bash
curl -X POST 'https://esempio.com/wp-json/health-check/v1/update' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposte `200`

Aggiornamento eseguito:

```json
{ "updated": true, "from": "1.6.0", "to": "1.7.0" }
```

Già aggiornato:

```json
{ "updated": false, "reason": "up_to_date", "current": "1.7.0", "latest": "1.7.0" }
```

Directory non scrivibile:

```json
{ "updated": false, "reason": "not_writable" }
```

Verifica di integrità fallita (hash, prefisso `<?php`, o versione incoerente):

```json
{ "updated": false, "reason": "integrity_check_failed" }
```

### Campi

| Campo | Tipo | Note |
|---|---|---|
| `updated` | bool | `true` solo se il file è stato effettivamente sostituito |
| `from` | string | Versione precedente (solo se `updated: true`) |
| `to` | string | Nuova versione (solo se `updated: true`) |
| `reason` | string | Motivo del mancato update (`up_to_date`, `not_writable`, `integrity_check_failed`) |
| `current` / `latest` | string | Versioni a confronto (solo con `reason: up_to_date`) |

### Errori

Tutti gli errori di rete/release restituiscono un `WP_Error`:

| Status | `code` | Caso |
|---|---|---|
| `502` | `wphc_update_network_error` | Impossibile contattare GitHub |
| `502` | `wphc_update_github_error` | GitHub ha risposto con status ≠ 200 |
| `502` | `wphc_update_bad_release` | Risposta GitHub non valida (manca `tag_name`) |
| `502` | `wphc_update_asset_missing` | Asset `wp-health-check.php` assente nella release |
| `502` | `wphc_update_download_failed` | Download del nuovo file fallito |
| `500` | `wphc_update_backup_failed` | Backup del file corrente fallito |
| `500` | `wphc_update_write_failed` | Scrittura del nuovo file fallita |
| `500` | `wphc_update_sanity_failed` | Sanity check post-scrittura fallito (backup ripristinato) |

---

## `POST /update/plugin`, `POST /update/theme`

Aggiornano, da wordpress.org soltanto, un singolo plugin o tema già installato
sul sito, tramite `Plugin_Upgrader`/`Theme_Upgrader` con rollback via
temp-backup nativo (richiede **WordPress ≥ 6.3**). Distinte dal self-update
dell'agent (`POST /update` sopra): qui si aggiorna software di terze parti,
non l'agent stesso. Vedi il razionale completo e il flusso passo-passo nella
sezione [Aggiornamento di plugin, temi e core via
API](../README.md#aggiornamento-di-plugin-temi-e-core-via-api) del README.

**Auth:** Bearer token. **Richiede inoltre** il kill-switch
`wp_health_check_updates_enabled` acceso (altrimenti `403 disabled`).
**Query:** `?check=1` per un dry-run (nessun update eseguito). **Cache:** nessuna.

### Payload

```json
{ "plugin": "akismet/akismet.php" }
```

`POST /update/theme` è identica con `{ "theme": "<stylesheet>" }`. Nessun
campo `package_url` o `version` è accettato: la sorgente e la versione target
sono sempre quelle che il transient di update del core ha già determinato.

### Esempio di richiesta

```bash
curl -X POST 'https://esempio.com/wp-json/health-check/v1/update/plugin' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI' \
  -H 'Content-Type: application/json' \
  -d '{ "plugin": "akismet/akismet.php" }'
```

### Risposte `200`

Aggiornamento eseguito:

```json
{ "updated": true, "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet", "from": "5.3.2", "to": "5.3.4", "log_id": 1287 }
```

Esito del dry-run (`?check=1`), elemento aggiornabile:

```json
{ "updated": false, "result": "updatable", "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet", "current": "5.3.2", "latest": "5.3.4" }
```

Altri esiti (`result`):

```json
{ "updated": false, "result": "up_to_date", "current": "5.3.4" }
{ "updated": false, "result": "not_updatable", "detail": "pacchetto non ospitato su wordpress.org" }
{ "updated": false, "result": "not_found" }
{ "updated": false, "result": "fs_method_unavailable" }
{ "updated": false, "result": "unsupported_wp_version" }
{ "updated": false, "result": "rolled_back", "detail": "...", "log_id": 1290 }
{ "updated": false, "result": "failed", "detail": "...", "log_id": 1290 }
```

### Campi

| Campo | Tipo | Note |
|---|---|---|
| `updated` | bool | `true` solo se l'elemento è stato effettivamente aggiornato |
| `type` | string | `plugin` \| `theme` |
| `target` | string | Plugin file o stylesheet richiesto |
| `name` | string | Nome leggibile dell'elemento |
| `from` / `to` | string | Versioni a confronto (solo con `updated: true`) |
| `log_id` | int | ID della riga nella tabella di log (vedi `GET /update/log`) |
| `result` | string | `updatable` (dry-run), `up_to_date`, `not_updatable`, `not_found`, `fs_method_unavailable`, `unsupported_wp_version`, `rolled_back`, `failed` |

### Errori

| Status | `code` | Caso |
|---|---|---|
| `400` | `wphc_missing_plugin` / `wphc_missing_theme` | Campo `plugin`/`theme` mancante |
| `403` | `wphc_updates_disabled` | Kill-switch spento per questo sito |
| `409` | `wphc_update_locked` | Un altro aggiornamento è già in corso |

---

## `POST /update/core`

Aggiorna il core di WordPress alla versione che WordPress stesso ha già
determinato disponibile (`get_core_updates()`), tramite `Core_Upgrader`. A
differenza di plugin/temi **non** usa il temp-backup nativo (garanzia di
rollback più debole) e completa esplicitamente l'upgrade del database via
`wp_upgrade()` dopo la sostituzione dei file (contesto headless: nessuna
visita admin la innescherebbe da sola). Vedi [Core: specificità e
avvertenze](../README.md#core-specificità-e-avvertenze) nel README.

**Auth:** Bearer token + kill-switch acceso. **Payload:** nessun campo
obbligatorio. **Query:** `?check=1` per un dry-run. **Cache:** nessuna.

### Esempio di richiesta

```bash
curl -X POST 'https://esempio.com/wp-json/health-check/v1/update/core' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposte `200`

```json
{ "updated": true, "type": "core", "target": "core", "name": "WordPress", "from": "6.4.3", "to": "6.5.0", "log_id": 1301 }
```

Stessi `result` di `/update/plugin`/`/update/theme` sopra (senza
`unsupported_wp_version`, che non si applica al core), con `type: "core"`.

### Errori

Stessi codici/status di `/update/plugin`/`/update/theme` (`wphc_updates_disabled`
`403`, `wphc_update_locked` `409`); nessun campo obbligatorio nel payload,
quindi nessun `400` specifico.

---

## `GET /update/log`

Lettura paginata della tabella di log degli aggiornamenti (plugin, temi,
core). Sola lettura: **sempre accessibile anche a kill-switch spento**.

**Auth:** Bearer token. **Query:** `type` (`plugin`\|`theme`\|`core`,
opzionale), `limit` (default 50, max 200), `offset` (default 0). **Cache:** nessuna.

### Esempio di richiesta

```bash
curl 'https://esempio.com/wp-json/health-check/v1/update/log?type=plugin&limit=50&offset=0' \
  -H 'Authorization: Bearer hJf6MAL91ICKb25IcgpQidxHfxYBPOuFwn1rOa3qQLI'
```

### Risposta `200`

```json
{
  "site": "https://esempio.com",
  "count": 2,
  "total": 137,
  "entries": [
    {
      "id": 1288, "correlation_id": "a1b2c3d4e5f60718", "created_at": "2026-07-14T10:00:03+00:00",
      "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet",
      "version_from": "5.3.2", "version_to": "5.3.4",
      "phase": "completed", "message": null, "ip": "203.0.113.7"
    },
    {
      "id": 1287, "correlation_id": "a1b2c3d4e5f60718", "created_at": "2026-07-14T10:00:00+00:00",
      "type": "plugin", "target": "akismet/akismet.php", "name": "Akismet",
      "version_from": "5.3.2", "version_to": "5.3.4",
      "phase": "requested", "message": null, "ip": "203.0.113.7"
    }
  ]
}
```

### Campi (per elemento di `entries`)

| Campo | Tipo | Note |
|---|---|---|
| `id` | int | ID della riga |
| `correlation_id` | string | Lega le righe `requested`/finale della stessa operazione |
| `created_at` | string | Timestamp ISO 8601 UTC della riga |
| `type` | string | `plugin` \| `theme` \| `core` |
| `target` | string | Plugin file, stylesheet, oppure `core` |
| `name` | string | Nome leggibile dell'elemento |
| `version_from` / `version_to` | string \| null | Versione installata / target |
| `phase` | string | `requested` \| `completed` \| `failed` \| `rolled_back` |
| `message` | string \| null | Dettaglio in caso di errore/rollback |
| `ip` | string \| null | IP del chiamante che ha innescato l'operazione |

---

## Riferimento codici di errore

Tutti i `code` restituiti dall'API, raggruppati per rotta.

### Autenticazione (rotte dati)

| `code` | Status | Rotte |
|---|---|---|
| `wphc_not_enrolled` | `503` | `/health`, `/detail/*`, `/update` |
| `wphc_unauthorized` | `401` | `/health`, `/detail/*`, `/update` |

### `/enroll`

| `code` | Status |
|---|---|
| `wphc_enroll_invalid_body` | `400` |
| `wphc_enroll_missing_field` | `400` |
| `wphc_enroll_unauthorized` | `401` |
| `wphc_enroll_url_mismatch` | `403` |

### `/update`

| `code` | Status |
|---|---|
| `wphc_update_network_error` | `502` |
| `wphc_update_github_error` | `502` |
| `wphc_update_bad_release` | `502` |
| `wphc_update_asset_missing` | `502` |
| `wphc_update_download_failed` | `502` |
| `wphc_update_backup_failed` | `500` |
| `wphc_update_write_failed` | `500` |
| `wphc_update_sanity_failed` | `500` |

> Nota: `/update` distingue **errori** (`WP_Error`, status 5xx) da **esiti
> non riusciti ma non erronei** (`200` con `reason`): `up_to_date`,
> `not_writable`, `integrity_check_failed` non sono codici di errore ma stati
> di risposta `200`.

### `/update/plugin`, `/update/theme`, `/update/core`

| `code` | Status |
|---|---|
| `wphc_missing_plugin` | `400` |
| `wphc_missing_theme` | `400` |
| `wphc_updates_disabled` | `403` |
| `wphc_update_locked` | `409` |

> Stessa distinzione di `/update`: `up_to_date`, `not_updatable`, `not_found`,
> `fs_method_unavailable`, `unsupported_wp_version`, `rolled_back`, `failed` e
> `updatable` (dry-run) sono valori del campo `result` in una risposta `200`,
> non codici di errore.
