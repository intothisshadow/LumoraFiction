<?php

/**
 * Admin view: the confirmation page for reinstating a suspended account.
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
/** @var bool $needsConfirmation whether they must confirm their email address before signing in */
/** @var string $reason */
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
        <h2>Reinstate <?= Html::e($person->displayName) ?>?</h2>
        <p>They can sign in and write again.<?= $needsConfirmation ? ' They never confirmed their email address, so they will have to do that first.' : '' ?> Stories that were taken out of view stay drafts; the author or an editor publishes them again when ready.</p>

        <div class="lf-field">
            <label for="reason">Reason (optional)</label>
            <textarea id="reason" name="reason" rows="4" maxlength="<?= (int) $limit ?>" aria-describedby="reason_hint"><?= Html::e($reason) ?></textarea>
            <p class="lf-hint" id="reason_hint">Kept in the activity log. Up to <?= number_format($limit) ?> characters.</p>
        </div>

        <p>
            <button type="submit" class="lf-button">Reinstate the account</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
