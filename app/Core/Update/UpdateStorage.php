<?php

/**
 * Where the updater keeps its working files, history and logs.
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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Everything here lives in files under storage/, never in the database: restoring the database to undo an update
 * would otherwise erase the record of the update, and a half-finished one must survive whatever state the tables are in.
 */
final class UpdateStorage
{
    private const HISTORY_LIMIT = 100;

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function updatesDir(): string
    {
        return $this->root . '/storage/updates';
    }

    public function backupsDir(): string
    {
        return $this->root . '/storage/backups';
    }

    public function stagingDir(): string
    {
        return $this->updatesDir() . '/staging';
    }

    public function packagePath(): string
    {
        return $this->updatesDir() . '/package.zip';
    }

    public function statePath(): string
    {
        return $this->updatesDir() . '/state.json';
    }

    public function lockPath(): string
    {
        return $this->updatesDir() . '/run.lock';
    }

    public function packageInfoPath(): string
    {
        return $this->updatesDir() . '/package.json';
    }

    public function latestPath(): string
    {
        return $this->updatesDir() . '/latest.json';
    }

    public function bundledPath(): string
    {
        return $this->updatesDir() . '/bundled.json';
    }

    public function historyPath(): string
    {
        return $this->updatesDir() . '/history.jsonl';
    }

    public function logPath(): string
    {
        return $this->root . '/storage/logs/updates.log';
    }

    /**
     * @throws UpdateException when a folder cannot be created
     */
    public function ensure(): void
    {
        foreach ([$this->updatesDir(), $this->backupsDir(), dirname($this->logPath())] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new UpdateException('The storage folder cannot be written to, so the update cannot keep its working files.');
            }
        }
    }

    /**
     * @return array<string, mixed>|null null when the file is missing or not valid JSON
     */
    public function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Written to a temporary name first and renamed, so a request that dies mid-write never leaves half a file.
     *
     * @param array<mixed> $data
     * @throws UpdateException
     */
    public function writeJson(string $path, array $data): void
    {
        $this->ensure();
        $temporary = $path . '.tmp';

        if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($temporary, $path)) {
            throw new UpdateException('The update could not save its progress to the storage folder.');
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function addHistory(array $entry): void
    {
        $this->ensure();
        $lines = $this->historyLines();
        $lines[] = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        if (count($lines) > self::HISTORY_LIMIT) {
            $lines = array_slice($lines, -self::HISTORY_LIMIT);
        }

        file_put_contents($this->historyPath(), implode("\n", $lines) . "\n", LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function history(int $limit = 20): array
    {
        $entries = [];

        foreach (array_reverse($this->historyLines()) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Appends one line to the update log. The log is for the administrator and holds no secrets or filesystem paths.
     */
    public function log(string $message): void
    {
        $this->ensure();
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . preg_replace('/[\r\n]+/', ' ', $message) . "\n";

        file_put_contents($this->logPath(), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Deletes a folder and everything in it. Symbolic links inside are removed, never followed.
     */
    public function removeTree(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }

    /**
     * @return list<string>
     */
    private function historyLines(): array
    {
        if (!is_file($this->historyPath())) {
            return [];
        }

        $raw = file_get_contents($this->historyPath());

        return $raw === false ? [] : array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => trim($line) !== ''));
    }
}
