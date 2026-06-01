<?php

declare(strict_types=1);

use S35WpHub\Model\Site;
use S35WpHub\Util\MixedText;
use S35WpHub\Version;
use S35WpHub\View;

/** @var list<Site> $sites */
/** @var string $csrf */
/** @var string $sites_tab */
/** @var list<array<string, mixed>> $fleet_plugins */

$sites_tab = $sites_tab ?? 'sites';
$fleet_plugins = $fleet_plugins ?? [];

$hubAnyOnlinePendingUpdates = false;
foreach ($sites as $_s) {
    if ($_s->lastStatus === 'online') {
        $hubPending = (int) $_s->pendingPlugins + (int) $_s->pendingThemes + (int) $_s->pendingCore;
        if ($hubPending > 0) {
            $hubAnyOnlinePendingUpdates = true;
            break;
        }
    }
}
$hubUpdateAllDisabled = $sites === [] || ! $hubAnyOnlinePendingUpdates;
$hubUpdateAllTitle = $sites === []
    ? ''
    : (! $hubAnyOnlinePendingUpdates ? 'No online sites have pending updates. Sync to refresh counts.' : '');
?>
<div class="page-head">
    <h1>Sites <span class="muted small" style="font-weight:normal">(<?= (int) ($snapshot_pair_count ?? 0) ?> snapshots)</span></h1>
    <div class="page-head-actions">
        <button type="button" class="btn primary" id="hub-sync-all-btn" <?= $sites === [] ? ' disabled' : '' ?>>Sync all</button>
        <button type="button" class="btn" id="hub-test-all-btn" <?= $sites === [] ? ' disabled' : '' ?>>Test connections</button>
        <button type="button" class="btn danger" id="hub-update-all-btn"<?= $hubUpdateAllDisabled ? ' disabled' : '' ?><?= $hubUpdateAllTitle !== '' ? ' title="' . View::e($hubUpdateAllTitle) . '"' : '' ?>>Update all sites</button>
        <a href="index.php?page=export_csv" class="btn" title="Export full site inventory to CSV">Export CSV</a>
    </div>
</div>

<nav class="hub-site-tabs" aria-label="Sites views">
    <a href="index.php" class="hub-site-tab<?= $sites_tab === 'sites' ? ' hub-site-tab--active' : '' ?>">Sites</a>
    <a href="index.php?tab=plugins" class="hub-site-tab<?= $sites_tab === 'plugins' ? ' hub-site-tab--active' : '' ?>">Plugins</a>
    <a href="index.php?tab=compare" class="hub-site-tab<?= $sites_tab === 'compare' ? ' hub-site-tab--active' : '' ?>">Compare</a>
    <a href="index.php?tab=activity" class="hub-site-tab<?= $sites_tab === 'activity' ? ' hub-site-tab--active' : '' ?>">Activity log</a>
</nav>

<div id="hub-progress-overlay" class="hub-progress-overlay" hidden aria-hidden="true">
    <div class="hub-progress-modal" role="dialog" aria-labelledby="hub-progress-title" aria-modal="true">
        <h2 id="hub-progress-title" class="hub-progress-title">Working…</h2>
        <div class="hub-progress-bar-wrap">
            <div class="hub-progress-bar" id="hub-progress-bar"></div>
        </div>
        <p class="hub-progress-status" id="hub-progress-status"></p>
        <div class="hub-progress-log" id="hub-progress-log"></div>
        <button type="button" class="btn primary hub-progress-done" id="hub-progress-close" hidden>Reload page</button>
    </div>
</div>

