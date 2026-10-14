<?php

/**
 * Thin PDO wrapper that applies the configurable table prefix and runs every query as a prepared statement.
 *
 * @package LumoraFiction
 * @subpackage Database
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Database;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * SQL written in the application and in migrations uses the literal token {prefix} in front of
 * every table name, so no table name is ever hardcoded and the random per-install prefix is
 * applied in exactly one place. The token is replaced in the SQL text only; user data is always
 * bound as parameters and never reaches the SQL string.
 */
final class Database
{
    // \z, not $: $ also matches before a trailing newline, which would let one into a table name.
    private const PREFIX_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{1,29}\z/';

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix,
    ) {
        if (!self::isValidPrefix($tablePrefix)) {
            throw new InvalidArgumentException('The table prefix must start with a letter and contain only letters, numbers, and underscores (2 to 30 characters).');
        }
    }

    /**
     * The one definition of an acceptable table prefix, shared by the connection, the config file
     * check, and the installer so they cannot drift apart.
     */
    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match(self::PREFIX_PATTERN, $prefix) === 1;
    }

    /**
     * Connects to a MySQL/MariaDB server over PDO. This is the constructor used by the running application.
     */
    public static function connect(
        string $host,
        string $database,
        string $username,
        string $password,
        string $tablePrefix,
        string $charset = 'utf8mb4',
        int $port = 3306,
    ): self {
        // These values are interpolated into the DSN, where ';' or '=' would let a crafted value
        // inject extra driver options, so they are rejected rather than escaped.
        foreach (['host' => $host, 'database' => $database, 'charset' => $charset] as $name => $value) {
            if ($value === '' || preg_match('/[;=\x00-\x1f]/', $value) === 1) {
                throw new DatabaseConnectionException('The database ' . $name . ' setting is not valid.');
            }
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Native prepared statements, so parameters are never spliced into SQL client-side.
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException('Unable to connect to the database.', previous: $exception);
        }

        return new self($pdo, $tablePrefix);
    }

    /**
     * Wraps an already-open PDO connection, such as an in-memory SQLite connection in tests.
     */
    public static function fromPdo(PDO $pdo, string $tablePrefix): self
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return new self($pdo, $tablePrefix);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Full table name for a short name, e.g. table('users') gives 'lumfic_a8f3d1_users'.
     */
    public function table(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid table name.');
        }

        return $this->tablePrefix . $name;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($this->applyPrefix($sql));
        $statement->execute($params);

        return $statement;
    }

    /**
     * Runs a statement that takes no parameters, such as schema changes from a migration.
     */
    public function run(string $sql): void
    {
        $this->pdo->exec($this->applyPrefix($sql));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function insertGetId(string $sql, array $params = []): string
    {
        $this->query($sql, $params);

        return $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function applyPrefix(string $sql): string
    {
        return str_replace('{prefix}', $this->tablePrefix, $sql);
    }
}
