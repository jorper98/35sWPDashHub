<?php

declare(strict_types=1);

use S35WpHub\Model\Owner;
use S35WpHub\Model\Site;
use S35WpHub\View;

/** @var Site|null $site */
/** @var list<Owner> $owners */
/** @var string $csrf */
$isEdit = $site !== null;
?>
<h1><?= $isEdit ? 'Edit site' : 'Add site' ?></h1>
<p class="muted">Use an HTTPS site URL and a user with the <strong>Application Passwords</strong> feature. For reliable update counts and remote <strong>Update</strong> from this dashboard, install the companion plugin: zip <code>plugin/s35-wp-hub</code>, upload it under WordPress <strong>Plugins → Add New → Upload Plugin</strong>, then activate.</p>

<form method="post" action="index.php" class="form">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="save_site">
    <?php if ($isEdit) : ?>
        <input type="hidden" name="id" value="<?= (int) $site->id ?>">
    <?php endif; ?>

    <label>Label (optional)
        <input type="text" name="label" value="<?= View::e($site?->label ?? '') ?>" placeholder="Client name">
    </label>

    <label>Site URL
        <input type="url" name="site_url" required value="<?= View::e($site?->siteUrl ?? '') ?>" placeholder="https://example.com">
    </label>

    <label>WordPress admin username
        <input type="text" name="admin_user" required value="<?= View::e($site?->adminUser ?? '') ?>" autocomplete="username">
    </label>

    <label>Application password
        <input type="password" name="app_password" <?= $isEdit ? '' : 'required' ?> autocomplete="current-password" placeholder="<?= $isEdit ? 'Leave blank to keep existing' : 'xxxx xxxx xxxx xxxx' ?>">
    </label>

    <label>Owner (optional)
        <select name="owner_id">
            <option value="0">— None —</option>
            <?php foreach ($owners as $o) : ?>
                <option value="<?= (int) $o->id ?>" <?= ($isEdit && $site->ownerId === $o->id) ? ' selected' : '' ?>>
                    <?= View::e($o->displayName()) ?> (<?= View::e($o->ownerEmail) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="actions-row">
        <button type="submit" class="btn primary"><?= $isEdit ? 'Save' : 'Add site' ?></button>
        <a class="btn link" href="index.php">Cancel</a>
    </div>
    <?php if ($isEdit) : ?>
        <div class="actions-row hub-test-connection-row">
            <button type="button" class="btn" id="hub-site-test-connection-btn">Test connection</button>
            <span class="muted small">Verifies the stored application password and whether the companion summary endpoint responds.</span>
        </div>
        <div id="hub-site-test-result" class="hub-site-test-result" hidden role="status"></div>
        <script>
        (function () {
            var btn = document.getElementById("hub-site-test-connection-btn");
            var out = document.getElementById("hub-site-test-result");
            if (!btn || !out) {
                return;
            }
            var body = document.body;
            var siteId = <?= (int) $site->id ?>;

            btn.addEventListener("click", function () {
                var csrf = body.dataset.csrf || "";
                if (!csrf) {
                    out.hidden = false;
                    out.className = "hub-site-test-result flash flash-error";
                    out.textContent = "Missing CSRF token. Reload the page.";
                    return;
                }
                btn.disabled = true;
                out.hidden = false;
                out.className = "hub-site-test-result muted small";
                out.textContent = "Testing connection…";

                var fd = new FormData();
                fd.append("csrf", csrf);
                fd.append("action", "test_connection");
                fd.append("ajax", "1");
                fd.append("id", String(siteId));

                fetch("index.php", {
                    method: "POST",
                    body: fd,
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                })
                    .then(function (r) {
                        return r.text().then(function (text) {
                            var data;
                            try {
                                data = text ? JSON.parse(text) : null;
                            } catch (e) {
                                throw new Error(
                                    r.ok
                                        ? "Server returned non-JSON (check PHP errors / logs)."
                                        : "Request failed (" + r.status + ")."
                                );
                            }
                            if (!r.ok) {
                                throw new Error((data && data.error) || "Request failed (" + r.status + ")");
                            }
                            if (data === null || typeof data !== "object") {
                                throw new Error("Empty or invalid JSON response.");
                            }
                            return data;
                        });
                    })
                    .then(function (data) {
                        var okRest = !!data.rest_authenticated;
                        var okSum = !!data.companion_summary_ok;
                        var msg = data.message || data.log_summary || "";
                        if (!okRest) {
                            out.className = "hub-site-test-result flash flash-error";
                        } else if (!okSum) {
                            out.className = "hub-site-test-result flash flash-warn";
                        } else {
                            out.className = "hub-site-test-result flash flash-success";
                        }
                        out.textContent = msg;
                    })
                    .catch(function (err) {
                        out.className = "hub-site-test-result flash flash-error";
                        out.textContent = err.message || "Request failed.";
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        })();
        </script>
    <?php endif; ?>
</form>
