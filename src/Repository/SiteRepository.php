<?php

declare(strict_types=1);

namespace S35WpHub\Repository;

use PDO;
use S35WpHub\Model\Site;

final class SiteRepository
{
    private const SELECT_BASE = 'SELECT s.id, s.site_url, s.admin_user, s.label, s.owner_id,
            s.last_status, s.pending_plugins, s.pending_themes, s.pending_core, s.active_plugins, s.inactive_plugins, s.active_themes, s.inactive_themes, s.last_error, s.last_sync_at, s.pending_updates_json, s.site_snapshot_json,
            s.last_connection_test_ok, s.last_connection_test_at,
            o.first_name AS owner_first_name, o.last_name AS owner_last_name
         FROM sites s
         LEFT JOIN owners o ON o.id = s.owner_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Site> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            self::SELECT_BASE . ' ORDER BY s.label COLLATE NOCASE, s.site_url'
        );
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $r) => Site::fromRow($r), $rows);
    }

    public function find(int $id): ?Site
    {
        $stmt = $this->pdo->prepare(self::SELECT_BASE . ' WHERE s.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return Site::fromRow($row);
    }

    /**
     * @return list<Site>
     */
    public function allForOwner(int $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_BASE . ' WHERE s.owner_id = ? ORDER BY s.label COLLATE NOCASE, s.site_url'
        );
        $stmt->execute([$ownerId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $r) => Site::fromRow($r), $rows);
    }

    /**
     * @return array{site: Site, app_password_encrypted: string}|null
     */
    public function findWithSecret(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.site_url, s.admin_user, s.app_password_encrypted, s.label, s.owner_id,
                    s.last_status, s.pending_plugins, s.pending_themes, s.pending_core, s.active_plugins, s.inactive_plugins, s.active_themes, s.inactive_themes, s.last_error, s.last_sync_at, s.pending_updates_json, s.site_snapshot_json,
                    s.last_connection_test_ok, s.last_connection_test_at,
                    o.first_name AS owner_first_name, o.last_name AS owner_last_name
             FROM sites s
             LEFT JOIN owners o ON o.id = s.owner_id
             WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $enc = (string) $row['app_password_encrypted'];
        unset($row['app_password_encrypted']);
        $site = Site::fromRow($row);

        return ['site' => $site, 'app_password_encrypted' => $enc];
    }

    public function create(
        string $siteUrl,
        string $adminUser,
        string $appPasswordEncrypted,
        ?string $label,
        ?int $ownerId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sites (site_url, admin_user, app_password_encrypted, label, owner_id) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$siteUrl, $adminUser, $appPasswordEncrypted, $label, $ownerId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $siteUrl,
        string $adminUser,
        ?string $appPasswordEncrypted,
        ?string $label,
        ?int $ownerId
    ): void {
        if ($appPasswordEncrypted !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE sites SET site_url=?, admin_user=?, app_password_encrypted=?, label=?, owner_id=?, updated_at=datetime(\'now\') WHERE id=?'
            );
            $stmt->execute([$siteUrl, $adminUser, $appPasswordEncrypted, $label, $ownerId, $id]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE sites SET site_url=?, admin_user=?, label=?, owner_id=?, updated_at=datetime(\'now\') WHERE id=?'
            );
            $stmt->execute([$siteUrl, $adminUser, $label, $ownerId, $id]);
        }
    }

    public function updateSyncState(
        int $id,
        string $lastStatus,
        int $pendingPlugins,
        int $pendingThemes,
        int $pendingCore,
        int $activePlugins,
        int $inactivePlugins,
        int $activeThemes,
        int $inactiveThemes,
        ?string $lastError,
        ?string $pendingUpdatesJson = null,
        ?string $siteSnapshotJson = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE sites SET last_status=?, pending_plugins=?, pending_themes=?, pending_core=?, active_plugins=?, inactive_plugins=?, active_themes=?, inactive_themes=?, last_error=?, pending_updates_json=?, site_snapshot_json=?, last_sync_at=datetime(\'now\'), updated_at=datetime(\'now\') WHERE id=?'
        );
        $stmt->execute([$lastStatus, $pendingPlugins, $pendingThemes, $pendingCore, $activePlugins, $inactivePlugins, $activeThemes, $inactiveThemes, $lastError, $pendingUpdatesJson, $siteSnapshotJson, $id]);
    }

    public function updateLastConnectionTest(int $id, bool $fullOk): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sites SET last_connection_test_ok=?, last_connection_test_at=datetime(\'now\'), updated_at=datetime(\'now\') WHERE id=?'
        );
        $stmt->execute([$fullOk ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sites WHERE id=?');
        $stmt->execute([$id]);
    }
}
