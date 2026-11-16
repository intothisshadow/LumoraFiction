<?php

/**
 * Whether a chaptered story may be read as one page, and how many words that page may hold.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Download\PdfBuilder;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * The whole-story page prints every chapter in one response, so its length is capped: a very long story on a small
 * shared host would otherwise be built in memory in one go. The default is the same word limit as a PDF download,
 * which is a limit already tuned for the same reason. The readers are static so the router, the story pages and the
 * links can ask without holding a settings object; a damaged value falls back to the default.
 */
final class WholeStorySettings
{
    public const DEFAULTS = [
        'reading.whole_story' => true,
        'reading.whole_story_words' => PdfBuilder::MAX_WORDS,
    ];

    public const MIN_WORDS = 5000;

    public const MAX_WORDS = 500000;

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /** On unless switched off for real, so a missing value keeps the feature available. */
    public static function enabled(Configuration $config): bool
    {
        return $config->setting('reading.whole_story') !== false;
    }

    public static function wordLimit(Configuration $config): int
    {
        $value = $config->setting('reading.whole_story_words');

        return is_int($value) && $value >= self::MIN_WORDS && $value <= self::MAX_WORDS ? $value : PdfBuilder::MAX_WORDS;
    }

    /**
     * @return array{whole_story: bool, whole_story_words: int}
     */
    public function current(): array
    {
        return ['whole_story' => self::enabled($this->config), 'whole_story_words' => self::wordLimit($this->config)];
    }

    /**
     * @param array{whole_story: bool, whole_story_words: string} $input
     * @return array{errors: array<string, string>, values: array{whole_story: bool, whole_story_words: int}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $words = self::wordLimit($this->config);
        $typed = trim($input['whole_story_words']);

        if (preg_match('/^[0-9]{1,7}\z/', $typed) === 1 && (int) $typed >= self::MIN_WORDS && (int) $typed <= self::MAX_WORDS) {
            $words = (int) $typed;
        } else {
            $errors['whole_story_words'] = 'Enter a whole number of words from ' . number_format(self::MIN_WORDS) . ' to ' . number_format(self::MAX_WORDS) . '.';
        }

        return ['errors' => $errors, 'values' => ['whole_story' => $input['whole_story'], 'whole_story_words' => $words]];
    }

    /**
     * @param array{whole_story: bool, whole_story_words: int} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['whole_story' => 'reading.whole_story', 'whole_story_words' => 'reading.whole_story_words'] as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            $this->audit->record($actor->id, 'settings.reading.update', 'settings', 'reading', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
