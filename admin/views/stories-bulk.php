<?php

/**
 * Names the selected stories and asks for the one choice that goes with a change to all of them: a rating, where they
 * stand, a series, a collection, the download choice, or a tag, fandom or character to add or remove.
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

/** @var string $action */
/** @var list<array{id: int, label: string}> $items only stories the person may change */
/** @var array{ratings?: list<LumoraFiction\Models\Term>, statuses?: list<LumoraFiction\Models\WorkStatus>, series?: list<LumoraFiction\Models\Series>, collections?: list<LumoraFiction\Models\Collection>, downloads?: list<LumoraFiction\Models\DownloadChoice>} $choices */
/** @var string $nameLabel the label of the name field for a tag, fandom or character */
/** @var array{name: string} $values */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var array<string, string> $fields hidden fields: the action, the list, the filters, the sort and the page */
/** @var string $csrfToken */
/** @var string $formUrl */
/** @var string $backUrl */

$count = count($items);
$stories = $count === 1 ? 'this story' : 'these ' . $count . ' stories';
$heading = match ($action) {
    'rating' => 'Set the rating of ' . $stories . '?',
    'status' => 'Set where ' . $stories . ' stand?',
    'series' => 'Add ' . $stories . ' to a series?',
    'collection' => 'Add ' . $stories . ' to a collection?',
    'downloads' => 'Change the download choice of ' . $stories . '?',
    default => ($nameLabel !== '' ? $nameLabel : 'Change') . ' for ' . $stories . '?',
};
$hint = match ($action) {
    'rating' => 'Each story gets exactly this rating in place of any it has.',
    'status' => 'Stories you may not change are skipped.',
    'series' => 'Only your own stories can join your series, and a story is in one series at a time, so one that is in another moves here at the end.',
    'collection' => 'Only your own stories can be offered. A collection that checks each story first holds them for its curator.',
    'downloads' => '',
    default => str_ends_with($action, '_add') ? 'A name that is not on the list is proposed for an administrator to review, as on the story form.' : 'Stories that do not have it are skipped.',
};
$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?= $errorSummary ?>

<?php if ($items === []): ?>
    <p>None of the selected stories can be changed by you.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the stories</a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($formUrl) ?>" class="lf-form" novalidate>
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
            <?php if ($hint !== ''): ?><p><?= Html::e($hint) ?></p><?php endif; ?>

            <?php if ($action === 'rating'): ?>
                <div class="lf-field<?= isset($errors['rating']) ? ' lf-field--error' : '' ?>">
                    <label for="rating_choice">Rating</label>
                    <select id="rating_choice" name="rating_choice" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($choices['ratings'] ?? [] as $rating): ?>
                            <option value="<?= (int) $rating->id ?>"><?= Html::e($rating->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $problem('rating') ?>
                </div>
            <?php elseif ($action === 'status'): ?>
                <div class="lf-field<?= isset($errors['work_status']) ? ' lf-field--error' : '' ?>">
                    <label for="status_choice">Where the stories stand</label>
                    <select id="status_choice" name="status_choice" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($choices['statuses'] ?? [] as $status): ?>
                            <option value="<?= Html::e($status->value) ?>"><?= Html::e($status->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $problem('work_status') ?>
                </div>
            <?php elseif ($action === 'series'): ?>
                <div class="lf-field<?= isset($errors['series']) ? ' lf-field--error' : '' ?>">
                    <label for="series_choice">Series</label>
                    <select id="series_choice" name="series_choice" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($choices['series'] ?? [] as $series): ?>
                            <option value="<?= (int) $series->id ?>"><?= Html::e($series->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $problem('series') ?>
                </div>
            <?php elseif ($action === 'collection'): ?>
                <div class="lf-field<?= isset($errors['collection']) ? ' lf-field--error' : '' ?>">
                    <label for="collection_choice">Collection</label>
                    <select id="collection_choice" name="collection_choice" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($choices['collections'] ?? [] as $collection): ?>
                            <option value="<?= (int) $collection->id ?>"><?= Html::e($collection->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $problem('collection') ?>
                </div>
            <?php elseif ($action === 'downloads'): ?>
                <div class="lf-field<?= isset($errors['downloads']) ? ' lf-field--error' : '' ?>">
                    <label for="downloads_choice">Downloads</label>
                    <select id="downloads_choice" name="downloads_choice" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($choices['downloads'] ?? [] as $choice): ?>
                            <option value="<?= Html::e($choice->value) ?>"><?= Html::e($choice->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $problem('downloads') ?>
                </div>
            <?php else: ?>
                <div class="lf-field<?= isset($errors['name']) ? ' lf-field--error' : '' ?>">
                    <label for="name"><?= Html::e($nameLabel) ?></label>
                    <input type="text" id="name" name="name" value="<?= Html::e($values['name']) ?>" maxlength="200" required autocomplete="off">
                    <?= $problem('name') ?>
                </div>
            <?php endif; ?>

            <p>
                <button type="submit" class="lf-button">Apply to <?= (int) $count ?> <?= $count === 1 ? 'story' : 'stories' ?></button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
