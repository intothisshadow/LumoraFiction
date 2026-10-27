<?php

/**
 * Storybook theme: a story with its details, summary and chapter list.
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

$chapters = lf_items('chapters');
$groups = lf_items('term_groups');
?>
<article class="lf-story">
    <?php lf_part('notices'); ?>
    <header class="lf-story__header">
        <?php if (lf_has('story.cover.full')): ?>
            <p class="lf-story__cover"><img class="lf-cover lf-cover--full" src="<?php lf_field('story.cover.full'); ?>" alt="<?php lf_field('story.cover.alt'); ?>" decoding="async"></p>
        <?php endif; ?>
        <h1 class="lf-story__title"><?php lf_field('story.title'); ?></h1>
        <p class="lf-story__byline">by <?php if (lf_has('story.author_url')): ?><a href="<?php lf_field('story.author_url'); ?>"><?php lf_field('story.author'); ?></a><?php else: ?><?php lf_field('story.author'); ?><?php endif; ?></p>
        <?php if ($chapters !== [] && isset($chapters[0]['url']) && !lf_has('chapter.html')): ?>
            <p class="lf-story__actions"><a class="lf-button" href="<?= lf_e($chapters[0]['url']) ?>">Start reading</a></p>
        <?php endif; ?>
        <?php if (lf_has('continue.url')): ?>
            <p class="lf-story__actions"><a class="lf-button" href="<?php lf_field('continue.url'); ?>">Continue reading: <?php lf_field('continue.title'); ?></a></p>
        <?php endif; ?>
        <?php lf_part('bookmark-buttons'); ?>
        <?php lf_part('follow-button'); ?>
        <?php lf_downloads(); ?>
    </header>

    <dl class="lf-story-meta">
        <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Status</dt><dd class="lf-story-meta__value"><?php lf_field('story.status'); ?></dd></div>
        <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Words</dt><dd class="lf-story-meta__value"><?php lf_field('story.words'); ?></dd></div>
        <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Chapters</dt><dd class="lf-story-meta__value"><?= lf_e(count($chapters)) ?></dd></div>
        <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Language</dt><dd class="lf-story-meta__value"><?php lf_field('story.language'); ?></dd></div>
        <?php if (lf_has('story.published')): ?>
            <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Published</dt><dd class="lf-story-meta__value"><?php lf_field('story.published'); ?></dd></div>
        <?php endif; ?>
        <div class="lf-story-meta__item"><dt class="lf-story-meta__label">Updated</dt><dd class="lf-story-meta__value"><?php lf_field('story.updated'); ?></dd></div>
    </dl>

    <?php if ($groups !== []): ?>
        <dl class="lf-term-groups">
            <?php foreach ($groups as $index => $group): ?>
                <div class="lf-term-group lf-term-group--<?= lf_e($group['type'] ?? 'other') ?>">
                    <dt class="lf-term-group__label"><?= lf_e($group['label'] ?? '') ?></dt>
                    <dd class="lf-term-group__terms"><?php lf_term_links('term_groups.' . $index . '.terms', ''); ?></dd>
                </div>
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

    <?php if (lf_has('chapter.html')): ?>
        <?php // A finished one-chapter story is read on this page, so there is no list of chapters to open. ?>
        <section class="lf-story__section lf-story__section--text" aria-label="The story">
            <?php lf_part('resume-button'); ?>
            <?php lf_part('reader-controls'); ?>
            <?php if (lf_has('chapter.notes')): ?>
                <aside class="lf-chapter__notes" aria-label="Author&rsquo;s notes"><p><?php lf_field('chapter.notes'); ?></p></aside>
            <?php endif; ?>
            <div class="lf-chapter__text"><?php lf_field('chapter.html'); ?></div>
        </section>
    <?php else: ?>
    <section class="lf-story__section lf-story__section--chapters">
        <h2 class="lf-story__section-title">Chapters</h2>
        <?php if ($chapters === []): ?>
            <p>There are no chapters yet.</p>
        <?php else: ?>
            <ol class="lf-chapter-list">
                <?php foreach ($chapters as $chapter): ?>
                    <li class="lf-chapter-list__item">
                        <a class="lf-chapter-list__link" href="<?= lf_e($chapter['url'] ?? '') ?>"><?= lf_e($chapter['title'] ?? '') ?></a>
                        <span class="lf-chapter-list__meta"><?= lf_e($chapter['words'] ?? '') ?> words<?= lf_e(($chapter['note'] ?? '') !== '' ? ' · ' . $chapter['note'] : '') ?></span>
                        <?php if (($chapter['edit_url'] ?? '') !== ''): ?>
                            <a class="lf-chapter-list__edit" href="<?= lf_e($chapter['edit_url']) ?>">Edit<span class="lf-screen-reader-text"> <?= lf_e($chapter['title'] ?? '') ?></span></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</article>
<?php
lf_get_footer();
