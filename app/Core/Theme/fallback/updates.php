<?php

/**
 * Built-in theme: the stories a signed-in reader follows that have something new.
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
                <li class="lf-story-list__item lf-updates-item">
                    <a class="lf-story-list__link" href="<?= lf_e($card['url'] ?? '') ?>"><?= lf_e($card['title'] ?? '') ?></a>
                    <span class="lf-story-list__meta">by <?= lf_e($card['author'] ?? '') ?></span>
                    <span class="lf-updates-item__new">&middot; <?= lf_e($card['whats_new'] ?? '') ?>.
                        <?php if (($card['new_url'] ?? '') !== ''): ?><a href="<?= lf_e($card['new_url']) ?>">Read the first new chapter</a><?php endif; ?></span>
                    <?php lf_part('bookmark-form', ['form' => $card['stop'] ?? []]); ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">Nothing new in the stories you follow. Use Follow this story on a story page to be told when it changes.</p>
    <?php endif; ?>

    <?php if (lf_has('stop_all.url')): ?>
        <p class="lf-updates-stop-all"><a href="<?php lf_field('stop_all.url'); ?>"><?php lf_field('stop_all.label'); ?></a></p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
