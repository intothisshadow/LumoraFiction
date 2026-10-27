<?php

/**
 * The story form: details and classification, plus visibility and chapter links once the story exists.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\WorkStatus;

/** @var Story|null $story */
/** @var array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string, cover_alt: string} $values */
/** @var list<array{type: TermType, style: string, options: list<Term>, ids: list<int>, text: string, required: bool}> $fields */
/** @var array<string, string> $languages */
/** @var list<WorkStatus> $workStatuses */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var bool $trusted whether this story goes straight to readers when the writer publishes it */
/** @var array<string, string> $urls */
/** @var int $chapterCount */
/** @var list<array{value: string, label: string, selected: bool}> $downloadChoices the story's download choices, empty when the story is new */
/** @var bool $authorAllowsDownloads whether the owner's default allows downloads */
/** @var bool $downloadsSiteOn whether downloads are switched on for the whole site */
/** @var string $authorProfileUrl */
/** @var list<array{title: string, html: string}> $panels panels added by plugins; the markup is trusted, so the plugin must escape it */
/** @var array{title: int, summary: int, notes: int, disclaimer: int, cover_alt: int} $limits */
/** @var array{thumb: string, full: string, alt: string} $cover the story's current cover, empty strings for none */
/** @var bool $coverAvailable whether this server can process pictures */
/** @var string $coverLimit the largest upload in megabytes */
/** @var string $assetVersion */

