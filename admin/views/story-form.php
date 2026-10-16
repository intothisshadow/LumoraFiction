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

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\WorkStatus;

/** @var Story|null $story */
/** @var array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string} $values */
/** @var list<array{type: TermType, style: string, options: list<Term>, ids: list<int>, text: string, required: bool}> $fields */
/** @var array<string, string> $languages */
/** @var list<WorkStatus> $workStatuses */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var bool $trusted whether the writer may publish without review */
/** @var array<string, string> $urls */
/** @var int $chapterCount */
/** @var array{title: int, summary: int, notes: int, disclaimer: int} $limits */

/** The attributes that tie a field to its hint and, when it failed, its message. */
$describe = static function (string $name, bool $hint = true) use ($errors): string {
    $ids = array_filter([$hint ? $name . '_hint' : '', isset($errors[$name]) ? $name . '_error' : '']);

    return $ids === [] ? '' : ' aria-describedby="' . Html::e(implode(' ', $ids)) . '"';
};
$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; My stories</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate data-lf-guard>
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
                ?>
                <?php if ($field['style'] === 'single'): ?>
                    <div class="lf-field<?= isset($errors[$kind]) ? ' lf-field--error' : '' ?>">
                        <label for="<?= Html::e($kind) ?>"><?= Html::e($label) ?><?= $required ?></label>
                        <select id="<?= Html::e($kind) ?>" name="term_<?= Html::e($kind) ?>"<?= $describe($kind, false) ?>>
                            <option value="">Not chosen</option>
                            <?php foreach ($field['options'] as $term): ?>
                                <option value="<?= (int) $term->id ?>"<?= in_array($term->id, $field['ids'], true) ? ' selected' : '' ?>><?= Html::e($term->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $problem($kind) ?>
                    </div>
                <?php elseif ($field['style'] === 'choices'): ?>
                    <fieldset class="lf-fieldset<?= isset($errors[$kind]) ? ' lf-field--error' : '' ?>" id="<?= Html::e($kind) ?>" tabindex="-1"<?= $describe($kind, false) ?>>
                        <legend><?= Html::e($label) ?><?= $required ?></legend>
                        <?php if ($field['options'] === []): ?>
                            <p class="lf-hint">None have been set up yet.</p>
                        <?php endif; ?>
                        <?php foreach ($field['options'] as $term): ?>
                            <div class="lf-choice">
                                <input type="checkbox" id="<?= Html::e($kind) ?>_<?= (int) $term->id ?>" name="terms_<?= Html::e($kind) ?>[]" value="<?= (int) $term->id ?>"<?= in_array($term->id, $field['ids'], true) ? ' checked' : '' ?>>
                                <label for="<?= Html::e($kind) ?>_<?= (int) $term->id ?>"><?= Html::e($term->name) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?= $problem($kind) ?>
                    </fieldset>
                <?php else: ?>
                    <div class="lf-field<?= isset($errors[$kind]) ? ' lf-field--error' : '' ?>">
                        <label for="<?= Html::e($kind) ?>"><?= Html::e($label) ?><?= $required ?></label>
                        <input type="text" id="<?= Html::e($kind) ?>" name="names_<?= Html::e($kind) ?>" value="<?= Html::e($field['text']) ?>" autocomplete="off"<?= $describe($kind) ?>>
                        <p class="lf-hint" id="<?= Html::e($kind) ?>_hint">Separate with commas, up to <?= (int) $type->maximumPerStory() ?>. A name that matches an existing entry or its alias uses it; a new one is added and checked by an administrator.</p>
                        <?= $problem($kind) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <p><button type="submit" class="lf-button"><?= $story === null ? 'Save story' : 'Save changes' ?></button></p>
</form>

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

    <section class="lf-card" aria-labelledby="more-heading">
        <h2 id="more-heading">More</h2>
        <p><a href="<?= Html::e($urls['preview']) ?>">Preview the story</a> &middot; <a href="<?= Html::e($urls['delete']) ?>">Delete the story</a></p>
    </section>
<?php endif; ?>
