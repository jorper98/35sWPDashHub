<?php

declare(strict_types=1);

/**
 * Builds dist/s35-wp-hub-dashboard-v{VERSION}.zip with dashboard code for upload / backup.
 *
 * Includes: public/, src/, config/config.example.php, database/, composer.json, composer.lock,
 *           vendor/ (if present), plugin/s35-wp-hub/, README, LICENSE, scripts/, storage/.gitkeep
 *
 * Excludes: .git/, config/config.php, SQLite files, plugin-update zips, pre-built plugin zips, dist/
 *
 * Options:
 *   --no-vendor   Omit vendor/ (smaller zip; run composer install --no-dev on the server)
 */

$root = dirname(__DIR__);
$noVendor = in_array('--no-vendor', $argv, true);

$versionFile = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Version.php';
if (! is_file($versionFile)) {
    fwrite(STDERR, "Missing {$versionFile}\n");
    exit(1);
}
$versionSrc = file_get_contents($versionFile);
if ($versionSrc === false || ! preg_match("/const VERSION = '([^']+)'/", $versionSrc, $vm)) {
    fwrite(STDERR, "Could not parse VERSION in src/Version.php\n");
    exit(1);
}
$version = $vm[1];

$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$zipName = 's35-wp-hub-dashboard-v' . $version . '.zip';
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;
$innerPrefix = 's35-wp-hub-dashboard/';

if (! is_dir($distDir) && ! mkdir($distDir, 0755, true) && ! is_dir($distDir)) {
    fwrite(STDERR, "Could not create directory: {$distDir}\n");
    exit(1);
}

$rootReal = realpath($root);
if ($rootReal === false) {
    fwrite(STDERR, "Could not resolve project root.\n");
    exit(1);
}

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP zip extension (ZipArchive) is required.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create zip: {$zipPath}\n");
    exit(1);
}

$filter = new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $current) use ($rootReal, $noVendor): bool {
        $path = $current->getRealPath();
        if ($path === false) {
            return false;
        }
        $name = $current->getFilename();
        if ($name === '.git' || $name === 'dist' || $name === '.idea' || $name === '.vscode' || $name === 'node_modules') {
            return false;
        }
        if ($noVendor) {
            $rel = substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $rootReal)) + 1);
            if ($rel === 'vendor' || str_starts_with($rel, 'vendor/')) {
                return false;
            }
        }

        return true;
    }
);

$iterator = new RecursiveIteratorIterator($filter);
$added = 0;

foreach ($iterator as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    if (! $fileInfo->isFile()) {
        continue;
    }
    $full = $fileInfo->getPathname();
    $rel = substr($full, strlen($rootReal) + 1);
    $rel = str_replace('\\', '/', $rel);

    if (shouldSkipFile($rel)) {
        continue;
    }

    $zip->addFile($full, $innerPrefix . $rel);
    ++$added;
}

$zip->close();

fwrite(STDOUT, "Built {$zipPath} ({$added} files)" . PHP_EOL);
if ($noVendor) {
    fwrite(STDOUT, 'vendor/ was omitted — run composer install --no-dev after unzip.' . PHP_EOL);
} elseif (! is_dir($root . DIRECTORY_SEPARATOR . 'vendor')) {
    fwrite(STDOUT, 'Note: vendor/ was missing — run composer install before packaging for a self-contained zip.' . PHP_EOL);
}

function shouldSkipFile(string $rel): bool
{
    if ($rel === 'config/config.php') {
        return true;
    }
    if (preg_match('#^storage/[^/]+\.(sqlite|sqlite-journal)$#', $rel) === 1) {
        return true;
    }
    if (preg_match('#^public/plugin-update/.*\.zip$#', $rel) === 1) {
        return true;
    }
    if ($rel === 'public/plugin-update/manifest.json' || $rel === 'public/plugin-update/.last-packaged-version') {
        return true;
    }
    if (preg_match('#^plugin/[^/]+\.zip$#', $rel) === 1) {
        return true;
    }
    if (str_ends_with($rel, '.log')) {
        return true;
    }

    return false;
}
