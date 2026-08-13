# REST Routes & Dispatch

> 52 nodes · cohesion 0.06

## Key Concepts

- **wp-health-check.php** (144 connections) — `mu-plugins/wp-health-check.php`
- **wphc_normalize_site_url()** (17 connections) — `mu-plugins/wp-health-check.php`
- **wphc_dispatch_webhook_payload()** (9 connections) — `mu-plugins/wp-health-check.php`
- **wphc_update_log_table()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_send_webhook_for_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_generate_thumbnail()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_render_site_health_tab()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_log()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_clear_stale_maintenance()** (4 connections) — `mu-plugins/wp-health-check.php`
- **WPHC_CLI_Command** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_update_log_entries()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_handle_test_webhook()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_thumbnail_regenerate()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_webhook_backoff_for_attempt()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_webhook_url_is_allowed()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_base64url_encode()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_build_outbound_signature()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_build_webhook_payload()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_normalize_items()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_reactivation_candidates()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maintenance_file_path()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_retry_webhook()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_self_update()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_webhook_result()** (3 connections) — `mu-plugins/wp-health-check.php`
- *... and 27 more nodes in this community*

## Relationships

- [Auto-Update State & Diagnostics](Auto-Update_State_%26_Diagnostics.md) (43 shared connections)
- [Bulk Update Job Engine](Bulk_Update_Job_Engine.md) (30 shared connections)
- [Update Logging & Source Tracking](Update_Logging_%26_Source_Tracking.md) (27 shared connections)
- [Enrollment, CORS & Signatures](Enrollment%2C_CORS_%26_Signatures.md) (26 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 212 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*