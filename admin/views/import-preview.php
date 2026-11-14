<?php

/**
 * Admin: import a story from another archive (step two, the preview and the choices).
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

use LumoraFiction\Core\Import\OtwWork;
use LumoraFiction\Helpers\Html;

/** @var array<string, mixed> $preview */
/** @var array<string, string> $errors */
/** @var array<string, mixed> $posted */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $cancelUrl */

$posting = $posted !== [];
$included = static fn (int $index): bool => $posting ? in_array((string) $index, (array) ($posted['include'] ?? []), true) : true;
$chosen = static fn (string $group, string $key, string $fallback): string => $posting ? (string) ($posted[$group][$key] ?? $fallback) : $fallback;
$option = static fn (string $value, string $current, string $label): string => '<option value="' . Html::e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . Html::e($label) . '</option>';
$statusLabels = ['matches' => 'Already on this site', 'new' => 'New', 'dropped' => 'Left out'];
$kindLabels = ['fandom' => 'Fandoms', 'character' => 'Characters', 'relationship' => 'Relationships', 'tag' => 'Tags'];
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="lf-lead">Nothing has been saved yet. Check what was found, adjust the choices and confirm at the end.</p>

<?php if ($preview['refused'] !== []): ?>
    <div class="lf-notice lf-notice--warning" role="note">
        <p>Some files could not be read:</p>
        <ul>
            <?php foreach ($preview['refused'] as $refused): ?>
                <li><?= $refused['name'] !== '' ? '<strong>' . Html::e($refused['name']) . '</strong>: ' : '' ?><?= Html::e($refused['error']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <?php foreach ($preview['works'] as $entry): ?>
        <?php /** @var OtwWork $work */ $work = $entry['work']; $index = (int) $entry['index']; ?>
        <section class="lf-card lf-import-work" aria-labelledby="work-<?= $index ?>-heading">
            <div class="lf-choice">
                <input type="checkbox" id="include-<?= $index ?>" name="include[]" value="<?= $index ?>"<?= $included($index) ? ' checked' : '' ?>>
                <label for="include-<?= $index ?>"><span id="work-<?= $index ?>-heading" class="lf-import-work__title"><?= Html::e($work->title) ?></span></label>
            </div>

            <p class="lf-hint">
                <?= count($work->chapters) === 1 ? '1 chapter' : count($work->chapters) . ' chapters' ?>,
                <?= number_format(max(0, $work->words)) ?> words,
                <?= $work->complete ? 'complete' : 'in progress' ?><?php if ($work->publishedAt !== null): ?>,
                first published <?= Html::e($work->publishedAt->format('Y-m-d')) ?><?php endif; ?><?php if ($work->fileName !== ''): ?>
                (file <?= Html::e($work->fileName) ?>)<?php endif; ?>
            </p>

            <?php if ($entry['bylineWarning']): ?>
                <div class="lf-notice lf-notice--warning" role="note"><p>The file says it is by &ldquo;<?= Html::e($work->byline) ?>&rdquo;, which is not your name here or one of the names you gave. Import it only if it is your own work or you have its author&rsquo;s permission.</p></div>
            <?php endif; ?>

            <?php if ($entry['duplicate'] !== null): ?>
                <div class="lf-notice lf-notice--warning" role="note">
                    <p>You already imported this work as &ldquo;<?= Html::e($entry['duplicate']['title']) ?>&rdquo;.</p>
                    <div class="lf-field">
                        <label for="duplicate-<?= $index ?>">What to do</label>
                        <select id="duplicate-<?= $index ?>" name="duplicate[<?= $index ?>]">
                            <?= $option('skip', $chosen('duplicate', (string) $index, 'skip'), 'Skip it') ?>
                            <?= $option('new', $chosen('duplicate', (string) $index, 'skip'), 'Import it again as a new story') ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($entry['notes'] as $note): ?>
                <p class="lf-hint"><?= Html::e($note) ?></p>
            <?php endforeach; ?>

            <?php foreach ($kindLabels as $kind => $label): ?>
                <?php if (!empty($entry['terms'][$kind])): ?>
                    <h3><?= Html::e($label) ?></h3>
                    <ul class="lf-import-terms">
                        <?php foreach ($entry['terms'][$kind] as $term): ?>
                            <li><?= Html::e($term['name']) ?> <span class="lf-import-terms__status">&ndash; <?= Html::e($statusLabels[$term['status']] ?? $term['status']) ?><?= $term['note'] !== '' ? ' (' . Html::e($term['note']) . ')' : '' ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="lf-field">
                <label for="mode-<?= $index ?>">Save it as</label>
                <select id="mode-<?= $index ?>" name="mode[<?= $index ?>]">
                    <?= $option('draft', $chosen('mode', (string) $index, 'draft'), 'A draft, so you can check it first') ?>
                    <?= $option('submit', $chosen('mode', (string) $index, 'draft'), 'Submit it for publication') ?>
                </select>
            </div>
            <?php if ($entry['sourceNote']): ?>
                <div class="lf-choice">
                    <input type="checkbox" id="source-note-<?= $index ?>" name="source_note[]" value="<?= $index ?>"<?= $posting && in_array((string) $index, (array) ($posted['source_note'] ?? []), true) ? ' checked' : '' ?>>
                    <label for="source-note-<?= $index ?>">Add a line to the story&rsquo;s notes saying where it was first published</label>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="lf-card" aria-labelledby="mapping-heading">
        <h2 id="mapping-heading">Match to this site</h2>
        <p class="lf-hint">Every story here needs a rating. A rating you do not choose leaves the story a draft.</p>

        <?php foreach ($preview['ratings'] as $row): ?>
            <div class="lf-field">
                <label for="rating-<?= Html::e($row['key']) ?>">Rating &ldquo;<?= Html::e($row['source']) ?>&rdquo; (<?= (int) $row['count'] ?>)</label>
                <select id="rating-<?= Html::e($row['key']) ?>" name="rating[<?= Html::e($row['key']) ?>]">
                    <?php $current = $chosen('rating', $row['key'], (string) ($row['suggested'] ?? '')); ?>
                    <?= $option('', $current, 'Choose a rating later') ?>
                    <?php foreach ($preview['ratingOptions'] as $item): ?>
                        <?= $option((string) $item['id'], $current, $item['name']) ?>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <?php foreach ($preview['warnings'] as $row): ?>
            <div class="lf-field">
                <label for="warning-<?= Html::e($row['key']) ?>">Warning &ldquo;<?= Html::e($row['source']) ?>&rdquo; (<?= (int) $row['count'] ?>)</label>
                <select id="warning-<?= Html::e($row['key']) ?>" name="warning[<?= Html::e($row['key']) ?>]">
                    <?php $current = $chosen('warning', $row['key'], (string) ($row['suggested'] ?? '')); ?>
                    <?= $option('', $current, 'Leave it out') ?>
                    <?php foreach ($preview['warningOptions'] as $item): ?>
                        <?= $option((string) $item['id'], $current, $item['name']) ?>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <?php foreach ($preview['categories'] as $row): ?>
            <div class="lf-field">
                <label for="category-<?= Html::e($row['key']) ?>">Category &ldquo;<?= Html::e($row['source']) ?>&rdquo; (<?= (int) $row['count'] ?>)</label>
                <select id="category-<?= Html::e($row['key']) ?>" name="category[<?= Html::e($row['key']) ?>]">
                    <?php $current = $chosen('category', $row['key'], (string) ($row['suggested'] ?? '')); ?>
                    <?= $option('', $current, 'Leave it out') ?>
                    <?php foreach ($preview['categoryOptions'] as $item): ?>
                        <?= $option((string) $item['id'], $current, $item['name']) ?>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <?php if ($preview['ratings'] === [] && $preview['warnings'] === [] && $preview['categories'] === []): ?>
            <p class="lf-hint">The files carry no rating, warnings or categories.</p>
        <?php endif; ?>
    </section>

    <?php if ($preview['series'] !== []): ?>
        <section class="lf-card" aria-labelledby="series-heading">
            <h2 id="series-heading">Series</h2>
            <?php foreach ($preview['series'] as $row): ?>
                <div class="lf-field">
                    <label for="series-<?= Html::e($row['key']) ?>">&ldquo;<?= Html::e($row['name']) ?>&rdquo; (<?= (int) $row['count'] ?> in these files)</label>
                    <select id="series-<?= Html::e($row['key']) ?>" name="series[<?= Html::e($row['key']) ?>]">
                        <?php $current = $chosen('series', $row['key'], (string) $row['default']); ?>
                        <?= $option('none', $current, 'Import without a series') ?>
                        <?= $option('new', $current, 'Make a new series with this name') ?>
                        <?php foreach ($preview['ownSeries'] as $item): ?>
                            <?= $option((string) $item['id'], $current, 'Add to my series ' . $item['title']) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($preview['collections'] !== []): ?>
        <section class="lf-card" aria-labelledby="collections-heading">
            <h2 id="collections-heading">Collections</h2>
            <p class="lf-hint">The other site lists these collections, which may belong to someone else. Here a story can only go into a collection of your own.</p>
            <?php foreach ($preview['collections'] as $row): ?>
                <div class="lf-field">
                    <label for="collection-<?= Html::e($row['key']) ?>">&ldquo;<?= Html::e($row['name']) ?>&rdquo; (<?= (int) $row['count'] ?>)</label>
                    <select id="collection-<?= Html::e($row['key']) ?>" name="collection[<?= Html::e($row['key']) ?>]">
                        <?php $current = $chosen('collection', $row['key'], (string) $row['default']); ?>
                        <?= $option('none', $current, 'Do not put it in a collection') ?>
                        <?php if ($preview['mayStartCollection']): ?>
                            <?= $option('new', $current, 'Make a new collection with this name') ?>
                        <?php endif; ?>
                        <?php foreach ($preview['ownCollections'] as $item): ?>
                            <?= $option((string) $item['id'], $current, 'Add to my collection ' . $item['title']) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="lf-card" aria-labelledby="confirm-heading">
        <h2 id="confirm-heading">Confirm</h2>
        <div class="lf-choice<?= isset($errors['own_work']) ? ' lf-field--error' : '' ?>">
            <input type="checkbox" id="own_work" name="own_work" value="1"<?= $posting && !empty($posted['own_work']) ? ' checked' : '' ?>>
            <label for="own_work">These are my own stories, or I have their author&rsquo;s permission to post them here.</label>
        </div>
        <p>
            <button type="submit" class="lf-button">Import the ticked stories</button>
            <button type="submit" class="lf-button lf-button--quiet" formaction="<?= Html::e($cancelUrl) ?>" formnovalidate>Cancel</button>
        </p>
    </section>
</form>
