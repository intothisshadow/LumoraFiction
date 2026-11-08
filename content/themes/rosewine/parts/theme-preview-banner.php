<?php

/**
 * Rosewine theme: the notice shown while an Administrator previews a theme. Printed by lf_theme_preview_banner().
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

$banner = is_array($banner ?? null) ? $banner : [];

?>
<div class="lf-theme-preview" role="region" aria-label="Theme preview">
    <p class="lf-theme-preview__text"><?= lf_e($banner['text'] ?? '') ?></p>
    <form class="lf-theme-preview__form" method="post" action="<?= lf_e($banner['activate_action'] ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= lf_e($banner['token'] ?? '') ?>">
        <input type="hidden" name="return" value="<?= lf_e($banner['return'] ?? '') ?>">
        <button class="lf-theme-preview__button lf-theme-preview__button--primary" type="submit"><?= lf_e($banner['activate_label'] ?? 'Make this the active theme') ?></button>
    </form>
    <form class="lf-theme-preview__form" method="post" action="<?= lf_e($banner['exit_action'] ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= lf_e($banner['token'] ?? '') ?>">
        <input type="hidden" name="return" value="<?= lf_e($banner['return'] ?? '') ?>">
        <button class="lf-theme-preview__button" type="submit"><?= lf_e($banner['exit_label'] ?? 'Exit preview') ?></button>
    </form>
</div>
