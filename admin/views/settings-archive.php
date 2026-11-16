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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;

/** @var array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool, require_signin: bool, who_publishes: string} $values */
/** @var array<string, string> $errors */
/** @var array<string, string> $modes */
/** @var list<array{value: string, label: string}> $roles */
/** @var array<string, string> $whoModes */
/** @var array{authors: int, stories: int} $staffImpact */
/** @var string $csrfToken */
/** @var string $action */
/** @var array<string, array<string, array{label: string, description: string, values: list<array{key: string, name: string, description: string}>}>> $catalogue starter lists by list, then preset key */
/** @var array<string, string> $applied the starter list last applied to each list */
/** @var string $presetUrl */
/** @var string $presetToken */
/** @var array{whole_story: bool, whole_story_words: int} $reading */
/** @var array<string, string> $readingErrors */
/** @var string $readingToken */
/** @var string $readingAction */
/** @var array{min: int, max: int} $wordLimits */

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
        <legend>Who can publish stories</legend>
        <p class="lf-hint">Applies at once to everyone, whatever their role. Nobody's role is changed: switching back restores what each account could do, and stories already published stay online. Readers always keep bookmarks, reading history, follows, comments and notifications.</p>
        <?php foreach ($whoModes as $who => $label): ?>
            <div class="lf-choice">
                <input type="radio" id="who_publishes_<?= Html::e($who) ?>" name="who_publishes" value="<?= Html::e($who) ?>"<?= $values['who_publishes'] === $who ? ' checked' : '' ?> aria-describedby="who_publishes_<?= Html::e($who) ?>_hint">
                <label for="who_publishes_<?= Html::e($who) ?>"><?= Html::e($label) ?></label>
                <p class="lf-hint" id="who_publishes_<?= Html::e($who) ?>_hint"><?php if ($who === 'anyone'): ?>The usual setup: an account with the Author role writes and publishes (the first story can wait for approval, below).<?php elseif ($who === 'approved'): ?>Registering never makes an Author. An administrator gives the Author role to the people who may write, under Users.<?php else: ?>Only Administrators and Editors write. Suited to a site with one author or a few chosen ones. <?= (int) $staffImpact['authors'] === 0 ? 'No Author accounts exist now.' : (int) $staffImpact['authors'] . ($staffImpact['authors'] === 1 ? ' Author account' : ' Author accounts') . ' would become reader-only; their ' . (int) $staffImpact['stories'] . ($staffImpact['stories'] === 1 ? ' published story stays' : ' published stories stay') . ' online.' ?><?php endif; ?></p>
            </div>
        <?php endforeach; ?>
        <?php if (isset($errors['who_publishes'])): ?><p class="lf-error" id="who_publishes"><?= Html::e($errors['who_publishes']) ?></p><?php endif; ?>
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
            <p class="lf-hint" id="default_role_hint"><?= $values['who_publishes'] === 'anyone' ? 'Authors can write and submit stories. ' : 'Only readers can register on this site, because of who may publish (above). ' ?>Readers can only read and keep bookmarks. Editors and administrators are always chosen by hand.</p>
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

    <fieldset class="lf-card">
        <legend>Classification</legend>

        <div class="lf-choice">
            <input type="checkbox" id="writer_tags" name="writer_tags" value="1"<?= $values['writer_tags'] ? ' checked' : '' ?> aria-describedby="writer_tags_hint">
            <label for="writer_tags">Writers may propose new tags</label>
            <p class="lf-hint" id="writer_tags_hint">When this is off, writers can only pick tags that already exist, and only an administrator adds new ones under Classification. Tags writers proposed earlier stay where they are. Fandoms, characters and relationships are not affected.</p>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Reading</legend>

        <div class="lf-choice">
            <input type="checkbox" id="require_signin" name="require_signin" value="1"<?= $values['require_signin'] ? ' checked' : '' ?> aria-describedby="require_signin_hint">
            <label for="require_signin">Visitors must sign in to read</label>
            <p class="lf-hint" id="require_signin_hint">When this is on, anyone who is not signed in is sent to the sign-in page instead of seeing stories. Leave it off for an open archive.</p>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>

