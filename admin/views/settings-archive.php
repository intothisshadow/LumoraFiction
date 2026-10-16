<?php

/**
 * Settings › Archive.
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

/** @var array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool} $values */
/** @var array<string, string> $errors */
/** @var array<string, string> $modes */
/** @var list<array{value: string, label: string}> $roles */
/** @var string $csrfToken */
/** @var string $action */

$modeHints = [
    'both' => 'Both kinds of story, with the fields each needs.',
    'fanfiction' => 'Stories set in existing worlds, with fandoms, characters, and relationships.',
    'original' => 'Stories in worlds of their own, without fandom fields.',
];
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
        <legend>What the site publishes</legend>
        <p class="lf-hint">Turning something off hides it from writers and readers. Nothing already published is deleted, so you can change your mind.</p>
        <?php foreach ($modes as $mode => $label): ?>
            <div class="lf-choice">
                <input type="radio" id="archive_mode_<?= Html::e($mode) ?>" name="archive_mode" value="<?= Html::e($mode) ?>"<?= $values['archive_mode'] === $mode ? ' checked' : '' ?> aria-describedby="archive_mode_<?= Html::e($mode) ?>_hint">
                <label for="archive_mode_<?= Html::e($mode) ?>"><?= Html::e($label) ?></label>
                <p class="lf-hint" id="archive_mode_<?= Html::e($mode) ?>_hint"><?= Html::e($modeHints[$mode] ?? '') ?></p>
            </div>
        <?php endforeach; ?>
        <?php if (isset($errors['archive_mode'])): ?><p class="lf-error" id="archive_mode"><?= Html::e($errors['archive_mode']) ?></p><?php endif; ?>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Joining</legend>

        <div class="lf-choice">
            <input type="checkbox" id="registration_enabled" name="registration_enabled" value="1"<?= $values['registration_enabled'] ? ' checked' : '' ?> aria-describedby="registration_enabled_hint">
            <label for="registration_enabled">Anyone can register</label>
            <p class="lf-hint" id="registration_enabled_hint">When this is off, nobody new can create an account. Existing accounts keep working.</p>
        </div>

        <div class="lf-field<?= isset($errors['default_role']) ? ' lf-field--error' : '' ?>">
            <label for="default_role">New accounts become</label>
            <select id="default_role" name="default_role" aria-describedby="default_role_hint<?= isset($errors['default_role']) ? ' default_role_error' : '' ?>">
                <?php foreach ($roles as $role): ?>
                    <option value="<?= Html::e($role['value']) ?>"<?= $values['default_role'] === $role['value'] ? ' selected' : '' ?>><?= Html::e($role['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="lf-hint" id="default_role_hint">Authors can write and submit stories. Readers can only read and keep bookmarks. Editors and administrators are always chosen by hand.</p>
            <?php if (isset($errors['default_role'])): ?><p class="lf-error" id="default_role_error"><?= Html::e($errors['default_role']) ?></p><?php endif; ?>
        </div>

        <div class="lf-choice">
            <input type="checkbox" id="email_verification" name="email_verification" value="1"<?= $values['email_verification'] ? ' checked' : '' ?> aria-describedby="email_verification_hint">
            <label for="email_verification">New accounts must confirm their email address</label>
            <p class="lf-hint" id="email_verification_hint">Recommended. Without this, anyone can create accounts with addresses that are not theirs, which makes spam and abuse much easier.</p>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Approval</legend>

        <div class="lf-choice">
            <input type="checkbox" id="hold_first_story" name="hold_first_story" value="1"<?= $values['hold_first_story'] ? ' checked' : '' ?> aria-describedby="hold_first_story_hint">
            <label for="hold_first_story">A new Author's first story waits for approval</label>
            <p class="lf-hint" id="hold_first_story_hint">Recommended. An editor reviews the first story before it appears, so a brand new account cannot publish spam straight away. Later stories are not held.</p>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
