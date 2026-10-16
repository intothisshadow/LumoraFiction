<?php

/**
 * One-time messages kept in the session across a redirect, such as "Settings saved".
 *
 * @package LumoraFiction
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Http;

use InvalidArgumentException;
use LumoraFiction\Core\Security\SessionManager;

/**
 * Messages are stored as plain text and escaped when printed, so nothing a visitor typed can become markup.
 */
final class FlashMessages
{
    private const SESSION_KEY = '_flash';

    private const TYPES = ['success', 'error', 'warning', 'info'];

    public function __construct(private readonly SessionManager $sessions)
    {
    }

    public function add(string $type, string $message): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown message type.');
        }

        $this->sessions->start();

        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Returns the waiting messages and forgets them, so each is shown once.
     *
     * @param array<string, mixed> $cookies
     * @return list<array{type: string, message: string}>
     */
    public function pull(array $cookies): array
    {
        if (!$this->sessions->startIfExisting($cookies)) {
            return [];
        }

        $stored = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);

        $messages = [];

        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (is_array($entry) && is_string($entry['type'] ?? null) && in_array($entry['type'], self::TYPES, true) && is_string($entry['message'] ?? null)) {
                $messages[] = ['type' => $entry['type'], 'message' => $entry['message']];
            }
        }

        return $messages;
    }
}