<form method="post" action="<?= Html::e($readingAction) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($readingToken) ?>">

    <fieldset class="lf-card">
        <legend>Reading a story on one page</legend>
        <?php if (isset($readingErrors['reading_form'])): ?><p class="lf-error" role="alert"><?= Html::e($readingErrors['reading_form']) ?></p><?php endif; ?>

        <div class="lf-choice">
            <input type="checkbox" id="whole_story" name="whole_story" value="1"<?= $reading['whole_story'] ? ' checked' : '' ?> aria-describedby="whole_story_hint">
            <label for="whole_story">Let readers open a story with several chapters as one page</label>
            <p class="lf-hint" id="whole_story_hint">Adds a &ldquo;Read the whole story on one page&rdquo; link to such stories. Switching it off removes the link and the address; nothing is lost.</p>
        </div>

        <div class="lf-field<?= isset($readingErrors['whole_story_words']) ? ' lf-field--error' : '' ?>">
            <label for="whole_story_words">Longest page, in words</label>
            <input type="text" id="whole_story_words" name="whole_story_words" inputmode="numeric" value="<?= Html::e((string) $reading['whole_story_words']) ?>" maxlength="7" aria-describedby="whole_story_words_hint<?= isset($readingErrors['whole_story_words']) ? ' whole_story_words_error' : '' ?>">
            <p class="lf-hint" id="whole_story_words_hint">From <?= number_format($wordLimits['min']) ?> to <?= number_format($wordLimits['max']) ?>. A longer story shows the chapters that fit, says so, and links to the next chapter on its own page. The same limit is used for PDF downloads by default.</p>
            <?php if (isset($readingErrors['whole_story_words'])): ?><p class="lf-error" id="whole_story_words_error"><?= Html::e($readingErrors['whole_story_words']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save reading settings</button></p>
</form>

<?php if ($catalogue !== []): ?>
    <section class="lf-card lf-presets" aria-labelledby="lf-presets-heading">
        <h2 id="lf-presets-heading">Starter lists</h2>
        <p class="lf-hint">A starter list adds a ready-made set of values to a list so you do not have to type them. It is always optional: leave a list empty to make your own values under <a href="<?= Html::e(AdminUrl::to('taxonomy')) ?>">Classification</a>, where every value can be renamed, described, put in order or hidden. Adding a list never removes or changes anything you have already edited, and adding it again only restores values you deleted.</p>
        <p class="lf-hint">Starter lists exist for <strong>ratings</strong> (AO3-style, FanFiction.net-style, movie-style or a minimal three-value set), <strong>archive warnings</strong> (AO3-style or minimal), <strong>relationship categories</strong> (the standard six) and <strong>story status</strong> (with or without &ldquo;abandoned&rdquo;). Fandoms, characters, relationships, tags, genres and categories have none: writers propose the first four as they write (tags can be switched to administrator-only under Classification above), and you add genres and categories yourself under <a href="<?= Html::e(AdminUrl::to('taxonomy')) ?>">Classification</a>.</p>

        <?php foreach ($catalogue as $kind => $presets): ?>
            <form method="post" action="<?= Html::e($presetUrl) ?>" class="lf-form lf-presets__form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($presetToken) ?>">
                <input type="hidden" name="kind" value="<?= Html::e($kind) ?>">
                <div class="lf-field">
                    <label for="preset_<?= Html::e($kind) ?>"><?= Html::e(LumoraFiction\Services\ClassificationPresets::KIND_LABELS[$kind] ?? $kind) ?></label>
                    <select id="preset_<?= Html::e($kind) ?>" name="preset" aria-describedby="preset_<?= Html::e($kind) ?>_hint">
                        <option value="">No starter list</option>
                        <?php foreach ($presets as $key => $preset): ?>
                            <option value="<?= Html::e($key) ?>"><?= Html::e($preset['label']) ?> (<?= count($preset['values']) ?> values)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="lf-hint" id="preset_<?= Html::e($kind) ?>_hint">
                        <?php foreach ($presets as $key => $preset): ?>
                            <?= Html::e($preset['label']) ?>: <?= Html::e(implode(', ', array_column($preset['values'], 'name'))) ?>.
                        <?php endforeach; ?>
                        <?php if (isset($applied[$kind], $presets[$applied[$kind]])): ?>Last added: <?= Html::e($presets[$applied[$kind]]['label']) ?>.<?php endif; ?>
                    </p>
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Add<span class="lf-visually-hidden"> to <?= Html::e(strtolower(LumoraFiction\Services\ClassificationPresets::KIND_LABELS[$kind] ?? $kind)) ?></span></button></p>
            </form>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
