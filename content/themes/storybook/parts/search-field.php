<?php

/**
 * Storybook theme: one control of the search form, a text box, a number box or a list to pick from.
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

$field = is_array($field ?? null) ? $field : [];
$id = (string) ($field['id'] ?? '');

?>
<div class="lf-search-form__field">
    <label class="lf-form__label" for="<?= lf_e($id) ?>"><?= lf_e($field['label'] ?? '') ?></label>
    <?php if (($field['type'] ?? '') === 'select'): ?>
        <select class="lf-form__input" id="<?= lf_e($id) ?>" name="<?= lf_e($field['name'] ?? '') ?>">
            <?php foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option): ?>
                <option value="<?= lf_e($option['value'] ?? '') ?>"<?= ($option['selected'] ?? false) === true ? ' selected' : '' ?>><?= lf_e($option['label'] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input class="lf-form__input" id="<?= lf_e($id) ?>" name="<?= lf_e($field['name'] ?? '') ?>" type="<?= lf_e($field['type'] ?? 'text') ?>" value="<?= lf_e($field['value'] ?? '') ?>" autocomplete="off"<?= ($field['type'] ?? '') === 'number' ? ' min="0" inputmode="numeric"' : '' ?>>
    <?php endif; ?>
    <?php if (($field['hint'] ?? '') !== ''): ?>
        <p class="lf-form__hint"><?= lf_e($field['hint']) ?></p>
    <?php endif; ?>
</div>
