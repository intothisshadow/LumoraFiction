<?php

/**
 * The markup for a sortable table heading.
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

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Helpers\Html;

final class SortHeading
{
    /**
     * A column header whose label is a link. Only the column in use carries aria-sort; the arrow is drawn by the
     * stylesheet. The link keeps the filters but drops the page number, so a changed sort starts at page 1.
     *
     * @param array<string, string> $filters the other address parameters to keep, empty ones already left out
     */
    public static function render(ColumnSort $sort, string $key, string $label, string $listUrl, array $filters): string
    {
        $active = $sort->key === $key;
        $query = http_build_query($filters + $sort->linkQuery($key));
        $state = $active ? ($sort->direction === ColumnSort::ASCENDING ? 'ascending' : 'descending') : null;

        return '<th scope="col"' . ($state !== null ? ' aria-sort="' . $state . '"' : '') . '>'
            . '<a href="' . Html::e($listUrl . '?' . $query) . '" class="lf-sort' . ($active ? ' lf-sort--' . ($sort->direction === ColumnSort::ASCENDING ? 'asc' : 'desc') : '') . '">'
            . Html::e($label) . '</a></th>';
    }
}
