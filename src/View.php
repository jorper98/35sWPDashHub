<?php

declare(strict_types=1);

namespace S35WpHub;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $name, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $path = __DIR__ . '/views/' . $name . '.php';
        if (! is_file($path)) {
            throw new \RuntimeException('View not found: ' . $name);
        }
        require $path;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
