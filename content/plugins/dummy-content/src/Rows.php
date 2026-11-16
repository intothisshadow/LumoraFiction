<?php

/**
 * Multi-row inserts shared by the Dummy Content makers.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

use LumoraFiction\Core\Database\Database;

final class Rows
{
    public function __construct(private readonly Database $database)
    {
    }

    public function maxId(string $table): int
    {
        return (int) $this->database->fetchColumn('SELECT COALESCE(MAX(id), 0) FROM {prefix}' . $table);
    }

    /**
     * Table and column names come from the plugin's own files, never from a request; the values are bound.
     *
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     * @return int rows written
     */
    public function insert(string $table, array $columns, array $rows, ?int $perStatement = null, bool $ignore = false): int
    {
        if ($rows === []) {
            return 0;
        }

        $perStatement ??= max(1, intdiv(30000, count($columns)));
        $written = 0;
        $group = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            $written += $this->database->execute(
                'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO {prefix}' . $table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $group)),
                array_merge(...$chunk),
            );
        }

        return $written;
    }
}
