# Changelog & Feature History

> 35 nodes · cohesion 0.06

## Key Concepts

- **POST /enroll (bootstrap firmato)** (6 connections) — `README.md`
- **Bootstrap firmato Ed25519 (problema del bootstrap)** (5 connections) — `README.md`
- **Protocollo v2: segreto per-sito ruotabile/revocabile** (5 connections) — `README.md`
- **P0 §3.1: Bearer statico nel bundle pubblico + identità auto-dichiarata** (4 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P1 §3.4: token per-sito non ruotabile, segreto unico di flotta** (4 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **§5.C: envelope encryption per Application Password e segreto per-sito** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Piano di migrazione a fasi (0-4)** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **§5.A: sessione opaca per-utente browser→hub** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Modello del token protocollo 1 (HMAC deterministico)** (3 connections) — `README.md`
- **v1.30.0: protocollo v2 segreto per-sito ruotabile e revocabile** (2 connections) — `CHANGELOG.md`
- **P1 §3.6: nessun anti-replay sulle chiamate operative hub→plugin** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P1 §3.3: Application Password in chiaro su Supabase** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Catena di fiducia browser→hub→plugin→sito** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **automation_2wp (hub Flask)** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P0 §3.2: POST /enroll pubblico senza rate limit (oracolo + SSRF)** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **kid sulla chiave pubblica Ed25519 del centro (k1/k2)** (2 connections) — `README.md`
- **POST /rotate (rotazione firmata del segreto)** (2 connections) — `README.md`
- **v1.11.0: email di alert su enroll URL mismatch** (1 connections) — `CHANGELOG.md`
- **v1.30.0: anti-replay X-WPHC-Timestamp/Nonce/Signature** (1 connections) — `CHANGELOG.md`
- **v1.30.0: gate HTTPS su /enroll, /rotate, /revoke** (1 connections) — `CHANGELOG.md`
- **v1.30.0: rate limiting sui tentativi di autenticazione falliti** (1 connections) — `CHANGELOG.md`
- **v1.9.0: confronto URL tollerante su /enroll** (1 connections) — `CHANGELOG.md`
- **hash_equals constant-time token comparison convention** (1 connections) — `CLAUDE.md`
- **No secret ever lives on a site** (1 connections) — `CLAUDE.md`
- **P2 §3.9: sessione da 14gg generata da token che vive 20s** (1 connections) — `docs/sicurezza-autenticazione-analisi.md`
- *... and 10 more nodes in this community*

## Relationships

- No strong cross-community connections detected

## Source Files

- `CHANGELOG.md`
- `CLAUDE.md`
- `README.md`
- `docs/sicurezza-autenticazione-analisi.md`

## Audit Trail

- EXTRACTED: 31 (89%)
- INFERRED: 4 (11%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*