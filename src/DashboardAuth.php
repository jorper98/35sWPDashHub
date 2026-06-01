<?php

declare(strict_types=1);

namespace S35WpHub;

use PDO;
use S35WpHub\Repository\DashboardUserRepository;

/**
 * Session gate for the dashboard. Users live in SQLite (dashboard_users), seeded once from config.
 * If the table is empty, config dashboard_users / legacy keys are used until the next DB migration seeds.
 */
final class DashboardAuth
{
    private const SESSION_KEY = 'hub_auth';

    /**
     * Block unauthenticated access. Exits with login HTML, JSON, or redirect.
     */
    public static function ensure(PDO $pdo): void
    {
        $users = self::normalizedUsers($pdo);
        if ($users === []) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Dashboard login is not configured. Add dashboard_users to config/config.php (see config.example.php).';
            exit;
        }

        if (isset($_GET['hub_logout'])) {
            unset($_SESSION[self::SESSION_KEY]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            header('Location: index.php', true, 302);
            exit;
        }

        if (self::isAuthenticated()) {
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST' && (string) ($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Not logged in.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($method === 'POST' && (string) ($_POST['hub_login'] ?? '') === '1') {
            self::handleLoginPost($users);

            return;
        }

        self::renderLoginForm(null);
        exit;
    }

    public static function isAuthenticated(): bool
    {
        $auth = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($auth) && isset($auth['username']) && is_string($auth['username']) && $auth['username'] !== '';
    }

    public static function currentUsername(): ?string
    {
        if (! self::isAuthenticated()) {
            return null;
        }
        /** @var array{username: string} $auth */
        $auth = $_SESSION[self::SESSION_KEY];

        return $auth['username'];
    }

    /**
     * @return list<array{username: string, password_hash: string}>
     */
    private static function normalizedUsers(PDO $pdo): array
    {
        $repo = new DashboardUserRepository($pdo);
        if ($repo->count() > 0) {
            return $repo->allCredentials();
        }

        return DashboardUserRepository::fromConfig();
    }

    /**
     * @param list<array{username: string, password_hash: string}> $users
     */
    private static function handleLoginPost(array $users): void
    {
        $csrf = (string) ($_POST['csrf'] ?? '');
        if ($csrf === '' || ! hash_equals((string) ($_SESSION['csrf'] ?? ''), $csrf)) {
            self::renderLoginForm('Invalid session token. Refresh and try again.');

            exit;
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        foreach ($users as $u) {
            if (! hash_equals($u['username'], $username)) {
                continue;
            }
            if (password_verify($password, $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION[self::SESSION_KEY] = ['username' => $u['username']];

                header('Location: index.php', true, 302);
                exit;
            }
        }

        self::renderLoginForm('Invalid username or password.');
        exit;
    }

    private static function renderLoginForm(?string $error): void
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        $csrf = (string) $_SESSION['csrf'];
        $err = $error !== null && $error !== '' ? htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';

        header('Content-Type: text/html; charset=utf-8');
        http_response_code(401);
        $title = htmlspecialchars('Sign in — ' . Version::APP_DISPLAY_NAME . ' v' . Version::VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $title . '</title><link rel="stylesheet" href="assets/style.css"></head><body class="hub-app">';
        echo '<main class="wrap hub-app-main" style="max-width:22rem;padding-top:3rem">';
        echo '<h1 style="font-size:1.35rem;margin:0 0 1rem">' . htmlspecialchars(Version::APP_DISPLAY_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
        echo '<p class="muted" style="margin:0 0 1rem;font-size:0.9rem">Sign in to the dashboard.</p>';
        if ($err !== '') {
            echo '<div class="flash flash-error">' . $err . '</div>';
        }
        echo '<form method="post" action="index.php" class="stack-form" style="margin-top:1rem">';
        echo '<input type="hidden" name="hub_login" value="1">';
        echo '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<label>Username<input type="text" name="username" required autocomplete="username" autofocus></label>';
        echo '<label>Password<input type="password" name="password" required autocomplete="current-password"></label>';
        echo '<button type="submit" class="btn primary">Sign in</button>';
        echo '</form></main>';
        echo '<footer style="text-align: center; padding: 20px; background-color: #2c3e50; color: white; margin-top: auto;">';
        echo '<p>&copy; 2026 by Jorge Pereira and <a href="https://35sites.com" target="_blank" rel="noopener noreferrer" style="color: white;">Powered by 35sites.com</a></p>';
        echo '</footer></body></html>';
    }
}
