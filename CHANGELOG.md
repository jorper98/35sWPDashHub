# Changelog

All notable changes to this project are recorded here. The dashboard semantic version is `S35WpHub\Version::VERSION` in [`src/Version.php`](src/Version.php). The expected **35sDash Companion** (folder `plugin/s35-wp-hub`) zip version is `Version::COMPANION_PLUGIN_EXPECTED` in the same file (and `S35_WP_HUB_VERSION` in [`plugin/s35-wp-hub/s35-wp-hub.php`](plugin/s35-wp-hub/s35-wp-hub.php)).

Line-by-line **companion plugin** release notes (WordPress.org–style) live in [`plugin/s35-wp-hub/readme.txt`](plugin/s35-wp-hub/readme.txt) under **Changelog**.

## [0.0.19] — 2026-06-01

**Companion expected:** **0.0.26** (rebuild and deploy — fixes stale plugin active status in summary endpoint).

### Companion (zip 0.0.26)

- **Bug fix:** summary endpoint `installed_plugins` no longer relies on `is_plugin_active()` for activation status. After clearing the WordPress object cache, `active_plugins` and `active_sitewide_plugins` are now read directly from the database via `$wpdb` queries, bypassing any persistent object cache layer (Redis, Memcached, etc.) that could serve stale data after remote plugin activation/deactivation. This fixes the case where activating an already-active plugin from the dashboard left it stuck in the "Inactive" list after sync.
- Added `wp_cache_flush_runtime()` call (WordPress 6.0+) to clear the runtime cache alongside `wp_cache_flush()`.

## [0.0.18] — 2026-05-15

**Companion expected:** **0.0.25** (unchanged).

### Dashboard

- **Snapshot management:** new "Manage Snapshots" table on the Compare tab with checkboxes, select-all, and bulk "Delete selected" action. Individual per-row delete buttons also added.
- **Plugins tab:** site filter dropdown added — filter the fleet plugin inventory by a specific site. Counts and bulk buttons update to match the selected site.
- **Plugins tab:** Sites column now shows **Active / Inactive** counts (e.g. `7 / 3`) instead of a single total, and updates live when filters change.

## [0.0.17] — 2026-05-15

**Companion expected:** **0.0.25** (rebuild and deploy — fixes incorrect inactive plugin status reporting).

### Dashboard

- **Bug fix:** active plugin status now accurately reflects WordPress state. Companion plugin switched from `in_array($plugin_file, get_option('active_plugins'))` to `is_plugin_active($plugin_file)` in the summary endpoint, matching the activation endpoint's behavior and avoiding stale cache mismatches.
- **Bug fix:** added `sleep(2)` before post-action sync in activate/deactivate/delete handlers so WordPress has time to flush plugin state before the dashboard captures the snapshot.
- **Bug fix:** filter state persistence on the Plugins tab now uses the `URL` constructor and `sessionStorage` for reliable cross-reload preservation.
- **Bug fix:** auto-reload after fleet plugin actions (activate, deactivate, delete) completes after 3 seconds so the updated status is visible without a manual reload.
- **Bug fix:** syntax error in `sites-plugins-tab.js` (missing closing `})` on `.then` callback) which broke all filter functionality.
- **Compare tab:** changed-row background color adjusted to `#424c69` (dark blue-grey) for better readability.

## [0.0.16] — 2026-05-14

**Companion expected:** **0.0.23** (unchanged).

### Dashboard

- **Bug fix:** undefined variable warnings for `$activityActionFilter` and `$compareResultFilter` in `Application.php` — variables are now initialized before their conditional blocks so they're always defined when passed to the view.
- **Bug fix:** removed duplicate `$scope` validation block in `postRunUpdates()`.
- **Sites → Plugins:** filter state (search text and status filter) is now persisted in the URL as `plugin_search` and `plugin_status` query parameters. Filters survive page reloads after bulk actions (activate, deactivate, delete).
- **Compare tab:** changed-row background color updated from a light cream (`#fef2f2`) to a darker red tint (`rgba(248, 113, 113, 0.1)`) so text remains readable on the dark theme.

## [0.0.15] — 2026-05-11

**Companion expected:** **0.0.23** (unchanged).

### Dashboard

- **Export CSV** button on the Sites page head. Downloads `sites-inventory-YYYY-MM-DD.csv` with full plugin inventory, active theme, and WordPress core version for every site.
- Columns: `Site, SiteURL, Owner, PHP Version, Type, Name, Status, Version`.
- Themes: only the active theme is exported (the snapshot does not store inactive themes).

## [0.0.14] — 2026-05-11

**Companion expected:** **0.0.23** (unchanged).

### Dashboard

- **Sites tab:** Plugins and Themes columns now show **Active / Inactive** counts instead of a single pending-update number. Core column remains unchanged.
- **Sync:** companion sync counts active/inactive plugins from `installed_plugins` in the summary response.
- **Database:** new columns `active_plugins`, `inactive_plugins`, `active_themes`, `inactive_themes` added to `sites` table.

## [0.0.13] — 2026-05-11

**Companion expected:** **0.0.23** (unchanged).

### Dashboard

- Internal updates and housekeeping.

## [0.0.12] — 2026-05-05

**Companion expected:** **0.0.23** (unchanged from 0.0.11).

### Dashboard

- **Fleet-wide update snapshots:** "Sync all" and **Capture now** on the Compare tab record the complete plugin/theme state of **every online site** at a single point in time. Each snapshot is a timestamped batch covering the entire fleet.
- **Compare tab** (Sites → Compare): select any snapshot to compare the entire fleet's current state against that point in time. The table shows **all sites, all plugins, all themes** with a **Same** / **Different** result per item. Differences include a reason (version changed, status changed, site offline, plugin installed/removed, etc.).
- A **"Capture now"** button on the Compare tab lets you manually sync all sites and capture a new fleet snapshot on demand.
- The snapshot count in the Sites header reflects the number of captured batches.

