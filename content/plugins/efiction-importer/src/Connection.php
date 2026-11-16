<?php

/**
 * The connection details of one import, held in the signed-in administrator's server-side session and nowhere else.
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

use LumoraFiction\Core\Security\SessionManager;

/**
 * The password is never written to a file, a setting, the log, a page or an address: it lives in the session until the run ends
 * or the administrator signs out, and the form never shows it back.
 */
final class Connection
{
    private const KEY = 'efiction_importer';

    public const FIELDS = ['host', 'port', 'name', 'user', 'password', 'prefix', 'folder', 'stories'];

    public function __construct(private readonly SessionManager $sessions)
    {
    }

    /**
     * @return array{host: string, port: int, name: string, user: string, password: string, prefix: string, folder: string, stories: string}|null
     */
    public function get(): ?array
    {
        $this->open();
        $saved = $_SESSION[self::KEY] ?? null;

        if (!is_array($saved) || !isset($saved['host'], $saved['name'], $saved['user'])) {
            return null;
        }

        return [
            'host' => (string) $saved['host'],
            'port' => (int) ($saved['port'] ?? 3306),
            'name' => (string) $saved['name'],
            'user' => (string) $saved['user'],
            'password' => (string) ($saved['password'] ?? ''),
            'prefix' => (string) ($saved['prefix'] ?? ''),
            'folder' => (string) ($saved['folder'] ?? ''),
            'stories' => (string) ($saved['stories'] ?? ''),
        ];
    }

    /**
     * What the form may show again: everything but the password, and whether one is held.
     *
     * @return array{host: string, port: string, name: string, user: string, prefix: string, folder: string, stories: string, has_password: bool}
     */
    public function formValues(): array
    {
        $saved = $this->get();

        return [
            'host' => $saved['host'] ?? 'localhost',
            'port' => (string) ($saved['port'] ?? 3306),
            'name' => $saved['name'] ?? '',
            'user' => $saved['user'] ?? '',
            'prefix' => $saved['prefix'] ?? 'fanfiction_',
            'folder' => $saved['folder'] ?? '',
            'stories' => $saved['stories'] ?? '',
            'has_password' => ($saved['password'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, int|string> $values
     */
    public function put(array $values): void
    {
        $this->open();
        $_SESSION[self::KEY] = array_intersect_key($values, array_flip(self::FIELDS));
    }

    public function forget(): void
    {
        $this->open();
        unset($_SESSION[self::KEY]);
    }

    private function open(): void
    {
        if (!$this->sessions->isActive()) {
            $this->sessions->start();
        }
    }
}
