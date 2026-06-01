<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reads WPvivid backup metadata from wp_options for the hub dashboard.
 * Detection order: Pro, then free (wordpress.org), then other active slugs that look like WPvivid backup.
 *
 * Uses the same option keys as WPvivid Backup & Migration (free and Pro share this data).
 */
final class S35_Wp_Hub_Wpvivid
{
    /**
     * @return list<string>
     */
    private static function pro_plugin_files(): array
    {
        return [
            'wpvivid-backup-pro/wpvivid-backup-pro.php',
        ];
    }

    /**
     * @return list<string>
     */
    private static function free_plugin_files(): array
    {
        return [
            'wpvivid-backuprestore/wpvivid-backuprestore.php',
        ];
    }

    private static function ensure_plugin_helpers_loaded(): void
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    private static function is_plugin_active_anywhere(string $plugin_file): bool
    {
        self::ensure_plugin_helpers_loaded();

        if (is_plugin_active($plugin_file)) {
            return true;
        }

        return is_multisite() && function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network($plugin_file);
    }

    /**
     * Other installed slugs (e.g. renames) — classify Pro-like paths before treating as free.
     *
     * @return list<string>
     */
    private static function collect_flexible_wpvivid_backup_paths(): array
    {
        self::ensure_plugin_helpers_loaded();

        $seen = [];
        $add = static function (string $p) use (&$seen): void {
            if ($p !== '' && ! isset($seen[$p])) {
                $seen[$p] = true;
            }
        };

        $active = get_option('active_plugins', []);
        if (is_array($active)) {
            foreach ($active as $plugin) {
                if (self::string_looks_like_wpvivid_backup_plugin($plugin)) {
                    $add((string) $plugin);
                }
            }
        }

        if (is_multisite() && function_exists('get_site_option')) {
            $nw = get_site_option('active_sitewide_plugins', []);
            if (is_array($nw)) {
                foreach (array_keys($nw) as $plugin) {
                    if (self::string_looks_like_wpvivid_backup_plugin($plugin)) {
                        $add((string) $plugin);
                    }
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Pro first, then free, then flexible paths (Pro-like basename preferred).
     *
     * @return 'pro'|'free'|null
     */
    private static function resolve_active_edition(): ?string
    {
        foreach (self::pro_plugin_files() as $file) {
            if (self::is_plugin_active_anywhere($file)) {
                return 'pro';
            }
        }

        foreach (self::free_plugin_files() as $file) {
            if (self::is_plugin_active_anywhere($file)) {
                return 'free';
            }
        }

        $flex = self::collect_flexible_wpvivid_backup_paths();
        foreach ($flex as $path) {
            if (self::path_looks_like_pro_edition($path)) {
                return 'pro';
            }
        }

        if ($flex !== []) {
            return 'free';
        }

        return null;
    }

    private static function path_looks_like_pro_edition(string $plugin_path): bool
    {
        $lower = strtolower($plugin_path);

        return strpos($lower, 'backup-pro') !== false;
    }

    /**
     * @param mixed $plugin
     */
    private static function string_looks_like_wpvivid_backup_plugin($plugin): bool
    {
        if (! is_string($plugin) || $plugin === '') {
            return false;
        }
        $lower = strtolower($plugin);

        return strpos($lower, 'wpvivid') !== false && strpos($lower, 'backup') !== false;
    }

    /**
     * @return array{active: bool, edition?: ?string, last_success_at?: ?string, last_success_unix?: ?int, source?: ?string}
     */
    public static function summary_payload(): array
    {
        $edition = self::resolve_active_edition();
        if ($edition === null) {
            return [
                'active' => false,
                'edition' => null,
            ];
        }

        $remoteUnix = self::latest_remote_backup_unix();
        $completedUnix = self::last_completed_task_unix();

        $unix = $remoteUnix;
        $source = null;
        if ($unix !== null) {
            $source = 'remote_backup_list';
        } elseif ($completedUnix !== null) {
            $unix = $completedUnix;
            $source = 'last_task_completed';
        }

        return [
            'active' => true,
            'edition' => $edition,
            'last_success_at' => $unix !== null ? gmdate('c', $unix) : null,
            'last_success_unix' => $unix,
            'source' => $source,
        ];
    }

    private static function latest_remote_backup_unix(): ?int
    {
        $list = get_option('wpvivid_backup_list', []);
        if (! is_array($list) || $list === []) {
            return null;
        }

        $best = null;
        foreach ($list as $backup) {
            if (! is_array($backup)) {
                continue;
            }
            if (empty($backup['remote']) || ! is_array($backup['remote'])) {
                continue;
            }
            if (! isset($backup['create_time'])) {
                continue;
            }
            $ct = (int) $backup['create_time'];
            if ($ct <= 0) {
                continue;
            }
            if ($best === null || $ct > $best) {
                $best = $ct;
            }
        }

        return $best;
    }

    private static function last_completed_task_unix(): ?int
    {
        $msg = get_option('wpvivid_last_msg', []);
        if (! is_array($msg) || empty($msg['id'])) {
            return null;
        }
        $status = $msg['status'] ?? null;
        if (! is_array($status)) {
            return null;
        }
        $str = isset($status['str']) ? (string) $status['str'] : '';
        if ($str !== 'completed') {
            return null;
        }
        if (! isset($status['start_time'])) {
            return null;
        }
        $t = (int) $status['start_time'];

        return $t > 0 ? $t : null;
    }
}
