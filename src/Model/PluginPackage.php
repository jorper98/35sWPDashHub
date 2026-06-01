<?php

declare(strict_types=1);

namespace S35WpHub\Model;

final class PluginPackage
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalFilename,
        public readonly string $diskName,
        public readonly ?string $slugHint,
        public readonly string $createdAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['original_filename'] ?? ''),
            (string) ($row['disk_name'] ?? ''),
            isset($row['slug_hint']) && $row['slug_hint'] !== '' ? (string) $row['slug_hint'] : null,
            (string) ($row['created_at'] ?? '')
        );
    }
}
