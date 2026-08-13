# Core Architecture Constraints

> 8 nodes · cohesion 0.25

## Key Concepts

- **Architettura must-use plugin a file singolo** (7 connections) — `README.md`
- **REST responses only reflect matching Origin, never wildcard** (1 connections) — `CLAUDE.md`
- **/detail/server explicit field allowlist** (1 connections) — `CLAUDE.md`
- **Single self-contained mu-plugin file constraint** (1 connections) — `CLAUDE.md`
- **Never writes to wp-config.php** (1 connections) — `CLAUDE.md`
- **wp-health-check dev repo** (1 connections) — `CLAUDE.md`
- **API Health Check reference document** (1 connections) — `docs/API health check.md`
- **POST /update (self-update dell'agent da GitHub)** (1 connections) — `README.md`

## Relationships

- No strong cross-community connections detected

## Source Files

- `CLAUDE.md`
- `README.md`
- `docs/API health check.md`

## Audit Trail

- EXTRACTED: 7 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*