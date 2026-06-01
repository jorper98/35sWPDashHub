<?php

declare(strict_types=1);

use S35WpHub\Application;
use S35WpHub\Config;
use S35WpHub\DashboardAuth;

session_start();

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.php';
Config::load($configPath);

/** @var PDO $pdo */
$pdo = require dirname(__DIR__) . '/src/bootstrap.php';

DashboardAuth::ensure($pdo);

$app = new Application($pdo);
$app->run();
