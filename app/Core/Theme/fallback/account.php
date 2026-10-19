<?php

/**
 * Built-in theme: the account pages (sign in, registration, password reset, profile). One template draws them all
 * from the forms, notices and links the page supplies.
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

lf_get_header();
?>
<article class="lf-page lf-account">
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <?php if (lf_has('intro')): ?>
        <p class="lf-account__intro"><?php lf_field('intro'); ?></p>
    <?php endif; ?>
    <?php foreach (lf_items('notices') as $notice): ?>
        <p class="lf-notice lf-notice--<?= lf_e($notice['type'] ?? 'info') ?>" role="<?= ($notice['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= lf_e($notice['text'] ?? '') ?></p>
    <?php endforeach; ?>
    <?php $shortcuts = lf_items('shortcuts'); ?>
    <?php if ($shortcuts !== []): ?>
        <ul class="lf-account__links">
            <?php foreach ($shortcuts as $link): ?>
                <li class="lf-account__link-item"><a href="<?= lf_e($link['url'] ?? '') ?>"><?= lf_e($link['label'] ?? '') ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php foreach (lf_items('forms') as $form): ?>
        <?php lf_part('account-form', ['form' => $form]); ?>
    <?php endforeach; ?>
    <?php $links = lf_items('links'); ?>
    <?php if ($links !== []): ?>
        <ul class="lf-account__links">
            <?php foreach ($links as $link): ?>
                <li class="lf-account__link-item"><a href="<?= lf_e($link['url'] ?? '') ?>"><?= lf_e($link['label'] ?? '') ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
<?php
lf_get_footer();
