<?php

/**
 * Which feeds the site offers, in which forms and how much each carries.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;

/**
 * Everything reads the values through the static helpers, so a damaged setting falls back to the default instead of
 * switching a feed on or off by accident.
 */
final class FeedSettings
{
    public const DEFAULT_LIMIT = 20;

    public const MIN_LIMIT = 5;

    public const MAX_LIMIT = 50;

    /** Kind => label for the settings screen, in the order they are listed there. */
    public const KINDS = [
        'site' => 'The whole site: recently published and updated stories',
        'author' => 'Each author: their stories',
        'fandom' => 'Each fandom',
        'category' => 'Each category',
        'character' => 'Each character',
        'relationship' => 'Each relationship',
        'genre' => 'Each genre',
        'tag' => 'Each tag',
        'series' => 'Each series',
        'collection' => 'Each collection',
        'story' => 'Each story: its new chapters',
        'comments' => 'Each story: its comments',
        'news' => 'News posts',
    ];

    public const DEFAULTS = [
        'feeds.kinds' => ['site', 'author', 'fandom', 'category', 'character', 'relationship', 'genre', 'tag', 'series', 'collection', 'story', 'news'],
        'feeds.json' => true,
        'feeds.limit' => self::DEFAULT_LIMIT,
        'feeds.full_text' => true,
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The kinds that are ticked, in the fixed order of KINDS, whatever the archive mode: a kind the mode hides keeps its
     * tick, so switching the mode back restores it. A name this version does not know is ignored.
     *
     * @return list<string>
     */
    public static function kinds(Configuration $config): array
    {
        $chosen = $config->setting('feeds.kinds', self::DEFAULTS['feeds.kinds']);
        $chosen = is_array($chosen) ? $chosen : self::DEFAULTS['feeds.kinds'];

        return array_values(array_filter(array_keys(self::KINDS), static fn (string $kind): bool => in_array($kind, $chosen, true)));
    }

    /**
     * Whether the archive mode allows this kind at all. A feed of a kind of term follows the term's own rule (original
     * fiction has no fandoms, fanfiction has no categories); the other kinds are open in every mode.
     */
    public static function modeAllows(Configuration $config, string $kind): bool
    {
        $type = TermType::tryFrom($kind);

        return $type === null || $type->availableIn(ArchiveSettings::modeOf($config));
    }

    /**
     * The kinds the settings screen lists: the ones the archive mode and the series and collections switches have not turned off.
     *
     * @return list<string>
     */
    public static function listedKinds(Configuration $config): array
    {
        return array_values(array_filter(array_keys(self::KINDS), static fn (string $kind): bool => self::available($config, $kind)));
    }

    public static function kindEnabled(Configuration $config, string $kind): bool
    {
        return self::available($config, $kind) && in_array($kind, self::kinds($config), true);
    }

    /**
     * The one rule for a kind the site cannot offer at all: the archive mode has no such kind, or the feature it is about
     * (series, collections) is switched off. What was ticked for it is kept.
     */
    private static function available(Configuration $config, string $kind): bool
    {
        return self::modeAllows($config, $kind) && GroupingSettings::kindOn($config, $kind);
    }

    /**
     * Whether a feed of this kind is really served: switched on, and not hiding stories an archive closed to visitors
     * keeps behind its sign-in page. A feed reader cannot sign in, so such an archive offers no story feed at all. News
     * is open to everyone even then, as the news page is.
     */
    public static function offered(Configuration $config, string $kind): bool
    {
        if ($kind !== 'news' && $config->setting('reading.require_signin') === true) {
            return false;
        }

        return self::kindEnabled($config, $kind);
    }

    public static function jsonEnabled(Configuration $config): bool
    {
        return $config->setting('feeds.json', true) !== false;
    }

    public static function limit(Configuration $config): int
    {
        $value = $config->setting('feeds.limit', self::DEFAULT_LIMIT);

        return is_int($value) ? max(self::MIN_LIMIT, min(self::MAX_LIMIT, $value)) : self::DEFAULT_LIMIT;
    }

    /** Whether a chapter's whole text goes into its entry, or only the opening of it. */
    public static function fullText(Configuration $config): bool
    {
        return $config->setting('feeds.full_text', true) !== false;
    }

    /**
     * @return array{kinds: list<string>, json: bool, limit: int, full_text: bool}
     */
    public function current(): array
    {
        return [
            'kinds' => self::kinds($this->config),
            'json' => self::jsonEnabled($this->config),
            'limit' => self::limit($this->config),
            'full_text' => self::fullText($this->config),
        ];
    }

    /**
     * @param list<string> $kinds the kind names that were ticked
     * @return array{errors: array<string, string>, values: array{kinds: list<string>, json: bool, limit: int, full_text: bool}}
     */
    public function validate(array $kinds, bool $json, string $limit, bool $fullText): array
    {
        // Kept in the fixed order whatever order the browser sent them in. A kind the archive mode hides is not on the
        // form, so what was saved for it stays and a hand-made post cannot change it.
        $stored = self::kinds($this->config);
        $chosen = array_values(array_filter(
            array_keys(self::KINDS),
            fn (string $kind): bool => in_array($kind, self::available($this->config, $kind) ? $kinds : $stored, true),
        ));
        $errors = [];
        $number = filter_var(trim($limit), FILTER_VALIDATE_INT);

        if ($number === false || $number < self::MIN_LIMIT || $number > self::MAX_LIMIT) {
            $errors['limit'] = 'Enter a whole number from ' . self::MIN_LIMIT . ' to ' . self::MAX_LIMIT . '.';
            $number = self::limit($this->config);
        }

        return ['errors' => $errors, 'values' => ['kinds' => $chosen, 'json' => $json, 'limit' => $number, 'full_text' => $fullText]];
    }

    /**
     * @param array{kinds: list<string>, json: bool, limit: int, full_text: bool} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['kinds' => 'feeds.kinds', 'json' => 'feeds.json', 'limit' => 'feeds.limit', 'full_text' => 'feeds.full_text'] as $field => $setting) {
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

            $this->audit->record($actor->id, 'settings.feeds.update', 'settings', 'feeds', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
