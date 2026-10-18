<?php

/**
 * Appearance › Theme options: the settings the active theme offers.
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

/** @var string $themeName */
/** @var list<array{key: string, label: string, type: string, css_variable: string, choices: array<string, string>, default: string|bool, hint: string}> $fields */
/** @var array<string, string|bool> $values */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
/** @var string $action */
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#opt_' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($fields === []): ?>
    <p class="lf-lead"><?= Html::e($themeName) ?> has no options to change.</p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

        <fieldset class="lf-card">
            <legend><?= Html::e($themeName) ?></legend>
            <p class="lf-hint">Anything left on &ldquo;use the theme&rsquo;s own&rdquo; keeps the look the theme&rsquo;s designer chose.</p>

            <?php foreach ($fields as $field): ?>
                <?php
                $key = $field['key'];
                $id = 'opt_' . $key;
                $value = $values[$key] ?? $field['default'];
                $hintId = $id . '_hint';
                $describedBy = $field['hint'] !== '' ? ' aria-describedby="' . Html::e($hintId) . '"' : '';
                ?>
                <div class="lf-field">
                    <?php if ($field['type'] === 'checkbox'): ?>
                        <div class="lf-choice">
                            <input type="checkbox" id="<?= Html::e($id) ?>" name="<?= Html::e($id) ?>" value="1"<?= $value === true ? ' checked' : '' ?><?= $describedBy ?>>
                            <label for="<?= Html::e($id) ?>"><?= Html::e($field['label']) ?></label>
                        </div>
                    <?php elseif ($field['type'] === 'select'): ?>
                        <label for="<?= Html::e($id) ?>"><?= Html::e($field['label']) ?></label>
                        <select id="<?= Html::e($id) ?>" name="<?= Html::e($id) ?>"<?= $describedBy ?>>
                            <?php if ($field['css_variable'] !== ''): ?>
                                <option value=""<?= $value === '' ? ' selected' : '' ?>>Use the theme&rsquo;s own</option>
                            <?php endif; ?>
                            <?php foreach ($field['choices'] as $choice => $label): ?>
                                <option value="<?= Html::e((string) $choice) ?>"<?= (string) $value === (string) $choice ? ' selected' : '' ?>><?= Html::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <label for="<?= Html::e($id) ?>"><?= Html::e($field['label']) ?></label>
                        <input type="color" id="<?= Html::e($id) ?>" name="<?= Html::e($id) ?>" value="<?= Html::e(is_string($value) && $value !== '' ? $value : '#000000') ?>"<?= $describedBy ?>>
                        <span class="lf-choice lf-choice--inline">
                            <input type="checkbox" id="inherit_<?= Html::e($key) ?>" name="inherit_<?= Html::e($key) ?>" value="1"<?= $value === '' ? ' checked' : '' ?>>
                            <label for="inherit_<?= Html::e($key) ?>">Use the theme&rsquo;s own colour</label>
                        </span>
                    <?php endif; ?>
                    <?php if ($field['hint'] !== ''): ?><p class="lf-hint" id="<?= Html::e($hintId) ?>"><?= Html::e($field['hint']) ?></p><?php endif; ?>
                    <?php if (isset($errors[$key])): ?><p class="lf-error"><?= Html::e($errors[$key]) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <p><button type="submit" class="lf-button">Save options</button></p>
    </form>
<?php endif; ?>
