<?php

/**
 * One classification list: search, filter, and page through its terms.
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
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;

/** @var TermType $type */
/** @var list<Term> $terms */
/** @var array<int, string> $parents fandom names by id */
/** @var bool $curated */
/** @var array{search: string, status: string} $filters status is "approved", "unapproved" or empty for all */
/** @var array{all: int, approved: int, unapproved: int} $statusCounts */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $perPage */
/** @var int $bulkLimit */
/** @var string $bulkUrl */
/** @var string $listUrl */
/** @var string $newUrl */
/** @var string $overviewUrl */
/** @var string $termUrl */
/** @var string $csrfToken */
/** @var string $assetVersion */

// The short lists are kept in the order the administrator sets; the open lists are alphabetical, so they have no order to change.
$ordered = $curated;

$query = static function (int $number, ?string $status = null) use ($filters): string {
    $parts = [];

    if ($filters['search'] !== '') {
        $parts['q'] = $filters['search'];
    }

    $status ??= $filters['status'];

    if ($status !== '') {
        $parts['status'] = $status;
    }

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};

$statusLinks = ['' => 'All', 'approved' => 'Approved', 'unapproved' => 'Unapproved'];
$statusKey = static fn (string $status): string => $status === '' ? 'all' : $status;
$filtered = $filters['search'] !== '' || $filters['status'] !== '';
$bulkForm = 'lf-bulk-form';
?>
<p><a href="<?= Html::e($overviewUrl) ?>">&larr; All lists</a> &middot; <a href="<?= Html::e($newUrl) ?>">Add <?= Html::e($labels->lower($type)) ?></a></p>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="q">Search <?= Html::e($labels->lowerPlural($type)) ?></label>
        <input type="text" id="q" name="q" value="<?= Html::e($filters['search']) ?>" maxlength="100">
    </div>
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= Html::e($filters['status']) ?>"><?php endif; ?>
    <p><button type="submit" class="lf-button lf-button--secondary">Search</button></p>
</form>

<nav class="lf-status-filter" aria-label="Filter by approval status">
    <ul>
        <?php foreach ($statusLinks as $value => $label): ?>
            <li>
                <a href="<?= Html::e($listUrl . $query(1, $value)) ?>" class="lf-status-filter__link<?= $filters['status'] === $value ? ' lf-status-filter__link--current' : '' ?>"<?= $filters['status'] === $value ? ' aria-current="true"' : '' ?>><?= Html::e($label) ?> <span class="lf-status-filter__count">(<?= (int) $statusCounts[$statusKey($value)] ?>)</span></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<?php if ($terms === []): ?>
    <p class="lf-hint"><?= $filtered ? 'Nothing matches.' : 'Nothing has been added yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>

    <form method="post" action="<?= Html::e($bulkUrl) ?>" id="<?= Html::e($bulkForm) ?>" data-lf-bulk>
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="q" value="<?= Html::e($filters['search']) ?>">
        <input type="hidden" name="status" value="<?= Html::e($filters['status']) ?>">
        <input type="hidden" name="page" value="<?= (int) $page ?>">
    </form>

    <?php
    $bar = static function (string $position) use ($bulkForm, $type, $labels): void { ?>
        <div class="lf-bulk lf-bulk--terms" role="group" aria-label="Actions for the selected <?= Html::e($labels->lowerPlural($type)) ?> (<?= Html::e($position) ?>)">
            <span class="lf-bulk__count" data-lf-selected-count hidden>None selected</span>
            <button type="submit" form="<?= Html::e($bulkForm) ?>" name="bulk_action" value="approve" class="lf-button lf-button--secondary">Approve</button>
            <button type="submit" form="<?= Html::e($bulkForm) ?>" name="bulk_action" value="unapprove" class="lf-button lf-button--secondary">Mark as unapproved</button>
            <button type="submit" form="<?= Html::e($bulkForm) ?>" name="bulk_action" value="merge" class="lf-button lf-button--secondary">Merge&hellip;</button>
            <button type="submit" form="<?= Html::e($bulkForm) ?>" name="bulk_action" value="delete" class="lf-button lf-button--secondary">Delete&hellip;</button>
        </div>
    <?php };
    $bar('top');
    ?>
    <?php if ($total > $perPage && $total <= $bulkLimit): ?>
        <div class="lf-choice lf-bulk__matching">
            <input type="checkbox" id="all_matching" name="all_matching" value="1" form="<?= Html::e($bulkForm) ?>">
            <label for="all_matching">Apply to all <?= (int) $total ?> matching <?= Html::e($labels->lowerPlural($type)) ?>, not only the ones ticked on this page (you will be asked to confirm)</label>
        </div>
    <?php elseif ($total > $bulkLimit): ?>
        <p class="lf-hint"><?= (int) $total ?> terms match, which is more than the <?= (int) $bulkLimit ?> one action can change. Narrow the list with the search or the status filter to act on all of them at once.</p>
    <?php endif; ?>

    <table class="lf-table lf-table--selectable">
        <caption class="lf-visually-hidden"><?= Html::e($labels->plural($type)) ?></caption>
        <thead>
            <tr>
                <th scope="col">
                    <input type="checkbox" id="select_page" data-lf-select-all="term_ids" form="<?= Html::e($bulkForm) ?>" aria-label="Select all <?= Html::e($labels->lowerPlural($type)) ?> on this page" hidden>
                    <span class="lf-visually-hidden" data-lf-select-fallback>Select</span>
                </th>
                <th scope="col">Name</th>
                <?php if ($type->belongsToFandom()): ?><th scope="col">Fandom</th><?php endif; ?>
                <th scope="col">Public stories</th>
                <th scope="col">Status</th>
                <?php if ($ordered): ?><th scope="col">Order</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($terms as $term): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int) $term->id ?>" id="select_term_<?= (int) $term->id ?>" data-lf-select-item="term_ids" form="<?= Html::e($bulkForm) ?>" aria-label="Select <?= Html::e($term->name) ?>"></td>
                    <th scope="row"><a href="<?= Html::e($termUrl . '/' . $term->id) ?>"><?= Html::e($term->name) ?></a><?= $term->hidden ? ' <span class="lf-status">Hidden</span>' : '' ?></th>
                    <?php if ($type->belongsToFandom()): ?><td><?= Html::e($parents[$term->parentId] ?? 'None') ?></td><?php endif; ?>
                    <td><?= (int) $term->storyCount ?></td>
                    <td><?= $term->reviewed ? '<span class="lf-status lf-status--ok">Approved</span>' : '<span class="lf-status lf-status--warning">Unapproved</span>' ?></td>
                    <?php if ($ordered): ?>
                        <td>
                            <?php foreach (['up' => 'Move up', 'down' => 'Move down'] as $direction => $label): ?>
                                <form method="post" action="<?= Html::e($termUrl . '/' . $term->id . '/move') ?>" class="lf-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                                    <input type="hidden" name="direction" value="<?= Html::e($direction) ?>">
                                    <button type="submit" class="lf-button lf-button--quiet"><?= Html::e($label) ?><span class="lf-visually-hidden">: <?= Html::e($term->name) ?></span></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (count($terms) >= 8) { $bar('bottom'); } ?>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <script src="<?= Html::e(AdminUrl::asset('js/bulk-select.js', $assetVersion)) ?>" defer></script>
<?php endif; ?>
