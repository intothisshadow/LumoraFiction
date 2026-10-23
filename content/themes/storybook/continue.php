<?php

/**
 * Storybook theme: the signed-in reader's Continue reading list.
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
<section class="lf-archive">
    <header class="lf-archive__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
    </header>

    <?php lf_part('notices'); ?>

    <?php if (lf_has('stories')): ?>
        <div class="lf-archive__toolbar">
            <p class="lf-archive__count">Stories you have started: <?php lf_field('total'); ?></p>
        </div>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item lf-continue-item">
                    <?php lf_part('story-card', ['story' => $card]); ?>
                    <p class="lf-continue-item__place">
                        <?php if (!empty($card['finished'])): ?>
                            <a href="<?= lf_e($card['chapter_url'] ?? '') ?>"><?= lf_e($card['chapter_title'] ?? '') ?></a><span class="lf-continue-item__finished">Finished</span>
                        <?php else: ?>
                            Continue with <a href="<?= lf_e($card['chapter_url'] ?? '') ?>"><?= lf_e($card['chapter_title'] ?? '') ?></a>
                            <?php if (($card['progress'] ?? '') !== ''): ?><span class="lf-continue-item__progress">(<?= lf_e($card['progress']) ?>)</span><?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php if (($card['whats_new'] ?? '') !== ''): ?>
                        <p class="lf-continue-item__new"><?= lf_e($card['whats_new']) ?> since you last read.</p>
                    <?php endif; ?>
                    <p class="lf-continue-item__read">Last read <?= lf_e($card['read_on'] ?? '') ?></p>
                    <?php lf_part('bookmark-form', ['form' => $card['forget'] ?? []]); ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
        <?php if (lf_has('clear.url')): ?>
            <p class="lf-continue-clear"><a href="<?php lf_field('clear.url'); ?>"><?php lf_field('clear.label'); ?></a></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="lf-page__message">You have not started any stories yet. The ones you read will be listed here so you can pick up where you stopped.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
