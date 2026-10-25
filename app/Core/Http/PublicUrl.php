<?php

/**
 * The addresses of the public archive pages, and the routes that serve them.
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

use LumoraFiction\Models\TermType;

/**
 * One place for the shape of a story, chapter, author or term address, so the router and every page that links
 * to one agree. Slugs are chosen by the application, never typed into an address by hand, and they never change
 * after a story or a term is created, so an address stays valid for as long as the page exists.
 */
final class PublicUrl
{
    public const STORY = '/story/{slug}';

    public const CHAPTER = '/story/{slug}/{number}';

    public const AUTHOR = '/author/{slug}';

    public const SEARCH = '/search';

    public const BROWSE = '/browse';

    public const BROWSE_PAGE = '/browse/{page}';

    public const NEWS = '/news';

    public const NEWS_POST = '/news/{slug}';

    public const SIGN_IN = '/account/sign-in';

    public const SIGN_OUT = '/account/sign-out';

    public const REGISTER = '/account/register';

    public const VERIFY = '/account/verify/{token}';

    public const RESEND = '/account/resend';

    public const FORGOT = '/account/forgot';

    public const RESET = '/account/reset/{token}';

    public const PROFILE = '/account/profile';

    public const READING = '/account/profile/reading';

    public const BOOKMARKS = '/account/bookmarks';

    public const BOOKMARK_ADD = '/account/bookmarks/add';

    public const BOOKMARK_REMOVE = '/account/bookmarks/remove';

    public const CONTINUE = '/account/continue';

    public const CONTINUE_FORGET = '/account/continue/forget';

    public const CONTINUE_CLEAR = '/account/continue/clear';

    public const READING_POSITION = '/account/reading/position';

    public const UPDATES = '/account/updates';

    public const UPDATES_FOLLOW = '/account/updates/follow';

    public const UPDATES_STOP = '/account/updates/stop';

    public const UPDATES_STOP_ALL = '/account/updates/stop-all';

    /**
     * The address of an account page, such as account('sign-in') or account('profile/email').
     */
    public static function account(string $page): string
    {
        return BasePath::get() . '/account/' . $page;
    }

    /**
     * The search page with the given values, leaving out the empty ones so the address stays short.
     *
     * @param array<string, string|int|null> $values
     */
    public static function search(array $values = []): string
    {
        $values = array_filter($values, static fn (string|int|null $value): bool => $value !== null && $value !== '');

        return BasePath::get() . self::SEARCH . ($values === [] ? '' : '?' . http_build_query($values, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * The kinds of term that have an index under /browse, keyed by the plural name used in the address.
     * Characters and relationships have none: there are too many, and they only make sense inside a fandom.
     *
     * @return array<string, TermType>
     */
    public static function browseKinds(): array
    {
        return [
            'fandoms' => TermType::Fandom,
            'genres' => TermType::Genre,
            'categories' => TermType::Category,
            'tags' => TermType::Tag,
            'ratings' => TermType::Rating,
            'warnings' => TermType::Warning,
            'relationship-categories' => TermType::RelationshipCategory,
        ];
    }

    public static function browseKind(string $segment): ?TermType
    {
        return self::browseKinds()[$segment] ?? null;
    }

    /**
     * A browse page, such as browse('tags') or browse('tags', ['letter' => 'B']); empty values are left out.
     *
     * @param array<string, string|int|null> $query
     */
    public static function browse(string $page = '', array $query = []): string
    {
        $query = array_filter($query, static fn (string|int|null $value): bool => $value !== null && $value !== '');

        return BasePath::get() . self::BROWSE . ($page === '' ? '' : '/' . $page) . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public static function news(): string
    {
        return BasePath::get() . self::NEWS;
    }

    public static function newsPost(string $slug): string
    {
        return BasePath::get() . '/news/' . rawurlencode($slug);
    }

    public static function story(string $slug): string
    {
        return BasePath::get() . '/story/' . rawurlencode($slug);
    }

    public static function chapter(string $slug, int $number): string
    {
        return self::story($slug) . '/' . $number;
    }

    public static function author(string $slug): string
    {
        return BasePath::get() . '/author/' . rawurlencode($slug);
    }

    public static function term(TermType $type, string $slug): string
    {
        return BasePath::get() . self::termPrefix($type) . '/' . rawurlencode($slug);
    }

    /**
     * The first path segment of a term kind, such as /fandom or /relationship-category.
     */
    public static function termPrefix(TermType $type): string
    {
        return '/' . str_replace('_', '-', $type->value);
    }

    /**
     * The route pattern for a kind of term.
     */
    public static function termPattern(TermType $type): string
    {
        return self::termPrefix($type) . '/{slug}';
    }
}
