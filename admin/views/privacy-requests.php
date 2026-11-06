<?php

/**
 * Admin view: look an account up by email address for a data request, with export and delete actions.
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

/** @var string $email */
/** @var User|null $found */
/** @var string $message */
/** @var string $csrfToken */
/** @var string $searchUrl */
/** @var string $exportUrl */
/** @var string $deleteUrl */
/** @var string $settingsUrl */
?>
<p class="lf-hint">Someone who asks for a copy of their data, or for their account to be removed, can be answered here. Find the account by the email address it was registered with. What happens to stories when an account is deleted is set under <a href="<?= Html::e($settingsUrl) ?>">Settings &rsaquo; Privacy</a>.</p>

<form method="get" action="<?= Html::e($searchUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= Html::e($email) ?>" maxlength="191" autocomplete="off" required>
    </div>
    <p><button type="submit" class="lf-button lf-button--secondary">Find the account</button></p>
</form>

<?php if ($message !== ''): ?>
    <p class="lf-notice lf-notice--warning" role="status"><?= Html::e($message) ?></p>
<?php endif; ?>

<?php if ($found !== null): ?>
    <section class="lf-card" aria-labelledby="lf-privacy-account">
        <h2 id="lf-privacy-account"><?= Html::e($found->displayName) ?></h2>
        <dl class="lf-details-list">
            <dt>Role</dt><dd><?= Html::e($found->role->label()) ?></dd>
            <dt>Status</dt><dd><?= Html::e(ucfirst($found->status->value)) ?></dd>
            <dt>Registered</dt><dd><?= Html::e($found->createdAt->format('Y-m-d')) ?></dd>
        </dl>

        <form method="post" action="<?= Html::e($exportUrl) ?>" class="lf-inline-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= (int) $found->id ?>">
            <button type="submit" class="lf-button">Export their data (JSON)</button>
        </form>
        <p><a href="<?= Html::e($deleteUrl) ?>">Delete this account&hellip;</a></p>
    </section>
<?php endif; ?>
