<?php

/**
 * Admin view: the Updates screen, with the state of the site at a glance, the latest release, the manual upload, the
 * database and backup status, the system checks, the settings and what has happened before.
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
use LumoraFiction\Core\Admin\BackupListing;
use LumoraFiction\Core\Admin\Tabs;
use LumoraFiction\Core\Update\CompatibilityChecker;
use LumoraFiction\Core\Update\UpdateRun;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Services\UpdateChecker;

/** @var Tabs|null $tabs the GitHub and Manual Update tabs; null when the upload is not offered, so there is only one way to update */
/** @var array{checked_at: string, reason: string}|null $checkFailure why the last check could not reach GitHub */
/** @var string $assetVersion */
/** @var string $version */
/** @var array{repository: string, channel: string, auto_check: bool, frequency: string} $values */
/** @var array<string, string> $errors */
/** @var array{checked_at: string, release: array<string, mixed>|null, newer: bool}|null $last */
/** @var string $status one of the UpdateChecker status words */
/** @var string|null $lastAttempt when the last check ran, UTC */
/** @var bool $tokenSet */
/** @var string $repository */
/** @var string $repositoryUrl */
/** @var string $releasesUrl */
/** @var string $installFolder */
/** @var array{applied: int, total: int, waiting: int, up_to_date: bool} $schema */
/** @var list<array{label: string, status: string, detail: string}> $systemChecks */
/** @var string|null $notesHtml safe HTML for the release notes, or null when the text could not be read */
/** @var string $notesPlain */
/** @var list<array<string, mixed>> $backups the newest few */
/** @var string $backupToken */
/** @var string $backupCreateAction */
/** @var string $backupsListUrl */
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

$statusLabels = [
    UpdateChecker::STATUS_UP_TO_DATE => 'Up to date',
    UpdateChecker::STATUS_AVAILABLE => 'Update available',
    UpdateChecker::STATUS_NOT_CHECKED => 'Not checked yet',
    UpdateChecker::STATUS_FAILED => 'Check failed',
];
$badges = ['success' => 'Updated', 'rolled_back' => 'Rolled back', 'cancelled' => 'Cancelled', 'failed' => 'Failed'];
$checkLabels = [CompatibilityChecker::OK => 'Pass', CompatibilityChecker::WARNING => 'Warning', CompatibilityChecker::ERROR => 'Fail'];
$kinds = ['manual' => 'Made by hand', 'pre-update' => 'Before an update', 'pre-restore' => 'Before a restore'];
$release = $last['release'] ?? null;
$newer = $last !== null && $last['newer'];

/** A plain sentence for one history row. */
$sentence = static function (array $entry): string {
    $kind = (string) ($entry['kind'] ?? '');
    $result = (string) ($entry['result'] ?? '');
    $from = (string) ($entry['from'] ?? '');
    $to = (string) ($entry['to'] ?? '');

    if ($kind === 'update') {
        return match ($result) {
            'success' => 'Updated from ' . $from . ' to ' . $to . '.',
            'rolled_back' => 'The update to ' . $to . ' did not succeed, so the site was put back as it was.',
            'cancelled' => 'The update to ' . $to . ' was cancelled.',
            default => 'The update to ' . $to . ' failed.',
        };
    }

    $what = $kind === 'restore' ? 'restore' : 'backup';

    return match ($result) {
        'success' => $kind === 'restore' ? 'A backup was restored.' : 'A backup was made.',
        'rolled_back' => 'The ' . $what . ' did not succeed, so the site was put back as it was.',
        'cancelled' => 'The ' . $what . ' was cancelled.',
        default => 'The ' . $what . ' failed.',
    };
};
$size = static fn (mixed $bytes): string => BackupListing::size($bytes);
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

<section class="lf-card lf-update-overview" aria-label="Where this site stands">
    <div class="lf-update-overview__group">
        <h2 class="lf-update-overview__title">Installed version</h2>
        <dl class="lf-update-facts">
            <dt>Version</dt>
            <dd class="lf-update-facts__strong"><?= Html::e($version) ?></dd>
            <dt>Database</dt>
            <dd><?= $schema['up_to_date'] ? 'Schema version ' . (int) $schema['applied'] . ', up to date' : (int) $schema['waiting'] . ' change' . ($schema['waiting'] === 1 ? '' : 's') . ' waiting' ?></dd>
            <dt>Folder</dt>
            <dd><code><?= Html::e($installFolder) ?></code></dd>
        </dl>
    </div>
    <div class="lf-update-overview__group">
        <h2 class="lf-update-overview__title">Update status</h2>
        <dl class="lf-update-facts">
            <dt>Status</dt>
            <dd><span class="lf-badge lf-update-status lf-update-status--<?= Html::e($status) ?>"><?= Html::e($statusLabels[$status] ?? $status) ?></span></dd>
            <dt>Channel</dt>
            <dd><?= $values['channel'] === 'prerelease' ? 'Stable releases and pre-releases' : 'Stable releases only' ?></dd>
            <dt>Last checked</dt>
            <dd><?= $lastAttempt !== null ? Html::e($lastAttempt) . ' UTC' : 'Not yet' ?></dd>
        </dl>
    </div>
    <div class="lf-update-overview__group">
        <h2 class="lf-update-overview__title">Update source</h2>
        <dl class="lf-update-facts">
            <dt>Provider</dt>
            <dd class="lf-update-facts__strong">GitHub</dd>
            <dt>Repository</dt>
            <dd><a href="<?= Html::e($repositoryUrl) ?>" rel="noopener noreferrer"><?= Html::e($repository) ?></a></dd>
            <dt>Releases</dt>
            <dd><a href="<?= Html::e($releasesUrl) ?>" rel="noopener noreferrer">All releases</a></dd>
        </dl>
    </div>
