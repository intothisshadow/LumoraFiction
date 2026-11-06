<?php

/**
 * Asks for confirmation before comments are deleted, or before an action runs on every match.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var string $action approve, hide, spam, restore or delete */
/** @var int $count */
/** @var list<int> $ids the ticked comments; empty when the action covers every match */
/** @var bool $everything */
/** @var array{status: string, story: int, chapter: int, user: int, from: string, to: string, q: string} $filters */
/** @var int $page */
/** @var string $csrfToken */
/** @var string $formAction */
/** @var string $backUrl */

$what = $count === 1 ? 'this comment' : 'these ' . $count . ' comments';
$verbs = ['approve' => 'Approve', 'hide' => 'Hide', 'spam' => 'Mark as spam', 'restore' => 'Restore', 'delete' => 'Delete'];
$label = $verbs[$action] ?? 'Apply';
?>
<form method="post" action="<?= Html::e($formAction) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <input type="hidden" name="bulk_action" value="<?= Html::e($action) ?>">
    <input type="hidden" name="confirmed" value="1">
    <input type="hidden" name="page" value="<?= (int) $page ?>">
    <?php foreach ($filters as $name => $value): ?>
        <?php if ($value !== '' && $value !== 0): ?><input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $value) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($everything): ?>
        <input type="hidden" name="all_matching" value="1">
        <input type="hidden" name="expected" value="<?= (int) $count ?>">
    <?php else: ?>
        <?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>"><?php endforeach; ?>
    <?php endif; ?>

    <div class="lf-card">
        <h2><?= Html::e($label) ?> <?= Html::e($what) ?>?</h2>
        <?php if ($everything): ?>
            <p>This covers every comment the current tab and filters match: exactly <strong><?= (int) $count ?></strong>.</p>
        <?php else: ?>
            <p><?= (int) $count ?> <?= $count === 1 ? 'comment is' : 'comments are' ?> selected.</p>
        <?php endif; ?>
        <?php if ($action === 'delete'): ?>
            <p>Deleted comments, and any reports against them, are removed for good. This cannot be undone. A comment you only want out of sight can be hidden instead.</p>
        <?php elseif ($action === 'approve' || $action === 'restore'): ?>
            <p>They will be shown to readers, and any reports against them are cleared. A writer is told about a comment when it is approved.</p>
        <?php else: ?>
            <p>They will no longer be shown to readers. You can restore them later.</p>
        <?php endif; ?>
        <p>
            <button type="submit" class="lf-button"><?= Html::e($label) ?></button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
