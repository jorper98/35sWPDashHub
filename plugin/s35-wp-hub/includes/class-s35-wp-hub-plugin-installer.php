<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Installs or upgrades a plugin from an HTTPS .zip URL (used by the hub fleet deploy flow).
 */
final class S35_Wp_Hub_Plugin_Installer
{
    public static function install_from_url(string $package_url): WP_REST_Response|WP_Error
    {
        if (! S35_Wp_Hub_Package_Policy::is_package_url_allowed($package_url)) {
            $detail = S35_Wp_Hub_Package_Policy::explain_package_url_denial($package_url);

            return new WP_Error('package_url_not_allowed', $detail !== '' ? $detail : __('Package URL is not allowed.', 's35-wp-hub'), ['status' => 403]);
        }

        require_once __DIR__ . '/class-s35-wp-hub-plugin-upgrader.php';

        $temp_file = download_url($package_url);
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        $plugin_file = self::detect_plugin_file_from_zip((string) $temp_file);
        if (is_wp_error($plugin_file)) {
            @unlink($temp_file);

            return $plugin_file;
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = isset(get_plugins()[$plugin_file]);

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new S35_Wp_Hub_Plugin_Upgrader($skin);

        ob_start();
        if ($installed) {
            $result = $upgrader->run(
                [
                    'package' => $temp_file,
                    'destination' => WP_PLUGIN_DIR,
                    'clear_destination' => true,
                    'clear_working' => true,
                    'hook_extra' => [
                        'plugin' => $plugin_file,
                        'type' => 'plugin',
                        'action' => 'update',
                    ],
                ]
            );
        } else {
            $result = $upgrader->install($temp_file);
        }
        ob_end_clean();

        @unlink($temp_file);

        if (is_wp_error($result)) {
            return $result;
        }

        // install() returns true on success. run() (updates) returns an array from install_package(), not true.
        $success = $result === true || (is_array($result) && $result !== []);
        if (! $success) {
            $detail = self::format_upgrader_messages($skin);
            if ($detail === '') {
                $detail = __('Plugin install did not complete.', 's35-wp-hub');
            }

            return new WP_Error('install_failed', $detail, ['status' => 500]);
        }

        $message = $installed
            ? sprintf(
                /* translators: %s: plugin basename path */
                __('Updated plugin: %s', 's35-wp-hub'),
                $plugin_file
            )
            : sprintf(
                /* translators: %s: plugin basename path */
                __('Installed plugin: %s', 's35-wp-hub'),
                $plugin_file
            );

        return new WP_REST_Response(
            [
                'ok' => true,
                'message' => $message,
                'plugin' => $plugin_file,
            ],
            200
        );
    }

    /**
     * Core unzip_file() assumes {@see WP_Filesystem()} is already initialized; the upgrader does that
     * later, but we unzip earlier to read the plugin header — so connect first (same context/relaxed
     * ownership as {@see S35_Wp_Hub_Plugin_Upgrader::fs_connect()}).
     *
     * @return bool|WP_Error
     */
    private static function ensure_wp_filesystem_for_unzip()
    {
        global $wp_filesystem;

        if (is_object($wp_filesystem)) {
            return true;
        }

        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        ob_start();
        $credentials = request_filesystem_credentials('', '', false, WP_CONTENT_DIR, null, true);
        ob_end_clean();

        if (false === $credentials || ! WP_Filesystem($credentials, WP_CONTENT_DIR, true)) {
            return new WP_Error(
                'fs_unavailable',
                __('Could not access filesystem.', 's35-wp-hub'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * @return string|WP_Error Plugin file relative to wp-content/plugins (e.g. slug/slug.php)
     */
    private static function detect_plugin_file_from_zip(string $zip_path)
    {
        $work = wp_tempnam('s35-hub-plg');
        if ($work === false) {
            return new WP_Error('temp', __('Could not create working directory.', 's35-wp-hub'), ['status' => 500]);
        }
        @unlink($work);
        if (! mkdir($work, 0755) && ! is_dir($work)) {
            return new WP_Error('temp', __('Could not create working directory.', 's35-wp-hub'), ['status' => 500]);
        }

        $fs_ready = self::ensure_wp_filesystem_for_unzip();
        if (is_wp_error($fs_ready)) {
            self::delete_tree($work);

            return $fs_ready;
        }

        $unzipped = unzip_file($zip_path, $work . DIRECTORY_SEPARATOR);
        if (is_wp_error($unzipped)) {
            self::delete_tree($work);

            return $unzipped;
        }

        $subdirs = glob($work . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        if (count($subdirs) !== 1) {
            self::delete_tree($work);

            return new WP_Error(
                'bad_zip',
                __('Plugin zip must contain exactly one top-level folder.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        $slug_dir = $subdirs[0];
        $slug = basename($slug_dir);
        $main_php = null;
        foreach (glob($slug_dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $php) {
            $data = get_plugin_data($php, false, false);
            if (! empty($data['Name'])) {
                $main_php = basename($php);
                break;
            }
        }

        self::delete_tree($work);

        if ($main_php === null) {
            return new WP_Error(
                'bad_zip',
                __('No WordPress plugin header (Plugin Name) found in the zip.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        return $slug . '/' . $main_php;
    }

    /** @param object $skin {@see Automatic_Upgrader_Skin} */
    private static function format_upgrader_messages(object $skin): string
    {
        if (! method_exists($skin, 'get_upgrade_messages')) {
            return '';
        }
        $messages = $skin->get_upgrade_messages();
        if (! is_array($messages) || $messages === []) {
            return '';
        }
        $tail = array_slice($messages, -5);

        return implode(' ', array_map('strip_tags', $tail));
    }

    private static function delete_tree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
