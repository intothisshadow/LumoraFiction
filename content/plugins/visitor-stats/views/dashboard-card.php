<?php

/**
 * Dashboard card: view totals and the most read stories.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array{today: int, week: int, month: int, all: int} $totals */
/** @var list<array{story_id: int, views: int, title: string, url: string}> $top */
/** @var int $days */
/** @var bool $tracking */
/** @var string $screenUrl */
?>
<?php if (!$tracking): ?>
    <p>Counting is off, so nothing is being counted. <a href="<?= Html::e($screenUrl) ?>">Turn it on</a>.</p>
<?php endif; ?>
<table class="lf-table">
    <caption class="lf-visually-hidden">Views of stories</caption>
    <tbody>
        <tr><th scope="row">Today</th><td><?= number_format($totals['today']) ?></td></tr>
        <tr><th scope="row">Last 7 days</th><td><?= number_format($totals['week']) ?></td></tr>
        <tr><th scope="row">Last 30 days</th><td><?= number_format($totals['month']) ?></td></tr>
        <tr><th scope="row">All time</th><td><?= number_format($totals['all']) ?></td></tr>
    </tbody>
</table>
<?php if ($top !== []): ?>
    <h3>Most read, last <?= (int) $days ?> days</h3>
    <ol>
        <?php foreach ($top as $story): ?>
            <li><a href="<?= Html::e($story['url']) ?>"><?= Html::e($story['title']) ?></a> (<?= number_format($story['views']) ?>)</li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
<p><a href="<?= Html::e($screenUrl) ?>">All visitor stats</a></p>
