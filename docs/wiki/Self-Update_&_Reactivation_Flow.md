# Self-Update & Reactivation Flow

> 25 nodes

## Key Concepts

- **wphc_log_update_row()** (14 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_item_update()** (13 connections) — `mu-plugins/wp-health-check.php`
- **wphc_generate_correlation_id()** (12 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_core_update()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_perform_reactivate()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_autologin_token()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_update_preflight()** (7 connections) — `mu-plugins/wp-health-check.php`
- **wphc_log_wp_initiated_update()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_log_core_version_change()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_update_log_table()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_last_update()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_release_update_lock()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_consume_autologin()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_ajax_reveal_secret()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_current_actor()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_detect_update_source()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_reactivation_candidates()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_is_package_host_allowed()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_prune_update_log()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_reactivate_preflight()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_install_update_log_schema()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_maybe_invalidate_item_opcache()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_patch_update_transient_after_success()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_track_pending_autologin()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_untrack_pending_autologin()** (2 connections) — `mu-plugins/wp-health-check.php`

## Relationships

- [REST Routes & Dispatch](REST_Routes_%26_Dispatch.md) (36 shared connections)
- [Enrollment & Signature Verification](Enrollment_%26_Signature_Verification.md) (9 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 88 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*