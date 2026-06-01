<?php

declare(strict_types=1);

namespace S35WpHub;

use PDO;
use S35WpHub\Model\Site;
use S35WpHub\Repository\DashboardUserRepository;
use S35WpHub\Repository\LogRepository;
use S35WpHub\Repository\OwnerRepository;
use S35WpHub\Repository\SettingsRepository;
use S35WpHub\Repository\PluginPackageRepository;
use S35WpHub\Repository\SiteRepository;
use S35WpHub\Repository\UpdateSnapshotRepository;
use S35WpHub\Service\CompanionZipBuilder;
use S35WpHub\Service\FleetPluginsAggregator;
use S35WpHub\Service\OwnerReportService;
use S35WpHub\Service\PluginDeployService;
use S35WpHub\Service\RemoteUpdateService;
use S35WpHub\Service\SyncService;
use S35WpHub\Service\WordPressHttp;
use S35WpHub\Util\PluginZipInspector;
use S35WpHub\Util\SiteUrl;

final class Application
{
    private SiteRepository $sites;

    private LogRepository $logs;

    private SettingsRepository $settings;

    private Crypto $crypto;

    private SyncService $sync;

    private RemoteUpdateService $remoteUpdates;

    private OwnerRepository $owners;

    private OwnerReportService $ownerReports;

    private PluginPackageRepository $pluginPackages;

    private PluginDeployService $pluginDeploy;

    private UpdateSnapshotRepository $updateSnapshots;

    public function __construct(
        private readonly PDO $pdo
    ) {
        $keyB64 = (string) Config::get('encryption_key');
        $this->crypto = Crypto::fromBase64Key($keyB64);
        $this->sites = new SiteRepository($pdo);
        $this->logs = new LogRepository($pdo);
        $this->settings = new SettingsRepository($pdo);
        $this->owners = new OwnerRepository($pdo);
        $this->ownerReports = new OwnerReportService($this->logs);
        $http = self::buildWordPressHttp();
        $this->sync = new SyncService($this->sites, $http);
        $this->remoteUpdates = new RemoteUpdateService($http);
        $this->pluginDeploy = new PluginDeployService($http);
        $projectRoot = dirname(__DIR__);
        $this->pluginPackages = new PluginPackageRepository(
            $pdo,
            $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'plugin-packages'
        );
        $this->updateSnapshots = new UpdateSnapshotRepository($pdo);
    }

    private static function buildWordPressHttp(): WordPressHttp
    {
        $ca = Config::get('curl_ca_bundle');
        $caPath = is_string($ca) && $ca !== '' ? $ca : null;
        if ($caPath !== null && ! is_file($caPath)) {
            $caPath = null;
        }
        $verify = Config::get('http_verify_ssl', true);
        $verifySsl = filter_var($verify, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($verifySsl === null) {
            $verifySsl = (bool) $verify;
        }

        return new WordPressHttp(45, $caPath, $verifySsl);
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST') {
            $this->handlePost();

            return;
        }
        $this->handleGet();
    }

    private function baseUrl(): string
    {
        return rtrim((string) Config::get('base_url', ''), '/');
    }

