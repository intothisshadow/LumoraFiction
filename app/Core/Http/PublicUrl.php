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
