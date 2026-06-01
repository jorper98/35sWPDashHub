<?php

declare(strict_types=1);

namespace S35WpHub\Repository;

use PDO;

final class LogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(
        int $siteId,
        string $action,
        ?string $itemName = null,
        ?string $oldVersion = null,
        ?string $newVersion = null,
        ?string $message = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO logs (site_id, action, item_name, old_version, new_version, message) VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([$siteId, $action, $itemName, $oldVersion, $newVersion, $message]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentForSite(int $siteId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, action, item_name, old_version, new_version, message, created_at
             FROM logs WHERE site_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Recent log rows with site label/URL for the activity feed.
     *
     * @return list<array<string, mixed>>
     */
    public function recentAll(int $limit = 40): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.site_id, l.action, l.item_name, l.old_version, l.new_version, l.message, l.created_at,
                    s.site_url, s.label
             FROM logs l
             INNER JOIN sites s ON s.id = l.site_id
             ORDER BY l.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Filtered log rows with site label/URL, supporting date range and search.
     *
     * @param string $dateFrom Start date (YYYY-MM-DD), empty for no filter
     * @param string $dateTo End date (YYYY-MM-DD), empty for no filter
     * @param string $searchText Search text to match against site name, URL, or message
     * @param string $actionFilter Action filter: empty for all, or specific action prefix
     * @param int $limit Max rows to return
     * @return list<array<string, mixed>>
     */
    public function filteredAll(string $dateFrom = '', string $dateTo = '', string $searchText = '', string $actionFilter = '', int $limit = 100): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== '') {
            $where[] = "date(l.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "date(l.created_at) <= ?";
            $params[] = $dateTo;
        }
        if ($searchText !== '') {
            $where[] = "(LOWER(s.label) LIKE ? OR LOWER(s.site_url) LIKE ? OR LOWER(l.message) LIKE ?)";
            $like = '%' . strtolower($searchText) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($actionFilter !== '') {
            if ($actionFilter === 'update_run') {
                $where[] = "l.action IN ('update_run', 'update_run_failed')";
            } elseif ($actionFilter === 'plugin_deploy') {
                $where[] = "l.action IN ('plugin_deploy', 'plugin_deploy_failed')";
            } elseif ($actionFilter === 'plugin_activate') {
                $where[] = "l.action IN ('plugin_activate', 'plugin_activate_failed')";
            } elseif ($actionFilter === 'plugin_deactivate') {
                $where[] = "l.action IN ('plugin_deactivate', 'plugin_deactivate_failed')";
            } elseif ($actionFilter === 'plugin_delete') {
                $where[] = "l.action IN ('plugin_delete', 'plugin_delete_failed')";
            } else {
                $where[] = "l.action = ?";
                $params[] = $actionFilter;
            }
        }

        $sql = 'SELECT l.id, l.site_id, l.action, l.item_name, l.old_version, l.new_version, l.message, l.created_at,
                       s.site_url, s.label
                FROM logs l
                INNER JOIN sites s ON s.id = l.site_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY l.id DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $param) {
            if ($i === count($params) - 1) {
                $stmt->bindValue($i + 1, $param, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($i + 1, $param);
            }
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Update runs in the last N days (successful and failed).
     *
     * @return list<array<string, mixed>>
     */
    public function updateRunsForSiteSinceDays(int $siteId, int $days): array
    {
        $days = max(1, $days);
        $mod = sprintf('-%d days', $days);
        $stmt = $this->pdo->prepare(
            "SELECT id, action, item_name, old_version, new_version, message, created_at
             FROM logs
             WHERE site_id = ?
               AND action IN ('update_run', 'update_run_failed')
               AND date(created_at) >= date('now', ?)
             ORDER BY id DESC"
        );
        $stmt->execute([$siteId, $mod]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{total: int, updates: int, backups: int}
     */
    public function eventCountsForSiteSinceDays(int $siteId, int $days): array
    {
        $days = max(1, $days);
        $mod = sprintf('-%d days', $days);
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN action IN ('update_run', 'update_run_failed') THEN 1 ELSE 0 END) AS updates,
                SUM(CASE WHEN action = 'backup_created' THEN 1 ELSE 0 END) AS backups
             FROM logs
             WHERE site_id = ?
               AND date(created_at) >= date('now', ?)"
        );
        $stmt->execute([$siteId, $mod]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['total' => 0, 'updates' => 0, 'backups' => 0];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'updates' => (int) ($row['updates'] ?? 0),
            'backups' => (int) ($row['backups'] ?? 0),
        ];
    }
}
