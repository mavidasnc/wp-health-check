# Auth History & Security Notes

> 22 nodes · cohesion 0.10

## Key Concepts

- **Token model: protocol 1 (derived HMAC token)** (7 connections) — `README.md`
- **POST /enroll signed bootstrap** (4 connections) — `README.md`
- **P0 §3.1: Bearer statico nel bundle pubblico + identità auto-dichiarata** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **§5.C: envelope encryption per Application Password e segreto per-sito** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P1 §3.4: token per-sito non ruotabile, segreto unico di flotta** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **§5.A: sessione opaca per-utente browser→hub** (3 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **1.0.0: initial mu-plugin release with Ed25519 enroll and bearer auth** (2 connections) — `CHANGELOG.md`
- **P1 §3.3: Application Password in chiaro su Supabase** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Catena di fiducia browser→hub→plugin→sito** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Piano di migrazione a fasi (0-4)** (2 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **Ed25519 keypair generation (bin/generate-keys.php)** (2 connections) — `README.md`
- **1.9.0: tolerant enroll URL matching against canonical candidate set** (1 connections) — `CHANGELOG.md`
- **hash_equals constant-time token comparison convention** (1 connections) — `CLAUDE.md`
- **No secret ever lives on a site** (1 connections) — `CLAUDE.md`
- **P1 §3.6: nessun anti-replay sulle chiamate operative hub→plugin** (1 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P2 §3.8: sessione dashboard senza scadenza né verifica server-side** (1 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **mavida-fleet: token trapelato in wp-health-check.http** (1 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **P1 §3.5: service role key documentata come anon key, RLS non protettiva** (1 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **wp-fleet-manager (dashboard React)** (1 connections) — `docs/sicurezza-autenticazione-analisi.md`
- **kid-tagged central pubkey (k1/k2) rotation** (1 connections) — `README.md`
- **Never writes to wp-config.php constraint** (1 connections) — `README.md`
- **wphc_normalize_site_url() URL normalization rule** (1 connections) — `README.md`

## Relationships

- No strong cross-community connections detected

## Source Files

- `CHANGELOG.md`
- `CLAUDE.md`
- `README.md`
- `docs/sicurezza-autenticazione-analisi.md`

## Audit Trail

- EXTRACTED: 20 (91%)
- INFERRED: 2 (9%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*