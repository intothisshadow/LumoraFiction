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

use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Admin\SortHeading;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;

/** @var list<Story> $stories */
/** @var ColumnSort $sort */
/** @var array<int, string> $owners display names by account id; empty unless every story is listed */
/** @var array<int, array{total: int, published: int}> $chapterCounts */
/** @var array{scope: string, state: string, search: string, rating: string} $filters */
/** @var list<int> $unrated ids of listed public or waiting stories that have no rating */
/** @var string $scope */
/** @var list<PublicationState> $states */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $listUrl */
/** @var string $newUrl */
/** @var string $importUrl */
/** @var string $storyUrl */
/** @var string $bulkUrl */
/** @var array<string, string> $bulkMore menu text of the bulk actions that change a story's details, by action */
/** @var string $assetVersion */
/** @var string $csrfToken */

$kept = array_filter(['q' => $filters['search'], 'state' => $filters['state'], 'rating' => $filters['rating']], static fn (string $value): bool => $value !== '');
$query = static function (int $number) use ($kept, $sort): string {
    $parts = $kept + $sort->query();

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};

$filtered = $filters['search'] !== '' || $filters['state'] !== '' || $filters['rating'] !== '';
$statusClass = static fn (PublicationState $state): string => match ($state) {
    PublicationState::Published => ' lf-status--ok',
    PublicationState::Pending => ' lf-status--warning',
    default => '',
};
?>
<p>
    <a href="<?= Html::e($newUrl) ?>" class="lf-button">Add a story</a>
    <?php if ($importUrl !== ''): ?>
        <a href="<?= Html::e($importUrl) ?>" class="lf-button lf-button--secondary">Import a story</a>
    <?php endif; ?>
</p>

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
    <div class="lf-field">
        <label for="rating">Rating</label>
        <select id="rating" name="rating">
            <option value="">Any</option>
            <option value="none"<?= $filters['rating'] === 'none' ? ' selected' : '' ?>>No rating</option>
        </select>
    </div>
    <?php foreach ($sort->query() as $name => $value): ?>
        <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
    <?php endforeach; ?>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($stories === []): ?>
    <p class="lf-hint"><?= $filtered ? 'No story matches.' : ($scope === 'all' ? 'There are no stories yet.' : 'You have not written a story yet. Add one to get started.') ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>

    <form method="post" action="<?= Html::e($bulkUrl) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <?php // The list the person is on comes back after the action, with the same filter, sort and page. ?>
        <input type="hidden" name="scope" value="<?= Html::e($scope) ?>">
        <?php foreach ($kept + $sort->query() + ($page > 1 ? ['page' => (string) $page] : []) as $name => $value): ?>
            <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
        <?php endforeach; ?>

        <table class="lf-table lf-table--selectable">
            <caption class="lf-visually-hidden"><?= $scope === 'all' ? 'All stories' : 'Your stories' ?></caption>
            <thead>
                <tr>
                    <th scope="col">
                        <input type="checkbox" data-lf-select-all="story_ids" aria-label="Select all stories on this page" hidden>
                        <span class="lf-visually-hidden" data-lf-select-fallback>Select</span>
                    </th>
                    <?= SortHeading::render($sort, 'title', 'Title', $listUrl, $kept) ?>
                    <?php if ($scope === 'all'): ?><?= SortHeading::render($sort, 'author', 'Author', $listUrl, $kept) ?><?php endif; ?>
                    <?= SortHeading::render($sort, 'status', 'Status', $listUrl, $kept) ?>
                    <?= SortHeading::render($sort, 'chapters', 'Chapters', $listUrl, $kept) ?>
                    <?= SortHeading::render($sort, 'words', 'Words', $listUrl, $kept) ?>
                    <?= SortHeading::render($sort, 'updated', 'Last changed', $listUrl, $kept) ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stories as $story): ?>
                    <?php $counts = $chapterCounts[$story->id] ?? ['total' => 0, 'published' => 0]; ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int) $story->id ?>" id="select_<?= (int) $story->id ?>" data-lf-select-item="story_ids" aria-label="Select <?= Html::e($story->title) ?>"></td>
                        <th scope="row"><a href="<?= Html::e($storyUrl . '/' . $story->id) ?>"><?= Html::e($story->title) ?></a>
                            <?php // Every listed story is one this person may manage, which is the same rule the chapter screens check again. ?>
                            <span class="lf-row-actions">
                                <a href="<?= Html::e($storyUrl . '/' . $story->id . '/chapters') ?>" aria-label="Chapters of <?= Html::e($story->title) ?>">Chapters</a>
                                <a href="<?= Html::e($storyUrl . '/' . $story->id . '/chapters/new') ?>" aria-label="Add a chapter to <?= Html::e($story->title) ?>">Add a chapter</a>
                            </span>
                        </th>
                        <?php if ($scope === 'all'): ?><td><?= Html::e($owners[$story->ownerId] ?? 'Unknown') ?></td><?php endif; ?>
                        <td>
                            <span class="lf-status<?= $statusClass($story->state) ?>"><?= Html::e($story->state->label()) ?></span>
                            <?php if ($story->wasRejected()): ?><span class="lf-status lf-status--error">Not approved</span><?php endif; ?>
                            <?php if (in_array($story->id, $unrated, true)): ?><span class="lf-status lf-status--warning">No rating</span><?php endif; ?>
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
                <option value="trash">Move to the trash</option>
                <option value="delete">Delete for good&hellip;</option>
                <?php if ($bulkMore !== []): ?>
                    <optgroup label="Change their details">
                        <?php foreach ($bulkMore as $value => $text): ?>
                            <option value="<?= Html::e($value) ?>"><?= Html::e($text) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <button type="submit" class="lf-button lf-button--secondary">Apply</button>
            <span class="lf-bulk__count" data-lf-selected-count role="status" aria-live="polite" hidden>None selected</span>
        </div>
    </form>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <?= BulkBar::script($assetVersion) ?>
<?php endif; ?>
