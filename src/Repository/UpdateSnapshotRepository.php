<?php

declare(strict_types=1);

namespace S35WpHub\Repository;

use PDO;
use S35WpHub\Util\SnapshotBuilder;

final class UpdateSnapshotRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Capture a fleet-wide snapshot: one row per online site with their current plugin/theme state.
     *
     * @return int The batch_id of the newly created snapshot
     */
    public function captureFleet(
        string $label,
        array $sites,
        callable $getSiteSnapshotJson
    ): int {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO snapshot_batches (label) VALUES (?)'
            );
            $stmt->execute([$label]);
            $batchId = (int) $this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                'INSERT INTO site_snapshots (batch_id, site_id, snapshot_json) VALUES (?,?,?)'
            );

            foreach ($sites as $site) {
                $snapshotJson = $getSiteSnapshotJson($site);
                if ($snapshotJson === null || $snapshotJson === '') {
                    continue;
                }
                $siteId = is_object($site) ? (int) ($site->id ?? 0) : (int) ($site['id'] ?? 0);
                if ($siteId <= 0) {
                    continue;
                }
                $ins->execute([$batchId, $siteId, $snapshotJson]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $batchId;
    }

    /**
     * Returns all snapshot batches, newest first, with site count.
     *
     * @return list<array{id: int, label: string, created_at: string, site_count: int}>
     */
    public function listBatches(): array
    {
        $stmt = $this->pdo->query(
            "SELECT b.id, b.label, b.created_at, COUNT(s.id) AS site_count
             FROM snapshot_batches b
             LEFT JOIN site_snapshots s ON s.batch_id = b.id
             GROUP BY b.id
             ORDER BY b.created_at DESC"
        );
        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll();
    }

    /**
     * Returns all site snapshots for a given batch.
     *
     * @return list<array{site_id: int, snapshot_json: string, site_label: string, site_url: string}>
     */
    public function getBatchSnapshots(int $batchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ss.site_id, ss.snapshot_json, s.label AS site_label, s.site_url
             FROM site_snapshots ss
             INNER JOIN sites s ON s.id = ss.site_id
             WHERE ss.batch_id = ?
             ORDER BY s.label, s.site_url'
        );
        $stmt->execute([$batchId]);
        $rows = $stmt->fetchAll();

        if ($rows === false) {
            return [];
        }

        return $rows;
    }

    /**
     * Build a combined current-state map: for each site, the latest installed_plugins and themes.
     *
     * @return array<int, array{site_id: int, site_label: string, site_url: string, plugins: array<string, array{name: string, version: string, active: bool}>, themes: array<string, array{name: string, active: bool}>}>
     */
    public function currentFleetState(): array
    {
        $sites = $this->pdo->query(
            "SELECT s.id, s.label, s.site_url, s.site_snapshot_json
             FROM sites s
             WHERE s.last_status = 'online'
               AND s.site_snapshot_json IS NOT NULL"
        );
        if ($sites === false) {
            return [];
        }

        $result = [];
        foreach ($sites->fetchAll() as $row) {
            $snapshot = json_decode($row['site_snapshot_json'], true);
            if (!is_array($snapshot)) {
                continue;
            }

            $label = ($row['label'] !== null && $row['label'] !== '') ? $row['label'] : $row['site_url'];
            $plugins = [];
            if (!empty($snapshot['installed_plugins']) && is_array($snapshot['installed_plugins'])) {
                foreach ($snapshot['installed_plugins'] as $p) {
                    $plugins[$p['file']] = [
                        'name' => $p['name'],
                        'version' => $p['version'],
                        'active' => !empty($p['active']),
                    ];
                }
            }

            $themes = [];
            if (!empty($snapshot['active_theme_name']) && is_string($snapshot['active_theme_name'])) {
                $themes[$snapshot['active_theme_name']] = [
                    'name' => $snapshot['active_theme_name'],
                    'active' => true,
                ];
            }

            $result[(int) $row['id']] = [
                'site_id' => (int) $row['id'],
                'site_label' => $label,
                'site_url' => $row['site_url'],
                'plugins' => $plugins,
                'themes' => $themes,
            ];
        }

        return $result;
    }

    /**
     * Count total snapshot batches.
     */
    public function countBatches(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM snapshot_batches");
        return (int) ($stmt !== false ? $stmt->fetchColumn() : 0);
    }

    /**
     * Delete a snapshot batch and all its site snapshots.
     */
    public function deleteBatch(int $batchId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM site_snapshots WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        $stmt = $this->pdo->prepare('DELETE FROM snapshot_batches WHERE id = ?');
        $stmt->execute([$batchId]);

        return $stmt->rowCount() > 0;
    }
}
