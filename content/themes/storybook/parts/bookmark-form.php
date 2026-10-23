<?php

/**
 * Storybook theme: one bookmark button, a small form that adds or removes a bookmark and works without scripts.
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
$hidden = is_array($form['hidden'] ?? null) ? $form['hidden'] : [];
$saved = ($saved ?? false) === true;

?>
<form class="lf-bookmark-form<?= $saved ? ' lf-bookmark-form--saved' : '' ?>" method="post" action="<?= lf_e($form['action'] ?? '') ?>">
    <input type="hidden" name="csrf_token" value="<?= lf_e($form['csrf_token'] ?? '') ?>">
    <?php foreach ($hidden as $name => $value): ?>
        <input type="hidden" name="<?= lf_e($name) ?>" value="<?= lf_e($value) ?>">
    <?php endforeach; ?>
    <?php if (is_array($form['option'] ?? null)): ?>
        <label class="lf-bookmark-form__option"><input type="checkbox" name="<?= lf_e($form['option']['name'] ?? '') ?>" value="1"> <?= lf_e($form['option']['label'] ?? '') ?></label>
    <?php endif; ?>
    <button class="lf-button lf-bookmark-form__button" type="submit"><?= lf_e($form['submit'] ?? 'Bookmark') ?></button>
</form>
