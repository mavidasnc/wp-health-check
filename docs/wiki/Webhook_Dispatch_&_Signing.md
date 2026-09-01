# Webhook Dispatch & Signing

> 19 nodes · cohesion 0.13

## Key Concepts

- **wphc_normalize_site_url()** (17 connections) — `mu-plugins/wp-health-check.php`
- **wphc_dispatch_webhook_payload()** (9 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_send_webhook_for_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_render_site_health_tab()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_log()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_update_log_entries()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_handle_test_webhook()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_webhook_backoff_for_attempt()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_webhook_url_is_allowed()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_base64url_encode()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_build_outbound_signature()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_build_webhook_payload()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_retry_webhook()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_webhook_result()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_ping()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_webhook_url()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_ajax_view_log()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_latest_version()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_handle_save_webhook()** (2 connections) — `mu-plugins/wp-health-check.php`

## Relationships

- [Bulk Update Job Engine](Bulk_Update_Job_Engine.md) (22 shared connections)
- [Auto-Update State & Diagnostics](Auto-Update_State_%26_Diagnostics.md) (10 shared connections)
- [Enrollment, CORS & Signatures](Enrollment%2C_CORS_%26_Signatures.md) (4 shared connections)
- [Update Logging & Source Tracking](Update_Logging_%26_Source_Tracking.md) (2 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 61 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*