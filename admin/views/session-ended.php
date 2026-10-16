<?php

/**
 * Shown when a writer submits a form after their session has ended: it hands back what they typed so nothing is lost.
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

/** @var array<string, array{label: string, value: string}> $texts */
/** @var list<array{name: string, value: string}> $hidden */
/** @var string $action */
/** @var string $loginUrl */
?>
<h1>Your session has ended</h1>

<p class="lf-notice lf-notice--warning" role="alert">Nothing you typed has been lost, but it has not been saved either.</p>

<ol>
    <li><a href="<?= Html::e($loginUrl) ?>" target="_blank" rel="noopener">Sign in again</a>. This opens a new tab, so this page stays as it is.</li>
    <li>Come back to this page and press <strong>Continue editing</strong>. You will see the form again with your text in it, ready to save.</li>
</ol>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <?php foreach ($hidden as $field): ?>
        <input type="hidden" name="<?= Html::e($field['name']) ?>" value="<?= Html::e($field['value']) ?>">
    <?php endforeach; ?>

    <?php foreach ($texts as $name => $text): ?>
        <div class="lf-field">
            <label for="kept_<?= Html::e($name) ?>"><?= Html::e($text['label']) ?></label>
            <textarea id="kept_<?= Html::e($name) ?>" name="<?= Html::e($name) ?>" rows="<?= $name === 'content' ? 14 : 3 ?>" readonly><?= Html::e($text['value']) ?></textarea>
        </div>
    <?php endforeach; ?>

    <p><button type="submit" class="lf-button">Continue editing</button></p>
</form>
