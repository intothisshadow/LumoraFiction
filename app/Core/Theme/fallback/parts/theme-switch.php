<?php

/**
 * Built-in theme: the theme switcher, a plain form that works without a script. Printed by lf_theme_switch().
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

$switch = is_array($switch ?? null) ? $switch : [];
$options = is_array($switch['options'] ?? null) ? $switch['options'] : [];

?>
<form class="lf-theme-switch" method="post" action="<?= lf_e($switch['action'] ?? '') ?>">
    <input type="hidden" name="token" value="<?= lf_e($switch['token'] ?? '') ?>">
    <input type="hidden" name="return" value="<?= lf_e($switch['return'] ?? '') ?>">
    <label class="lf-theme-switch__label" for="lf-theme-switch-select"><?= lf_e($switch['label'] ?? 'Theme') ?></label>
    <select class="lf-select lf-theme-switch__select" id="lf-theme-switch-select" name="theme">
        <?php foreach ($options as $option): ?>
            <option value="<?= lf_e($option['value'] ?? '') ?>"<?= ($option['selected'] ?? false) === true ? ' selected' : '' ?>><?= lf_e($option['label'] ?? '') ?></option>
        <?php endforeach; ?>
    </select>
    <button class="lf-theme-switch__button" type="submit"><?= lf_e($switch['submit'] ?? 'Use this theme') ?></button>
</form>
