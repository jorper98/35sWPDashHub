<?php

declare(strict_types=1);

namespace S35WpHub\Model;

final class Site
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $siteUrl,
        public readonly string $adminUser,
        public readonly ?string $label,
        public readonly ?int $ownerId,
        public readonly ?string $ownerDisplayName,
        public readonly string $lastStatus,
        public readonly int $pendingPlugins,
        public readonly int $pendingThemes,
        public readonly int $pendingCore,
        public readonly int $activePlugins,
        public readonly int $inactivePlugins,
        public readonly int $activeThemes,
        public readonly int $inactiveThemes,
        public readonly ?string $lastError,
        public readonly ?string $lastSyncAt,
        public readonly ?string $pendingUpdatesJson,
        public readonly ?string $siteSnapshotJson,
        public readonly ?bool $lastConnectionTestOk = null,
        public readonly ?string $lastConnectionTestAt = null,
    ) {
    }

    /**
     * @return ?array{wp_version?: string, php_version?: string, active_theme_name?: string, companion_version?: string, integration_status?: array<string, mixed>, installed_plugins?: list<array{file: string, name: string, version: string, active: bool, plugin_uri?: string, author?: string, author_uri?: string}>, wpvivid_backup?: array{active: bool, edition?: ?string, last_success_at?: ?string, last_success_unix?: ?int, source?: ?string}}
     */
    public function siteSnapshot(): ?array
    {
        if ($this->siteSnapshotJson === null || $this->siteSnapshotJson === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->siteSnapshotJson, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return ?array{plugin_items?: list<array<string, mixed>>, theme_items?: list<array<string, mixed>>, core_item?: mixed, source?: string}
     */
    public function pendingUpdatesDetail(): ?array
    {
        if ($this->pendingUpdatesJson === null || $this->pendingUpdatesJson === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->pendingUpdatesJson, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $oid = $row['owner_id'] ?? null;
        $ownerId = $oid !== null && $oid !== '' ? (int) $oid : null;
        $ownerDisplay = null;
        if ($ownerId !== null) {
            $fn = trim((string) ($row['owner_first_name'] ?? ''));
            $ln = trim((string) ($row['owner_last_name'] ?? ''));
            $ownerDisplay = trim($fn . ' ' . $ln);
            if ($ownerDisplay === '') {
                $ownerDisplay = null;
            }
        }

        $connTestOk = null;
        if (array_key_exists('last_connection_test_ok', $row) && $row['last_connection_test_ok'] !== null && $row['last_connection_test_ok'] !== '') {
            $connTestOk = (int) $row['last_connection_test_ok'] === 1;
        }
        $connTestAt = null;
        if (array_key_exists('last_connection_test_at', $row) && $row['last_connection_test_at'] !== null && $row['last_connection_test_at'] !== '') {
            $connTestAt = (string) $row['last_connection_test_at'];
        }

        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) $row['site_url'],
            (string) $row['admin_user'],
            isset($row['label']) ? (string) $row['label'] : null,
            $ownerId,
            $ownerDisplay,
            (string) ($row['last_status'] ?? 'unknown'),
            (int) ($row['pending_plugins'] ?? 0),
            (int) ($row['pending_themes'] ?? 0),
            (int) ($row['pending_core'] ?? 0),
            (int) ($row['active_plugins'] ?? 0),
            (int) ($row['inactive_plugins'] ?? 0),
            (int) ($row['active_themes'] ?? 0),
            (int) ($row['inactive_themes'] ?? 0),
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            isset($row['last_sync_at']) ? (string) $row['last_sync_at'] : null,
            isset($row['pending_updates_json']) ? (string) $row['pending_updates_json'] : null,
            isset($row['site_snapshot_json']) ? (string) $row['site_snapshot_json'] : null,
            $connTestOk,
            $connTestAt,
        );
    }
}
