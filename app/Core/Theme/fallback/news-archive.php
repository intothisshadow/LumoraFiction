<?php

/**
 * Built-in theme: the News page, a paged list of announcements.
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
<section class="lf-news">
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <?php if (lf_has('posts')): ?>
        <ol class="lf-news-list">
            <?php foreach (lf_items('posts') as $card): ?>
                <li class="lf-news-list__item">
                    <a class="lf-news-list__link" href="<?= lf_e($card['url'] ?? '') ?>"><?= lf_e($card['title'] ?? '') ?></a>
                    <span class="lf-news-list__meta"><time datetime="<?= lf_e($card['datetime'] ?? '') ?>"><?= lf_e($card['date'] ?? '') ?></time></span>
                    <?php if (($card['excerpt'] ?? '') !== ''): ?>
                        <p class="lf-news-list__excerpt"><?= lf_e($card['excerpt']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">There is no news yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
