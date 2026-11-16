<?php

/**
 * Maintenance › eFiction importer: the report of a run.
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

/** @var array{id: int, state: string, created_at: string} $run */
/** @var Report $report */
/** @var string $homeUrl */
/** @var string $textUrl */
/** @var string $csvUrl */
/** @var string $resumeUrl */
/** @var string $usersUrl */

$state = ['done' => 'finished', 'cancelled' => 'stopped before the end', 'running' => 'not finished yet'][$run['state']] ?? $run['state'];
?>
<p class="lf-lead">This run was <?= Html::e($state) ?> (started <?= Html::e($run['created_at']) ?>). The report holds eFiction ids and reasons only: no passwords, no connection details and no email addresses.</p>

<?php if ($run['state'] === 'running'): ?>
    <div class="lf-notice lf-notice--warning" role="status"><p><a href="<?= Html::e($resumeUrl) ?>">Carry this run on</a>.</p></div>
<?php endif; ?>

<section class="lf-card" aria-labelledby="efi-counts-heading">
    <h2 id="efi-counts-heading">What was done</h2>
    <table class="lf-table">
        <caption class="lf-visually-hidden">Counts by kind</caption>
        <thead><tr><th scope="col">Kind</th><th scope="col">Made</th><th scope="col">Matched a thing already here</th><th scope="col">Already imported before</th><th scope="col">Skipped</th><th scope="col">Failed</th></tr></thead>
        <tbody>
            <?php foreach (Report::KINDS as $kind => $label): ?>
                <?php if (isset($report->counts[$kind])): ?>
                    <?php $row = $report->counts[$kind]; ?>
                    <tr><th scope="row"><?= Html::e($label) ?></th><td><?= number_format($row['made']) ?></td><td><?= number_format($row['matched']) ?></td><td><?= number_format($row['again'] ?? 0) ?></td><td><?= number_format($row['skipped']) ?></td><td><?= number_format($row['failed']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($report->extra !== []): ?>
        <ul>
            <?php foreach ($report->extra as $name => $value): ?>
                <li><?= Html::e((string) $name) ?>: <?= is_array($value) ? Html::e(implode(', ', array_map('strval', $value))) : number_format((int) $value) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ((int) ($report->extra['Accounts that must set a new password'] ?? 0) > 0): ?>
        <p class="lf-hint"><?= number_format((int) $report->extra['Accounts that must set a new password']) ?> accounts were not given a password: eFiction kept only an old kind of hash for them, which is never stored here. They can use <strong>Forgot your password?</strong> on the sign-in page, or you can send them a reset link from the <a href="<?= Html::e($usersUrl) ?>">Users</a> screen (tick them, then choose the bulk action to send a password reset).</p>
    <?php endif; ?>

    <?php foreach ($report->notes as $note): ?>
        <p class="lf-hint"><?= Html::e($note) ?></p>
    <?php endforeach; ?>

    <p><a class="lf-button lf-button--secondary" href="<?= Html::e($textUrl) ?>">Download as text</a> <a class="lf-button lf-button--secondary" href="<?= Html::e($csvUrl) ?>">Download as CSV</a></p>
</section>

<?php if ($report->lines !== []): ?>
    <section class="lf-card" aria-labelledby="efi-lines-heading">
        <h2 id="efi-lines-heading">Everything left out, with the reason</h2>
        <table class="lf-table">
            <caption class="lf-visually-hidden">Items that were skipped or failed</caption>
            <thead><tr><th scope="col">Kind</th><th scope="col">eFiction id</th><th scope="col">What happened</th><th scope="col">Why</th></tr></thead>
            <tbody>
                <?php foreach ($report->lines as $line): ?>
                    <tr><td><?= Html::e(Report::KINDS[$line['kind']] ?? $line['kind']) ?></td><td><?= Html::e($line['id']) ?></td><td><?= Html::e($line['status']) ?></td><td><?= Html::e($line['reason']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($report->overflow > 0): ?>
            <p class="lf-hint"><?= number_format($report->overflow) ?> more are not listed, to keep the report a readable size.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<p><a class="lf-button" href="<?= Html::e($homeUrl) ?>">Back to the importer</a></p>
