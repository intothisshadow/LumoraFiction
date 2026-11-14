<?php

/**
 * Admin: the report of an import.
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

use LumoraFiction\Helpers\Html;

/** @var array<string, mixed> $report */
/** @var string $downloadUrl */
/** @var string $undoUrl */
/** @var string $storyUrl */
/** @var string $importUrl */

$states = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'published' => 'Published'];
$stateClass = ['draft' => '', 'pending' => ' lf-status--warning', 'published' => ' lf-status--ok'];
?>
<p class="lf-lead">
    Imported <?= Html::e($report['createdAt']->format('Y-m-d H:i')) ?>:
    <?= (int) $report['imported'] ?> <?= (int) $report['imported'] === 1 ? 'story' : 'stories' ?> made<?= $report['undone'] ? ', and then undone' : '' ?><?= $report['state'] === 'cancelled' ? ', and then stopped before the rest were written' : '' ?>.
</p>

<p>
    <a class="lf-button" href="<?= Html::e($storyUrl) ?>">Go to your stories</a>
    <a href="<?= Html::e($downloadUrl) ?>">Download this report as a text file</a>
    <a href="<?= Html::e($importUrl) ?>">Import more</a>
</p>

<?php foreach ($report['stories'] as $story): ?>
    <?php $status = (string) ($story['status'] ?? ''); ?>
    <section class="lf-card lf-import-result lf-import-result--<?= Html::e($status) ?>">
        <?php $storyId = (int) ($story['story_id'] ?? 0); $state = (string) ($story['state'] ?? 'draft'); ?>
        <h2>
            <?php if ($status === 'imported' && in_array($storyId, $report['live'], true)): ?>
                <a href="<?= Html::e($storyUrl . '/' . $storyId) ?>"><?= Html::e((string) ($story['title'] ?? '')) ?></a>
            <?php else: ?>
                <?= Html::e((string) ($story['title'] ?? '')) ?>
            <?php endif; ?>
            <?php if ($status === 'imported'): ?>
                <span class="lf-status<?= $stateClass[$state] ?? '' ?>"><?= Html::e($states[$state] ?? 'Draft') ?></span>
            <?php endif; ?>
        </h2>
        <p>
            <?php if ($status === 'imported'): ?>
                Imported with <?= (int) ($story['chapters'] ?? 0) ?> <?= (int) ($story['chapters'] ?? 0) === 1 ? 'chapter' : 'chapters' ?>. <?= $state === 'draft' ? 'It is a draft, so nobody can see it yet.' : ($state === 'pending' ? 'It is waiting for approval.' : 'It is published.') ?>
                <?php if (in_array($storyId, $report['live'], true)): ?>
                    <a href="<?= Html::e($storyUrl . '/' . $storyId) ?>">Open the story</a>
                <?php endif; ?>
            <?php elseif ($status === 'skipped'): ?>
                Skipped. <?= Html::e((string) ($story['reason'] ?? '')) ?>
            <?php else: ?>
                Not imported. <?= Html::e((string) ($story['reason'] ?? '')) ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($story['notes'])): ?>
            <ul>
                <?php foreach ($story['notes'] as $note): ?>
                    <li><?= Html::e((string) $note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($report['refused'] !== []): ?>
    <section class="lf-card">
        <h2>Files that were not read</h2>
        <ul>
            <?php foreach ($report['refused'] as $refused): ?>
                <li><?= !empty($refused['name']) ? '<strong>' . Html::e((string) $refused['name']) . '</strong>: ' : '' ?><?= Html::e((string) ($refused['error'] ?? '')) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (!$report['undone'] && $report['live'] !== []): ?>
    <p><a href="<?= Html::e($undoUrl) ?>">Undo this import&hellip;</a></p>
<?php endif; ?>
