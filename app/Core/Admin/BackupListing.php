<?php

/**
 * The backups as the admin screens list them, with the addresses of each action.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Services\BackupService;

/**
 * One place builds a backup's row, so the Backups screen and the shortened list on the Updates screen can never show
 * different things or point an action at different addresses.
 */
final class BackupListing
{
    public const PATH = 'maintenance/backups';

    public function __construct(private readonly BackupService $backups)
    {
    }

    /**
     * @param int $limit how many of the newest to return; 0 for all of them
     * @return list<array<string, mixed>>
     */
    public function rows(int $limit = 0): array
    {
        $records = $this->backups->all();
        $rows = [];

        foreach ($limit > 0 ? array_slice($records, 0, $limit) : $records as $record) {
            $id = (string) $record['id'];
            $rows[] = $record + [
                'complete' => ($record['status'] ?? '') === 'complete',
                'files_url' => AdminUrl::to(self::PATH . '/' . $id . '/download/files'),
                'database_url' => AdminUrl::to(self::PATH . '/' . $id . '/download/database'),
                'restore_url' => AdminUrl::to(self::PATH . '/' . $id . '/restore'),
                'delete_url' => AdminUrl::to(self::PATH . '/' . $id . '/delete'),
            ];
        }

        return $rows;
    }

    /** How a backup's size reads: kilobytes below a megabyte. */
    public static function size(mixed $bytes): string
    {
        $bytes = (int) $bytes;

        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, (int) ceil($bytes / 1024))) . ' KB';
    }
}
