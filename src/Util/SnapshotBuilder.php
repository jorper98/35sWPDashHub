<?php

declare(strict_types=1);

namespace S35WpHub\Util;

final class SnapshotBuilder
{
    /**
     * Build a structured snapshot array from the site's site_snapshot_json.
     *
     * @return array{plugins: list<array{file: string, name: string, version: string, active: bool}>, themes: list<array{stylesheet: string, name: string, active: bool, version?: string}>, core_version: string}
     */
    public static function buildFromSnapshot(?string $snapshotJson): array
    {
        $plugins = [];
        $themes = [];
        $coreVersion = '';

        if ($snapshotJson !== null && $snapshotJson !== '') {
            try {
                $data = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    $installedPlugins = $data['installed_plugins'] ?? [];
                    if (is_array($installedPlugins)) {
                        foreach ($installedPlugins as $p) {
                            if (!is_array($p)) {
                                continue;
                            }
                            $file = (string) ($p['file'] ?? '');
                            if ($file === '') {
                                continue;
                            }
                            $plugins[] = [
                                'file' => $file,
                                'name' => (string) ($p['name'] ?? ''),
                                'version' => (string) ($p['version'] ?? ''),
                                'active' => (bool) ($p['active'] ?? false),
                            ];
                        }
                    }
                    if (isset($data['active_theme_name']) && is_string($data['active_theme_name']) && $data['active_theme_name'] !== '') {
                        $themes[] = [
                            'stylesheet' => $data['active_theme_name'],
                            'name' => $data['active_theme_name'],
                            'active' => true,
                        ];
                    }
                    $coreVersion = (string) ($data['wp_version'] ?? '');
                }
            } catch (\JsonException) {
            }
        }

        return [
            'plugins' => $plugins,
            'themes' => $themes,
            'core_version' => $coreVersion,
        ];
    }

    /**
     * Produce a short human-readable summary of a snapshot.
     */
    public static function formatSummary(array $snapshot): string
    {
        $parts = [];
        $pCount = count($snapshot['plugins']);
        $tCount = count($snapshot['themes']);
        if ($pCount > 0) {
            $parts[] = $pCount . ' plugin(s)';
        }
        if ($tCount > 0) {
            $parts[] = $tCount . ' theme(s)';
        }
        if ($snapshot['core_version'] !== '') {
            $parts[] = 'WP ' . $snapshot['core_version'];
        }

        return $parts === [] ? 'No data' : implode(', ', $parts);
    }

    /**
     * Produce a full detail listing of a snapshot.
     */
    public static function formatDetail(array $snapshot): string
    {
        $lines = [];
        foreach ($snapshot['plugins'] as $p) {
            $status = $p['active'] ? 'active' : 'inactive';
            $ver = $p['version'] !== '' ? ' v' . $p['version'] : '';
            $lines[] = '- ' . $p['name'] . $ver . ' (' . $status . ') [' . $p['file'] . ']';
        }
        foreach ($snapshot['themes'] as $t) {
            $status = !empty($t['active']) ? 'active' : 'inactive';
            $ver = !empty($t['version']) ? ' v' . $t['version'] : '';
            $lines[] = '- theme: ' . $t['name'] . $ver . ' (' . $status . ')';
        }

        return implode("\n", $lines);
    }
}
