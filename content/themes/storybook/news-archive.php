<?php

/**
 * Storybook theme: the News page, a paged list of announcements handed to it as a "posts" list.
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
<section class="lf-news">
    <header class="lf-archive__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
    </header>

    <?php if (lf_has('posts')): ?>
        <ol class="lf-news-list">
            <?php foreach (lf_items('posts') as $card): ?>
                <li class="lf-news-list__item"><?php lf_part('news-card', ['post' => $card]); ?></li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">There is no news yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
