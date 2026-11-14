<?php

/**
 * Admin: an import that is being written in steps.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;

/** @var array{token: string, state: string, done: int, total: int, busy: bool, lines: list<array<string, mixed>>} $progress */
/** @var string $csrfToken */
/** @var string $stepUrl */
/** @var string $stopUrl */
/** @var string $assetVersion */
?>
<p class="lf-lead">The stories are being written a few at a time, so a long import never runs out of time on the server. This page carries on by itself; keep it open until it finishes. If you close it, <strong>Stories &gt; Import</strong> lists this import under earlier imports, and you can carry on from there.</p>

<section class="lf-card" aria-labelledby="import-progress-heading">
    <h2 id="import-progress-heading">Imported <?= (int) $progress['done'] ?> of <?= (int) $progress['total'] ?></h2>
    <div aria-live="polite">
        <progress max="<?= max(1, (int) $progress['total']) ?>" value="<?= (int) $progress['done'] ?>"><?= (int) $progress['done'] ?> of <?= (int) $progress['total'] ?></progress>
        <?php if ($progress['busy']): ?>
            <p class="lf-hint">Another request is writing the next story. Wait a moment and carry on.</p>
        <?php endif; ?>
        <?php if ($progress['lines'] !== []): ?>
            <ul class="lf-import-progress__list" aria-label="Stories so far">
                <?php foreach ($progress['lines'] as $line): ?>
                    <li><?= Html::e((string) ($line['title'] ?? '')) ?> &ndash; <?= Html::e(match ((string) ($line['status'] ?? '')) { 'imported' => 'imported', 'skipped' => 'skipped', default => 'not imported' }) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="lf-update-actions">
        <form method="post" action="<?= Html::e($stepUrl) ?>" data-lf-autoadvance="1">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button">Carry on</button>
        </form>
        <form method="post" action="<?= Html::e($stopUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button lf-button--secondary">Stop here</button>
        </form>
    </div>
</section>

<script src="<?= Html::e(AdminUrl::asset('js/import-progress.js', $assetVersion ?? '')) ?>" defer></script>
