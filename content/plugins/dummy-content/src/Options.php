<?php

/**
 * What a Dummy Content run should make, validated from the form.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

final class Options
{
    /** field => [label, default, minimum, maximum] */
    public const FIELDS = [
        'users' => ['Users', 20, 0, 5000],
        'fandoms' => ['Fandoms', 8, 0, 500],
        'tags' => ['Tags', 40, 0, 5000],
        'stories' => ['Stories', 50, 0, 100000],
        'max_chapters' => ['Most chapters in one story', 12, 1, 200],
        'words' => ['Average words per chapter', 400, 50, 3000],
        'bookmarks' => ['Bookmarks', 100, 0, 200000],
    ];

    public function __construct(
        public readonly int $users,
        public readonly int $fandoms,
        public readonly int $tags,
        public readonly int $stories,
        public readonly int $maxChapters,
        public readonly int $words,
        public readonly int $bookmarks,
        public readonly ?int $seed,
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public static function defaults(): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $definition) {
            $values[$field] = $definition[1];
        }

        return $values + ['seed' => ''];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{options: self|null, values: array<string, int|string>, errors: array<string, string>}
     */
    public static function fromInput(array $input): array
    {
        $values = [];
        $errors = [];

        foreach (self::FIELDS as $field => [$label, , $min, $max]) {
            $raw = trim((string) ($input[$field] ?? ''));
            $values[$field] = $raw;

            if (preg_match('/^[0-9]{1,7}\z/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
                $errors[$field] = $label . ' must be a whole number from ' . number_format($min) . ' to ' . number_format($max) . '.';
            }
        }

        $seed = trim((string) ($input['seed'] ?? ''));
        $values['seed'] = $seed;

        if ($seed !== '' && preg_match('/^[0-9]{1,9}\z/', $seed) !== 1) {
            $errors['seed'] = 'The seed must be a whole number, or empty for a different result every time.';
        }

        if ($errors !== []) {
            return ['options' => null, 'values' => $values, 'errors' => $errors];
        }

        return [
            'options' => new self(
                (int) $values['users'],
                (int) $values['fandoms'],
                (int) $values['tags'],
                (int) $values['stories'],
                (int) $values['max_chapters'],
                (int) $values['words'],
                (int) $values['bookmarks'],
                $seed === '' ? null : (int) $seed,
            ),
            'values' => $values,
            'errors' => [],
        ];
    }
}
