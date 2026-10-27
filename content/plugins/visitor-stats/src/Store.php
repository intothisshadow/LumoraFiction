<?php

/**
 * Day-level view totals: writing them and reading them back.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\VisitorStats;

use LumoraFiction\Core\Database\Database;

/**
 * Every row is a count for one day, so no row can be traced to one visitor. The plugin keeps its own tables, created
 * on first use, so core needs no migration for an optional feature.
 */
final class Store
{
    public const BREAKDOWN_REFERRER = 'referrer';

    public const BREAKDOWN_BROWSER = 'browser';

    public const BREAKDOWN_DEVICE = 'device';

    public const BREAKDOWN_COUNTRY = 'country';

    /** Referrer domains are cut to this length so a hostile value cannot grow the table. */
    private const VALUE_LENGTH = 100;

    public function __construct(private readonly Database $database)
    {
    }

    public function ensureTables(): void
    {
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}visitor_stats_days (
                view_date DATE NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (view_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}visitor_stats_story_days (
                story_id INT UNSIGNED NOT NULL,
                view_date DATE NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (story_id, view_date),
                KEY idx_date (view_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}visitor_stats_breakdown (
                view_date DATE NOT NULL,
                kind VARCHAR(10) NOT NULL,
                value VARCHAR(100) NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (view_date, kind, value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /**
     * @param array<string, string> $breakdown kind => value, for the kinds known about this view
     */
    public function recordView(int $storyId, array $breakdown, ?string $today = null): void
    {
        $today ??= date('Y-m-d');

        $this->bump('{prefix}visitor_stats_days', 'view_date', [$today]);
        $this->bump('{prefix}visitor_stats_story_days', 'story_id, view_date', [$storyId, $today]);

        foreach ($breakdown as $kind => $value) {
            $this->bump('{prefix}visitor_stats_breakdown', 'view_date, kind, value', [$today, $kind, mb_substr($value, 0, self::VALUE_LENGTH)]);
        }
    }

    /**
     * @return array{today: int, week: int, month: int, all: int}
     */
    public function totals(): array
    {
        $row = $this->database->fetchOne(
            'SELECT
                COALESCE(SUM(CASE WHEN view_date = :today THEN views END), 0) AS today,
                COALESCE(SUM(CASE WHEN view_date >= :week THEN views END), 0) AS week,
                COALESCE(SUM(CASE WHEN view_date >= :month THEN views END), 0) AS month,
                COALESCE(SUM(views), 0) AS total
             FROM {prefix}visitor_stats_days',
            ['today' => $this->daysAgo(0), 'week' => $this->daysAgo(6), 'month' => $this->daysAgo(29)],
        ) ?? [];

        return [
            'today' => (int) ($row['today'] ?? 0),
            'week' => (int) ($row['week'] ?? 0),
            'month' => (int) ($row['month'] ?? 0),
            'all' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * One entry for each of the last $days days, oldest first, with zero for a day nobody read.
     *
     * @return list<array{date: string, views: int}>
     */
    public function daily(int $days): array
    {
        $byDate = [];

        foreach ($this->database->fetchAll(
            'SELECT view_date, views FROM {prefix}visitor_stats_days WHERE view_date >= :since',
            ['since' => $this->daysAgo($days - 1)],
        ) as $row) {
            $byDate[(string) $row['view_date']] = (int) $row['views'];
        }

        $series = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $this->daysAgo($offset);
            $series[] = ['date' => $date, 'views' => $byDate[$date] ?? 0];
        }

        return $series;
    }

    /**
     * The most read stories over the last $days days.
     *
     * @return list<array{story_id: int, views: int}>
     */
    public function topStories(int $days, int $limit): array
    {
        $rows = $this->database->fetchAll(
            'SELECT story_id, SUM(views) AS views FROM {prefix}visitor_stats_story_days
              WHERE view_date >= :since
              GROUP BY story_id
              ORDER BY views DESC, story_id ASC
              LIMIT ' . max(1, $limit),
            ['since' => $this->daysAgo($days - 1)],
        );

        return array_map(static fn (array $row): array => ['story_id' => (int) $row['story_id'], 'views' => (int) $row['views']], $rows);
    }

    /**
     * Totals for the given stories, for the writer's own screen and list.
     *
     * @param list<int> $storyIds
     * @return array<int, array{week: int, month: int, all: int}>
     */
    public function storyTotals(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($storyIds), '?'));
        $rows = $this->database->fetchAll(
            "SELECT story_id,
                COALESCE(SUM(CASE WHEN view_date >= ? THEN views END), 0) AS week,
                COALESCE(SUM(CASE WHEN view_date >= ? THEN views END), 0) AS month,
                SUM(views) AS total
             FROM {prefix}visitor_stats_story_days WHERE story_id IN ($marks) GROUP BY story_id",
            [$this->daysAgo(6), $this->daysAgo(29), ...$storyIds],
        );

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row['story_id']] = ['week' => (int) $row['week'], 'month' => (int) $row['month'], 'all' => (int) $row['total']];
        }

        return $totals;
    }

    /**
     * @return list<array{value: string, views: int}>
     */
    public function breakdown(string $kind, int $days, int $limit = 10): array
    {
        $rows = $this->database->fetchAll(
            'SELECT value, SUM(views) AS views FROM {prefix}visitor_stats_breakdown
              WHERE kind = :kind AND view_date >= :since
              GROUP BY value
              ORDER BY views DESC, value ASC
              LIMIT ' . max(1, $limit),
            ['kind' => $kind, 'since' => $this->daysAgo($days - 1)],
        );

        return array_map(static fn (array $row): array => ['value' => (string) $row['value'], 'views' => (int) $row['views']], $rows);
    }

    /**
     * Counts one more view for the row, creating it on the first view of the day. Done in one statement so two
     * readers arriving together cannot lose a count.
     *
     * @param list<int|string> $key
     */
    private function bump(string $table, string $columns, array $key): void
    {
        $this->database->execute(
            "INSERT INTO {$table} ({$columns}, views) VALUES (" . implode(', ', array_fill(0, count($key), '?')) . ', 1)
             ON DUPLICATE KEY UPDATE views = views + 1',
            $key,
        );
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime('-' . max(0, $days) . ' days'));
    }
}
