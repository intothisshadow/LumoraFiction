<?php

/**
 * The Comments screen: tabs with counts, filters, and a table of comments with single and bulk actions.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Comment;
use LumoraFiction\Models\CommentStatus;

/** @var list<array{comment: Comment, story_title: string, story_slug: string, owner_id: int, chapter_number: int, chapter_title: string, commenter: string, commenter_status: string, published: bool, parent_commenter: string}> $rows */
/** @var array<int, list<array{name: string, reason: string, at: string}>> $reports */
/** @var array{status: string, story: int, chapter: int, user: int, from: string, to: string, q: string, kind: string} $filters */
/** @var array<string, string> $tabs */
/** @var array{all: int, published: int, pending: int, hidden: int, spam: int, reported: int} $counts */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $perPage */
/** @var int $bulkLimit */
/** @var bool $isModerator */
/** @var array{id: int, total: int, held: int, spam: int, moderation: string}|null $commenter */
/** @var string $listUrl */
/** @var string $bulkUrl */
/** @var string $accountUrl */
/** @var Closure(array<string, mixed>): string $chapterUrl */
/** @var string $csrfToken */
/** @var string $assetVersion */

$query = static function (array $change = [], int $number = 1) use ($filters): string {
    $parts = array_filter($change + $filters + ['page' => $number > 1 ? $number : ''], static fn (mixed $value): bool => $value !== '' && $value !== 0);

    return $parts === [] ? '' : '?' . http_build_query($parts);
};
$statusClass = static fn (CommentStatus $status): string => match ($status) {
    CommentStatus::Published => ' lf-status--ok',
    CommentStatus::Pending => ' lf-status--warning',
    default => '',
};
$bulkForm = 'lf-bulk-form';
$filtered = $filters['status'] !== '' || $filters['story'] > 0 || $filters['chapter'] > 0 || $filters['user'] > 0 || $filters['from'] !== '' || $filters['to'] !== '' || $filters['q'] !== '' || $filters['kind'] !== '';
$active = array_filter(['story' => 'Story ' . $filters['story'], 'chapter' => 'Chapter ' . $filters['chapter'], 'user' => 'Commenter ' . $filters['user']], static fn (string $label, string $key): bool => $filters[$key] > 0, ARRAY_FILTER_USE_BOTH);
?>
<p class="lf-lead"><?= $isModerator ? 'Every comment on the site, whatever its state.' : 'The comments under your own stories.' ?> Pending and reported comments are the ones waiting for a decision.</p>

<nav class="lf-status-filter" aria-label="Filter by state">
    <ul>
        <?php foreach ($tabs as $value => $label): ?>
            <?php $key = $value === '' ? 'all' : $value; ?>
            <li>
                <a href="<?= Html::e($listUrl . $query(['status' => $value])) ?>" class="lf-status-filter__link<?= $filters['status'] === $value ? ' lf-status-filter__link--current' : '' ?>"<?= $filters['status'] === $value ? ' aria-current="true"' : '' ?>><?= Html::e($label) ?> <span class="lf-status-filter__count">(<?= (int) $counts[$key] ?>)</span></a>
            </li>
        <?php endforeach; ?>
        <li>
            <a href="<?= Html::e($listUrl . $query(['kind' => $filters['kind'] === 'replies' ? '' : 'replies'])) ?>" class="lf-status-filter__link<?= $filters['kind'] === 'replies' ? ' lf-status-filter__link--current' : '' ?>"<?= $filters['kind'] === 'replies' ? ' aria-current="true"' : '' ?>>Replies only</a>
        </li>
    </ul>
</nav>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="q">Search the text</label>
        <input type="text" id="q" name="q" value="<?= Html::e($filters['q']) ?>" maxlength="100">
    </div>
    <div class="lf-field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= Html::e($filters['from']) ?>">
    </div>
    <div class="lf-field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= Html::e($filters['to']) ?>">
    </div>
    <?php foreach (['status' => $filters['status'], 'story' => $filters['story'], 'chapter' => $filters['chapter'], 'user' => $filters['user'], 'kind' => $filters['kind']] as $name => $value): ?>
        <?php if ($value !== '' && $value !== 0): ?><input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $value) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($active !== []): ?>
    <p class="lf-hint">Showing only:
        <?php foreach ($active as $key => $label): ?>
            <?= Html::e($label) ?> (<a href="<?= Html::e($listUrl . $query([$key => 0])) ?>">show all</a>)<?= $key === array_key_last($active) ? '' : ', ' ?>
        <?php endforeach; ?>
    </p>
