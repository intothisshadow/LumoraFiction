<?php

/**
 * Storybook theme: previous, story and next links around a chapter. Pass "label" so the two copies on a page
 * have different names for screen readers.
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

?>
<nav class="lf-chapter-nav" aria-label="<?= lf_e($label ?? 'Chapters') ?>">
    <?php if (lf_has('previous.url')): ?>
        <a class="lf-chapter-nav__link lf-chapter-nav__link--prev" href="<?php lf_field('previous.url'); ?>" rel="prev">
            <span class="lf-chapter-nav__hint">&larr; Previous</span>
            <?php lf_field('previous.title'); ?>
        </a>
    <?php endif; ?>
    <a class="lf-chapter-nav__link lf-chapter-nav__link--index" href="<?php lf_field('story.url'); ?>">
        <span class="lf-chapter-nav__hint">Story</span>
        All chapters
    </a>
    <?php if (lf_has('next.url')): ?>
        <a class="lf-chapter-nav__link lf-chapter-nav__link--next" href="<?php lf_field('next.url'); ?>" rel="next">
            <span class="lf-chapter-nav__hint">Next &rarr;</span>
            <?php lf_field('next.title'); ?>
        </a>
    <?php endif; ?>
</nav>
