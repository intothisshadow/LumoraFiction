<?php

/**
 * Admin view: one person's account, with the forms that change their role and publishing rights.
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
use LumoraFiction\Models\PublishingOverride;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;

/** @var User $person */
/** @var bool $isSelf */
/** @var array<string, int> $counts stories by publication state */
/** @var list<PublicationState> $states */
/** @var string $publicUrl empty until the person has a public story */
/** @var list<UserRole> $roles */
/** @var list<PublishingOverride> $overrides */
/** @var bool $siteHoldsFirst */
/** @var string $csrfToken */
/** @var string $roleUrl */
/** @var string $publishingUrl */
/** @var string $suspendUrl */
/** @var string $reinstateUrl */
/** @var string $listUrl */

$roleHints = [
    UserRole::Administrator->value => 'Manages the whole site, including accounts and settings.',
    UserRole::Editor->value => 'Reviews and edits every story, and publishes without review.',
    UserRole::Author->value => 'Writes and manages their own stories.',
    UserRole::Reader->value => 'Reads, and keeps bookmarks. Cannot write.',
];
$statusClass = match ($person->status) {
    UserStatus::Active => ' lf-status--ok',
    UserStatus::Pending => ' lf-status--warning',
    UserStatus::Suspended => ' lf-status--error',
};
?>
<p><a href="<?= Html::e($listUrl) ?>">&larr; All users</a></p>

<div class="lf-card">
    <h2><?= Html::e($person->displayName) ?></h2>
    <table class="lf-table lf-user-summary">
        <tbody>
            <tr><th scope="row">Role</th><td><?= Html::e($person->role->label()) ?></td></tr>
            <tr><th scope="row">Status</th><td><span class="lf-status<?= Html::e($statusClass) ?>"><?= Html::e(ucfirst($person->status->value)) ?></span></td></tr>
            <tr><th scope="row">Joined</th><td><?= Html::e($person->createdAt->format('Y-m-d')) ?></td></tr>
            <tr><th scope="row">Email address</th><td><?= Html::e($person->email) ?> (<?= $person->emailVerifiedAt === null ? 'not confirmed' : 'confirmed' ?>)</td></tr>
            <tr>
                <th scope="row">Public page</th>
                <td><?php if ($publicUrl !== ''): ?><a href="<?= Html::e($publicUrl) ?>">View their author page</a><?php else: ?>None yet. It appears once they have published a story.<?php endif; ?></td>
            </tr>
            <tr>
                <th scope="row">Stories</th>
                <td><?= Html::e(implode(' · ', array_map(static fn (PublicationState $state): string => $state->label() . ': ' . (int) ($counts[$state->value] ?? 0), $states))) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php if ($isSelf): ?>
    <p class="lf-hint">This is your own account. You cannot change your role or suspend yourself here; change your details from your account page, or ask another administrator.</p>
<?php else: ?>
    <form method="post" action="<?= Html::e($roleUrl) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <div class="lf-card">
            <h2>Role</h2>
            <div class="lf-field">
                <label for="role">Role</label>
                <select id="role" name="role" aria-describedby="role_hint">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= Html::e($role->value) ?>"<?= $person->role === $role ? ' selected' : '' ?>><?= Html::e($role->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="lf-hint" id="role_hint"><?= Html::e(implode(' ', array_map(static fn (UserRole $role): string => $role->label() . ': ' . $roleHints[$role->value], $roles))) ?> The site always keeps at least one active administrator.</p>
            </div>
            <div class="lf-field">
                <label for="role_reason">Reason (optional)</label>
                <input type="text" id="role_reason" name="reason" maxlength="1000" aria-describedby="role_reason_hint">
                <p class="lf-hint" id="role_reason_hint">Kept in the activity log with this change.</p>
            </div>
            <p><button type="submit" class="lf-button">Change role</button></p>
        </div>
    </form>

    <?php if ($person->role === UserRole::Author): ?>
        <form method="post" action="<?= Html::e($publishingUrl) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <div class="lf-card">
                <h2>Publishing rights</h2>
                <div class="lf-field">
                    <label for="publishing">When this author sends a story</label>
                    <select id="publishing" name="publishing" aria-describedby="publishing_hint">
                        <option value=""<?= $person->publishingOverride === null ? ' selected' : '' ?>>Follow the site setting</option>
                        <?php foreach ($overrides as $override): ?>
                            <option value="<?= Html::e($override->value) ?>"<?= $person->publishingOverride === $override ? ' selected' : '' ?>><?= Html::e($override->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="lf-hint" id="publishing_hint">
                        <strong>Follow the site setting</strong>:
                        <?= $siteHoldsFirst ? 'their first story waits for approval, and later ones publish directly once one of theirs has been published.' : 'their stories publish directly.' ?>
                        <strong>Publishes without review</strong>: every story they send goes live at once.
                        <strong>Every story is reviewed</strong>: every story they send waits for an editor, however many they have published.
                        This applies from their next submission; stories already published stay published.
                    </p>
                </div>
                <p><button type="submit" class="lf-button">Save publishing rights</button></p>
            </div>
        </form>
    <?php endif; ?>

    <div class="lf-card">
        <h2>Access</h2>
        <?php if ($person->status === UserStatus::Suspended): ?>
            <p>This account is suspended. They cannot sign in or write.</p>
            <p><a href="<?= Html::e($reinstateUrl) ?>" class="lf-button">Reinstate this account&hellip;</a></p>
        <?php else: ?>
            <p>Suspending signs them out and stops them signing in or writing. Their stories stay as they are unless you choose to take them out of view.</p>
            <p><a href="<?= Html::e($suspendUrl) ?>" class="lf-button lf-button--secondary">Suspend this account&hellip;</a></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
