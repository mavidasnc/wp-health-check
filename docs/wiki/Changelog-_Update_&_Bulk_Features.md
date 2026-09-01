# Changelog: Update & Bulk Features

> 25 nodes · cohesion 0.10

## Key Concepts

- **Bulk update via POST /update/bulk + WP-Cron** (12 connections) — `README.md`
- **POST /update/plugin, /update/theme, /update/core** (8 connections) — `README.md`
- **1.31.0: asynchronous bulk update via POST /update/bulk + WP-Cron introduced** (5 connections) — `CHANGELOG.md`
- **wphc_update_log table (requested/completed two-row pattern)** (4 connections) — `README.md`
- **1.18.0: plugin/theme/core third-party update routes introduced** (3 connections) — `CHANGELOG.md`
- **HMAC-SHA256 signed webhook notification on job completion** (3 connections) — `README.md`
- **1.31.1: items[].from/to always null bug fixed in wphc_bulk_apply_outcome** (2 connections) — `CHANGELOG.md`
- **1.32.1: stall_after_ts computed from item timeout not next_run_ts** (2 connections) — `CHANGELOG.md`
- **Core-only exclusive bulk job constraint** (2 connections) — `README.md`
- **Bulk item retry with growing backoff (0/60/300/900s)** (2 connections) — `README.md`
- **bulk_update.stalled derived-at-read flag** (2 connections) — `README.md`
- **wphc_is_package_host_allowed() optional host allowlist** (2 connections) — `README.md`
- **POST /update/reactivate reconciliation route** (2 connections) — `README.md`
- **Non-negotiable constraint: no package_url/version in payload** (2 connections) — `README.md`
- **wp_health_check_updates_enabled kill-switch** (2 connections) — `README.md`
- **1.23.0: POST /update/reactivate route added** (1 connections) — `CHANGELOG.md`
- **1.24.0: host restriction default inverted, premium plugins updatable by default** (1 connections) — `CHANGELOG.md`
- **1.31.0: signed webhook notification on bulk job completion added** (1 connections) — `CHANGELOG.md`
- **1.32.0: core update added to bulk engine, exclusive job** (1 connections) — `CHANGELOG.md`
- **1.32.0: fleet webhook active by default when URL empty** (1 connections) — `CHANGELOG.md`
- **1.32.1: drain mutex re-extended to 1800s before core update** (1 connections) — `CHANGELOG.md`
- **1.32.1: GET /update/bulk reaps orphaned running items on every call** (1 connections) — `CHANGELOG.md`
- **POST /update/bulk/cancel** (1 connections) — `README.md`
- **wp_health_check_db_version incremental schema migrations (dbDelta)** (1 connections) — `README.md`
- **wp_health_check_update_lock anti-concurrency lock** (1 connections) — `README.md`

## Relationships

- [Detail Routes & Auto-Update State](Detail_Routes_%26_Auto-Update_State.md) (1 shared connections)

## Source Files

- `CHANGELOG.md`
- `README.md`

## Audit Trail

- EXTRACTED: 30 (94%)
- INFERRED: 2 (6%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*