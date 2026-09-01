# Core Architecture Constraints

> 9 nodes · cohesion 0.22

## Key Concepts

- **Must-use plugin single-file architecture** (7 connections) — `README.md`
- **Atomic rename() write with neutral-extension temp file** (2 connections) — `README.md`
- **REST responses only reflect matching Origin, never wildcard** (1 connections) — `CLAUDE.md`
- **/detail/server explicit field allowlist** (1 connections) — `CLAUDE.md`
- **Single self-contained mu-plugin file constraint** (1 connections) — `CLAUDE.md`
- **Never writes to wp-config.php** (1 connections) — `CLAUDE.md`
- **wp-health-check dev repo** (1 connections) — `CLAUDE.md`
- **API Health Check reference document** (1 connections) — `docs/API health check.md`
- **Self-update flow (wphc_perform_self_update)** (1 connections) — `README.md`

## Relationships

- No strong cross-community connections detected

## Source Files

- `CLAUDE.md`
- `README.md`
- `docs/API health check.md`

## Audit Trail

- EXTRACTED: 7 (88%)
- INFERRED: 1 (12%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*