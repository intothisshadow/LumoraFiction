<?php

/**
 * Backups of the application files and the database, kept in the storage folder.
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

use FilesystemIterator;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Update\DatabaseDump;
use LumoraFiction\Core\Update\FileSync;
use LumoraFiction\Core\Update\SafeZip;
use LumoraFiction\Core\Update\UpdateException;
use LumoraFiction\Core\Update\UpdateLayout;
use LumoraFiction\Core\Update\UpdateStorage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * A backup is a files ZIP, a database dump and a small JSON record, sharing one identifier. The ZIP holds the
 * application (code, bundled and added plugins and themes, the server rules) but never the configuration, uploads or
 * runtime storage: those are not replaced by an update, so a restore must not roll them back either. The record is
 * written last, so a backup that was interrupted is listed as incomplete instead of being trusted.
 */
final class BackupService
{
    public const KIND_MANUAL = 'manual';

    public const KIND_UPDATE = 'pre-update';

    public const KIND_RESTORE = 'pre-restore';

    public const ID_PATTERN = '/^\d{8}-\d{6}-[0-9a-f]{12}\z/';

    private const BACKUP_ZIP_LIMIT = 2 * 1024 * 1024 * 1024;

    private const ROOT_FILE_LIMIT = 2 * 1024 * 1024;

    public function __construct(
        private readonly UpdateStorage $storage,
        private readonly Database $database,
        private readonly string $version,
    ) {
    }

    /**
     * Allocates an identifier and writes the first, incomplete, record.
     *
     * @return array<string, mixed> the record
     * @throws UpdateException
     */
    public function begin(string $kind): array
    {
        $this->storage->ensure();

        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $record = [
            'id' => $id,
            'kind' => $kind,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'app_version' => $this->version,
            'status' => 'incomplete',
            'compressed' => DatabaseDump::canCompress(),
        ];

        $this->storage->writeJson($this->recordPath($id), $record);

        return $record;
    }

