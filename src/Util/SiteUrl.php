<?php

declare(strict_types=1);

namespace S35WpHub\Util;

final class SiteUrl
{
    public static function normalize(string $input): string
    {
        $t = trim($input);
        if ($t === '') {
            throw new \InvalidArgumentException('Site URL is required.');
        }
        if (! preg_match('#^https?://#i', $t)) {
            $t = 'https://' . $t;
        }
        $parts = parse_url($t);
        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid site URL.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return $scheme . '://' . $host . $port . $path;
    }

    public static function restBase(string $normalizedSiteUrl): string
    {
        return rtrim($normalizedSiteUrl, '/') . '/wp-json';
    }
}
