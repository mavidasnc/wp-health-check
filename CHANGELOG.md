# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/), e questo
progetto aderisce a [Semantic Versioning](https://semver.org/lang/it/).

## [Unreleased]

## [1.12.0] - 2026-07-09

### Fixed

- Conteggi plugin/temi potenzialmente stale dietro un object cache persistente
  mal configurato (che rende persistente il gruppo di cache `plugins`/`themes`):
  `?fresh=1` su `/health` e `/detail/plugins` ora svuota le cache delle liste
  (`wp_clean_plugins_cache( false )` / `wp_clean_themes_cache( false )`) prima
  di ricontare, così `get_plugins()`/`wp_get_themes()` riscansionano la
  cartella e `plugins_total` / `count` / `themes_total` risultano corretti.
  La logica di conteggio in sé era già corretta; il problema era la freschezza
  dei dati (cache dei payload: `/detail/plugins` 1h, `/health` 60s; più il
  transient degli update mantenuto dal cron). Per dati autorevoli usare
  sempre `?fresh=1`.

## [1.11.0] - 2026-07-09

### Added

- Quando un enroll fallisce per URL mismatch (`wphc_enroll_url_mismatch`), oltre
  a registrare il dettaglio in `wp_health_check_last_enroll_error`, il plugin
  invia un'email di alert a `WP_HEALTH_CHECK_ALERT_EMAIL` (nuova costante di
  flotta, default `maurizio@mavida.com`; stringa vuota per disabilitare) con
  URL ricevuto, URL atteso, IP, timestamp e l'elenco completo degli URL validi
  per l'enroll. Rate-limit di un invio all'ora per sito (transient anti-flood).
  L'email riguarda solo il mismatch URL, non gli altri fallimenti; il ramo è
  raggiungibile solo con firma Ed25519 valida. Usa `wp_mail()` del core, nessuna
  dipendenza esterna.

## [1.10.0] - 2026-07-09

### Added

- Tab Site Health: pulsante di **self-update** del plugin (stesso flusso di
  `POST /update` via la nuova funzione condivisa `wphc_perform_self_update()`),
  con verifica dell'**ultima versione disponibile** su GitHub (cachata 1h,
  `wphc_get_latest_version()`) ed evidenziazione se esiste un aggiornamento.
- Tab Site Health: riga con gli **URL validi per l'enroll**
  (`wphc_candidate_site_urls()`, "principale" evidenziato), per sapere con
  quale URL il centro deve firmare la busta.
- Nuova opzione `wp_health_check_last_enroll_error`: registra il motivo
  dell'**ultimo enroll fallito** (codice, motivo, URL inviato, timestamp, IP),
  mostrato nella tab e azzerato automaticamente al primo enroll riuscito.
  Registra tutti i tipi di fallimento (corpo non valido, firma non valida,
  URL mismatch).

### Changed

- La logica di self-update è stata estratta in `wphc_perform_self_update()`,
  condivisa fra la rotta REST `POST /update` (contratto di risposta invariato)
  e il pulsante nella tab Site Health.

### Removed

