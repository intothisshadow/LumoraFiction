<?php

/**
 * Maintenance › eFiction importer: a run being written in steps.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Plugins\EfictionImporter\Report;

/** @var array{id: int} $run */
/** @var Report $report */
/** @var string $phaseLabel */
/** @var int $phaseNumber */
/** @var int $phaseTotal */
/** @var bool $paused */
/** @var bool $connected */
/** @var string $stepToken */
/** @var string $stepAction */
/** @var string $stopToken */
/** @var string $stopAction */
/** @var string $connectUrl */
/** @var string $assetUrl */
?>
<p class="lf-lead">The archive is being brought across a few items at a time, so a large one never runs out of time on the server. This page carries on by itself; keep it open until it finishes. If you close it, the importer's screen offers to carry it on, and you will be asked for the eFiction password again if you have signed out.</p>

<?php if (!$connected): ?>
    <div class="lf-notice lf-notice--warning" role="status"><p>The eFiction connection is no longer held. <a href="<?= Html::e($connectUrl) ?>">Connect again</a> to carry on.</p></div>
<?php endif; ?>

<section class="lf-card" aria-labelledby="efi-progress-heading">
    <h2 id="efi-progress-heading">Step <?= $phaseNumber ?> of <?= $phaseTotal ?>: <?= Html::e($phaseLabel) ?></h2>
    <div aria-live="polite">
        <progress max="<?= max(1, $phaseTotal) ?>" value="<?= max(0, $phaseNumber - 1) ?>"><?= $phaseNumber ?> of <?= $phaseTotal ?></progress>
        <ul>
            <?php foreach (Report::KINDS as $kind => $label): ?>
                <?php if (isset($report->counts[$kind])): ?>
                    <li><?= Html::e($label) ?>: <?= number_format($report->counts[$kind]['made']) ?> made, <?= number_format($report->counts[$kind]['matched'] + $report->counts[$kind]['again']) ?> matched or already there, <?= number_format($report->counts[$kind]['skipped'] + $report->counts[$kind]['failed']) ?> left out</li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="lf-update-actions">
        <form method="post" action="<?= Html::e($stepAction) ?>"<?= $paused || !$connected ? '' : ' data-lf-autoadvance="1"' ?>>
            <input type="hidden" name="csrf_token" value="<?= Html::e($stepToken) ?>">
            <button type="submit" class="lf-button">Carry on</button>
        </form>
        <form method="post" action="<?= Html::e($stopAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($stopToken) ?>">
            <button type="submit" class="lf-button lf-button--secondary">Stop here</button>
        </form>
    </div>
</section>

<script src="<?= Html::e($assetUrl) ?>" defer></script>
