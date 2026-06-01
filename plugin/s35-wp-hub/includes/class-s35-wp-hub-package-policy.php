<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Restricts which HTTPS hosts may be used as plugin package URLs for /plugins/install-package.
 */
final class S35_Wp_Hub_Package_Policy
{
    public static function is_package_url_allowed(string $url): bool
    {
        if (preg_match('#^https://#i', $url) !== 1) {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $lower = strtolower($path);

        if (strlen($lower) < 5 || substr($lower, -4) !== '.zip') {
            return false;
        }

        $host = self::url_host_lower($url);
        if ($host === '') {
            return false;
        }

        $allowed = self::allowed_hosts_lower();
        if ($allowed === []) {
            return false;
        }

        return self::host_matches_allowlist($host, $allowed);
    }

    /**
     * Human-readable reason the URL was rejected (for REST errors).
     */
    public static function explain_package_url_denial(string $url): string
    {
        if (preg_match('#^https://#i', $url) !== 1) {
            return __('Package URL must use HTTPS.', 's35-wp-hub');
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $lower = strtolower($path);
        if (strlen($lower) < 5 || substr($lower, -4) !== '.zip') {
            return __('Package URL path must end with .zip.', 's35-wp-hub');
        }

        $host = self::url_host_lower($url);
        if ($host === '') {
            return __('Package URL has no hostname.', 's35-wp-hub');
        }

        $allowed = self::allowed_hosts_lower();
        if ($allowed === []) {
            return sprintf(
                /* translators: %s: hostname from the package URL (hub host to allow) */
                __(
                    'No package hosts are configured. In wp-config.php add before wp-settings.php: define( \'S35_WP_HUB_ALLOWED_PACKAGE_HOSTS\', \'%s\' ); — use the same hostname as in your hub config base_url (the site that serves /plugin-packages/). Or set S35_WP_HUB_UPDATE_MANIFEST_URL to an HTTPS URL on that same host.',
                    's35-wp-hub'
                ),
                $host
            );
        }

        if (self::host_matches_allowlist($host, $allowed)) {
            return '';
        }

        return sprintf(
            /* translators: 1: hostname from package URL, 2: comma-separated allowlist */
            __(
                'Package host "%1$s" is not allowlisted (currently: %2$s). Set S35_WP_HUB_ALLOWED_PACKAGE_HOSTS to include that host, or point S35_WP_HUB_UPDATE_MANIFEST_URL at your hub on the same hostname (www vs non-www must match base_url, or list both hosts).',
                's35-wp-hub'
            ),
            $host,
            implode(', ', $allowed)
        );
    }

    /**
     * @param list<string> $allowedHostsLower
     */
    private static function host_matches_allowlist(string $packageHostLower, array $allowedHostsLower): bool
    {
        foreach ($allowedHostsLower as $a) {
            if ($a === '') {
                continue;
            }
            if (self::hosts_equivalent($packageHostLower, $a)) {
                return true;
            }
        }

        return false;
    }

    private static function hosts_equivalent(string $h1, string $h2): bool
    {
        if ($h1 === $h2) {
            return true;
        }
        $strip = static function (string $h): string {
            if (strlen($h) > 4 && substr($h, 0, 4) === 'www.') {
                return substr($h, 4);
            }

            return $h;
        };

        return $strip($h1) === $strip($h2);
    }

    /**
     * @return list<string>
     */
    public static function allowed_hosts_lower(): array
    {
        $raw = '';
        if (defined('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS')) {
            $raw = (string) constant('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS');
        }
        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));

            return array_values(array_filter(array_map('strtolower', $parts)));
        }

        $manifest = '';
        if (defined('S35_WP_HUB_UPDATE_MANIFEST_URL')) {
            $manifest = (string) constant('S35_WP_HUB_UPDATE_MANIFEST_URL');
        }
        if ($manifest === '') {
            return [];
        }
        $h = parse_url($manifest, PHP_URL_HOST);
        if (! is_string($h) || $h === '') {
            return [];
        }

        return [strtolower($h)];
    }

    private static function url_host_lower(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST);

        return is_string($h) && $h !== '' ? strtolower($h) : '';
    }
}
