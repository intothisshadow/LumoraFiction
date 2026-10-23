<?php

/**
 * Performs an installation: builds the schema, creates the administrator, saves settings, and writes the config file.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Database\DatabaseConnectionException;
use LumoraFiction\Core\Database\Migrator;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Security\PasswordPolicy;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ClassificationPresets;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\UserService;
use Throwable;

/**
 * The config file is written last. Until it exists the site counts as not installed, so a failure
 * at any earlier point leaves the person free to try again, and the tables this attempt created are
 * removed first so the retry does not trip over its own leftovers.
 */
final class Installer
{
    public function __construct(
        private readonly string $root,
        private readonly ErrorHandler $errors,
        private readonly ?string $migrationsPath = null,
        private readonly ?string $presetsPath = null,
    ) {
    }

    /**
     * @throws InstallException with messages that are safe to show the person installing
     */
    public function install(InstallRequest $request): void
    {
        $configFile = $this->root . '/config/config.php';

        if (is_file($configFile)) {
            throw InstallException::because('Lumora Fiction is already installed.');
        }

        $database = $this->connect($request);
        $probe = new DatabaseProbe();

        $problems = $probe->check($database);

        if ($problems !== []) {
            throw new InstallException($problems);
        }

        $wroteConfig = false;

        try {
            (new Migrator($database, $this->migrationsPath ?? $this->root . '/app/Migrations'))->migrate();

            $config = new Configuration($configFile);
            $config->bindDatabase($database);

            $administrator = $this->createAdministrator($database, $config, $request);
            $this->saveSettings($config, $request);
            $this->applyStarterLists($database, $config, $administrator, $request);

            Configuration::generate($configFile, [
                'db_host' => $request->dbHost,
                'db_port' => $request->dbPort,
                'db_name' => $request->dbName,
                'db_user' => $request->dbUser,
                'db_password' => $request->dbPassword,
                'db_charset' => 'utf8mb4',
                'table_prefix' => $request->tablePrefix,
                'secret_key' => bin2hex(random_bytes(32)),
                'site_url' => $request->siteUrl,
                'debug' => false,
                'environment' => 'production',
            ]);

            $wroteConfig = true;
        } catch (Throwable $exception) {
            // Details go to the log; the person installing sees only that it failed and where to look.
            $this->errors->report($exception);
            $this->undo($database, $probe, $wroteConfig ? $configFile : null);

            throw InstallException::because('The installation could not be completed. Nothing was changed. Check the error log in the storage/logs folder for details, then try again.', $exception);
        }
    }

    private function connect(InstallRequest $request): Database
    {
        try {
            return Database::connect($request->dbHost, $request->dbName, $request->dbUser, $request->dbPassword, $request->tablePrefix, 'utf8mb4', $request->dbPort);
        } catch (DatabaseConnectionException $exception) {
            $this->errors->report($exception);

            throw InstallException::because('Could not connect to the database with those details. Check the host, port, database name, user, and password.', $exception);
        }
    }

    private function createAdministrator(Database $database, Configuration $config, InstallRequest $request): User
    {
        $users = new UserService($database, new PasswordPolicy($config));

        $user = $users->create(
            $request->adminLoginName,
            $request->adminDisplayName,
            $request->adminEmail,
            $request->adminPassword,
            UserRole::Administrator,
            UserStatus::Active,
        );

        // The person installing controls this address by definition, so it needs no confirmation email.
        $users->markEmailVerified($user->id);

        return $users->findById($user->id) ?? $user;
    }

    /**
     * Adds the starter lists the person chose, if any. Choosing none leaves a list empty, which the site handles.
     */
    private function applyStarterLists(Database $database, Configuration $config, User $administrator, InstallRequest $request): void
    {
        if ($request->presets === []) {
            return;
        }

        $audit = new AuditLogService($database);
        $presets = new ClassificationPresets($database, $config, new TaxonomyService($database, $config, $audit), $audit, $this->presetsPath ?? $this->root . '/app/Data/presets');

        foreach ($request->presets as $kind => $key) {
            $presets->apply($administrator, $kind, $key);
        }
    }

    private function saveSettings(Configuration $config, InstallRequest $request): void
    {
        /** @var array{version: string} $version */
        $version = require $this->root . '/version.php';

        $config->setSetting('site.name', $request->siteName);
        $config->setSetting('site.timezone', $request->timezone);
        $config->setSetting('archive.mode', $request->archiveMode);
        $config->setSetting('app.installed_version', $version['version']);

        // Written explicitly so the secure choices are visible in the settings, not only implied by defaults.
        foreach (AuthService::DEFAULTS as $name => $value) {
            $config->setSetting($name, $value);
        }
    }

    /**
     * Removes whatever this attempt created. The pre-install check guaranteed no table with this
     * prefix existed beforehand, so anything with it now was made here.
     */
    private function undo(Database $database, DatabaseProbe $probe, ?string $configFileWritten): void
    {
        try {
            foreach ($probe->tablesWithPrefix($database) as $table) {
                $database->pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }

            // Only a config file this attempt wrote is removed, never one that appeared some other way.
            if ($configFileWritten !== null && is_file($configFileWritten)) {
                unlink($configFileWritten);
            }
        } catch (Throwable $exception) {
            $this->errors->report($exception);
        }
    }
}
