<?php

/**
 * Settings › Feeds.
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

use LumoraFiction\Helpers\Html;

/** @var array{kinds: list<string>, json: bool, limit: int, full_text: bool} $values */
/** @var list<array{value: string, label: string, checked: bool}> $kinds */
/** @var list<string> $hidden */
/** @var array<string, string> $errors */
/** @var int $minLimit */
/** @var int $maxLimit */
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

<p class="lf-lead">Feeds let readers follow new stories and chapters in a feed reader. They only ever list what a visitor can already read: a draft, an unpublished story or a story waiting for approval never appears, and an author is named by their display name only.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Feeds to offer</legend>
        <?php foreach ($kinds as $kind): ?>
            <div class="lf-choice">
                <input type="checkbox" id="kind_<?= Html::e($kind['value']) ?>" name="kinds[]" value="<?= Html::e($kind['value']) ?>"<?= $kind['checked'] ? ' checked' : '' ?>>
                <label for="kind_<?= Html::e($kind['value']) ?>"><?= Html::e($kind['label']) ?></label>
            </div>
        <?php endforeach; ?>
        <?php if ($hidden !== []): ?>
            <p class="lf-hint">Not listed because the archive mode or the series and collections setting switches them off:<?= Html::e(implode(', ', $hidden)) ?>. What was chosen for them is kept, and comes back if the mode changes.</p>
        <?php endif; ?>
        <p class="lf-hint">A feed that is switched off answers like a page that does not exist, and the site stops pointing to it. A story's comment feed also needs comments to be on in the comment settings. While the archive requires sign-in to read, no story feed is served at all, because a feed reader cannot sign in; the news feed stays open.</p>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Form</legend>
        <div class="lf-choice">
            <input type="checkbox" id="json" name="json" value="1"<?= $values['json'] ? ' checked' : '' ?> aria-describedby="json_hint">
            <label for="json">Also offer every feed as a JSON Feed</label>
        </div>
        <p class="lf-hint" id="json_hint">Every feed is RSS. With this on, each one is also available as a JSON Feed at the same address with <code>.json</code> added.</p>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Size</legend>
        <div class="lf-field<?= isset($errors['limit']) ? ' lf-field--error' : '' ?>">
            <label for="limit">Entries in each feed</label>
            <input type="text" inputmode="numeric" id="limit" name="limit" value="<?= Html::e((string) $values['limit']) ?>" maxlength="2" aria-describedby="limit_hint<?= isset($errors['limit']) ? ' limit_error' : '' ?>">
            <p class="lf-hint" id="limit_hint">From <?= (int) $minLimit ?> to <?= (int) $maxLimit ?>. A reader sees only the newest entries, so a small number keeps feeds fast on a busy site.</p>
            <?php if (isset($errors['limit'])): ?><p class="lf-error" id="limit_error"><?= Html::e($errors['limit']) ?></p><?php endif; ?>
        </div>

        <div class="lf-choice">
            <input type="checkbox" id="full_text" name="full_text" value="1"<?= $values['full_text'] ? ' checked' : '' ?> aria-describedby="full_text_hint">
            <label for="full_text">Put the whole text of each chapter and news post in its entry</label>
        </div>
        <p class="lf-hint" id="full_text_hint">When this is off, an entry carries only the opening and a link, so readers come to the site to read.</p>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
