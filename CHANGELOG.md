# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/), e questo
progetto aderisce a [Semantic Versioning](https://semver.org/lang/it/).

## [Unreleased]

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

[Unreleased]: https://github.com/mavidasnc/wp-health-check/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/mavidasnc/wp-health-check/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/mavidasnc/wp-health-check/releases/tag/v1.0.0
