<?php

/**
 * Built-in theme: the Browse hub and the indexes of fandoms, genres, tags and the other kinds of term.
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
<section class="lf-archive lf-browse">
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <?php if (lf_has('description')): ?>
        <p class="lf-archive__description"><?php lf_field('description'); ?></p>
    <?php endif; ?>

    <?php if (lf_has('lists') || lf_has('kinds')): ?>
        <?php if (lf_has('lists')): ?>
            <h2 class="lf-browse__heading">Stories</h2>
            <ul class="lf-browse__cards">
                <?php foreach (lf_items('lists') as $list): ?>
                    <li class="lf-browse__card"><a class="lf-browse__card-link" href="<?= lf_e($list['url'] ?? '') ?>"><?= lf_e($list['label'] ?? '') ?></a> <span class="lf-browse__card-note"><?= lf_e($list['description'] ?? '') ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (lf_has('kinds')): ?>
            <h2 class="lf-browse__heading">Find by</h2>
            <ul class="lf-browse__cards">
                <?php foreach (lf_items('kinds') as $kind): ?>
                    <li class="lf-browse__card"><a class="lf-browse__card-link" href="<?= lf_e($kind['url'] ?? '') ?>"><?= lf_e($kind['label'] ?? '') ?></a> <span class="lf-browse__card-note"><?= lf_e($kind['count_label'] ?? '') ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php else: ?>
        <?php if (lf_has('sorts')): ?>
            <p class="lf-archive__sort">Order:
                <?php foreach (lf_items('sorts') as $sort): ?>
                    <a href="<?= lf_e($sort['url'] ?? '') ?>"<?= ($sort['current'] ?? false) === true ? ' aria-current="true"' : '' ?>><?= lf_e($sort['label'] ?? '') ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if (lf_has('letters')): ?>
            <nav class="lf-letters" aria-label="First letter">
                <ul class="lf-letters__list">
                    <?php foreach (lf_items('letters') as $letter): ?>
                        <li class="lf-letters__item"><a class="lf-letters__link" href="<?= lf_e($letter['url'] ?? '') ?>"<?= ($letter['current'] ?? false) === true ? ' aria-current="true"' : '' ?>><?= lf_e($letter['label'] ?? '') ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
        <?php if (lf_has('terms')): ?>
            <ul class="lf-term-index">
                <?php foreach (lf_items('terms') as $term): ?>
                    <li class="lf-term-index__item"><a class="lf-term-index__link" href="<?= lf_e($term['url'] ?? '') ?>"><?= lf_e($term['name'] ?? '') ?></a> <span class="lf-term-index__count"><?= lf_e($term['count_label'] ?? '') ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php lf_pagination(); ?>
        <?php else: ?>
            <p class="lf-page__message">There is nothing listed here yet.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
