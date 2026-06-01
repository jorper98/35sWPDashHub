<?php

declare(strict_types=1);

namespace S35WpHub\Util;

final class SnapshotComparator
{
    /**
     * Compute the diff between a before and after snapshot.
     *
     * @param array{plugins: list<array{file: string, name: string, version: string, active: bool}>, themes: list<array{stylesheet: string, name: string, active: bool}>, core_version: string} $before
     * @param array{plugins: list<array{file: string, name: string, version: string, active: bool}>, themes: list<array{stylesheet: string, name: string, active: bool}>, core_version: string} $after
     * @return array{
     *     plugins_updated: list<array{name: string, file: string, old_version: string, new_version: string}>,
     *     plugins_added: list<array{name: string, file: string, version: string}>,
     *     plugins_removed: list<array{name: string, file: string, version: string}>,
     *     plugins_activated: list<array{name: string, file: string}>,
     *     plugins_deactivated: list<array{name: string, file: string}>,
     *     theme_changed: bool,
     *     old_theme: string,
     *     new_theme: string,
     *     core_updated: bool,
     *     old_core_version: string,
     *     new_core_version: string,
     *     summary_line: string
     * }
     */
    public static function compare(array $before, array $after): array
    {
        $beforePlugins = [];
        foreach ($before['plugins'] as $p) {
            $beforePlugins[$p['file']] = $p;
        }
        $afterPlugins = [];
        foreach ($after['plugins'] as $p) {
            $afterPlugins[$p['file']] = $p;
        }

        $beforeFiles = array_keys($beforePlugins);
        $afterFiles = array_keys($afterPlugins);
        $addedFiles = array_diff($afterFiles, $beforeFiles);
        $removedFiles = array_diff($beforeFiles, $afterFiles);
        $commonFiles = array_intersect($beforeFiles, $afterFiles);

        $pluginsUpdated = [];
        $pluginsActivated = [];
        $pluginsDeactivated = [];

        foreach ($commonFiles as $file) {
            $old = $beforePlugins[$file];
            $new = $afterPlugins[$file];
            if ($old['version'] !== $new['version'] && $new['version'] !== '') {
                $pluginsUpdated[] = [
                    'name' => $new['name'],
                    'file' => $file,
                    'old_version' => $old['version'],
                    'new_version' => $new['version'],
                ];
            }
            if (!$old['active'] && $new['active']) {
                $pluginsActivated[] = ['name' => $new['name'], 'file' => $file];
            } elseif ($old['active'] && !$new['active']) {
                $pluginsDeactivated[] = ['name' => $new['name'], 'file' => $file];
            }
        }

        $pluginsAdded = [];
        foreach ($addedFiles as $file) {
            $pluginsAdded[] = [
                'name' => $afterPlugins[$file]['name'],
                'file' => $file,
                'version' => $afterPlugins[$file]['version'],
            ];
        }

        $pluginsRemoved = [];
        foreach ($removedFiles as $file) {
            $pluginsRemoved[] = [
                'name' => $beforePlugins[$file]['name'],
                'file' => $file,
                'version' => $beforePlugins[$file]['version'],
            ];
        }

        $oldTheme = $before['themes'][0]['name'] ?? '';
        $newTheme = $after['themes'][0]['name'] ?? '';
        $themeChanged = $oldTheme !== $newTheme && $newTheme !== '';

        $oldCore = $before['core_version'] ?? '';
        $newCore = $after['core_version'] ?? '';
        $coreUpdated = $oldCore !== $newCore
            && $newCore !== ''
            && version_compare($newCore, $oldCore, '>');

        $summaryParts = [];
        if (count($pluginsUpdated) > 0) {
            $summaryParts[] = count($pluginsUpdated) . ' plugin(s) updated';
        }
        if (count($pluginsAdded) > 0) {
            $summaryParts[] = count($pluginsAdded) . ' plugin(s) added';
        }
        if (count($pluginsRemoved) > 0) {
            $summaryParts[] = count($pluginsRemoved) . ' plugin(s) removed';
        }
        if (count($pluginsActivated) > 0) {
            $summaryParts[] = count($pluginsActivated) . ' plugin(s) activated';
        }
        if (count($pluginsDeactivated) > 0) {
            $summaryParts[] = count($pluginsDeactivated) . ' plugin(s) deactivated';
        }
        if ($themeChanged) {
            $summaryParts[] = 'theme changed to ' . $newTheme;
        }
        if ($coreUpdated) {
            $summaryParts[] = 'WordPress core updated to ' . $newCore;
        }
        $summaryLine = $summaryParts === [] ? 'No changes detected' : implode(', ', $summaryParts);

        return [
            'plugins_updated' => $pluginsUpdated,
            'plugins_added' => $pluginsAdded,
            'plugins_removed' => $pluginsRemoved,
            'plugins_activated' => $pluginsActivated,
            'plugins_deactivated' => $pluginsDeactivated,
            'theme_changed' => $themeChanged,
            'old_theme' => $oldTheme,
            'new_theme' => $newTheme,
            'core_updated' => $coreUpdated,
            'old_core_version' => $oldCore,
            'new_core_version' => $newCore,
            'summary_line' => $summaryLine,
        ];
    }
}