- Tab Site Health: rimossa la sezione per modificare
  `wp_health_check_dashboard_origin` dalla UI (le chiamate alla flotta sono ora
  server-to-server; l'opzione e la logica CORS restano nel plugin, popolate
  dall'enroll). Rimosso l'helper orfano `wphc_is_valid_origin()`.

## [1.9.0] - 2026-07-09

### Changed

- `POST /enroll`: il confronto tra il `site_url` firmato e l'URL del sito è ora
  **tollerante**. Il sito costruisce un set di URL canonici candidati
  (`home_url()`, `site_url()`, `network_home_url()`, `network_site_url()`,
  ciascuno con/senza `www.`, tutti normalizzati) e accetta l'enroll se il
  `site_url` firmato normalizzato è nel set. Risolve i 403 spurii su siti WPML
  (dove `home_url()` varia per lingua), dietro reverse proxy o con varianti
  www/non-www. La verifica della firma resta prima e obbligatoria.
- Il codice di errore del mismatch URL passa da `wphc_enroll_site_mismatch` a
  `wphc_enroll_url_mismatch` (`403`), che ora espone nel `message` l'URL atteso
  e nei campi `data.expected` / `data.received` atteso e ricevuto.
- Il campo `site` della risposta di `/enroll` è ora il `site_url` firmato
  realmente registrato (la chiave a cui è legato il token), non più
  `home_url()` normalizzato.

### Added

- Opzione `wp_health_check_site_url`: memorizza esattamente il `site_url`
  firmato ricevuto in fase di enroll. Azzerata dal reset (WP-CLI e tab Site
  Health) e mostrata come riga di sola lettura nella tab Site Health.

## [1.8.0] - 2026-07-09

### Added

- Nuovi campi nel `summary` di `GET /health`: `php_memory_limit`, `server_ip`
  (IP del server WordPress da `SERVER_ADDR`, `null` se non determinabile),
  `themes_total` (numero di temi installati), `theme_name` (nome del tema
  attivo) e `parent_theme_name` (nome del tema parent, `null` se il tema
  attivo non è un child theme).
- Campo `server_ip` anche nella sezione `server` di `GET /detail/server`.

## [1.7.0] - 2026-07-08

### Fixed

- `GET /health` riportava sempre `plugins_updates: 0`, `themes_updates: 0` e
  `core_update: false` anche con aggiornamenti realmente disponibili
  (osservato in produzione: `/detail/plugins` mostrava correttamente un
  aggiornamento disponibile per lo stesso sito). Causa: i conteggi di
  `wp_get_update_data()` sono condizionati da
  `current_user_can( 'update_plugins'/'update_themes'/'update_core' )`, che
  in questa rotta vale sempre `false` — l'autenticazione è il bearer token,
  non una sessione utente WordPress. Ora i conteggi vengono letti
  direttamente dagli stessi transient di update mantenuti dal cron
  (`update_plugins`, `update_themes`, `get_core_updates()`), con la stessa
  logica di `wp_get_update_data()` ma senza il controllo di capability.

## [1.6.0] - 2026-07-08

### Fixed

- Il default del core REST API di WordPress (`rest_send_cors_headers()`, che
  riflette qualunque `Origin` con `Access-Control-Allow-Credentials: true`
  per l'intera REST API) girava dopo la logica di questo plugin e la
  sovrascriveva, vanificando di fatto la restrizione su
  `wp_health_check_dashboard_origin`. `wphc_maybe_send_cors_headers()` viene
  ora richiamata una seconda volta su `rest_pre_serve_request` (priorità 20,
  dopo il 10 di default del core), limitata al namespace `health-check/v1`,
  rimuovendo prima qualunque header CORS già impostato dal core.

## [1.5.0] - 2026-07-08

### Fixed

- Le rotte di `health-check/v1` ora impediscono esplicitamente la cache
  HTTP/edge lato server (`nocache_headers()` + costante `DONOTCACHEPAGE`),
  inviati da `wphc_maybe_send_cors_headers()` prima di qualunque header CORS.
  Senza questo, un plugin di page-cache (es. LiteSpeed Cache) poteva mettere
  in cache l'intera risposta — inclusi gli header CORS legati all'`Origin`
  del chiamante — e riservirla identica a chiunque altro, causando errori
  CORS incoerenti in produzione (osservato dietro LiteSpeed Cache: lo stesso
  `Access-Control-Allow-Origin` restituito a prescindere dall'`Origin`
  inviato). La cache applicativa via transient del plugin non è interessata
  da questo fix, resta invariata.

## [1.4.0] - 2026-07-08

### Added

- Nuova tab "WP Health Check" in Strumenti → Salute del sito (via
  `site_health_navigation_tabs`/`site_health_tab_content`, disponibili da
  WordPress 5.8), visibile solo a chi ha `manage_options`: versione plugin,
  repository GitHub configurato, stato enrollment, ultimo accesso, stato
  `trust_proxy`, campo per leggere/modificare `wp_health_check_dashboard_origin`
  e pulsante di reset enrollment (equivalente a `wp health-check reset`).
- Funzione condivisa `wphc_reset_enrollment()`, usata sia dal comando WP-CLI
  sia dal nuovo pulsante di reset nella tab Site Health.

## [1.3.0] - 2026-07-08

### Changed

- Con `wp_health_check_dashboard_origin` non configurata, gli header CORS ora
  autorizzano qualunque origin (riflessa in `Access-Control-Allow-Origin`,
  mai un wildcard letterale), invece di non inviare alcun header. Utile in
  fase di setup/sviluppo prima del primo `/enroll`. Non appena
  `dashboard_origin` viene impostata, torna ad essere l'unica origin
  autorizzata, come prima.

## [1.2.0] - 2026-07-08

### Changed

- `POST /update` torna protetta dal bearer token (`wphc_require_token`), come
  le altre rotte dati. Era stata resa temporaneamente pubblica nella 1.1.0 per
  sbloccare i test da un'app/dashboard in sviluppo locale.

## [1.1.0] - 2026-07-08

### Added

- Campo `plugin_version` nel `summary` di `GET /health`, accanto a `wp_version`
  e `php_version`.

### Changed

- `POST /update` è temporaneamente pubblica (nessun controllo bearer token),
  per sbloccare le chiamate dirette da un'app/dashboard in sviluppo locale.
  Va ripristinata l'autenticazione a token non appena il flusso bearer sarà
  di nuovo attivo lato dashboard (vedi nota nel README).

### Fixed

- `GET /detail/server` poteva rispondere `500` se `WP_Debug_Data::debug_data()`
  lanciava un'eccezione diversa da `ImagickException` durante l'introspezione
  dell'ambiente server (es. su alcuni host). Il catch ora copre `Throwable` in
  generale: la rotta non fallisce più, prosegue senza quella sezione.

## [1.0.0] - 2026-07-08

### Added

- Must-use plugin a file singolo (`mu-plugins/wp-health-check.php`), compatibile
  PHP 7.4+ e WordPress 6.4+.
- Bootstrap firmato Ed25519 su `POST /enroll`, senza scritture in `wp-config.php`.
- Modello del token per-sito `base64url(hmac_sha256(url_normalizzato, MASTER_SECRET))`,
  calcolato lato sistema centrale, senza rotazione né scadenza.
- Autenticazione Bearer token in tempo costante (`hash_equals`) su tutte le rotte
  dati.
- Rotte REST nel namespace `health-check/v1`: `/enroll`, `/health`,
  `/detail/plugins`, `/detail/theme`, `/detail/server`, `/update`.
- Tracciamento dell'ultimo accesso autenticato (timestamp + IP), con supporto
  opzionale a `X-Forwarded-For` dietro proxy fidato.
- Self-update firmato dalle release del repository GitHub pubblico, con verifica
  di integrità (SHA-256 + firma), backup automatico e scrittura atomica.
- Caching per-rotta via transient, con `?fresh=1` come unico meccanismo di
  refresh forzato.
- Gestione CORS con origin esplicita (mai wildcard) verso la dashboard registrata
  in fase di enroll.
- Comando WP-CLI `wp health-check reset` per il re-provisioning/offboarding.
- Script `bin/generate-keys.php` per la generazione della coppia di chiavi
  Ed25519 lato sistema centrale.
- Tooling di sviluppo: PHPCS/WPCS + PHPCompatibilityWP, PHPStan con stub
  WordPress, configurazione wp-env.

[Unreleased]: https://github.com/mavidasnc/wp-health-check/compare/v1.12.0...HEAD
[1.12.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/mavidasnc/wp-health-check/releases/tag/v1.0.0
