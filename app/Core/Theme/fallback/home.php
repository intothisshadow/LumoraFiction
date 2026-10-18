<?php

/**
 * Built-in theme: the home page.
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
<section class="lf-home">
    <h1 class="lf-page__title"><?php lf_site_name(); ?></h1>
    <?php if (lf_has('stories')): ?>
        <h2 class="lf-story__section-title">Recently published</h2>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item">
                    <a class="lf-story-list__link" href="<?= lf_e($card['url'] ?? '') ?>"><?= lf_e($card['title'] ?? '') ?></a>
                    <span class="lf-story-list__meta">by <?= lf_e($card['author'] ?? '') ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php else: ?>
        <p class="lf-page__message">Welcome. There are no stories to show yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
