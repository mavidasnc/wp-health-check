# Third-Party Update Design

> 24 nodes

## Key Concepts

- **POST /update/plugin,/theme,/core third-party update routes** (10 connections) — `README.md`
- **Option C: single-item synchronous update route (recommended)** (5 connections) — `docs/plugin-update-via-api-analisi.md`
- **Plugin/theme/core update via API specification** (5 connections) — `docs/plugin-update-via-api-specifiche.md`
- **Self-update step-by-step flow** (4 connections) — `README.md`
- **v1.18.0: plugin/theme/core update routes + installer plugin** (3 connections) — `CHANGELOG.md`
- **wphc_update_log table schema** (3 connections) — `docs/plugin-update-via-api-specifiche.md`
- **Installer: signed mu-plugin auto-install/self-deactivate flow** (3 connections) — `installer/wp-health-check-installer/readme.txt`
- **wphc_update_log custom table + GET /update/log** (3 connections) — `README.md`
- **Plugin update via API feasibility/security analysis** (3 connections) — `docs/plugin-update-via-api-analisi.md`
- **Core update specifics (weaker rollback, wp_upgrade())** (2 connections) — `docs/plugin-update-via-api-specifiche.md`
- **Frozen decisions table (Option C, wordpress.org only, kill-switch)** (2 connections) — `docs/plugin-update-via-api-specifiche.md`
- **P2: 20s autologin token producing 14-day session asymmetry** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Autologin magic-link two-step flow** (2 connections) — `README.md`
- **Native PHP filesystem functions instead of WP_Filesystem** (2 connections) — `CLAUDE.md`
- **WP_Filesystem 'direct' method constraint** (2 connections) — `docs/plugin-update-via-api-analisi.md`
- **Threat model: package source RCE constraint** (2 connections) — `docs/plugin-update-via-api-analisi.md`
- **Non-negotiable security constraint: payload names only which element, never source/version** (2 connections) — `README.md`
- **Option E: toggle native auto-update** (1 connections) — `docs/plugin-update-via-api-analisi.md`
- **wp_health_check_updates_enabled per-site kill-switch** (1 connections) — `README.md`
- **POST /update/reactivate plugin active-state reconciliation** (1 connections) — `README.md`
- **wp_health_check_restrict_official_only optional host allowlist** (1 connections) — `README.md`
- **WP Health Check Installer plugin readme** (1 connections) — `installer/wp-health-check-installer/readme.txt`
- **Self-update strict ordering constraint** (1 connections) — `CLAUDE.md`
- **Two-row (requested/completed) logging pattern** (1 connections) — `docs/plugin-update-via-api-specifiche.md`

## Relationships

- [Protocol & Security Docs](Protocol_%26_Security_Docs.md) (2 shared connections)

## Source Files

- `CHANGELOG.md`
- `CLAUDE.md`
- `README.md`
- `docs/plugin-update-via-api-analisi.md`
- `docs/plugin-update-via-api-specifiche.md`
- `docs/sicurezza-autenticazione-analisi.md`
- `installer/wp-health-check-installer/readme.txt`

## Audit Trail

- EXTRACTED: 28 (88%)
- INFERRED: 4 (12%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*