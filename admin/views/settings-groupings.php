<?php

/**
 * Settings › Series and collections.
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

/** @var array{series_enabled: bool, collections_enabled: bool, collections_creators: string} $values */
/** @var array<string, string> $creators */
/** @var array<string, string> $errors */
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

<p class="lf-lead">A series is an author&rsquo;s own stories in a fixed order. A collection is a group of stories gathered by someone who may not own them all. Switching either off hides its screens and public pages. Nothing is deleted, and it all comes back when the feature is switched on again.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Series</legend>
        <div class="lf-choice">
            <input type="checkbox" id="series_enabled" name="series_enabled" value="1"<?= $values['series_enabled'] ? ' checked' : '' ?>>
            <label for="series_enabled">Let authors group their stories into series</label>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Collections</legend>
        <div class="lf-choice">
            <input type="checkbox" id="collections_enabled" name="collections_enabled" value="1"<?= $values['collections_enabled'] ? ' checked' : '' ?>>
            <label for="collections_enabled">Offer collections</label>
        </div>
        <div class="lf-field<?= isset($errors['collections_creators']) ? ' lf-field--error' : '' ?>">
            <label for="collections_creators">Who may start a collection</label>
            <select id="collections_creators" name="collections_creators" aria-describedby="collections_creators_hint">
                <?php foreach ($creators as $value => $label): ?>
                    <option value="<?= Html::e($value) ?>"<?= $values['collections_creators'] === $value ? ' selected' : '' ?>><?= Html::e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="lf-hint" id="collections_creators_hint">Any author can still offer a story to a collection that is open to submissions.</p>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
