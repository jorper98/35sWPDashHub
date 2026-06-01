<?php
/**
 * Plugin Name:       35sDash Companion
 * Plugin URI:        https://35sites.com/wordpress-plugins/
 * Description:       Centralized Wordpress Sites dashboard / Manager
 * Version:           0.0.26
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            35sites.com
 * Author URI:        https://35sites.com/wordpress-plugins/
 * License:           MIT
 * Text Domain:       s35-wp-hub
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('S35_WP_HUB_FILE', __FILE__);
define('S35_WP_HUB_VERSION', '0.0.26');

/**
 * Optional hardening: comma-separated allowed IPv4/IPv6 addresses for REST requests to this plugin.
 * Example: define('S35_WP_HUB_ALLOWED_IPS', '203.0.113.10,2001:db8::1');
 */
if (! defined('S35_WP_HUB_ALLOWED_IPS')) {
    define('S35_WP_HUB_ALLOWED_IPS', '');
}

/**
 * Optional: URL to a JSON manifest so WordPress can offer one-click plugin updates from your hub.
 * Example: define('S35_WP_HUB_UPDATE_MANIFEST_URL', 'https://hub.example.com/public/plugin-update/manifest.php');
 * See public/plugin-update/ in the s35-wp-hub repository.
 */
if (! defined('S35_WP_HUB_UPDATE_MANIFEST_URL')) {
    define('S35_WP_HUB_UPDATE_MANIFEST_URL', '');
}

/**
 * Optional: comma-separated hostnames allowed as plugin package URLs for fleet deploy (POST /plugins/install-package).
 * If empty, the host from S35_WP_HUB_UPDATE_MANIFEST_URL is used when set.
 * Example: define('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS', 'hub.example.com,cdn.example.com');
 */
if (! defined('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS')) {
    define('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS', '');
}

require_once __DIR__ . '/includes/class-s35-wp-hub-package-policy.php';
require_once __DIR__ . '/includes/class-s35-wp-hub-plugin-installer.php';
require_once __DIR__ . '/includes/class-s35-wp-hub-wpvivid.php';
require_once __DIR__ . '/includes/class-s35-wp-hub-rest.php';
require_once __DIR__ . '/includes/class-s35-wp-hub-updater.php';

S35_Wp_Hub_Updater::boot();

add_action(
    'init',
    static function (): void {
        load_plugin_textdomain('s35-wp-hub', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
);

add_action('rest_api_init', [S35_Wp_Hub_Rest::class, 'register_routes']);