    /**
     * Zips the application files. Running it again for the same backup starts that ZIP over.
     *
     * @return int the number of files in the ZIP
     * @throws UpdateException
     */
    public function backupFiles(string $id): int
    {
        $record = $this->requireRecord($id);
        $path = $this->filesPath($id);

        if (is_file($path)) {
            unlink($path);
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('The backup ZIP cannot be created. Check that the storage folder is writable.');
        }

        $count = 0;

        foreach ($this->siteFiles() as $relative) {
            if (!$zip->addFile($this->storage->root() . '/' . $relative, $relative)) {
                $zip->close();

                throw new UpdateException('A file could not be added to the backup.');
            }

            $count++;
        }

        if (!$zip->close()) {
            throw new UpdateException('The backup ZIP could not be written. The disk may be full.');
        }

        $check = new ZipArchive();

        if ($check->open($path, ZipArchive::RDONLY) !== true || $check->numFiles !== $count) {
            throw new UpdateException('The backup ZIP was written but does not check out, so it was not trusted.');
        }

        $check->close();
        $this->storage->writeJson($this->recordPath($id), array_merge($record, ['files_count' => $count]));

        return $count;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed> the cursor to pass next time; 'done' is true when the dump is finished
     * @throws UpdateException
     */
    public function backupDatabase(string $id, array $cursor, float $deadline): array
    {
        $record = $this->requireRecord($id);
        $dump = new DatabaseDump($this->database, (bool) ($record['compressed'] ?? false));
        $cursor = $dump->dump($this->databasePath($id), $cursor, $deadline);

        if ($cursor['done']) {
            $this->storage->writeJson($this->recordPath($id), array_merge($record, ['db_tables' => $cursor['tables'], 'db_rows' => $cursor['rows']]));
        }

        return $cursor;
    }

    /**
     * Checks both files and marks the backup complete, with the checksums a later restore verifies.
     *
     * @return array<string, mixed> the finished record
     * @throws UpdateException when either file is missing or does not check out
     */
    public function finish(string $id): array
    {
        $record = $this->requireRecord($id);
        $files = $this->filesPath($id);
        $database = $this->databasePath($id);

        if (!is_file($files) || !is_file($database)) {
            throw new UpdateException('The backup is missing a file, so it cannot be completed.');
        }

        if (!(new DatabaseDump($this->database, (bool) ($record['compressed'] ?? false)))->isComplete($database)) {
            throw new UpdateException('The database backup does not end where it should, so it was not trusted.');
        }

        $record['status'] = 'complete';
        $record['files_bytes'] = (int) filesize($files);
        $record['files_sha256'] = (string) hash_file('sha256', $files);
        $record['db_bytes'] = (int) filesize($database);
        $record['db_sha256'] = (string) hash_file('sha256', $database);
        $this->storage->writeJson($this->recordPath($id), $record);

        return $record;
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function all(): array
    {
        $records = [];

        foreach (glob($this->storage->backupsDir() . '/*.json') ?: [] as $file) {
            $record = $this->storage->readJson($file);

            if ($record !== null && is_string($record['id'] ?? null) && preg_match(self::ID_PATTERN, $record['id']) === 1) {
                $records[] = $record;
            }
        }

        usort($records, static fn (array $a, array $b): int => strcmp((string) $b['id'], (string) $a['id']));

        return $records;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        return $this->storage->readJson($this->recordPath($id));
    }

    public function isComplete(string $id): bool
    {
        return ($this->find($id)['status'] ?? '') === 'complete';
    }

    /**
     * @return string|null the file for a download, or null when the backup or that part of it does not exist
     */
    public function pathFor(string $id, string $part): ?string
    {
        if ($this->find($id) === null) {
            return null;
        }

        $path = $part === 'files' ? $this->filesPath($id) : ($part === 'database' ? $this->databasePath($id) : null);

        return $path !== null && is_file($path) ? $path : null;
    }

    public function delete(string $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }

        foreach ([$this->filesPath($id), $this->databasePath($id, true), $this->databasePath($id, false), $this->recordPath($id)] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    /**
     * Checks that a finished backup is still exactly what was written: both files present and matching their checksums.
     * A restore calls this before it changes anything, so a damaged backup is found while the site is still whole.
     *
     * @throws UpdateException
     */
    public function verify(string $id): void
    {
        $record = $this->requireComplete($id);
        $files = $this->filesPath($id);
        $database = $this->databasePath($id, (bool) ($record['compressed'] ?? false));

        if (!is_file($files) || !hash_equals((string) ($record['files_sha256'] ?? ''), (string) hash_file('sha256', $files))) {
            throw new UpdateException('The files backup is missing or has changed since it was made, so it was not used.');
        }

        if (!is_file($database) || !hash_equals((string) ($record['db_sha256'] ?? ''), (string) hash_file('sha256', $database))) {
            throw new UpdateException('The database backup is missing or has changed since it was made, so it was not used.');
        }
    }

    /**
     * Fails, changing nothing, when the web server could not write the files a restore of this backup would write.
     *
     * @throws UpdateException
     */
    public function assertRestorable(string $id): void
    {
        $this->requireComplete($id);

        $zip = SafeZip::open($this->filesPath($id), self::BACKUP_ZIP_LIMIT);

        try {
            $paths = array_values(array_filter(
                array_column(SafeZip::entries($zip), 'path'),
                static fn (string $path): bool => UpdateLayout::isUpdatable($path),
            ));
        } finally {
            $zip->close();
        }

        (new FileSync($this->storage->root()))->assertPathsWritable($paths);
    }

    /**
     * Puts the application files back as the backup had them. Plugins and themes added since are left alone.
     *
     * @return array{written: int, removed: int, skipped: list<string>}
     * @throws UpdateException when the backup is incomplete or fails its checksum
     */
    public function restoreFiles(string $id): array
    {
        $record = $this->requireComplete($id);
        $path = $this->filesPath($id);

        if (!hash_equals((string) ($record['files_sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new UpdateException('The files backup has changed since it was made, so it was not used.');
        }

        $staging = $this->storage->updatesDir() . '/restore-files';
        $this->storage->removeTree($staging);

        $zip = SafeZip::open($path, self::BACKUP_ZIP_LIMIT);

        try {
            $entries = array_values(array_filter(
                SafeZip::entries($zip),
                static fn (array $entry): bool => UpdateLayout::isUpdatable($entry['path']),
            ));

            SafeZip::extract($zip, $entries, $staging);
        } finally {
            $zip->close();
        }

        try {
            return (new FileSync($this->storage->root()))->apply($staging);
        } finally {
            $this->storage->removeTree($staging);
        }
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     * @throws UpdateException
     */
    public function restoreDatabase(string $id, array $cursor, float $deadline): array
    {
        $record = $this->requireComplete($id);
        $path = $this->databasePath($id, (bool) ($record['compressed'] ?? false));

        if ($cursor === [] && !hash_equals((string) ($record['db_sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new UpdateException('The database backup has changed since it was made, so it was not used.');
        }

        return (new DatabaseDump($this->database, (bool) ($record['compressed'] ?? false)))
            ->restore($path, $cursor, $deadline, array_map('strval', (array) ($record['db_tables'] ?? [])));
    }

    /**
     * A rough size for a new backup, to compare with the free space before one is started.
     */
    public function estimateBytes(): int
    {
        $bytes = 0;

        foreach ($this->siteFiles() as $relative) {
            $size = filesize($this->storage->root() . '/' . $relative);
            $bytes += $size === false ? 0 : $size;
        }

        $row = $this->database->fetchOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :pattern',
            ['pattern' => str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->database->prefix()) . '%'],
        );

        return $bytes + (int) ($row['bytes'] ?? 0);
    }

    /**
     * @return list<string> paths, relative to the site, of the application files a backup holds
     */
    public function siteFiles(): array
    {
        $root = $this->storage->root();
        $files = [];

        foreach (['app', 'admin', 'assets', 'docs', 'content/plugins', 'content/themes'] as $directory) {
            if (!is_dir($root . '/' . $directory) || is_link($root . '/' . $directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                if (UpdateLayout::isUpdatable($relative)) {
                    $files[$relative] = true;
                }
            }
        }

        // Files that sit directly in the site folder or in a few folders otherwise left to the site. A very large one
        // in the site folder is a host's error log, not part of the application.
        foreach (['', 'content/', 'content/plugins/', 'content/themes/', 'content/uploads/', 'config/', 'storage/'] as $prefix) {
            if (!is_dir($root . '/' . $prefix)) {
                continue;
            }

            foreach (scandir($root . '/' . $prefix) ?: [] as $name) {
                $file = $root . '/' . $prefix . $name;
                $relative = $prefix . $name;

                if (is_file($file) && !is_link($file) && UpdateLayout::isUpdatable($relative) && ($prefix !== '' || filesize($file) <= self::ROOT_FILE_LIMIT)) {
                    $files[$relative] = true;
                }
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    private function recordPath(string $id): string
    {
        return $this->storage->backupsDir() . '/' . $id . '.json';
    }

    private function filesPath(string $id): string
    {
        return $this->storage->backupsDir() . '/' . $id . '-files.zip';
    }

    private function databasePath(string $id, ?bool $compressed = null): string
    {
        $compressed ??= (bool) ($this->find($id)['compressed'] ?? DatabaseDump::canCompress());

        return $this->storage->backupsDir() . '/' . $id . '-database.sql' . ($compressed ? '.gz' : '');
    }

    /**
     * @return array<string, mixed>
     * @throws UpdateException
     */
    private function requireRecord(string $id): array
    {
        $record = $this->find($id);

        if ($record === null) {
            throw new UpdateException('That backup does not exist.');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     * @throws UpdateException
     */
    private function requireComplete(string $id): array
    {
        $record = $this->requireRecord($id);

        if (($record['status'] ?? '') !== 'complete') {
            throw new UpdateException('That backup was never finished, so it cannot be restored.');
        }

        return $record;
    }
}
