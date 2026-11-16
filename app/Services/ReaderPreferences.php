<?php

/**
 * A reader's colour scheme, text size and reading width, kept in the account.
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

use LumoraFiction\Core\Database\Database;

/**
 * The browser keeps the same three display choices for everyone, so a guest gets them without an account. This class is
 * the account's copy, which follows a reader to another device, plus the one choice that only an account can have,
 * whether to be told when a followed story changes. A stored value that is no longer a known choice is dropped, so a
 * removed option cannot leave a reader with a broken page.
 */
final class ReaderPreferences
{
    /** Preference => allowed values. The first is the neutral value, which means "no choice made". */
    public const CHOICES = [
        'scheme' => ['system', 'light', 'dark'],
        'size' => ['default', 'small', 'large', 'xlarge'],
        'width' => ['default', 'narrow', 'wide'],
        'updates' => ['off', 'on'],
        // How a story with several chapters opens: chapter by chapter, or all on one page.
        'view' => ['chapters', 'whole'],
        // The distraction-free layout of a chapter, which only the reader script switches.
        'reader' => ['off', 'on'],
    ];

    public const LABELS = [
        'scheme' => ['system' => 'Match my device', 'light' => 'Light', 'dark' => 'Dark'],
        'size' => ['default' => 'Default', 'small' => 'Small', 'large' => 'Large', 'xlarge' => 'Extra large'],
        'width' => ['default' => 'Default', 'narrow' => 'Narrow', 'wide' => 'Wide'],
        'updates' => ['off' => 'No, do not tell me', 'on' => 'Yes, tell me'],
        'view' => ['chapters' => 'Chapter by chapter', 'whole' => 'The whole story on one page'],
        'reader' => ['off' => 'Off', 'on' => 'On'],
    ];

    /**
     * Whether the reader asked to be told about changes to the stories they follow. Off until they say otherwise.
     */
    public function wantsUpdates(int $userId): bool
    {
        return ($this->forUser($userId)['updates'] ?? 'off') === 'on';
    }

    /** A theme is named by its folder, a plain slug. Whether it exists, is offered and fits the site is decided when it is used. */
    private const THEME_PATTERN = '/^[a-z][a-z0-9-]{0,49}\z/';

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * The theme this account chose, or null when it has not (or the stored name is not a plain slug).
     */
    public function theme(int $userId): ?string
    {
        return $this->forUser($userId)['theme'] ?? null;
    }

    /**
     * Saves or clears the account's theme without touching its other choices, which the reading form and script own.
     */
    public function saveTheme(int $userId, ?string $slug): void
    {
        $saved = $this->forUser($userId);

        if ($slug !== null && preg_match(self::THEME_PATTERN, $slug) === 1) {
            $saved['theme'] = $slug;
        } else {
            unset($saved['theme']);
        }

        $this->database->execute(
            'UPDATE {prefix}users SET reader_preferences = :value WHERE id = :id',
            ['value' => $saved === [] ? null : json_encode($saved, JSON_THROW_ON_ERROR), 'id' => $userId],
        );
    }

    /**
     * The saved choices, or an empty array when the account has none.
     *
     * @return array<string, string> the display choices, the follow-updates choice and, when the account chose one, its theme
     */
    public function forUser(int $userId): array
    {
        $stored = $this->database->fetchColumn('SELECT reader_preferences FROM {prefix}users WHERE id = :id', ['id' => $userId]);

        return is_string($stored) ? self::clean(json_decode($stored, true)) : [];
    }

    /**
     * Saves the choices that are valid and ignores the rest. Returns what was stored.
     *
     * @return array<string, string>
     */
    public function save(int $userId, mixed $input): array
    {
        $clean = self::clean($input);

        // The reading form and script know nothing of the theme, so saving them must not wipe it.
        if (!is_array($input) || !array_key_exists('theme', $input)) {
            $theme = $this->theme($userId);

            if ($theme !== null) {
                $clean['theme'] = $theme;
            }
        }

        $this->database->execute(
            'UPDATE {prefix}users SET reader_preferences = :value WHERE id = :id',
            ['value' => $clean === [] ? null : json_encode($clean, JSON_THROW_ON_ERROR), 'id' => $userId],
        );

        return $clean;
    }

    /**
     * Keeps only known preferences with known values.
     *
     * @return array<string, string>
     */
    public static function clean(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $clean = [];

        foreach (self::CHOICES as $name => $allowed) {
            $value = $input[$name] ?? null;

            if (is_string($value) && in_array($value, $allowed, true)) {
                $clean[$name] = $value;
            }
        }

        $theme = $input['theme'] ?? null;

        if (is_string($theme) && preg_match(self::THEME_PATTERN, $theme) === 1) {
            $clean['theme'] = $theme;
        }

        return $clean;
    }
}
