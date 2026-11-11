<?php

/**
 * Built-in theme: where a story stands in its series and the collections it belongs to.
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
        <p class="lf-series-line"><?php lf_field('series.label'); ?> in <a href="<?php lf_field('series.url'); ?>"><?php lf_field('series.title'); ?></a></p>
        <?php if (lf_has('series.previous.url') || lf_has('series.next.url')): ?>
            <ul class="lf-series-nav" aria-label="Other stories in this series">
                <?php if (lf_has('series.previous.url')): ?><li><a rel="prev" href="<?php lf_field('series.previous.url'); ?>">Previous: <?php lf_field('series.previous.title'); ?></a></li><?php endif; ?>
                <?php if (lf_has('series.next.url')): ?><li><a rel="next" href="<?php lf_field('series.next.url'); ?>">Next: <?php lf_field('series.next.title'); ?></a></li><?php endif; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($collections !== []): ?>
        <p class="lf-collections-line">Part of <?= count($collections) === 1 ? 'the collection' : 'these collections' ?>:
            <?php foreach ($collections as $index => $collection): ?><?= $index > 0 ? ', ' : '' ?><a href="<?= lf_e($collection['url'] ?? '') ?>"><?= lf_e($collection['title'] ?? '') ?></a><?php endforeach; ?>
        </p>
    <?php endif; ?>
</div>
