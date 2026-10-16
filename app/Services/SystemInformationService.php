<?php

/**
 * Describes the server and the installation, for the System Information screen and its downloadable snapshot.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use Closure;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Requirements;
use Throwable;

/**
 * The screen is for the administrator, so it shows everything useful. The snapshot is for sending to
 * someone else when asking for help, so it leaves out anything that identifies the database or
 * the server's folders: rows marked private never leave the screen. Credentials and the secret key
 * are never collected at all, so no later change to the snapshot can leak them by accident.
 */
final class SystemInformationService
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    public const STATUS_INFO = 'info';

    private const FOLDERS = [
        'config' => 'config',
        'storage/logs' => 'storage/logs',
        'storage/cache' => 'storage/cache',
        'storage/backups' => 'storage/backups',
        'storage/sessions' => 'storage/sessions',
        'content/uploads' => 'content/uploads',
    ];

    /**
     * @param array<string, mixed> $server
     * @param (Closure(string): (string|false))|null $iniGet replaceable so tests need not change real PHP settings
     * @param (Closure(string): bool)|null $extensionLoaded replaceable so tests can pretend an extension is missing
     */
    public function __construct(
        private readonly string $root,
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly array $server,
        private readonly ?Closure $iniGet = null,
        private readonly ?Closure $extensionLoaded = null,
        private readonly string $phpVersion = PHP_VERSION,
    ) {
    }

    /**
     * @return array<string, list<array{label: string, value: string, status: string, private: bool}>>
     */
    public function sections(): array
    {
        return [
            'Application' => $this->application(),
            'Server' => $this->serverRows(),
            'PHP limits' => $this->limits(),
            'PHP extensions' => $this->extensions(),
            'Database' => $this->databaseRows(),
            'Folders' => $this->folders(),
            'Security' => $this->security(),
        ];
    }

    /**
     * The same information without the private rows, shaped for JSON.
     *
     * @return array<string, array<string, string>>
     */
    public function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->sections() as $title => $rows) {
            foreach ($rows as $row) {
                if (!$row['private']) {
                    $snapshot[$title][$row['label']] = $row['value'];
                }
            }
        }

        return $snapshot;
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function application(): array
    {
        /** @var array{version: string} $version */
        $version = require $this->root . '/version.php';

        $stored = rtrim((string) $this->config->get('site_url', ''), '/');
        $detected = $this->detectedAddress();

        $rows = [
            $this->row('Lumora Fiction version', $version['version']),
            $this->row('Installed version', $this->text($this->config->setting('app.installed_version'), 'Unknown')),
            $this->row('Site address (stored)', $stored === '' ? 'Not set' : $stored, $stored === '' ? self::STATUS_ERROR : self::STATUS_OK),
        ];

        // The host header is supplied by the visitor, so it is shown for comparison only and never trusted.
        $rows[] = $this->row(
            'Site address (detected from this request)',
            $detected,
            $stored !== '' && $detected !== 'Unknown' && strcasecmp($detected, $stored) !== 0 ? self::STATUS_WARNING : self::STATUS_INFO,
        );
        $rows[] = $this->row('Debug mode', $this->config->get('debug', false) === true ? 'On' : 'Off', $this->config->get('debug', false) === true ? self::STATUS_WARNING : self::STATUS_OK);

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function serverRows(): array
    {
        $https = $this->isHttps();
        $uri = (string) ($this->server['REQUEST_URI'] ?? '');
        $software = $this->server['SERVER_SOFTWARE'] ?? null;

        return [
            $this->row('PHP version', $this->phpVersion, version_compare($this->phpVersion, Requirements::MINIMUM_PHP_VERSION, '<') ? self::STATUS_ERROR : self::STATUS_OK),
            $this->row('PHP interface', PHP_SAPI),
            $this->row('Operating system', PHP_OS_FAMILY),
            $this->row('Web server', is_string($software) && $software !== '' ? $this->printable($software) : 'Unknown'),
            $this->row('Secure connection (HTTPS)', $https ? 'Yes' : 'No', $https ? self::STATUS_OK : self::STATUS_WARNING),
            $this->row('Clean web addresses', str_contains($uri, 'index.php') ? 'Not confirmed' : 'Working', str_contains($uri, 'index.php') ? self::STATUS_WARNING : self::STATUS_OK),
            $this->row('Time zone', $this->text($this->config->setting('site.timezone'), 'UTC')),
            $this->row('Server time (UTC)', gmdate('Y-m-d H:i')),
        ];
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function limits(): array
    {
        $rows = [];

        foreach (['memory_limit' => 'Memory limit', 'max_execution_time' => 'Maximum run time (seconds)', 'upload_max_filesize' => 'Largest upload', 'post_max_size' => 'Largest form submission', 'max_input_vars' => 'Form fields per submission'] as $setting => $label) {
            $value = $this->ini($setting);
            $rows[] = $this->row($label, $value === false || $value === '' ? 'Unknown' : $value);
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function extensions(): array
    {
        $rows = [];

        foreach (Requirements::REQUIRED_EXTENSIONS as $extension) {
            $loaded = $this->loaded($extension);
            $rows[] = $this->row($extension . ' (required)', $loaded ? 'Enabled' : 'Missing', $loaded ? self::STATUS_OK : self::STATUS_ERROR);
        }

        foreach (Requirements::RECOMMENDED_EXTENSIONS as $extension) {
            $loaded = $this->loaded($extension);
            $rows[] = $this->row($extension . ' (recommended)', $loaded ? 'Enabled' : 'Not enabled', $loaded ? self::STATUS_OK : self::STATUS_INFO);
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function databaseRows(): array
    {
        try {
            $version = (string) $this->database->fetchColumn('SELECT VERSION()');
            $problem = Requirements::databaseVersionProblem($version);

            $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->database->prefix()) . '%';
            $tables = $this->database->pdo()->query('SHOW TABLES LIKE ' . $this->database->pdo()->quote($pattern))->fetchAll(\PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [$this->row('Database', 'Could not be queried', self::STATUS_ERROR)];
        }

        return [
            $this->row('Database server', $this->printable($version), $problem === null ? self::STATUS_OK : self::STATUS_ERROR),
            // The prefix and table count describe the database's layout, so they stay on the screen only.
            $this->row('Table prefix', $this->database->prefix(), self::STATUS_INFO, true),
            $this->row('Tables', (string) count($tables), self::STATUS_INFO),
        ];
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function folders(): array
    {
        $rows = [];

        foreach (self::FOLDERS as $label => $relative) {
            $path = $this->root . '/' . $relative;
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);

            $rows[] = $this->row($label, !$exists ? 'Missing' : ($writable ? 'Writable' : 'Not writable'), $writable ? self::STATUS_OK : self::STATUS_ERROR);
        }

        $log = $this->root . '/storage/logs/error.log';

        $rows[] = is_file($log)
            ? $this->row('Error log', sprintf('%s KB, last written %s UTC', number_format(((int) filesize($log)) / 1024, 1, '.', ''), gmdate('Y-m-d H:i', (int) filemtime($log))), self::STATUS_INFO)
            : $this->row('Error log', 'Empty', self::STATUS_OK);

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string, status: string, private: bool}>
     */
    private function security(): array
    {
        $installerPresent = is_dir($this->root . '/install');
        $configFile = $this->root . '/config/config.php';
        $mode = is_file($configFile) ? (int) (fileperms($configFile) & 0777) : null;
        $display = $this->ini('display_errors');

        return [
            $this->row('Installer folder', $installerPresent ? 'Still present, delete it' : 'Removed', $installerPresent ? self::STATUS_ERROR : self::STATUS_OK),
            $this->row(
                'Configuration file permissions',
                $mode === null ? 'Not found' : sprintf('%04o%s', $mode, ($mode & 0004) !== 0 ? ' (readable by every account on the server)' : ''),
                $mode === null ? self::STATUS_ERROR : (($mode & 0004) !== 0 ? self::STATUS_WARNING : self::STATUS_OK),
            ),
            $this->row('Debug mode', $this->config->get('debug', false) === true ? 'On, details are shown to visitors' : 'Off', $this->config->get('debug', false) === true ? self::STATUS_WARNING : self::STATUS_OK),
            $this->row('PHP shows errors on the page', in_array(strtolower((string) $display), ['1', 'on', 'true', 'stdout', 'stderr'], true) ? 'Yes' : 'No', in_array(strtolower((string) $display), ['1', 'on', 'true', 'stdout', 'stderr'], true) ? self::STATUS_WARNING : self::STATUS_OK),
            $this->row('HSTS (remember to use HTTPS)', $this->config->setting('security.hsts', false) === true ? 'On' : 'Off', self::STATUS_INFO),
        ];
    }

    private function detectedAddress(): string
    {
        $host = $this->server['HTTP_HOST'] ?? null;

        if (!is_string($host) || preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?\z/', $host) !== 1) {
            return 'Unknown';
        }

        return ($this->isHttps() ? 'https' : 'http') . '://' . $host . $this->baseDirectory();
    }

    private function baseDirectory(): string
    {
        $script = $this->server['SCRIPT_NAME'] ?? '';
        $directory = is_string($script) ? str_replace('\\', '/', dirname($script)) : '';

        return ($directory === '/' || $directory === '.' || preg_match('#^/[A-Za-z0-9._~/-]*\z#', $directory) !== 1) ? '' : rtrim($directory, '/');
    }

    private function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    private function ini(string $setting): string|false
    {
        return ($this->iniGet ?? ini_get(...))($setting);
    }

    private function loaded(string $extension): bool
    {
        return ($this->extensionLoaded ?? extension_loaded(...))($extension);
    }

    /**
     * Server-supplied text is reduced to printable characters so it cannot carry control codes.
     */
    private function printable(string $text): string
    {
        return mb_substr((string) preg_replace('/[^\P{C}]+/u', '', $text), 0, 120);
    }

    private function text(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $this->printable($value) : $fallback;
    }

    /**
     * @return array{label: string, value: string, status: string, private: bool}
     */
    private function row(string $label, string $value, string $status = self::STATUS_INFO, bool $private = false): array
    {
        return ['label' => $label, 'value' => $value, 'status' => $status, 'private' => $private];
    }
}
