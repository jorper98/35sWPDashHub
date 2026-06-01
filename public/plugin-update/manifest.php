<?php

declare(strict_types=1);

/**
 * Dynamic update manifest: version is read from plugin/s35-wp-hub/s35-wp-hub.php; package URL uses config base_url.
 * Point S35_WP_HUB_UPDATE_MANIFEST_URL at this file over HTTPS (e.g. …/public/plugin-update/manifest.php).
 */

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
$configPath = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';

if (! is_file($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'Dashboard config missing.'], JSON_UNESCAPED_UNICODE);

    exit;
}

/** @var array<string, mixed> $config */
$config = require $configPath;
$base = rtrim((string) ($config['base_url'] ?? ''), '/');
if ($base === '') {
    http_response_code(503);
    echo json_encode(['error' => 'base_url is not set in config.'], JSON_UNESCAPED_UNICODE);

    exit;
}

$mainFile = $root . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 's35-wp-hub' . DIRECTORY_SEPARATOR . 's35-wp-hub.php';
if (! is_file($mainFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Companion plugin source folder missing.'], JSON_UNESCAPED_UNICODE);

    exit;
}

$mainContents = file_get_contents($mainFile);
if ($mainContents === false || preg_match("/define\s*\(\s*'S35_WP_HUB_VERSION'\s*,\s*'([^']+)'/", $mainContents, $m) !== 1) {
    http_response_code(503);
    echo json_encode(['error' => 'Could not read companion version from source.'], JSON_UNESCAPED_UNICODE);

    exit;
}

$payload = [
    'version' => $m[1],
    'package' => $base . '/plugin-update/s35-wp-hub.zip',
    'url' => 'https://github.com/jorper98/s35WPHub',
    'tested' => '6.7',
    'requires' => '6.0',
    'requires_php' => '7.4',
];

try {
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\JsonException) {
    http_response_code(500);
    echo '{"error":"encode_failed"}';
}
