<?php

/**
 * Admin view: the list of static pages, with search, a status filter, sortable columns, paging and starter drafts.
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

use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Admin\SortHeading;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Page;
use LumoraFiction\Models\PublicationState;

/** @var list<Page> $pages */
/** @var array<int, Page> $parents the parents of the pages in the list, by id */
/** @var ColumnSort $sort */
/** @var array{state: string, search: string} $filters */
/** @var list<PublicationState> $states */
/** @var int $total */
/** @var int $page */
/** @var int $pageCount */
/** @var bool $hasAny */
/** @var array<string, array{title: string, slug: string, text: string}> $starters */
/** @var string $starterToken */
/** @var string $starterUrl */
/** @var string $listUrl */
/** @var string $newUrl */
/** @var string $pageUrl */
/** @var string $bulkUrl */
/** @var string $assetVersion */

$bulkForm = 'lf-bulk-form';

$kept = array_filter(['q' => $filters['search'], 'state' => $filters['state']], static fn (string $value): bool => $value !== '');
$query = static function (int $number) use ($kept, $sort): string {
    $parts = $kept + $sort->query();

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};

$filtered = $filters['search'] !== '' || $filters['state'] !== '';
?>
<p><a href="<?= Html::e($newUrl) ?>" class="lf-button">Add a page</a></p>

<?php if ($starters !== []): ?>
    <section class="lf-card" aria-labelledby="lf-starters-heading">
        <h2 id="lf-starters-heading">Start from a draft</h2>
        <p class="lf-hint">Each button adds a draft with placeholder text for you to replace. Nothing is published until you publish it. A button disappears once a page uses that address.</p>
        <?php foreach ($starters as $key => $starter): ?>
            <form method="post" action="<?= Html::e($starterUrl) ?>" class="lf-inline-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($starterToken) ?>">
                <input type="hidden" name="starter" value="<?= Html::e((string) $key) ?>">
                <button type="submit" class="lf-button lf-button--secondary">Add &ldquo;<?= Html::e($starter['title']) ?>&rdquo;</button>
            </form>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

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
    <?php foreach ($sort->query() as $name => $value): ?>
        <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
    <?php endforeach; ?>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($pages === []): ?>
    <p class="lf-hint"><?= $filtered ? 'No page matches.' : 'There are no pages yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>

    <?= BulkBar::form($bulkForm, $bulkUrl, $starterToken, $kept + $sort->query() + ['page' => $page > 1 ? (string) $page : '']) ?>
    <?= BulkBar::bar($bulkForm, 'pages', ['publish' => 'Publish', 'unpublish' => 'Unpublish', 'menu' => 'Show in the menu', 'footer' => 'Show in the footer', 'remove' => 'Remove from menu and footer', 'delete' => 'Delete…']) ?>

    <table class="lf-table lf-table--selectable">
        <caption class="lf-visually-hidden">Pages</caption>
        <thead>
            <tr>
                <?= BulkBar::selectAllHeader('page_ids', $bulkForm, 'pages') ?>
                <?= SortHeading::render($sort, 'title', 'Title', $listUrl, $kept) ?>
                <th scope="col">Address</th>
                <?= SortHeading::render($sort, 'status', 'Status', $listUrl, $kept) ?>
                <th scope="col">Shown in</th>
                <?= SortHeading::render($sort, 'order', 'Order', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'updated', 'Last changed', $listUrl, $kept) ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pages as $row): ?>
                <?php
                $parent = $row->parentId === null ? null : ($parents[$row->parentId] ?? null);
                // A page under a parent that is a draft or not in the menu is listed on its own, so say so instead of ignoring the tick.
                $standsAlone = $parent !== null && $row->inMenu && (!$parent->isPublished() || !$parent->inMenu);
                ?>
                <tr>
                    <?= BulkBar::checkbox('page_ids', $bulkForm, $row->id, 'Select ' . $row->title) ?>
                    <th scope="row"<?= $row->isChild() ? ' class="lf-page-row--child"' : '' ?>><a href="<?= Html::e($pageUrl . '/' . $row->id) ?>"><?= Html::e($row->title) ?></a>
                        <?php if ($parent !== null): ?><span class="lf-hint lf-page-row__parent">Under <?= Html::e($parent->title) ?></span><?php endif; ?>
                        <?php if ($standsAlone): ?><span class="lf-hint lf-page-row__note">Listed on its own in the menu, because <?= Html::e($parent->title) ?> <?= !$parent->isPublished() ? 'is a draft' : 'is not shown in the main menu' ?>.</span><?php endif; ?>
                    </th>
                    <td><code>/<?= Html::e($row->slug) ?></code></td>
                    <td><span class="lf-status<?= $row->isPublished() ? ' lf-status--ok' : '' ?>"><?= Html::e($row->state->label()) ?></span></td>
                    <td><?= Html::e(implode(', ', array_filter([$row->inMenu ? 'Menu' : '', $row->inFooter ? 'Footer' : ''])) ?: 'Neither') ?></td>
                    <td><?= (int) $row->sortOrder ?></td>
                    <td><time datetime="<?= Html::e($row->updatedAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($row->updatedAt->format('Y-m-d H:i')) ?></time></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pageCount > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pageCount ?></span>
            <?php if ($page < $pageCount): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <?= BulkBar::script($assetVersion) ?>
<?php endif; ?>