<?php endif; ?>

<?php if ($commenter !== null): ?>
    <section class="lf-card" aria-labelledby="lf-commenter-heading">
        <h2 id="lf-commenter-heading">This commenter</h2>
        <p><?= (int) $commenter['total'] ?> comments in all, <?= (int) $commenter['held'] ?> held for approval, <?= (int) $commenter['spam'] ?> marked as spam.
            <?php if ($commenter['moderation'] === 'hold'): ?>Their future comments wait for approval.<?php elseif ($commenter['moderation'] === 'block'): ?>They are blocked from commenting.<?php endif; ?></p>
        <form method="post" action="<?= Html::e($accountUrl) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <input type="hidden" name="user_id" value="<?= (int) $commenter['id'] ?>">
            <p>
                <?php if ($commenter['moderation'] !== 'hold'): ?><button type="submit" name="mode" value="hold" class="lf-button lf-button--secondary">Hold their future comments</button><?php endif; ?>
                <?php if ($commenter['moderation'] !== 'block'): ?><button type="submit" name="mode" value="block" class="lf-button lf-button--secondary">Block them from commenting</button><?php endif; ?>
                <?php if ($commenter['moderation'] !== ''): ?><button type="submit" name="mode" value="clear" class="lf-button lf-button--secondary">Clear the hold or block</button><?php endif; ?>
            </p>
        </form>
    </section>
<?php endif; ?>

