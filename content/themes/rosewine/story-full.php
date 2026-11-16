<?php

/**
 * Rosewine theme: a story with several chapters printed on one page, with a contents list.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

lf_get_header();

$groups = lf_items('term_groups');
$contents = lf_items('whole.contents');
$chapters = lf_items('whole.chapters');
?>
<article class="lf-story lf-whole">
    <?php lf_part('notices'); ?>
    <header class="lf-story__header">
        <h1 class="lf-story__title"><?php lf_field('story.title'); ?></h1>
        <?php lf_feed_link(); ?>
        <p class="lf-story__byline">by <?php if (lf_has('story.author_url')): ?><a href="<?php lf_field('story.author_url'); ?>"><?php lf_field('story.author'); ?></a><?php else: ?><?php lf_field('story.author'); ?><?php endif; ?></p>
        <div class="lf-story__tools">
            <?php lf_part('bookmark-buttons'); ?>
            <?php lf_part('follow-button'); ?>
        </div>
        <?php lf_downloads(); ?>
    </header>

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

    <?php lf_part('reader-controls'); ?>

    <?php if (lf_has('whole.notice')): ?>
        <div class="lf-whole__notice" role="note"><p><?php lf_field('whole.notice'); ?></p></div>
    <?php endif; ?>

    <a class="lf-whole__skip" href="#lf-whole-text">Skip the contents</a>
    <nav class="lf-whole__contents" aria-labelledby="lf-whole-contents-title">
        <h2 class="lf-whole__contents-title" id="lf-whole-contents-title">Chapters</h2>
        <ol class="lf-whole__contents-list">
            <?php foreach ($contents as $entry): ?>
                <li class="lf-whole__contents-item">
                    <a href="<?= lf_e($entry['url'] ?? '') ?>"><?= lf_e($entry['title'] ?? '') ?></a>
                    <span class="lf-whole__contents-meta"><?= lf_e($entry['words'] ?? '') ?> words</span>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div id="lf-whole-text" tabindex="-1">
        <?php foreach ($chapters as $index => $chapter): ?>
            <section class="lf-whole__chapter" id="<?= lf_e($chapter['anchor'] ?? '') ?>" aria-labelledby="<?= lf_e($chapter['anchor'] ?? '') ?>-title">
                <h2 class="lf-whole__chapter-title" id="<?= lf_e($chapter['anchor'] ?? '') ?>-title"><?= lf_e($chapter['title'] ?? '') ?></h2>
                <p class="lf-whole__chapter-meta">Chapter <?= lf_e($chapter['position'] ?? '') ?> of <?php lf_field('whole.total'); ?> &middot; <?= lf_e($chapter['words'] ?? '') ?> words</p>
                <?php if (($chapter['notes'] ?? '') !== ''): ?>
                    <aside class="lf-chapter__notes" aria-label="Author&rsquo;s notes"><p><?= lf_e($chapter['notes']) ?></p></aside>
                <?php endif; ?>
                <div class="lf-chapter__text"><?php lf_field('whole.chapters.' . $index . '.html'); ?></div>
                <?php if (($chapter['end_notes'] ?? '') !== ''): ?>
                    <aside class="lf-chapter__end-notes" aria-label="Author&rsquo;s end notes"><p><?= lf_e($chapter['end_notes']) ?></p></aside>
                <?php endif; ?>
                <?php if (($chapter['comments_label'] ?? '') !== ''): ?>
                    <p class="lf-whole__comments"><a href="<?= lf_e($chapter['comments_url'] ?? '') ?>"><?= lf_e($chapter['comments_label']) ?> on this chapter</a></p>
                <?php endif; ?>
                <p class="lf-whole__top"><a href="#lf-whole-contents-title">Back to the contents</a></p>
            </section>
        <?php endforeach; ?>
    </div>

    <?php if (lf_has('story.end_notes')): ?>
        <aside class="lf-story__end-notes" aria-label="End notes for the story"><p><?php lf_field('story.end_notes'); ?></p></aside>
    <?php endif; ?>

    <?php if (lf_has('whole.next.url')): ?>
        <p class="lf-whole__footer"><a class="lf-button" href="<?php lf_field('whole.next.url'); ?>">Continue with <?php lf_field('whole.next.title'); ?></a></p>
    <?php endif; ?>

    <p class="lf-whole__back"><a href="<?php lf_field('whole.back_url'); ?>">Back to chapter by chapter</a></p>
</article>
<?php
lf_get_footer();