    private function redirect(string $pathOrUrl): never
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            header('Location: ' . $pathOrUrl);
        } else {
            header('Location: ' . $this->baseUrl() . '/' . ltrim($pathOrUrl, '/'));
        }
        exit;
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['csrf'];
    }

    private function wantsAjax(): bool
    {
        return (string) ($_POST['ajax'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $status = 200): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function assertCsrf(): void
    {
        $t = (string) ($_POST['csrf'] ?? '');
        if ($t === '' || ! hash_equals((string) ($_SESSION['csrf'] ?? ''), $t)) {
            if ($this->wantsAjax()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            http_response_code(403);
            echo 'Invalid CSRF token.';
            exit;
        }
    }

    private function handleGet(): void
    {
        $page = (string) ($_GET['page'] ?? 'home');
        if ($page === 'export_csv') {
            $this->handleExportCsv();
            return;
        }
        if ($page === 'site_new') {
            View::render('layout', [
                'title' => 'Add site',
                'body' => $this->capture('site_form', [
                    'site' => null,
                    'owners' => $this->owners->all(),
                    'csrf' => $this->csrfToken(),
                    'agency' => $this->settings->get('agency_name'),
                ]),
            ]);

            return;
        }
        if ($page === 'site_edit') {
            $id = (int) ($_GET['id'] ?? 0);
            $site = $this->sites->find($id);
            if ($site === null) {
                $this->flash('error', 'Site not found.');
                $this->redirect('index.php');

                return;
            }
            View::render('layout', [
                'title' => 'Edit site',
                'body' => $this->capture('site_form', [
                    'site' => $site,
                    'owners' => $this->owners->all(),
                    'csrf' => $this->csrfToken(),
                    'agency' => $this->settings->get('agency_name'),
                ]),
            ]);

            return;
        }
        if ($page === 'owners') {
            $rows = [];
            foreach ($this->owners->all() as $o) {
                $rows[] = ['owner' => $o, 'site_count' => $this->owners->countSites($o->id)];
            }
            View::render('layout', [
                'title' => 'Owners',
                'body' => $this->capture('owners_index', [
                    'owner_rows' => $rows,
                    'csrf' => $this->csrfToken(),
                    'can_send_reports' => $this->reportMailConfig() !== null,
                ]),
            ]);

            return;
        }
        if ($page === 'owner_new') {
            View::render('layout', [
                'title' => 'Add owner',
                'body' => $this->capture('owner_form', [
                    'owner' => null,
                    'csrf' => $this->csrfToken(),
                ]),
            ]);

            return;
        }
        if ($page === 'owner_edit') {
            $id = (int) ($_GET['id'] ?? 0);
            $owner = $this->owners->find($id);
            if ($owner === null) {
                $this->flash('error', 'Owner not found.');
                $this->redirect('index.php?page=owners');

                return;
            }
            View::render('layout', [
                'title' => 'Edit owner',
                'body' => $this->capture('owner_form', [
                    'owner' => $owner,
                    'csrf' => $this->csrfToken(),
                ]),
            ]);

            return;
        }
        if ($page === 'settings') {
            $base = $this->baseUrl();
            $host = parse_url($base, PHP_URL_HOST);
            View::render('layout', [
                'title' => 'Settings',
                'body' => $this->capture('settings', [
                    'agency_name' => $this->settings->get('agency_name'),
                    'csrf' => $this->csrfToken(),
                    'companion_manifest_url' => $base . '/plugin-update/manifest.php',
                    'hub_host_for_packages' => is_string($host) && $host !== '' ? $host : '',
                ]),
            ]);

            return;
        }
        if ($page === 'account') {
            $user = DashboardAuth::currentUsername();
            if ($user === null) {
                $this->redirect('index.php');

                return;
            }
            $dashUsers = new DashboardUserRepository($this->pdo);
            View::render('layout', [
                'title' => 'Account',
                'body' => $this->capture('account', [
                    'username' => $user,
                    'csrf' => $this->csrfToken(),
                    'can_change_password' => $dashUsers->getPasswordHash($user) !== null,
                ]),
            ]);

            return;
        }
        if ($page === 'plugin_packages') {
            $base = $this->baseUrl();
            View::render('layout', [
                'title' => 'Plugin packages',
                'body' => $this->capture('plugin_packages', [
                    'packages' => $this->pluginPackages->all(),
                    'sites' => $this->sites->all(),
                    'csrf' => $this->csrfToken(),
                    'hub_base_url' => $base,
                    'companion_manifest_url' => $base . '/plugin-update/manifest.php',
                    'companion_package_url' => $base . '/plugin-update/s35-wp-hub.zip',
                ]),
            ]);

            return;
        }

        $list = $this->sites->all();
        $sitesTab = strtolower(trim((string) ($_GET['tab'] ?? '')));
        if (! in_array($sitesTab, ['plugins', 'compare', 'activity'], true)) {
            $sitesTab = 'sites';
        }

        $activityLogs = [];
        $activityDateFrom = '';
        $activityDateTo = '';
        $activitySearch = '';
        $activityActionFilter = '';
        if ($sitesTab === 'activity') {
            $activityDateFrom = trim((string) ($_GET['date_from'] ?? ''));
            $activityDateTo = trim((string) ($_GET['date_to'] ?? ''));
            $activitySearch = trim((string) ($_GET['search'] ?? ''));
            $activityActionFilter = trim((string) ($_GET['action_filter'] ?? ''));
            $activityLogs = $this->logs->filteredAll($activityDateFrom, $activityDateTo, $activitySearch, $activityActionFilter, 200);
        } else {
            $activityLogs = $this->logs->recentAll(40);
        }

        $compareDeltas = [];
        $compareSnapshots = [];
        $compareBatchLabel = '';
        $compareResultFilter = '';
        if ($sitesTab === 'compare') {
            $compareSnapshots = $this->updateSnapshots->listBatches();
            $compareBatchId = (int) ($_GET['batch_id'] ?? 0);
            $compareSiteId = (int) ($_GET['site_id'] ?? 0);
            if ($compareBatchId > 0) {
                $batchRows = $this->updateSnapshots->getBatchSnapshots($compareBatchId);
                if ($compareSiteId > 0) {
                    $filtered = [];
                    foreach ($batchRows as $br) {
                        if ((int) $br['site_id'] === $compareSiteId) {
                            $filtered[] = $br;
                        }
                    }
                    $batchRows = $filtered;
                }
                if ($batchRows !== []) {
                    $compareBatchLabel = $batchRows[0]['created_at'] ?? '';
                }
                $currentFleet = $this->updateSnapshots->currentFleetState();
                $deltas = [];

                foreach ($batchRows as $batchRow) {
                    $siteId = (int) $batchRow['site_id'];
                    $snapshot = json_decode($batchRow['snapshot_json'], true);
                    if (!is_array($snapshot)) {
                        continue;
                    }

                    $siteLabel = ($batchRow['site_label'] !== '') ? $batchRow['site_label'] : $batchRow['site_url'];
                    $siteUrl = $batchRow['site_url'];
                    $currentSite = $currentFleet[$siteId] ?? null;

                    $snapPlugins = [];
                    if (!empty($snapshot['installed_plugins']) && is_array($snapshot['installed_plugins'])) {
                        foreach ($snapshot['installed_plugins'] as $p) {
                            $snapPlugins[$p['file']] = [
                                'name' => $p['name'],
                                'version' => $p['version'],
                                'active' => !empty($p['active']),
                            ];
                        }
                    }

                    $snapThemes = [];
                    if (!empty($snapshot['active_theme_name']) && is_string($snapshot['active_theme_name'])) {
                        $snapThemes[$snapshot['active_theme_name']] = ['name' => $snapshot['active_theme_name'], 'active' => true];
                    }

                    $curPlugins = $currentSite !== null ? $currentSite['plugins'] : [];
                    $curThemes = $currentSite !== null ? $currentSite['themes'] : [];
                    $isOffline = $currentSite === null;

                    foreach ($snapPlugins as $file => $sp) {
                        if (isset($curPlugins[$file])) {
                            $cp = $curPlugins[$file];
                            $verDiff = $cp['version'] !== $sp['version'];
                            $actDiff = $cp['active'] !== $sp['active'];
                            if ($verDiff || $actDiff) {
                                $reasons = [];
                                if ($verDiff) {
                                    $reasons[] = 'Version changed ' . $sp['version'] . ' → ' . $cp['version'];
                                }
                                if ($actDiff) {
                                    $reasons[] = 'Status changed ' . ($sp['active'] ? 'Active' : 'Inactive') . ' → ' . ($cp['active'] ? 'Active' : 'Inactive');
                                }
                                $deltas[] = [
                                    'site_label' => $siteLabel,
                                    'site_url' => $siteUrl,
                                    'type' => 'plugin',
                                    'name' => $sp['name'],
                                    'file' => $file,
                                    'old_version' => $sp['version'],
                                    'new_version' => $cp['version'],
                                    'old_active' => $sp['active'],
                                    'new_active' => $cp['active'],
                                    'status' => 'different',
                                    'reason' => implode('; ', $reasons),
                                ];
                            } else {
                                $deltas[] = [
                                    'site_label' => $siteLabel,
                                    'site_url' => $siteUrl,
                                    'type' => 'plugin',
                                    'name' => $sp['name'],
                                    'file' => $file,
                                    'old_version' => $sp['version'],
                                    'new_version' => $cp['version'],
                                    'old_active' => $sp['active'],
                                    'new_active' => $cp['active'],
                                    'status' => 'same',
                                    'reason' => '',
                                ];
                            }
                        } else {
                            $deltas[] = [
                                'site_label' => $siteLabel,
                                'site_url' => $siteUrl,
                                'type' => 'plugin',
                                'name' => $sp['name'],
                                'file' => $file,
                                'old_version' => $sp['version'],
                                'new_version' => $isOffline ? '—' : '',
                                'old_active' => $sp['active'],
                                'new_active' => false,
                                'status' => 'different',
                                'reason' => $isOffline ? 'Site is currently offline' : 'Plugin no longer installed',
                            ];
                        }
                    }

                    foreach ($curPlugins as $file => $cp) {
                        if (!isset($snapPlugins[$file])) {
                            $deltas[] = [
                                'site_label' => $siteLabel,
                                'site_url' => $siteUrl,
                                'type' => 'plugin',
                                'name' => $cp['name'],
                                'file' => $file,
                                'old_version' => '',
                                'new_version' => $cp['version'],
                                'old_active' => false,
                                'new_active' => $cp['active'],
                                'status' => 'different',
                                'reason' => 'Plugin installed since snapshot',
                            ];
                        }
                    }

                    foreach ($snapThemes as $tName => $st) {
                        if (isset($curThemes[$tName])) {
                            $deltas[] = [
                                'site_label' => $siteLabel,
                                'site_url' => $siteUrl,
                                'type' => 'theme',
                                'name' => $st['name'],
                                'file' => '',
                                'old_version' => '',
                                'new_version' => '',
                                'old_active' => $st['active'],
                                'new_active' => $curThemes[$tName]['active'],
                                'status' => 'same',
                                'reason' => '',
                            ];
                        } else {
                            $deltas[] = [
                                'site_label' => $siteLabel,
                                'site_url' => $siteUrl,
                                'type' => 'theme',
                                'name' => $st['name'],
                                'file' => '',
                                'old_version' => '',
                                'new_version' => '',
                                'old_active' => $st['active'],
                                'new_active' => false,
                                'status' => 'different',
                                'reason' => $isOffline ? 'Site is currently offline' : 'Theme no longer installed',
                            ];
                        }
                    }

                    foreach ($curThemes as $tName => $ct) {
                        if (!isset($snapThemes[$tName])) {
                            $deltas[] = [
                                'site_label' => $siteLabel,
                                'site_url' => $siteUrl,
                                'type' => 'theme',
                                'name' => $ct['name'],
                                'file' => '',
                                'old_version' => '',
                                'new_version' => '',
                                'old_active' => false,
                                'new_active' => $ct['active'],
                                'status' => 'different',
                                'reason' => 'Theme installed since snapshot',
                            ];
                        }
                    }
                }

                $compareResultFilter = strtolower(trim((string) ($_GET['result_filter'] ?? '')));
                if ($compareResultFilter !== '' && in_array($compareResultFilter, ['no_change', 'changed'], true)) {
                    $filteredDeltas = [];
                    foreach ($deltas as $d) {
                        if ($compareResultFilter === 'no_change' && $d['status'] === 'same') {
                            $filteredDeltas[] = $d;
                        } elseif ($compareResultFilter === 'changed' && $d['status'] === 'different') {
                            $filteredDeltas[] = $d;
                        }
                    }
                    $compareDeltas = $filteredDeltas;
                } else {
                    $compareDeltas = $deltas;
                }
            }
        }

        View::render('layout', [
            'title' => 'Sites',
            'body' => $this->capture('sites_index', [
                'sites' => $list,
                'sites_tab' => $sitesTab,
                'fleet_plugins' => FleetPluginsAggregator::aggregate($list),
                'csrf' => $this->csrfToken(),
                'agency' => $this->settings->get('agency_name'),
                'activity_logs' => $activityLogs,
                'compare_snapshots' => $compareSnapshots,
                'compare_deltas' => $compareDeltas,
                'compare_batch_label' => $compareBatchLabel,
                'selected_batch_id' => $sitesTab === 'compare' ? (int) ($_GET['batch_id'] ?? 0) : 0,
                'selected_site_id' => $sitesTab === 'compare' ? (int) ($_GET['site_id'] ?? 0) : 0,
                'compare_result_filter' => $compareResultFilter,
                'activity_date_from' => $activityDateFrom,
                'activity_date_to' => $activityDateTo,
                'activity_search' => $activitySearch,
                'activity_action_filter' => $activityActionFilter,
            ]),
        ]);
    }

    /**
     * @param array{last_status: string, pending_plugins: int, pending_themes: int, pending_core: int, active_plugins: int, inactive_plugins: int, active_themes: int, inactive_themes: int, last_error: ?string, source: string, pending_summary?: ?string} $r
     */
    private function formatSyncLogMessage(array $r): string
    {
        if ($r['last_status'] === 'offline') {
            return 'Offline. ' . (string) ($r['last_error'] ?? 'Unreachable');
        }

        $p = (int) $r['pending_plugins'];
        $t = (int) $r['pending_themes'];
        $c = (int) $r['pending_core'];
        $ap = (int) $r['active_plugins'];
        $ip = (int) $r['inactive_plugins'];
        $at = (int) $r['active_themes'];
        $it = (int) $r['inactive_themes'];
        $sourceLabel = match ($r['source']) {
            'companion' => 'companion plugin (WordPress update transients)',
            'fallback' => 'WordPress REST API (fallback)',
            default => (string) $r['source'],
        };
        $pendingLine = ($p + $t + $c) === 0
            ? 'Online. No pending updates.'
            : sprintf('Online. Pending updates: %d plugin(s), %d theme(s), %d core.', $p, $t, $c);
        $parts = [
            $pendingLine,
            sprintf('Installed: %d active / %d inactive plugin(s), %d active / %d inactive theme(s).', $ap, $ip, $at, $it),
            'Counts from ' . $sourceLabel . '.',
        ];
        $summary = (string) ($r['pending_summary'] ?? '');
        if ($summary !== '') {
            $parts[] = 'Pending items: ' . $summary;
        }
        if ($r['source'] === 'fallback' && ($r['last_error'] ?? '') !== '') {
            $parts[] = (string) $r['last_error'];
        }

        return implode(' ', $parts);
    }

    /**
     * @param array{
     *     rest_authenticated: bool,
     *     rest_status: int,
     *     rest_error: ?string,
     *     wp_user_slug: ?string,
     *     companion_summary_ok: bool,
     *     companion_summary_status: int,
     *     companion_summary_error: ?string
     * } $r
     */
    private function formatTestConnectionLogMessage(array $r): string
    {
        if (! $r['rest_authenticated']) {
            return 'Connection test failed: WordPress REST auth — ' . (string) ($r['rest_error'] ?? 'HTTP ' . $r['rest_status']);
        }
        $who = $r['wp_user_slug'] !== null ? 'user "' . $r['wp_user_slug'] . '"' : 'user';
        if ($r['companion_summary_ok']) {
            return 'Connection test OK: authenticated as ' . $who . '; companion summary route reachable.';
        }
        $hint = (string) ($r['companion_summary_error'] ?? 'HTTP ' . $r['companion_summary_status']);
        if ($r['companion_summary_status'] === 404) {
            return 'Connection test: REST auth OK (' . $who . '). Companion missing or inactive (summary HTTP 404).';
        }

        return 'Connection test: REST auth OK (' . $who . '). Companion summary failed — ' . $hint;
    }

    /**
     * @param array{
     *     rest_authenticated: bool,
     *     rest_status: int,
     *     rest_error: ?string,
     *     wp_user_slug: ?string,
     *     companion_summary_ok: bool,
     *     companion_summary_status: int,
     *     companion_summary_error: ?string
     * } $r
     */
    private function formatTestConnectionFlashMessage(array $r): string
    {
        if (! $r['rest_authenticated']) {
            return 'Test failed: could not authenticate to WordPress REST API. Check username and application password.';
        }
        if ($r['companion_summary_ok']) {
            return 'Test OK: application password works and the companion summary endpoint responded.';
        }
        if ($r['companion_summary_status'] === 404) {
            return 'Application password works, but the companion plugin summary route was not found (404). Install or activate s35-wp-hub on the site.';
        }

        return 'Application password works, but the companion summary request failed. See the activity log for the HTTP details (capabilities, IP allowlist, etc.).';
    }

    private function handleExportCsv(): void
    {
        $sites = $this->sites->all();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sites-inventory-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');

        fputcsv($out, ['Site', 'SiteURL', 'Owner', 'PHP Version', 'Type', 'Name', 'Status', 'Version']);

        foreach ($sites as $site) {
            $siteLabel = ($site->label !== null && $site->label !== '') ? $site->label : $site->siteUrl;
            $owner = $site->ownerDisplayName ?? '';
            $snap = $site->siteSnapshot();
            $phpVersion = is_array($snap) ? ($snap['php_version'] ?? '') : '';
            $wpVersion = is_array($snap) ? ($snap['wp_version'] ?? '') : '';
            $activeTheme = is_array($snap) ? ($snap['active_theme_name'] ?? '') : '';

            fputcsv($out, [$siteLabel, $site->siteUrl, $owner, $phpVersion, 'Core', 'WordPress', 'N/A', $wpVersion]);

            if (is_array($snap) && !empty($snap['installed_plugins']) && is_array($snap['installed_plugins'])) {
                foreach ($snap['installed_plugins'] as $plugin) {
                    if (!is_array($plugin)) {
                        continue;
                    }
                    $name = (string) ($plugin['name'] ?? '');
                    $version = (string) ($plugin['version'] ?? '');
                    $isActive = !empty($plugin['active']);
                    fputcsv($out, [$siteLabel, $site->siteUrl, $owner, $phpVersion, 'Plugin', $name, $isActive ? 'Active' : 'Inactive', $version]);
                }
            }

            if ($activeTheme !== '') {
                fputcsv($out, [$siteLabel, $site->siteUrl, $owner, $phpVersion, 'Theme', $activeTheme, 'Active', '']);
            }
        }

        fclose($out);
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $view, array $data): string
    {
        ob_start();
        View::render($view, $data);

        return (string) ob_get_clean();
    }

    private function handlePost(): void
    {
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'save_site' => $this->postSaveSite(),
            'delete_site' => $this->postDeleteSite(),
            'sync_all' => $this->postSyncAll(),
            'sync_one' => $this->postSyncOne(),
            'test_connection' => $this->postTestConnection(),
            'run_updates' => $this->postRunUpdates(),
            'save_settings' => $this->postSaveSettings(),
            'change_dashboard_password' => $this->postChangeDashboardPassword(),
            'save_owner' => $this->postSaveOwner(),
            'delete_owner' => $this->postDeleteOwner(),
            'send_owner_report' => $this->postSendOwnerReport(),
            'send_all_owner_reports' => $this->postSendAllOwnerReports(),
            'upload_plugin_package' => $this->postUploadPluginPackage(),
            'delete_plugin_package' => $this->postDeletePluginPackage(),
            'deploy_plugin_package' => $this->postDeployPluginPackage(),
            'build_companion_zip' => $this->postBuildCompanionZip(),
            'delete_remote_plugin' => $this->postDeleteRemotePlugin(),
            'deactivate_remote_plugin' => $this->postDeactivateRemotePlugin(),
            'activate_remote_plugin' => $this->postActivateRemotePlugin(),
            'capture_snapshot' => $this->postCaptureSnapshot(),
            'capture_update_snapshot' => $this->postCaptureUpdateSnapshot(),
            'delete_snapshot' => $this->postDeleteSnapshot(),
            'delete_snapshots' => $this->postDeleteSnapshots(),
            default => $this->redirect('index.php'),
        };
    }

    /**
     * @return ?array{email: string, name: string}
     */
    private function reportMailConfig(): ?array
    {
        $email = trim((string) Config::get('report_mail_from', ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'email' => $email,
            'name' => trim((string) Config::get('report_mail_from_name', 's35-wp-hub')),
        ];
    }

    private function postSaveOwner(): void
    {
        $this->assertCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $fn = trim((string) ($_POST['first_name'] ?? ''));
        $ln = trim((string) ($_POST['last_name'] ?? ''));
        $mail = trim((string) ($_POST['owner_email'] ?? ''));
        $renewal = trim((string) ($_POST['renewal_date'] ?? ''));

        if ($fn === '' || $ln === '') {
            $this->flash('error', 'First and last name are required.');
            $this->redirect($id > 0 ? 'index.php?page=owner_edit&id=' . $id : 'index.php?page=owner_new');

            return;
        }
        if ($mail === '' || ! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid owner email is required.');
            $this->redirect($id > 0 ? 'index.php?page=owner_edit&id=' . $id : 'index.php?page=owner_new');

            return;
        }

        if ($id === 0) {
            $this->owners->create($fn, $ln, $renewal, $mail);
            $this->flash('success', 'Owner added.');
            $this->redirect('index.php?page=owners');

            return;
        }

        if ($this->owners->find($id) === null) {
            $this->flash('error', 'Owner not found.');
            $this->redirect('index.php?page=owners');

            return;
        }
        $this->owners->update($id, $fn, $ln, $renewal, $mail);
        $this->flash('success', 'Owner saved.');
        $this->redirect('index.php?page=owners');
    }

    private function postDeleteOwner(): void
    {
        $this->assertCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || $this->owners->find($id) === null) {
            $this->redirect('index.php?page=owners');

            return;
        }
        $this->owners->delete($id);
        $this->flash('success', 'Owner removed. Sites are unassigned.');
        $this->redirect('index.php?page=owners');
    }

    private function postSendOwnerReport(): void
    {
        $this->assertCsrf();
        $cfg = $this->reportMailConfig();
        if ($cfg === null) {
            $this->flash('error', 'Set report_mail_from in config.php to send email.');
            $this->redirect('index.php?page=owners');

            return;
        }
        $oid = (int) ($_POST['owner_id'] ?? 0);
        $owner = $this->owners->find($oid);
        if ($owner === null) {
            $this->flash('error', 'Owner not found.');
            $this->redirect('index.php?page=owners');

            return;
        }
        $sites = $this->sites->allForOwner($oid);
        $agency = $this->settings->get('agency_name');
        if ($this->ownerReports->sendReport($owner, $agency, $sites, $cfg['email'], $cfg['name'])) {
            $this->flash('success', 'Report sent to ' . $owner->ownerEmail . '.');
        } else {
            $this->flash('error', 'Could not send email (check server mail / address).');
        }
        $this->redirect('index.php?page=owners');
    }

    private function postSendAllOwnerReports(): void
    {
        $this->assertCsrf();
        $cfg = $this->reportMailConfig();
        if ($cfg === null) {
            $this->flash('error', 'Set report_mail_from in config.php to send email.');
            $this->redirect('index.php?page=owners');

            return;
        }
        $agency = $this->settings->get('agency_name');
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $ownerList = $this->owners->all();
        if ($ownerList === []) {
            $this->flash('error', 'No owners defined yet.');
            $this->redirect('index.php?page=owners');

            return;
        }
        foreach ($ownerList as $owner) {
            $sites = $this->sites->allForOwner($owner->id);
            if ($sites === []) {
                ++$skipped;

                continue;
            }
            if ($this->ownerReports->sendReport($owner, $agency, $sites, $cfg['email'], $cfg['name'])) {
                ++$sent;
            } else {
                ++$failed;
            }
        }
        $parts = [];
        if ($sent > 0) {
            $parts[] = $sent . ' sent';
        }
        if ($failed > 0) {
            $parts[] = $failed . ' failed';
        }
        if ($skipped > 0) {
            $parts[] = $skipped . ' skipped (no sites)';
        }
        $msg = $parts !== [] ? implode(', ', $parts) . '.' : 'No reports sent (no owner had sites assigned).';
        $this->flash($failed > 0 ? 'error' : 'success', $msg);
        $this->redirect('index.php?page=owners');
    }

    private function postChangeDashboardPassword(): void
    {
        $this->assertCsrf();
        $username = DashboardAuth::currentUsername();
        if ($username === null) {
            $this->redirect('index.php');

            return;
        }
        $repo = new DashboardUserRepository($this->pdo);
        $existingHash = $repo->getPasswordHash($username);
        if ($existingHash === null) {
            $this->flash(
                'error',
                'This account is not in the dashboard user database. Open the site list once after upgrading, or edit config.php.'
            );
            $this->redirect('index.php?page=account');

            return;
        }
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        if ($new === '' || strlen($new) < 8) {
            $this->flash('error', 'New password must be at least 8 characters.');
            $this->redirect('index.php?page=account');

            return;
        }
        if ($new !== $confirm) {
            $this->flash('error', 'New passwords do not match.');
            $this->redirect('index.php?page=account');

            return;
        }
        if (! password_verify($current, $existingHash)) {
            $this->flash('error', 'Current password is incorrect.');
            $this->redirect('index.php?page=account');

            return;
        }
        $repo->updatePassword($username, password_hash($new, PASSWORD_DEFAULT));
        $this->flash('success', 'Password updated.');
        $this->redirect('index.php?page=account');
    }

    private function postSaveSettings(): void
    {
        $this->assertCsrf();
        $name = trim((string) ($_POST['agency_name'] ?? ''));
        if ($name === '') {
            $name = 'My Agency';
        }
        $this->settings->set('agency_name', $name);
        $this->flash('success', 'Settings saved.');
        $this->redirect('index.php?page=settings');
    }

    private function postSaveSite(): void
    {
        $this->assertCsrf();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $label = trim((string) ($_POST['label'] ?? '')) ?: null;
        $rawUrl = (string) ($_POST['site_url'] ?? '');
        $admin = trim((string) ($_POST['admin_user'] ?? ''));
        $appPass = (string) ($_POST['app_password'] ?? '');

        try {
            $url = SiteUrl::normalize($rawUrl);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($id > 0 ? 'index.php?page=site_edit&id=' . $id : 'index.php?page=site_new');

            return;
        }

        if ($admin === '') {
            $this->flash('error', 'Admin username is required.');
            $this->redirect($id > 0 ? 'index.php?page=site_edit&id=' . $id : 'index.php?page=site_new');

            return;
        }

        $ownerIdRaw = (int) ($_POST['owner_id'] ?? 0);
        $ownerId = $ownerIdRaw > 0 ? $ownerIdRaw : null;
        if ($ownerId !== null && $this->owners->find($ownerId) === null) {
            $this->flash('error', 'Selected owner was not found.');
            $this->redirect($id > 0 ? 'index.php?page=site_edit&id=' . $id : 'index.php?page=site_new');

            return;
        }

        if ($id === 0) {
            if ($appPass === '') {
                $this->flash('error', 'Application password is required for new sites.');
                $this->redirect('index.php?page=site_new');

                return;
            }
            $enc = $this->crypto->encrypt(SyncService::normalizeAppPassword($appPass));
            $this->sites->create($url, $admin, $enc, $label, $ownerId);
            $this->flash('success', 'Site added.');
            $this->redirect('index.php');

            return;
        }

        if ($appPass !== '') {
            $enc = $this->crypto->encrypt(SyncService::normalizeAppPassword($appPass));
            $this->sites->update($id, $url, $admin, $enc, $label, $ownerId);
        } else {
            $this->sites->update($id, $url, $admin, null, $label, $ownerId);
        }
        $this->flash('success', 'Site updated.');
        $this->redirect('index.php');
    }

    private function postDeleteSite(): void
    {
        $this->assertCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('index.php');

            return;
        }
        $this->sites->delete($id);
        $this->flash('success', 'Site removed.');
        $this->redirect('index.php');
    }

    private function postSyncAll(): void
    {
        $this->assertCsrf();
        $online = 0;
        $offline = 0;
        foreach ($this->sites->all() as $site) {
            $r = $this->syncOneSite($site);
            if ($r === null) {
                continue;
            }
            $this->logs->add((int) $site->id, 'sync', null, null, null, $this->formatSyncLogMessage($r));
            if ($r['last_status'] === 'online') {
                ++$online;
            } else {
                ++$offline;
            }
        }
        $this->updateSnapshots->captureFleet(
            'Sync all',
            $this->sites->all(),
            fn ($site) => $site->siteSnapshotJson
        );
        $this->flash('success', sprintf('Sync finished for all sites: %d online, %d offline.', $online, $offline));
        $this->redirect('index.php');
    }

    private function postTestConnection(): void
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Invalid site.'], 400);
            }
            $this->flash('error', 'Invalid site.');
            $this->redirect('index.php');

            return;
        }
        $row = $this->sites->findWithSecret($id);
        if ($row === null) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Site not found.'], 404);
            }
            $this->flash('error', 'Site not found.');
            $this->redirect('index.php');

            return;
        }
        try {
            $plain = $this->crypto->decrypt($row['app_password_encrypted']);
        } catch (\Throwable) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Could not read stored application password.'], 500);
            }
            $this->flash('error', 'Could not read stored application password.');
            $this->redirect('index.php?page=site_edit&id=' . $id);

            return;
        }
        if ($plain === '') {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'No application password stored for this site.'], 400);
            }
            $this->flash('error', 'Save an application password for this site before running a connection test.');
            $this->redirect('index.php?page=site_edit&id=' . $id);

            return;
        }
        $site = $row['site'];
        $r = $this->sync->testConnection($site, $plain);
        $logMsg = $this->formatTestConnectionLogMessage($r);
        $this->logs->add($id, 'test_connection', null, null, null, $logMsg);
        $connectionTestFullOk = $r['rest_authenticated'] && $r['companion_summary_ok'];
        $this->sites->updateLastConnectionTest($id, $connectionTestFullOk);

        $flashType = $r['rest_authenticated'] ? 'success' : 'error';
        $flashMsg = $this->formatTestConnectionFlashMessage($r);

        if ($ajax) {
            $this->jsonResponse([
                'ok' => true,
                'site_id' => $id,
                'site_label' => $site->label ?? $site->siteUrl,
                'rest_authenticated' => $r['rest_authenticated'],
                'rest_status' => $r['rest_status'],
                'companion_summary_ok' => $r['companion_summary_ok'],
                'companion_summary_status' => $r['companion_summary_status'],
                'connection_test_full_ok' => $connectionTestFullOk,
                'message' => $flashMsg,
                'log_summary' => $logMsg,
            ]);
        }
        $this->flash($flashType, $flashMsg);
        $this->redirect('index.php?page=site_edit&id=' . $id);
    }

    private function postSyncOne(): void
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $id = (int) ($_POST['id'] ?? 0);
        $site = $this->sites->find($id);
        if ($site === null) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Site not found.'], 404);
            }
            $this->flash('error', 'Site not found.');
            $this->redirect('index.php');

            return;
        }
        $r = $this->syncOneSite($site);
        if ($r !== null) {
            $this->logs->add($id, 'sync', null, null, null, $this->formatSyncLogMessage($r));
            $flashMsg = $this->formatSyncFlashMessage($r);
            if ($ajax) {
                $this->jsonResponse([
                    'ok' => true,
                    'site_id' => $id,
                    'site_label' => $site->label ?? $site->siteUrl,
                    'last_status' => $r['last_status'],
                    'message' => $flashMsg,
                    'log_summary' => $this->formatSyncLogMessage($r),
                ]);
            }
            $this->flash('success', $flashMsg);
        } else {
            if ($ajax) {
                $this->jsonResponse([
                    'ok' => true,
                    'site_id' => $id,
                    'site_label' => $site->label ?? $site->siteUrl,
                    'last_status' => null,
                    'message' => 'Site synced.',
                    'log_summary' => null,
                ]);
            }
            $this->flash('success', 'Site synced.');
        }
        $this->redirect('index.php');
    }

    /**
     * @param array{last_status: string, pending_plugins: int, pending_themes: int, pending_core: int, active_plugins: int, inactive_plugins: int, active_themes: int, inactive_themes: int, last_error: ?string, source: string, pending_summary?: ?string} $r
     */
    private function formatSyncFlashMessage(array $r): string
    {
        if ($r['last_status'] === 'offline') {
            return 'Sync finished: offline — see activity log for the error.';
        }

        $p = (int) $r['pending_plugins'];
        $t = (int) $r['pending_themes'];
        $c = (int) $r['pending_core'];
        if ($p + $t + $c === 0) {
            return 'Sync finished: online — no pending updates. Full details in activity log.';
        }

        return sprintf(
            'Sync finished: online — %d plugin, %d theme, %d core update(s) pending. Full details in activity log.',
            $p,
            $t,
            $c
        );
    }

    /**
     * @return ?array{last_status: string, pending_plugins: int, pending_themes: int, pending_core: int, active_plugins: int, inactive_plugins: int, active_themes: int, inactive_themes: int, last_error: ?string, source: string, pending_summary?: ?string}
     */
    private function syncOneSite(Site $site): ?array
    {
        $row = $this->sites->findWithSecret((int) $site->id);
        if ($row === null) {
            return null;
        }
        $plain = $this->crypto->decrypt($row['app_password_encrypted']);

        return $this->sync->syncSite($row['site'], $plain);
    }

    private function postRunUpdates(): void
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $id = (int) ($_POST['id'] ?? 0);
        $confirm = (string) ($_POST['confirm'] ?? '');
        if ($confirm !== '1') {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Confirmation required to run updates.'], 400);
            }
            $this->flash('error', 'Confirmation required to run updates.');
            $this->redirect('index.php');

            return;
        }
        $row = $this->sites->findWithSecret($id);
        if ($row === null) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Site not found.'], 404);
            }
            $this->flash('error', 'Site not found.');
            $this->redirect('index.php');

            return;
        }
        $site = $row['site'];
        $pendingSum = (int) $site->pendingPlugins + (int) $site->pendingThemes + (int) $site->pendingCore;
        if ($pendingSum === 0) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'No pending updates for this site. Sync to refresh counts.'], 400);
            }
            $this->flash('error', 'No pending updates for this site.');
            $this->redirect('index.php');

            return;
        }
        $plain = $this->crypto->decrypt($row['app_password_encrypted']);
        $scope = (string) ($_POST['scope'] ?? 'all');
        if (! in_array($scope, ['all', 'plugins', 'themes', 'core'], true)) {
            $scope = 'all';
        }

        $result = $this->remoteUpdates->runUpdates($site, $plain, $scope);
        if ($result['ok']) {
            $this->logs->add($id, 'update_run', null, null, null, $result['message']);
            if (! $ajax) {
                $this->flash('success', $result['message']);
            }
        } else {
            $this->logs->add($id, 'update_run_failed', null, null, null, $result['message']);
            if (! $ajax) {
                $this->flash('error', $result['message']);
            }
        }
        $syncResult = $this->syncOneSite($site);
        $syncSummary = null;
        if ($syncResult !== null) {
            $this->logs->add($id, 'sync', null, null, null, $this->formatSyncLogMessage($syncResult));
            $syncSummary = $this->formatSyncLogMessage($syncResult);
        }
        if ($ajax) {
            $this->jsonResponse([
                'ok' => $result['ok'],
                'site_id' => $id,
                'site_label' => $site->label ?? $site->siteUrl,
                'message' => $result['message'],
                'after_sync_summary' => $syncSummary,
            ]);
        }
        $this->redirect('index.php');
    }

    private function postDeleteRemotePlugin(): void
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $confirm = (string) ($_POST['confirm'] ?? '');
        if ($confirm !== '1') {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Confirmation required to delete a plugin.'], 400);
            }
            $this->flash('error', 'Confirmation required to delete a plugin.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $pluginFile = $this->normalizeDashboardPluginFile((string) ($_POST['plugin_file'] ?? ''));
        if ($pluginFile === null || $this->isProtectedCompanionPluginPath($pluginFile)) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Invalid plugin file or the companion plugin cannot be removed from here.'], 400);
            }
            $this->flash('error', 'Invalid plugin file or the companion plugin cannot be removed from here.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $rawIds = (string) ($_POST['site_ids'] ?? '');
        $parts = preg_split('/[\s,]+/', trim($rawIds)) ?: [];
        $siteIdMap = [];
        foreach ($parts as $p) {
            $id = (int) $p;
            if ($id > 0) {
                $siteIdMap[$id] = true;
            }
        }
        $siteIds = array_keys($siteIdMap);
        sort($siteIds);

        if ($siteIds === []) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'No sites selected.'], 400);
            }
            $this->flash('error', 'No sites selected.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $results = [];
        $anyOk = false;
        foreach ($siteIds as $sid) {
            $row = $this->sites->findWithSecret($sid);
            if ($row === null) {
                $results[] = ['site_id' => $sid, 'ok' => false, 'message' => 'Site not found.', 'label' => ''];

                continue;
            }
            $site = $row['site'];
            if ($site->lastStatus !== 'online') {
                $results[] = [
                    'site_id' => $sid,
                    'ok' => false,
                    'message' => 'Site is offline; sync when reachable.',
                    'label' => $site->label ?? $site->siteUrl,
                ];

                continue;
            }
            $plain = $this->crypto->decrypt($row['app_password_encrypted']);
            $result = $this->remoteUpdates->deletePlugin($site, $plain, $pluginFile);
            if ($result['ok']) {
                $anyOk = true;
                $this->logs->add($sid, 'plugin_delete', $pluginFile, null, null, $result['message']);
                sleep(3);
                $syncResult = $this->syncOneSite($site);
                if ($syncResult !== null) {
                    $this->logs->add($sid, 'sync', null, null, null, $this->formatSyncLogMessage($syncResult));
                }
            } else {
                $this->logs->add($sid, 'plugin_delete_failed', $pluginFile, null, null, $result['message']);
            }
            $results[] = [
                'site_id' => $sid,
                'ok' => $result['ok'],
                'message' => $result['message'],
                'label' => $site->label ?? $site->siteUrl,
            ];
        }

        if ($ajax) {
            $this->jsonResponse([
                'ok' => $anyOk,
                'plugin_file' => $pluginFile,
                'results' => $results,
            ]);
        }

        $this->flash(
            $anyOk ? 'success' : 'error',
            $anyOk ? 'Plugin delete finished. See activity log for each site.' : 'Plugin delete failed for all selected sites.'
        );
        $this->redirect('index.php?tab=plugins');
    }

    private function postDeactivateRemotePlugin(): void
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $confirm = (string) ($_POST['confirm'] ?? '');
        if ($confirm !== '1') {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Confirmation required to deactivate a plugin.'], 400);
            }
            $this->flash('error', 'Confirmation required to deactivate a plugin.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $pluginFile = $this->normalizeDashboardPluginFile((string) ($_POST['plugin_file'] ?? ''));
        if ($pluginFile === null || $this->isProtectedCompanionPluginPath($pluginFile)) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Invalid plugin file or the companion plugin cannot be turned off from here.'], 400);
            }
            $this->flash('error', 'Invalid plugin file or the companion plugin cannot be turned off from here.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $rawIds = (string) ($_POST['site_ids'] ?? '');
        $parts = preg_split('/[\s,]+/', trim($rawIds)) ?: [];
        $siteIdMap = [];
        foreach ($parts as $p) {
            $id = (int) $p;
            if ($id > 0) {
                $siteIdMap[$id] = true;
            }
        }
        $siteIds = array_keys($siteIdMap);
        sort($siteIds);

        if ($siteIds === []) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'No sites selected.'], 400);
            }
            $this->flash('error', 'No sites selected.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $results = [];
        $anyOk = false;
        foreach ($siteIds as $sid) {
            $row = $this->sites->findWithSecret($sid);
            if ($row === null) {
                $results[] = ['site_id' => $sid, 'ok' => false, 'message' => 'Site not found.', 'label' => ''];

                continue;
            }
            $site = $row['site'];
            if ($site->lastStatus !== 'online') {
                $results[] = [
                    'site_id' => $sid,
                    'ok' => false,
                    'message' => 'Site is offline; sync when reachable.',
                    'label' => $site->label ?? $site->siteUrl,
                ];

                continue;
            }
            $plain = $this->crypto->decrypt($row['app_password_encrypted']);
            $result = $this->remoteUpdates->deactivatePlugin($site, $plain, $pluginFile);
            if ($result['ok']) {
                $anyOk = true;
                $this->logs->add($sid, 'plugin_deactivate', $pluginFile, null, null, $result['message']);
                sleep(3);
                $syncResult = $this->syncOneSite($site);
                if ($syncResult !== null) {
                    $this->logs->add($sid, 'sync', null, null, null, $this->formatSyncLogMessage($syncResult));
                }
            } else {
                $this->logs->add($sid, 'plugin_deactivate_failed', $pluginFile, null, null, $result['message']);
            }
            $results[] = [
                'site_id' => $sid,
                'ok' => $result['ok'],
                'message' => $result['message'],
                'label' => $site->label ?? $site->siteUrl,
            ];
        }

        if ($ajax) {
            $this->jsonResponse([
                'ok' => $anyOk,
                'plugin_file' => $pluginFile,
                'results' => $results,
            ]);
        }

        $this->flash(
            $anyOk ? 'success' : 'error',
            $anyOk ? 'Plugin deactivate finished. See activity log for each site.' : 'Plugin deactivate failed for all selected sites.'
        );
        $this->redirect('index.php?tab=plugins');
    }

    private function postActivateRemotePlugin(): void
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $confirm = (string) ($_POST['confirm'] ?? '');
        if ($confirm !== '1') {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Confirmation required to activate a plugin.'], 400);
            }
            $this->flash('error', 'Confirmation required to activate a plugin.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $pluginFile = $this->normalizeDashboardPluginFile((string) ($_POST['plugin_file'] ?? ''));
        if ($pluginFile === null) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Invalid plugin file.'], 400);
            }
            $this->flash('error', 'Invalid plugin file.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $rawIds = (string) ($_POST['site_ids'] ?? '');
        $parts = preg_split('/[\s,]+/', trim($rawIds)) ?: [];
        $siteIdMap = [];
        foreach ($parts as $p) {
            $id = (int) $p;
            if ($id > 0) {
                $siteIdMap[$id] = true;
            }
        }
        $siteIds = array_keys($siteIdMap);
        sort($siteIds);

        if ($siteIds === []) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'No sites selected.'], 400);
            }
            $this->flash('error', 'No sites selected.');
            $this->redirect('index.php?tab=plugins');

            return;
        }

        $results = [];
        $anyOk = false;
        foreach ($siteIds as $sid) {
            $row = $this->sites->findWithSecret($sid);
            if ($row === null) {
                $results[] = ['site_id' => $sid, 'ok' => false, 'message' => 'Site not found.', 'label' => ''];

                continue;
            }
            $site = $row['site'];
            if ($site->lastStatus !== 'online') {
                $results[] = [
                    'site_id' => $sid,
                    'ok' => false,
                    'message' => 'Site is offline; sync when reachable.',
                    'label' => $site->label ?? $site->siteUrl,
                ];

                continue;
            }
            $plain = $this->crypto->decrypt($row['app_password_encrypted']);
            $result = $this->remoteUpdates->activatePlugin($site, $plain, $pluginFile);
            if ($result['ok']) {
                $anyOk = true;
                $this->logs->add($sid, 'plugin_activate', $pluginFile, null, null, $result['message']);
                sleep(3);
                $syncResult = $this->syncOneSite($site);
                if ($syncResult !== null) {
                    $this->logs->add($sid, 'sync', null, null, null, $this->formatSyncLogMessage($syncResult));
                }
            } else {
                $this->logs->add($sid, 'plugin_activate_failed', $pluginFile, null, null, $result['message']);
            }
            $results[] = [
                'site_id' => $sid,
                'ok' => $result['ok'],
                'message' => $result['message'],
                'label' => $site->label ?? $site->siteUrl,
            ];
        }

        if ($ajax) {
            $this->jsonResponse([
                'ok' => $anyOk,
                'plugin_file' => $pluginFile,
                'results' => $results,
            ]);
        }

        $this->flash(
            $anyOk ? 'success' : 'error',
            $anyOk ? 'Plugin activate finished. See activity log for each site.' : 'Plugin activate failed for all selected sites.'
        );
        $this->redirect('index.php?tab=plugins');
    }

    private function packagePublicUrl(string $diskName): string
    {
        return $this->baseUrl() . '/plugin-packages/' . $diskName;
    }

    private function normalizeDashboardPluginFile(string $raw): ?string
    {
        $plugin_file = str_replace('\\', '/', trim($raw));
        $plugin_file = ltrim($plugin_file, '/');
        if ($plugin_file === '' || str_contains($plugin_file, '..') || str_contains($plugin_file, "\0")) {
            return null;
        }
        if (preg_match('#\.php$#i', $plugin_file) !== 1) {
            return null;
        }

        return $plugin_file;
    }

    private function isProtectedCompanionPluginPath(string $pluginFile): bool
    {
        return strtolower($pluginFile) === strtolower($this->companionPluginRelativePath());
    }

    private function companionPluginRelativePath(): string
    {
        return 's35-wp-hub/s35-wp-hub.php';
    }

    private function postUploadPluginPackage(): never
    {
        $this->assertCsrf();
        if (! isset($_FILES['plugin_zip'])) {
            $this->flash('error', 'No file uploaded.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $file = $_FILES['plugin_zip'];
        $err = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Upload failed (error code ' . $err . ').');
            $this->redirect('index.php?page=plugin_packages');
        }
        $tmp = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
        $orig = is_array($file) ? (string) ($file['name'] ?? 'package.zip') : 'package.zip';
        $size = is_array($file) ? (int) ($file['size'] ?? 0) : 0;
        $maxRaw = Config::get('plugin_package_max_bytes', 20971520);
        $max = is_int($maxRaw) ? $maxRaw : (int) $maxRaw;
        if ($max < 1) {
            $max = 20971520;
        }
        if ($size > $max) {
            $this->flash('error', 'File exceeds plugin_package_max_bytes limit.');
            $this->redirect('index.php?page=plugin_packages');
        }
        if ($tmp === '' || ! is_uploaded_file($tmp)) {
            $this->flash('error', 'Invalid upload.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 's35hub_upl_' . bin2hex(random_bytes(8)) . '.zip';
        if (! move_uploaded_file($tmp, $staging)) {
            $this->flash('error', 'Could not save upload.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $v = PluginZipInspector::validate($staging);
        if (! $v['ok']) {
            @unlink($staging);
            $this->flash('error', (string) ($v['error'] ?? 'Invalid plugin zip.'));
            $this->redirect('index.php?page=plugin_packages');
        }
        try {
            $this->pluginPackages->createFromTemp($orig, $v['slug_hint'] ?? null, $staging);
        } catch (\Throwable) {
            @unlink($staging);
            $this->flash('error', 'Could not store package.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $this->flash('success', 'Package uploaded.');
        $this->redirect('index.php?page=plugin_packages');
    }

    private function postDeletePluginPackage(): never
    {
        $this->assertCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $this->pluginPackages->delete($id);
        $this->flash('success', 'Package removed.');
        $this->redirect('index.php?page=plugin_packages');
    }

    private function postDeployPluginPackage(): never
    {
        $this->assertCsrf();
        $ajax = $this->wantsAjax();
        $packageId = (int) ($_POST['package_id'] ?? 0);
        $siteId = (int) ($_POST['site_id'] ?? 0);
        $pkg = $this->pluginPackages->find($packageId);
        if ($pkg === null) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Package not found.'], 404);
            }
            $this->flash('error', 'Package not found.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $path = $this->pluginPackages->absolutePath($pkg);
        if (! is_file($path)) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Package file missing on hub.'], 404);
            }
            $this->flash('error', 'Package file missing on hub.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $row = $this->sites->findWithSecret($siteId);
        if ($row === null) {
            if ($ajax) {
                $this->jsonResponse(['ok' => false, 'error' => 'Site not found.'], 404);
            }
            $this->flash('error', 'Site not found.');
            $this->redirect('index.php?page=plugin_packages');
        }
        $site = $row['site'];
        $plain = $this->crypto->decrypt($row['app_password_encrypted']);
        $packageUrl = $this->packagePublicUrl($pkg->diskName);
        $result = $this->pluginDeploy->installPackageFromUrl($site, $plain, $packageUrl);
        if ($result['ok']) {
            $this->logs->add($siteId, 'plugin_deploy', $pkg->originalFilename, null, null, $result['message']);
        } else {
            $this->logs->add($siteId, 'plugin_deploy_failed', $pkg->originalFilename, null, null, $result['message']);
        }
        $syncSummary = null;
        if ($result['ok']) {
            $syncResult = $this->syncOneSite($site);
            if ($syncResult !== null) {
                $this->logs->add($siteId, 'sync', null, null, null, $this->formatSyncLogMessage($syncResult));
                $syncSummary = $this->formatSyncLogMessage($syncResult);
            }
        }
        if ($ajax) {
            $this->jsonResponse([
                'ok' => $result['ok'],
                'site_id' => $siteId,
                'site_label' => $site->label ?? $site->siteUrl,
                'message' => $result['message'],
                'after_sync_summary' => $syncSummary,
            ]);
        }
        $this->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('index.php?page=plugin_packages');
    }

    private function postBuildCompanionZip(): never
    {
        $this->assertCsrf();
        $root = dirname(__DIR__);
        $r = CompanionZipBuilder::build($root);
        $this->flash($r['ok'] ? 'success' : 'error', $r['message']);
        $this->redirect('index.php?page=plugin_packages');
    }

    private function postCaptureSnapshot(): never
    {
        $this->assertCsrf();
        $synced = 0;
        $skipped = 0;
        foreach ($this->sites->all() as $site) {
            $row = $this->sites->findWithSecret((int) $site->id);
            if ($row === null) {
                ++$skipped;
                continue;
            }
            $plain = $this->crypto->decrypt($row['app_password_encrypted']);
            $r = $this->sync->syncSite($row['site'], $plain);
            if ($r['last_status'] === 'online') {
                ++$synced;
            } else {
                ++$skipped;
            }
        }
        $this->updateSnapshots->captureFleet(
            'Manual capture',
            $this->sites->all(),
            fn ($site) => $site->siteSnapshotJson
        );
        $this->flash('success', sprintf('Snapshot captured: %d sites synced, %d skipped.', $synced, $skipped));
        $this->redirect('index.php?tab=compare');
    }

    private function postCaptureUpdateSnapshot(): void
    {
        $this->assertCsrf();
        $batchId = $this->updateSnapshots->captureFleet(
            'Pre-update capture',
            $this->sites->all(),
            fn ($site) => $site->siteSnapshotJson
        );
        if ($this->wantsAjax()) {
            $this->jsonResponse([
                'ok' => true,
                'batch_id' => $batchId,
                'message' => 'Pre-update snapshot captured.',
            ]);
        }
        $this->flash('success', 'Pre-update snapshot captured.');
        $this->redirect('index.php?tab=compare');
    }

    private function postDeleteSnapshot(): never
    {
        $this->assertCsrf();
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        if ($batchId > 0) {
            $this->updateSnapshots->deleteBatch($batchId);
            $this->flash('success', 'Snapshot deleted.');
        }
        $this->redirect('index.php?tab=compare');
    }

    private function postDeleteSnapshots(): never
    {
        $this->assertCsrf();
        $rawIds = $_POST['batch_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $batchIds = [];
        foreach ($rawIds as $p) {
            $id = (int) $p;
            if ($id > 0) {
                $batchIds[] = $id;
            }
        }

        if ($batchIds === []) {
            $this->flash('error', 'No snapshots selected.');
            $this->redirect('index.php?tab=compare');
        }

        $deleted = 0;
        foreach ($batchIds as $batchId) {
            $this->updateSnapshots->deleteBatch($batchId);
            ++$deleted;
        }

        $this->flash('success', sprintf('%d snapshot(s) deleted.', $deleted));
        $this->redirect('index.php?tab=compare');
    }
}
