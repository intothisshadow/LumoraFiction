<?php

/**
 * A validated sort order for an admin list: which column, which direction, and the ORDER BY text for it.
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

/**
 * Request text is only ever a lookup key into the screen's own column map, so the SQL fragment comes from code and a
 * hostile value falls back to the default order. The id tie-break keeps paging stable when many rows share a value.
 */
final class ColumnSort
{
    public const ASCENDING = 'asc';

    public const DESCENDING = 'desc';

    /**
     * @param array<string, array{sql: string, first: string}> $columns
     */
    private function __construct(
        public readonly string $key,
        public readonly string $direction,
        public readonly bool $isDefault,
        private readonly array $columns,
        private readonly string $tieBreak,
    ) {
    }

    /**
     * @param array<string, array{sql: string, first: string}> $columns sort key => SQL expression and the direction a first click uses
     * @param string $tieBreak the unique column that settles ties, e.g. "s.id"
     */
    public static function resolve(
        array $columns,
        string $defaultKey,
        string $defaultDirection,
        string $tieBreak,
        ?string $sort,
        ?string $direction,
    ): self {
        $known = $sort !== null && isset($columns[$sort]);
        $key = $known ? $sort : $defaultKey;
        $fallback = $known ? $columns[$key]['first'] : $defaultDirection;
        // A direction only means something for a column that was asked for, so an unknown column gets the whole default.
        $chosen = $known && ($direction === self::ASCENDING || $direction === self::DESCENDING) ? $direction : $fallback;

        return new self($key, $chosen, $key === $defaultKey && $chosen === $defaultDirection, $columns, $tieBreak);
    }

    public function orderBy(): string
    {
        $direction = $this->direction === self::ASCENDING ? 'ASC' : 'DESC';

        return $this->columns[$this->key]['sql'] . ' ' . $direction . ', ' . $this->tieBreak . ' ' . $direction;
    }

    /**
     * The address parameters for this order; empty for the default, so ordinary addresses stay clean.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->isDefault ? [] : ['sort' => $this->key, 'dir' => $this->direction];
    }

    /**
     * The parameters for a link that sorts by $key: the opposite direction for the column in use, the column's own
     * first direction for any other.
     *
     * @return array<string, string>
     */
    public function linkQuery(string $key): array
    {
        $direction = $key === $this->key
            ? ($this->direction === self::ASCENDING ? self::DESCENDING : self::ASCENDING)
            : ($this->columns[$key]['first'] ?? self::ASCENDING);

        return ['sort' => $key, 'dir' => $direction];
    }
}
