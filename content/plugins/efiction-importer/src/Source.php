<?php

/**
 * A read-only view of an eFiction 3.x database: connecting, finding the table prefix, and SELECT queries.
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

use PDO;
use PDOException;

final class Source
{
    /** Tables the importer cannot work without; the rest are read only when they exist. */
    public const REQUIRED = ['stories', 'chapters', 'authors'];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', ''];

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
        private readonly string $databaseName,
    ) {
    }

    /**
     * @param array{host: string, port: int, name: string, prefix: string}|null $own this site's own connection, so it can be refused
     * @throws SourceException
     */
    public static function connect(string $host, int $port, string $name, string $user, string $password, string $prefix, ?array $own = null): self
    {
        if (preg_match('/[;=\x00-\x1f]/', $host . $name) === 1 || $host === '' || $name === '') {
            throw new SourceException('The host and database name must be filled in, and cannot contain ; or =.');
        }

        if (preg_match('/^[A-Za-z0-9_]{0,40}\z/', $prefix) !== 1) {
            throw new SourceException('The table prefix may only contain letters, numbers and underscores.');
        }

        if ($own !== null && self::isOwn($host, $port, $name, $prefix, $own)) {
            throw new SourceException('That points at this site\'s own tables. The importer only reads an eFiction database, so choose the eFiction table prefix, not this site\'s.');
        }

        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name), $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            // The server itself then refuses a write, so read-only does not rest on this class alone.
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (PDOException $exception) {
            throw new SourceException(self::describe($exception));
        }

        return new self($pdo, $prefix, $name);
    }

    /**
     * The same server and database count as this site only when the prefix is this site's too: a shared host often has one
     * database for two scripts, and eFiction beside Lumora Fiction under another prefix is exactly what is being imported.
     *
     * @param array{host: string, port: int, name: string, prefix: string} $own
     */
    public static function isOwn(string $host, int $port, string $name, string $prefix, array $own): bool
    {
        $sameHost = strtolower($host) === strtolower($own['host'])
            || (in_array(strtolower($host), self::LOCAL_HOSTS, true) && in_array(strtolower($own['host']), self::LOCAL_HOSTS, true));

        return $sameHost && $port === $own['port'] && $name === $own['name'] && $prefix === $own['prefix'];
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function hasTable(string $table): bool
    {
        return $this->tableExists($this->prefix . $table);
    }

    /**
     * The prefixes in this database that have the three tables the importer needs, so a wrong or empty prefix can be corrected.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        $tables = [];

        try {
            foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $tables[(string) $table] = true;
            }
        } catch (PDOException) {
            return [];
        }

        $found = [];

        foreach (array_keys($tables) as $table) {
            if (!str_ends_with($table, 'stories')) {
                continue;
            }

            $prefix = substr($table, 0, -strlen('stories'));

            if (isset($tables[$prefix . 'chapters'], $tables[$prefix . 'authors'])) {
                $found[] = $prefix;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The databases this account may see, or none when the server does not allow listing them.
     *
     * @return list<string>
     */
    public static function databases(string $host, int $port, string $user, string $password): array
    {
        if (preg_match('/[;=\x00-\x1f]/', $host) === 1 || $host === '') {
            return [];
        }

        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            $names = array_map('strval', $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException) {
            return [];
        }

        return array_values(array_filter($names, static fn (string $name): bool => !in_array(strtolower($name), ['information_schema', 'performance_schema', 'mysql', 'sys'], true)));
    }

    /**
     * Runs one SELECT. {p} stands for the eFiction table prefix. Anything that is not a single SELECT is refused here, before
     * the server's own read-only session would refuse it.
     *
     * @param array<string, int|string> $params
     * @return list<array<string, mixed>>
     * @throws SourceException
     */
    public function select(string $sql, array $params = []): array
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) !== 1 || str_contains($sql, ';')) {
            throw new SourceException('Only a single read is allowed on the eFiction database.');
        }

        try {
            $statement = $this->pdo->prepare(str_replace('{p}', $this->prefix, $sql));

            foreach ($params as $name => $value) {
                $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $statement->execute();

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new SourceException(self::describe($exception));
        }
    }

    /**
     * @param array<string, int|string> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->select($sql, $params)[0] ?? null;

        return $row === null ? null : reset($row);
    }

    /**
     * eFiction's own settings row (the story storage mode, the stories folder, the language and the version), or nothing when the
     * table is unreadable.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        if (!$this->hasTable('settings')) {
            return [];
        }

        $row = $this->select('SELECT store, storiespath, language, version FROM `{p}settings` LIMIT 1')[0] ?? [];

        return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row);
    }

    private function tableExists(string $table): bool
    {
        try {
            // SHOW cannot be prepared on the server, so the pattern is quoted; the underscores of a prefix must match as themselves.
            $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);

            return $this->pdo->query('SHOW TABLES LIKE ' . $this->pdo->quote($pattern))->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }

    private static function describe(PDOException $exception): string
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);

        return match (true) {
            $code === 1045 => 'The database did not accept that user name and password.',
            $code === 1049 => 'There is no database with that name.',
            $code === 1044 => 'That user may not use that database.',
            $code === 1142, $code === 1143 => 'That user may not read one of the eFiction tables.',
            $code === 1146 => 'One of the eFiction tables is missing. Check the table prefix.',
            in_array($code, [2002, 2003, 2006, 2013], true) => 'The database server could not be reached. Check the host and port.',
            default => 'The eFiction database could not be read' . ($code > 0 ? ' (error ' . $code . ').' : '.'),
        };
    }
}
