<?php

/**
 * Lavender Mist theme: the list of public collections.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

lf_get_header();
?>
<section class="lf-archive lf-collections">
    <header class="lf-archive__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
        <?php if (lf_has('description')): ?>
            <p class="lf-archive__description"><?php lf_field('description'); ?></p>
        <?php endif; ?>
    </header>

    <?php if (lf_has('collections')): ?>
        <ul class="lf-collection-list">
            <?php foreach (lf_items('collections') as $collection): ?>
                <li class="lf-collection-list__item">
                    <article class="lf-collection-card">
                        <h2 class="lf-collection-card__title"><a href="<?= lf_e($collection['url'] ?? '') ?>"><?= lf_e($collection['title'] ?? '') ?></a></h2>
                        <p class="lf-collection-card__meta"><?= lf_e($collection['count'] ?? '') ?><?php if (($collection['curator'] ?? '') !== ''): ?> &middot; gathered by <?= lf_e($collection['curator']) ?><?php endif; ?></p>
                        <?php if (($collection['description'] ?? '') !== ''): ?>
                            <p class="lf-collection-card__description"><?= lf_e($collection['description']) ?></p>
                        <?php endif; ?>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">There are no collections yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
