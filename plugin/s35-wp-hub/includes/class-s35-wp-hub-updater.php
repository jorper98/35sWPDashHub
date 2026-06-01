<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Injects a plugin update into WordPress when the hub publishes a newer version via a JSON manifest.
 */
final class S35_Wp_Hub_Updater
{
    private const CACHE_TRANSIENT = 's35_wp_hub_update_manifest_v1';

    private const CACHE_TTL_OK = 21600;

    private const CACHE_TTL_FAIL = 900;

    public static function boot(): void
    {
        $url = self::manifest_url();
        if ($url === '') {
            return;
        }

        add_filter('site_transient_update_plugins', [self::class, 'filter_update_plugins'], 10, 1);
    }

    public static function manifest_url(): string
    {
        if (defined('S35_WP_HUB_UPDATE_MANIFEST_URL')) {
            $fromConst = (string) constant('S35_WP_HUB_UPDATE_MANIFEST_URL');
            if ($fromConst !== '') {
                return $fromConst;
            }
        }

        return (string) apply_filters('s35_wp_hub_update_manifest_url', '');
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public static function filter_update_plugins($transient)
    {
        if (! is_object($transient) || empty($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        $plugin_file = plugin_basename(S35_WP_HUB_FILE);
        if (! isset($transient->checked[$plugin_file])) {
            return $transient;
        }

        $manifest = self::get_manifest();
        if ($manifest === null) {
            return $transient;
        }

        $new_version = (string) ($manifest['version'] ?? '');
        if ($new_version === '' || version_compare($new_version, S35_WP_HUB_VERSION, '<=')) {
            return $transient;
        }

        $package = (string) ($manifest['package'] ?? '');
        if ($package === '' || ! self::is_allowed_package_url($package)) {
            return $transient;
        }

        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[$plugin_file] = (object) [
            'id' => $plugin_file,
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'new_version' => $new_version,
            'url' => (string) ($manifest['url'] ?? ''),
            'package' => $package,
            'tested' => (string) ($manifest['tested'] ?? ''),
            'requires_php' => (string) ($manifest['requires_php'] ?? '7.4'),
            'requires' => (string) ($manifest['requires'] ?? '6.0'),
        ];

        return $transient;
    }

    /**
     * @return ?array<string, mixed>
     */
    private static function get_manifest(): ?array
    {
        $cached = get_transient(self::CACHE_TRANSIENT);
        if (is_array($cached) && isset($cached['_fetch_failed'])) {
            return null;
        }
        if (is_array($cached) && isset($cached['version'])) {
            return $cached;
        }

        $url = self::manifest_url();
        if ($url === '' || ! self::is_allowed_manifest_url($url)) {
            return null;
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 12,
                'redirection' => 2,
                'sslverify' => true,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_TRANSIENT, ['_fetch_failed' => 1], self::CACHE_TTL_FAIL);

            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (! is_array($data) || ($data['version'] ?? '') === '') {
            set_transient(self::CACHE_TRANSIENT, ['_fetch_failed' => 1], self::CACHE_TTL_FAIL);

            return null;
        }

        set_transient(self::CACHE_TRANSIENT, $data, self::CACHE_TTL_OK);

        return $data;
    }

    private static function is_allowed_manifest_url(string $url): bool
    {
        return (bool) preg_match('#^https://#i', $url);
    }

    private static function is_allowed_package_url(string $url): bool
    {
        if (! preg_match('#^https://#i', $url)) {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $lower = strtolower($path);

        return strlen($lower) > 4 && substr($lower, -4) === '.zip';
    }
}
