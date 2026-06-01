<?php

declare(strict_types=1);

namespace S35WpHub\Service;

use S35WpHub\Model\Site;
use S35WpHub\Util\MixedText;
use S35WpHub\Util\WordPressOrgPluginLink;

final class FleetPluginsAggregator
{
    /**
     * @param list<Site> $sites
     * @return list<array{
     *     file: string,
     *     name: string,
     *     version_label: string,
     *     site_count: int,
     *     sites: list<array{site_id: int, label: string, site_url: string, version: string, active: bool}>,
     *     wp_org_url: string,
     *     plugin_uri: string,
     *     author: string,
     *     author_uri: string
     * }>
     */
    public static function aggregate(array $sites): array
    {
        /** @var array<string, array{name: string, sites: list<array{site_id: int, label: string, site_url: string, version: string, active: bool}>, plugin_uri: string, author: string, author_uri: string}> $byFile */
        $byFile = [];

        foreach ($sites as $site) {
            if ($site->id === null) {
                continue;
            }
            $snap = $site->siteSnapshot();
            if (! is_array($snap)) {
                continue;
            }
            $list = $snap['installed_plugins'] ?? null;
            if (! is_array($list) || $list === []) {
                continue;
            }
            $label = $site->label !== null && $site->label !== '' ? $site->label : $site->siteUrl;
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $file = isset($row['file']) ? trim((string) $row['file']) : '';
                if ($file === '') {
                    continue;
                }
                $name = isset($row['name']) ? trim((string) $row['name']) : '';
                if ($name === '') {
                    $name = $file;
                }
                $version = isset($row['version']) ? trim((string) $row['version']) : '';
                $active = ! empty($row['active']);
                if (! isset($byFile[$file])) {
                    $byFile[$file] = [
                        'name' => $name,
                        'sites' => [],
                        'plugin_uri' => '',
                        'author' => '',
                        'author_uri' => '',
                    ];
                } elseif (strcasecmp($byFile[$file]['name'], $name) > 0) {
                    $byFile[$file]['name'] = $name;
                }
                $pluginUri = WordPressOrgPluginLink::sanitizeHttpUrl(MixedText::toPlainString($row['plugin_uri'] ?? null));
                if ($pluginUri !== '' && $byFile[$file]['plugin_uri'] === '') {
                    $byFile[$file]['plugin_uri'] = $pluginUri;
                }
                $author = strip_tags(MixedText::toPlainString($row['author'] ?? null));
                $author = trim(preg_replace('/\s+/u', ' ', $author) ?? $author);
                if ($author !== '' && $byFile[$file]['author'] === '') {
                    $byFile[$file]['author'] = $author;
                }
                $authorUri = WordPressOrgPluginLink::sanitizeHttpUrl(MixedText::toPlainString($row['author_uri'] ?? null));
                if ($authorUri !== '' && $byFile[$file]['author_uri'] === '') {
                    $byFile[$file]['author_uri'] = $authorUri;
                }
                $byFile[$file]['sites'][] = [
                    'site_id' => (int) $site->id,
                    'label' => $label,
                    'site_url' => $site->siteUrl,
                    'version' => $version,
                    'active' => $active,
                ];
            }
        }

        $rows = [];
        foreach ($byFile as $file => $data) {
            $siteList = $data['sites'];
            $versionLabel = '—';
            if ($siteList !== []) {
                $uniqueVers = array_unique(array_map(static fn (array $s): string => $s['version'], $siteList));
                $allSame = count($uniqueVers) <= 1;
                if ($allSame) {
                    $v0 = $siteList[0]['version'];
                    $versionLabel = $v0 !== '' ? $v0 : '—';
                } else {
                    $versionLabel = 'Various';
                }
            }

            $rows[] = [
                'file' => $file,
                'name' => $data['name'],
                'version_label' => $versionLabel,
                'site_count' => count($siteList),
                'sites' => $siteList,
                'wp_org_url' => WordPressOrgPluginLink::directoryUrlFromPluginFile($file),
                'plugin_uri' => $data['plugin_uri'],
                'author' => $data['author'],
                'author_uri' => $data['author_uri'],
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $cmp = strcasecmp($a['name'], $b['name']);

                return $cmp !== 0 ? $cmp : strcasecmp($a['file'], $b['file']);
            }
        );

        return $rows;
    }
}
