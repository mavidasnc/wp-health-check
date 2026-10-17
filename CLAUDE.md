# CLAUDE.md

> **Documentazione spostata**: la cartella `docs/` di questo progetto è stata unificata in `../__docs/wp-health-check/` (riorganizzazione del 2026-10-13). I percorsi qui sotto puntano già alla nuova posizione.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress must-use plugin fleet agent, distributed to client sites as a single self-contained PHP file (`mu-plugins/wp-health-check.php`, no runtime dependencies) via a public GitHub repo, and capable of self-updating from signed releases. This repository is the source and tooling repo around that one file.

**Since 1.39.0 the source lives in `src/` and the distributed file is GENERATED.** `src/` holds 21 `final` classes with static methods (prefix `WPHC_`) plus `WPHC_CLI_Command`, `header.php`, `constants.php` and `boot.php`; `bin/build.php` concatenates them in the order of `src/manifest.php` and, for the release, strips the comments. `mu-plugins/wp-health-check.php` must never be edited by hand: change `src/` and rebuild. Layout, build gates, strip rules and the release checklist are in [src/README.md](src/README.md) (the authoritative source for the build); the test suite is described in [tests/README.md](tests/README.md). Only the generated `mu-plugins/wp-health-check.php` and `bin/generate-keys.php` are ever deployed anywhere.

Full architecture, protocol, and security rationale live in [README.md](README.md) — read it before making non-trivial changes, since almost every design decision there (why mu-plugins, why no `wp-config.php` writes, the enroll signature scheme, the token model, the self-update ordering) is deliberate and documented with its trade-offs. Do not treat unfamiliar patterns in the code as accidental; check README.md first. The design of the move to classes (class map, decisions D-1..D-9, preserved bugs) is in `../__docs/wp-health-check/refactoring-classi.md`.

## Commands

```bash
composer install          # PHPCS/WPCS, PHPCompatibilityWP, PHPStan + WordPress/WP-CLI stubs, PHPUnit
composer run build        # build WITH strip: regenerates mu-plugins/wp-health-check.php, the committed and distributable file
composer run build:dev    # build WITHOUT strip (carries the WPHC-BUILD dev marker), written to build/dev/, never to mu-plugins/
composer run build:check  # compares a fresh strip build with the committed mu-plugins/ file (exit 1 if different)
composer run test:build   # tests of the build itself (plain PHP, no WordPress)
composer run test         # PHPUnit characterization suite inside wp-env (PHP 8.1 by default)
composer run lint         # phpcs on src/ and bin/ (the generated file is excluded)
composer run lint:fix     # phpcbf autofix (NEVER on class-wphc-admin-view.php, see src/README.md)
composer run analyse      # phpstan (level 5) on src/ and bin/

npx @wordpress/env start  # wp-env (tests-cli container); the tests load a COPY of the built plugin
python -I tests/e2e/run_all.py   # e2e with a real WordPress and the hub's vendored Python code
```

Test commands for single suites, the PHP 7.4 matrix (`tests/php74/`), golden snapshots, contract vectors and the e2e upgrade test from 1.38.1 are in `tests/README.md` and `tests/e2e/README.md`. Always rebuild (`composer run build`) after touching `src/` and commit the regenerated `mu-plugins/wp-health-check.php` together with the source: the PHPUnit bootstrap refuses to start if `mu-plugins/` differs from a fresh strip build.

The committed `mu-plugins/wp-health-check.php` is the strip build, the one that is signed and published. Before signing or publishing ANY file, check that it is not the dev build: `grep -L WPHC-BUILD mu-plugins/wp-health-check.php` must PRINT the file name (`-L` lists files that do NOT contain the word). Empty output means the `// WPHC-BUILD: dev` marker is present: do not sign, do not publish.

To regenerate the central Ed25519 keypair (dev/staging only, never reuse production keys):

```bash
php bin/generate-keys.php
```

## Non-negotiable architectural constraints

