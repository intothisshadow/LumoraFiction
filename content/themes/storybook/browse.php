<?php

/**
 * Storybook theme: the Browse hub and the indexes of fandoms, genres, tags and the other kinds of term.
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
    <header class="lf-archive__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
        <?php if (lf_has('description')): ?>
            <p class="lf-archive__description"><?php lf_field('description'); ?></p>
        <?php endif; ?>
    </header>

    <?php if (lf_has('lists') || lf_has('kinds')): ?>
        <?php if (lf_has('lists')): ?>
            <section class="lf-browse__group" aria-labelledby="lf-browse-lists">
                <h2 class="lf-browse__heading" id="lf-browse-lists">Stories</h2>
                <ul class="lf-browse__cards">
                    <?php foreach (lf_items('lists') as $list): ?>
                        <li class="lf-browse__card">
                            <a class="lf-browse__card-link" href="<?= lf_e($list['url'] ?? '') ?>"><?= lf_e($list['label'] ?? '') ?></a>
                            <?php if (($list['description'] ?? '') !== ''): ?>
                                <span class="lf-browse__card-note"><?= lf_e($list['description']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if (lf_has('kinds')): ?>
            <section class="lf-browse__group" aria-labelledby="lf-browse-kinds">
                <h2 class="lf-browse__heading" id="lf-browse-kinds">Find by</h2>
                <ul class="lf-browse__cards">
                    <?php foreach (lf_items('kinds') as $kind): ?>
                        <li class="lf-browse__card">
                            <a class="lf-browse__card-link" href="<?= lf_e($kind['url'] ?? '') ?>"><?= lf_e($kind['label'] ?? '') ?></a>
                            <span class="lf-browse__card-note"><?= lf_e($kind['count_label'] ?? '') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <div class="lf-archive__toolbar">
            <?php if (lf_has('total')): ?>
                <p class="lf-archive__count">Entries: <?php lf_field('total'); ?></p>
            <?php endif; ?>
            <?php if (lf_has('sorts')): ?>
                <nav class="lf-sort" aria-label="Order">
                    <span class="lf-sort__label">Order</span>
                    <ul class="lf-sort__list">
                        <?php foreach (lf_items('sorts') as $sort): ?>
                            <li class="lf-sort__item<?= lf_e(($sort['current'] ?? false) === true ? ' lf-sort__item--current' : '') ?>"><a class="lf-sort__link" href="<?= lf_e($sort['url'] ?? '') ?>"<?= ($sort['current'] ?? false) === true ? ' aria-current="true"' : '' ?>><?= lf_e($sort['label'] ?? '') ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
        <?php if (lf_has('letters')): ?>
            <nav class="lf-letters" aria-label="First letter">
                <ul class="lf-letters__list">
                    <?php foreach (lf_items('letters') as $letter): ?>
                        <li class="lf-letters__item<?= lf_e(($letter['current'] ?? false) === true ? ' lf-letters__item--current' : '') ?>"><a class="lf-letters__link" href="<?= lf_e($letter['url'] ?? '') ?>"<?= ($letter['current'] ?? false) === true ? ' aria-current="true"' : '' ?>><?= lf_e($letter['label'] ?? '') ?></a></li>
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
