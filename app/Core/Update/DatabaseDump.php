<?php

/**
 * Writes the site's tables to a SQL file in resumable pieces, and reads such a file back.
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

use LumoraFiction\Core\Database\Database;
use PDO;
use PDOException;

/**
 * Both directions work for a limited time and return a cursor, so a large database is handled across several requests
 * on a host that cuts a request off after thirty seconds. The file holds one statement per line (every newline inside a
 * value is escaped), which is what lets a restore stop and continue at a byte offset without parsing SQL.
 *
 * The sessions table is left out in both directions: restoring it would sign the administrator out in the middle of the
 * step that is restoring, and a session is of no use after a rollback anyway.
 */
final class DatabaseDump
{
    public const FOOTER = '-- lumora-fiction-dump-complete';

    private const PAGE_ROWS = 400;

    private const INSERT_BYTES = 400000;

    private const LINE_CHUNK = 1048576;

    public function __construct(
        private readonly Database $database,
        private readonly bool $compress = true,
    ) {
    }

    public static function canCompress(): bool
    {
        return function_exists('gzopen');
    }

    /**
     * @return list<string> the application's tables, in the order they are dumped
     */
    public function tables(): array
    {
        $pdo = $this->database->pdo();
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->database->prefix()) . '%';
        $names = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($pattern))->fetchAll(PDO::FETCH_COLUMN);
        $skip = $this->database->prefix() . 'sessions';
        $tables = [];

        foreach ($names as $name) {
            if ((string) $name !== $skip) {
                $tables[] = (string) $name;
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * Dumps until the deadline, then reports where it stopped.
     *
     * @param array<string, mixed> $cursor empty to start, otherwise the cursor the last call returned
     * @return array{tables: list<string>, table: int, offset: int, size: int, rows: int, done: bool}
     * @throws UpdateException when the file cannot be written
     */
    public function dump(string $path, array $cursor, float $deadline): array
    {
        $fresh = !isset($cursor['tables']);
        $tables = $fresh ? $this->tables() : array_values(array_map('strval', (array) $cursor['tables']));
        $table = $fresh ? 0 : (int) ($cursor['table'] ?? 0);
        $offset = $fresh ? 0 : (int) ($cursor['offset'] ?? 0);
        $rows = $fresh ? 0 : (int) ($cursor['rows'] ?? 0);

        if (!$fresh) {
            // Anything a request wrote after the saved size belongs to a step that never reported back.
            $this->truncate($path, (int) ($cursor['size'] ?? 0));
        } elseif (is_file($path)) {
            unlink($path);
        }

        $handle = $this->compress ? gzopen($path, 'ab6') : fopen($path, 'ab');

        if ($handle === false) {
            throw new UpdateException('The backup file cannot be written. Check that the storage folder is writable and has room.');
        }

        try {
            if ($fresh) {
                $this->write($handle, "-- Lumora Fiction database dump\n-- created " . gmdate('Y-m-d H:i:s') . " UTC\n");
            }

            while ($table < count($tables)) {
                $name = $tables[$table];

                if ($offset === 0) {
                    $this->write($handle, 'DROP TABLE IF EXISTS ' . self::identifier($name) . ";\n");
                    $this->write($handle, $this->createStatement($name) . ";\n");
                }

                $order = $this->orderBy($name);

                while (true) {
                    $page = $this->database->pdo()->query(
                        'SELECT * FROM ' . self::identifier($name) . $order . ' LIMIT ' . self::PAGE_ROWS . ' OFFSET ' . $offset,
                    )->fetchAll(PDO::FETCH_ASSOC);

                    if ($page === []) {
                        break;
                    }

                    $this->writeRows($handle, $name, $page);
                    $offset += count($page);
                    $rows += count($page);

                    if (count($page) < self::PAGE_ROWS) {
                        break;
                    }

                    if (microtime(true) >= $deadline) {
                        return $this->saved($handle, $path, $tables, $table, $offset, $rows, false);
                    }
                }

                $table++;
                $offset = 0;
            }

            $this->write($handle, self::FOOTER . "\n");

            return $this->saved($handle, $path, $tables, $table, $offset, $rows, true);
        } catch (PDOException $exception) {
            throw new UpdateException('The database could not be read for the backup.', 0, $exception);
        }
    }

    /**
     * Reads statements back until the deadline. When the file ends, tables that the dump does not know are dropped, so a
     * table made by a migration that is being rolled back does not outlive it.
     *
     * @param array<string, mixed> $cursor empty to start
     * @param list<string> $expectedTables the tables the dump was taken from
     * @return array{offset: int, statements: int, done: bool}
     * @throws UpdateException when the file is damaged, incomplete or a statement fails
     */
    public function restore(string $path, array $cursor, float $deadline, array $expectedTables): array
    {
        $offset = (int) ($cursor['offset'] ?? 0);
        $statements = (int) ($cursor['statements'] ?? 0);
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');

        if ($handle === false) {
            throw new UpdateException('The database backup cannot be read.');
        }

        $pdo = $this->database->pdo();
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $complete = false;

        try {
            // Backslash escapes are what the dump uses, and a restore must not trip over a stricter mode than the backup was taken in.
            $pdo->exec("SET SESSION sql_mode = ''");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('SET UNIQUE_CHECKS = 0');

            if ($offset > 0) {
                $gzip ? gzseek($handle, $offset) : fseek($handle, $offset);
            }

            while (($line = $this->readLine($handle, $gzip)) !== null) {
                $offset += strlen($line);
                $statement = rtrim($line, "\r\n");

                if ($statement === self::FOOTER) {
                    $complete = true;

                    continue;
                }

                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }

                $pdo->exec($statement);
                $statements++;

                // Checked after every statement: one insert can be several hundred kilobytes, so counting statements says little.
                if (microtime(true) >= $deadline) {
                    return ['offset' => $offset, 'statements' => $statements, 'done' => false];
                }
            }

            if (!$complete) {
                throw new UpdateException('The database backup is incomplete, so it was not used. Nothing more was changed.');
            }

            foreach (array_diff($this->tables(), $expectedTables) as $extra) {
                $pdo->exec('DROP TABLE IF EXISTS ' . self::identifier($extra));
            }

            return ['offset' => $offset, 'statements' => $statements, 'done' => true];
        } catch (PDOException $exception) {
            throw new UpdateException('The database backup could not be restored.', 0, $exception);
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->exec('SET UNIQUE_CHECKS = 1');
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($mode));
        }
    }

    /**
     * Whether a file is a finished dump, read through to its last line.
     */
    public function isComplete(string $path): bool
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $last = '';

        while (($line = $this->readLine($handle, $gzip)) !== null) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }

        $gzip ? gzclose($handle) : fclose($handle);

        return $last === self::FOOTER;
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function quote(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z']) . "'";
    }

    private function createStatement(string $table): string
    {
        $row = $this->database->pdo()->query('SHOW CREATE TABLE ' . self::identifier($table))->fetch(PDO::FETCH_NUM);

        if (!is_array($row) || !isset($row[1])) {
            throw new UpdateException('The structure of a table could not be read for the backup.');
        }

        // One statement per line; a newline inside a definition is only ever layout.
        return (string) preg_replace('/\s*\R\s*/', ' ', (string) $row[1]);
    }

    private function orderBy(string $table): string
    {
        $rows = $this->database->pdo()->query('SHOW KEYS FROM ' . self::identifier($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return '';
        }

        usort($rows, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);

        return ' ORDER BY ' . implode(', ', array_map(static fn (array $row): string => self::identifier((string) $row['Column_name']), $rows));
    }

    /**
     * @param resource $handle
     * @param list<array<string, mixed>> $rows
     */
    private function writeRows($handle, string $table, array $rows): void
    {
        $columns = implode(', ', array_map(self::identifier(...), array_keys($rows[0])));
        $values = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $parts = [];

            foreach ($row as $value) {
                $parts[] = $value === null ? 'NULL' : self::quote((string) $value);
            }

            $tuple = '(' . implode(', ', $parts) . ')';
            $values[] = $tuple;
            $bytes += strlen($tuple);

            if ($bytes >= self::INSERT_BYTES) {
                $this->write($handle, 'INSERT INTO ' . self::identifier($table) . ' (' . $columns . ') VALUES ' . implode(', ', $values) . ";\n");
                $values = [];
                $bytes = 0;
            }
        }

        if ($values !== []) {
            $this->write($handle, 'INSERT INTO ' . self::identifier($table) . ' (' . $columns . ') VALUES ' . implode(', ', $values) . ";\n");
        }
    }

    /**
     * @param resource $handle
     * @throws UpdateException
     */
    private function write($handle, string $text): void
    {
        $result = $this->compress ? gzwrite($handle, $text) : fwrite($handle, $text);

        if ($result === false || $result < strlen($text)) {
            throw new UpdateException('The backup file cannot be written. The disk may be full.');
        }
    }

    /**
     * @param resource $handle
     * @param list<string> $tables
     * @return array{tables: list<string>, table: int, offset: int, size: int, rows: int, done: bool}
     */
    private function saved($handle, string $path, array $tables, int $table, int $offset, int $rows, bool $done): array
    {
        $this->compress ? gzclose($handle) : fclose($handle);
        clearstatcache(true, $path);

        return ['tables' => $tables, 'table' => $table, 'offset' => $offset, 'size' => (int) filesize($path), 'rows' => $rows, 'done' => $done];
    }

    private function truncate(string $path, int $size): void
    {
        if (!is_file($path)) {
            throw new UpdateException('The backup file went missing before the backup finished.');
        }

        $handle = fopen($path, 'r+b');

        if ($handle === false) {
            throw new UpdateException('The backup file cannot be written. Check that the storage folder is writable.');
        }

        ftruncate($handle, $size);
        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function readLine($handle, bool $gzip): ?string
    {
        $line = '';

        while (true) {
            $part = $gzip ? gzgets($handle, self::LINE_CHUNK) : fgets($handle, self::LINE_CHUNK);

            if ($part === false) {
                return $line === '' ? null : $line;
            }

            $line .= $part;

            if (str_ends_with($part, "\n")) {
                return $line;
            }
        }
    }
}
