<?php

/**
 * Which cached pages a recorded change makes stale.
 *
 * @package LumoraFiction
 * @subpackage Cache
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Cache;

use LumoraFiction\Core\Database\Database;

/**
 * Every change to the site is written to the audit log, so the log's action names are the one list of what can change.
 * A story or chapter empties its own page and the listings; the news empties the news pages and the listings; anything
 * that can alter how every page looks (settings, classification, pages and menus, themes, plugins, an account's name)
 * empties everything. An action nobody has classified empties everything too, so a new kind of change can leave a stale
 * page behind at worst by being over-careful, never by being forgotten.
 */
final class PurgeRules
{
    public const ALL = 'all';

    public const LISTINGS = 'listings';

    public const NEWS = 'news';

    /** Changes that never alter what a visitor sees. */
    private const IGNORED = ['backup', 'alert', 'privacy', 'update.started', 'update.cancel_requested', 'plugin.load_failed', 'account.password', 'account.email'];

    public function __construct(private readonly Database $database)
    {
    }

    public static function storyTag(string $slug): string
    {
        return 'story-' . strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $slug));
    }

    public static function pageTag(string $slug): string
    {
        return 'page-' . strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $slug));
    }

    /**
     * @return list<string> tags to empty, or [self::ALL] for everything; an empty list means nothing changed for visitors
     */
    public function scopeFor(string $action, ?string $targetType, ?string $targetId): array
    {
        foreach (self::IGNORED as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return [];
            }
        }

        $family = strtok($action, '.');

        return match ($family) {
            'story', 'chapter', 'comment' => $this->forStoryChange($family, $targetType, $targetId),
            'news' => [self::NEWS, self::LISTINGS],
            default => [self::ALL],
        };
    }

    /**
     * @return list<string>
     */
    private function forStoryChange(string $family, ?string $targetType, ?string $targetId): array
    {
        $id = $targetId !== null && ctype_digit($targetId) ? (int) $targetId : 0;
        $slug = $id < 1 ? null : match ($targetType) {
            'story' => $this->database->fetchColumn('SELECT slug FROM {prefix}stories WHERE id = :id', ['id' => $id]),
            'chapter' => $this->database->fetchColumn('SELECT s.slug FROM {prefix}chapters c JOIN {prefix}stories s ON s.id = c.story_id WHERE c.id = :id', ['id' => $id]),
            'comment' => $this->database->fetchColumn('SELECT s.slug FROM {prefix}comments m JOIN {prefix}chapters c ON c.id = m.chapter_id JOIN {prefix}stories s ON s.id = c.story_id WHERE m.id = :id', ['id' => $id]),
            default => null,
        };

        // A comment on a story the lookup cannot find (a comment account, for example) concerns no page of a story.
        if (!is_string($slug) || $slug === '') {
            return $family === 'comment' && $targetType !== 'comment' ? [] : [self::ALL];
        }

        // Comments show on the chapter page only, and never in a listing.
        return $family === 'comment' ? [self::storyTag($slug)] : [self::storyTag($slug), self::LISTINGS];
    }
}
