<?php

/**
 * Lavender Mist theme: a chapter's text with its notes, reader controls and the links to the chapters either side.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

lf_get_header();
?>
<article class="lf-chapter">
    <?php lf_part('notices'); ?>
    <header class="lf-chapter__header">
        <p class="lf-chapter__story"><a href="<?php lf_field('story.url'); ?>"><?php lf_field('story.title'); ?></a> &middot; by <?php if (lf_has('story.author_url')): ?><a href="<?php lf_field('story.author_url'); ?>"><?php lf_field('story.author'); ?></a><?php else: ?><?php lf_field('story.author'); ?><?php endif; ?></p>
        <h1 class="lf-chapter__title"><?php lf_field('chapter.title'); ?></h1>
        <?php lf_feed_link(); ?>
        <p class="lf-chapter__meta"><?php if (lf_has('chapter.total')): ?>Chapter <?php lf_field('chapter.position'); ?> of <?php lf_field('chapter.total'); ?><?php else: ?>Chapter <?php lf_field('chapter.number'); ?><?php endif; ?> &middot; <?php lf_field('chapter.words'); ?> words</p>
    </header>

    <?php lf_part('chapter-nav', ['label' => 'Chapters, top']); ?>
    <?php lf_part('bookmark-buttons'); ?>
    <?php lf_part('resume-button'); ?>
    <?php lf_part('reader-controls'); ?>

    <?php if (lf_has('chapter.notes')): ?>
        <aside class="lf-chapter__notes" aria-label="Author&rsquo;s notes"><p><?php lf_field('chapter.notes'); ?></p></aside>
    <?php endif; ?>

    <div class="lf-chapter__text"><?php lf_field('chapter.html'); ?></div>

    <?php if (lf_has('chapter.end_notes')): ?>
        <aside class="lf-chapter__end-notes" aria-label="Author&rsquo;s end notes"><p><?php lf_field('chapter.end_notes'); ?></p></aside>
    <?php endif; ?>

    <?php if (lf_has('story.end_notes')): ?>
        <aside class="lf-story__end-notes" aria-label="End notes for the story"><p><?php lf_field('story.end_notes'); ?></p></aside>
    <?php endif; ?>

    <?php lf_part('chapter-nav', ['label' => 'Chapters, bottom']); ?>

    <?php lf_comments(); ?>
</article>
<?php
lf_get_footer();
