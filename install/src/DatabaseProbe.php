<?php

/**
 * Checks that a database server can host Lumora Fiction before anything is created.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Installer;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Requirements;
use Throwable;

/**
 * Rather than trusting the version number alone, the probe does the same kinds of things the
 * migrations will do (create, insert into, alter, and drop a table) in a throwaway table, so a
 * database user without those privileges is caught up front with a clear message and not halfway
 * through building the schema.
 */
final class DatabaseProbe
{
    /**
     * @return string|null a plain-language problem, or null when the version is acceptable or cannot be read
     */
    public static function versionProblem(string $reported): ?string
    {
        return Requirements::databaseVersionProblem($reported);
    }

    /**
     * @return list<string> plain-language problems; empty when the database is ready
     */
    public function check(Database $database): array
    {
        $problems = [];

        try {
            $version = (string) $database->fetchColumn('SELECT VERSION()');
        } catch (Throwable) {
            return ['The database did not answer a basic query.'];
        }

        if (($problem = self::versionProblem($version)) !== null) {
            $problems[] = $problem;
        }

        if ($this->tablesWithPrefix($database) !== []) {
            return [...$problems, 'This database already contains tables that start with that prefix. Choose a different table prefix, or use an empty database.'];
        }

        return [...$problems, ...$this->privilegeProblems($database)];
    }

    /**
     * Every table whose name starts with the install's prefix. Used both to refuse an install over
     * existing data and to undo a failed install, which is safe because the check above guarantees
     * that anything with this prefix was created by the failed attempt.
     *
     * @return list<string>
     */
    public function tablesWithPrefix(Database $database): array
    {
        // Underscore and percent are wildcards in LIKE, and prefixes are full of underscores.
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $database->prefix()) . '%';

        return array_map(
            'strval',
            $database->pdo()->query('SHOW TABLES LIKE ' . $database->pdo()->quote($pattern))->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    /**
     * @return list<string>
     */
    private function privilegeProblems(Database $database): array
    {
        $table = $database->table('install_probe');
        $created = false;

        try {
            try {
                $database->run('CREATE TABLE {prefix}install_probe (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
                $created = true;
            } catch (Throwable) {
                return ['The database user is not allowed to create tables.'];
            }

            try {
                $database->execute('INSERT INTO {prefix}install_probe (id) VALUES (1)');
            } catch (Throwable) {
                return ['The database user is not allowed to add rows to tables.'];
            }

            try {
                $database->run('ALTER TABLE {prefix}install_probe ADD COLUMN note VARCHAR(10) NULL');
            } catch (Throwable) {
                return ['The database user is not allowed to alter tables, which updates need.'];
            }

            $engine = $database->fetchColumn(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
                ['name' => $table],
            );

            if (is_string($engine) && strcasecmp($engine, 'InnoDB') !== 0) {
                return ['This database server does not support InnoDB tables, which Lumora Fiction requires.'];
            }

            return [];
        } finally {
            if ($created) {
                try {
                    $database->run('DROP TABLE IF EXISTS {prefix}install_probe');
                } catch (Throwable) {
                    // The probe table is harmless and carries the install's own prefix; a refused drop is not worth failing over.
                }
            }
        }
    }
}
