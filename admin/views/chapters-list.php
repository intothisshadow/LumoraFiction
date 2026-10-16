<?php

/**
 * The chapters of one story, with buttons to move each one up or down and to publish or unpublish it.
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
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;

/** @var Story $story */
/** @var list<Chapter> $chapters */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var array{story: string, new: string, move: string, chapter: string} $urls */

$last = count($chapters) - 1;
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($urls['story']) ?>">&larr; Back to the story</a></p>
<p><a href="<?= Html::e($urls['new']) ?>" class="lf-button">Add a chapter</a></p>

<?php if ($chapters === []): ?>
    <p class="lf-hint">This story has no chapters yet.</p>
<?php else: ?>
    <p class="lf-hint">Use the Move buttons to change the order; the numbers update by themselves.</p>
    <table class="lf-table lf-chapter-table">
        <caption class="lf-visually-hidden">Chapters of <?= Html::e($story->title) ?></caption>
        <thead>
            <tr>
                <th scope="col">No.</th>
                <th scope="col">Title</th>
                <th scope="col">Status</th>
                <th scope="col">Words</th>
                <th scope="col">Order</th>
                <th scope="col">Visibility</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($chapters as $index => $chapter): ?>
                <?php $name = $chapter->title !== '' ? $chapter->title : 'Chapter ' . $chapter->number; ?>
                <tr>
                    <td><?= (int) $chapter->number ?></td>
                    <th scope="row"><a href="<?= Html::e($urls['chapter'] . '/' . $chapter->id) ?>"><?= Html::e($name) ?></a></th>
                    <td><span class="lf-status<?= $chapter->state === PublicationState::Published ? ' lf-status--ok' : '' ?>"><?= Html::e($chapter->state->label()) ?></span></td>
                    <td><?= number_format($chapter->wordCount) ?></td>
                    <td>
                        <form method="post" action="<?= Html::e($urls['move']) ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                            <input type="hidden" name="chapter" value="<?= (int) $chapter->id ?>">
                            <button type="submit" name="direction" value="up" class="lf-button lf-button--quiet"<?= $index === 0 ? ' disabled' : '' ?> aria-label="Move <?= Html::e($name) ?> up">Move up</button>
                            <button type="submit" name="direction" value="down" class="lf-button lf-button--quiet"<?= $index === $last ? ' disabled' : '' ?> aria-label="Move <?= Html::e($name) ?> down">Move down</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="<?= Html::e($urls['chapter'] . '/' . $chapter->id . '/' . ($chapter->state === PublicationState::Published ? 'unpublish' : 'publish')) ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                            <button type="submit" class="lf-button lf-button--quiet" aria-label="<?= $chapter->state === PublicationState::Published ? 'Unpublish' : 'Publish' ?> <?= Html::e($name) ?>"><?= $chapter->state === PublicationState::Published ? 'Unpublish' : 'Publish' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
