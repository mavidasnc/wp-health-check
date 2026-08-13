# Bulk Update Job Engine

> 25 nodes · cohesion 0.14

## Key Concepts

- **wphc_bulk_run_tick()** (16 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_bulk_enqueue()** (15 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_finish()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_bulk_status()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_apply_outcome()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_is_active()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_recount()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_bulk_cancel()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_abort_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_get_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_maybe_reap()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_save_job()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_reap_stuck_items()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_schedule_tick()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_backoff_for_attempt()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_summary()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_acquire_lock()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_classify_result()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_maybe_finalize()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_maybe_rearm()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_next_delay()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_next_item_index()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_release_lock()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_sync_summary()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_unschedule_ticks()** (2 connections) — `mu-plugins/wp-health-check.php`

## Relationships

- [REST Routes & Dispatch](REST_Routes_%26_Dispatch.md) (30 shared connections)
- [Auto-Update State & Diagnostics](Auto-Update_State_%26_Diagnostics.md) (5 shared connections)
- [Update Logging & Source Tracking](Update_Logging_%26_Source_Tracking.md) (3 shared connections)
- [Enrollment, CORS & Signatures](Enrollment%2C_CORS_%26_Signatures.md) (1 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 81 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*