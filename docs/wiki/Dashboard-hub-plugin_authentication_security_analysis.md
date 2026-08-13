# Dashboard-hub-plugin authentication security analysis

> God node · 14 connections · `docs/sicurezza-autenticazione-analisi.md`

**Community:** [Protocol & Security Docs](Protocol_%26_Security_Docs.md)

## Connections by Relation

### references
- §5.B target model: per-site rotatable secret with anti-replay `EXTRACTED`
- P1: no anti-replay on hub-to-plugin operational calls `EXTRACTED`
- P1: per-site token not rotatable/revocable, fleet-wide single secret `EXTRACTED`
- P2: 20s autologin token producing 14-day session asymmetry `EXTRACTED`
- P2: /enroll without nonce or freshness window on issued_at `EXTRACTED`
- P2: no rate limit/lockout or HTTPS gate on plugin routes `EXTRACTED`
- P2: Bearer compared with == instead of constant-time in hub backend `EXTRACTED`
- P3: wp_health_check_last_autologin written but never read, excluded from reset `EXTRACTED`
- P3: Ed25519 public key hardcoded without kid identifier `EXTRACTED`
- P0: static bearer token in public JS bundle + self-declared identity `EXTRACTED`
- P0: POST /enroll public without rate limit (SSRF + enrollment oracle) `EXTRACTED`
- P1: Application Password stored in plaintext on Supabase `EXTRACTED`
- P1: Supabase service role key documented as anon key, RLS non-protective `EXTRACTED`
- P2: dashboard session without expiry or server-side verification `EXTRACTED`

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*