<?php

/**
 * Central configuration service: bootstrap values from config/config.php and editable settings from the database.
 *
 * @package LumoraFiction
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core;

use InvalidArgumentException;
use JsonException;
use LumoraFiction\Core\Database\Database;
use RuntimeException;

/**
 * Two layers:
 *  - File config: values needed before the database is reachable (credentials, table prefix,
 *    secret key), loaded once from config/config.php and read-only at runtime.
 *  - Settings: administrator-editable values stored in the database, cached after the first read.
 *    Defaults are registered by the code that owns each setting, so a missing row falls back to a
 *    known value instead of null.
 */
final class Configuration
{
    private const REQUIRED_FILE_KEYS = ['db_host', 'db_name', 'db_user', 'db_password', 'table_prefix', 'secret_key'];

    // \z, not $: $ also matches before a trailing newline.
    private const SETTING_NAME_PATTERN = '/^[a-z][a-z0-9_.]{0,99}\z/';

    /** @var array<string, mixed> */
    private array $fileConfig = [];

    private bool $fileLoaded = false;

    /** @var array<string, mixed> decoded values keyed by setting name */
    private array $settings = [];

    private bool $settingsLoaded = false;

    /** @var array<string, mixed> */
    private array $defaults = [];

    private ?Database $database = null;

    public function __construct(private readonly string $configFile)
    {
    }

    public function bindDatabase(Database $database): void
    {
        $this->database = $database;
    }

    /**
     * Registers fallback values for settings. Later registrations win over earlier ones for the same name.
     *
     * @param array<string, mixed> $defaults
     */
    public function registerDefaults(array $defaults): void
    {
        foreach ($defaults as $name => $value) {
            $this->assertValidSettingName((string) $name);
            $this->defaults[(string) $name] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureFileLoaded();

        return $this->fileConfig[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->ensureFileLoaded();

        return array_key_exists($key, $this->fileConfig);
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        $this->assertValidSettingName($name);
        $this->ensureSettingsLoaded();

        if (array_key_exists($name, $this->settings)) {
            return $this->settings[$name];
        }

        return array_key_exists($name, $this->defaults) ? $this->defaults[$name] : $default;
    }

    public function setSetting(string $name, mixed $value): void
    {
        $this->assertValidSettingName($name);
        $this->ensureSettingsLoaded();

        try {
            // Slashes and Unicode are left as written so values stay readable when inspected in the database.
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Setting values must be representable as JSON.', previous: $exception);
        }

        $this->settings[$name] = $value;

        if ($this->database === null) {
            return;
        }

        // The value is bound twice because native prepared statements do not allow reusing one
        // named parameter; this avoids VALUES(), which newer MySQL versions deprecate.
        $this->database->execute(
            'INSERT INTO {prefix}settings (setting_name, setting_value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE setting_value = :updated',
            ['name' => $name, 'value' => $encoded, 'updated' => $encoded],
        );
    }

    /**
     * Writes config/config.php. Used by the installer.
     *
     * @param array<string, mixed> $data
     */
    public static function generate(string $path, array $data): void
    {
        self::validateFileConfig($data);

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";

        // Written to a temporary file and renamed, so a failure part-way through can never leave a
        // truncated config that locks the site out.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the configuration file.');
        }

        // The file holds database credentials and the secret key. This is best effort because some
        // hosts do not allow changing permissions.
        chmod($temporary, 0640);

        if (!rename($temporary, $path)) {
            unlink($temporary);

            throw new RuntimeException('Unable to write the configuration file.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function validateFileConfig(array $data): void
    {
        foreach (self::REQUIRED_FILE_KEYS as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                throw new RuntimeException('The configuration is missing a required value: ' . $key . '.');
            }
        }

        if (!Database::isValidPrefix($data['table_prefix'])) {
            throw new RuntimeException('The configuration contains an invalid table prefix.');
        }

        if (strlen($data['secret_key']) < 32) {
            throw new RuntimeException('The configuration secret key is too short.');
        }
    }

    private function ensureFileLoaded(): void
    {
        if ($this->fileLoaded) {
            return;
        }

        if (!is_file($this->configFile)) {
            throw new RuntimeException('Configuration file not found. Run the installer first.');
        }

        $data = require $this->configFile;

        if (!is_array($data)) {
            throw new RuntimeException('Configuration file must return an array.');
        }

        self::validateFileConfig($data);

        $this->fileConfig = $data;
        $this->fileLoaded = true;
    }

    private function ensureSettingsLoaded(): void
    {
        if ($this->settingsLoaded || $this->database === null) {
            return;
        }

        foreach ($this->database->fetchAll('SELECT setting_name, setting_value FROM {prefix}settings') as $row) {
            try {
                $this->settings[$row['setting_name']] = json_decode((string) $row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // A value that is not valid JSON was not written by this class; ignore it so the
                // default applies instead of surfacing corrupt data.
                continue;
            }
        }

        $this->settingsLoaded = true;
    }

    private function assertValidSettingName(string $name): void
    {
        if (preg_match(self::SETTING_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid setting name.');
        }
    }
}
