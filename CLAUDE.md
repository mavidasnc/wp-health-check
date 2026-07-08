# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress must-use plugin fleet agent (`mu-plugins/wp-health-check.php`), a single self-contained PHP file with no runtime dependencies, distributed to client sites via a public GitHub repo and capable of self-updating from signed releases. This repository is the tooling/dev repo around that one file; only `mu-plugins/wp-health-check.php` and `bin/generate-keys.php` are ever deployed anywhere.

Full architecture, protocol, and security rationale live in [README.md](README.md) — read it before making non-trivial changes, since almost every design decision there (why mu-plugins, why no `wp-config.php` writes, the enroll signature scheme, the token model, the self-update ordering) is deliberate and documented with its trade-offs. Do not treat unfamiliar patterns in the code as accidental; check README.md first.

## Commands

```bash
composer install        # PHPCS/WPCS, PHPCompatibilityWP, PHPStan + WordPress/WP-CLI stubs
composer run lint        # phpcs on mu-plugins/ and bin/
composer run lint:fix     # phpcbf autofix
composer run analyse      # phpstan (level 5) on mu-plugins/ and bin/

npx @wordpress/env start  # wp-env; mounts mu-plugins/wp-health-check.php into a local WP site
```

There is no automated test suite in this repo — verification is via `composer run lint`, `composer run analyse`, and manual curl/wp-env exercising of the REST routes (examples for every route are in README.md).

To regenerate the central Ed25519 keypair (dev/staging only, never reuse production keys):

```bash
php bin/generate-keys.php
```

## Non-negotiable architectural constraints

- **Single self-contained file, no autoload at runtime.** WordPress only auto-loads `.php` files directly in the root of `wp-content/mu-plugins/`, not subdirectories — this is why `mu-plugins/wp-health-check.php` has no PSR-4 classes and includes core files (`class-wp-debug-data.php`, `plugin.php`, `update.php`) only lazily, inside the specific callback that needs them. The `WPHC_CLI_Command` class lives in the same file for the same reason. Composer's PSR-4 autoload only covers dev tooling (e.g. `bin/generate-keys.php`), never the plugin.
- **Never writes to `wp-config.php`.** Non-secret fleet-wide config (agent version, GitHub repo coordinates, the central Ed25519 *public* key) is hardcoded as PHP constants in the file itself, since it's identical across the fleet and distributed via GitHub. Per-site state (token, dashboard origin, last-access timestamps) lives exclusively in `wp_options`.
- **No secret ever lives on a site.** The embedded key is the central system's Ed25519 *public* key — usable only to verify signatures, never to produce them. The token itself is never derived on-site; it's computed once centrally (`base64url(hmac_sha256(normalized_url, MASTER_SECRET))`) and delivered opaque via the signed `/enroll` payload.
- **PHP 7.4+ runtime compatibility is mandatory** for `mu-plugins/wp-health-check.php` and `bin/generate-keys.php`, even though dev tooling (composer, PHPStan, wp-env) targets PHP 8.1+. `phpcs.xml.dist` enforces this via `PHPCompatibilityWP` with `testVersion: 7.4-`. Never introduce PHP 8-only syntax into the runtime file.
- **Self-update never runs automatically** — only triggered by an authenticated `POST /update` call, never a site-side cron. Any change to the update flow must preserve strict ordering (version check → writability preflight → download → integrity check *before touching the production file* → backup → atomic `rename()` write → post-write sanity check with automatic rollback → opcache invalidation), since each step exists specifically to guarantee the production file is never left corrupted or half-written.
- **`/health` must stay cheap.** It's polled frequently and must never call `WP_Debug_Data::debug_data()` or force remote wordpress.org update checks — those live exclusively behind `/detail/server` (12h cache) or the explicit `?fresh=1` opt-in.
- **`/detail/server` uses an explicit field allowlist**, not the raw `WP_Debug_Data::debug_data()` array, so a core-added private field (DB user/host) can never leak into the response regardless of future WordPress changes.

## Code conventions

- Function/constant prefixes are `wphc_` and `WP_HEALTH_CHECK_` (not the full plugin slug) — this is intentional, see the `PrefixAllGlobals.ShortPrefixPassed` exclusion in `phpcs.xml.dist`.
- All token comparisons use `hash_equals()` (constant-time); never use `===` or `==` for secret/token comparison.
- Filesystem operations in the self-update flow use native PHP functions (`file_put_contents`, `copy`, `rename`), not `WP_Filesystem` — deliberate, since the file must work before `WP_Filesystem` is initialized and without FTP credentials. Errors are still always checked explicitly despite the `@` silencing operator.
- REST responses only ever send the requesting site's own `Origin` back (never `*`) and only when it matches `wp_health_check_dashboard_origin`, set during enroll.
