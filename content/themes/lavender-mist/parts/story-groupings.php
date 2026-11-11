<?php

/**
 * Lavender Mist theme: where a story stands in its series, with links to the stories either side, and the collections it
 * belongs to. Prints nothing for a story that is in neither.
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

$collections = lf_items('collections');

if (!lf_has('series.title') && $collections === []) {
    return;
}
?>
<div class="lf-story-groupings">
    <?php if (lf_has('series.title')): ?>
        <p class="lf-series-line"><span class="lf-series-line__part"><?php lf_field('series.label'); ?></span> in <a class="lf-series-line__link" href="<?php lf_field('series.url'); ?>"><?php lf_field('series.title'); ?></a></p>
        <?php if (lf_has('series.previous.url') || lf_has('series.next.url')): ?>
            <ul class="lf-series-nav" aria-label="Other stories in this series">
                <?php if (lf_has('series.previous.url')): ?>
                    <li class="lf-series-nav__item"><a class="lf-series-nav__link" rel="prev" href="<?php lf_field('series.previous.url'); ?>"><span aria-hidden="true">&larr;</span> Previous: <?php lf_field('series.previous.title'); ?></a></li>
                <?php endif; ?>
                <?php if (lf_has('series.next.url')): ?>
                    <li class="lf-series-nav__item"><a class="lf-series-nav__link" rel="next" href="<?php lf_field('series.next.url'); ?>">Next: <?php lf_field('series.next.title'); ?> <span aria-hidden="true">&rarr;</span></a></li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($collections !== []): ?>
        <div class="lf-collections-line">
            <span class="lf-collections-line__label">Part of <?= count($collections) === 1 ? 'the collection' : 'these collections' ?>:</span>
            <ul class="lf-collections-line__list">
                <?php foreach ($collections as $collection): ?>
                    <li class="lf-collections-line__item"><a href="<?= lf_e($collection['url'] ?? '') ?>"><?= lf_e($collection['title'] ?? '') ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
