<?php

/**
 * Lavender Mist theme: a collection, with its curator, description and the stories in it.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

lf_get_header();
?>
<section class="lf-archive lf-collection">
    <header class="lf-archive__header lf-collection__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
        <?php lf_feed_link(); ?>
        <p class="lf-collection__curator">Gathered by <?php if (lf_has('collection.curator_url')): ?><a href="<?php lf_field('collection.curator_url'); ?>"><?php lf_field('collection.curator'); ?></a><?php else: ?><?php lf_field('collection.curator'); ?><?php endif; ?></p>
        <?php if (lf_has('collection.private')): ?>
            <p class="lf-collection__private" role="note">This collection is private. Only you and the site staff can see it.</p>
        <?php endif; ?>
        <?php if (lf_has('description')): ?>
            <p class="lf-archive__description lf-collection__description"><?php lf_field('description'); ?></p>
        <?php endif; ?>
    </header>

    <?php if (lf_has('stories')): ?>
        <p class="lf-archive__count">Stories: <?php lf_field('total'); ?></p>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">There are no stories in this collection yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
