<?php

/**
 * Admin view: the confirmation before items in the trash are deleted for good.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Story;

/** @var list<Story> $stories only stories in the trash that the person may delete */
/** @var list<array{chapter: LumoraFiction\Models\Chapter, story_title: string}> $chapters */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?php if ($stories === [] && $chapters === []): ?>
    <p>None of the ticked items can be deleted by you.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the trash</a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="trash_action" value="delete">
        <input type="hidden" name="confirmed" value="1">

        <div class="lf-card">
            <h2>Delete <?= count($stories) + count($chapters) === 1 ? 'this item' : 'these ' . (count($stories) + count($chapters)) . ' items' ?> for good?</h2>
            <ul>
                <?php foreach ($stories as $story): ?>
                    <li><?= Html::e($story->title) ?> (story, with its chapters)<input type="hidden" name="stories[]" value="<?= (int) $story->id ?>"></li>
                <?php endforeach; ?>
                <?php foreach ($chapters as $row): ?>
                    <li><?= Html::e($row['chapter']->title !== '' ? $row['chapter']->title : 'Untitled chapter') ?> (chapter of <?= Html::e($row['story_title']) ?>)<input type="hidden" name="chapters[]" value="<?= (int) $row['chapter']->id ?>"></li>
                <?php endforeach; ?>
            </ul>
            <p>They will be removed for good, with the earlier versions of the chapters. This cannot be undone.</p>
            <p>
                <button type="submit" class="lf-button">Delete for good</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
