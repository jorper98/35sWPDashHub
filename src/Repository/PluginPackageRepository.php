<?php

declare(strict_types=1);

namespace S35WpHub\Repository;

use PDO;
use S35WpHub\Model\PluginPackage;

final class PluginPackageRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $packagesAbsoluteDir
    ) {
    }

    public function ensureStorageDir(): void
    {
        if (! is_dir($this->packagesAbsoluteDir) && ! mkdir($this->packagesAbsoluteDir, 0755, true) && ! is_dir($this->packagesAbsoluteDir)) {
            throw new \RuntimeException('Could not create plugin-packages directory.');
        }
    }

    /**
     * @return list<PluginPackage>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, original_filename, disk_name, slug_hint, created_at FROM plugin_packages ORDER BY id DESC'
        );
        if ($stmt === false) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = PluginPackage::fromRow($row);
        }

        return $out;
    }

    public function find(int $id): ?PluginPackage
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, original_filename, disk_name, slug_hint, created_at FROM plugin_packages WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return PluginPackage::fromRow($row);
    }

    /**
     * @param string $tempZipPath Path to temp uploaded file (will be moved)
     */
    public function createFromTemp(string $originalFilename, ?string $slugHint, string $tempZipPath): int
    {
        $this->ensureStorageDir();
        if (! is_file($tempZipPath)) {
            throw new \InvalidArgumentException('Temp package file missing.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO plugin_packages (original_filename, disk_name, slug_hint) VALUES (?, ?, ?)'
            );
            $stmt->execute([$originalFilename, '_pending', $slugHint]);
            $id = (int) $this->pdo->lastInsertId();
            $diskName = $id . '.zip';
            $upd = $this->pdo->prepare('UPDATE plugin_packages SET disk_name = ? WHERE id = ?');
            $upd->execute([$diskName, $id]);

            $dest = $this->packagesAbsoluteDir . DIRECTORY_SEPARATOR . $diskName;
            if (! rename($tempZipPath, $dest)) {
                throw new \RuntimeException('Could not move uploaded package to storage.');
            }
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $pkg = $this->find($id);
        if ($pkg === null) {
            return;
        }
        $path = $this->packagesAbsoluteDir . DIRECTORY_SEPARATOR . $pkg->diskName;
        if (is_file($path)) {
            @unlink($path);
        }
        $stmt = $this->pdo->prepare('DELETE FROM plugin_packages WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function absolutePath(PluginPackage $pkg): string
    {
        return $this->packagesAbsoluteDir . DIRECTORY_SEPARATOR . $pkg->diskName;
    }
}
