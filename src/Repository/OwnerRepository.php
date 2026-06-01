<?php

declare(strict_types=1);

namespace S35WpHub\Repository;

use PDO;
use S35WpHub\Model\Owner;

final class OwnerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Owner> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, first_name, last_name, renewal_date, owner_email, created_at
             FROM owners ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE'
        );
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $r) => Owner::fromRow($r), $rows);
    }

    public function find(int $id): ?Owner
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, renewal_date, owner_email, created_at FROM owners WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return Owner::fromRow($row);
    }

    public function create(string $firstName, string $lastName, string $renewalDate, string $ownerEmail): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO owners (first_name, last_name, renewal_date, owner_email) VALUES (?,?,?,?)'
        );
        $stmt->execute([$firstName, $lastName, $renewalDate, $ownerEmail]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $firstName, string $lastName, string $renewalDate, string $ownerEmail): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE owners SET first_name=?, last_name=?, renewal_date=?, owner_email=? WHERE id=?'
        );
        $stmt->execute([$firstName, $lastName, $renewalDate, $ownerEmail, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('UPDATE sites SET owner_id = NULL WHERE owner_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM owners WHERE id = ?')->execute([$id]);
    }

    public function countSites(int $ownerId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sites WHERE owner_id = ?');
        $stmt->execute([$ownerId]);

        return (int) $stmt->fetchColumn();
    }
}
