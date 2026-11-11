<?php

/**
 * Rosewine theme: a series, with its author, summary, totals and its stories in order.
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
<section class="lf-archive lf-series">
    <header class="lf-archive__header lf-series__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
        <?php lf_feed_link(); ?>
        <p class="lf-series__byline">by <?php if (lf_has('series.author_url')): ?><a href="<?php lf_field('series.author_url'); ?>"><?php lf_field('series.author'); ?></a><?php else: ?><?php lf_field('series.author'); ?><?php endif; ?></p>
        <?php if (lf_has('description')): ?>
            <p class="lf-archive__description lf-series__summary"><?php lf_field('description'); ?></p>
        <?php endif; ?>
        <dl class="lf-story-meta lf-series__facts">
            <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Status</dt><dd class="lf-story-meta__value"><?php lf_field('series.status'); ?></dd></div>
            <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Stories</dt><dd class="lf-story-meta__value"><?php lf_field('series.stories'); ?></dd></div>
            <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Chapters</dt><dd class="lf-story-meta__value"><?php lf_field('series.chapters'); ?></dd></div>
            <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Words</dt><dd class="lf-story-meta__value"><?php lf_field('series.words'); ?></dd></div>
        </dl>
    </header>

    <ol class="lf-story-list lf-story-list--series">
        <?php foreach (lf_items('stories') as $card): ?>
            <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
        <?php endforeach; ?>
    </ol>
    <?php lf_pagination(); ?>
</section>
<?php
lf_get_footer();
