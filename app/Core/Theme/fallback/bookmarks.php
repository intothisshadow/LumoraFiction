<?php

/**
 * Built-in theme: the signed-in reader's list of bookmarks.
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
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>

    <?php lf_part('notices'); ?>

    <?php if (lf_has('stories')): ?>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item">
                    <a class="lf-story-list__link" href="<?= lf_e($card['url'] ?? '') ?>"><?= lf_e($card['title'] ?? '') ?></a>
                    <span class="lf-story-list__meta">by <?= lf_e($card['author'] ?? '') ?></span>
                    <?php if (($card['chapter_url'] ?? '') !== ''): ?>
                        <span class="lf-story-list__meta">&middot; <a href="<?= lf_e($card['chapter_url']) ?>"><?= lf_e($card['chapter_title'] ?? '') ?></a></span>
                    <?php endif; ?>
                    <?php lf_part('bookmark-form', ['form' => $card['remove'] ?? []]); ?>
                    <?php if (is_array($card['follow'] ?? null)): ?>
                        <span class="lf-bookmark-item__follow"><?= !empty($card['follow']['following']) ? 'Following.' : 'Not following.' ?></span>
                        <?php lf_part('bookmark-form', ['form' => $card['follow']['form'] ?? []]); ?>
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
