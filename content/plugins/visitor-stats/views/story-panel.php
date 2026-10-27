<?php

/**
 * Story screen panel: view totals for one story.
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

/** @var array{week: int, month: int, all: int} $totals */
/** @var bool $tracking */
?>
<table class="lf-table">
    <caption class="lf-visually-hidden">Views of this story</caption>
    <tbody>
        <tr><th scope="row">Last 7 days</th><td><?= number_format($totals['week']) ?></td></tr>
        <tr><th scope="row">Last 30 days</th><td><?= number_format($totals['month']) ?></td></tr>
        <tr><th scope="row">All time</th><td><?= number_format($totals['all']) ?></td></tr>
    </tbody>
</table>
<p class="lf-hint">Counts readers who are not signed in, once for each time a story or chapter page is opened.<?= $tracking ? '' : ' Counting is currently switched off, so these numbers are not growing.' ?></p>
