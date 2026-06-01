<?php

declare(strict_types=1);

namespace S35WpHub;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException(
                'PHP PDO SQLite is not enabled. Enable extension=pdo_sqlite (and extension=sqlite3) in php.ini, then restart your web server or PHP.'
            );
        }
        $dir = dirname($sqlitePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $dsn = 'sqlite:' . $sqlitePath;
        $this->pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrateIfNeeded(): void
    {
        $path = dirname(__DIR__) . '/database/schema.sql';
        if (! is_file($path)) {
            throw new \RuntimeException('database/schema.sql not found.');
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException('Could not read schema.');
        }
        $this->pdo->exec($sql);
        $this->ensureSitesPendingUpdatesColumn();
        $this->ensureOwnersTable();
        $this->ensureSiteOwnerIdColumn();
        $this->ensureSiteSnapshotJsonColumn();
        $this->ensureSiteLastConnectionTestColumns();
        $this->ensureSiteActiveInactiveColumns();
        $this->ensurePluginPackagesTable();
        $this->ensureDashboardUsersSeeded();
        $this->ensureUpdateSnapshotsTable();
    }

    private function ensureUpdateSnapshotsTable(): void
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='snapshot_batches'");
        if ($stmt !== false && $stmt->fetchColumn() !== false) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE snapshot_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );

        $hasOld = false;
        $oldStmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='update_snapshots'");
        if ($oldStmt !== false && $oldStmt->fetchColumn() !== false) {
            $hasOld = true;
        }

        if ($hasOld) {
            $this->pdo->exec('DROP TABLE IF EXISTS update_snapshots');
        }

        $this->pdo->exec(
            'CREATE TABLE site_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id INTEGER NOT NULL REFERENCES snapshot_batches(id) ON DELETE CASCADE,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                snapshot_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $this->pdo->exec('CREATE INDEX idx_site_snapshots_batch ON site_snapshots(batch_id)');
        $this->pdo->exec('CREATE INDEX idx_site_snapshots_site ON site_snapshots(site_id)');
    }

    private function ensurePluginPackagesTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS plugin_packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                original_filename TEXT NOT NULL,
                disk_name TEXT NOT NULL,
                slug_hint TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
    }

    private function ensureOwnersTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS owners (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                renewal_date TEXT NOT NULL,
                owner_email TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
    }

    private function ensureSiteOwnerIdColumn(): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(sites)');
        if ($stmt === false) {
            return;
        }
        $cols = $stmt->fetchAll();
        $names = array_map(static fn (array $r) => (string) ($r['name'] ?? ''), $cols);
        if (in_array('owner_id', $names, true)) {
            return;
        }
        $this->pdo->exec('ALTER TABLE sites ADD COLUMN owner_id INTEGER NULL');
    }

    private function ensureSiteSnapshotJsonColumn(): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(sites)');
        if ($stmt === false) {
            return;
        }
        $cols = $stmt->fetchAll();
        $names = array_map(static fn (array $r) => (string) ($r['name'] ?? ''), $cols);
        if (in_array('site_snapshot_json', $names, true)) {
            return;
        }
        $this->pdo->exec('ALTER TABLE sites ADD COLUMN site_snapshot_json TEXT NULL');
    }

    private function ensureDashboardUsersSeeded(): void
    {
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM dashboard_users')->fetchColumn();
        if ($n > 0) {
            return;
        }
        foreach (Repository\DashboardUserRepository::fromConfig() as $row) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO dashboard_users (username, password_hash) VALUES (?, ?)'
            );
            $stmt->execute([$row['username'], $row['password_hash']]);
        }
    }

    private function ensureSitesPendingUpdatesColumn(): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(sites)');
        if ($stmt === false) {
            return;
        }
        $cols = $stmt->fetchAll();
        $names = array_map(static fn (array $r) => (string) ($r['name'] ?? ''), $cols);
        if (in_array('pending_updates_json', $names, true)) {
            return;
        }
        $this->pdo->exec('ALTER TABLE sites ADD COLUMN pending_updates_json TEXT NULL');
    }

    private function ensureSiteLastConnectionTestColumns(): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(sites)');
        if ($stmt === false) {
            return;
        }
        $cols = $stmt->fetchAll();
        $names = array_map(static fn (array $r) => (string) ($r['name'] ?? ''), $cols);
        if (! in_array('last_connection_test_ok', $names, true)) {
            $this->pdo->exec('ALTER TABLE sites ADD COLUMN last_connection_test_ok INTEGER NULL');
        }
        if (! in_array('last_connection_test_at', $names, true)) {
            $this->pdo->exec('ALTER TABLE sites ADD COLUMN last_connection_test_at TEXT NULL');
        }
    }

    private function ensureSiteActiveInactiveColumns(): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(sites)');
        if ($stmt === false) {
            return;
        }
        $cols = $stmt->fetchAll();
        $names = array_map(static fn (array $r) => (string) ($r['name'] ?? ''), $cols);
        foreach (['active_plugins', 'inactive_plugins', 'active_themes', 'inactive_themes'] as $col) {
            if (! in_array($col, $names, true)) {
                $this->pdo->exec('ALTER TABLE sites ADD COLUMN ' . $col . ' INTEGER NOT NULL DEFAULT 0');
            }
        }
    }
}
