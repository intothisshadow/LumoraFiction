<?php

/**
 * Storybook theme: a plain page of text.
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
<article class="lf-page">
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <?php if (lf_has('page.html')): ?>
        <div class="lf-page__body"><?php lf_field('page.html'); ?></div>
    <?php elseif (lf_has('message')): ?>
        <p class="lf-page__message"><?php lf_field('message'); ?></p>
    <?php endif; ?>
</article>
<?php
lf_get_footer();
