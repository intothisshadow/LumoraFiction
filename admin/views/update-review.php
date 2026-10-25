<?php

/**
 * Admin view: what an update package contains and whether this site can take it, before it is confirmed.
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

use LumoraFiction\Core\Update\UpdatePackage;
use LumoraFiction\Helpers\Html;

/** @var array{package: UpdatePackage, source: string, verified: bool, checks: list<array{label: string, status: string, detail: string}>, passes: bool, skipped: list<string>, added: list<string>} $review */
/** @var string $from */
/** @var string $csrfToken */
/** @var string $startAction */
/** @var string $discardAction */
/** @var string $backToUpdates */

$package = $review['package'];
$marks = ['ok' => 'Passed', 'warning' => 'Note', 'error' => 'Problem'];
?>
<p class="lf-lead">Lumora Fiction <strong><?= Html::e($from) ?></strong> will become <strong><?= Html::e($package->version) ?></strong>. Nothing has been changed yet.</p>

<?php if ($review['verified']): ?>
    <p class="lf-notice lf-notice--success" role="status">The package matches its SHA-256 checksum<?= $review['source'] === 'github' ? ' published with the release on GitHub' : ' that you gave' ?>.</p>
<?php else: ?>
    <p class="lf-notice lf-notice--warning" role="status">This package was uploaded without a checksum, so it has been checked for being complete but not for being genuine. Install it only if you got it from the official release page.</p>
<?php endif; ?>

<section class="lf-card" aria-labelledby="lf-review-checks-title">
    <h2 id="lf-review-checks-title">Checks</h2>
    <table class="lf-table lf-update-checks">
        <caption class="lf-visually-hidden">Whether this server and site can take the update</caption>
        <thead>
            <tr>
                <th scope="col">Check</th>
                <th scope="col">Result</th>
                <th scope="col">Detail</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($review['checks'] as $check): ?>
                <tr>
                    <th scope="row"><?= Html::e($check['label']) ?></th>
                    <td><span class="lf-badge lf-update-check lf-update-check--<?= Html::e($check['status']) ?>"><?= Html::e($marks[$check['status']] ?? $check['status']) ?></span></td>
                    <td><?= Html::e($check['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="lf-card" aria-labelledby="lf-review-plan-title">
    <h2 id="lf-review-plan-title">What will happen</h2>
    <ol>
        <li>Visitors see the maintenance page; you keep working in the admin area.</li>
        <li>The files and the database are backed up and the backup is checked. You can download it afterwards from <a href="<?= Html::e($backToUpdates) ?>">Backups</a>.</li>
        <li>The application files are replaced (<?= count($package->files) ?> files). Your configuration, uploads, and any plugin or theme the release does not ship are never touched.</li>
        <li><?= count($package->migrations) ?> database changes are known to this release; only the ones this site has not run yet are applied.</li>
        <li>The new version is checked. If anything fails after the backup, the files and the database are put back automatically.</li>
    </ol>

    <?php if ($review['skipped'] !== []): ?>
        <p class="lf-hint">Left out, because they were removed from this site earlier: <?= Html::e(implode(', ', array_map(static fn (string $dir): string => basename($dir), $review['skipped']))) ?>.</p>
    <?php endif; ?>
    <?php if ($review['added'] !== []): ?>
        <p class="lf-hint">New with this release, installed but not turned on: <?= Html::e(implode(', ', array_map(static fn (string $dir): string => basename($dir), $review['added']))) ?>.</p>
    <?php endif; ?>
</section>

<div class="lf-update-actions">
    <?php if ($review['passes']): ?>
        <form method="post" action="<?= Html::e($startAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button">Back up and update to <?= Html::e($package->version) ?></button>
        </form>
    <?php else: ?>
        <p class="lf-notice lf-notice--error" role="alert">This update cannot be installed until the problems above are fixed.</p>
    <?php endif; ?>

    <form method="post" action="<?= Html::e($discardAction) ?>">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <button type="submit" class="lf-button lf-button--secondary">Throw the package away</button>
    </form>
</div>
