<?php

/**
 * Admin view: the Updates screen, with the check for a new release, the manual upload, and what has happened before.
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

use LumoraFiction\Core\Update\UpdateRun;
use LumoraFiction\Helpers\Html;

/** @var string $version */
/** @var array{repository: string, channel: string} $values */
/** @var array<string, string> $errors */
/** @var array{checked_at: string, release: array<string, mixed>|null, newer: bool}|null $last */
/** @var UpdateRun|null $run */
/** @var bool $review */
/** @var array{since: string, reason: string, message: string}|null $stranded */
/** @var list<array<string, mixed>> $history */
/** @var list<string> $logLines */
/** @var bool $canUpload */
/** @var string $csrfToken */
/** @var string $settingsAction */
/** @var string $checkAction */
/** @var string $downloadAction */
/** @var string $uploadAction */
/** @var string $clearAction */
/** @var string $reviewUrl */
/** @var string $runUrl */
/** @var string $backupsUrl */
/** @var string $maintenanceUrl */

$results = ['success' => 'Updated', 'rolled_back' => 'Rolled back', 'cancelled' => 'Cancelled', 'failed' => 'Failed'];
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="lf-lead">You are running Lumora Fiction <strong><?= Html::e($version) ?></strong>. Updating never happens by itself: you check, review and confirm. Before anything is replaced the files and the database are backed up, and if a step fails the site is put back as it was. See also <a href="<?= Html::e($backupsUrl) ?>">Backups</a> and <a href="<?= Html::e($maintenanceUrl) ?>">Maintenance mode</a>.</p>

<?php if ($run !== null): ?>
    <div class="lf-notice lf-notice--warning lf-update-unfinished" role="status">
        <p><strong>A <?= Html::e($run->kind) ?> is unfinished<?= $run->kind === UpdateRun::KIND_UPDATE ? ' (to version ' . Html::e($run->toVersion) . ')' : '' ?>.</strong> <?= $run->message !== '' ? Html::e($run->message) : '' ?></p>
        <p><a class="lf-button" href="<?= Html::e($runUrl) ?>">Resume or cancel it</a></p>
    </div>
<?php elseif ($stranded !== null): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p><strong>The site is still closed to visitors</strong> since <?= Html::e($stranded['since']) ?> UTC, but no update is running. This happens when an update was stopped by hand. Administrators can still sign in.</p>
        <form method="post" action="<?= Html::e($clearAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button">Open the site to visitors</button>
        </form>
    </div>
<?php elseif ($review): ?>
    <div class="lf-notice lf-notice--info" role="status">
        <p>An update package is waiting for your review.</p>
        <p><a class="lf-button" href="<?= Html::e($reviewUrl) ?>">Review the update</a></p>
    </div>
<?php endif; ?>

<section class="lf-card lf-update-check" aria-labelledby="lf-update-check-title">
    <h2 id="lf-update-check-title">Check GitHub for a new release</h2>

    <?php if ($last !== null && $last['release'] !== null): ?>
        <?php $release = $last['release']; ?>
        <?php if ($last['newer']): ?>
            <p class="lf-notice lf-notice--info" role="status">Version <strong><?= Html::e((string) $release['version']) ?></strong> is available<?= $release['published'] !== '' ? ' (published ' . Html::e((string) $release['published']) . ')' : '' ?><?= $release['prerelease'] === true ? ', as a pre-release' : '' ?>.</p>
            <?php if ($release['notes'] !== ''): ?>
                <details class="lf-update-notes">
                    <summary>What is in it</summary>
                    <pre class="lf-update-notes__text"><?= Html::e((string) $release['notes']) ?></pre>
                </details>
            <?php endif; ?>
            <?php if ($release['url'] !== ''): ?><p><a href="<?= Html::e((string) $release['url']) ?>" rel="noopener noreferrer">Read the release page on GitHub</a></p><?php endif; ?>
        <?php else: ?>
            <p class="lf-notice lf-notice--success" role="status">You are running the newest release.</p>
        <?php endif; ?>
        <p class="lf-hint">Last checked <?= Html::e($last['checked_at']) ?> UTC.</p>
    <?php elseif ($last !== null): ?>
        <p class="lf-hint">Last checked <?= Html::e($last['checked_at']) ?> UTC. GitHub has no release to offer on this channel.</p>
    <?php else: ?>
        <p class="lf-hint">The site has not looked yet. Checking contacts GitHub once and installs nothing.</p>
    <?php endif; ?>

    <div class="lf-update-actions">
        <form method="post" action="<?= Html::e($checkAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button lf-button--secondary">Check for updates</button>
        </form>
        <?php if ($last !== null && $last['newer'] && $run === null): ?>
            <form method="post" action="<?= Html::e($downloadAction) ?>">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <button type="submit" class="lf-button">Download and review</button>
            </form>
        <?php endif; ?>
    </div>
    <p class="lf-hint">The download is checked against the SHA-256 checksum published with the release. Nothing is installed until you confirm on the next screen.</p>
