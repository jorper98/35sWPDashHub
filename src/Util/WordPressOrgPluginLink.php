<?php

declare(strict_types=1);

namespace S35WpHub\Util;

/**
 * Derive a wordpress.org/plugins/{slug}/ URL from a plugin bootstrap file path (folder heuristic).
 */
final class WordPressOrgPluginLink
{
    /**
     * Directory segment or main file stem, lowercased (heuristic .org slug; may 404 for non-repo plugins).
     */
    public static function slugFromPluginFile(string $file): string
    {
        $file = str_replace('\\', '/', trim($file));
        if ($file === '') {
            return '';
        }
        if (str_contains($file, '/')) {
            $seg = explode('/', $file, 2)[0];

            return strtolower(trim($seg));
        }

        $base = pathinfo($file, PATHINFO_FILENAME);

        return strtolower(trim((string) $base));
    }

    public static function directoryUrlFromPluginFile(string $file): string
    {
        $slug = self::slugFromPluginFile($file);
        if ($slug === '') {
            return '';
        }

        return 'https://wordpress.org/plugins/' . rawurlencode($slug) . '/';
    }

    /**
     * Allow only http(s) URLs; strip #fragment (common on Plugin URI / Author URI tracking).
     */
    public static function sanitizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $noFrag = preg_replace('/#.*$/', '', $url);
        if (! is_string($noFrag) || $noFrag === '') {
            return '';
        }
        $validated = filter_var($noFrag, FILTER_VALIDATE_URL);
        if ($validated === false || ! is_string($validated)) {
            return '';
        }
        if (preg_match('#^https?://#i', $validated) !== 1) {
            return '';
        }

        return $validated;
    }
}
