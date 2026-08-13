# Protocol & Security Docs

> 37 nodes

## Key Concepts

- **Dashboard-hub-plugin authentication security analysis** (14 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **v1.30.0: rotatable secret, anti-replay, thumbnail diagnostics** (7 connections) — `CHANGELOG.md`
- **§5.B target model: per-site rotatable secret with anti-replay** (5 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Protocol 2: rotatable/revocable per-site secret** (5 connections) — `README.md`
- **CHANGELOG.md version history** (5 connections) — `CHANGELOG.md`
- **X-WPHC-* anti-replay signature headers** (4 connections) — `README.md`
- **POST /enroll signed bootstrap** (4 connections) — `README.md`
- **POST /rotate and POST /revoke secret lifecycle** (4 connections) — `README.md`
- **Protocol 1 token model (HMAC deterministic)** (4 connections) — `README.md`
- **v1.16.0: fix pre_site_transient_update_* short-circuit filters** (3 connections) — `CHANGELOG.md`
- **has_gdpr / has_builder detection task** (3 connections) — `docs/plugin-implementation-todo.md`
- **v1.0.0: initial mu-plugin release** (2 connections) — `CHANGELOG.md`
- **v1.29.0: public IP, update source log, last login** (2 connections) — `CHANGELOG.md`
- **X-WPHC canonical signing string (method\nroute\nsha256body\ntimestamp\nnonce)** (2 connections) — `docs/API health check.md`
- **wphc_detect_site_signals() central signal detection function** (2 connections) — `docs/plugin-implementation-todo.md`
- **P1: no anti-replay on hub-to-plugin operational calls** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P1: per-site token not rotatable/revocable, fleet-wide single secret** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P2: /enroll without nonce or freshness window on issued_at** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P2: no rate limit/lockout or HTTPS gate on plugin routes** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P2: Bearer compared with == instead of constant-time in hub backend** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P3: wp_health_check_last_autologin written but never read, excluded from reset** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P3: Ed25519 public key hardcoded without kid identifier** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **kid identifier for Ed25519 central public key rotation** (2 connections) — `README.md`
- **summary.public_ip / server_ip_is_private via ipify** (2 connections) — `README.md`
- **Tab Site Health admin UI** (2 connections) — `README.md`
- *... and 12 more nodes in this community*

## Relationships

- [Third-Party Update Design](Third-Party_Update_Design.md) (2 shared connections)
- [Core Architecture Constraints](Core_Architecture_Constraints.md) (1 shared connections)

## Source Files

- `CHANGELOG.md`
- `CLAUDE.md`
- `README.md`
- `docs/API health check.md`
- `docs/plugin-implementation-todo.md`
- `docs/sicurezza-autenticazione-analisi.md`

## Audit Trail

- EXTRACTED: 46 (88%)
- INFERRED: 6 (12%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*