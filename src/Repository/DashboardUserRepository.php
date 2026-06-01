<?php

declare(strict_types=1);

namespace S35WpHub\Repository;

use PDO;
use S35WpHub\Config;

final class DashboardUserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function count(): int
    {
        $n = $this->pdo->query('SELECT COUNT(*) FROM dashboard_users')->fetchColumn();

        return (int) $n;
    }

    /**
     * @return list<array{username: string, password_hash: string}>
     */
    public function allCredentials(): array
    {
        $stmt = $this->pdo->query('SELECT username, password_hash FROM dashboard_users ORDER BY username');
        if ($stmt === false) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $u = trim((string) ($row['username'] ?? ''));
            $h = (string) ($row['password_hash'] ?? '');
            if ($u !== '' && $h !== '' && str_starts_with($h, '$2')) {
                $out[] = ['username' => $u, 'password_hash' => $h];
            }
        }

        return $out;
    }

    public function getPasswordHash(string $username): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM dashboard_users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $h = (string) ($row['password_hash'] ?? '');

        return $h !== '' && str_starts_with($h, '$2') ? $h : null;
    }

    public function updatePassword(string $username, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE dashboard_users SET password_hash = ? WHERE username = ?');
        $stmt->execute([$passwordHash, $username]);
    }

    /**
     * Users defined in config (same rules as legacy DashboardAuth).
     *
     * @return list<array{username: string, password_hash: string}>
     */
    public static function fromConfig(): array
    {
        $out = [];
        $raw = Config::get('dashboard_users');
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $u = trim((string) ($row['username'] ?? ''));
                $h = (string) ($row['password_hash'] ?? '');
                if ($u !== '' && $h !== '' && str_starts_with($h, '$2')) {
                    $out[] = ['username' => $u, 'password_hash' => $h];
                }
            }
        }

        $legacyHash = Config::get('dashboard_password_hash');
        if (is_string($legacyHash) && $legacyHash !== '' && str_starts_with($legacyHash, '$2')) {
            $legacyUser = trim((string) Config::get('dashboard_username', 'admin'));
            if ($legacyUser === '') {
                $legacyUser = 'admin';
            }
            $out[] = ['username' => $legacyUser, 'password_hash' => $legacyHash];
        }

        return $out;
    }
}
