<?php

/**
 * Built-in theme: one account form, drawn from the bundled description the page supplies.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

$form = is_array($form ?? null) ? $form : [];
$fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
$errors = is_array($form['errors'] ?? null) ? $form['errors'] : [];

?>
<form class="lf-form" method="post" action="<?= lf_e($form['action'] ?? '') ?>" novalidate>
    <?php if (($form['heading'] ?? '') !== ''): ?>
        <h2 class="lf-form__heading"><?= lf_e($form['heading']) ?></h2>
    <?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= lf_e($form['csrf_token'] ?? '') ?>">
    <?php foreach ($errors as $error): ?>
        <p class="lf-notice lf-notice--error" role="alert"><?= lf_e($error['message'] ?? '') ?></p>
    <?php endforeach; ?>
    <?php foreach ($fields as $field): ?>
        <?php
        $id = (string) ($field['id'] ?? '');
        $describedBy = trim(
            (($field['hint'] ?? '') !== '' ? $id . '-hint ' : '') . (($field['error'] ?? '') !== '' ? $id . '-error' : '')
        );
        ?>
        <div class="lf-form__field">
            <label class="lf-form__label" for="<?= lf_e($id) ?>"><?= lf_e($field['label'] ?? '') ?></label>
            <?php if (($field['type'] ?? '') === 'select'): ?>
                <select class="lf-form__input" id="<?= lf_e($id) ?>" name="<?= lf_e($field['name'] ?? '') ?>"<?= $describedBy !== '' ? ' aria-describedby="' . lf_e($describedBy) . '"' : '' ?>>
                    <?php foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option): ?>
                        <option value="<?= lf_e($option['value'] ?? '') ?>"<?= ($option['selected'] ?? false) === true ? ' selected' : '' ?>><?= lf_e($option['label'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="lf-form__input" id="<?= lf_e($id) ?>" name="<?= lf_e($field['name'] ?? '') ?>" type="<?= lf_e($field['type'] ?? 'text') ?>" value="<?= lf_e($field['value'] ?? '') ?>" autocomplete="<?= lf_e($field['autocomplete'] ?? 'off') ?>"<?= ($field['error'] ?? '') !== '' ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . lf_e($describedBy) . '"' : '' ?> required>
            <?php endif; ?>
            <?php if (($field['hint'] ?? '') !== ''): ?>
                <p class="lf-form__hint" id="<?= lf_e($id) ?>-hint"><?= lf_e($field['hint']) ?></p>
            <?php endif; ?>
            <?php if (($field['error'] ?? '') !== ''): ?>
                <p class="lf-form__error" id="<?= lf_e($id) ?>-error"><?= lf_e($field['error']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <p class="lf-form__actions"><button class="lf-button" type="submit"><?= lf_e($form['submit'] ?? 'Save') ?></button></p>
</form>
