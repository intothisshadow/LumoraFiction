<?php

/**
 * Settings › Downloads.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array{enabled: bool, formats: list<string>, count: bool, show_counts: bool} $values */
/** @var list<array{value: string, label: string, description: string, checked: bool, available: bool}> $formats */
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

<p class="lf-lead">Readers can take a story away as a file. These switches decide whether that is possible on this site. Each Author can also refuse downloads for all their stories, or for single stories, but only while downloads are on here.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Downloads</legend>
        <div class="lf-choice">
            <input type="checkbox" id="enabled" name="enabled" value="1"<?= $values['enabled'] ? ' checked' : '' ?> aria-describedby="enabled_hint">
            <label for="enabled">Let readers download stories</label>
        </div>
        <p class="lf-hint" id="enabled_hint">When this is off, the download menu is hidden on every story and every download address is refused. Authors' own choices are kept, but have no effect until it is back on.</p>
    </fieldset>

    <fieldset class="lf-card<?= isset($errors['formats']) ? ' lf-field--error' : '' ?>" id="formats">
        <legend>Formats</legend>
        <?php foreach ($formats as $format): ?>
            <div class="lf-choice">
                <input type="checkbox" id="format_<?= Html::e($format['value']) ?>" name="formats[]" value="<?= Html::e($format['value']) ?>"<?= $format['checked'] ? ' checked' : '' ?> aria-describedby="format_<?= Html::e($format['value']) ?>_hint">
                <label for="format_<?= Html::e($format['value']) ?>"><?= Html::e($format['label']) ?></label>
            </div>
            <p class="lf-hint" id="format_<?= Html::e($format['value']) ?>_hint"><?= Html::e($format['description']) ?>.<?= $format['available'] ? '' : ' This server is missing something it needs for ' . Html::e($format['label']) . ', so it is not offered even if ticked. The System information screen lists the PHP extensions in use.' ?></p>
        <?php endforeach; ?>
        <?php if (isset($errors['formats'])): ?><p class="lf-error"><?= Html::e($errors['formats']) ?></p><?php endif; ?>
        <p class="lf-hint">A PDF is only made for stories up to 100,000 words, and fewer on a host with little memory; longer ones are offered as EPUB or HTML.</p>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Counts</legend>
        <div class="lf-choice">
            <input type="checkbox" id="count" name="count" value="1"<?= $values['count'] ? ' checked' : '' ?> aria-describedby="count_hint">
            <label for="count">Count downloads</label>
        </div>
        <p class="lf-hint" id="count_hint">Keeps a plain total for each story and format. Nothing is kept about who downloaded.</p>
        <div class="lf-choice">
            <input type="checkbox" id="show_counts" name="show_counts" value="1"<?= $values['show_counts'] ? ' checked' : '' ?> aria-describedby="show_counts_hint">
            <label for="show_counts">Show the number of downloads beside each format</label>
        </div>
        <p class="lf-hint" id="show_counts_hint">Only shown while counting is on.</p>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