</section>

<p class="lf-lead">Nothing is ever installed without your confirmation: you check, review and confirm. Before anything is replaced the files and the database are backed up, and if a step fails the site is put back as it was. See also <a href="<?= Html::e($backupsUrl) ?>">Backups</a> and <a href="<?= Html::e($maintenanceUrl) ?>">Maintenance mode</a>.</p>

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

<?php if ($checkFailure !== null): ?>
    <p class="lf-notice lf-notice--warning lf-update-unreachable" role="status"><strong>The last check could not reach GitHub</strong> (<?= Html::e($checkFailure['checked_at']) ?> UTC): <?= Html::e($checkFailure['reason']) ?><?= $tabs !== null ? ' You can still update with a release ZIP on the Manual Update tab.' : '' ?></p>
<?php endif; ?>

<?php if ($tabs !== null): ?>
    <?= $tabs->bar('Ways to update') ?>
    <?= $tabs->openPanel('github') ?>
<?php endif; ?>
<section class="lf-card lf-update-release" aria-labelledby="lf-update-release-title">
    <h2 id="lf-update-release-title">Latest release</h2>

    <?php if ($release !== null): ?>
        <p class="lf-update-release__name">
            <strong><?= Html::e((string) $release['name']) ?></strong>
            <span class="lf-hint">version <?= Html::e((string) $release['version']) ?></span>
            <span class="lf-badge"><?= $release['prerelease'] === true ? 'Pre-release' : 'Stable' ?></span>
            <?= $release['published'] !== '' ? '<span class="lf-hint">Published ' . Html::e((string) $release['published']) . '</span>' : '' ?>
        </p>

        <?php if ($newer): ?>
            <p class="lf-notice lf-notice--info" role="status">Version <strong><?= Html::e((string) $release['version']) ?></strong> is newer than the <?= Html::e($version) ?> you are running.</p>
        <?php else: ?>
            <p class="lf-notice lf-notice--success" role="status">You are running the newest release.</p>
        <?php endif; ?>

        <div class="lf-update-actions">
            <?php if ($release['url'] !== ''): ?><a class="lf-button lf-button--secondary" href="<?= Html::e((string) $release['url']) ?>" rel="noopener noreferrer">View release notes on GitHub</a><?php endif; ?>
            <?php if ($newer && $run === null): ?>
                <form method="post" action="<?= Html::e($downloadAction) ?>">
                    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                    <button type="submit" class="lf-button">Download release ZIP</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($newer): ?>
            <p class="lf-hint">The download is checked against the SHA-256 checksum published with the release, and a release without one is refused. Nothing is installed until you confirm on the next screen.</p>
        <?php else: ?>
            <p class="lf-hint">There is no button to download this release again: updates only go forward, so the version you are running cannot be installed over itself. To put damaged files back, restore a backup or upload the files by hand.</p>
        <?php endif; ?>

        <?php if ($release['notes'] !== ''): ?>
            <h3 class="lf-update-notes__heading">Release notes</h3>
            <?php if ($notesHtml !== null): ?>
                <div class="lf-update-notes__body" tabindex="0" role="region" aria-label="Release notes"><?= $notesHtml ?></div>
            <?php else: ?>
                <pre class="lf-update-notes__text" tabindex="0"><?= Html::e($notesPlain) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($last !== null): ?>
        <p class="lf-hint">GitHub has no release to offer on this channel.</p>
    <?php else: ?>
        <p class="lf-hint">The site has not looked yet. Use <strong>Check for updates</strong> below.</p>
    <?php endif; ?>
</section>

