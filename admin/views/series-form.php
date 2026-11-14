<?php

/**
 * Admin: add or edit a series and order its stories.
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

use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Series;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\WorkStatus;

/** @var Series|null $series */
/** @var array{title: string, summary: string, work_status: string} $values */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var list<Story> $members */
/** @var list<Story> $available */
/** @var list<WorkStatus> $statuses */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $baseUrl */
/** @var string $storyUrl */
/** @var string $publicUrl */
/** @var array{title: int, summary: int} $limits */
/** @var string $assetVersion */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
$describe = static fn (string $name): string => ' aria-describedby="' . Html::e($name) . '_hint' . (isset($errors[$name]) ? ' ' . Html::e($name) . '_error' : '') . '"';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($baseUrl) ?>">&larr; All series</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate data-lf-guard>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $limits['title'] ?>"<?= $describe('title') ?>>
        <p class="lf-hint" id="title_hint">What readers see at the top of the series page.</p>
        <?= $problem('title') ?>
    </div>

    <div class="lf-field<?= isset($errors['summary']) ? ' lf-field--error' : '' ?>">
        <label for="summary">Summary</label>
        <textarea id="summary" name="summary" rows="4" maxlength="<?= (int) $limits['summary'] ?>"<?= $describe('summary') ?>><?= Html::e($values['summary']) ?></textarea>
        <p class="lf-hint" id="summary_hint">A few lines about the series as a whole. Optional.</p>
        <?= $problem('summary') ?>
    </div>

    <div class="lf-field<?= isset($errors['work_status']) ? ' lf-field--error' : '' ?>">
        <label for="work_status">Status of the whole series</label>
        <select id="work_status" name="work_status"<?= $describe('work_status') ?>>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= Html::e($status->value) ?>"<?= $values['work_status'] === $status->value ? ' selected' : '' ?>><?= Html::e($status->label()) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="lf-hint" id="work_status_hint">Say when the series is finished, even if you may add stories to it later.</p>
        <?= $problem('work_status') ?>
    </div>

    <p><button type="submit" class="lf-button"><?= $series === null ? 'Add the series' : 'Save changes' ?></button></p>
</form>

<?php if ($series !== null): ?>
    <section class="lf-card" aria-labelledby="series-stories-heading">
        <h2 id="series-stories-heading">Stories in this series</h2>

        <?php if ($members === []): ?>
            <p class="lf-hint">No stories yet. Add the first one below.</p>
        <?php else: ?>
            <?= BulkBar::form('lf-bulk-series', $baseUrl . '/' . $series->id . '/stories/bulk', $csrfToken) ?>
            <?= BulkBar::bar('lf-bulk-series', 'stories', ['remove' => 'Take out of the series'], '') ?>
            <?= BulkBar::selectAll('series_ids', 'lf-bulk-series', 'stories') ?>
            <ol class="lf-series-members">
                <?php foreach ($members as $index => $story): ?>
                    <li class="lf-series-members__item">
                        <?= BulkBar::input('series_ids', 'lf-bulk-series', $story->id, 'Select ' . $story->title) ?>
                        <a href="<?= Html::e($storyUrl . '/' . $story->id) ?>"><?= Html::e($story->title) ?></a>
                        <span class="lf-hint"><?= Html::e($story->state->label()) ?></span>
                        <span class="lf-series-members__actions">
                            <?php foreach (['up' => 'Move up', 'down' => 'Move down'] as $direction => $label): ?>
                                <?php if (($direction === 'up' && $index > 0) || ($direction === 'down' && $index < count($members) - 1)): ?>
                                    <form method="post" action="<?= Html::e($baseUrl . '/' . $series->id . '/stories/' . $story->id . '/move') ?>" class="lf-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                                        <input type="hidden" name="direction" value="<?= Html::e($direction) ?>">
                                        <button type="submit" class="lf-button lf-button--secondary"><?= Html::e($label) ?><span class="lf-visually-hidden"> <?= Html::e($story->title) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <form method="post" action="<?= Html::e($baseUrl . '/' . $series->id . '/stories/' . $story->id . '/remove') ?>" class="lf-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                                <button type="submit" class="lf-button lf-button--secondary">Take out<span class="lf-visually-hidden"> <?= Html::e($story->title) ?></span></button>
                            </form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?= BulkBar::script($assetVersion) ?>
        <?php endif; ?>

        <?php if ($available !== []): ?>
            <form method="post" action="<?= Html::e($baseUrl . '/' . $series->id . '/stories') ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <div class="lf-field">
                    <label for="story_id">Add a story at the end</label>
                    <select id="story_id" name="story_id">
                        <?php foreach ($available as $story): ?>
                            <option value="<?= (int) $story->id ?>"><?= Html::e($story->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Add to the series</button></p>
            </form>
        <?php else: ?>
            <p class="lf-hint">Every story that is not already in a series is in this one. Stories are added from here or from a story&rsquo;s own form.</p>
        <?php endif; ?>
    </section>

    <p>
        <?php if ($publicUrl !== ''): ?><a href="<?= Html::e($publicUrl) ?>">View it on the site</a> &middot;<?php endif; ?>
        <a href="<?= Html::e($baseUrl . '/' . $series->id . '/delete') ?>">Delete this series</a>
    </p>
<?php endif; ?>
