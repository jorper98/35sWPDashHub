<?php

declare(strict_types=1);

use S35WpHub\Model\Owner;
use S35WpHub\View;

/** @var Owner|null $owner */
/** @var string $csrf */
$isEdit = $owner !== null;
?>
<h1><?= $isEdit ? 'Edit owner' : 'Add owner' ?></h1>
<p class="muted">Assign owners to sites from each site’s <strong>Edit</strong> page. Reports include health, pending updates, and remote update activity from the last 30 days.</p>

<form method="post" action="index.php" class="form">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="action" value="save_owner">
    <?php if ($isEdit) : ?>
        <input type="hidden" name="id" value="<?= (int) $owner->id ?>">
    <?php endif; ?>

    <label>First name
        <input type="text" name="first_name" required value="<?= View::e($owner?->firstName ?? '') ?>">
    </label>
    <label>Last name
        <input type="text" name="last_name" required value="<?= View::e($owner?->lastName ?? '') ?>">
    </label>
    <label>Owner email (for reports)
        <input type="email" name="owner_email" required value="<?= View::e($owner?->ownerEmail ?? '') ?>" autocomplete="email">
    </label>
    <label>Renewal date
        <input type="date" name="renewal_date" value="<?= View::e($owner?->renewalDate ?? '') ?>">
    </label>

    <div class="actions-row">
        <button type="submit" class="btn primary"><?= $isEdit ? 'Save' : 'Add owner' ?></button>
        <a class="btn link" href="index.php?page=owners">Cancel</a>
    </div>
</form>
