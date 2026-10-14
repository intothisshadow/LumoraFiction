<?php

/**
 * Validates the installer form and turns it into an install request.
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

use DateTimeZone;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Security\PasswordPolicy;
use LumoraFiction\Services\AccountRules;

/**
 * Pure: no database, no filesystem. Each field gets its own message so the form can point at it.
 */
final class InstallValidator
{
    public const ARCHIVE_MODES = ['both', 'fanfiction', 'original'];

    /** Login names that are the first thing anyone tries against an administrator account. */
    private const GUESSABLE_LOGINS = ['admin', 'administrator', 'root', 'webmaster', 'sysadmin', 'owner', 'user', 'test', 'demo', 'editor', 'moderator', 'support'];

    /**
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, request: InstallRequest|null}
     */
    public function validate(array $input): array
    {
        $text = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        // Passwords are taken exactly as typed: spaces at either end can be part of a real password.
        $raw = static fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';

        $errors = [];

        $host = $text('db_host');
        $name = $text('db_name');
        $user = $text('db_user');
        $port = $text('db_port') === '' ? '3306' : $text('db_port');
        $prefix = $text('table_prefix');

        // The host and database name end up inside the connection string, where ";" or "=" would let
        // a crafted value add driver options, so they are refused here as well as at connection time.
        if ($host === '' || preg_match('/[;=\x00-\x1f]/', $host) === 1) {
            $errors['db_host'] = 'Enter the database host, for example localhost.';
        }

        if (preg_match('/^\d{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            $errors['db_port'] = 'Enter a port number between 1 and 65535.';
        }

        if ($name === '' || preg_match('/[;=\x00-\x1f]/', $name) === 1) {
            $errors['db_name'] = 'Enter the name of the database.';
        }

        if ($user === '') {
            $errors['db_user'] = 'Enter the database user name.';
        }

        if (($problem = TablePrefix::problem($prefix)) !== null) {
            $errors['table_prefix'] = $problem;
        }

        $siteName = trim((string) preg_replace('/\s+/u', ' ', $text('site_name')));
        $siteUrl = rtrim($text('site_url'), '/');
        $timezone = $text('timezone');
        $mode = $text('archive_mode');

        if ($siteName === '' || mb_strlen($siteName) > 100 || preg_match('/[\x00-\x1f\x7f\p{Cc}]/u', $siteName) === 1) {
            $errors['site_name'] = 'Enter a site name of up to 100 characters.';
        }

        // Emailed links are built from this value, so it must be a plain web address.
        if (preg_match('#^https?://[^\s/?\\#]+(/[^\s?\\#]*)?\z#', $siteUrl) !== 1) {
            $errors['site_url'] = 'Enter the full web address of the site, starting with http:// or https://.';
        }

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a time zone from the list.';
        }

        if (!in_array($mode, self::ARCHIVE_MODES, true)) {
            $errors['archive_mode'] = 'Choose what the site will publish.';
        }

        $login = $text('admin_login_name');
        $display = AccountRules::normalizeDisplayName($text('admin_display_name'));
        $email = AccountRules::normalizeEmail($text('admin_email'));
        $password = $raw('admin_password');

        if (($problem = AccountRules::loginNameProblem($login)) !== null) {
            $errors['admin_login_name'] = $problem;
        } elseif (in_array(strtolower($login), self::GUESSABLE_LOGINS, true)) {
            $errors['admin_login_name'] = 'That login name is too easy to guess. Choose something less obvious.';
        }

        if (($problem = AccountRules::displayNameProblem($display)) !== null) {
            $errors['admin_display_name'] = $problem;
        } elseif (!isset($errors['admin_login_name']) && ($problem = AccountRules::distinctnessProblem($login, $display)) !== null) {
            $errors['admin_login_name'] = $problem;
        }

        if (($problem = AccountRules::emailProblem($email)) !== null) {
            $errors['admin_email'] = $problem;
        }

        if (($problem = (new PasswordPolicy(new Configuration('/unused')))->violation($password, [$login, $display, $email])) !== null) {
            $errors['admin_password'] = $problem;
        } elseif ($password !== $raw('admin_password_confirm')) {
            $errors['admin_password_confirm'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'request' => null];
        }

        return [
            'errors' => [],
            'request' => new InstallRequest($host, (int) $port, $name, $user, $raw('db_password'), $prefix, $siteName, $siteUrl, $timezone, $mode, $login, $display, $email, $password),
        ];
    }
}
