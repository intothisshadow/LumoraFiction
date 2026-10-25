<?php

/**
 * Admin view: the backups of the files and the database, with the actions on each.
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

/** @var list<array<string, mixed>> $backups */
/** @var UpdateRun|null $running */
/** @var string $csrfToken */
/** @var string $createAction */
/** @var string $runUrl */
/** @var string $updatesUrl */

$kinds = ['manual' => 'Made by hand', 'pre-update' => 'Before an update', 'pre-restore' => 'Before a restore'];
$size = static function (mixed $bytes): string {
    $bytes = (int) $bytes;

    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, (int) ceil($bytes / 1024))) . ' KB';
};
?>
<p class="lf-lead">A backup is a ZIP of the application files and a copy of the database, kept in the storage folder. It does not include your configuration file or the pictures and files writers have uploaded: copy those yourself if you move to another host. A backup holds every private draft and every password hash, so treat a downloaded one like a key.</p>

<?php if ($running !== null): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p>A <?= Html::e($running->kind) ?> is in progress.</p>
        <p><a class="lf-button" href="<?= Html::e($runUrl) ?>">Go to it</a></p>
    </div>
<?php else: ?>
    <form method="post" action="<?= Html::e($createAction) ?>" class="lf-update-actions">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <button type="submit" class="lf-button">Back up now</button>
    </form>
<?php endif; ?>

<section class="lf-card" aria-labelledby="lf-backups-title">
    <h2 id="lf-backups-title">Backups on this server</h2>

    <?php if ($backups === []): ?>
        <p class="lf-hint">There are no backups yet. One is made automatically before every update.</p>
    <?php else: ?>
        <table class="lf-table lf-backups">
            <caption class="lf-visually-hidden">Backups, newest first</caption>
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
                                <?php if ($running === null): ?><a href="<?= Html::e($backup['restore_url']) ?>">Restore</a><?php endif; ?>
                            <?php endif; ?>
                            <a href="<?= Html::e($backup['delete_url']) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<p class="lf-hint">Backups are never deleted by themselves; remove old ones here to free space. To recover by hand when the admin area itself is not reachable, unzip the newest files backup over the site folder and import the database backup with your host&rsquo;s database tool. The <a href="<?= Html::e($updatesUrl) ?>">Updates</a> screen has the log of what was done.</p>
