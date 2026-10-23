<?php

/**
 * Storybook theme: the signed-in reader's list of bookmarks.
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
            <p class="lf-archive__count">Bookmarks: <?php lf_field('total'); ?></p>
        </div>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item lf-bookmark-item">
                    <?php lf_part('story-card', ['story' => $card]); ?>
                    <?php if (($card['chapter_url'] ?? '') !== ''): ?>
                        <p class="lf-bookmark-item__chapter">Bookmarked chapter: <a href="<?= lf_e($card['chapter_url']) ?>"><?= lf_e($card['chapter_title'] ?? '') ?></a></p>
                    <?php endif; ?>
                    <?php lf_part('bookmark-form', ['form' => $card['remove'] ?? []]); ?>
                    <?php if (is_array($card['follow'] ?? null)): ?>
                        <p class="lf-bookmark-item__follow"><?= !empty($card['follow']['following']) ? 'You are following this story.' : 'Not following this story.' ?></p>
                        <?php lf_part('bookmark-form', ['form' => $card['follow']['form'] ?? [], 'saved' => !empty($card['follow']['following'])]); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">You have no bookmarks yet. Use the Bookmark button on a story or chapter to save it here.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
