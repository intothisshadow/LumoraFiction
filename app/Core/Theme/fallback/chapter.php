<?php

/**
 * Built-in theme: a chapter's text with its notes and the links to the chapters either side.
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
<article class="lf-chapter">
    <?php lf_part('notices'); ?>
    <header class="lf-chapter__header">
        <p class="lf-chapter__story"><a href="<?php lf_field('story.url'); ?>"><?php lf_field('story.title'); ?></a></p>
        <h1 class="lf-chapter__title"><?php lf_field('chapter.title'); ?></h1>
        <p class="lf-chapter__meta">Chapter <?php lf_field('chapter.number'); ?> &middot; <?php lf_field('chapter.words'); ?> words</p>
    </header>

    <?php lf_part('bookmark-buttons'); ?>
    <?php lf_part('resume-button'); ?>

    <?php if (lf_has('chapter.notes')): ?>
        <aside class="lf-chapter__notes" aria-label="Author&rsquo;s notes"><p><?php lf_field('chapter.notes'); ?></p></aside>
    <?php endif; ?>

    <div class="lf-chapter__text"><?php lf_field('chapter.html'); ?></div>

    <nav class="lf-chapter__nav" aria-label="Chapters">
        <?php if (lf_has('previous.url')): ?><a class="lf-chapter__nav-link lf-chapter__nav-link--prev" href="<?php lf_field('previous.url'); ?>" rel="prev">&larr; <?php lf_field('previous.title'); ?></a><?php endif; ?>
        <?php if (lf_has('next.url')): ?><a class="lf-chapter__nav-link lf-chapter__nav-link--next" href="<?php lf_field('next.url'); ?>" rel="next"><?php lf_field('next.title'); ?> &rarr;</a><?php endif; ?>
    </nav>
</article>
<?php
lf_get_footer();
