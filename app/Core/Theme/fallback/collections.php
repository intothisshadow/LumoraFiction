<?php

/**
 * Built-in theme: the list of public collections.
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
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <?php if (lf_has('collections')): ?>
        <ul class="lf-collection-list">
            <?php foreach (lf_items('collections') as $collection): ?>
                <li class="lf-collection-list__item">
                    <a href="<?= lf_e($collection['url'] ?? '') ?>"><?= lf_e($collection['title'] ?? '') ?></a>
                    <span class="lf-collection-list__meta"><?= lf_e($collection['count'] ?? '') ?><?php if (($collection['curator'] ?? '') !== ''): ?> &middot; gathered by <?= lf_e($collection['curator']) ?><?php endif; ?></span>
                    <?php if (($collection['description'] ?? '') !== ''): ?><br><?= lf_e($collection['description']) ?><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p>There are no collections yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