<?php if ($rows === []): ?>
    <p class="lf-hint"><?= $filtered ? 'No comment matches.' : 'There are no comments yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>

    <form method="post" action="<?= Html::e($bulkUrl) ?>" id="<?= Html::e($bulkForm) ?>">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="page" value="<?= (int) $page ?>">
        <?php foreach ($filters as $name => $value): ?>
            <?php if ($value !== '' && $value !== 0): ?><input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e((string) $value) ?>"><?php endif; ?>
        <?php endforeach; ?>
    </form>

    <?php
    $bar = static function (string $position) use ($bulkForm): void { ?>
        <div class="lf-bulk lf-bulk--terms" role="group" aria-label="Actions for the selected comments (<?= Html::e($position) ?>)">
            <span class="lf-bulk__count" data-lf-selected-count hidden>None selected</span>
            <?php foreach (['approve' => 'Approve', 'hide' => 'Hide', 'spam' => 'Mark as spam', 'restore' => 'Restore', 'delete' => 'Delete…'] as $value => $label): ?>
                <button type="submit" form="<?= Html::e($bulkForm) ?>" name="bulk_action" value="<?= Html::e($value) ?>" class="lf-button lf-button--secondary"><?= Html::e($label) ?></button>
            <?php endforeach; ?>
        </div>
    <?php };
    $bar('top');
    ?>
    <?php if ($total > $perPage && $total <= $bulkLimit): ?>
        <div class="lf-choice lf-bulk__matching">
            <input type="checkbox" id="all_matching" name="all_matching" value="1" form="<?= Html::e($bulkForm) ?>">
            <label for="all_matching">Apply to all <?= (int) $total ?> matching comments, not only the ones ticked on this page (you will be asked to confirm)</label>
        </div>
    <?php elseif ($total > $bulkLimit): ?>
        <p class="lf-hint"><?= (int) $total ?> comments match, which is more than the <?= (int) $bulkLimit ?> one action can change. Narrow the list with the tabs and filters to act on all of them at once.</p>
    <?php endif; ?>

    <table class="lf-table lf-table--selectable lf-table--comments">
        <caption class="lf-visually-hidden">Comments</caption>
        <thead>
            <tr>
                <th scope="col">
                    <input type="checkbox" id="select_page" data-lf-select-all="comment_ids" form="<?= Html::e($bulkForm) ?>" aria-label="Select all comments on this page" hidden>
                    <span class="lf-visually-hidden" data-lf-select-fallback>Select</span>
                </th>
                <th scope="col">Comment</th>
                <th scope="col">Commenter</th>
                <th scope="col">Where</th>
                <th scope="col">Date</th>
                <th scope="col">State</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $comment = $row['comment']; $short = mb_strimwidth($comment->body, 0, 140, '…', 'UTF-8'); ?>
                <tr id="comment-<?= (int) $comment->id ?>">
                    <td><input type="checkbox" name="ids[]" value="<?= (int) $comment->id ?>" data-lf-select-item="comment_ids" form="<?= Html::e($bulkForm) ?>" aria-label="Select the comment by <?= Html::e($row['commenter']) ?> on <?= Html::e($row['story_title']) ?>"></td>
                    <td class="lf-comment-cell">
                        <?php if ($comment->isReply()): ?>
                            <p class="lf-comment-cell__reply-to"><span class="lf-badge">Reply</span> to <?= Html::e($row['parent_commenter'] === '' ? 'a comment' : $row['parent_commenter']) ?><?= $row['published'] && $row['chapter_number'] > 0 && $comment->parentId !== null ? ' &middot; <a href="' . Html::e(PublicUrl::chapter($row['story_slug'], $row['chapter_number']) . '#comment-' . $comment->parentId) . '">the comment it answers</a>' : '' ?></p>
                        <?php endif; ?>
                        <?php if ($short !== $comment->body): ?>
                            <details><summary><?= Html::e($short) ?></summary><p class="lf-comment-cell__full"><?= nl2br(Html::e($comment->body), false) ?></p></details>
                        <?php else: ?>
                            <p class="lf-comment-cell__full"><?= nl2br(Html::e($comment->body), false) ?></p>
                        <?php endif; ?>
                        <?php if ($comment->reportCount > 0): ?>
                            <details class="lf-comment-cell__reports">
                                <summary><?= (int) $comment->reportCount ?> <?= $comment->reportCount === 1 ? 'report' : 'reports' ?></summary>
                                <ul>
                                    <?php foreach ($reports[$comment->id] ?? [] as $report): ?>
                                        <li><?= Html::e($report['name'] === '' ? 'Someone' : $report['name']) ?>: <?= Html::e($report['reason'] === '' ? '(no reason given)' : $report['reason']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                        <p class="lf-comment-cell__links">
                            <?php if ($row['published'] && $row['chapter_number'] > 0): ?><a href="<?= Html::e($chapterUrl($row)) ?>">View in context</a> &middot; <?php endif; ?>
                            <a href="<?= Html::e($listUrl . $query(['chapter' => $comment->chapterId])) ?>">This chapter</a>
                            <?php if ($isModerator): ?> &middot; <a href="<?= Html::e($listUrl . $query(['user' => $comment->userId])) ?>">This commenter</a><?php endif; ?>
                        </p>
                    </td>
                    <td><?= Html::e($row['commenter'] === '' ? 'Unknown' : $row['commenter']) ?><?= $comment->userId === $row['owner_id'] ? ' <span class="lf-status lf-status--ok">Author</span>' : '' ?></td>
                    <td>
                        <a href="<?= Html::e($listUrl . $query(['story' => $comment->storyId])) ?>"><?= Html::e($row['story_title']) ?></a><br>
                        <?= $row['chapter_number'] > 0 ? 'Chapter ' . (int) $row['chapter_number'] : 'Chapter removed' ?>
                    </td>
                    <td><time datetime="<?= Html::e($comment->createdAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($comment->createdAt->format('Y-m-d H:i')) ?></time><?= $comment->wasEdited() ? '<br>edited' : '' ?></td>
                    <td><span class="lf-status<?= $statusClass($comment->status) ?>"><?= Html::e($comment->status->label()) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (count($rows) >= 8) { $bar('bottom'); } ?>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query([], $page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query([], $page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <script src="<?= Html::e(AdminUrl::asset('js/bulk-select.js', $assetVersion)) ?>" defer></script>
<?php endif; ?>