<section class="lf-card lf-update-check" aria-labelledby="lf-update-check-title">
    <h2 id="lf-update-check-title">Check for updates</h2>
    <p class="lf-hint">Checking sends one plain request to GitHub's public list of releases. Nothing about your site, your members or your stories is sent: GitHub sees this server's address, as it does with any web request, and the name and version of this software.</p>
    <div class="lf-update-actions">
        <form method="post" action="<?= Html::e($checkAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <button type="submit" class="lf-button lf-button--secondary">Check for updates</button>
        </form>
    </div>
</section>
<?php if ($tabs !== null): ?>
    <?= $tabs->closePanel() ?>
    <?= $tabs->openPanel('manual') ?>
<?php endif; ?>

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
<?php if ($tabs !== null): ?>
    <?= $tabs->closePanel() ?>
<?php endif; ?>

<section class="lf-card lf-update-database" aria-labelledby="lf-update-database-title">
    <h2 id="lf-update-database-title">Database updates</h2>
    <?php if ($schema['up_to_date']): ?>
        <p>The database is up to date: <?= (int) $schema['applied'] ?> change<?= $schema['applied'] === 1 ? ' has' : 's have' ?> been applied.</p>
    <?php else: ?>
        <p class="lf-notice lf-notice--warning" role="status"><?= (int) $schema['waiting'] ?> database change<?= $schema['waiting'] === 1 ? ' is' : 's are' ?> waiting. They are applied the next time an Administrator opens the admin, or by the next update.</p>
    <?php endif; ?>
</section>

<section class="lf-card lf-update-backups" aria-labelledby="lf-update-backups-title">
    <h2 id="lf-update-backups-title">Full backups</h2>

    <?php if ($backups === []): ?>
        <p class="lf-hint">There are no backups yet. One is made automatically before every update.</p>
    <?php else: ?>
        <table class="lf-table lf-backups">
            <caption class="lf-visually-hidden">The newest backups</caption>
            <thead>
                <tr>
                    <th scope="col">Made (UTC)</th>
                    <th scope="col">Why</th>
                    <th scope="col">Version</th>
                    <th scope="col">Size</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <th scope="row"><time datetime="<?= Html::e(str_replace(' ', 'T', (string) ($backup['created_at'] ?? ''))) ?>"><?= Html::e((string) ($backup['created_at'] ?? '')) ?></time></th>
                        <td><?= Html::e($kinds[(string) ($backup['kind'] ?? '')] ?? 'Backup') ?><?= $backup['complete'] ? '' : ' <span class="lf-badge">Not finished</span>' ?></td>
                        <td><?= Html::e((string) ($backup['app_version'] ?? '')) ?></td>
                        <td><?= $backup['complete'] ? Html::e($size(($backup['files_bytes'] ?? 0) + ($backup['db_bytes'] ?? 0))) : '' ?></td>
                        <td class="lf-backups__actions">
                            <?php if ($backup['complete']): ?>
                                <a href="<?= Html::e($backup['files_url']) ?>">Download files</a>
                                <a href="<?= Html::e($backup['database_url']) ?>">Download database</a>
                                <?php if ($run === null): ?><a href="<?= Html::e($backup['restore_url']) ?>">Restore</a><?php endif; ?>
                            <?php endif; ?>
                            <a href="<?= Html::e($backup['delete_url']) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="lf-update-actions">
        <?php if ($run === null): ?>
            <form method="post" action="<?= Html::e($backupCreateAction) ?>">
                <input type="hidden" name="csrf_token" value="<?= Html::e($backupToken) ?>">
                <button type="submit" class="lf-button">Back up now</button>
            </form>
        <?php endif; ?>
        <a class="lf-button lf-button--secondary" href="<?= Html::e($backupsListUrl) ?>">All backups</a>
    </div>
</section>

