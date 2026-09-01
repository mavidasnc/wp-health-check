# Bulk Update Job Engine

> 47 nodes · cohesion 0.09

## Key Concepts

- **wp-health-check.php** (146 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_run_tick()** (17 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_bulk_enqueue()** (16 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_bulk_status()** (12 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_finish()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_save_job()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_apply_outcome()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_is_active()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_recount()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_bulk_cancel()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_abort_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_get_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_maybe_reap()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_reap_stuck_items()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_schedule_tick()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_clear_stale_maintenance()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_backoff_for_attempt()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_maybe_finalize()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_normalize_items()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_summary()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maintenance_file_path()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_self_update()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_acquire_lock()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_body_is_core_only()** (2 connections) — `mu-plugins/wp-health-check.php`
- *... and 22 more nodes in this community*

## Relationships

- [Auto-Update State & Diagnostics](Auto-Update_State_%26_Diagnostics.md) (39 shared connections)
- [Update Logging & Source Tracking](Update_Logging_%26_Source_Tracking.md) (30 shared connections)
- [Enrollment, CORS & Signatures](Enrollment%2C_CORS_%26_Signatures.md) (23 shared connections)
- [Webhook Dispatch & Signing](Webhook_Dispatch_%26_Signing.md) (22 shared connections)
- [CLI Reset & Enrollment Commands](CLI_Reset_%26_Enrollment_Commands.md) (3 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 217 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*