<?php

/**
 * The importer's own tables: which eFiction item became which local one, and the runs with their reports.
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

namespace LumoraFiction\Plugins\EfictionImporter;

use LumoraFiction\Core\Database\Database;

/**
 * The plugin keeps its own tables, so core needs no migration for it and removing the data is dropping two tables.
 * An item is "made" when the import created it and not made when it matched something that was already here: only
 * made items are ever removed again.
 */
final class Tracker
{
    public const USER = 'user';

    public const STORY = 'story';

    public const CHAPTER = 'chapter';

    public const TERM = 'term';

    public const COMMENT = 'comment';

    public const BOOKMARK = 'bookmark';

    public const NEWS = 'news';

    public const PAGE = 'page';

    public const SERIES = 'series';

    public function __construct(private readonly Database $database)
    {
    }

    public function ensureTables(): void
    {
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}efiction_items (
                kind VARCHAR(12) NOT NULL,
                source_id VARCHAR(40) NOT NULL,
                local_id INT UNSIGNED NOT NULL,
                made TINYINT UNSIGNED NOT NULL DEFAULT 1,
                complete TINYINT UNSIGNED NOT NULL DEFAULT 1,
                run_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (kind, source_id),
                KEY idx_local (kind, local_id),
                KEY idx_run (run_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}efiction_runs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                state VARCHAR(12) NOT NULL,
                report MEDIUMTEXT NOT NULL,
                job MEDIUMTEXT NULL,
                locked_until DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function exists(): bool
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
            ['name' => $this->database->prefix() . 'efiction_items'],
        ) > 0;
    }

    public function record(string $kind, string $sourceId, int $localId, int $runId, bool $made = true, bool $complete = true): void
    {
        $this->database->execute(
            'INSERT INTO {prefix}efiction_items (kind, source_id, local_id, made, complete, run_id, created_at) VALUES (:kind, :source, :local, :made, :complete, :run, :now)
             ON DUPLICATE KEY UPDATE local_id = VALUES(local_id), made = VALUES(made), complete = VALUES(complete), run_id = VALUES(run_id)',
            ['kind' => $kind, 'source' => $sourceId, 'local' => $localId, 'made' => $made ? 1 : 0, 'complete' => $complete ? 1 : 0, 'run' => $runId, 'now' => date('Y-m-d H:i:s')],
        );
    }

    public function complete(string $kind, string $sourceId): void
    {
        $this->database->execute('UPDATE {prefix}efiction_items SET complete = 1 WHERE kind = :kind AND source_id = :source', ['kind' => $kind, 'source' => $sourceId]);
    }

    /**
     * @return array{local_id: int, made: bool, complete: bool}|null
     */
    public function find(string $kind, string $sourceId): ?array
    {
        $row = $this->database->fetchOne('SELECT local_id, made, complete FROM {prefix}efiction_items WHERE kind = :kind AND source_id = :source', ['kind' => $kind, 'source' => $sourceId]);

        return $row === null ? null : ['local_id' => (int) $row['local_id'], 'made' => (int) $row['made'] === 1, 'complete' => (int) $row['complete'] === 1];
    }

    public function localId(string $kind, string $sourceId): ?int
    {
        return $this->find($kind, $sourceId)['local_id'] ?? null;
    }

    /**
     * The eFiction id behind a local one, for the redirects.
     */
    public function sourceOf(string $kind, int $localId): ?string
    {
        $value = $this->database->fetchColumn('SELECT source_id FROM {prefix}efiction_items WHERE kind = :kind AND local_id = :local', ['kind' => $kind, 'local' => $localId]);

        return $value === false || $value === null ? null : (string) $value;
    }

    public function forget(string $kind, string $sourceId): void
    {
        $this->database->execute('DELETE FROM {prefix}efiction_items WHERE kind = :kind AND source_id = :source', ['kind' => $kind, 'source' => $sourceId]);
    }

    /**
     * @return list<array{source_id: string, local_id: int}> the items of one kind this import made
     */
    public function made(string $kind): array
    {
        return array_map(
            static fn (array $row): array => ['source_id' => (string) $row['source_id'], 'local_id' => (int) $row['local_id']],
            $this->database->fetchAll('SELECT source_id, local_id FROM {prefix}efiction_items WHERE kind = :kind AND made = 1 ORDER BY local_id DESC', ['kind' => $kind]),
        );
    }

    /**
     * @return array<string, int> kind => how many items the import made and still has
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->database->fetchAll('SELECT kind, COUNT(*) AS total FROM {prefix}efiction_items WHERE made = 1 GROUP BY kind') as $row) {
            $counts[(string) $row['kind']] = (int) $row['total'];
        }

        return $counts;
    }

    public function forgetAll(): void
    {
        $this->database->execute('DELETE FROM {prefix}efiction_items');
    }

    // ---- runs ------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $report
     */
    public function startRun(array $job, array $report): int
    {
        return (int) $this->database->insertGetId(
            'INSERT INTO {prefix}efiction_runs (state, report, job, created_at) VALUES (\'running\', :report, :job, :now)',
            ['report' => self::encode($report), 'job' => self::encode($job), 'now' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * @return array{id: int, state: string, report: array<string, mixed>, job: array<string, mixed>, created_at: string}|null
     */
    public function run(int $id): ?array
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}efiction_runs WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::shape($row);
    }

    /**
     * @return array{id: int, state: string, report: array<string, mixed>, job: array<string, mixed>, created_at: string}|null
     */
    public function latestRun(): ?array
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}efiction_runs ORDER BY id DESC LIMIT 1');

        return $row === null ? null : self::shape($row);
    }

    /**
     * Takes the right to work on the run for a short while, so two requests never write the same run at once.
     */
    public function lock(int $id, int $seconds): bool
    {
        return $this->database->execute(
            'UPDATE {prefix}efiction_runs SET locked_until = :until WHERE id = :id AND state = \'running\' AND (locked_until IS NULL OR locked_until < :now)',
            ['until' => date('Y-m-d H:i:s', time() + $seconds), 'now' => date('Y-m-d H:i:s'), 'id' => $id],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $report
     */
    public function save(int $id, string $state, array $job, array $report): void
    {
        $this->database->execute(
            'UPDATE {prefix}efiction_runs SET state = :state, job = :job, report = :report, locked_until = NULL WHERE id = :id',
            ['state' => $state, 'job' => self::encode($job), 'report' => self::encode($report), 'id' => $id],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, state: string, report: array<string, mixed>, job: array<string, mixed>, created_at: string}
     */
    private static function shape(array $row): array
    {
        $report = json_decode((string) $row['report'], true);
        $job = json_decode((string) ($row['job'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'state' => (string) $row['state'],
            'report' => is_array($report) ? $report : [],
            'job' => is_array($job) ? $job : [],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
