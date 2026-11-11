<?php

/**
 * Admin view: the privacy settings form.
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

/** @var array{page_id: int, deleted_stories: string, neutral_name: string, ping_enabled: bool} $values */
/** @var array<string, string> $errors */
/** @var array<int, string> $pageChoices */
/** @var array<string, string> $storyChoices */
/** @var int $nameMax */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $pagesUrl */
/** @var string $requestsUrl */
/** @var string $testPingUrl */
/** @var string|null $installId */
/** @var int|null $lastPing */
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
        <legend>Privacy policy</legend>

        <div class="lf-field<?= isset($errors['page_id']) ? ' lf-field--error' : '' ?>">
            <label for="page_id">Privacy policy page</label>
            <select id="page_id" name="page_id" aria-describedby="page_id_hint<?= isset($errors['page_id']) ? ' page_id_error' : '' ?>">
                <option value="0"<?= $values['page_id'] === 0 ? ' selected' : '' ?>>None</option>
                <?php foreach ($pageChoices as $id => $title): ?>
                    <option value="<?= (int) $id ?>"<?= $values['page_id'] === $id ? ' selected' : '' ?>><?= Html::e($title) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="lf-hint" id="page_id_hint">Only published pages are listed. The registration form links to the page you choose, and themes can link to it too. Write and publish it under <a href="<?= Html::e($pagesUrl) ?>">Pages</a>; the Pages screen can start you off with a draft.</p>
            <?php if (isset($errors['page_id'])): ?><p class="lf-error" id="page_id_error"><?= Html::e($errors['page_id']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Deleted accounts</legend>

        <?php foreach ($storyChoices as $value => $label): ?>
            <div class="lf-choice">
                <input type="radio" id="deleted_stories_<?= Html::e($value) ?>" name="deleted_stories" value="<?= Html::e($value) ?>"<?= $values['deleted_stories'] === $value ? ' checked' : '' ?><?= $value === 'keep' ? ' aria-describedby="deleted_stories_hint"' : '' ?>>
                <label for="deleted_stories_<?= Html::e($value) ?>"><?= Html::e($label) ?></label>
            </div>
        <?php endforeach; ?>
        <p class="lf-hint" id="deleted_stories_hint">Either way, the person&rsquo;s name, email address, login, reading history, bookmarks and follows are removed. This is the usual choice: it is preselected when an administrator deletes an account from <a href="<?= Html::e($requestsUrl) ?>">Privacy requests</a>, who can change it for that request, and an author deleting their own account chooses for themselves.</p>
        <?php if (isset($errors['deleted_stories'])): ?><p class="lf-error" id="deleted_stories"><?= Html::e($errors['deleted_stories']) ?></p><?php endif; ?>

        <div class="lf-field<?= isset($errors['neutral_name']) ? ' lf-field--error' : '' ?>">
            <label for="neutral_name">Name shown on kept stories</label>
            <input type="text" id="neutral_name" name="neutral_name" value="<?= Html::e($values['neutral_name']) ?>" maxlength="<?= (int) $nameMax ?>" aria-describedby="neutral_name_hint<?= isset($errors['neutral_name']) ? ' neutral_name_error' : '' ?>">
            <p class="lf-hint" id="neutral_name_hint">Readers see this in place of the author&rsquo;s name when stories are kept.</p>
            <?php if (isset($errors['neutral_name'])): ?><p class="lf-error" id="neutral_name_error"><?= Html::e($errors['neutral_name']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Anonymous install ping</legend>

        <div class="lf-choice">
            <input type="checkbox" id="ping_enabled" name="ping_enabled" value="1"<?= $values['ping_enabled'] ? ' checked' : '' ?> aria-describedby="ping_enabled_hint">
            <label for="ping_enabled">Tell the Lumora Fiction project this site exists</label>
        </div>
        <p class="lf-hint" id="ping_enabled_hint">Off unless you turn it on. When on, this site sends three things about once a month, and only when an Administrator opens an admin page: a random install ID, the Lumora Fiction version and the PHP version. It never sends your site&rsquo;s address or name, any email address, any story, setting or visitor information. It helps the project see roughly how many sites are in use and which PHP versions can be dropped. Nothing on this site depends on it, and it is not part of the exported settings file.</p>

        <dl class="lf-details-list">
            <dt>Install ID</dt>
            <dd><?= $installId !== null ? '<code>' . Html::e($installId) . '</code>' : 'Made when you first turn the ping on.' ?></dd>
            <dt>Last tried</dt>
            <dd><?= $lastPing !== null ? Html::e(gmdate('j F Y, H:i', $lastPing)) . ' UTC' : 'Never.' ?></dd>
        </dl>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>

<form method="post" action="<?= Html::e($testPingUrl) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <p><button type="submit" class="lf-button lf-button--secondary">Send a test ping now</button></p>
    <p class="lf-hint">Sends one ping straight away, while the switch is on, and tells you whether it was accepted.</p>
</form>