- **Single distributed file, no autoload at runtime.** WordPress only auto-loads `.php` files directly in the root of `wp-content/mu-plugins/`, not subdirectories, so the build must keep producing ONE self-contained file: the classes of `src/` are concatenated, never loaded by an autoloader, and there is no namespace. Core files (`class-wp-debug-data.php`, `plugin.php`, `update.php`) are included only lazily, inside the specific callback that needs them. `WPHC_CLI_Command` lives in the same file and is the last entry of the manifest. `composer.json` has no `autoload` section at all: Composer only provides dev tooling.
- **Size budget.** The hub pushes the whole file in the body of `POST /update` (gzip + base64 inside a JSON body) and some hosts reject bodies over 128 KiB (mod_security). The build fails if the push body of the stripped file reaches 131072 bytes and warns above 110000 characters of gzip + base64. Do not add comments or code to `src/` without checking the figures printed by `composer run build`; never ship a non-stripped build.
- **Pure refactoring, identical behaviour.** 1.39.0 only reorganized the code: routes, bodies, errors, options, transients, cron hooks, user meta, the `wphc_update_log` table, hook order and the HTML of the Site Health tab are identical to 1.38.1. Known bugs were deliberately PRESERVED (list in section 9 of `refactoring-classi.md`, plus `finding-throttle-doppia-valutazione.md`): they are fixed in separate releases, one change at a time, each with its own test. Never mix a reorganization with a behaviour change, and never fix a preserved bug "in passing".
- **Mandatory tests for any change to `src/`.** The PHPUnit suite, the golden snapshots (`tests/fixtures/golden/`, including `hooks/registry.json`) and the upgrade test from 1.38.1 (`tests/e2e/upgrade_test.py`) must stay green; PHP 7.4, 8.1 and 8.3 are all exercised. A golden that changes means a behaviour change: decide it explicitly, do not just regenerate it. Skipped tests fail the run (`failOnSkipped`).
- **`WP_HEALTH_CHECK_FILE`.** The self-update rewrites the file named by this constant (`WPHC_Self_Updater::install_release()`), defined in `src/constants.php` with `if ( ! defined() )` and default `__FILE__` (the mu-plugin itself in the built file). It can be redefined in `wp-config.php`: the target must be on the same filesystem as `WPMU_PLUGIN_DIR` and never a `.php` copy in the root of `mu-plugins/`. `__FILE__`, `__DIR__`, `__CLASS__`, `__FUNCTION__`, `__METHOD__` and `__LINE__` are build errors anywhere else in `src/` (the first two are allowed only in `constants.php`).
- **No global `wphc_*` functions.** Since 1.39.0 the plugin declares no global functions: a call such as `wphc_require_token()` from third-party code is a fatal error and `remove_action( ..., 'wphc_x' )` by string name no longer works (decision D-2). Do not add wrappers.
- **Never writes to `wp-config.php`.** Non-secret fleet-wide config (agent version, GitHub repo coordinates, the central Ed25519 *public* key) is hardcoded as PHP constants in the file itself (`src/constants.php`), since it's identical across the fleet and distributed via GitHub. Per-site state (token, dashboard origin, last-access timestamps) lives exclusively in `wp_options`.
- **No secret ever lives on a site.** The embedded key is the central system's Ed25519 *public* key — usable only to verify signatures, never to produce them. The token itself is never derived on-site; it's computed once centrally (`base64url(hmac_sha256(normalized_url, MASTER_SECRET))`) and delivered opaque via the signed `/enroll` payload.
- **PHP 7.4+ runtime compatibility is mandatory** for the generated file (so for all of `src/`) and for `bin/generate-keys.php`, even though dev tooling (composer, PHPStan, wp-env) targets PHP 8.1+. `phpcs.xml.dist` enforces this via `PHPCompatibilityWP` with `testVersion: 7.4-`. Never introduce PHP 8-only syntax into `src/`.
- **Self-update never runs automatically** — only triggered by an authenticated `POST /update` call, never a site-side cron. Any change to the update flow must preserve strict ordering (version check → writability preflight → download → integrity check *before touching the production file* → backup → atomic `rename()` write → post-write sanity check with automatic rollback → opcache invalidation), since each step exists specifically to guarantee the production file is never left corrupted or half-written.
- **`/health` must stay cheap.** It's polled frequently and must never call `WP_Debug_Data::debug_data()` or force remote wordpress.org update checks — those live exclusively behind `/detail/server` (12h cache) or the explicit `?fresh=1` opt-in.
- **`/detail/server` uses an explicit field allowlist**, not the raw `WP_Debug_Data::debug_data()` array, so a core-added private field (DB user/host) can never leak into the response regardless of future WordPress changes.

## Code conventions

- Classes are `final`, named `WPHC_*` (one class per file, `src/class-wphc-*.php`, static methods). The prefix `wphc_` survives only in PERSISTED or public names, which are strings and must never be renamed: option, transient and cron hook names (`wphc_bulk_update_tick`, `wphc_webhook_retry`), `admin_post_wphc_*` / `wp_ajax_wphc_*` actions, the `wphc_autologin` query var, the `wphc_update_log` table, the `wphc_last_login*` user meta. Option and transient names are constants of `WPHC_Options`. Constants are `WP_HEALTH_CHECK_*`. The short prefixes are intentional, see the `PrefixAllGlobals.ShortPrefixPassed` exclusion in `phpcs.xml.dist`.
- All token comparisons use `hash_equals()` (constant-time); never use `===` or `==` for secret/token comparison.
- Filesystem operations in the self-update flow use native PHP functions (`file_put_contents`, `copy`, `rename`), not `WP_Filesystem` — deliberate, since the file must work before `WP_Filesystem` is initialized and without FTP credentials. Errors are still always checked explicitly despite the `@` silencing operator.
- REST responses only ever send the requesting site's own `Origin` back (never `*`) and only when it matches `wp_health_check_dashboard_origin`, set during enroll; with no origin configured no CORS header is sent at all (since 1.36.0).
- Hooks are registered in one place, `WPHC_Plugin::boot()` (called by `src/boot.php` at load time, never on a delayed hook), in the same order as the 1.38.1 file: the order is part of the behaviour and pinned by the hook-registry golden.
