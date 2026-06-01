<?php

declare(strict_types=1);

use S35WpHub\Model\Owner;
use S35WpHub\View;

/** @var string $csrf */
/** @var list<array{owner: Owner, site_count: int}> $owner_rows */
/** @var bool $can_send_reports */
?>
<div class="page-head">
    <h1>Owners</h1>
    <div class="page-head-actions">
        <a class="btn primary" href="index.php?page=owner_new">Add owner</a>
        <form method="post" action="index.php" class="inline-form" onsubmit="return confirm('Email a report to every owner who has at least one site?');">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="action" value="send_all_owner_reports">
            <button type="submit" class="btn"<?= $can_send_reports ? '' : ' title="Set report_mail_from in config.php first"' ?>>Email all owners</button>
        </form>
    </div>
</div>

<?php if (! $can_send_reports) : ?>
    <div class="flash flash-warn">To deliver mail, set <code>report_mail_from</code> (and optional <code>report_mail_from_name</code>) in <code>config/config.php</code> to a valid sender address your host allows. You can still use the buttons below; the app will remind you if sending is not configured.</div>
<?php endif; ?>

<?php if ($owner_rows === []) : ?>
    <p class="muted">No owners yet. <a href="index.php?page=owner_new">Add an owner</a>, then assign them on each site’s edit screen.</p>
<?php else : ?>
<table class="grid">
    <thead>
    <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Renewal</th>
        <th>Sites</th>
        <th class="actions">Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($owner_rows as $row) :
        $o = $row['owner'];
        $n = (int) $row['site_count'];
        ?>
        <tr>
            <td class="strong"><?= View::e($o->displayName()) ?></td>
            <td><?= View::e($o->ownerEmail) ?></td>
            <td class="muted small"><?= View::e($o->renewalDate !== '' ? $o->renewalDate : '—') ?></td>
            <td><?= $n ?></td>
            <td class="actions">
                <form method="post" action="index.php" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="send_owner_report">
                    <input type="hidden" name="owner_id" value="<?= (int) $o->id ?>">
                    <button type="submit" class="btn small"<?= $can_send_reports ? '' : ' title="Set report_mail_from in config.php"' ?>>Email report</button>
                </form>
                <a class="btn small link" href="index.php?page=owner_edit&id=<?= (int) $o->id ?>">Edit</a>
                <form method="post" action="index.php" class="stack-form"
                      onsubmit="return confirm('Remove this owner? Sites will be unassigned.');">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="action" value="delete_owner">
                    <input type="hidden" name="id" value="<?= (int) $o->id ?>">
                    <button type="submit" class="btn small ghost">Remove</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
