<?php

/**
 * Built-in theme: a story with its details and chapter list.
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
<article class="lf-story">
    <?php lf_part('notices'); ?>
    <header class="lf-story__header">
        <?php if (lf_has('story.cover.full')): ?>
            <p class="lf-story__cover"><img class="lf-cover lf-cover--full" src="<?php lf_field('story.cover.full'); ?>" alt="<?php lf_field('story.cover.alt'); ?>"></p>
        <?php endif; ?>
        <h1 class="lf-story__title"><?php lf_field('story.title'); ?></h1>
        <p class="lf-story__byline">by <?php lf_field('story.author'); ?></p>
        <p class="lf-story__meta"><?php lf_field('story.status'); ?> &middot; <?php lf_field('story.words'); ?> words &middot; <?php lf_field('story.language'); ?></p>
        <?php if (lf_has('continue.url')): ?>
            <p class="lf-story__actions"><a href="<?php lf_field('continue.url'); ?>">Continue reading: <?php lf_field('continue.title'); ?></a></p>
        <?php endif; ?>
        <?php lf_part('bookmark-buttons'); ?>
        <?php lf_part('follow-button'); ?>
    </header>

    <?php $groups = lf_items('term_groups'); ?>
    <?php if ($groups !== []): ?>
        <dl class="lf-story__terms">
            <?php foreach ($groups as $index => $group): ?>
                <dt class="lf-story__term-label"><?= lf_e($group['label'] ?? '') ?></dt>
                <dd class="lf-story__term-list"><?php lf_term_links('term_groups.' . $index . '.terms'); ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php foreach (['summary' => 'Summary', 'notes' => 'Author\'s notes', 'disclaimer' => 'Disclaimer'] as $key => $heading): ?>
        <?php if (lf_has('story.' . $key)): ?>
            <section class="lf-story__section lf-story__section--<?= lf_e($key) ?>">
                <h2 class="lf-story__section-title"><?= lf_e($heading) ?></h2>
                <p class="lf-story__section-text"><?php lf_field('story.' . $key); ?></p>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <section class="lf-story__section lf-story__section--chapters">
        <h2 class="lf-story__section-title">Chapters</h2>
        <?php if (!lf_has('chapters')): ?>
            <p>There are no chapters yet.</p>
        <?php else: ?>
            <ol class="lf-chapter-list">
                <?php foreach (lf_items('chapters') as $chapter): ?>
                    <li class="lf-chapter-list__item">
                        <a class="lf-chapter-list__link" href="<?= lf_e($chapter['url'] ?? '') ?>"><?= lf_e($chapter['title'] ?? '') ?></a>
                        <span class="lf-chapter-list__meta"><?= lf_e($chapter['words'] ?? '') ?> words<?= lf_e(($chapter['note'] ?? '') !== '' ? ' · ' . $chapter['note'] : '') ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</article>
<?php
lf_get_footer();
