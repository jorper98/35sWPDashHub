<?php

declare(strict_types=1);

use S35WpHub\Model\PluginPackage;
use S35WpHub\Model\Site;
use S35WpHub\View;

/** @var list<PluginPackage> $packages */
/** @var list<Site> $sites */
/** @var string $csrf */
/** @var string $hub_base_url */
/** @var string $companion_manifest_url */
/** @var string $companion_package_url */
?>
<div class="page-head">
    <h1>Plugin packages</h1>
</div>

<p class="muted">Upload WordPress plugin zips and deploy them to sites over HTTPS. Each site’s companion must allow your hub host (see <code>S35_WP_HUB_ALLOWED_PACKAGE_HOSTS</code> or <code>S35_WP_HUB_UPDATE_MANIFEST_URL</code> in <code>wp-config.php</code>).</p>

<section class="card-like" style="margin-bottom: 1.5rem;">
    <h2>35sDash Companion (plugin/s35-wp-hub)</h2>
    <p class="muted small">Version in <code>plugin/s35-wp-hub</code> is exposed at:</p>
    <p><code><?= View::e($companion_manifest_url) ?></code></p>
    <p class="muted small">Package URL (rebuild zip after changing source so it matches the manifest version):</p>
    <p><code><?= View::e($companion_package_url) ?></code></p>
    <form method="post" action="index.php" style="margin-top: 0.75rem;">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="build_companion_zip">
        <button type="submit" class="btn primary">Build companion zip</button>
        <span class="muted small">Writes <code>public/plugin-update/s35-wp-hub.zip</code> (requires PHP zip extension).</span>
    </form>
</section>

<h2>Upload package</h2>
<form method="post" action="index.php" enctype="multipart/form-data" class="stack" style="margin-bottom: 2rem;">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="upload_plugin_package">
    <label class="label">Plugin zip (one top-level folder, WordPress plugin headers)</label>
    <input type="file" name="plugin_zip" accept=".zip,application/zip" required>
    <button type="submit" class="btn primary">Upload</button>
</form>

<?php if ($packages === []) : ?>
    <p class="muted">No uploaded packages yet.</p>
<?php else : ?>
    <h2>Uploaded packages</h2>
    <table class="grid">
        <thead>
        <tr>
            <th>File</th>
            <th>Slug hint</th>
            <th>Public URL</th>
            <th>Uploaded</th>
            <th class="actions">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($packages as $p) : ?>
            <tr>
                <td class="strong"><?= View::e($p->originalFilename) ?></td>
                <td><?= View::e($p->slugHint ?? '—') ?></td>
                <td class="small"><code><?= View::e($hub_base_url) ?>/plugin-packages/<?= View::e($p->diskName) ?></code></td>
                <td class="small"><?= View::e($p->createdAt) ?></td>
                <td>
                    <form method="post" action="index.php" class="inline" onsubmit="return confirm('Delete this package from the hub?');">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="action" value="delete_plugin_package">
                        <input type="hidden" name="id" value="<?= (int) $p->id ?>">
                        <button type="submit" class="btn small danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($sites !== []) : ?>
        <h2 style="margin-top: 2rem;">Deploy to sites</h2>
        <p class="muted small">Choose a package and sites, then deploy. The companion installs or upgrades from the hub URL.</p>
        <div class="stack" style="margin-bottom: 1rem;">
            <label class="label" for="hub-deploy-package">Package</label>
            <select id="hub-deploy-package" class="input">
                <?php foreach ($packages as $p) : ?>
                    <option value="<?= (int) $p->id ?>"><?= View::e($p->originalFilename) ?> (id <?= (int) $p->id ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <table class="grid">
            <thead>
            <tr>
                <th><input type="checkbox" id="hub-deploy-check-all" title="Select all"></th>
                <th>Site</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $s) : ?>
                <tr>
                    <td><input type="checkbox" class="hub-deploy-site-cb" value="<?= (int) $s->id ?>"></td>
                    <td><?= View::e($s->label !== null && $s->label !== '' ? $s->label : $s->siteUrl) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top: 1rem;">
            <button type="button" class="btn primary" id="hub-deploy-selected-btn">Deploy to selected sites</button>
        </p>
        <script type="application/json" id="hub-plugin-sites-json"><?= json_encode(
            array_map(
                static fn (Site $s) => [
                    'id' => $s->id,
                    'label' => $s->label !== null && $s->label !== '' ? $s->label : $s->siteUrl,
                ],
                $sites
            ),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?></script>
    <?php endif; ?>
<?php endif; ?>

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

<script src="assets/plugin-packages.js" defer></script>
