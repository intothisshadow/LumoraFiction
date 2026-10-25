<?php

/**
 * Built-in theme: one news post with a sanitised body.
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
<article class="lf-news-post">
    <h1 class="lf-page__title"><?php lf_field('post.title'); ?></h1>
    <p class="lf-news-post__meta">
        <time datetime="<?php lf_field('post.datetime'); ?>"><?php lf_field('post.date'); ?></time>
        <?php if (lf_has('post.author')): ?> &middot; by <?php lf_field('post.author'); ?><?php endif; ?>
    </p>
    <div class="lf-news-post__body"><?php lf_field('post.body'); ?></div>
    <p class="lf-news-post__back"><a href="<?php lf_field('post.list_url'); ?>">&larr; All news</a></p>
</article>
<?php
lf_get_footer();
