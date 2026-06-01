<?php

declare(strict_types=1);

namespace S35WpHub\Util;

use ZipArchive;

/**
 * Validates a WordPress plugin zip (single top-level folder, Plugin Name header) for hub uploads.
 */
final class PluginZipInspector
{
    /**
     * @return array{ok: bool, error?: string, slug_hint?: string}
     */
    public static function validate(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            return ['ok' => false, 'error' => 'Upload missing or not saved.'];
        }
        if (! class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'PHP zip extension is required to validate uploads.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Could not open zip archive.'];
        }

        $root = null;
        $foundPhp = false;
        $slugHint = null;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $parts = explode('/', $name, 2);
            if (count($parts) < 2 || $parts[1] === '') {
                $zip->close();

                return ['ok' => false, 'error' => 'Plugin zip must contain a single top-level folder (no loose files at archive root).'];
            }
            $dir = $parts[0];
            if ($root === null) {
                $root = $dir;
            } elseif ($root !== $dir) {
                $zip->close();

                return ['ok' => false, 'error' => 'Plugin zip must contain only one top-level folder.'];
            }
            if (preg_match('#\.php$#i', $parts[1]) === 1) {
                $content = $zip->getFromIndex($i);
                if (is_string($content) && self::hasPluginNameHeader($content)) {
                    $foundPhp = true;
                    $slugHint = $dir;
                }
            }
        }

        $zip->close();

        if ($root === null) {
            return ['ok' => false, 'error' => 'Empty or invalid zip archive.'];
        }
        if (! $foundPhp) {
            return ['ok' => false, 'error' => 'No file with a WordPress plugin header (Plugin Name) was found in the top-level folder.'];
        }

        return ['ok' => true, 'slug_hint' => $slugHint ?? $root];
    }

    private static function hasPluginNameHeader(string $phpSource): bool
    {
        return preg_match('/^\s*\*\s*Plugin\s*Name\s*:/mi', $phpSource) === 1;
    }
}