<section class="lf-card lf-update-system" aria-labelledby="lf-update-system-title">
    <h2 id="lf-update-system-title">System status</h2>
    <p class="lf-hint">The same checks run again on the review screen before an update starts, so a row that fails here would stop one.</p>
    <table class="lf-table lf-update-system__table">
        <caption class="lf-visually-hidden">What this server needs to update the site</caption>
        <thead>
            <tr>
                <th scope="col">Check</th>
                <th scope="col">Result</th>
                <th scope="col">Detail</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($systemChecks as $check): ?>
                <tr>
                    <th scope="row"><?= Html::e($check['label']) ?></th>
                    <td><span class="lf-badge lf-update-check--<?= Html::e($check['status']) ?>"><?= Html::e($checkLabels[$check['status']] ?? $check['status']) ?></span></td>
                    <td><?= Html::e($check['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<form method="post" action="<?= Html::e($settingsAction) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <?php if ($tabs !== null): ?><input type="hidden" name="tab" value="<?= Html::e($tabs->selected()) ?>" data-lf-tab-field><?php endif; ?>

    <fieldset class="lf-card">
        <legend>Update settings</legend>

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

        <div class="lf-choice">
            <input type="checkbox" id="auto_check" name="auto_check" value="1"<?= $values['auto_check'] ? ' checked' : '' ?> aria-describedby="auto_check_hint">
            <label for="auto_check">Check for new releases automatically</label>
            <p class="lf-hint" id="auto_check_hint">When one is due, the check is made the next time an Administrator opens an admin page. There is no background job, and nothing is ever downloaded or installed by it: it only looks, and shows a notice if a newer release exists.</p>
        </div>

        <div class="lf-field<?= isset($errors['frequency']) ? ' lf-field--error' : '' ?>">
            <label for="frequency">How often</label>
            <select id="frequency" name="frequency">
                <option value="daily"<?= $values['frequency'] === 'daily' ? ' selected' : '' ?>>Once a day</option>
                <option value="weekly"<?= $values['frequency'] === 'weekly' ? ' selected' : '' ?>>Once a week</option>
            </select>
            <?php if (isset($errors['frequency'])): ?><p class="lf-error"><?= Html::e($errors['frequency']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['github_token']) ? ' lf-field--error' : '' ?>">
            <label for="github_token">GitHub token (optional)</label>
            <input type="password" id="github_token" name="github_token" value="" maxlength="255" autocomplete="off" spellcheck="false" aria-describedby="github_token_hint<?= isset($errors['github_token']) ? ' github_token_error' : '' ?>">
            <p class="lf-hint" id="github_token_hint">Currently: <strong><?= $tokenSet ? 'Set' : 'Not set' ?></strong>. Only needed when GitHub says this server has asked too often. It raises that limit, is sent only to GitHub and is never shown again. Leave the box empty to keep what is saved.</p>
            <?php if (isset($errors['github_token'])): ?><p class="lf-error" id="github_token_error"><?= Html::e($errors['github_token']) ?></p><?php endif; ?>
            <?php if ($tokenSet): ?>
                <div class="lf-choice">
                    <input type="checkbox" id="clear_token" name="clear_token" value="1">
                    <label for="clear_token">Remove the saved token</label>
                </div>
            <?php endif; ?>
        </div>

        <p><button type="submit" class="lf-button lf-button--secondary">Save</button></p>
    </fieldset>
</form>

<section class="lf-card" aria-labelledby="lf-update-history-title">
    <h2 id="lf-update-history-title">Update history</h2>

    <?php if ($history === []): ?>
        <p class="lf-hint">No update, restore or backup has been made from this screen yet.</p>
    <?php else: ?>
        <table class="lf-table lf-update-history">
            <caption class="lf-visually-hidden">Updates, restores and backups, newest first</caption>
            <thead>
                <tr>
                    <th scope="col">Version</th>
                    <th scope="col">Date (UTC)</th>
                    <th scope="col">Result</th>
                    <th scope="col">Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $entry): ?>
                    <?php
                    $result = (string) ($entry['result'] ?? '');
                    $reached = ($entry['kind'] ?? '') === 'update' ? (string) ($entry['to'] ?? '') : (string) ($entry['from'] ?? '');
                    $via = ($entry['source'] ?? '') === 'github' ? ' (GitHub)' : (($entry['source'] ?? '') === 'manual' && ($entry['kind'] ?? '') === 'update' ? ' (uploaded)' : '');
                    ?>
                    <tr>
                        <th scope="row"><?= Html::e($reached) ?></th>
                        <td><time datetime="<?= Html::e(str_replace(' ', 'T', (string) ($entry['time'] ?? ''))) ?>"><?= Html::e((string) ($entry['time'] ?? '')) ?></time></td>
                        <td><span class="lf-badge lf-update-result lf-update-result--<?= Html::e($result) ?>"><?= Html::e($badges[$result] ?? $result) ?></span> <?= Html::e($sentence($entry) . $via) ?></td>
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

<section class="lf-card lf-update-about" aria-labelledby="lf-update-about-title">
    <h2 id="lf-update-about-title">About updates</h2>
    <ul>
        <li>Nothing is downloaded or installed until you say so, and an update never starts by itself.</li>
        <li>A release downloaded from GitHub is checked against the SHA-256 checksum published with it. A release without one is refused.</li>
        <li>Before anything is replaced, the application files and the database are backed up.</li>
        <li>If a step fails, the files and the database are put back as they were when the update began. A restore does the same from a backup you choose.</li>
        <li>Your configuration file, the pictures and files writers uploaded, and any plugin or theme the release does not ship are left alone.</li>
        <li>An update never puts the installer back.</li>
        <li>While an update runs, visitors see a maintenance page. Administrators can still sign in.</li>
    </ul>
</section>

<?php if ($tabs !== null): ?>
    <script src="<?= Html::e(AdminUrl::asset('js/tabs.js', $assetVersion)) ?>" defer></script>
<?php endif; ?>
