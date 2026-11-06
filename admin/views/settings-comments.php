<?php

/**
 * Settings > Comments.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Services\CommentSettings;

/** @var array{enabled: bool, trust: string, author_toggle: bool, edit_minutes: int, report_threshold: int} $values */
/** @var array<string, string> $errors */
/** @var int $maxMinutes */
/** @var int $maxThreshold */
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

<p class="lf-lead">Readers who are signed in can leave a plain-text comment under a chapter. Turning comments off hides the form and the list but deletes nothing. Spam protection for comment forms is set under Settings &rsaquo; Spam protection.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Switch</legend>
        <div class="lf-choice">
            <input type="checkbox" id="enabled" name="enabled" value="1"<?= $values['enabled'] ? ' checked' : '' ?>>
            <label for="enabled">Readers may comment on chapters</label>
        </div>
        <div class="lf-choice">
            <input type="checkbox" id="author_toggle" name="author_toggle" value="1"<?= $values['author_toggle'] ? ' checked' : '' ?>>
            <label for="author_toggle">Writers may turn comments off for their own stories and chapters</label>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Who needs approval</legend>
        <div class="lf-choice">
            <input type="radio" id="trust_first_held" name="trust" value="<?= Html::e(CommentSettings::TRUST_FIRST_HELD) ?>"<?= $values['trust'] === CommentSettings::TRUST_FIRST_HELD ? ' checked' : '' ?>>
            <label for="trust_first_held">Hold a person&rsquo;s first comment for approval (recommended)</label>
        </div>
        <p class="lf-hint">Once one of their comments has been published, their later comments appear at once. A story&rsquo;s own writer and staff are never held.</p>
        <div class="lf-choice">
            <input type="radio" id="trust_open" name="trust" value="<?= Html::e(CommentSettings::TRUST_OPEN) ?>"<?= $values['trust'] === CommentSettings::TRUST_OPEN ? ' checked' : '' ?>>
            <label for="trust_open">Publish every comment at once</label>
        </div>
        <p class="lf-hint">Comments the spam checks suspect are still kept back, and you can hold or block one account at any time from the Comments screen.</p>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Limits</legend>

        <div class="lf-field<?= isset($errors['edit_minutes']) ? ' lf-field--error' : '' ?>">
            <label for="edit_minutes">Minutes a commenter may edit their comment</label>
            <input type="text" inputmode="numeric" id="edit_minutes" name="edit_minutes" value="<?= Html::e((string) $values['edit_minutes']) ?>" maxlength="4" aria-describedby="edit_minutes_hint<?= isset($errors['edit_minutes']) ? ' edit_minutes_error' : '' ?>">
            <p class="lf-hint" id="edit_minutes_hint">From 0 to <?= (int) $maxMinutes ?>. Use 0 to allow no editing. A commenter can always delete their own comment, and nobody else can change someone&rsquo;s words.</p>
            <?php if (isset($errors['edit_minutes'])): ?><p class="lf-error" id="edit_minutes_error"><?= Html::e($errors['edit_minutes']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['report_threshold']) ? ' lf-field--error' : '' ?>">
            <label for="report_threshold">Reports that hide a comment</label>
            <input type="text" inputmode="numeric" id="report_threshold" name="report_threshold" value="<?= Html::e((string) $values['report_threshold']) ?>" maxlength="2" aria-describedby="report_threshold_hint<?= isset($errors['report_threshold']) ? ' report_threshold_error' : '' ?>">
            <p class="lf-hint" id="report_threshold_hint">From 1 to <?= (int) $maxThreshold ?>. A comment reported by this many different readers is hidden until a moderator has looked at it.</p>
            <?php if (isset($errors['report_threshold'])): ?><p class="lf-error" id="report_threshold_error"><?= Html::e($errors['report_threshold']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
