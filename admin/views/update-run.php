<?php

/**
 * Admin view: progress of an update, restore or backup that is being carried out step by step.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Update\UpdateRun;
use LumoraFiction\Helpers\Html;

/** @var UpdateRun $run */
/** @var list<array{label: string, state: string}> $steps */
/** @var list<array{time: int, text: string, ok: bool}> $log */
/** @var string $csrfToken */
/** @var string $advanceAction */
/** @var string $cancelAction */
/** @var bool $autoAdvance */
/** @var bool $rollingBack */
/** @var string $assetVersion */

$needsAttention = $run->status === UpdateRun::ATTENTION;
$cancelLabel = $run->destructive && $run->kind !== UpdateRun::KIND_BACKUP ? 'Cancel and go back to the earlier version' : 'Cancel';
?>
<?php if ($rollingBack): ?>
    <p class="lf-notice lf-notice--error" role="alert">Something went wrong, and the site is being put back as it was before the <?= Html::e($run->kind) ?>. Keep this page open; it carries on by itself.</p>
<?php elseif ($needsAttention): ?>
    <p class="lf-notice lf-notice--error" role="alert"><strong>This step did not finish.</strong> <?= Html::e($run->message) ?></p>
    <p>Resume tries the step again. If it keeps failing, cancel<?= $run->destructive ? ' to go back to the earlier version' : '' ?>.</p>
<?php else: ?>
    <p class="lf-lead">This page carries on by itself. Please keep it open until it finishes<?= $run->kind === UpdateRun::KIND_BACKUP ? '' : '; visitors see the maintenance page in the meantime' ?>.</p>
<?php endif; ?>

<section class="lf-card" aria-labelledby="lf-run-steps-title">
    <h2 id="lf-run-steps-title"><?= Html::e(ucfirst($run->kind)) ?><?= $run->kind === UpdateRun::KIND_UPDATE ? ' to version ' . Html::e($run->toVersion) : '' ?></h2>
    <ol class="lf-update-steps">
        <?php foreach ($steps as $step): ?>
            <li class="lf-update-steps__item lf-update-steps__item--<?= Html::e($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
                <?= Html::e($step['label']) ?>
                <span class="lf-update-steps__state"><?= $step['state'] === 'done' ? '(done)' : ($step['state'] === 'current' ? ($needsAttention ? '(stopped)' : '(working)') : '') ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($log !== []): ?>
        <ul class="lf-update-log-list" aria-label="What has happened so far">
            <?php foreach ($log as $line): ?>
                <li class="<?= $line['ok'] ? '' : 'lf-update-log-list__item--problem' ?>"><?= Html::e(gmdate('H:i:s', (int) $line['time'])) ?> UTC &mdash; <?= Html::e($line['text']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="lf-update-actions">
        <form method="post" action="<?= Html::e($advanceAction) ?>" data-lf-autoadvance="<?= $autoAdvance ? '1' : '0' ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button"><?= $needsAttention ? 'Resume' : 'Continue' ?></button>
        </form>

        <?php if (!$rollingBack): ?>
            <form method="post" action="<?= Html::e($cancelAction) ?>">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <button type="submit" class="lf-button lf-button--secondary"><?= Html::e($cancelLabel) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($autoAdvance): ?>
    <script src="<?= Html::e(AdminUrl::asset('js/update-run.js', $assetVersion ?? '')) ?>" defer></script>
<?php endif; ?>
