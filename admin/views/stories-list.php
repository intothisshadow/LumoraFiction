<?php

/**
 * A writer's list of stories, or every story for staff, with search, a status filter and bulk actions.
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

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;

/** @var list<Story> $stories */
/** @var array<int, string> $owners display names by account id; empty unless every story is listed */
/** @var array<int, array{total: int, published: int}> $chapterCounts */
/** @var array{scope: string, state: string, search: string} $filters */
/** @var string $scope */
/** @var list<PublicationState> $states */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $listUrl */
/** @var string $newUrl */
/** @var string $storyUrl */
/** @var string $bulkUrl */
/** @var string $csrfToken */

$query = static function (int $number) use ($filters): string {
    $parts = [];

    if ($filters['search'] !== '') {
        $parts['q'] = $filters['search'];
    }

    if ($filters['state'] !== '') {
        $parts['state'] = $filters['state'];
    }

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};

$filtered = $filters['search'] !== '' || $filters['state'] !== '';
$statusClass = static fn (PublicationState $state): string => match ($state) {
    PublicationState::Published => ' lf-status--ok',
    PublicationState::Pending => ' lf-status--warning',
    default => '',
};
?>
<p><a href="<?= Html::e($newUrl) ?>" class="lf-button">Add a story</a></p>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="q">Search titles</label>
        <input type="text" id="q" name="q" value="<?= Html::e($filters['search']) ?>" maxlength="100">
    </div>
    <div class="lf-field">
        <label for="state">Status</label>
        <select id="state" name="state">
            <option value="">Any status</option>
            <?php foreach ($states as $state): ?>
                <option value="<?= Html::e($state->value) ?>"<?= $filters['state'] === $state->value ? ' selected' : '' ?>><?= Html::e($state->label()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($stories === []): ?>
    <p class="lf-hint"><?= $filtered ? 'No story matches.' : ($scope === 'all' ? 'There are no stories yet.' : 'You have not written a story yet. Add one to get started.') ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>

    <form method="post" action="<?= Html::e($bulkUrl) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

        <table class="lf-table lf-table--selectable">
            <caption class="lf-visually-hidden"><?= $scope === 'all' ? 'All stories' : 'Your stories' ?></caption>
            <thead>
                <tr>
                    <th scope="col"><span class="lf-visually-hidden">Select</span></th>
                    <th scope="col">Title</th>
                    <?php if ($scope === 'all'): ?><th scope="col">Author</th><?php endif; ?>
                    <th scope="col">Status</th>
                    <th scope="col">Chapters</th>
                    <th scope="col">Words</th>
                    <th scope="col">Last changed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stories as $story): ?>
                    <?php $counts = $chapterCounts[$story->id] ?? ['total' => 0, 'published' => 0]; ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int) $story->id ?>" id="select_<?= (int) $story->id ?>" aria-label="Select <?= Html::e($story->title) ?>"></td>
                        <th scope="row"><a href="<?= Html::e($storyUrl . '/' . $story->id) ?>"><?= Html::e($story->title) ?></a></th>
                        <?php if ($scope === 'all'): ?><td><?= Html::e($owners[$story->ownerId] ?? 'Unknown') ?></td><?php endif; ?>
                        <td>
                            <span class="lf-status<?= $statusClass($story->state) ?>"><?= Html::e($story->state->label()) ?></span>
                            <?php if ($story->wasRejected()): ?><span class="lf-status lf-status--error">Not approved</span><?php endif; ?>
                        </td>
                        <td><?= (int) $counts['published'] ?> of <?= (int) $counts['total'] ?> published</td>
                        <td><?= number_format($story->wordCount) ?></td>
                        <td><time datetime="<?= Html::e($story->updatedAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($story->updatedAt->format('Y-m-d H:i')) ?></time></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="lf-bulk">
            <label for="bulk_action">With the selected stories</label>
            <select id="bulk_action" name="bulk_action">
                <option value="">Choose&hellip;</option>
                <option value="publish">Publish (or send for approval)</option>
                <option value="unpublish">Unpublish</option>
                <option value="delete">Delete&hellip;</option>
            </select>
            <button type="submit" class="lf-button lf-button--secondary">Apply</button>
        </div>
    </form>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
