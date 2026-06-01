<?php

declare(strict_types=1);

namespace S35WpHub\Service;

use S35WpHub\Config;
use S35WpHub\Model\Site;
use S35WpHub\Repository\SiteRepository;
use S35WpHub\Util\MixedText;
use S35WpHub\Util\SiteUrl;
use S35WpHub\Util\WordPressOrgPluginLink;

final class SyncService
{
    /** @var int Snapshot JSON: avoid encode failure; UTF-8 mojibake substitution. */
    private const SNAPSHOT_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly WordPressHttp $http
    ) {
    }

    /**
     * @return array{
     *     last_status: string,
     *     pending_plugins: int,
     *     pending_themes: int,
     *     pending_core: int,
     *     active_plugins: int,
     *     inactive_plugins: int,
     *     active_themes: int,
     *     inactive_themes: int,
     *     last_error: ?string,
     *     source: 'offline'|'companion'|'fallback',
     *     pending_summary: ?string
     * }
     */
    public function syncSite(Site $site, string $plainAppPassword): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $user = $site->adminUser;
        $pass = self::normalizeAppPassword($plainAppPassword);

        $ping = $this->http->request('GET', $base, $user, $pass, null, true);
        if (! $ping['ok']) {
            $msg = $ping['error'] ?? ('HTTP ' . $ping['status']);
            $err = $msg !== '' ? $msg : 'Unreachable';
            $this->sites->updateSyncState(
                (int) $site->id,
                'offline',
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                $err,
                $site->pendingUpdatesJson,
                $site->siteSnapshotJson
            );

            return [
                'last_status' => 'offline',
                'pending_plugins' => 0,
                'pending_themes' => 0,
                'pending_core' => 0,
                'active_plugins' => 0,
                'inactive_plugins' => 0,
                'active_themes' => 0,
                'inactive_themes' => 0,
                'last_error' => $err,
                'source' => 'offline',
                'pending_summary' => null,
            ];
        }

        $summaryUrl = $base . '/s35-wp-hub/v1/updates/summary';
        $sum = $this->http->request('GET', $summaryUrl, $user, $pass, null, true);

        if ($sum['ok'] && is_array($sum['decoded'])) {
            $d = $sum['decoded'];
            $p = (int) ($d['plugins'] ?? 0);
            $t = (int) ($d['themes'] ?? 0);
            $c = (int) ($d['core'] ?? 0);

            $detailPayload = null;
            $pendingJson = null;
            $hasItems = isset($d['plugin_items']) || isset($d['theme_items']);
            if ($hasItems) {
                $pluginItems = is_array($d['plugin_items'] ?? null) ? $d['plugin_items'] : [];
                $themeItems = is_array($d['theme_items'] ?? null) ? $d['theme_items'] : [];
                $coreItem = $d['core_item'] ?? null;
                if (! is_array($coreItem) && $coreItem !== null) {
                    $coreItem = null;
                }
                if ($pluginItems !== []) {
                    $p = count($pluginItems);
                }
                if ($themeItems !== []) {
                    $t = count($themeItems);
                }
                $detailPayload = [
                    'plugin_items' => $pluginItems,
                    'theme_items' => $themeItems,
                    'core_item' => $coreItem,
                    'source' => 'companion',
                ];
                $pendingJson = json_encode($detailPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
            }

            $snapshotJson = self::snapshotJsonFromSummary($d);
            if ($snapshotJson === null) {
                $snapshotJson = $site->siteSnapshotJson;
            }
            $snapshotJson = self::overlayInstalledPluginsFromSummaryIntoSnapshot($d, $snapshotJson);
            $snapshotJson = $this->enrichSiteSnapshot($site->siteUrl, $base, $user, $pass, $snapshotJson);
            $snapshotJson = $this->mergeIntegrationStatusIntoSnapshot($base, $user, $pass, $snapshotJson);

            $activePlugins = 0;
            $inactivePlugins = 0;
            $installedPlugins = self::installedPluginsFromSummaryDecoded($d);
            if ($installedPlugins !== null) {
                foreach ($installedPlugins as $pp) {
                    if (!empty($pp['active'])) {
                        ++$activePlugins;
                    } else {
                        ++$inactivePlugins;
                    }
                }
            }
            $activeThemes = 1;
            $inactiveThemes = 0;

            $this->sites->updateSyncState((int) $site->id, 'online', $p, $t, $c, $activePlugins, $inactivePlugins, $activeThemes, $inactiveThemes, null, $pendingJson, $snapshotJson);

            return [
                'last_status' => 'online',
                'pending_plugins' => $p,
                'pending_themes' => $t,
                'pending_core' => $c,
                'active_plugins' => $activePlugins,
                'inactive_plugins' => $inactivePlugins,
                'active_themes' => $activeThemes,
                'inactive_themes' => $inactiveThemes,
                'last_error' => null,
                'source' => 'companion',
                'pending_summary' => self::formatPendingSummary($detailPayload),
            ];
        }

        $fb = $this->fallbackDetailAndCounts($base, $user, $pass);
        $p = $fb['p'];
        $t = $fb['t'];
        $c = $fb['c'];
        $detailPayload = $fb['detail'];
        $pendingJson = $detailPayload !== null
            ? (json_encode($detailPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null)
            : null;

        $hint = null;
        if ($sum['status'] === 404) {
            $hint = 'Companion plugin not installed (or REST route missing). '
                . 'Zip the folder plugin/s35-wp-hub from your s35-wp-hub project, '
                . 'then in WordPress: Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate. '
                . 'Click Sync again for accurate counts and Update.';
        } else {
            $hint = $sum['error'] ?? ('summary HTTP ' . $sum['status']);
        }
        $snapshotJson = $this->enrichSiteSnapshot($site->siteUrl, $base, $user, $pass, $site->siteSnapshotJson);
        $snapshotJson = self::snapshotWithoutIntegrationStatus($snapshotJson);

        $activePlugins = 0;
        $inactivePlugins = 0;
        $activeThemes = 0;
        $inactiveThemes = 0;

        $this->sites->updateSyncState(
            (int) $site->id,
            'online',
            $p,
            $t,
            $c,
            $activePlugins,
            $inactivePlugins,
            $activeThemes,
            $inactiveThemes,
            $hint,
            $pendingJson,
            $snapshotJson
        );

        return [
            'last_status' => 'online',
            'pending_plugins' => $p,
            'pending_themes' => $t,
            'pending_core' => $c,
            'active_plugins' => $activePlugins,
            'inactive_plugins' => $inactivePlugins,
            'active_themes' => $activeThemes,
            'inactive_themes' => $inactiveThemes,
            'last_error' => $hint,
            'source' => 'fallback',
            'pending_summary' => self::formatPendingSummary($detailPayload),
        ];
    }

    /**
     * @param array<string, mixed> $d
     */
    private static function snapshotJsonFromSummary(array $d): ?string
    {
        $payload = [
            'wp_version' => MixedText::toPlainString($d['wp_version'] ?? null),
            'php_version' => MixedText::toPlainString($d['php_version'] ?? null),
            'active_theme_name' => MixedText::toPlainString($d['active_theme_name'] ?? null),
            'companion_version' => MixedText::toPlainString($d['companion_version'] ?? null),
        ];
        self::scrubBogusSnapshotStrings($payload);
        $ipl = self::installedPluginsFromSummaryDecoded($d);
        if ($ipl !== null) {
            $payload['installed_plugins'] = $ipl;
        }
        if (array_key_exists('wpvivid_backup', $d) && is_array($d['wpvivid_backup'])) {
            $payload['wpvivid_backup'] = self::sanitizeWpvividBackupForSnapshot($d['wpvivid_backup']);
        }
        if ($payload['wp_version'] === '' && $payload['php_version'] === '' && $payload['active_theme_name'] === ''
            && $payload['companion_version'] === '' && ! isset($payload['installed_plugins'])
            && ! isset($payload['wpvivid_backup'])) {
            return null;
        }

        return json_encode($payload, self::SNAPSHOT_JSON_FLAGS) ?: null;
    }

    /**
     * Always persist `installed_plugins` from the live summary when present (per-plugin `active`, versions, etc.).
     * Prevents stale activation state when snapshot JSON fell back to the previous DB row or encode failed earlier.
     *
     * @param array<string, mixed> $summaryDecoded Decoded GET …/updates/summary body
     */
    private static function overlayInstalledPluginsFromSummaryIntoSnapshot(array $summaryDecoded, ?string $snapshotJson): ?string
    {
        if (! array_key_exists('installed_plugins', $summaryDecoded)) {
            return $snapshotJson;
        }

        $full = self::snapshotFullArrayFromJson($snapshotJson);
        $list = self::installedPluginsFromSummaryDecoded($summaryDecoded);
        $full['installed_plugins'] = is_array($list) ? $list : [];

        return self::encodeFullSnapshotArray($full) ?? $snapshotJson;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{active: bool, edition: ?string, last_success_at: ?string, last_success_unix: ?int, source: ?string}
     */
    private static function sanitizeWpvividBackupForSnapshot(array $raw): array
    {
        $active = ! empty($raw['active']);
        $edition = null;
        if (isset($raw['edition']) && is_string($raw['edition'])
            && in_array($raw['edition'], ['pro', 'free'], true)) {
            $edition = $raw['edition'];
        }
        $out = [
            'active' => $active,
            'edition' => $active ? $edition : null,
            'last_success_at' => null,
            'last_success_unix' => null,
            'source' => null,
        ];
        if (! $active) {
            return $out;
        }
        if (isset($raw['last_success_at']) && is_string($raw['last_success_at']) && $raw['last_success_at'] !== '') {
            $out['last_success_at'] = $raw['last_success_at'];
        }
        if (isset($raw['last_success_unix']) && is_numeric($raw['last_success_unix'])) {
            $out['last_success_unix'] = (int) $raw['last_success_unix'];
        }
        if (isset($raw['source']) && is_string($raw['source']) && $raw['source'] !== '') {
            $out['source'] = $raw['source'];
        }

        return $out;
    }

    /**
     * @return list<array{file: string, name: string, version: string, active: bool, plugin_uri?: string, author?: string, author_uri?: string}>|null null if the summary did not include installed_plugins (older companion)
     */
    private static function installedPluginsFromSummaryDecoded(array $d): ?array
    {
        if (! array_key_exists('installed_plugins', $d)) {
            return null;
        }
        $raw = $d['installed_plugins'];
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $file = MixedText::toPlainString($row['file'] ?? null);
            if ($file === '') {
                continue;
            }
            $name = MixedText::toPlainString($row['name'] ?? null);
            if ($name === '') {
                $name = $file;
            }
            $author = strip_tags(MixedText::toPlainString($row['author'] ?? null));
            $author = trim(preg_replace('/\s+/u', ' ', $author) ?? $author);
            $pluginUri = WordPressOrgPluginLink::sanitizeHttpUrl(MixedText::toPlainString($row['plugin_uri'] ?? null));
            $authorUri = WordPressOrgPluginLink::sanitizeHttpUrl(MixedText::toPlainString($row['author_uri'] ?? null));
            $entry = [
                'file' => $file,
                'name' => $name,
                'version' => MixedText::toPlainString($row['version'] ?? null),
                'active' => self::coercePluginActiveFromSummaryRow($row),
            ];
            if ($pluginUri !== '') {
                $entry['plugin_uri'] = $pluginUri;
            }
            if ($author !== '') {
                $entry['author'] = $author;
            }
            if ($authorUri !== '') {
                $entry['author_uri'] = $authorUri;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Normalize `active` from JSON (bool, 0/1, common strings). Avoid treating ambiguous strings as “on”.
     *
     * @param array<string, mixed> $row
     */
    private static function coercePluginActiveFromSummaryRow(array $row): bool
    {
        if (! array_key_exists('active', $row)) {
            return false;
        }
        $v = $row['active'];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int) $v) === 1;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));

            return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'active';
        }

        return false;
    }

    /**
     * Fills missing snapshot fields using core REST (active theme) and optional HTTP hints (generator meta, headers).
     * Preserves extra snapshot keys (e.g. integration_status) across syncs.
     */
    private function enrichSiteSnapshot(
        string $siteUrl,
        string $restBase,
        string $user,
        string $pass,
        ?string $snapshotJson
    ): ?string {
        $full = self::snapshotFullArrayFromJson($snapshotJson);
        $map = self::snapshotMapFromJson($snapshotJson);
        $need = $map['wp_version'] === '' || $map['php_version'] === '' || $map['active_theme_name'] === ''
            || $map['companion_version'] === '';
        if (! $need) {
            self::applyCoreMapToFull($full, $map);
            self::scrubFullCoreStrings($full);

            return self::encodeFullSnapshotArray($full);
        }

        $themesResp = $this->http->request('GET', $restBase . '/wp/v2/themes', $user, $pass, null, true);
        self::applyResponseHeadersToSnapshotMap($map, $themesResp['headers'] ?? []);
        if ($map['active_theme_name'] === '' && $themesResp['ok'] && is_array($themesResp['decoded'])) {
            $name = self::activeThemeNameFromThemesPayload($themesResp['decoded']);
            if ($name !== '') {
                $map['active_theme_name'] = $name;
            }
        }

        if ($map['php_version'] === '') {
            $idxResp = $this->http->request('GET', $restBase, $user, $pass, null, false);
            self::applyResponseHeadersToSnapshotMap($map, $idxResp['headers'] ?? []);
        }

        if ($map['wp_version'] === '' || $map['php_version'] === '') {
            $home = rtrim(SiteUrl::normalize($siteUrl), '/');
            $htmlResp = $this->http->request('GET', $home . '/', $user, $pass, null, false);
            self::applyResponseHeadersToSnapshotMap($map, $htmlResp['headers'] ?? []);
            if ($map['wp_version'] === '' && $htmlResp['ok']) {
                $v = self::extractWpVersionFromHtml((string) $htmlResp['body']);
                if ($v !== '') {
                    $map['wp_version'] = $v;
                }
            }
        }

        if ($map['companion_version'] === '') {
            $map['companion_version'] = $this->fetchCompanionPluginVersionFromRest($restBase, $user, $pass);
        }

        self::scrubBogusSnapshotStrings($map);
        self::applyCoreMapToFull($full, $map);
        self::scrubFullCoreStrings($full);

        return self::encodeFullSnapshotArray($full) ?? $snapshotJson;
    }

    /**
     * Fetches companion integration diagnostics and compares allowlist/manifest host to this hub’s base_url.
     */
    private function mergeIntegrationStatusIntoSnapshot(
        string $restBase,
        string $user,
        string $pass,
        ?string $snapshotJson
    ): ?string {
        $full = self::snapshotFullArrayFromJson($snapshotJson);
        $intUrl = $restBase . '/s35-wp-hub/v1/integration/status';
        $res = $this->http->request('GET', $intUrl, $user, $pass, null, true);

        $hubUrl = rtrim((string) Config::get('base_url', ''), '/');
        $hubHostRaw = $hubUrl !== '' ? parse_url($hubUrl, PHP_URL_HOST) : null;
        $hubHost = is_string($hubHostRaw) && $hubHostRaw !== '' ? strtolower($hubHostRaw) : '';

        if (! $res['ok'] || ! is_array($res['decoded'])) {
            $full['integration_status'] = [
                'api_reachable' => false,
                'api_http_status' => (int) ($res['status'] ?? 0),
                'hub_host' => $hubHost,
                'hub_base_url_set' => $hubUrl !== '',
            ];

            return self::encodeFullSnapshotArray($full) ?? $snapshotJson;
        }

        $wp = $res['decoded'];
        $manifestHost = strtolower((string) ($wp['manifest_host'] ?? ''));
        $hosts = $wp['package_allowlist_hosts'] ?? [];
        $hosts = is_array($hosts) ? array_values(array_filter(array_map('strval', $hosts))) : [];
        $allowEmpty = (bool) ($wp['package_allowlist_empty'] ?? true);

        $manifestMatches = $hubHost !== '' && $manifestHost !== '' && self::hostsEquivalent($hubHost, $manifestHost);
        $fleetOk = $hubHost !== '' && ! $allowEmpty && self::hubHostMatchesAllowlist($hubHost, $hosts);

        $full['integration_status'] = [
            'api_reachable' => true,
            'manifest_configured' => (bool) ($wp['manifest_configured'] ?? false),
            'manifest_host' => $manifestHost,
            'package_allowlist_hosts' => $hosts,
            'package_allowlist_empty' => $allowEmpty,
            'package_allowlist_source' => (string) ($wp['package_allowlist_source'] ?? ''),
            'hub_host' => $hubHost,
            'hub_base_url_set' => $hubUrl !== '',
            'manifest_matches_hub' => $manifestMatches,
            'fleet_deploy_ok' => $fleetOk,
        ];

        return self::encodeFullSnapshotArray($full) ?? $snapshotJson;
    }

    /**
     * @param list<string> $allowedLowerOrMixed
     */
    private static function hubHostMatchesAllowlist(string $hubHostLower, array $allowedLowerOrMixed): bool
    {
        if ($hubHostLower === '') {
            return false;
        }
        foreach ($allowedLowerOrMixed as $h) {
            $h = strtolower((string) $h);
            if ($h === '') {
                continue;
            }
            if (self::hostsEquivalent($hubHostLower, $h)) {
                return true;
            }
        }

        return false;
    }

    private static function hostsEquivalent(string $aLower, string $bLower): bool
    {
        $a = strtolower($aLower);
        $b = strtolower($bLower);
        if ($a === $b) {
            return true;
        }
        $strip = static function (string $h): string {
            if (strlen($h) > 4 && substr($h, 0, 4) === 'www.') {
                return substr($h, 4);
            }

            return $h;
        };

        return $strip($a) === $strip($b);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotFullArrayFromJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($d) ? $d : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @param array{wp_version: string, php_version: string, active_theme_name: string, companion_version: string} $map
     * @param array<string, mixed> $full
     */
    private static function applyCoreMapToFull(array &$full, array $map): void
    {
        $full['wp_version'] = $map['wp_version'];
        $full['php_version'] = $map['php_version'];
        $full['active_theme_name'] = $map['active_theme_name'];
        $full['companion_version'] = $map['companion_version'];
    }

    /**
     * @param array<string, mixed> $full
     */
    private static function scrubFullCoreStrings(array &$full): void
    {
        foreach (['wp_version', 'php_version', 'active_theme_name', 'companion_version'] as $k) {
            $v = isset($full[$k]) ? (string) $full[$k] : '';
            if ($v === '' || strcasecmp($v, 'Array') === 0) {
                $full[$k] = '';
            }
        }
    }

    /**
     * @param array<string, mixed> $full
     */
    private static function encodeFullSnapshotArray(array $full): ?string
    {
        $wv = isset($full['wp_version']) ? (string) $full['wp_version'] : '';
        $pv = isset($full['php_version']) ? (string) $full['php_version'] : '';
        $an = isset($full['active_theme_name']) ? (string) $full['active_theme_name'] : '';
        $cv = isset($full['companion_version']) ? (string) $full['companion_version'] : '';
        $hasCore = $wv !== '' || $pv !== '' || $an !== '' || $cv !== '';
        $hasIntegration = isset($full['integration_status']) && is_array($full['integration_status']);
        $hasInstalledPlugins = isset($full['installed_plugins']) && is_array($full['installed_plugins']);
        $hasWpvivid = isset($full['wpvivid_backup']) && is_array($full['wpvivid_backup']);
        if (! $hasCore && ! $hasIntegration && ! $hasInstalledPlugins && ! $hasWpvivid) {
            return null;
        }

        return json_encode($full, self::SNAPSHOT_JSON_FLAGS) ?: null;
    }

    /**
     * Removes companion-only snapshot keys when the hub falls back to REST (no working summary route).
     */
    private static function snapshotWithoutIntegrationStatus(?string $snapshotJson): ?string
    {
        $full = self::snapshotFullArrayFromJson($snapshotJson);
        unset($full['integration_status'], $full['installed_plugins'], $full['wpvivid_backup']);

        return self::encodeFullSnapshotArray($full);
    }

    private function fetchCompanionPluginVersionFromRest(string $restBase, string $user, string $pass): string
    {
        $pl = $this->http->request('GET', $restBase . '/wp/v2/plugins', $user, $pass, null, true);
        if (! $pl['ok'] || ! is_array($pl['decoded'])) {
            return '';
        }
        foreach ($pl['decoded'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $pid = (string) ($item['plugin'] ?? '');
            if ($pid === 's35-wp-hub/s35-wp-hub.php') {
                return MixedText::toPlainString($item['version'] ?? null);
            }
        }

        return '';
    }

    /**
     * @return array{wp_version: string, php_version: string, active_theme_name: string, companion_version: string}
     */
    private static function snapshotMapFromJson(?string $json): array
    {
        $map = [
            'wp_version' => '',
            'php_version' => '',
            'active_theme_name' => '',
            'companion_version' => '',
        ];
        if ($json === null || $json === '') {
            return $map;
        }
        try {
            $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($d)) {
                return $map;
            }
            foreach (array_keys($map) as $k) {
                if (! array_key_exists($k, $d)) {
                    continue;
                }
                $t = MixedText::toPlainString($d[$k]);
                if ($t !== '') {
                    $map[$k] = $t;
                }
            }
        } catch (\JsonException) {
        }
        self::scrubBogusSnapshotStrings($map);

        return $map;
    }

    /**
     * @param array{wp_version: string, php_version: string, active_theme_name: string, companion_version: string} $map
     * @param array<string, string> $headers
     */
    private static function applyResponseHeadersToSnapshotMap(array &$map, array $headers): void
    {
        if ($map['php_version'] === '' && isset($headers['x-powered-by'])) {
            if (preg_match('/PHP\s*\/\s*([\d.]+)/i', $headers['x-powered-by'], $m)) {
                $map['php_version'] = $m[1];
            }
        }
        foreach (['x-php-version', 'php-version', 'x-php-version-id'] as $hk) {
            if ($map['php_version'] === '' && isset($headers[$hk])) {
                $v = trim($headers[$hk]);
                if ($v !== '' && preg_match('/^[\d.]+$/', $v) === 1) {
                    $map['php_version'] = $v;
                    break;
                }
                if ($v !== '' && preg_match('/([\d.]+)/', $v, $m) === 1) {
                    $map['php_version'] = $m[1];
                    break;
                }
            }
        }
        if ($map['php_version'] === '' && isset($headers['server'])) {
            if (preg_match('/PHP\s*\/\s*([\d.]+)/i', $headers['server'], $m)) {
                $map['php_version'] = $m[1];
            }
        }
        if ($map['wp_version'] === '' && isset($headers['x-wp-version'])) {
            $v = trim($headers['x-wp-version']);
            if ($v !== '') {
                $map['wp_version'] = $v;
            }
        }
    }

    /**
     * @param array{wp_version: string, php_version: string, active_theme_name: string, companion_version?: string} $map
     */
    private static function scrubBogusSnapshotStrings(array &$map): void
    {
        foreach (['wp_version', 'php_version', 'active_theme_name', 'companion_version'] as $k) {
            $v = $map[$k] ?? '';
            if ($v === '' || strcasecmp($v, 'Array') === 0) {
                $map[$k] = '';
            }
        }
    }

    private static function extractWpVersionFromHtml(string $html): string
    {
        if (preg_match('/<meta\s+name=["\']generator["\']\s+content=["\']WordPress\s+([\d.]+)/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/content=["\']WordPress\s+([\d.]+)["\']\s+name=["\']generator["\']/i', $html, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @param mixed $decoded JSON-decoded /wp/v2/themes response
     */
    private static function activeThemeNameFromThemesPayload(mixed $decoded): string
    {
        if (! is_array($decoded)) {
            return '';
        }
        /** @var list<array<string, mixed>> $items */
        $items = array_is_list($decoded) ? $decoded : [$decoded];
        $list = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }
        foreach ($list as $item) {
            if (($item['status'] ?? '') === 'active') {
                $name = MixedText::toPlainString($item['name'] ?? null);
                if ($name !== '') {
                    return $name;
                }
            }
        }
        if (count($list) === 1) {
            return MixedText::toPlainString($list[0]['name'] ?? null);
        }

        return '';
    }

    /**
     * @return array{p: int, t: int, c: int, detail: ?array<string, mixed>}
     */
    private function fallbackDetailAndCounts(string $restBase, string $user, string $pass): array
    {
        $pluginItems = [];
        $pl = $this->http->request('GET', $restBase . '/wp/v2/plugins', $user, $pass, null, true);
        if ($pl['ok'] && is_array($pl['decoded'])) {
            foreach ($pl['decoded'] as $item) {
                if (! is_array($item) || empty($item['update'])) {
                    continue;
                }
                $file = (string) ($item['plugin'] ?? '');
                $name = (string) ($item['name'] ?? '');
                if ($name === '') {
                    $name = $file !== '' ? $file : 'Plugin';
                }
                $newVersion = '';
                if (isset($item['update']) && is_array($item['update'])) {
                    $newVersion = (string) ($item['update']['new_version'] ?? '');
                }
                $pluginItems[] = [
                    'file' => $file,
                    'name' => $name,
                    'new_version' => $newVersion,
                ];
            }
        }

        $themeItems = [];
        $th = $this->http->request('GET', $restBase . '/wp/v2/themes', $user, $pass, null, true);
        if ($th['ok'] && is_array($th['decoded'])) {
            foreach ($th['decoded'] as $item) {
                if (! is_array($item) || empty($item['update'])) {
                    continue;
                }
                $stylesheet = (string) ($item['stylesheet'] ?? '');
                $name = (string) ($item['name'] ?? '');
                if ($name === '') {
                    $name = $stylesheet !== '' ? $stylesheet : 'Theme';
                }
                $newVersion = '';
                if (isset($item['update']) && is_array($item['update'])) {
                    $newVersion = (string) ($item['update']['new_version'] ?? '');
                }
                $themeItems[] = [
                    'stylesheet' => $stylesheet,
                    'name' => $name,
                    'new_version' => $newVersion,
                ];
            }
        }

        $detail = [
            'plugin_items' => $pluginItems,
            'theme_items' => $themeItems,
            'core_item' => null,
            'source' => 'fallback_rest',
        ];

        return [
            'p' => count($pluginItems),
            't' => count($themeItems),
            'c' => 0,
            'detail' => $detail,
        ];
    }

    /**
     * @param ?array{plugin_items?: list<array<string, mixed>>, theme_items?: list<array<string, mixed>>, core_item?: ?array<string, mixed>} $detail
     */
    private static function formatPendingSummary(?array $detail): ?string
    {
        if ($detail === null) {
            return null;
        }
        $chunks = [];
        foreach ($detail['plugin_items'] ?? [] as $it) {
            if (! is_array($it)) {
                continue;
            }
            $name = (string) ($it['name'] ?? $it['file'] ?? 'Plugin');
            $nv = (string) ($it['new_version'] ?? '');
            $chunks[] = $nv !== '' ? "{$name} → v{$nv}" : $name;
        }
        foreach ($detail['theme_items'] ?? [] as $it) {
            if (! is_array($it)) {
                continue;
            }
            $name = (string) ($it['name'] ?? $it['stylesheet'] ?? 'Theme');
            $nv = (string) ($it['new_version'] ?? '');
            $chunks[] = $nv !== '' ? "theme {$name} → v{$nv}" : "theme {$name}";
        }
        $core = $detail['core_item'] ?? null;
        if (is_array($core) && (($core['version'] ?? '') !== '' || ($core['current'] ?? '') !== '')) {
            $to = (string) ($core['version'] ?? '?');
            $from = (string) ($core['current'] ?? '');
            $chunks[] = $from !== '' ? "WordPress core {$from} → {$to}" : "WordPress core → {$to}";
        }

        return $chunks === [] ? null : implode('; ', $chunks);
    }

    public static function normalizeAppPassword(string $password): string
    {
        return preg_replace('/\s+/', '', $password) ?? $password;
    }

    /**
     * Probes WordPress REST authentication (/wp/v2/users/me) and companion summary reachability.
     *
     * @return array{
     *     rest_authenticated: bool,
     *     rest_status: int,
     *     rest_error: ?string,
     *     wp_user_slug: ?string,
     *     companion_summary_ok: bool,
     *     companion_summary_status: int,
     *     companion_summary_error: ?string
     * }
     */
    public function testConnection(Site $site, string $plainAppPassword): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $user = $site->adminUser;
        $pass = self::normalizeAppPassword($plainAppPassword);

        $me = $this->http->request('GET', $base . '/wp/v2/users/me', $user, $pass, null, true);
        $restOk = $me['ok'] && is_array($me['decoded']);
        $slug = null;
        if ($restOk) {
            $slugRaw = $me['decoded']['slug'] ?? null;
            $slug = is_string($slugRaw) && $slugRaw !== '' ? $slugRaw : null;
        }

        $summaryUrl = $base . '/s35-wp-hub/v1/updates/summary';
        $sum = $this->http->request('GET', $summaryUrl, $user, $pass, null, true);
        $sumOk = $sum['ok'] && is_array($sum['decoded']);

        return [
            'rest_authenticated' => $restOk,
            'rest_status' => $me['status'],
            'rest_error' => $restOk ? null : ($me['error'] ?? ('HTTP ' . $me['status'])),
            'wp_user_slug' => $slug,
            'companion_summary_ok' => $sumOk,
            'companion_summary_status' => $sum['status'],
            'companion_summary_error' => $sumOk ? null : ($sum['error'] ?? ('HTTP ' . $sum['status'])),
        ];
    }
}
