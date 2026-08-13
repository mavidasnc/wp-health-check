# Enrollment, CORS & Signatures

> 22 nodes · cohesion 0.21

## Key Concepts

- **wphc_route_enroll()** (17 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_revoke()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_rotate()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_client_ip()** (11 connections) — `mu-plugins/wp-health-check.php`
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
- **wphc_build_enroll_signing_payload()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_handle_options_preflight()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_reassert_cors_headers()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_last_login()** (2 connections) — `mu-plugins/wp-health-check.php`

## Relationships

- [REST Routes & Dispatch](REST_Routes_%26_Dispatch.md) (26 shared connections)
- [Update Logging & Source Tracking](Update_Logging_%26_Source_Tracking.md) (9 shared connections)
- [Auto-Update State & Diagnostics](Auto-Update_State_%26_Diagnostics.md) (6 shared connections)
- [Bulk Update Job Engine](Bulk_Update_Job_Engine.md) (1 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 90 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*