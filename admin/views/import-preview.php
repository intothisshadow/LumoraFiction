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
$hasDownloads = array_filter($preview['works'], static fn (array $entry): bool => !$entry['general']) !== [];
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

            <?php if ($entry['general']): ?>
                <?php
                $field = static fn (string $name, string $fallback): string => $posting ? (string) ($posted['meta'][$index][$name] ?? $fallback) : $fallback;
                $ticked = static fn (string $name, int $id): bool => $posting && in_array((string) $id, (array) ($posted['meta'][$index][$name] ?? []), true);
                ?>
                <p class="lf-hint">Read as <?= $entry['files'] > 1 ? 'an ordinary HTML file for each of ' . (int) $entry['files'] . ' files, joined as chapters' : 'an ordinary HTML file' ?>. It carries no rating, tags or author of its own, so choose them below. This story will be yours.</p>

                <?php if ($entry['canSplit']): ?>
                    <div class="lf-field">
                        <label for="split-<?= $index ?>">How the text is cut into chapters</label>
                        <select id="split-<?= $index ?>" name="split[<?= $index ?>]">
                            <?php foreach ($preview['splitModes'] as $value => $label): ?>
                                <?= $option($value, $posting ? (string) ($posted['split'][$index] ?? $entry['split']) : $entry['split'], $label) ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="lf-button lf-button--quiet" name="resplit" value="<?= $index ?>" formaction="<?= Html::e($action . '/split') ?>" formnovalidate>Cut it again</button>
                    </div>
                <?php endif; ?>

                <?php if (count($entry['chapterList']) > 1): ?>
                    <table class="lf-table lf-import-chapters">
                        <caption>Chapters found. To change the order, change the numbers.</caption>
                        <thead>
                            <tr><th scope="col">Order</th><th scope="col">Chapter</th><th scope="col">Words</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entry['chapterList'] as $position => $chapter): ?>
                                <tr>
                                    <td>
                                        <label class="lf-visually-hidden" for="order-<?= $index ?>-<?= $position ?>">Place of <?= Html::e($chapter['title'] !== '' ? $chapter['title'] : 'chapter ' . $chapter['number']) ?></label>
                                        <input type="text" id="order-<?= $index ?>-<?= $position ?>" name="order[<?= $index ?>][<?= $position ?>]" inputmode="numeric" size="3" maxlength="4" value="<?= Html::e($posting ? (string) ($posted['order'][$index][$position] ?? $position + 1) : (string) ($position + 1)) ?>">
                                    </td>
                                    <th scope="row"><?= Html::e($chapter['title'] !== '' ? $chapter['title'] : 'Chapter ' . $chapter['number']) ?></th>
                                    <td><?= number_format($chapter['words']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div class="lf-field">
                    <label for="meta-title-<?= $index ?>">Title</label>
                    <input type="text" id="meta-title-<?= $index ?>" name="meta[<?= $index ?>][title]" value="<?= Html::e($field('title', $work->title)) ?>" maxlength="255">
                </div>
                <div class="lf-field">
                    <label for="meta-summary-<?= $index ?>">Summary</label>
                    <textarea id="meta-summary-<?= $index ?>" name="meta[<?= $index ?>][summary]" rows="3"><?= Html::e($field('summary', '')) ?></textarea>
                </div>
                <div class="lf-field">
                    <label for="meta-notes-<?= $index ?>">Notes for readers</label>
                    <textarea id="meta-notes-<?= $index ?>" name="meta[<?= $index ?>][notes]" rows="2"><?= Html::e($field('notes', '')) ?></textarea>
                </div>
                <div class="lf-field">
                    <label for="meta-language-<?= $index ?>">Language code</label>
                    <input type="text" id="meta-language-<?= $index ?>" name="meta[<?= $index ?>][language]" value="<?= Html::e($field('language', $work->language !== '' ? $work->language : 'en')) ?>" maxlength="3" size="4">
                </div>
                <div class="lf-field">
                    <label for="meta-status-<?= $index ?>">Where the story stands</label>
                    <select id="meta-status-<?= $index ?>" name="meta[<?= $index ?>][status]">
                        <?php foreach ($preview['statusOptions'] as $item): ?>
                            <?= $option($item['value'], $field('status', 'ongoing'), $item['label']) ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lf-field">
                    <label for="meta-rating-<?= $index ?>">Rating</label>
                    <select id="meta-rating-<?= $index ?>" name="meta[<?= $index ?>][rating]">
                        <?= $option('', $field('rating', ''), 'Choose a rating later (it stays a draft until you do)') ?>
                        <?php foreach ($preview['ratingOptions'] as $item): ?>
                            <?= $option((string) $item['id'], $field('rating', ''), $item['name']) ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($preview['warningOptions'] !== []): ?>
                    <fieldset class="lf-field">
                        <legend>Warnings</legend>
                        <?php foreach ($preview['warningOptions'] as $item): ?>
                            <div class="lf-choice">
                                <input type="checkbox" id="meta-warning-<?= $index ?>-<?= (int) $item['id'] ?>" name="meta[<?= $index ?>][warning][]" value="<?= (int) $item['id'] ?>"<?= $ticked('warning', (int) $item['id']) ? ' checked' : '' ?>>
                                <label for="meta-warning-<?= $index ?>-<?= (int) $item['id'] ?>"><?= Html::e($item['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ($preview['genreOptions'] !== []): ?>
                    <fieldset class="lf-field">
                        <legend>Genres</legend>
                        <?php foreach ($preview['genreOptions'] as $item): ?>
                            <div class="lf-choice">
                                <input type="checkbox" id="meta-genre-<?= $index ?>-<?= (int) $item['id'] ?>" name="meta[<?= $index ?>][genre][]" value="<?= (int) $item['id'] ?>"<?= $ticked('genre', (int) $item['id']) ? ' checked' : '' ?>>
                                <label for="meta-genre-<?= $index ?>-<?= (int) $item['id'] ?>"><?= Html::e($item['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php foreach ($preview['kinds'] as $kind => $info): ?>
                    <div class="lf-field">
                        <label for="meta-<?= Html::e($kind) ?>-<?= $index ?>"><?= Html::e($info['label']) ?></label>
                        <input type="text" id="meta-<?= Html::e($kind) ?>-<?= $index ?>" name="meta[<?= $index ?>][<?= Html::e($kind) ?>]" value="<?= Html::e($field($kind, '')) ?>" aria-describedby="meta-<?= Html::e($kind) ?>-<?= $index ?>-hint">
                        <p class="lf-hint" id="meta-<?= Html::e($kind) ?>-<?= $index ?>-hint">Separate with commas. A name that is already on this site uses it<?= $info['proposable'] ? '; a new one is added as a proposal for an administrator to review' : '; a new one is left out, since only administrators add to this list' ?>.</p>
                    </div>
                <?php endforeach; ?>

                <div class="lf-field">
                    <label for="meta-published-<?= $index ?>">Date first published (optional)</label>
                    <input type="date" id="meta-published-<?= $index ?>" name="meta[<?= $index ?>][published]" value="<?= Html::e($field('published', '')) ?>" aria-describedby="meta-published-<?= $index ?>-hint">
                    <p class="lf-hint" id="meta-published-<?= $index ?>-hint">The dates in the file are not trusted. Without a date here, the story is dated today.</p>
                </div>
            <?php endif; ?>

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

    <?php if ($hasDownloads): ?>
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
    <?php endif; ?>

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
