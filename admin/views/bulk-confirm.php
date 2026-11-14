<?php

/**
 * The shared "are you sure" page for a bulk action that cannot be undone: it names what the action will do to how many items.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var string $heading */
/** @var string $note what will happen, and that it cannot be undone */
/** @var string $button */
/** @var list<array{id: int, label: string}> $items only items the person may change */
/** @var array<string, string> $fields hidden fields to send back: the action, the confirmation, the sort and page */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
/** @var string $backLabel */
?>
<?php if ($items === []): ?>
    <p>None of the selected items can be changed by you.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; <?= Html::e($backLabel) ?></a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="confirmed" value="1">
        <?php foreach ($fields as $name => $value): ?>
            <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
        <?php endforeach; ?>

        <div class="lf-card">
            <h2><?= Html::e($heading) ?></h2>
            <ul>
                <?php foreach ($items as $item): ?>
                    <li>
                        <?= Html::e($item['label']) ?>
                        <input type="hidden" name="ids[]" value="<?= (int) $item['id'] ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><?= Html::e($note) ?></p>
            <p>
                <button type="submit" class="lf-button"><?= Html::e($button) ?></button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
