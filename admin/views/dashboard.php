<?php

/**
 * The admin home screen.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/** @var User $user */
/** @var list<array{variant: string, message: string}> $notices */
/** @var array{total: int, by_role: array<string, int>, by_status: array<string, int>}|null $summary */
/** @var list<array{action: string, actor: string|null, target_type: string|null, target_id: string|null, details: array<string, mixed>, at: DateTimeImmutable}> $activity */

$descriptions = ['settings.archive.update' => 'changed the archive settings'];
?>
<p class="lf-lead">Welcome, <?= Html::e($user->displayName) ?>. You are signed in as <?= Html::e(strtolower($user->role->label())) ?>.</p>

<?php if ($notices !== []): ?>
    <section aria-labelledby="lf-notices-heading" class="lf-card">
        <h2 id="lf-notices-heading">Needs attention</h2>
        <ul class="lf-notices">
            <?php foreach ($notices as $notice): ?>
                <li class="lf-notice lf-notice--<?= Html::e($notice['variant']) ?>"><?= Html::e($notice['message']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($summary !== null): ?>
    <section aria-labelledby="lf-people-heading" class="lf-card">
        <h2 id="lf-people-heading">People</h2>
        <p><?= (int) $summary['total'] ?> account<?= $summary['total'] === 1 ? '' : 's' ?> in total.</p>
        <table class="lf-table">
            <caption class="lf-visually-hidden">Accounts by role and status</caption>
            <thead>
                <tr><th scope="col">Role</th><th scope="col">Accounts</th></tr>
            </thead>
            <tbody>
                <?php foreach ($summary['by_role'] as $role => $count): ?>
                    <tr><th scope="row"><?= Html::e(ucfirst($role)) ?></th><td><?= (int) $count ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (($summary['by_status']['pending'] ?? 0) > 0): ?>
            <p><?= (int) $summary['by_status']['pending'] ?> waiting to confirm their email address.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($user->can(Capability::ManageSite)): ?>
    <section aria-labelledby="lf-activity-heading" class="lf-card">
        <h2 id="lf-activity-heading">Recent activity</h2>
        <?php if ($activity === []): ?>
            <p>Nothing has been recorded yet.</p>
        <?php else: ?>
            <ul class="lf-activity">
                <?php foreach ($activity as $entry): ?>
                    <li>
                        <?= Html::e($entry['actor'] ?? 'The system') ?>
                        <?= Html::e($descriptions[$entry['action']] ?? 'did ' . $entry['action']) ?>
                        <time datetime="<?= Html::e($entry['at']->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($entry['at']->format('Y-m-d H:i')) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section aria-labelledby="lf-links-heading" class="lf-card">
        <h2 id="lf-links-heading">Quick links</h2>
        <ul>
            <li><a href="<?= Html::e(AdminUrl::to('settings/archive')) ?>">Archive settings</a></li>
            <li><a href="<?= Html::e(AdminUrl::to('maintenance/system-information')) ?>">System information</a></li>
        </ul>
    </section>
<?php endif; ?>
