<?php

/**
 * Storybook theme: a chapter's text with its notes, reader controls and the links to the chapters either side.
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
    <header class="lf-chapter__header">
        <p class="lf-chapter__story"><a href="<?php lf_field('story.url'); ?>"><?php lf_field('story.title'); ?></a> &middot; by <?php if (lf_has('story.author_url')): ?><a href="<?php lf_field('story.author_url'); ?>"><?php lf_field('story.author'); ?></a><?php else: ?><?php lf_field('story.author'); ?><?php endif; ?></p>
        <h1 class="lf-chapter__title"><?php lf_field('chapter.title'); ?></h1>
        <p class="lf-chapter__meta"><?php if (lf_has('chapter.total')): ?>Chapter <?php lf_field('chapter.position'); ?> of <?php lf_field('chapter.total'); ?><?php else: ?>Chapter <?php lf_field('chapter.number'); ?><?php endif; ?> &middot; <?php lf_field('chapter.words'); ?> words</p>
    </header>

    <?php lf_part('chapter-nav', ['label' => 'Chapters, top']); ?>
    <?php lf_part('reader-controls'); ?>

    <?php if (lf_has('chapter.notes')): ?>
        <aside class="lf-chapter__notes" aria-label="Author&rsquo;s notes"><p><?php lf_field('chapter.notes'); ?></p></aside>
    <?php endif; ?>

    <div class="lf-chapter__text"><?php lf_field('chapter.html'); ?></div>

    <?php lf_part('chapter-nav', ['label' => 'Chapters, bottom']); ?>
</article>
<?php
lf_get_footer();