<?php if ($sites !== []) : ?>
<script type="application/json" id="hub-sites-json"><?= json_encode(
    array_map(
        static fn (Site $s) => [
            'id' => $s->id,
            'label' => $s->label !== null && $s->label !== '' ? $s->label : $s->siteUrl,
            'last_status' => $s->lastStatus,
            'pending_total' => (int) $s->pendingPlugins + (int) $s->pendingThemes + (int) $s->pendingCore,
        ],
        $sites
    ),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<?php endif; ?>

<?php if ($sites_tab === 'sites') : ?>

<?php if ($sites === []) : ?>
    <p class="muted">No sites yet. <a href="index.php?page=site_new">Add your first WordPress site</a>.</p>
<?php else : ?>
<table class="grid hub-sites-grid">
    <thead>
    <tr>
        <th>Label / URL</th>
        <th>Owner</th>
        <th>Status</th>
        <th>Plugins</th>
        <th>Themes</th>
        <th>Core</th>
        <th>WPvivid backup</th>
        <th>Last sync</th>
        <th class="actions">Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($sites as $s) : ?>
        <?php
        $det = $s->pendingUpdatesDetail();
        $src = '';
        $pItems = [];
        $tItems = [];
        $coreIt = null;
        if (is_array($det)) {
            $src = (string) ($det['source'] ?? '');
            $pItems = is_array($det['plugin_items'] ?? null) ? $det['plugin_items'] : [];
            $tItems = is_array($det['theme_items'] ?? null) ? $det['theme_items'] : [];
            $coreIt = $det['core_item'] ?? null;
        }
        $hasDetail = $pItems !== [] || $tItems !== [] || (is_array($coreIt) && $coreIt !== []);
        $pendingTotal = (int) $s->pendingPlugins + (int) $s->pendingThemes + (int) $s->pendingCore;
        $snap = $s->siteSnapshot();
        $phpSnapshot = is_array($snap) ? MixedText::toPlainString($snap['php_version'] ?? null) : '';
        if (strcasecmp($phpSnapshot, 'Array') === 0) {
            $phpSnapshot = '';
        }
        $hubPluginVer = is_array($snap) ? MixedText::toPlainString($snap['companion_version'] ?? null) : '';
        $hubExpect = Version::COMPANION_PLUGIN_EXPECTED;
        $hubSegment = 'Hub plugin —';
        $hubPluginStale = false;
        $hubPluginOk = false;
        if ($hubPluginVer !== '') {
            $cmp = version_compare($hubPluginVer, $hubExpect);
            if ($cmp >= 0) {
                $hubSegment = 'Hub plugin ' . $hubPluginVer . ' ✓';
                $hubPluginOk = true;
            } else {
                $hubSegment = 'Hub plugin ' . $hubPluginVer . ' (update to ' . $hubExpect . '+)';
                $hubPluginStale = true;
            }
        }
        $int = is_array($snap) ? ($snap['integration_status'] ?? null) : null;
        $settingsSegment = '';
        $integrationStale = false;
        $integrationOk = false;
        if (is_array($int)) {
            if (! ($int['api_reachable'] ?? false)) {
                $settingsSegment = 'Settings: update companion to ' . $hubExpect . '+ to verify wp-config / hosts';
                $integrationStale = true;
            } elseif (! ($int['hub_base_url_set'] ?? false)) {
                $settingsSegment = 'Settings: set base_url in dashboard config.php';
                $integrationStale = true;
            } elseif ($int['fleet_deploy_ok'] ?? false) {
                $settingsSegment = 'Settings ✓';
                $integrationOk = true;
                if (($int['manifest_configured'] ?? false) && ! ($int['manifest_matches_hub'] ?? false)) {
                    $settingsSegment .= ' · manifest host ≠ hub base_url';
                }
            } else {
                $hints = [];
                if (! ($int['manifest_configured'] ?? false)) {
                    $hints[] = 'set S35_WP_HUB_UPDATE_MANIFEST_URL';
                }
                if ($int['package_allowlist_empty'] ?? true) {
                    $hints[] = 'no package allowlist (add S35_WP_HUB_ALLOWED_PACKAGE_HOSTS or manifest on hub host)';
                } elseif (($int['hub_host'] ?? '') !== '') {
                    $hints[] = 'hub host "' . MixedText::toPlainString($int['hub_host']) . '" not allowlisted for packages';
                }
                $settingsSegment = 'Settings: ' . ($hints !== [] ? implode('; ', $hints) : 'fleet deploy blocked') . ' — see README / Settings';
                $integrationStale = true;
            }
        }
        $hubSettingsLine = $hubSegment;
        if ($settingsSegment !== '') {
            $hubSettingsLine = $hubSegment . ' | ' . $settingsSegment;
        }
        $hubRowClasses = 'small site-status-hub';
        if ($hubPluginStale || $integrationStale) {
            $hubRowClasses .= ' site-status-hub--stale';
        } elseif ($integrationOk || ($settingsSegment === '' && $hubPluginOk)) {
            if ($hubPluginVer === '' && $settingsSegment === '') {
                $hubRowClasses .= ' muted';
            } else {
                $hubRowClasses .= ' site-status-hub--ok';
            }
        } else {
            $hubRowClasses .= ' muted';
        }
        $frontUrl = $s->siteUrl;
        $adminUrl = rtrim($s->siteUrl, '/') . '/wp-admin/';
        $quickLinksOk = preg_match('#^https?://#i', $frontUrl) === 1;

        $wpvTitle = '';
        $wpvCell = 'N/A';
        $wpvClass = 'hub-wpvivid-backup hub-wpvivid-backup--na';
        $wpvMissingBackupHtml = false;
        $wpvEditionLabelForMissing = '';
        $wpvSnap = is_array($snap) ? ($snap['wpvivid_backup'] ?? null) : null;
        if (is_array($wpvSnap) && array_key_exists('active', $wpvSnap) && $wpvSnap['active']) {
            $ed = isset($wpvSnap['edition']) && is_string($wpvSnap['edition']) ? $wpvSnap['edition'] : '';
            $edLabel = $ed === 'pro' ? 'Pro' : ($ed === 'free' ? 'Free' : 'WPvivid');
            $unix = isset($wpvSnap['last_success_unix']) && is_numeric($wpvSnap['last_success_unix'])
                ? (int) $wpvSnap['last_success_unix'] : null;
            if ($unix === null || $unix <= 0) {
                $at = $wpvSnap['last_success_at'] ?? null;
                if (is_string($at) && $at !== '') {
                    $parsed = strtotime($at);
                    if ($parsed !== false) {
                        $unix = $parsed;
                    }
                }
            }
            if ($unix === null || $unix <= 0) {
                $wpvMissingBackupHtml = true;
                $wpvEditionLabelForMissing = $edLabel;
                $wpvClass = 'hub-wpvivid-backup hub-wpvivid-backup--no-record';
            } else {
                $staleSeconds = 7 * 86400;
                $age = time() - $unix;
                $dateStr = gmdate('Y-m-d H:i', $unix) . ' UTC';
                if ($age > $staleSeconds) {
                    $wpvCell = $edLabel . ': ' . $dateStr . ' (overdue)';
                    $wpvClass = 'hub-wpvivid-backup hub-wpvivid-backup--stale';
                } else {
                    $wpvCell = $edLabel . ': ' . $dateStr;
                    $wpvClass = 'hub-wpvivid-backup hub-wpvivid-backup--ok';
                }
            }
        } elseif (is_array($wpvSnap) && array_key_exists('active', $wpvSnap) && ! $wpvSnap['active']) {
            $wpvCell = 'N/A';
            $wpvTitle = 'WPvivid Backup (free or Pro) is not active on this site.';
        } elseif ($wpvSnap === null) {
            $wpvCell = 'N/A';
            $wpvTitle = 'Run Sync after installing companion ' . Version::COMPANION_PLUGIN_EXPECTED . '+ to load WPvivid status.';
        }
        ?>
        <tr>
            <td>
                <div class="strong"><?= View::e($s->label ?? $s->siteUrl) ?></div>
                <div class="site-url-row muted small">
                    <span class="site-url-text"><?= View::e($s->siteUrl) ?></span>
                    <?php if ($quickLinksOk) : ?>
                        <span class="site-quick-links">
                            <a class="site-quick-link" href="<?= View::e($frontUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open website" aria-label="Open website">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                            </a>
                            <a class="site-quick-link" href="<?= View::e($adminUrl) ?>" target="_blank" rel="noopener noreferrer" title="WordPress admin" aria-label="WordPress admin">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M3 5v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2H5c-1.11 0-2 .9-2 2zm12 4c0 1.66-1.34 3-3 3s-3-1.34-3-3 1.34-3 3-3 3 1.34 3 3zM6 19c0-2 4-3.1 6-3.1s6 1.1 6 3.1v1H6v-1z"/></svg>
                            </a>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($s->lastError !== null && $s->lastError !== '') : ?>
                    <div class="hint"><?= View::e($s->lastError) ?></div>
                <?php endif; ?>
            </td>
            <td class="muted small"><?= $s->ownerDisplayName !== null ? View::e($s->ownerDisplayName) : '—' ?></td>
            <td>
                <div class="site-status-cell">
                    <span class="pill pill-<?= View::e($s->lastStatus) ?><?= $s->lastStatus === 'online' && ($s->lastConnectionTestOk !== null) ? ' pill--with-connection-test' : '' ?>">
                        <?= View::e($s->lastStatus) ?>
                        <?php if ($s->lastStatus === 'online' && $s->lastConnectionTestOk === true && $pendingTotal > 0) : ?>
                            <abbr class="site-connection-test-mark site-connection-test-mark--updates" title="Online and reachable — <?= (int) $pendingTotal ?> pending update(s) (plugins, themes, or core). Run Update or Sync after changes.">UPDATES</abbr>
                        <?php elseif ($s->lastStatus === 'online' && $s->lastConnectionTestOk === true) : ?>
                            <abbr class="site-connection-test-mark site-connection-test-mark--ok" title="Last connection test: OK (REST auth and companion summary)">✓</abbr>
                        <?php elseif ($s->lastStatus === 'online' && $s->lastConnectionTestOk === false) : ?>
                            <abbr class="site-connection-test-mark site-connection-test-mark--bad" title="Last connection test failed or companion endpoint missing — run Test connection">✗</abbr>
                        <?php endif; ?>
                    </span>
                    <div class="muted small site-status-php"><?= $phpSnapshot !== '' ? 'PHP ' . View::e($phpSnapshot) : 'PHP —' ?></div>
                    <div class="<?= View::e($hubRowClasses) ?>"><?= View::e($hubSettingsLine) ?></div>
                </div>
            </td>
            <td><?= (int) $s->activePlugins ?> / <?= (int) $s->inactivePlugins ?></td>
            <td><?= (int) $s->activeThemes ?> / <?= (int) $s->inactiveThemes ?></td>
            <td><?= (int) $s->pendingCore ?></td>
            <td class="small">
                <span class="<?= View::e($wpvClass) ?>"<?= $wpvTitle !== '' ? ' title="' . View::e($wpvTitle) . '"' : '' ?>>
                    <?php if ($wpvMissingBackupHtml) : ?>
                        <?= View::e($wpvEditionLabelForMissing) ?>: <span class="hub-wpvivid-backup__missing"><?= View::e('No successful backup recorded') ?></span>
                    <?php else : ?>
                        <?= View::e($wpvCell) ?>
                    <?php endif; ?>
                </span>
            </td>
            <td class="muted small"><?= View::e($s->lastSyncAt ?? '—') ?></td>
            <td class="actions">
                <form method="post" action="index.php" class="stack-form hub-sync-one-form">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="sync_one">
                    <input type="hidden" name="id" value="<?= (int) $s->id ?>">
                    <button type="submit" class="btn small">Sync</button>
                </form>
                <?php
                $canHubUpdate = $s->lastStatus === 'online' && $pendingTotal > 0;
                $hubUpdateTitle = ! $canHubUpdate
                    ? ($s->lastStatus !== 'online'
                        ? 'Site is offline. Sync when the site is reachable.'
                        : ($pendingTotal === 0 ? 'No pending updates. Sync to refresh counts.' : ''))
                    : '';
                ?>
                <form method="post" action="index.php" class="stack-form hub-update-form">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="run_updates">
                    <input type="hidden" name="id" value="<?= (int) $s->id ?>">
                    <input type="hidden" name="confirm" value="1">
                    <input type="hidden" name="scope" value="all">
                    <button type="submit" class="btn small danger"<?= ! $canHubUpdate ? ' disabled' : '' ?><?= $hubUpdateTitle !== '' ? ' title="' . View::e($hubUpdateTitle) . '"' : '' ?>>Update</button>
                </form>
                <a class="btn small link" href="index.php?page=site_edit&id=<?= (int) $s->id ?>">Edit</a>
                <form method="post" action="index.php" class="stack-form"
                      onsubmit="return confirm('Remove this site from the dashboard?');">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="delete_site">
                    <input type="hidden" name="id" value="<?= (int) $s->id ?>">
                    <button type="submit" class="btn small ghost">Remove</button>
                </form>
            </td>
        </tr>
        <?php if ($s->lastStatus === 'online' && $pendingTotal > 0) : ?>
        <tr class="pending-subrow">
            <td colspan="8" class="pending-cell">
                <div class="pending-head">Pending updates (last sync)</div>
                <?php if ($hasDetail) : ?>
                    <p class="muted small pending-source">
                        <?php if ($src === 'companion') : ?>
                            List matches WordPress’s update queue (same source as <strong>Dashboard → Updates</strong>).
                        <?php else : ?>
                            List from WordPress REST (fallback). Install the latest <strong>s35-wp-hub</strong> companion plugin for the exact same list as core’s update transients.
                        <?php endif; ?>
                    </p>
                    <?php if ($pItems !== []) : ?>
                        <div class="pending-group"><span class="pending-label">Plugins</span>
                            <ul class="pending-list">
                                <?php foreach ($pItems as $pi) :
                                    if (! is_array($pi)) {
                                        continue;
                                    }
                                    $pn = View::e((string) ($pi['name'] ?? $pi['file'] ?? 'Plugin'));
                                    $nv = (string) ($pi['new_version'] ?? '');
                                    ?>
                                    <li><?= $pn ?><?= $nv !== '' ? ' <span class="muted">→ v' . View::e($nv) . '</span>' : '' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($tItems !== []) : ?>
                        <div class="pending-group"><span class="pending-label">Themes</span>
                            <ul class="pending-list">
                                <?php foreach ($tItems as $ti) :
                                    if (! is_array($ti)) {
                                        continue;
                                    }
                                    $tn = View::e((string) ($ti['name'] ?? $ti['stylesheet'] ?? 'Theme'));
                                    $nv = (string) ($ti['new_version'] ?? '');
                                    ?>
                                    <li><?= $tn ?><?= $nv !== '' ? ' <span class="muted">→ v' . View::e($nv) . '</span>' : '' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (is_array($coreIt) && (($coreIt['version'] ?? '') !== '' || ($coreIt['current'] ?? '') !== '')) : ?>
                        <div class="pending-group"><span class="pending-label">Core</span>
                            <ul class="pending-list">
                                <li>
                                    <?= View::e((string) ($coreIt['current'] ?? 'WordPress')) ?>
                                    <?php if (($coreIt['version'] ?? '') !== '') : ?>
                                        <span class="muted"> → <?= View::e((string) $coreIt['version']) ?></span>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </div>
                    <?php elseif ((int) $s->pendingCore > 0) : ?>
                        <div class="pending-group"><span class="pending-label">Core</span>
                            <p class="muted small">WordPress core update reported; re-sync if version numbers are missing.</p>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="muted small">Counts show <?= (int) $pendingTotal ?> pending update(s), but no item list is stored yet. <strong>Sync</strong> again after uploading the current <code>plugin/s35-wp-hub</code> zip to this site so the dashboard can list names (matches what WordPress tracks internally).</p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php elseif ($sites_tab === 'activity') : ?>

<section class="activity-section">
    <h2>Activity log</h2>
    <p class="muted small">Sync, update runs, and refresh-after-update are recorded here with a short summary.</p>

    <form method="get" action="index.php" class="activity-filter-form">
        <input type="hidden" name="tab" value="activity">
        <label class="activity-filter-label">
            <span class="muted small">From</span>
            <input type="date" name="date_from" class="input" value="<?= View::e($activity_date_from ?? '') ?>">
        </label>
        <label class="activity-filter-label">
            <span class="muted small">To</span>
            <input type="date" name="date_to" class="input" value="<?= View::e($activity_date_to ?? '') ?>">
        </label>
        <label class="activity-filter-label">
            <span class="muted small">Search</span>
            <input type="search" name="search" class="input activity-search-input" placeholder="Site name, URL, or details…" value="<?= View::e($activity_search ?? '') ?>">
        </label>
        <label class="activity-filter-label">
            <span class="muted small">Action</span>
            <select name="action_filter" class="input activity-action-select">
                <option value=""<?= ($activity_action_filter ?? '') === '' ? ' selected' : '' ?>>All</option>
                <option value="sync"<?= ($activity_action_filter ?? '') === 'sync' ? ' selected' : '' ?>>Sync</option>
                <option value="update_run"<?= ($activity_action_filter ?? '') === 'update_run' ? ' selected' : '' ?>>Update run</option>
                <option value="test_connection"<?= ($activity_action_filter ?? '') === 'test_connection' ? ' selected' : '' ?>>Connection test</option>
                <option value="plugin_deploy"<?= ($activity_action_filter ?? '') === 'plugin_deploy' ? ' selected' : '' ?>>Plugin deploy</option>
                <option value="plugin_activate"<?= ($activity_action_filter ?? '') === 'plugin_activate' ? ' selected' : '' ?>>Plugin activate</option>
                <option value="plugin_deactivate"<?= ($activity_action_filter ?? '') === 'plugin_deactivate' ? ' selected' : '' ?>>Plugin deactivate</option>
                <option value="plugin_delete"<?= ($activity_action_filter ?? '') === 'plugin_delete' ? ' selected' : '' ?>>Plugin delete</option>
            </select>
        </label>
        <button type="submit" class="btn small">Filter</button>
        <a href="index.php?tab=activity" class="btn small">Clear</a>
    </form>

    <?php if (($activity_logs ?? []) === []) : ?>
        <p class="muted">No activity found. Use <strong>Sync</strong>, <strong>Test connections</strong>, or <strong>Update</strong> on a site.</p>
    <?php else : ?>
    <div class="activity-wrap">
        <table class="grid activity-grid">
            <thead>
            <tr>
                <th>When</th>
                <th>Site</th>
                <th>Action</th>
                <th>Details</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($activity_logs as $log) :
                $action = (string) ($log['action'] ?? '');
                $actionLabel = match ($action) {
                    'sync' => 'Sync',
                    'test_connection' => 'Connection test',
                    'update_run' => 'Update run',
                    'update_run_failed' => 'Update run (failed)',
                    'plugin_delete' => 'Plugin delete',
                    'plugin_delete_failed' => 'Plugin delete (failed)',
                    'plugin_deactivate' => 'Plugin deactivate',
                    'plugin_deactivate_failed' => 'Plugin deactivate (failed)',
                    'plugin_activate' => 'Plugin activate',
                    'plugin_activate_failed' => 'Plugin activate (failed)',
                    'plugin_deploy' => 'Plugin deploy',
                    'plugin_deploy_failed' => 'Plugin deploy (failed)',
                    default => $action !== '' ? $action : '—',
                };
                $siteLabel = trim((string) ($log['label'] ?? ''));
                if ($siteLabel === '') {
                    $siteLabel = (string) ($log['site_url'] ?? '');
                }
                $logId = (int) ($log['id'] ?? 0);
                ?>
                <tr>
                    <td class="muted small nowrap"><?= View::e((string) ($log['created_at'] ?? '')) ?></td>
                    <td class="small"><?= View::e($siteLabel) ?></td>
                    <td><span class="pill pill-action"><?= View::e($actionLabel) ?></span></td>
                    <td class="small log-detail"><?= View::e((string) ($log['message'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php elseif ($sites_tab === 'plugins') : ?>

<?php if ($sites === []) : ?>
    <p class="muted">No sites yet. <a href="index.php?page=site_new">Add your first WordPress site</a>.</p>
<?php else : ?>
    <p class="muted small hub-fleet-intro">Aggregated from each site’s last sync. Requires companion <?= View::e(Version::COMPANION_PLUGIN_EXPECTED) ?>+ on WordPress and a successful <strong>Sync</strong>.</p>
    <div class="hub-fleet-toolbar">
        <label class="hub-fleet-search-label">
            <span class="muted small">Search</span>
            <input type="search" id="hub-fleet-plugin-search" class="input hub-fleet-search-input" placeholder="Plugin name, file, version…" autocomplete="off">
        </label>
        <label class="hub-fleet-filter-label">
            <span class="muted small">Site</span>
            <select id="hub-fleet-plugin-site-filter" class="input hub-fleet-site-select" aria-label="Filter plugins by site">
                <option value="all" selected>All sites</option>
                <?php foreach ($sites as $s) : ?>
                    <?php $siteLabel = ($s->label !== null && $s->label !== '') ? $s->label : $s->siteUrl; ?>
                    <option value="<?= (int) $s->id ?>"><?= View::e($siteLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="hub-fleet-filter-label">
            <span class="muted small">Show</span>
            <select id="hub-fleet-plugin-status-filter" class="input hub-fleet-status-select" aria-label="Filter plugins by activation status">
                <option value="all" selected>All plugins</option>
                <option value="active">Active on any site</option>
                <option value="inactive">Inactive on any site</option>
            </select>
        </label>
    </div>
    <?php if ($fleet_plugins === []) : ?>
        <p class="muted">No plugin inventory stored yet. Upgrade the companion plugin, then sync each site.</p>
    <?php else : ?>
<script type="application/json" id="hub-fleet-plugins-json"><?= json_encode(
    array_values($fleet_plugins),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<table class="grid" id="hub-fleet-plugins-table">
    <thead>
    <tr>
        <th>Plugin</th>
        <th>Version</th>
        <th>Sites</th>
        <th class="actions">Deactivate</th>
        <th class="actions">Remove</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($fleet_plugins as $fi => $frow) :
        $fname = (string) ($frow['name'] ?? '');
        $ffile = (string) ($frow['file'] ?? '');
        $fvLabel = (string) ($frow['version_label'] ?? '—');
        $fcount = (int) ($frow['site_count'] ?? 0);
        $fwpOrg = (string) ($frow['wp_org_url'] ?? '');
        $fauthor = (string) ($frow['author'] ?? '');
        $fauthorUri = (string) ($frow['author_uri'] ?? '');
        $searchBlob = strtolower($fname . ' ' . $ffile . ' ' . $fvLabel . ' ' . $fauthor);
        $fsites = $frow['sites'] ?? [];
        $fleetHasActive = false;
        $fleetHasInactive = false;
        $fleetActiveCount = 0;
        $fleetInactiveCount = 0;
        if (is_array($fsites)) {
            foreach ($fsites as $fs) {
                if (! is_array($fs)) {
                    continue;
                }
                if (! empty($fs['active'])) {
                    $fleetHasActive = true;
                    ++$fleetActiveCount;
                } else {
                    $fleetHasInactive = true;
                    ++$fleetInactiveCount;
                }
            }
        }
        ?>
        <tr class="hub-fleet-plugin-row" data-fleet-row-index="<?= (int) $fi ?>" data-search="<?= View::e($searchBlob) ?>" data-fleet-has-active="<?= $fleetHasActive ? '1' : '0' ?>" data-fleet-has-inactive="<?= $fleetHasInactive ? '1' : '0' ?>" data-fleet-active="<?= (int) $fleetActiveCount ?>" data-fleet-inactive="<?= (int) $fleetInactiveCount ?>">
            <td>
                <div class="strong"><?= View::e($fname) ?></div>
                <div class="muted small"><code><?= View::e($ffile) ?></code></div>
                <?php if ($fwpOrg !== '' || $fauthorUri !== '') : ?>
                <div class="hub-fleet-plugin-links muted small">
                    <?php if ($fwpOrg !== '') : ?>
                        <a href="<?= View::e($fwpOrg) ?>" target="_blank" rel="noopener noreferrer" class="hub-fleet-plugin-info-link" title="Plugin directory slug from folder name; may not exist for non–WordPress.org plugins">WordPress.org</a>
                    <?php endif; ?>
                    <?php if ($fauthorUri !== '' && $fauthorUri !== $fwpOrg) : ?>
                        <?php if ($fwpOrg !== '') : ?>
                            <span class="hub-fleet-plugin-links__sep" aria-hidden="true"> · </span>
                        <?php endif; ?>
                        <a href="<?= View::e($fauthorUri) ?>" target="_blank" rel="noopener noreferrer" class="hub-fleet-plugin-info-link"><?= View::e($fauthor !== '' ? $fauthor : 'Author') ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </td>
            <td><?= View::e($fvLabel) ?></td>
            <td>
                <button type="button" class="btn small link hub-fleet-sites-btn" data-fleet-row="<?= (int) $fi ?>"><?= (int) $fleetActiveCount ?> / <?= (int) $fleetInactiveCount ?></button>
            </td>
            <td class="actions">
                <?php
                $isHubSelf = strtolower($ffile) === 's35-wp-hub/s35-wp-hub.php';
                ?>
                <button type="button" class="btn small hub-fleet-deactivate-all-btn"
                        data-fleet-row="<?= (int) $fi ?>"
                    <?= $isHubSelf ? ' disabled title="The companion plugin must stay active for the dashboard."' : '' ?>>
                    All sites
                </button>
            </td>
            <td class="actions">
                <button type="button" class="btn small danger hub-fleet-delete-all-btn"
                        data-fleet-row="<?= (int) $fi ?>"
                    <?= $isHubSelf ? ' disabled title="The companion plugin cannot be removed from the dashboard."' : '' ?>>
                    All sites
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<dialog id="hub-fleet-plugin-dialog" class="hub-dialog" aria-labelledby="hub-fleet-dialog-title">
    <div class="hub-dialog-panel">
        <button type="button" class="hub-dialog-close btn small ghost" aria-label="Close">&times;</button>
        <h2 id="hub-fleet-dialog-title" class="hub-dialog-title"></h2>
        <div id="hub-fleet-dialog-sub" class="hub-fleet-dialog-sub muted small" hidden></div>
        <div id="hub-fleet-dialog-body" class="hub-dialog-body"></div>
    </div>
</dialog>
    <?php endif; ?>
<?php endif; ?>

<?php elseif ($sites_tab === 'compare') : ?>

<section class="compare-section">
    <h2>Compare Snapshots</h2>
    <p class="muted small">Select a snapshot to compare the entire fleet's current state against that point in time. All sites, plugins, and themes are shown with their match status.</p>

    <div class="compare-toolbar">
        <form method="get" action="index.php" class="compare-form-inline">
            <input type="hidden" name="tab" value="compare">
            <label class="compare-label-inline">
                <span class="muted small">Website</span>
                <select name="site_id" class="input compare-select" onchange="this.form.submit()">
                    <option value="0">All sites</option>
                    <?php foreach ($sites as $s) : ?>
                        <?php $siteLabel = ($s->label !== null && $s->label !== '') ? $s->label : $s->siteUrl; ?>
                        <option value="<?= (int) $s->id ?>"<?= (int) $s->id === ($selected_site_id ?? 0) ? ' selected' : '' ?>>
                            <?= View::e($siteLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="compare-label-inline">
                <span class="muted small">Snapshot</span>
                <select name="batch_id" class="input compare-select" onchange="this.form.submit()">
                    <option value="0">— Select a snapshot —</option>
                    <?php foreach ($compare_snapshots as $cs) : ?>
                        <option value="<?= (int) $cs['id'] ?>"<?= (int) $cs['id'] === ($selected_batch_id ?? 0) ? ' selected' : '' ?>>
                            <?= View::e($cs['created_at']) ?> — <?= (int) $cs['site_count'] ?> site(s)<?= $cs['label'] !== '' ? ' (' . View::e($cs['label']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="compare-label-inline">
                <span class="muted small">Results</span>
                <select name="result_filter" class="input compare-select" onchange="this.form.submit()">
                    <option value=""<?= ($compare_result_filter ?? '') === '' ? ' selected' : '' ?>>All</option>
                    <option value="no_change"<?= ($compare_result_filter ?? '') === 'no_change' ? ' selected' : '' ?>>No change</option>
                    <option value="changed"<?= ($compare_result_filter ?? '') === 'changed' ? ' selected' : '' ?>>Changed</option>
                </select>
            </label>
        </form>
        <form method="post" action="index.php" class="compare-capture-form">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="capture_snapshot">
            <button type="submit" class="btn small">Capture now</button>
        </form>
        <a href="index.php?tab=compare" class="btn small">Clear</a>
        <?php if (($selected_batch_id ?? 0) > 0) : ?>
        <form method="post" action="index.php" class="compare-capture-form" onsubmit="return confirm('Delete this snapshot? This cannot be undone.');">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="delete_snapshot">
            <input type="hidden" name="batch_id" value="<?= (int) $selected_batch_id ?>">
            <button type="submit" class="btn small danger">Delete snapshot</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($compare_snapshots !== []) : ?>
    <section class="compare-manage-section">
        <h3>Manage Snapshots</h3>
        <div class="compare-manage-toolbar">
            <button type="button" class="btn small danger" id="hub-delete-snapshots-btn" disabled onclick="hubDeleteSelectedSnapshots()">Delete selected</button>
            <span class="muted small" id="hub-snapshot-count">(0 selected)</span>
        </div>
        <table class="grid compare-manage-table">
            <thead>
            <tr>
                <th class="chk"><input type="checkbox" id="hub-snapshot-select-all"></th>
                <th>Date</th>
                <th>Label</th>
                <th>Sites</th>
                <th class="actions">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($compare_snapshots as $cs) : ?>
                <tr>
                    <td class="chk"><input type="checkbox" class="hub-snapshot-checkbox" data-batch-id="<?= (int) $cs['id'] ?>"></td>
                    <td>
                        <a href="index.php?tab=compare&batch_id=<?= (int) $cs['id'] ?>" class="compare-manage-link"><?= View::e($cs['created_at']) ?></a>
                    </td>
                    <td><?= View::e($cs['label'] !== '' ? $cs['label'] : '—') ?></td>
                    <td><?= (int) $cs['site_count'] ?></td>
                    <td class="actions">
                        <a href="index.php?tab=compare&batch_id=<?= (int) $cs['id'] ?>" class="btn small link">Compare</a>
                        <form method="post" action="index.php" class="inline-form" onsubmit="return confirm('Delete this snapshot? This cannot be undone.');">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="action" value="delete_snapshot">
                            <input type="hidden" name="batch_id" value="<?= (int) $cs['id'] ?>">
                            <button type="submit" class="btn small ghost">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if (($compare_deltas ?? []) !== [] && ($selected_batch_id ?? 0) > 0) : ?>
        <?php
        $sameCount = 0;
        $diffCount = 0;
        foreach ($compare_deltas as $cd) {
            if ($cd['status'] === 'same') { ++$sameCount; } else { ++$diffCount; }
        }
        ?>
        <div class="compare-stats">
            <span class="compare-stat-same"><?= $sameCount ?> same</span>
            <span class="compare-stat-diff"><?= $diffCount ?> different</span>
            <span class="muted small"><?= count($compare_deltas) ?> total items from <?= ($selected_site_id ?? 0) > 0 ? 1 : (int) ($compare_snapshots[0]['site_count'] ?? 0) ?> site(s) at <?= View::e($compare_batch_label) ?></span>
        </div>
        <table class="grid compare-delta-table">
            <thead>
            <tr>
                <th>Site</th>
                <th>Type</th>
                <th>Name</th>
                <th>Version</th>
                <th>Status</th>
                <th>Result</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($compare_deltas as $delta) : ?>
                <tr class="compare-row-<?= View::e($delta['status']) ?>">
                    <td class="small">
                        <a href="<?= View::e($delta['site_url']) ?>" target="_blank" rel="noopener noreferrer" class="compare-site-link"><?= View::e($delta['site_label']) ?></a>
                    </td>
                    <td>
                        <span class="compare-type-badge compare-type-<?= View::e($delta['type']) ?>"><?= ucfirst(View::e($delta['type'])) ?></span>
                    </td>
                    <td>
                        <span class="diff-name"><?= View::e($delta['name']) ?></span>
                        <?php if ($delta['file'] !== '') : ?>
                            <br><code class="muted small"><?= View::e($delta['file']) ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($delta['status'] === 'different' && ($delta['old_version'] !== '' || $delta['new_version'] !== '')) : ?>
                            <span class="diff-ver diff-old"><?= View::e($delta['old_version'] !== '' ? $delta['old_version'] : '—') ?></span>
                            <span class="diff-arrow">&rarr;</span>
                            <span class="diff-ver diff-new"><?= View::e($delta['new_version'] !== '' ? $delta['new_version'] : '—') ?></span>
                        <?php else : ?>
                            <span class="diff-ver"><?= View::e($delta['new_version'] !== '' ? $delta['new_version'] : '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($delta['status'] === 'different' && $delta['old_active'] !== $delta['new_active']) : ?>
                            <span class="diff-ver diff-old"><?= $delta['old_active'] ? 'Active' : 'Inactive' ?></span>
                            <span class="diff-arrow">&rarr;</span>
                            <span class="diff-ver <?= $delta['new_active'] ? 'diff-new' : 'diff-old' ?>"><?= $delta['new_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php else : ?>
                            <span><?= $delta['new_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php endif; ?>
                    </td>
                                        <td class="small">
                        <?php if ($delta['old_version'] === '' && $delta['new_version'] !== '') : ?>
                            <span class="compare-status-added">Added</span>
                        <?php elseif ($delta['old_version'] !== '' && $delta['new_version'] === '') : ?>
                            <span class="compare-status-deleted">Deleted</span>
                        <?php elseif ($delta['old_version'] !== $delta['new_version']) : ?>
                            <span class="compare-status-diff">Changed</span>
                        <?php elseif ($delta['old_active'] !== $delta['new_active']) : ?>
                            <span class="compare-status-diff">Changed</span>
                        <?php else : ?>
                            <span class="compare-status-same">no change</span>
                        <?php endif; ?>
                        <br><span class="muted small" style="font-size:10px">
                            Snap: <?= View::e($delta['old_version'] !== '' ? $delta['old_version'] : '—') ?> / <?= $delta['old_active'] ? 'Active' : 'Inactive' ?>
                            &nbsp;|&nbsp;
                            Curr: <?= View::e($delta['new_version'] !== '' ? $delta['new_version'] : '—') ?> / <?= $delta['new_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($selected_batch_id > 0) : ?>
        <p class="muted">No items found in the selected snapshot.</p>
    <?php else : ?>
        <p class="muted">Select a snapshot above or click <strong>Capture now</strong> to take a new fleet-wide snapshot.</p>
    <?php endif; ?>
</section>

<?php endif; ?>

<?php if ($sites_tab === 'compare') : ?>
<script>
(function () {
    var deleteBtn = document.getElementById("hub-delete-snapshots-btn");
    var countEl = document.getElementById("hub-snapshot-count");
    var selectAll = document.getElementById("hub-snapshot-select-all");
    if (!deleteBtn) return;

    function getChecked() {
        return Array.from(document.querySelectorAll(".hub-snapshot-checkbox:checked"));
    }

    function updateState() {
        var n = getChecked().length;
        deleteBtn.disabled = n === 0;
        if (countEl) countEl.textContent = "(" + n + " selected)";
        if (selectAll) {
            var all = document.querySelectorAll(".hub-snapshot-checkbox");
            selectAll.checked = all.length > 0 && n === all.length;
        }
    }

    document.addEventListener("change", function (e) {
        if (e.target.classList.contains("hub-snapshot-checkbox") || e.target === selectAll) {
            if (e.target === selectAll) {
                var boxes = document.querySelectorAll(".hub-snapshot-checkbox");
                for (var i = 0; i < boxes.length; i++) boxes[i].checked = selectAll.checked;
            }
            updateState();
        }
    });

    window.hubDeleteSelectedSnapshots = function () {
        var checked = getChecked();
        if (checked.length === 0) return;
        if (!confirm("Delete " + checked.length + " snapshot(s)? This cannot be undone.")) return;

        var form = document.createElement("form");
        form.method = "post";
        form.action = "index.php";

        var csrf = document.querySelector("meta[name=csrf]") || document.body.dataset.csrf;
        var csrfInput = document.createElement("input");
        csrfInput.type = "hidden";
        csrfInput.name = "csrf";
        csrfInput.value = csrf ? csrf.content || csrf : "";
        form.appendChild(csrfInput);

        var actionInput = document.createElement("input");
        actionInput.type = "hidden";
        actionInput.name = "action";
        actionInput.value = "delete_snapshots";
        form.appendChild(actionInput);

        checked.forEach(function (cb) {
            var id = cb.getAttribute("data-batch-id");
            if (id) {
                var inp = document.createElement("input");
                inp.type = "hidden";
                inp.name = "batch_ids[]";
                inp.value = id;
                form.appendChild(inp);
            }
        });

        document.body.appendChild(form);
        form.submit();
    };

    updateState();
})();
</script>
<?php endif; ?>

<script src="assets/sites-progress.js" defer></script>
<?php if ($sites_tab === 'plugins' && $sites !== [] && $fleet_plugins !== []) : ?>
<script src="assets/sites-plugins-tab.js" defer></script>
<?php endif; ?>
