<?php

declare(strict_types=1);

/**
 * Builds public/plugin-update/s35-wp-hub.zip from plugin/s35-wp-hub/.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

use S35WpHub\Service\CompanionZipBuilder;

$r = CompanionZipBuilder::build($root);
fwrite($r['ok'] ? STDOUT : STDERR, $r['message'] . PHP_EOL);

exit($r['ok'] ? 0 : 1);
