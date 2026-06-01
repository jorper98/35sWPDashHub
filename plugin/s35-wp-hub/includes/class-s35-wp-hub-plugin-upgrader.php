<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/**
 * Plugin upgrader that connects to the filesystem with relaxed ownership.
 *
 * Core {@see Plugin_Upgrader} calls {@see WP_Upgrader::fs_connect()} with
 * `$allow_relaxed_file_ownership = false`, which rejects the `direct` transport on many
 * hosts where `wp-content/plugins` is group-writable. REST installs have no credentials
 * form, so that surfaces as "Could not access filesystem." We always allow relaxed
 * ownership for hub-triggered installs.
 */
final class S35_Wp_Hub_Plugin_Upgrader extends Plugin_Upgrader
{
    /**
     * @param string[] $directories
     *
     * @return bool|\WP_Error
     */
    public function fs_connect( $directories = array(), $allow_relaxed_file_ownership = false ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
    {
        return parent::fs_connect($directories, true);
    }
}
