<?php

/**
 * Asks before the ticked chapters of a story are deleted for good.
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
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;

/** @var Story $story */
/** @var list<Chapter> $chapters only chapters of this story */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?php if ($chapters === []): ?>
    <p>None of the selected chapters can be deleted.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the chapters</a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="bulk_action" value="delete">
        <input type="hidden" name="confirmed" value="1">

        <div class="lf-card">
            <h2>Delete <?= count($chapters) === 1 ? 'this chapter' : 'these ' . count($chapters) . ' chapters' ?> of <?= Html::e($story->title) ?>?</h2>
            <ul>
                <?php foreach ($chapters as $chapter): ?>
                    <li>
                        <?= Html::e($chapter->title !== '' ? $chapter->title : 'Chapter ' . $chapter->number) ?>
                        <input type="hidden" name="ids[]" value="<?= (int) $chapter->id ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>The chapters, their earlier versions and their comments will be removed for good, and the later chapters move up. This cannot be undone.</p>
            <p>
                <button type="submit" class="lf-button">Delete</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
