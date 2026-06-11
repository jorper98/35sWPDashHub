# Changelog

All notable changes to this project will be documented in this file.

## [0.0.21] 
### Fixed
- Resolved an issue where the dashboard snapshot would incorrectly report a plugin as inactive after an 'Activate' action if the plugin was already active on the remote site.
- Replaced raw $wpdb active plugin queries in the companion plugin sync with WordPress core's native is_plugin_active() function (post-cache-flush) to guarantee the snapshot perfectly matches the remote site's actual state.

## [0.0.20] 
### Added
- Integrated live inactive plugin check directly into the admin UI (Plugins tab).
- Added dynamic AJAX reporting for plugin status across all sites.

### Changed
- Improved path resolution in CLI scripts to support execution from both root and `public/` directories.
- Removed standalone `export_sites.php` and `check_plugins.php` scripts to eliminate security risks associated with web-accessible credential dumping.

### Security
- Removed public-facing scripts that could potentially expose decrypted application passwords. All sensitive operations are now strictly confined to the authenticated admin interface.

## [0.0.19] - Previous Version
- Initial dashboard hub manager features.
