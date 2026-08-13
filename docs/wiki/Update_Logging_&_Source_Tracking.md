# Update Logging & Source Tracking

> 22 nodes · cohesion 0.16

## Key Concepts

- **wphc_generate_correlation_id()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_log_update_row()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_item_update()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_core_update()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_reactivate()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_autologin_token()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_update_preflight()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_log_wp_initiated_update()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_log_core_version_change()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_last_update()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_release_update_lock()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_detect_update_source()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_consume_autologin()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_ajax_reveal_secret()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_current_actor()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_is_package_host_allowed()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_prune_update_log()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_reactivate_preflight()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_invalidate_item_opcache()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_patch_update_transient_after_success()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_track_pending_autologin()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_untrack_pending_autologin()** (2 connections) — `mu-plugins/wp-health-check.php`

## Relationships

- [REST Routes & Dispatch](REST_Routes_%26_Dispatch.md) (27 shared connections)
- [Enrollment, CORS & Signatures](Enrollment%2C_CORS_%26_Signatures.md) (9 shared connections)
- [Auto-Update State & Diagnostics](Auto-Update_State_%26_Diagnostics.md) (9 shared connections)
- [Bulk Update Job Engine](Bulk_Update_Job_Engine.md) (3 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 86 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*