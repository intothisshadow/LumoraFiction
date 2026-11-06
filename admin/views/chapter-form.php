<?php

/**
 * The chapter form: a title, optional notes, and the text editor with its Save and Publish buttons.
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
use LumoraFiction\Models\Story;

/** @var Story $story */
/** @var Chapter|null $chapter */
/** @var array{title: string, content: string, content_format: string, notes: string, end_notes: string} $values */
/** @var string $editor trusted markup from the content editor */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var array<string, string> $urls */
/** @var int $revisionCount earlier versions kept of this chapter */
/** @var array{title: int, notes: int, end_notes: int} $limits */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; Chapters of <?= Html::e($story->title) ?></a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form lf-form--wide" novalidate data-lf-guard>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
        <label for="title">Chapter title</label>
        <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $limits['title'] ?>" aria-describedby="title_hint<?= isset($errors['title']) ? ' title_error' : '' ?>">
        <p class="lf-hint" id="title_hint">Optional. A chapter without a title is shown by its number.</p>
        <?= $problem('title') ?>
    </div>

    <details class="lf-details"<?= $values['notes'] !== '' || isset($errors['notes']) ? ' open' : '' ?>>
        <summary>Author&rsquo;s notes for this chapter</summary>
        <div class="lf-field<?= isset($errors['notes']) ? ' lf-field--error' : '' ?>">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="4" aria-describedby="notes_hint<?= isset($errors['notes']) ? ' notes_error' : '' ?>"><?= Html::e($values['notes']) ?></textarea>
            <p class="lf-hint" id="notes_hint">Shown with the chapter. Plain text, up to <?= number_format($limits['notes']) ?> characters.</p>
            <?= $problem('notes') ?>
        </div>
    </details>

    <?= $problem('format') ?>
    <?= $problem('content') ?>

    <?= $editor ?>

    <details class="lf-details"<?= $values['end_notes'] !== '' || isset($errors['end_notes']) ? ' open' : '' ?>>
        <summary>End notes for this chapter</summary>
        <div class="lf-field<?= isset($errors['end_notes']) ? ' lf-field--error' : '' ?>">
            <label for="end_notes">End notes</label>
            <textarea id="end_notes" name="end_notes" rows="4" aria-describedby="end_notes_hint<?= isset($errors['end_notes']) ? ' end_notes_error' : '' ?>"><?= Html::e($values['end_notes']) ?></textarea>
            <p class="lf-hint" id="end_notes_hint">Shown after the text of this chapter, before the buttons to the next one. Plain text, up to <?= number_format($limits['end_notes']) ?> characters. Save with the buttons above.</p>
            <?= $problem('end_notes') ?>
        </div>
    </details>
</form>

<?php if ($chapter !== null): ?>
    <p>
        <a href="<?= Html::e($urls['preview']) ?>">Preview this chapter</a> &middot;
        <a href="<?= Html::e($urls['revisions']) ?>">Earlier versions (<?= (int) $revisionCount ?>)</a> &middot;
        <a href="<?= Html::e($urls['delete']) ?>">Delete this chapter</a>
    </p>
    <form method="post" action="<?= Html::e($urls['trash']) ?>" class="lf-inline-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <button type="submit" class="lf-button lf-button--secondary">Move to the trash</button>
    </form>
    <?php if (is_array($commentControl ?? null)): ?>
        <form method="post" action="<?= Html::e($commentAction) ?>" class="lf-inline-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($commentToken) ?>">
            <input type="hidden" name="story" value="<?= (int) $commentControl['story'] ?>">
            <input type="hidden" name="chapter" value="<?= (int) $commentControl['chapter'] ?>">
            <input type="hidden" name="return" value="chapter">
            <input type="hidden" name="closed" value="<?= $commentControl['closed'] ? '0' : '1' ?>">
            <p><?= $commentControl['closed'] ? 'Comments are turned off for this chapter.' : 'Readers may comment on this chapter.' ?>
                <button type="submit" class="lf-button lf-button--secondary"><?= $commentControl['closed'] ? 'Turn comments on' : 'Turn comments off' ?></button></p>
        </form>
    <?php endif; ?>
<?php endif; ?>
