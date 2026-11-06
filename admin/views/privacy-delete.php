<?php

/**
 * Admin view: the confirmation shown before an account is deleted for a privacy request.
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
use LumoraFiction\Models\User;

/** @var User $person */
/** @var array<string, string> $errors */
/** @var bool $keepsStories */
/** @var string $neutralName */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
/** @var string $settingsUrl */
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $text): ?>
                <li><?= $field === 'form' ? Html::e($text) : '<a href="#' . Html::e($field) . '">' . Html::e($text) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Delete the account of &ldquo;<?= Html::e($person->displayName) ?>&rdquo;?</h2>
        <p>This removes the email address, login name, display name, password, author text and picture, and the bookmarks, reading history, follows and notifications. They can no longer sign in. This cannot be undone.</p>
        <p><?= $keepsStories
            ? 'Their stories are kept and shown under the name &ldquo;' . Html::e($neutralName) . '&rdquo;.'
            : 'Their stories, with all their chapters, are deleted too.' ?> This is set under <a href="<?= Html::e($settingsUrl) ?>">Settings &rsaquo; Privacy</a>.</p>

        <div class="lf-field<?= isset($errors['confirm']) ? ' lf-field--error' : '' ?>">
            <label for="confirm">Type their display name to confirm</label>
            <input type="text" id="confirm" name="confirm" value="" autocomplete="off" required aria-describedby="confirm_hint<?= isset($errors['confirm']) ? ' confirm_error' : '' ?>">
            <p class="lf-hint" id="confirm_hint">Type <strong><?= Html::e($person->displayName) ?></strong> exactly as shown.</p>
            <?php if (isset($errors['confirm'])): ?><p class="lf-error" id="confirm_error"><?= Html::e($errors['confirm']) ?></p><?php endif; ?>
        </div>

        <p>
            <button type="submit" class="lf-button">Delete the account</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
