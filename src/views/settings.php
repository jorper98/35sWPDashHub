<?php

declare(strict_types=1);

use S35WpHub\View;

/** @var string $agency_name */
/** @var string $csrf */
/** @var string $companion_manifest_url */
/** @var string $hub_host_for_packages */
?>
<h1>Settings</h1>
<p class="muted">Used for future reporting features. Stored locally in SQLite.</p>
<?php if ($companion_manifest_url !== '') : ?>
    <section class="card-like" style="margin-bottom: 1.5rem;">
        <h2 class="h3">WordPress companion (wp-config.php)</h2>
        <p class="muted small">Use this URL for one-click companion updates (HTTPS only):</p>
        <pre class="small" style="overflow:auto;">define('S35_WP_HUB_UPDATE_MANIFEST_URL', '<?= View::e($companion_manifest_url) ?>');</pre>
        <?php if ($hub_host_for_packages !== '') : ?>
            <p class="muted small">For <strong>Plugin packages</strong> fleet deploy, allow your hub host (comma-separated if several):</p>
            <pre class="small" style="overflow:auto;">define('S35_WP_HUB_ALLOWED_PACKAGE_HOSTS', '<?= View::e($hub_host_for_packages) ?>');</pre>
        <?php endif; ?>
    </section>
<?php endif; ?>
<form method="post" action="index.php" class="form">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="save_settings">
    <label>Agency name
        <input type="text" name="agency_name" value="<?= View::e($agency_name) ?>">
    </label>
    <div class="actions-row">
        <button type="submit" class="btn primary">Save</button>
        <a class="btn link" href="index.php">Back</a>
    </div>
</form>
