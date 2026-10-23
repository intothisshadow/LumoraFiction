<?php

/**
 * Admin view: how long trashed items and earlier chapter versions are kept.
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

/** @var array{trash_days: int, revisions: int} $values */
/** @var array<string, string> $errors */
/** @var int $maxDays */
/** @var int $maxRevisions */
/** @var string $csrfToken */
/** @var string $action */
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

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Trash</legend>
        <p class="lf-hint">A story or chapter that a writer moves to the trash can be restored until this many days have passed. After that it is deleted for good, along with the earlier versions of a chapter. The sweep happens when someone opens the Trash page.</p>

        <div class="lf-field<?= isset($errors['trash_days']) ? ' lf-field--error' : '' ?>">
            <label for="trash_days">Days to keep trashed items</label>
            <input type="text" inputmode="numeric" id="trash_days" name="trash_days" value="<?= Html::e((string) $values['trash_days']) ?>" maxlength="4" aria-describedby="trash_days_hint<?= isset($errors['trash_days']) ? ' trash_days_error' : '' ?>">
            <p class="lf-hint" id="trash_days_hint">From 0 to <?= (int) $maxDays ?>. Use 0 to keep them until someone deletes them. The usual choice is 30.</p>
            <?php if (isset($errors['trash_days'])): ?><p class="lf-error" id="trash_days_error"><?= Html::e($errors['trash_days']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Earlier chapter versions</legend>
        <p class="lf-hint">Each time a change replaces a chapter&rsquo;s text, title or notes, the version it had is kept so the writer can go back to it. Only the newest are kept for each chapter, so the oldest go first.</p>

        <div class="lf-field<?= isset($errors['revisions']) ? ' lf-field--error' : '' ?>">
            <label for="revisions">Earlier versions to keep for each chapter</label>
            <input type="text" inputmode="numeric" id="revisions" name="revisions" value="<?= Html::e((string) $values['revisions']) ?>" maxlength="3" aria-describedby="revisions_hint<?= isset($errors['revisions']) ? ' revisions_error' : '' ?>">
            <p class="lf-hint" id="revisions_hint">From 1 to <?= (int) $maxRevisions ?>. Long chapters take space in the database, so a lower number saves room on a small host. The usual choice is 20.</p>
            <?php if (isset($errors['revisions'])): ?><p class="lf-error" id="revisions_error"><?= Html::e($errors['revisions']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
