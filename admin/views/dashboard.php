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
/** @var list<array{variant: string, message: string}> $notices the most serious alerts, a few at most */
/** @var int $alertTotal every alert there is, shown or not */
/** @var string $alertsUrl */
/** @var array{total: int, by_role: array<string, int>, by_status: array<string, int>}|null $summary */
/** @var array<string, int>|null $writing the writer's own story counts by publication state */
/** @var int|null $awaitingApproval stories in the approval queue; null for someone who may not review */
/** @var string $reviewUrl */
/** @var array{draft: int, published: int}|null $newsCounts news posts by state; null for someone who may not manage news */
/** @var string $newsUrl */
/** @var string $newNewsUrl */
/** @var string $storiesUrl */
/** @var string $newStoryUrl */
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
        <p><a href="<?= Html::e($alertsUrl) ?>"><?= $alertTotal > count($notices) ? 'See all ' . (int) $alertTotal . ' alerts' : 'Open Alerts' ?></a></p>
    </section>
<?php endif; ?>

<?php if ($awaitingApproval !== null): ?>
    <section aria-labelledby="lf-review-heading" class="lf-card">
        <h2 id="lf-review-heading">Awaiting approval</h2>
        <?php if ($awaitingApproval === 0): ?>
            <p>No story is waiting for approval.</p>
        <?php else: ?>
            <p><a href="<?= Html::e($reviewUrl) ?>"><?= (int) $awaitingApproval ?> <?= $awaitingApproval === 1 ? 'story is' : 'stories are' ?> waiting for approval</a>.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($writing !== null): ?>
    <section aria-labelledby="lf-writing-heading" class="lf-card">
        <h2 id="lf-writing-heading">Your writing</h2>
        <?php if (array_sum($writing) === 0): ?>
            <p>You have not written a story yet.</p>
        <?php else: ?>
            <ul class="lf-writing-counts">
                <li><a href="<?= Html::e($storiesUrl . '?state=draft') ?>"><?= (int) $writing['draft'] ?> <?= $writing['draft'] === 1 ? 'draft' : 'drafts' ?></a></li>
                <li><a href="<?= Html::e($storiesUrl . '?state=published') ?>"><?= (int) $writing['published'] ?> published</a></li>
                <?php if ($writing['pending'] > 0): ?><li><a href="<?= Html::e($storiesUrl . '?state=pending') ?>"><?= (int) $writing['pending'] ?> waiting for approval</a></li><?php endif; ?>
                <?php if ($writing['unpublished'] > 0): ?><li><a href="<?= Html::e($storiesUrl . '?state=unpublished') ?>"><?= (int) $writing['unpublished'] ?> unpublished</a></li><?php endif; ?>
            </ul>
        <?php endif; ?>
        <p><a href="<?= Html::e($newStoryUrl) ?>" class="lf-button lf-button--secondary">Add a story</a> <a href="<?= Html::e($storiesUrl) ?>">All your stories</a></p>
    </section>
<?php endif; ?>

<?php if ($newsCounts !== null): ?>
    <section aria-labelledby="lf-news-heading" class="lf-card">
        <h2 id="lf-news-heading">News</h2>
        <?php if (array_sum($newsCounts) === 0): ?>
            <p>No news has been posted yet.</p>
        <?php else: ?>
            <ul class="lf-writing-counts">
                <li><a href="<?= Html::e($newsUrl . '?state=published') ?>"><?= (int) $newsCounts['published'] ?> published</a></li>
                <li><a href="<?= Html::e($newsUrl . '?state=draft') ?>"><?= (int) $newsCounts['draft'] ?> <?= $newsCounts['draft'] === 1 ? 'draft' : 'drafts' ?></a></li>
            </ul>
        <?php endif; ?>
        <p><a href="<?= Html::e($newNewsUrl) ?>" class="lf-button lf-button--secondary">Add a news post</a> <a href="<?= Html::e($newsUrl) ?>">All news</a></p>
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