## [0.0.11] — 2026-05-04

**Companion expected:** **0.0.23** (rebuild and deploy the companion zip from `plugin/s35-wp-hub` — adds `/plugins/activate` REST endpoint).

### Dashboard

- **Sites → Plugins modal:** the **Status** column in the per-plugin site list is now a toggle button (**Active** / **Inactive**) that lets you activate or deactivate the plugin on that individual site directly from the button, instead of just displaying the current state. Bulk action toolbar added at the top of the modal with **Deactivate all**, **Activate all**, and **Delete from all** buttons.
- **Activate remote plugin:** new `POST activate_remote_plugin` handler and `RemoteUpdateService::activatePlugin()` method, calling the companion's `/wp-json/s35-wp-hub/v1/plugins/activate` route.

### Companion (zip 0.0.23)

- `POST /plugins/activate` — activates a plugin by file slug (requires `activate_plugins` capability, IP allowlist respected).

## [0.0.10] — 2026-04-03

**Companion expected:** **0.0.22** (rebuild and deploy the companion zip from `plugin/s35-wp-hub` so `installed_plugins` includes header metadata).

### Dashboard

- **Sites → Plugins:** each plugin row shows a **WordPress.org** link derived from the plugin folder slug (`folder/plugin.php` → `https://wordpress.org/plugins/folder/`, same idea as wp-admin “plugin information” for directory plugins; may 404 for non-repo plugins). When the companion provides **Author URI**, an **Author** link is shown as a fallback (deduplicated if it matches the .org URL). The site list modal repeats the same links.

### Companion (zip 0.0.22)

- Summary `installed_plugins` adds optional `plugin_uri`, `author`, and `author_uri` from `get_plugins()` headers for the hub UI.

## [0.0.9] — 2026-04-03

**Companion expected:** 0.0.21 (unchanged from 0.0.8; no companion zip requirement for this dashboard-only release).

### Dashboard

- **Sites → Status:** when a site is **online**, the **connection test** is **OK**, and there is **at least one pending update** (plugins, themes, or core), the green checkmark is replaced by an **UPDATES** label in the warning/amber color so you can scan the grid for work without opening each row.
- **Sites → Plugins:** **Show** filter on the fleet table — **All plugins**, **Active on any site**, or **Inactive on any site** — combined with the existing search box (client-side filtering).

Further dashboard changes will be logged in new sections below as they ship.

## [0.0.8] — 2026-04-01

**Companion expected:** 0.0.21 (rebuild and deploy the companion zip from `plugin/s35-wp-hub`).

### Dashboard

- Rebrand UI to **35sDash — WordPress Sites Dashboard** (`Version::APP_DISPLAY_NAME`: header, login, email report tagline).
- **Sites → Plugins:** fleet plugin inventory with **Deactivate** (all sites / per site) and **Remove** (delete plugin files), progress overlay, activity log actions `plugin_deactivate` / `plugin_deactivate_failed` and existing delete actions.
- **Sync:** always overlay `installed_plugins` from the live companion summary when present; stricter JSON `active` coercion; `JSON_INVALID_UTF8_SUBSTITUTE` on snapshot encoding to avoid falling back to stale snapshots.

### Companion (zip 0.0.21)

- **35sDash Companion** plugin header (name, description, Author URI, Plugin URI → [wordpress-plugins](https://35sites.com/wordpress-plugins/)).
- `POST /plugins/delete` and `POST /plugins/deactivate` (cannot remove or deactivate the companion via the hub).
- Summary `installed_plugins`: invalidate `active_plugins` / network sitewide caches before reading; derive `active` from options + `is_plugin_active_for_network` so fleet status matches wp-admin after remote deactivate.

## [0.0.7] — 2026-03-28

**Companion expected:** see that release’s README/CHANGELOG entry; upgrade path described in historical notes below.

### 2026-04-01 (0.0.7 era)

- **WPvivid backup column** on Sites: reads `wpvivid_backup` from the companion summary (last successful remote backup in WPvivid’s list when present, otherwise last completed backup task).
- **Edition detection:** Pro is resolved before free; inactive or missing WPvivid shows **N/A** in the hub.
- **Snapshot:** `wpvivid_backup` is stored in `site_snapshot_json`; cleared when sync falls back to core REST only (no companion summary).
- **UI:** Pro/Free labels, overdue highlighting, red “No successful backup recorded” line, table-specific CSS.
- **Sync:** Successful per-site **Sync** (AJAX) reloads the dashboard so the grid updates immediately.

### 2026-03-28 (dashboard 0.0.7)

- Site registry, **owners** (assign sites, renewal date), **email reports** per owner, encrypted credentials, fleet sync, remote updates via the companion, **Plugin packages**, dynamic **manifest.php** for companion updates, dashboard auth, and bulk actions (see README for setup).

## [0.0.5] — 2026-03-25

- Fleet **plugin inventory** and more reliable install-from-URL behavior on the WordPress REST side.
- Companion releases aligned with this era are summarized in `plugin/s35-wp-hub/readme.txt` (for example 0.0.8+ inventory-related summary work).

## [0.0.3] — 2026-03-24

- Release packaging and version gating for the companion.
- Remote updates REST route uses an explicit **POST** method.

## [0.0.1] — 2026-03-24

- Initial public dashboard and **s35-wp-hub** companion plugin (early tagline: WP-Central Lite).
