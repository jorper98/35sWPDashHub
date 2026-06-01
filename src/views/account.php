<?php

declare(strict_types=1);

use S35WpHub\View;

/** @var string $username */
/** @var string $csrf */
/** @var bool $can_change_password */
?>
<h1>Account</h1>
<p class="muted">Signed in as <strong><?= View::e($username) ?></strong>.</p>

<?php if ($can_change_password) : ?>
    <h2 class="subhead">Change password</h2>
    <form method="post" action="index.php" class="form">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="change_dashboard_password">
        <label>Current password
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>New password
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
        </label>
        <label>Confirm new password
            <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
        </label>
        <div class="actions-row">
            <button type="submit" class="btn primary">Update password</button>
            <a class="btn link" href="index.php">Back</a>
        </div>
    </form>
<?php else : ?>
    <p class="muted">Password for this account is not stored in the database yet. Open the <a href="index.php">Sites</a> page once to finish setup, or change the hash in <code>config.php</code>.</p>
    <p><a class="btn link" href="index.php">Back</a></p>
<?php endif; ?>
