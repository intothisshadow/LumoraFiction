<?php

/**
 * Applies versioned .sql migration files in order and records which have run.
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

use PDOException;

/**
 * Migrations are plain SQL files named NNNN_description.sql, applied in filename order. MySQL and
 * MariaDB commit DDL implicitly, so a migration cannot be rolled back as a transaction: keep each
 * file to one logical change so a failure leaves at most one change to repair by hand.
 */
final class Migrator
{
    private const FILENAME_PATTERN = '/^\d{4}_[a-z0-9_]+\.sql\z/';

    public function __construct(
        private readonly Database $database,
        private readonly string $migrationsPath,
        private readonly int $lockTimeoutSeconds = 30,
    ) {
    }

    public function ensureMigrationsTable(): void
    {
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(191) NOT NULL,
                executed_at DATETIME NOT NULL,
                UNIQUE KEY uniq_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /**
     * @return array<int, string> file paths of migrations that have not run yet, in order
     */
    public function pending(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();

        return array_values(array_filter(
            $this->migrationFiles(),
            static fn (string $file): bool => !in_array(basename($file), $applied, true),
        ));
    }

    /**
     * @return array{applied: int, total: int, up_to_date: bool}
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $applied = count($this->appliedMigrations());
        $total = count($this->migrationFiles());

        return ['applied' => $applied, 'total' => $total, 'up_to_date' => $applied >= $total];
    }

    /**
     * @return array<int, string> names of the migrations that were executed
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        // A named lock stops two requests (for example two administrators clicking Update) from
        // running the same migration at once. The pending list is read again after the lock is
        // held, because the other process may have finished the work while this one waited.
        $this->acquireLock();

        try {
            $executed = [];

            foreach ($this->pending() as $file) {
                $this->apply($file);
                $executed[] = basename($file);
            }

            return $executed;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Records migrations as applied without running them, for a schema built another way.
     *
     * @param array<int, string> $migrations filenames
     */
    public function markApplied(array $migrations): void
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();

        foreach ($migrations as $migration) {
            if (!in_array($migration, $applied, true)) {
                $this->recordMigration($migration);
            }
        }
    }

    /**
     * Splits a SQL file into statements on semicolons, ignoring semicolons inside quoted strings,
     * quoted identifiers, and comments. Comments are dropped.
     *
     * @return array<int, string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;

                // A backslash escapes the next character inside strings but not inside `identifiers`.
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($char === '#' || ($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\r", "\n"], true))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    private function apply(string $file): void
    {
        $name = basename($file);
        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new MigrationException('Migration ' . $name . ' could not be read.');
        }

        try {
            foreach (self::splitStatements($sql) as $statement) {
                $this->database->run($statement);
            }
        } catch (PDOException $exception) {
            throw new MigrationException(
                'Migration ' . $name . ' failed. Statements that ran before the failure are not undone.',
                previous: $exception,
            );
        }

        $this->recordMigration($name);
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];

        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => preg_match(self::FILENAME_PATTERN, basename($file)) === 1,
        ));

        sort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function appliedMigrations(): array
    {
        return array_column($this->database->fetchAll('SELECT migration FROM {prefix}migrations'), 'migration');
    }

    private function recordMigration(string $migration): void
    {
        $this->database->execute(
            'INSERT INTO {prefix}migrations (migration, executed_at) VALUES (:migration, :executed_at)',
            ['migration' => $migration, 'executed_at' => date('Y-m-d H:i:s')],
        );
    }

    private function lockName(): string
    {
        return 'lumorafiction_migrate_' . $this->database->prefix();
    }

    private function acquireLock(): void
    {
        $acquired = $this->database->fetchColumn(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $this->lockName(), 'timeout' => $this->lockTimeoutSeconds],
        );

        if ((int) $acquired !== 1) {
            throw new MigrationException('Another update is already changing the database. Try again in a moment.');
        }
    }

    private function releaseLock(): void
    {
        $this->database->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $this->lockName()]);
    }
}