/** The attributes that tie a field to its hint and, when it failed, its message. */
$describe = static function (string $name, bool $hint = true) use ($errors): string {
    $ids = array_filter([$hint ? $name . '_hint' : '', isset($errors[$name]) ? $name . '_error' : '']);

    return $ids === [] ? '' : ' aria-describedby="' . Html::e(implode(' ', $ids)) . '"';
};
$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; My stories</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" enctype="multipart/form-data" novalidate data-lf-guard>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <section class="lf-card" aria-labelledby="details-heading">
        <h2 id="details-heading">Details</h2>

        <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $limits['title'] ?>" required<?= $describe('title', false) ?>>
            <?= $problem('title') ?>
        </div>

        <div class="lf-field<?= isset($errors['summary']) ? ' lf-field--error' : '' ?>">
            <label for="summary">Summary</label>
            <textarea id="summary" name="summary" rows="5"<?= $describe('summary') ?>><?= Html::e($values['summary']) ?></textarea>
            <p class="lf-hint" id="summary_hint">A short description readers see before they start. Up to <?= number_format($limits['summary']) ?> characters.</p>
            <?= $problem('summary') ?>
        </div>

        <div class="lf-field<?= isset($errors['notes']) ? ' lf-field--error' : '' ?>">
            <label for="notes">Author&rsquo;s notes</label>
            <textarea id="notes" name="notes" rows="3"<?= $describe('notes') ?>><?= Html::e($values['notes']) ?></textarea>
            <p class="lf-hint" id="notes_hint">Notes about the story as a whole. Optional.</p>
            <?= $problem('notes') ?>
        </div>

        <div class="lf-field<?= isset($errors['disclaimer']) ? ' lf-field--error' : '' ?>">
            <label for="disclaimer">Disclaimer</label>
            <textarea id="disclaimer" name="disclaimer" rows="3"<?= $describe('disclaimer') ?>><?= Html::e($values['disclaimer']) ?></textarea>
            <p class="lf-hint" id="disclaimer_hint">For example who owns the characters. Optional.</p>
            <?= $problem('disclaimer') ?>
        </div>

        <div class="lf-field<?= isset($errors['language']) ? ' lf-field--error' : '' ?>">
            <label for="language">Language</label>
            <select id="language" name="language"<?= $describe('language', false) ?>>
                <?php foreach ($languages as $code => $name): ?>
                    <option value="<?= Html::e($code) ?>"<?= $values['language'] === $code ? ' selected' : '' ?>><?= Html::e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $problem('language') ?>
        </div>

        <div class="lf-field<?= isset($errors['work_status']) ? ' lf-field--error' : '' ?>">
            <label for="work_status">Where the story stands</label>
            <select id="work_status" name="work_status"<?= $describe('work_status', false) ?>>
                <?php foreach ($workStatuses as $status): ?>
                    <option value="<?= Html::e($status->value) ?>"<?= $values['work_status'] === $status->value ? ' selected' : '' ?>><?= Html::e($status->label()) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $problem('work_status') ?>
        </div>
    </section>

    <?php if ($coverAvailable): ?>
        <section class="lf-card" aria-labelledby="cover-heading">
            <h2 id="cover-heading">Cover</h2>

            <div class="lf-cover-field">
                <?php if ($cover['full'] !== ''): ?>
                    <p class="lf-cover-field__current">
                        <img class="lf-cover-field__image" src="<?= Html::e($cover['thumb']) ?>" alt="<?= Html::e($cover['alt']) ?>">
                    </p>
                <?php endif; ?>

                <div class="lf-cover-field__controls">
                    <div class="lf-field<?= isset($errors['cover']) ? ' lf-field--error' : '' ?>">
                        <label for="cover"><?= $cover['full'] !== '' ? 'Replace the cover' : 'Cover picture' ?></label>
                        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"<?= $describe('cover') ?>>
                        <p class="lf-hint" id="cover_hint">A JPEG, PNG or WebP picture, up to <?= Html::e($coverLimit) ?> MB. It is resized and saved again on this site; other details in the file, such as where a photo was taken, are not kept. Optional.</p>
                        <?= $problem('cover') ?>
                    </div>

                    <?php // Shown by the script once a picture is chosen; without a script the whole picture is used. ?>
                    <div class="lf-cropper" data-lf-cropper data-lf-ratio="1.5" hidden>
                        <p class="lf-hint" id="cover_crop_hint">The story page shows the cover in a 3 by 2 frame. Drag the frame over the picture, or use the sliders, to choose the part to keep.</p>
                        <canvas class="lf-cropper__canvas" width="480" height="320" role="img" aria-label="Preview of the picture with the frame"></canvas>
                        <div class="lf-cropper__controls">
                            <div class="lf-choice">
                                <input type="checkbox" id="cover_crop_use" data-lf-crop-use>
                                <label for="cover_crop_use">Crop the picture to this frame</label>
                            </div>
                            <div class="lf-field"><label for="cover_crop_zoom">Zoom</label><input type="range" id="cover_crop_zoom" min="100" max="400" value="100" step="1" data-lf-crop-zoom></div>
                            <div class="lf-field"><label for="cover_crop_left">Left to right</label><input type="range" id="cover_crop_left" min="0" max="100" value="50" step="1" data-lf-crop-x></div>
                            <div class="lf-field"><label for="cover_crop_top">Top to bottom</label><input type="range" id="cover_crop_top" min="0" max="100" value="50" step="1" data-lf-crop-y></div>
                        </div>
                        <input type="hidden" name="crop_x" value="">
                        <input type="hidden" name="crop_y" value="">
                        <input type="hidden" name="crop_w" value="">
                        <input type="hidden" name="crop_h" value="">
                    </div>

                    <div class="lf-field<?= isset($errors['cover_alt']) ? ' lf-field--error' : '' ?>">
                        <label for="cover_alt">Describe the cover</label>
                        <input type="text" id="cover_alt" name="cover_alt" value="<?= Html::e($values['cover_alt']) ?>" maxlength="<?= (int) $limits['cover_alt'] ?>" autocomplete="off"<?= $describe('cover_alt') ?>>
                        <p class="lf-hint" id="cover_alt_hint">What the picture shows, for readers who cannot see it. Needed whenever a cover is set.</p>
                        <?= $problem('cover_alt') ?>
                    </div>

                    <?php if ($cover['full'] !== ''): ?>
                        <div class="lf-choice">
                            <input type="checkbox" id="cover_remove" name="cover_remove" value="1">
                            <label for="cover_remove">Remove the cover</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($fields !== []): ?>
        <section class="lf-card" aria-labelledby="classification-heading">
            <h2 id="classification-heading">Classification</h2>
            <p class="lf-hint">A rating says who the story suits and a warning says what it contains. They are separate on purpose, so fill in both.</p>

            <?php foreach ($fields as $field): ?>
                <?php
                $type = $field['type'];
                $kind = $type->value;
                $label = $type->isSingleChoice() ? $type->label() : $type->pluralLabel();
                $required = $field['required'] ? ' <span class="lf-required">(needed before publishing)</span>' : '';

                // A list with nothing to choose from is left off the form, not shown empty, and is never required.
                if ($field['style'] !== 'names' && $field['options'] === []) {
                    continue;
                }

                $described = array_values(array_filter($field['options'], static fn (Term $term): bool => $term->description !== ''));
                ?>
                <?php if ($field['style'] === 'single'): ?>
                    <div class="lf-field<?= isset($errors[$kind]) ? ' lf-field--error' : '' ?>">
                        <label for="<?= Html::e($kind) ?>"><?= Html::e($label) ?><?= $required ?></label>
                        <select id="<?= Html::e($kind) ?>" name="term_<?= Html::e($kind) ?>"<?= $describe($kind, $described !== []) ?>>
                            <option value="">Not chosen</option>
                            <?php foreach ($field['options'] as $term): ?>
                                <option value="<?= (int) $term->id ?>"<?= in_array($term->id, $field['ids'], true) ? ' selected' : '' ?>><?= Html::e($term->name) ?><?= $term->hidden ? ' (no longer offered)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($described !== []): ?>
                            <p class="lf-hint" id="<?= Html::e($kind) ?>_hint"><?= Html::e(implode(' ', array_map(static fn (Term $term): string => $term->name . ': ' . $term->description, $described))) ?></p>
                        <?php endif; ?>
                        <?= $problem($kind) ?>
                    </div>
                <?php elseif ($field['style'] === 'choices'): ?>
                    <fieldset class="lf-fieldset<?= isset($errors[$kind]) ? ' lf-field--error' : '' ?>" id="<?= Html::e($kind) ?>" tabindex="-1"<?= $describe($kind, false) ?>>
                        <legend><?= Html::e($label) ?><?= $required ?></legend>
                        <?php foreach ($field['options'] as $term): ?>
                            <div class="lf-choice">
                                <input type="checkbox" id="<?= Html::e($kind) ?>_<?= (int) $term->id ?>" name="terms_<?= Html::e($kind) ?>[]" value="<?= (int) $term->id ?>"<?= in_array($term->id, $field['ids'], true) ? ' checked' : '' ?><?= $term->description !== '' ? ' aria-describedby="' . Html::e($kind) . '_' . (int) $term->id . '_hint"' : '' ?>>
                                <label for="<?= Html::e($kind) ?>_<?= (int) $term->id ?>"><?= Html::e($term->name) ?><?= $term->hidden ? ' (no longer offered)' : '' ?></label>
                                <?php if ($term->description !== ''): ?><p class="lf-hint" id="<?= Html::e($kind) ?>_<?= (int) $term->id ?>_hint"><?= Html::e($term->description) ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?= $problem($kind) ?>
                    </fieldset>
                <?php else: ?>
                    <div class="lf-field<?= isset($errors[$kind]) ? ' lf-field--error' : '' ?>">
                        <label for="<?= Html::e($kind) ?>"><?= Html::e($label) ?><?= $required ?></label>
                        <input type="text" id="<?= Html::e($kind) ?>" name="names_<?= Html::e($kind) ?>" value="<?= Html::e($field['text']) ?>" autocomplete="off" data-lf-suggest="<?= Html::e($kind) ?>"<?= $describe($kind) ?>>
                        <p class="lf-hint" id="<?= Html::e($kind) ?>_hint">Separate with commas, up to <?= (int) $type->maximumPerStory() ?>. Matching entries appear as you type. A name that matches an existing entry or its alias uses it; a new one is added and checked by an administrator.</p>
                        <?= $problem($kind) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($story !== null && $downloadChoices !== []): ?>
        <section class="lf-card" aria-labelledby="downloads-heading">
            <h2 id="downloads-heading">Downloads</h2>
            <div class="lf-field">
                <label for="download_choice">Can readers download this story?</label>
                <select id="download_choice" name="download_choice" aria-describedby="download_choice_hint">
                    <?php foreach ($downloadChoices as $choice): ?>
                        <option value="<?= Html::e($choice['value']) ?>"<?= $choice['selected'] ? ' selected' : '' ?>><?= Html::e($choice['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="lf-hint" id="download_choice_hint">The author's default for all their stories is <?= $authorAllowsDownloads ? 'to allow downloads' : 'not to allow downloads' ?><?= $authorProfileUrl === '' ? '' : ', which you can change on <a href="' . Html::e($authorProfileUrl) . '">your author profile</a>' ?>. A choice made here for this one story wins over that default.<?= $downloadsSiteOn ? '' : ' Downloads are switched off for the whole site at the moment, so nothing here has an effect until an Administrator switches them on.' ?></p>
            </div>
        </section>
    <?php endif; ?>

    <p><button type="submit" class="lf-button"><?= $story === null ? 'Save story' : 'Save changes' ?></button></p>
</form>

<?php if ($coverAvailable): ?>
    <script src="<?= Html::e(AdminUrl::asset('js/cover-crop.js', $assetVersion ?? '')) ?>" defer></script>
<?php endif; ?>

<?php if ($fields !== []): ?>
    <script src="<?= Html::e(AdminUrl::asset('js/term-suggest.js', $assetVersion ?? '')) ?>" data-lf-suggest-url="<?= Html::e(AdminUrl::to('taxonomy/suggest')) ?>" defer></script>
<?php endif; ?>

<?php if ($story !== null): ?>
    <section class="lf-card" aria-labelledby="visibility-heading">
        <h2 id="visibility-heading">Visibility</h2>
        <p>This story is <strong><?= Html::e(strtolower($story->state->label())) ?></strong>.
            <?php if ($story->state === PublicationState::Pending): ?>
                It is waiting for approval and is hidden from readers until then.
            <?php elseif ($story->state === PublicationState::Published): ?>
                Readers can see it once it has at least one published chapter.
            <?php else: ?>
                Only you and the site staff can see it.
            <?php endif; ?>
        </p>

        <?php if ($story->wasRejected()): ?>
            <div class="lf-notice lf-notice--warning lf-rejection" role="note">
                <p><strong>This story was not approved.</strong> The reviewer wrote:</p>
                <blockquote class="lf-rejection__reason"><?= nl2br(Html::e($story->rejectionReason)) ?></blockquote>
                <p>Change what they asked for, then send it for approval again.</p>
            </div>
        <?php endif; ?>

        <?php if ($story->state === PublicationState::Published || $story->state === PublicationState::Pending): ?>
            <form method="post" action="<?= Html::e($urls['unpublish']) ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <p><button type="submit" class="lf-button lf-button--secondary"><?= $story->state === PublicationState::Pending ? 'Withdraw from approval' : 'Unpublish' ?></button></p>
            </form>
        <?php endif; ?>

        <?php if ($story->state !== PublicationState::Published && ($story->state !== PublicationState::Pending || $trusted)): ?>
            <form method="post" action="<?= Html::e($urls['publish']) ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <p><button type="submit" class="lf-button"><?= $trusted ? 'Publish the story' : 'Send for approval' ?></button></p>
            </form>
        <?php endif; ?>
    </section>

    <section class="lf-card" aria-labelledby="chapters-heading">
        <h2 id="chapters-heading">Chapters</h2>
        <p><?= (int) $chapterCount === 0 ? 'This story has no chapters yet.' : (int) $chapterCount . ($chapterCount === 1 ? ' chapter' : ' chapters') . ' &middot; ' . number_format($story->wordCount) . ' words' ?></p>
        <p>
            <a href="<?= Html::e($urls['addChapter']) ?>" class="lf-button lf-button--secondary">Add a chapter</a>
            <?php if ($chapterCount > 0): ?><a href="<?= Html::e($urls['chapters']) ?>">Manage chapters</a><?php endif; ?>
        </p>
    </section>

    <?php foreach ($panels as $index => $panel): ?>
        <section class="lf-card" aria-labelledby="lf-story-panel-<?= (int) $index ?>">
            <h2 id="lf-story-panel-<?= (int) $index ?>"><?= Html::e($panel['title']) ?></h2>
            <?= $panel['html'] ?>
        </section>
    <?php endforeach; ?>

    <section class="lf-card" aria-labelledby="more-heading">
        <h2 id="more-heading">More</h2>
        <p><a href="<?= Html::e($urls['preview']) ?>">Preview the story</a> &middot; <a href="<?= Html::e($urls['delete']) ?>">Delete the story</a></p>
        <div class="lf-inline-actions">
            <form method="post" action="<?= Html::e($urls['duplicate']) ?>" class="lf-inline-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <button type="submit" class="lf-button lf-button--secondary">Make a copy</button>
            </form>
            <form method="post" action="<?= Html::e($urls['trash']) ?>" class="lf-inline-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <button type="submit" class="lf-button lf-button--secondary">Move to the trash</button>
            </form>
        </div>
        <p class="lf-hint">A copy has the same details and classification, and its chapters as drafts, but no cover picture. The trash keeps a story, unpublished, until you restore it or the retention period ends.</p>
    </section>
<?php endif; ?>
