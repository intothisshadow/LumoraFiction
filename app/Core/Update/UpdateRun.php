<?php

/**
 * The saved state of an update, restore or backup that is being carried out one step at a time.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

/**
 * Written to a file after every step and read back at the start of the next, possibly by a newer copy of this class
 * than the one that wrote it: an update replaces the code in the middle of its own run. The saved shape and the step
 * names must therefore stay readable across releases, and fromArray() ignores what it does not know.
 */
final class UpdateRun
{
    public const KIND_UPDATE = 'update';

    public const KIND_RESTORE = 'restore';

    public const KIND_BACKUP = 'backup';

    public const RUNNING = 'running';

    public const ROLLING_BACK = 'rolling_back';

    /** A step failed and nothing else will happen until the administrator resumes or cancels. */
    public const ATTENTION = 'attention';

    public const COMPLETE = 'complete';

    public const ROLLED_BACK = 'rolled_back';

    public const CANCELLED = 'cancelled';

    private const LOG_LIMIT = 80;

    /**
     * @param list<string> $steps
     * @param array<string, mixed> $cursor
     * @param list<string> $skipPackages
     * @param list<string> $addedPackages
     * @param list<array{time: int, text: string, ok: bool}> $log
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $source,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public array $steps,
        public readonly int $startedAt,
        public int $position = 0,
        public string $status = self::RUNNING,
        public array $cursor = [],
        public ?string $backupId = null,
        public ?string $restoreId = null,
        public array $skipPackages = [],
        public array $addedPackages = [],
        public bool $destructive = false,
        public string $message = '',
        public int $updatedAt = 0,
        public array $log = [],
    ) {
    }

    public function step(): ?string
    {
        return $this->steps[$this->position] ?? null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETE, self::ROLLED_BACK, self::CANCELLED], true);
    }

    public function note(string $text, bool $ok = true): void
    {
        $this->log[] = ['time' => time(), 'text' => $text, 'ok' => $ok];

        if (count($this->log) > self::LOG_LIMIT) {
            $this->log = array_slice($this->log, -self::LOG_LIMIT);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source' => $this->source,
            'from' => $this->fromVersion,
            'to' => $this->toVersion,
            'steps' => $this->steps,
            'started_at' => $this->startedAt,
            'position' => $this->position,
            'status' => $this->status,
            'cursor' => $this->cursor,
            'backup_id' => $this->backupId,
            'restore_id' => $this->restoreId,
            'skip_packages' => $this->skipPackages,
            'added_packages' => $this->addedPackages,
            'destructive' => $this->destructive,
            'message' => $this->message,
            'updated_at' => $this->updatedAt,
            'log' => $this->log,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $steps = array_values(array_filter((array) ($data['steps'] ?? []), 'is_string'));

        if (!is_string($data['id'] ?? null) || !is_string($data['kind'] ?? null) || $steps === []) {
            return null;
        }

        return new self(
            $data['id'],
            $data['kind'],
            (string) ($data['source'] ?? ''),
            (string) ($data['from'] ?? ''),
            (string) ($data['to'] ?? ''),
            $steps,
            (int) ($data['started_at'] ?? time()),
            (int) ($data['position'] ?? 0),
            (string) ($data['status'] ?? self::RUNNING),
            (array) ($data['cursor'] ?? []),
            is_string($data['backup_id'] ?? null) ? $data['backup_id'] : null,
            is_string($data['restore_id'] ?? null) ? $data['restore_id'] : null,
            array_values(array_filter((array) ($data['skip_packages'] ?? []), 'is_string')),
            array_values(array_filter((array) ($data['added_packages'] ?? []), 'is_string')),
            ($data['destructive'] ?? false) === true,
            (string) ($data['message'] ?? ''),
            (int) ($data['updated_at'] ?? 0),
            array_values(array_filter((array) ($data['log'] ?? []), 'is_array')),
        );
    }
}
