<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class S35_Wp_Hub_Rest
{
    public static function register_routes(): void
    {
        register_rest_route(
            's35-wp-hub/v1',
            '/updates/summary',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'summary'],
                'permission_callback' => [self::class, 'can_view_updates'],
            ]
        );

        register_rest_route(
            's35-wp-hub/v1',
            '/updates/run',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'run_updates'],
                'permission_callback' => [self::class, 'can_run_updates'],
                'args' => [
                    'scope' => [
                        'type' => 'string',
                        'default' => 'all',
                        'enum' => ['all', 'plugins', 'themes', 'core'],
                    ],
                ],
            ]
        );

        register_rest_route(
            's35-wp-hub/v1',
            '/plugins/install-package',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'install_package'],
                'permission_callback' => [self::class, 'can_install_package'],
                'args' => [
                    'package_url' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            's35-wp-hub/v1',
            '/plugins/delete',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'delete_plugin_rest'],
                'permission_callback' => [self::class, 'can_delete_plugin_rest'],
                'args' => [
                    'plugin_file' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            's35-wp-hub/v1',
            '/plugins/deactivate',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'deactivate_plugin_rest'],
                'permission_callback' => [self::class, 'can_deactivate_plugin_rest'],
                'args' => [
                    'plugin_file' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            's35-wp-hub/v1',
            '/plugins/activate',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'activate_plugin_rest'],
                'permission_callback' => [self::class, 'can_activate_plugin_rest'],
                'args' => [
                    'plugin_file' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );

        register_rest_route(
            's35-wp-hub/v1',
            '/integration/status',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'integration_status'],
                'permission_callback' => [self::class, 'can_view_updates'],
            ]
        );

        /**
         * SSO foundation (v0.0.1): reserved for a future POST /login/token flow.
         * Dashboard will mint a short-lived token; this endpoint will validate it and set auth cookies.
         * See PRD — not implemented in this release.
         */
    }

    public static function can_view_updates(): bool
    {
        if (! self::ip_allowed()) {
            return false;
        }

        return current_user_can('update_plugins')
            || current_user_can('update_themes')
            || current_user_can('update_core');
    }

    public static function can_run_updates(): bool
    {
        return self::can_view_updates();
    }

    public static function can_install_package(): bool
    {
        if (! self::ip_allowed()) {
            return false;
        }

        return current_user_can('install_plugins') && current_user_can('update_plugins');
    }

    public static function can_delete_plugin_rest(): bool
    {
        if (! self::ip_allowed()) {
            return false;
        }

        return current_user_can('delete_plugins');
    }

    public static function can_deactivate_plugin_rest(): bool
    {
        if (! self::ip_allowed()) {
            return false;
        }

        return current_user_can('activate_plugins');
    }

    public static function can_activate_plugin_rest(): bool
    {
        if (! self::ip_allowed()) {
            return false;
        }

        return current_user_can('activate_plugins');
    }

    public static function install_package(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ip_allowed()) {
            return new WP_Error('forbidden_ip', __('Request not allowed from this IP.', 's35-wp-hub'), ['status' => 403]);
        }

        $url = trim((string) $request->get_param('package_url'));
        if ($url === '') {
            return new WP_Error(
                'missing_package_url',
                __('Parameter package_url is required.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        return S35_Wp_Hub_Plugin_Installer::install_from_url($url);
    }

    public static function delete_plugin_rest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ip_allowed()) {
            return new WP_Error('forbidden_ip', __('Request not allowed from this IP.', 's35-wp-hub'), ['status' => 403]);
        }

        if (! current_user_can('delete_plugins')) {
            return new WP_Error('cannot_delete_plugins', __('You cannot delete plugins.', 's35-wp-hub'), ['status' => 403]);
        }

        $plugin_file = trim((string) $request->get_param('plugin_file'));
        $plugin_file = str_replace('\\', '/', $plugin_file);
        $plugin_file = ltrim($plugin_file, '/');
        if ($plugin_file === '') {
            return new WP_Error(
                'missing_plugin_file',
                __('Parameter plugin_file is required.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        if (validate_file($plugin_file) !== 0) {
            return new WP_Error('invalid_plugin_file', __('Invalid plugin path.', 's35-wp-hub'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $self_basename = plugin_basename(S35_WP_HUB_FILE);
        if ($plugin_file === $self_basename) {
            return new WP_Error(
                'cannot_delete_companion',
                __('Cannot delete the 35sDash Companion plugin from the dashboard.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        $full = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (! is_file($full)) {
            return new WP_Error('plugin_not_found', __('Plugin is not installed at that path.', 's35-wp-hub'), ['status' => 404]);
        }

        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file, true);
        }

        $deleted = delete_plugins([$plugin_file]);
        if ($deleted !== true) {
            return new WP_Error('delete_failed', __('Could not delete plugin files.', 's35-wp-hub'), ['status' => 500]);
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'message' => sprintf(
                    /* translators: %s: plugin basename */
                    __('Deleted plugin: %s', 's35-wp-hub'),
                    $plugin_file
                ),
            ],
            200
        );
    }

    public static function deactivate_plugin_rest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ip_allowed()) {
            return new WP_Error('forbidden_ip', __('Request not allowed from this IP.', 's35-wp-hub'), ['status' => 403]);
        }

        if (! current_user_can('activate_plugins')) {
            return new WP_Error(
                'cannot_deactivate_plugins',
                __('You cannot deactivate plugins.', 's35-wp-hub'),
                ['status' => 403]
            );
        }

        $plugin_file = trim((string) $request->get_param('plugin_file'));
        $plugin_file = str_replace('\\', '/', $plugin_file);
        $plugin_file = ltrim($plugin_file, '/');
        if ($plugin_file === '') {
            return new WP_Error(
                'missing_plugin_file',
                __('Parameter plugin_file is required.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        if (validate_file($plugin_file) !== 0) {
            return new WP_Error('invalid_plugin_file', __('Invalid plugin path.', 's35-wp-hub'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $self_basename = plugin_basename(S35_WP_HUB_FILE);
        if ($plugin_file === $self_basename) {
            return new WP_Error(
                'cannot_deactivate_companion',
                __('Cannot deactivate the 35sDash Companion plugin from the dashboard.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        $full = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (! is_file($full)) {
            return new WP_Error('plugin_not_found', __('Plugin is not installed at that path.', 's35-wp-hub'), ['status' => 404]);
        }

        if (! is_plugin_active($plugin_file)) {
            return new WP_REST_Response(
                [
                    'ok' => true,
                    'message' => sprintf(
                        /* translators: %s: plugin basename */
                        __('Plugin already inactive: %s', 's35-wp-hub'),
                        $plugin_file
                    ),
                ],
                200
            );
        }

        deactivate_plugins($plugin_file, true);

        if (is_plugin_active($plugin_file)) {
            return new WP_Error('deactivate_failed', __('Could not deactivate plugin.', 's35-wp-hub'), ['status' => 500]);
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'message' => sprintf(
                    /* translators: %s: plugin basename */
                    __('Deactivated plugin: %s', 's35-wp-hub'),
                    $plugin_file
                ),
            ],
            200
        );
    }

    public static function activate_plugin_rest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ip_allowed()) {
            return new WP_Error('forbidden_ip', __('Request not allowed from this IP.', 's35-wp-hub'), ['status' => 403]);
        }

        if (! current_user_can('activate_plugins')) {
            return new WP_Error(
                'cannot_activate_plugins',
                __('You cannot activate plugins.', 's35-wp-hub'),
                ['status' => 403]
            );
        }

        $plugin_file = trim((string) $request->get_param('plugin_file'));
        $plugin_file = str_replace('\\', '/', $plugin_file);
        $plugin_file = ltrim($plugin_file, '/');
        if ($plugin_file === '') {
            return new WP_Error(
                'missing_plugin_file',
                __('Parameter plugin_file is required.', 's35-wp-hub'),
                ['status' => 400]
            );
        }

        if (validate_file($plugin_file) !== 0) {
            return new WP_Error('invalid_plugin_file', __('Invalid plugin path.', 's35-wp-hub'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $full = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (! is_file($full)) {
            return new WP_Error('plugin_not_found', __('Plugin is not installed at that path.', 's35-wp-hub'), ['status' => 404]);
        }

        if (is_plugin_active($plugin_file)) {
            return new WP_REST_Response(
                [
                    'ok' => true,
                    'message' => sprintf(
                        /* translators: %s: plugin basename */
                        __('Plugin already active: %s', 's35-wp-hub'),
                        $plugin_file
                    ),
                ],
                200
            );
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return new WP_Error('activate_failed', $result->get_error_message(), ['status' => 500]);
        }

        if (! is_plugin_active($plugin_file)) {
            return new WP_Error('activate_failed', __('Could not activate plugin.', 's35-wp-hub'), ['status' => 500]);
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'message' => sprintf(
                    /* translators: %s: plugin basename */
                    __('Activated plugin: %s', 's35-wp-hub'),
                    $plugin_file
                ),
            ],
            200
        );
    }

    public static function integration_status(): WP_REST_Response
    {
        $manifestUrl = S35_Wp_Hub_Updater::manifest_url();
        $manifestHost = '';
        if ($manifestUrl !== '') {
            $mh = parse_url($manifestUrl, PHP_URL_HOST);
            $manifestHost = is_string($mh) && $mh !== '' ? strtolower($mh) : '';
        }

        $allowHosts = S35_Wp_Hub_Package_Policy::allowed_hosts_lower();
        $explicitRaw = defined('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS')
            ? trim((string) constant('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS'))
            : '';
        $source = 'none';
        if ($explicitRaw !== '') {
            $source = 'explicit';
        } elseif ($allowHosts !== []) {
            $source = 'manifest';
        }

        return new WP_REST_Response(
            [
                'manifest_configured' => $manifestUrl !== '',
                'manifest_host' => $manifestHost,
                'package_allowlist_hosts' => $allowHosts,
                'package_allowlist_empty' => $allowHosts === [],
                'package_allowlist_source' => $source,
            ],
            200
        );
    }

    private static function ip_allowed(): bool
    {
        $raw = (string) S35_WP_HUB_ALLOWED_IPS;
        if ($raw === '') {
            return true;
        }
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        if ($allowed === []) {
            return true;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return in_array($ip, $allowed, true);
    }

    /**
     * Theme name / REST fields may be a string or a WP-style array (e.g. raw + rendered). Never cast arrays to (string) — that becomes "Array".
     *
     * @param string|array<int|string, mixed>|int|float|null $value
     */
    private static function rest_scalar_string($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            foreach (['rendered', 'raw', 'name', 'text', 0] as $key) {
                if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
                    return trim($value[$key]);
                }
            }
            foreach ($value as $inner) {
                $s = self::rest_scalar_string($inner);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }

    public static function summary(): WP_REST_Response
    {
        self::load_update_dependencies();

        if (! function_exists('wp_version_check')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_version_check([], true);
        wp_update_plugins();
        wp_update_themes();

        $core_pending = 0;
        $core = get_core_updates();
        if (is_array($core) && isset($core[0]) && is_object($core[0]) && isset($core[0]->response) && $core[0]->response === 'upgrade') {
            $core_pending = 1;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_items = [];
        $pu = get_site_transient('update_plugins');
        if (is_object($pu) && ! empty($pu->response) && is_array($pu->response)) {
            foreach ($pu->response as $plugin_file => $update_data) {
                if (! is_string($plugin_file)) {
                    continue;
                }
                $path = WP_PLUGIN_DIR . '/' . $plugin_file;
                $name = $plugin_file;
                if (file_exists($path)) {
                    $pdata = get_plugin_data($path, false, false);
                    if (! empty($pdata['Name'])) {
                        $name = $pdata['Name'];
                    }
                }
                $new_version = '';
                if (is_object($update_data) && isset($update_data->new_version)) {
                    $new_version = (string) $update_data->new_version;
                }
                $plugin_items[] = [
                    'file' => $plugin_file,
                    'name' => $name,
                    'new_version' => $new_version,
                ];
            }
        }
        $plugins_pending = count($plugin_items);

        $theme_items = [];
        $tu = get_site_transient('update_themes');
        if (is_object($tu) && ! empty($tu->response) && is_array($tu->response)) {
            foreach ($tu->response as $stylesheet => $update_data) {
                if (! is_string($stylesheet)) {
                    continue;
                }
                $theme = wp_get_theme($stylesheet);
                $name = $theme->exists() ? (string) $theme->get('Name') : $stylesheet;
                $new_version = '';
                if (is_object($update_data) && isset($update_data->new_version)) {
                    $new_version = (string) $update_data->new_version;
                }
                $theme_items[] = [
                    'stylesheet' => $stylesheet,
                    'name' => $name,
                    'new_version' => $new_version,
                ];
            }
        }
        $themes_pending = count($theme_items);

        $core_item = null;
        if (is_array($core) && isset($core[0]) && is_object($core[0]) && isset($core[0]->response) && $core[0]->response === 'upgrade') {
            $o = $core[0];
            $core_item = [
                'version' => isset($o->version) ? (string) $o->version : '',
                'current' => isset($o->current) ? (string) $o->current : '',
            ];
        }

        $active_theme = wp_get_theme();
        $active_theme_name = $active_theme->exists() ? self::rest_scalar_string($active_theme->get('Name')) : '';

        // Persistent object caches can briefly serve stale `active_plugins` after remote activation/deactivation.
        // Clear per-key cache, alloptions batch cache, and multisite equivalents.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('active_plugins', 'options');
            wp_cache_delete('alloptions', 'options');
            if (is_multisite()) {
                wp_cache_delete('active_sitewide_plugins', 'site-options');
                wp_cache_delete('alloptions', 'site-options');
            }
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }

        // Read active_plugins directly from the database to guarantee fresh status
        // regardless of any persistent object cache layer (Redis, Memcached, etc.).
        global $wpdb;
        $db_active_plugins = [];
        $raw_active = $wpdb->get_var("SELECT option_value FROM $wpdb->options WHERE option_name = 'active_plugins'");
        if (is_string($raw_active)) {
            $db_active_plugins = maybe_unserialize($raw_active);
            if (! is_array($db_active_plugins)) {
                $db_active_plugins = [];
            }
        }
        $db_sitewide_plugins = [];
        if (is_multisite()) {
            $raw_sitewide = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM $wpdb->sitemeta WHERE meta_key = 'active_sitewide_plugins' AND site_id = %d",
                    get_current_network_id()
                )
            );
            if (is_string($raw_sitewide)) {
                $db_sitewide_plugins = maybe_unserialize($raw_sitewide);
                if (! is_array($db_sitewide_plugins)) {
                    $db_sitewide_plugins = [];
                }
            }
        }

        $installed_plugins = [];
        $all_plugins = apply_filters('all_plugins', get_plugins());
        if (is_array($all_plugins)) {
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                if (! is_string($plugin_file) || $plugin_file === '' || ! is_array($plugin_data)) {
                    continue;
                }
                $pname = isset($plugin_data['Name']) && is_string($plugin_data['Name']) && $plugin_data['Name'] !== ''
                    ? $plugin_data['Name']
                    : $plugin_file;
                $pver = isset($plugin_data['Version']) && is_string($plugin_data['Version'])
                    ? $plugin_data['Version']
                    : '';
                $puri = isset($plugin_data['PluginURI']) && is_string($plugin_data['PluginURI'])
                    ? trim($plugin_data['PluginURI'])
                    : '';
                $pauthor = isset($plugin_data['Author']) && is_string($plugin_data['Author'])
                    ? trim($plugin_data['Author'])
                    : '';
                $pauthor_uri = isset($plugin_data['AuthorURI']) && is_string($plugin_data['AuthorURI'])
                    ? trim($plugin_data['AuthorURI'])
                    : '';
                // Bypass is_plugin_active() to avoid stale object-cache results;
                // check directly against the database-sourced arrays.
                $is_active = in_array($plugin_file, $db_active_plugins, true) || isset($db_sitewide_plugins[$plugin_file]);
                $installed_plugins[] = [
                    'file' => $plugin_file,
                    'name' => $pname,
                    'version' => $pver,
                    'active' => $is_active,
                    'plugin_uri' => $puri,
                    'author' => $pauthor,
                    'author_uri' => $pauthor_uri,
                ];
            }
        }

        return new WP_REST_Response(
            [
                'plugins' => $plugins_pending,
                'themes' => $themes_pending,
                'core' => $core_pending,
                'plugin_items' => $plugin_items,
                'theme_items' => $theme_items,
                'core_item' => $core_item,
                'wp_version' => self::rest_scalar_string(get_bloginfo('version')),
                'php_version' => self::rest_scalar_string(PHP_VERSION),
                'active_theme_name' => $active_theme_name,
                'companion_version' => self::rest_scalar_string(S35_WP_HUB_VERSION),
                'installed_plugins' => $installed_plugins,
                'wpvivid_backup' => S35_Wp_Hub_Wpvivid::summary_payload(),
            ],
            200
        );
    }

    public static function run_updates(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! self::ip_allowed()) {
            return new WP_Error('forbidden_ip', __('Request not allowed from this IP.', 's35-wp-hub'), ['status' => 403]);
        }

        $scope = (string) $request->get_param('scope');
        if ($scope === '') {
            $scope = 'all';
        }

        self::load_update_dependencies();
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if (! function_exists('wp_version_check')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_version_check([], true);
        wp_update_plugins();
        wp_update_themes();

        $log = [];

        if ($scope === 'all' || $scope === 'plugins') {
            if ($scope === 'plugins' && ! current_user_can('update_plugins')) {
                return new WP_Error('cannot_update_plugins', __('You cannot update plugins.', 's35-wp-hub'), ['status' => 403]);
            }
            if (current_user_can('update_plugins')) {
                $log[] = self::upgrade_plugins();
            } elseif ($scope === 'all') {
                $log[] = __('Skipped plugins (insufficient capability).', 's35-wp-hub');
            }
        }

        if ($scope === 'all' || $scope === 'themes') {
            if ($scope === 'themes' && ! current_user_can('update_themes')) {
                return new WP_Error('cannot_update_themes', __('You cannot update themes.', 's35-wp-hub'), ['status' => 403]);
            }
            if (current_user_can('update_themes')) {
                $log[] = self::upgrade_themes();
            } elseif ($scope === 'all') {
                $log[] = __('Skipped themes (insufficient capability).', 's35-wp-hub');
            }
        }

        if ($scope === 'all' || $scope === 'core') {
            if ($scope === 'core' && ! current_user_can('update_core')) {
                return new WP_Error('cannot_update_core', __('You cannot update WordPress core.', 's35-wp-hub'), ['status' => 403]);
            }
            if (current_user_can('update_core')) {
                $log[] = self::upgrade_core();
            } elseif ($scope === 'all') {
                $log[] = __('Skipped core (insufficient capability).', 's35-wp-hub');
            }
        }

        $message = implode(' ', array_filter($log));

        return new WP_REST_Response(
            [
                'ok' => true,
                'message' => $message !== '' ? $message : __('No updates applied.', 's35-wp-hub'),
            ],
            200
        );
    }

    private static function load_update_dependencies(): void
    {
        if (! function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
    }

    private static function upgrade_plugins(): string
    {
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        if (! is_object($updates) || empty($updates->response) || ! is_array($updates->response)) {
            return __('No plugin updates pending.', 's35-wp-hub');
        }

        /** @var list<string> $queue Snapshot before any upgrade — transient changes after each run. */
        $queue = array_values(array_filter(array_keys($updates->response), 'is_string'));
        $done = 0;
        $updated_names = [];
        $failed = [];
        $failed_no_package = [];

        foreach ($queue as $plugin_file) {
            wp_update_plugins();
            $updates = get_site_transient('update_plugins');
            if (! is_object($updates) || empty($updates->response[$plugin_file])) {
                continue;
            }

            $row_data = $updates->response[$plugin_file];
            $package_url = self::plugin_update_package_url(is_object($row_data) ? $row_data : null);
            if ($package_url === '') {
                $failed_no_package[] = $plugin_file;
                continue;
            }

            // Capture plugin name before upgrading.
            $plugin_name = $plugin_file;
            if (isset($row_data->name) && is_string($row_data->name) && $row_data->name !== '') {
                $plugin_name = $row_data->name;
            } elseif (function_exists('get_plugin_data')) {
                $path = WP_PLUGIN_DIR . '/' . $plugin_file;
                if (file_exists($path)) {
                    $pdata = get_plugin_data($path, false, false);
                    if (! empty($pdata['Name'])) {
                        $plugin_name = $pdata['Name'];
                    }
                }
            }

            // A new upgrader + skin per plugin; reusing one instance often breaks after the first upgrade.
            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            ob_start();
            $result = $upgrader->upgrade($plugin_file);
            ob_end_clean();

            if ($result) {
                ++$done;
                $updated_names[] = $plugin_name;
            } else {
                $failed[] = $plugin_file;
            }
        }

        $parts = [];
        if ($done > 0) {
            $names_str = implode(', ', $updated_names);
            $parts[] = sprintf(
                __('%d plugin(s): %s', 's35-wp-hub'),
                $done,
                $names_str
            );
        } else {
            $parts[] = __('0 plugin(s)', 's35-wp-hub');
        }
        if ($failed_no_package !== []) {
            $parts[] = sprintf(
                __('Skipped %s: WordPress reported an update but no download URL yet (typical for licensed or commercial plugins).', 's35-wp-hub'),
                implode(', ', $failed_no_package)
            );
        }
        if ($failed !== []) {
            $parts[] = sprintf(
                __('Could not update: %s', 's35-wp-hub'),
                implode(', ', $failed)
            );
        }

        return implode(' ', $parts);
    }

    private static function plugin_update_package_url(?object $data): string
    {
        if ($data === null) {
            return '';
        }
        if (isset($data->package) && is_string($data->package) && $data->package !== '') {
            return $data->package;
        }
        if (isset($data->download_link) && is_string($data->download_link) && $data->download_link !== '') {
            return $data->download_link;
        }

        return '';
    }

    private static function upgrade_themes(): string
    {
        wp_update_themes();
        $updates = get_site_transient('update_themes');
        if (! is_object($updates) || empty($updates->response) || ! is_array($updates->response)) {
            return __('No theme updates pending.', 's35-wp-hub');
        }

        $queue = array_values(array_filter(array_keys($updates->response), 'is_string'));
        $done = 0;
        $updated_names = [];
        $failed = [];

        foreach ($queue as $stylesheet) {
            wp_update_themes();
            $updates = get_site_transient('update_themes');
            if (! is_object($updates) || empty($updates->response[$stylesheet])) {
                continue;
            }

            $row_data = $updates->response[$stylesheet];
            $theme_name = $stylesheet;
            if (is_object($row_data) && isset($row_data->display_name) && is_string($row_data->display_name) && $row_data->display_name !== '') {
                $theme_name = $row_data->display_name;
            } else {
                $theme = wp_get_theme($stylesheet);
                if ($theme->exists() && $theme->get('Name') !== '') {
                    $theme_name = $theme->get('Name');
                }
            }

            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Theme_Upgrader($skin);
            ob_start();
            $result = $upgrader->upgrade($stylesheet);
            ob_end_clean();

            if ($result) {
                ++$done;
                $updated_names[] = $theme_name;
            } else {
                $failed[] = $stylesheet;
            }
        }

        $parts = [];
        if ($done > 0) {
            $names_str = implode(', ', $updated_names);
            $parts[] = sprintf(
                __('%d theme(s): %s', 's35-wp-hub'),
                $done,
                $names_str
            );
        } else {
            $parts[] = __('0 theme(s)', 's35-wp-hub');
        }
        if ($failed !== []) {
            $parts[] = sprintf(
                __('Could not update: %s', 's35-wp-hub'),
                implode(', ', $failed)
            );
        }

        return implode(' ', $parts);
    }

    private static function upgrade_core(): string
    {
        $core_updates = get_core_updates();
        if (! is_array($core_updates) || ! isset($core_updates[0]) || ! is_object($core_updates[0])) {
            return __('No core update pending.', 's35-wp-hub');
        }
        $update = $core_updates[0];
        if (! isset($update->response) || $update->response !== 'upgrade') {
            return __('No core update pending.', 's35-wp-hub');
        }

        $target_version = isset($update->current) ? (string) $update->current : '';

        require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        ob_start();
        $result = $upgrader->upgrade($update);
        ob_end_clean();

        if ($result && $target_version !== '') {
            return sprintf(__('WordPress core: %s', 's35-wp-hub'), $target_version);
        }

        return $result
            ? __('WordPress core updated.', 's35-wp-hub')
            : __('WordPress core update did not complete.', 's35-wp-hub');
    }
}
