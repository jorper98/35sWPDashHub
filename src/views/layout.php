<?php

declare(strict_types=1);

use S35WpHub\View;
use S35WpHub\Version;

/** @var string $title */
/** @var string $body */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$hubCsrf = (string) $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — <?= View::e(Version::APP_DISPLAY_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="hub-app" data-csrf="<?= View::e($hubCsrf) ?>">
<header class="top">
    <div class="wrap top-inner">
        <a class="brand" href="index.php"><?= View::e(Version::APP_DISPLAY_NAME) ?></a>
        <span class="ver">v<?= View::e(Version::VERSION) ?></span>
        <nav class="nav">
            <a href="index.php">Sites</a>
            <a href="index.php?page=site_new">Add site</a>
            <a href="index.php?page=plugin_packages">Plugin packages</a>
            <a href="index.php?page=owners">Owners</a>
            <a href="index.php?page=settings">Settings</a>
            <a href="index.php?page=account">Account</a>
            <?php
            $hubUser = \S35WpHub\DashboardAuth::currentUsername();
            if ($hubUser !== null) :
                ?>
            <span class="muted small"><?= View::e($hubUser) ?></span>
            <a href="index.php?hub_logout=1">Log out</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="wrap">
    <?php if (is_array($flash)) : ?>
        <div class="flash flash-<?= View::e((string) $flash['type']) ?>"><?= View::e((string) $flash['message']) ?></div>
    <?php endif; ?>
    <?= $body ?>
</main>
<footer style="text-align: center; padding: 20px; background-color: #2c3e50; color: white; margin-top: auto;">
    <p>&copy; 2026 by Jorge Pereira and <a href="https://35sites.com" target="_blank" rel="noopener noreferrer" style="color: white;">Powered by 35sites.com</a></p>
</footer>
</body>
</html>
