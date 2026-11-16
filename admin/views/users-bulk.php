<?php

/**
 * Asks before a change to several accounts at once, and takes the one reason or choice that goes with it.
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

use LumoraFiction\Core\Security\PublishingPolicy;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\PublishingOverride;
use LumoraFiction\Models\UserRole;

/** @var string $action suspend, reinstate, role, publishing or reset */
/** @var list<array{id: int, label: string}> $items */
/** @var list<UserRole> $roles */
/** @var string $reason */
/** @var int $limit */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var array<string, string> $fields hidden fields: the action, the filters, the sort and the page */
/** @var string $csrfToken */
/** @var string $formUrl */
/** @var string $backUrl */

$count = count($items);
$people = $count === 1 ? 'this account' : 'these ' . $count . ' accounts';
$heading = match ($action) {
    'suspend' => 'Suspend ' . $people . '?',
    'reinstate' => 'Reinstate ' . $people . '?',
    'role' => 'Change the role of ' . $people . '?',
    'publishing' => 'Change how ' . $people . ' publish?',
    default => 'Send a password reset link to ' . $people . '?',
};
$note = match ($action) {
    'suspend' => 'Each person is signed out and cannot sign in or write until they are reinstated. Your own account, and the last active administrator, are skipped.',
    'reinstate' => 'Each person can sign in again, once they have confirmed their email address if they had not yet.',
    'role' => 'The new role applies to each person at once. Your own account, and the last active administrator, are skipped.',
    'publishing' => 'Only authors can be given a publishing setting; anyone else is skipped.',
    default => 'Each person is emailed a link to choose a new password. A link sent a moment ago is not sent again, and suspended accounts are skipped.',
};
$button = match ($action) {
    'suspend' => 'Suspend',
    'reinstate' => 'Reinstate',
    'role' => 'Change the role',
    'publishing' => 'Save the setting',
    default => 'Send the links',
};
?>
<?= $errorSummary ?>

<?php if ($items === []): ?>
    <p>None of the selected accounts can be changed by you.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the accounts</a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($formUrl) ?>" class="lf-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="confirmed" value="1">
        <?php foreach ($fields as $name => $value): ?>
            <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
        <?php endforeach; ?>

        <div class="lf-card">
            <h2><?= Html::e($heading) ?></h2>
            <ul>
                <?php foreach ($items as $item): ?>
                    <li>
                        <?= Html::e($item['label']) ?>
                        <input type="hidden" name="ids[]" value="<?= (int) $item['id'] ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><?= Html::e($note) ?></p>
            <?php if (in_array($action, ['role', 'publishing'], true) && PublishingPolicy::mode() === PublishingPolicy::STAFF): ?>
                <p class="lf-hint">Only staff can publish on this site, so <?= $action === 'role' ? 'an Author stays reader-only' : 'a publishing setting has no effect' ?> until that is changed under Settings &rsaquo; Archive.</p>
            <?php endif; ?>

            <?php if ($action === 'role'): ?>
                <div class="lf-field<?= isset($errors['role_choice']) ? ' lf-field--error' : '' ?>">
                    <label for="role_choice">New role</label>
                    <select id="role_choice" name="role_choice" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= Html::e($role->value) ?>"><?= Html::e($role->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['role_choice'])): ?><p class="lf-error"><?= Html::e($errors['role_choice']) ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($action === 'publishing'): ?>
                <div class="lf-field<?= isset($errors['publishing']) ? ' lf-field--error' : '' ?>">
                    <label for="publishing">Publishing setting</label>
                    <select id="publishing" name="publishing">
                        <option value="">Follow the site setting</option>
                        <?php foreach (PublishingOverride::cases() as $override): ?>
                            <option value="<?= Html::e($override->value) ?>"><?= Html::e($override->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['publishing'])): ?><p class="lf-error"><?= Html::e($errors['publishing']) ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (in_array($action, ['suspend', 'reinstate', 'role'], true)): ?>
                <div class="lf-field<?= isset($errors['reason']) ? ' lf-field--error' : '' ?>">
                    <label for="reason">Reason (optional, kept in the activity log)</label>
                    <textarea id="reason" name="reason" rows="3" maxlength="<?= (int) $limit ?>"><?= Html::e($reason) ?></textarea>
                    <?php if (isset($errors['reason'])): ?><p class="lf-error"><?= Html::e($errors['reason']) ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($action === 'suspend'): ?>
                <div class="lf-choice">
                    <input type="checkbox" id="unpublish" name="unpublish" value="1">
                    <label for="unpublish">Also take their published stories out of view</label>
                </div>
            <?php endif; ?>

            <p>
                <button type="submit" class="lf-button"><?= Html::e($button) ?></button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
