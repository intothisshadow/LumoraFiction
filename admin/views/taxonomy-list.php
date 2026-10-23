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

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;

/** @var TermType $type */
/** @var list<Term> $terms */
/** @var array<int, string> $parents fandom names by id */
/** @var array{search: string, unreviewed: bool} $filters */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $listUrl */
/** @var string $newUrl */
/** @var string $overviewUrl */
/** @var string $termUrl */
/** @var string $csrfToken */

// The short lists are kept in the order the administrator sets; the open lists are alphabetical, so they have no order to change.
$ordered = !$type->writersMayPropose();

$query = static function (int $number) use ($filters): string {
    $parts = [];

    if ($filters['search'] !== '') {
        $parts['q'] = $filters['search'];
    }

    if ($filters['unreviewed']) {
        $parts['unreviewed'] = '1';
    }

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};
?>
<p><a href="<?= Html::e($overviewUrl) ?>">&larr; All lists</a> &middot; <a href="<?= Html::e($newUrl) ?>">Add <?= Html::e(strtolower($type->label())) ?></a></p>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="q">Search <?= Html::e(strtolower($type->pluralLabel())) ?></label>
        <input type="text" id="q" name="q" value="<?= Html::e($filters['search']) ?>" maxlength="100">
    </div>
    <div class="lf-choice">
        <input type="checkbox" id="unreviewed" name="unreviewed" value="1"<?= $filters['unreviewed'] ? ' checked' : '' ?>>
        <label for="unreviewed">Only those not yet reviewed</label>
    </div>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($terms === []): ?>
    <p class="lf-hint"><?= $filters['search'] !== '' || $filters['unreviewed'] ? 'Nothing matches.' : 'Nothing has been added yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>
    <table class="lf-table">
        <caption class="lf-visually-hidden"><?= Html::e($type->pluralLabel()) ?></caption>
        <thead>
            <tr>
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
                    <th scope="row"><a href="<?= Html::e($termUrl . '/' . $term->id) ?>"><?= Html::e($term->name) ?></a><?= $term->hidden ? ' <span class="lf-status">Hidden</span>' : '' ?></th>
                    <?php if ($type->belongsToFandom()): ?><td><?= Html::e($parents[$term->parentId] ?? 'None') ?></td><?php endif; ?>
                    <td><?= (int) $term->storyCount ?></td>
                    <td><?= $term->reviewed ? 'Reviewed' : '<span class="lf-status lf-status--warning">Not yet reviewed</span>' ?></td>
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

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
