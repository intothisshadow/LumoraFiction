<?php

/**
 * Asks for confirmation before several classification terms are deleted, or before an action runs on every match.
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
use LumoraFiction\Models\TermType;

/** @var TermType $type */
/** @var string $action approve, unapprove or delete */
/** @var int $count */
/** @var list<string> $names the first few names, for the person to recognise */
/** @var list<int> $ids the ticked terms; empty when the action covers every match */
/** @var bool $everything */
/** @var string $search */
/** @var string $status */
/** @var int $page */
/** @var string $csrfToken */
/** @var string $formAction */
/** @var string $backUrl */

$plural = $labels->lowerPlural($type);
$what = $count === 1 ? 'this ' . $labels->lower($type) :'these ' . $count . ' ' . $plural;
$title = match ($action) {
    'delete' => 'Delete ' . $what . '?',
    'approve' => 'Approve ' . $what . '?',
    default => 'Mark ' . $what . ' as unapproved?',
};
?>
<form method="post" action="<?= Html::e($formAction) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <input type="hidden" name="bulk_action" value="<?= Html::e($action) ?>">
    <input type="hidden" name="confirmed" value="1">
    <input type="hidden" name="q" value="<?= Html::e($search) ?>">
    <input type="hidden" name="status" value="<?= Html::e($status) ?>">
    <input type="hidden" name="page" value="<?= (int) $page ?>">
    <?php if ($everything): ?>
        <input type="hidden" name="all_matching" value="1">
        <input type="hidden" name="expected" value="<?= (int) $count ?>">
    <?php else: ?>
        <?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>"><?php endforeach; ?>
    <?php endif; ?>

    <div class="lf-card">
        <h2><?= Html::e($title) ?></h2>
        <?php if ($everything): ?>
            <p>This covers every <?= Html::e($plural) ?> the current list matches: exactly <strong><?= (int) $count ?></strong>.</p>
        <?php endif; ?>
        <?php if ($names !== []): ?>
            <ul>
                <?php foreach ($names as $name): ?><li><?= Html::e($name) ?></li><?php endforeach; ?>
                <?php if ($count > count($names)): ?><li>and <?= (int) ($count - count($names)) ?> more</li><?php endif; ?>
            </ul>
        <?php endif; ?>
        <?php if ($action === 'delete'): ?>
            <p>Deleted terms and their aliases are removed for good, which cannot be undone. A term that stories still use, or that still has characters or relationships under it, is skipped and left as it is.</p>
        <?php elseif ($action === 'approve'): ?>
            <p>Approved terms appear on the public indexes as soon as they have a public story.</p>
        <?php else: ?>
            <p>Unapproved terms stay attached to their stories and keep their aliases, but are left off the public indexes until they are approved again.</p>
        <?php endif; ?>
        <p>
            <button type="submit" class="lf-button"><?= $action === 'delete' ? 'Delete' : ($action === 'approve' ? 'Approve' : 'Mark as unapproved') ?></button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
