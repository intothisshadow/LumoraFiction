<?php

/**
 * Plain download totals for each story and format.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\DownloadFormat;

/**
 * A running total only: there is no row per download and nothing about who took the file, so there is nothing to
 * clean up or disclose.
 */
final class DownloadCounter
{
    public function __construct(private readonly Database $database)
    {
    }

    public function record(int $storyId, DownloadFormat $format): void
    {
        // One statement, so two downloads at the same moment both count.
        $this->database->execute(
            'INSERT INTO {prefix}story_download_counts (story_id, format, total) VALUES (:story, :format, 1)
             ON DUPLICATE KEY UPDATE total = total + 1',
            ['story' => $storyId, 'format' => $format->value],
        );
    }

    /**
     * @return array<string, int> format name => downloads, for the formats that have any
     */
    public function totals(int $storyId): array
    {
        $totals = [];

        foreach ($this->database->fetchAll('SELECT format, total FROM {prefix}story_download_counts WHERE story_id = :id', ['id' => $storyId]) as $row) {
            $totals[(string) $row['format']] = (int) $row['total'];
        }

        return $totals;
    }

    public function forget(int $storyId): void
    {
        $this->database->execute('DELETE FROM {prefix}story_download_counts WHERE story_id = :id', ['id' => $storyId]);
    }
}
