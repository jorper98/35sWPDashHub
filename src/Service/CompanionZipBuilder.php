<?php

declare(strict_types=1);

namespace S35WpHub\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Builds public/plugin-update/s35-wp-hub.zip from plugin/s35-wp-hub/ (same rules as scripts/build-plugin-zip.php).
 */
final class CompanionZipBuilder
{
    public const ZIP_BASENAME = 's35-wp-hub.zip';

    /**
     * @return array{ok: bool, message: string, zip_path: string, version: string}
     */
    public static function build(string $projectRoot): array
    {
        $pluginDir = $projectRoot . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 's35-wp-hub';
        $outDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'plugin-update';
        $zipPath = $outDir . DIRECTORY_SEPARATOR . self::ZIP_BASENAME;
        $stateFile = $outDir . DIRECTORY_SEPARATOR . '.last-packaged-version';
        $mainFile = $pluginDir . DIRECTORY_SEPARATOR . 's35-wp-hub.php';

        if (! is_dir($pluginDir)) {
            return ['ok' => false, 'message' => "Plugin folder not found: {$pluginDir}", 'zip_path' => $zipPath, 'version' => ''];
        }
        if (! is_file($mainFile)) {
            return ['ok' => false, 'message' => "Main plugin file not found: {$mainFile}", 'zip_path' => $zipPath, 'version' => ''];
        }

        $ver = self::readVersionFromMainFile($mainFile);
        if ($ver === null) {
            return ['ok' => false, 'message' => 'Could not parse S35_WP_HUB_VERSION in s35-wp-hub.php', 'zip_path' => $zipPath, 'version' => ''];
        }

        if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
            return ['ok' => false, 'message' => "Could not create output directory: {$outDir}", 'zip_path' => $zipPath, 'version' => $ver];
        }

        $lastPackagedVersion = '';
        if (is_file($stateFile)) {
            $lastPackagedVersion = trim((string) file_get_contents($stateFile));
        }

        if (is_file($zipPath)) {
            if ($lastPackagedVersion !== '') {
                $archivedPath = $outDir . DIRECTORY_SEPARATOR . 's35-wp-hub-' . $lastPackagedVersion . '.zip';
            } else {
                $archivedPath = $outDir . DIRECTORY_SEPARATOR . 's35-wp-hub-backup-' . date('Ymd-His') . '.zip';
            }
            if (is_file($archivedPath)) {
                $archivedPath = $outDir . DIRECTORY_SEPARATOR . 's35-wp-hub-' . ($lastPackagedVersion !== '' ? $lastPackagedVersion : 'backup')
                    . '-' . date('YmdHis') . '.zip';
            }
            if (! rename($zipPath, $archivedPath)) {
                return ['ok' => false, 'message' => 'Could not rename existing zip to archive.', 'zip_path' => $zipPath, 'version' => $ver];
            }
        }

        if (! class_exists(ZipArchive::class)) {
            return ['ok' => false, 'message' => 'PHP zip extension (ZipArchive) is required.', 'zip_path' => $zipPath, 'version' => $ver];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => "Could not create zip: {$zipPath}", 'zip_path' => $zipPath, 'version' => $ver];
        }

        $pluginDirReal = realpath($pluginDir);
        if ($pluginDirReal === false) {
            $zip->close();
            @unlink($zipPath);

            return ['ok' => false, 'message' => 'Could not resolve plugin path.', 'zip_path' => $zipPath, 'version' => $ver];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDirReal, \FilesystemIterator::SKIP_DOTS)
        );

        $prefix = 's35-wp-hub/';
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
                continue;
            }
            $full = $fileInfo->getPathname();
            $rel = substr($full, strlen($pluginDirReal) + 1);
            $rel = str_replace('\\', '/', $rel);
            $zip->addFile($full, $prefix . $rel);
        }

        $zip->close();

        if (file_put_contents($stateFile, $ver) === false) {
            return [
                'ok' => true,
                'message' => "Built {$zipPath} (warning: could not write .last-packaged-version)",
                'zip_path' => $zipPath,
                'version' => $ver,
            ];
        }

        return [
            'ok' => true,
            'message' => "Built {$zipPath} (S35_WP_HUB_VERSION {$ver})",
            'zip_path' => $zipPath,
            'version' => $ver,
        ];
    }

    public static function readVersionFromMainFile(string $mainFile): ?string
    {
        $mainContents = file_get_contents($mainFile);
        if ($mainContents === false) {
            return null;
        }
        if (preg_match("/define\s*\(\s*'S35_WP_HUB_VERSION'\s*,\s*'([^']+)'/", $mainContents, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
