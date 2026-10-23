<?php

/**
 * Admin view: a person's notifications about stories.
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
use LumoraFiction\Models\Notification;

/** @var list<Notification> $notifications */
/** @var bool $unreadOnly */
/** @var int $unread */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $csrfToken */
/** @var string $listUrl */
/** @var string $readUrl */
/** @var string $readAllUrl */

$query = static function (int $number) use ($unreadOnly): string {
    $parts = $unreadOnly ? ['show' => 'unread'] : [];

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};
?>
<p class="lf-lead">What needs you about stories: work waiting for review, and what became of your own. Each message links to the page where you deal with it.</p>

<p>
    <?php if ($unreadOnly): ?>
        Showing only unread. <a href="<?= Html::e($listUrl) ?>">Show everything</a>
    <?php else: ?>
        <?= (int) $unread ?> unread. <a href="<?= Html::e($listUrl . '?show=unread') ?>">Show only unread</a>
    <?php endif; ?>
</p>

<?php if ($notifications === []): ?>
    <p class="lf-hint"><?= $unreadOnly ? 'Nothing unread.' : 'Nothing yet. Messages about your stories and reviews appear here.' ?></p>
<?php else: ?>
    <ul class="lf-notification-list">
        <?php foreach ($notifications as $notification): ?>
            <li class="lf-notification<?= $notification->isRead() ? '' : ' lf-notification--unread' ?>">
                <p class="lf-notification__message">
                    <?= $notification->isRead() ? '' : '<span class="lf-status lf-status--warning">New</span> ' ?>
                    <?= Html::e($notification->message) ?>
                </p>
                <p class="lf-notification__meta">
                    <time datetime="<?= Html::e($notification->createdAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($notification->createdAt->format('Y-m-d H:i')) ?></time>
                    <?php if ($notification->path !== ''): ?>
                        &middot; <a href="<?= Html::e(AdminUrl::to($notification->path)) ?>">Open<span class="lf-visually-hidden">: <?= Html::e($notification->message) ?></span></a>
                    <?php endif; ?>
                </p>
                <?php if (!$notification->isRead()): ?>
                    <form method="post" action="<?= Html::e($readUrl) ?>" class="lf-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= (int) $notification->id ?>">
                        <button type="submit" class="lf-button lf-button--quiet">Mark as read<span class="lf-visually-hidden">: <?= Html::e($notification->message) ?></span></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($unread > 0): ?>
        <form method="post" action="<?= Html::e($readAllUrl) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <p><button type="submit" class="lf-button lf-button--secondary">Mark everything as read</button></p>
        </form>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
