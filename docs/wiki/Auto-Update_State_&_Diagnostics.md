# Auto-Update State & Diagnostics

> 28 nodes · cohesion 0.15

## Key Concepts

- **WP_REST_Request** (25 connections)
- **wphc_route_health()** (16 connections) — `mu-plugins/wp-health-check.php`
- **wphc_record_access()** (15 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_detail_theme()** (9 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_detail_plugins()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_detail_server()** (8 connections) — `mu-plugins/wp-health-check.php`
- **wphc_mute_update_shortcircuit()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_request_wants_check()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_request_wants_fresh()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_restore_update_shortcircuit()** (6 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_public_ip()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_ip_is_public()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_debug()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_detail_users()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_reactivate()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_core()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_plugin()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_update_theme()** (5 connections) — `mu-plugins/wp-health-check.php`
- **wphc_auto_update_enabled_for()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_get_server_ip()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_map_item_update_outcome()** (4 connections) — `mu-plugins/wp-health-check.php`
- **wphc_bulk_enqueue_preflight()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_route_thumbnail_status()** (3 connections) — `mu-plugins/wp-health-check.php`
- **wphc_build_user_summary()** (2 connections) — `mu-plugins/wp-health-check.php`
- **wphc_core_auto_update_state()** (2 connections) — `mu-plugins/wp-health-check.php`
- *... and 3 more nodes in this community*

## Relationships

- [REST Routes & Dispatch](REST_Routes_%26_Dispatch.md) (43 shared connections)
- [Update Logging & Source Tracking](Update_Logging_%26_Source_Tracking.md) (9 shared connections)
- [Enrollment, CORS & Signatures](Enrollment%2C_CORS_%26_Signatures.md) (6 shared connections)
- [Bulk Update Job Engine](Bulk_Update_Job_Engine.md) (5 shared connections)

## Source Files

- `mu-plugins/wp-health-check.php`

## Audit Trail

- EXTRACTED: 118 (100%)
- INFERRED: 0 (0%)
- AMBIGUOUS: 0 (0%)

---

*Part of the graphify knowledge wiki. See [index](index.md) to navigate.*