<?php

declare(strict_types=1);

use S35WpHub\Config;
use S35WpHub\Database;

$configPath = dirname(__DIR__) . '/config/config.php';
Config::load($configPath);

$dbPath = (string) Config::get('db_path');
$db = new Database($dbPath);
$db->migrateIfNeeded();

return $db->pdo();
