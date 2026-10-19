<?php

/**
 * Admin view: the confirmation page for suspending an account.
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
use LumoraFiction\Models\User;

/** @var User $person */
/** @var string $reason */
/** @var bool $unpublish */
/** @var int $liveStories published or waiting stories */
/** @var int $limit */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?= $errorSummary ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Suspend <?= Html::e($person->displayName) ?>?</h2>
        <p>They are signed out at once and cannot sign in or write until you reinstate them. Nothing is deleted.</p>

        <div class="lf-field">
            <label for="unpublish_stories">
                <input type="checkbox" id="unpublish_stories" name="unpublish" value="1"<?= $unpublish ? ' checked' : '' ?>>
                Also take their stories out of view
            </label>
            <p class="lf-hint"><?= $liveStories === 0 ? 'They have no published or waiting stories.' : (int) $liveStories . ($liveStories === 1 ? ' story is' : ' stories are') . ' published or waiting for approval. They become drafts again and disappear from the site at once. The stories, chapters and classification are kept.' ?></p>
        </div>

        <div class="lf-field<?= isset($errors['form']) ? ' lf-field--error' : '' ?>">
            <label for="reason">Reason (optional)</label>
            <textarea id="reason" name="reason" rows="4" maxlength="<?= (int) $limit ?>" aria-describedby="reason_hint"><?= Html::e($reason) ?></textarea>
            <p class="lf-hint" id="reason_hint">Kept in the activity log. If you take their stories out of view, the reason is kept with each of them too. Up to <?= number_format($limit) ?> characters.</p>
        </div>

        <p>
            <button type="submit" class="lf-button">Suspend the account</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
