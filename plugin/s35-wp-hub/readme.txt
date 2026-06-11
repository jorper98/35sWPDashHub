=== 35sDashHub Companion ===
Contributors: 35sites
Tags: rest-api, updates, maintenance, agency
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.0.27
License: MIT

REST endpoints used by the self-hosted 35sDashHub (WordPress Sites Dashboard Hub Manager) PHP app (Application Passwords). Learn more: https://35sites.com/wordpress-plugins/

== Description ==

This companion exposes:

* `GET /wp-json/s35-wp-hub/v1/updates/summary` — pending counts plus `plugin_items`, `theme_items`, and `core_item` (same queue WordPress uses in `update_plugins` / `update_themes` transients). Also returns `wp_version`, `php_version`, `active_theme_name`, `companion_version`, `installed_plugins` (all installed plugins: `file`, `name`, `version`, `active`, plus optional `plugin_uri`, `author`, `author_uri` from plugin headers for dashboard links), and `wpvivid_backup` (when WPvivid Backup & Migration is active: last successful remote or completed backup time for fleet health) for the dashboard fleet view.
* `POST /wp-json/s35-wp-hub/v1/updates/run` — runs updates (JSON body: `{"scope":"all"}` or `plugins`, `themes`, `core`).
* `POST /wp-json/s35-wp-hub/v1/plugins/delete` — deletes an installed plugin (JSON body: `{"plugin_file":"slug/plugin.php"}`). Cannot remove the companion itself.
* `POST /wp-json/s35-wp-hub/v1/plugins/deactivate` — deactivates an installed plugin (same JSON body). Cannot deactivate the companion itself. Inactive plugins return success with an "already inactive" message.
* `POST /wp-json/s35-wp-hub/v1/plugins/activate` — activates an installed plugin (same JSON body). Requires `activate_plugins` capability.

Authentication uses the same WordPress Application Password as the dashboard (HTTP Basic).

Future (not in v0.0.1): one-click admin login (SSO) via a short-lived token validated here and `wp_set_auth_cookie()`.

== Installation ==

1. Upload the `s35-wp-hub` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Create an Application Password for an administrator on Users → Profile.
4. Configure the site in your 35sDashHub dashboard.

== Optional IP allowlist ==

In `wp-config.php` before loading WordPress:

`define('S35_WP_HUB_ALLOWED_IPS', '203.0.113.10');`

Comma-separated list. When empty, all IPs are allowed (still protected by Application Passwords).

== Optional updates from your hub ==

Define a manifest URL so WordPress can show an update when you publish a newer zip on your 35sDashHub server:

`define('S35_WP_HUB_UPDATE_MANIFEST_URL', 'https://your-hub.example.com/public/plugin-update/manifest.json');`

The manifest is JSON (`version`, `package` zip URL, optional `tested`, `requires`, `requires_php`). See the main project README. You can also use the `s35_wp_hub_update_manifest_url` filter.

== Changelog ==

= 0.0.27 =
* Fixed active plugin status reporting to use WordPress core's native is_plugin_active() after cache flush, guaranteeing the dashboard snapshot perfectly matches the remote site's actual state.

= 0.0.26 =
* Summary endpoint `installed_plugins`: bypass `is_plugin_active()` and read `active_plugins` / `active_sitewide_plugins` directly from `$wpdb` after cache flush, fixing stale status when object cache layers (Redis, Memcached) serve outdated data. Added `wp_cache_flush_runtime()` for WordPress 6.0+.

= 0.0.25 =
* Summary endpoint: switch from `in_array($plugin_file, get_option('active_plugins'))` to `is_plugin_active($plugin_file)` for accurate active status reporting matching activation endpoint behavior.

= 0.0.24 =
* Companion version bump aligned with dashboard 0.0.17 improvements (filter persistence, post-action auto-reload, sleep before post-action sync).

= 0.0.23 =
* `POST /plugins/activate` endpoint for remote plugin activation from the dashboard fleet view (requires `activate_plugins`; cannot activate this companion).

= 0.0.22 =
* Summary `installed_plugins` entries include `plugin_uri`, `author`, and `author_uri` from WordPress plugin headers (`get_plugins`) so the hub can show WordPress.org-style and author fallback links in the fleet Plugins view.

= 0.0.21 =
* Summary `installed_plugins`: drop stale option-cache for `active_plugins` / network sitewide list, then derive `active` from `get_option` + `is_plugin_active_for_network` so fleet "Active" matches wp-admin after remote deactivate.

= 0.0.20 =
* `POST /plugins/deactivate` for remote deactivation from the fleet view (requires `activate_plugins`; cannot deactivate this companion).

= 0.0.19 =
* Rebrand plugin header (35sDash Companion, 35sites.com / wordpress-plugins link). `POST /plugins/delete` for remote removal from the dashboard fleet view (cannot delete this companion).

= 0.0.18 =
* WPvivid summary: resolve Pro before free; include `edition` (`pro` or `free`). Inactive sites include `edition: null`.

= 0.0.17 =
* WPvivid detection: support Pro (`wpvivid-backup-pro`…), multisite network activation, and any active plugin path containing wpvivid + backup (slug variants).

= 0.0.16 =
* Summary: add `wpvivid_backup` for the hub dashboard (WPvivid last successful remote backup or last completed task).

= 0.0.15 =
* Remove temporary debug logging from fleet deploy (no behavior change vs 0.0.14).

= 0.0.14 =
* Fleet package install: initialize `WP_Filesystem` before `unzip_file()` when detecting the plugin slug in the zip (core `unzip_file` requires the global filesystem object; without it REST deploy returned `fs_unavailable` on hosts where nothing else had connected first).

= 0.0.12 =
* Fleet package install: treat a successful `Plugin_Upgrader::run()` result (array from core) as success, not failure — fixes false "Plugin install did not complete" on plugin updates.

= 0.0.11 =
* Load `S35_Wp_Hub_Plugin_Upgrader` only when running a package install so bootstrap does not require `Plugin_Upgrader` before `class-wp-upgrader.php` is loaded (fixes fatal error on every page load).

= 0.0.10 =
* Fleet package install (`/plugins/install-package`) uses a custom upgrader that allows relaxed filesystem ownership so `direct` works on typical shared hosts (fixes "Could not access filesystem" when deploying from the hub).

= 0.0.9 =
* Summary includes `installed_plugins` for fleet-wide plugin inventory on the dashboard.

= 0.0.5 =
* Summary JSON includes `companion_version` (same as the installed plugin version) so the dashboard can show whether the site needs a newer zip.

= 0.0.4 =
* Summary `active_theme_name` and `wp_version` are normalized to plain strings (avoids "Array" when a header or REST-shaped value is an array).

= 0.0.3 =
* Summary endpoint also returns `wp_version`, `php_version`, and `active_theme_name` for dashboard reports.

= 0.0.2 =
* Bulk plugin/theme updates: use a fresh upgrader per item and refresh update transients between runs (fixes only the first plugin updating).

= 0.0.1 =
* Initial release: summary and remote update endpoints.
