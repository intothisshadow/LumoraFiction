<?php

/**
 * Built-in theme: one bookmark button, a small form that adds or removes a bookmark.
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

?>
<form class="lf-bookmark-form" method="post" action="<?= lf_e($form['action'] ?? '') ?>">
    <input type="hidden" name="csrf_token" value="<?= lf_e($form['csrf_token'] ?? '') ?>">
    <?php foreach ($hidden as $name => $value): ?>
        <input type="hidden" name="<?= lf_e($name) ?>" value="<?= lf_e($value) ?>">
    <?php endforeach; ?>
    <button class="lf-bookmark-form__button" type="submit"><?= lf_e($form['submit'] ?? 'Bookmark') ?></button>
</form>