</section>

<?php if ($canUpload && $run === null): ?>
    <section class="lf-card lf-update-upload" aria-labelledby="lf-update-upload-title">
        <h2 id="lf-update-upload-title">Upload a release package</h2>
        <p class="lf-hint">For a host that cannot reach GitHub: download the release ZIP (LumoraFiction-v&hellip;.zip) yourself and upload it here.</p>
        <form method="post" action="<?= Html::e($uploadAction) ?>" enctype="multipart/form-data" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <div class="lf-field">
                <label for="package">Release ZIP</label>
                <input type="file" id="package" name="package" accept=".zip,application/zip" required>
            </div>
            <div class="lf-field">
                <label for="checksum">SHA-256 checksum (optional)</label>
                <input type="text" id="checksum" name="checksum" maxlength="64" autocomplete="off" spellcheck="false" aria-describedby="checksum_hint">
                <p class="lf-hint" id="checksum_hint">Paste the checksum from the release page and the upload is refused if it does not match. Without one, the package can only be checked for being complete, not for being genuine.</p>
            </div>
            <p><button type="submit" class="lf-button">Upload and review</button></p>
        </form>
    </section>
<?php endif; ?>

<form method="post" action="<?= Html::e($settingsAction) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Where releases come from</legend>

        <div class="lf-field<?= isset($errors['repository']) ? ' lf-field--error' : '' ?>">
            <label for="repository">GitHub repository</label>
            <input type="text" id="repository" name="repository" value="<?= Html::e($values['repository']) ?>" maxlength="120" autocomplete="off" spellcheck="false" aria-describedby="repository_hint<?= isset($errors['repository']) ? ' repository_error' : '' ?>">
            <p class="lf-hint" id="repository_hint">In the form owner/name. Leave it as it is unless you run your own fork.</p>
            <?php if (isset($errors['repository'])): ?><p class="lf-error" id="repository_error"><?= Html::e($errors['repository']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['channel']) ? ' lf-field--error' : '' ?>">
            <label for="channel">Releases to offer</label>
            <select id="channel" name="channel">
                <option value="stable"<?= $values['channel'] === 'stable' ? ' selected' : '' ?>>Stable releases only</option>
                <option value="prerelease"<?= $values['channel'] === 'prerelease' ? ' selected' : '' ?>>Stable releases and pre-releases</option>
            </select>
            <?php if (isset($errors['channel'])): ?><p class="lf-error"><?= Html::e($errors['channel']) ?></p><?php endif; ?>
        </div>

        <p><button type="submit" class="lf-button lf-button--secondary">Save</button></p>
    </fieldset>
</form>

<section class="lf-card" aria-labelledby="lf-update-history-title">
    <h2 id="lf-update-history-title">What has happened</h2>

    <?php if ($history === []): ?>
        <p class="lf-hint">No update, restore or backup has been made from this screen yet.</p>
    <?php else: ?>
        <table class="lf-table lf-update-history">
            <caption class="lf-visually-hidden">Updates, restores and backups, newest first</caption>
            <thead>
                <tr>
                    <th scope="col">When (UTC)</th>
                    <th scope="col">What</th>
                    <th scope="col">Result</th>
                    <th scope="col">Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $entry): ?>
                    <?php $result = (string) ($entry['result'] ?? ''); ?>
                    <tr>
                        <th scope="row"><time datetime="<?= Html::e(str_replace(' ', 'T', (string) ($entry['time'] ?? ''))) ?>"><?= Html::e((string) ($entry['time'] ?? '')) ?></time></th>
                        <td>
                            <?= Html::e(ucfirst((string) ($entry['kind'] ?? ''))) ?>
                            <?= ($entry['kind'] ?? '') === 'update' ? Html::e((string) ($entry['from'] ?? '') . ' to ' . (string) ($entry['to'] ?? '')) : '' ?>
                            <?= ($entry['source'] ?? '') === 'github' ? '(GitHub)' : (($entry['source'] ?? '') === 'manual' && ($entry['kind'] ?? '') === 'update' ? '(uploaded)' : '') ?>
                        </td>
                        <td><span class="lf-badge lf-update-result lf-update-result--<?= Html::e($result) ?>"><?= Html::e($results[$result] ?? $result) ?></span></td>
                        <td><?= Html::e((string) ($entry['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($logLines !== []): ?>
        <details class="lf-update-log">
            <summary>Update log</summary>
            <pre class="lf-update-log__text"><?= Html::e(implode("\n", $logLines)) ?></pre>
        </details>
    <?php endif; ?>
</section>
