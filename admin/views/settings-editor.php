<?php

/**
 * Settings › Editor.
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

/** @var array{default_mode: string, lock_to_default: bool} $values */
/** @var array<string, string> $errors */
/** @var list<array{value: string, label: string}> $modes */
/** @var string $csrfToken */
/** @var string $action */

$modeHints = [
    'visual' => 'Write with a formatting toolbar, like a word processor.',
    'markdown' => 'Write with simple symbols such as asterisks for emphasis. Good for people who like the keyboard.',
    'html' => 'Write the HTML directly. For people who already know it.',
    'plain' => 'Just text. Blank lines start new paragraphs.',
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
        <legend>Editor writers start with</legend>
        <p class="lf-hint">A chapter can be switched to another mode at any time without losing its formatting. Writers can choose their own unless you lock the editor below.</p>
        <?php foreach ($modes as $mode): ?>
            <div class="lf-choice">
                <input type="radio" id="default_mode_<?= Html::e($mode['value']) ?>" name="default_mode" value="<?= Html::e($mode['value']) ?>"<?= $values['default_mode'] === $mode['value'] ? ' checked' : '' ?> aria-describedby="default_mode_<?= Html::e($mode['value']) ?>_hint">
                <label for="default_mode_<?= Html::e($mode['value']) ?>"><?= Html::e($mode['label']) ?></label>
                <p class="lf-hint" id="default_mode_<?= Html::e($mode['value']) ?>_hint"><?= Html::e($modeHints[$mode['value']] ?? '') ?></p>
            </div>
        <?php endforeach; ?>
        <?php if (isset($errors['default_mode'])): ?><p class="lf-error" id="default_mode"><?= Html::e($errors['default_mode']) ?></p><?php endif; ?>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Choice</legend>
        <div class="lf-choice">
            <input type="checkbox" id="lock_to_default" name="lock_to_default" value="1"<?= $values['lock_to_default'] ? ' checked' : '' ?> aria-describedby="lock_to_default_hint">
            <label for="lock_to_default">Everyone uses this editor</label>
            <p class="lf-hint" id="lock_to_default_hint">When this is on, writers cannot choose another mode, and chapters are shown in this one. Nothing stored is changed until a chapter is saved.</p>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
