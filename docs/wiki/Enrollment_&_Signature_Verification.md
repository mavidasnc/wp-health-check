# Enrollment & Signature Verification

> 20 nodes

## Key Concepts

- **wphc_route_enroll()** (17 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_revoke()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_rotate()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_client_ip()** (10 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_send_cors_headers()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_require_token()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_candidate_site_urls()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_normalize_url()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_throttle_check()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_throttle_register_failure()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_verify_request_signature()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_nonce_seen()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_remember_nonce()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_build_signed_payload()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_require_https()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_verify_central_signature()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_enroll_error()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_send_enroll_mismatch_alert()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_reassert_cors_headers()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_last_login()** (2 connections) — `mu-plugins/wp-health-check.php`

## Relationships

- [REST Routes & Dispatch](REST_Routes_%26_Dispatch.md) (32 shared connections)
- [Self-Update & Reactivation Flow](Self-Update_%26_Reactivation_Flow.md) (9 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 87 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*