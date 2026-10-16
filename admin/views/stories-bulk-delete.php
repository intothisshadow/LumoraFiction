<?php

/**
 * Asks for confirmation before deleting several stories at once.
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

/** @var list<Story> $stories only stories the writer may change */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?php if ($stories === []): ?>
    <p>None of the selected stories can be deleted by you.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the list</a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="bulk_action" value="delete">
        <input type="hidden" name="confirmed" value="1">

        <div class="lf-card">
            <h2>Delete <?= count($stories) === 1 ? 'this story' : 'these ' . count($stories) . ' stories' ?>?</h2>
            <ul>
                <?php foreach ($stories as $story): ?>
                    <li>
                        <?= Html::e($story->title) ?>
                        <input type="hidden" name="ids[]" value="<?= (int) $story->id ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>The stories and all their chapters will be removed for good. This cannot be undone.</p>
            <p>
                <button type="submit" class="lf-button">Delete</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
