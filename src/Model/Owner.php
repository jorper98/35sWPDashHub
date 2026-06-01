<?php

declare(strict_types=1);

namespace S35WpHub\Model;

final class Owner
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $renewalDate,
        public readonly string $ownerEmail,
        public readonly string $createdAt,
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) ($row['first_name'] ?? ''),
            (string) ($row['last_name'] ?? ''),
            (string) ($row['renewal_date'] ?? ''),
            (string) ($row['owner_email'] ?? ''),
            (string) ($row['created_at'] ?? ''),
        );
    }
}
