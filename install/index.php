<?php

/**
 * The installer: checks the server, then creates the database tables, the first administrator, and the config file.
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

use LumoraFiction\Core\Autoloader;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Security\ContentSecurityPolicy;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Security\SecurityHeaders;
use LumoraFiction\Core\Security\SessionManager;
use LumoraFiction\Installer\InstallException;
use LumoraFiction\Installer\InstallerCleanup;
use LumoraFiction\Installer\InstallValidator;
use LumoraFiction\Installer\Installer;
use LumoraFiction\Installer\RequirementsCheck;
use LumoraFiction\Installer\TablePrefix;
use LumoraFiction\Services\ClassificationPresets;

$root = dirname(__DIR__);

require $root . '/app/Core/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('LumoraFiction\\Installer', __DIR__ . '/src');
$autoloader->addNamespace('LumoraFiction', $root . '/app');
$autoloader->register();

$errorHandler = new ErrorHandler($root . '/storage/logs');
$errorHandler->register();

// Links and asset paths come from where this script actually lives, so a subdirectory install works.
$baseUrl = BasePath::detect(['SCRIPT_NAME' => dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))]);
$installUrl = $baseUrl . '/install/';
$isHttps = SecurityHeaders::isHttps($_SERVER);

SecurityHeaders::send(SecurityHeaders::headers($isHttps, false));
(new ContentSecurityPolicy())->send();

// Pages that carry a form token or a password must never be stored by a browser or proxy.
if (!headers_sent()) {
    header('Cache-Control: no-store');
}

if (is_file($root . '/config/config.php')) {
    require __DIR__ . '/views/already-installed.php';

    exit;
}

$requirementProblems = (new RequirementsCheck($root))->check();

if ($requirementProblems !== []) {
    require __DIR__ . '/views/requirements.php';

    exit;
}

// File-based, in a folder the application owns: no database exists yet, and only a form token and
// the suggested table prefix are ever kept in it.
$sessions = new SessionManager($isHttps, 'lumora_fiction_install', null, 3600, $root . '/storage/sessions');
$sessions->start();
Csrf::useSessions($sessions);

if (!isset($_SESSION['install_prefix']) || !is_string($_SESSION['install_prefix'])) {
    $_SESSION['install_prefix'] = TablePrefix::generate();
}

$suggestedPrefix = $_SESSION['install_prefix'];

// The starter lists are read from files, so the form can offer them before any database exists.
$presetCatalogue = ClassificationPresets::catalogueFromFiles($root . '/app/Data/presets');
$errors = [];
$formErrors = [];
$values = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $values = array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $_POST);

    if (!Csrf::verify('install', is_string($token) ? $token : null)) {
        $formErrors[] = 'Your session expired. Please check the details and try again.';
    } else {
        $result = (new InstallValidator(array_map(static fn (array $presets): array => array_keys($presets), $presetCatalogue)))->validate($_POST);
        $errors = $result['errors'];

        if ($result['request'] !== null) {
            try {
                (new Installer($root, $errorHandler))->install($result['request']);

                $sessions->destroy();

                // Rendered before cleanup because removing install/ removes the views too. That is
                // safe on Unix while this process still has them open.
                ob_start();
                require __DIR__ . '/views/success.php';
                $successPage = (string) ob_get_clean();

                $cleanupNote = (new InstallerCleanup())->remove(__DIR__)
                    ? '<p class="notice notice--success">The installer files were removed automatically.</p>'
                    : '<p class="notice notice--error" role="alert">The installer files could not be removed automatically. For security, delete the <code>install</code> folder yourself.</p>';

                echo str_replace('<!-- INSTALLER_CLEANUP_STATUS -->', $cleanupNote, $successPage);

                exit;
            } catch (InstallException $exception) {
                $formErrors = $exception->messages;
            }
        }
    }
}

$timezones = DateTimeZone::listIdentifiers();
$detectedHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$detectedSiteUrl = ($isHttps ? 'https' : 'http') . '://'
    . (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?\z/', $detectedHost) === 1 ? $detectedHost : 'localhost')
    . $baseUrl;

require __DIR__ . '/views/form.php';
