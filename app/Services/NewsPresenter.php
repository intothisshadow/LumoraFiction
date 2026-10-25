<?php

/**
 * Builds the data a theme receives for news: the home block, the archive and a single post.
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

use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Models\NewsPost;

/**
 * The one place that decides which fields of a news post a theme may see, so a preview and the public pages hand a
 * theme the same shape. Only the author's display name is included: no account id, no login name, no email.
 */
final class NewsPresenter
{
    /** Longest excerpt shown on a card, in characters. */
    private const EXCERPT_MAX = 240;

    public function __construct(
        private readonly ContentRenderer $renderer,
        private readonly UserService $users,
    ) {
    }

    /**
     * A card for a list. The excerpt is plain text, so a theme prints it with lf_e() like any other value.
     *
     * @return array{title: string, url: string, date: string, datetime: string, author: string, excerpt: string, pinned: string}
     */
    public function card(NewsPost $post, string $author, string $url): array
    {
        return [
            'title' => $post->title,
            'url' => $url,
            'date' => $this->date($post),
            'datetime' => ($post->publishedAt ?? $post->createdAt)->format('Y-m-d'),
            'author' => $author,
            'excerpt' => $this->excerpt($post),
            'pinned' => $post->pinned ? 'Pinned' : '',
        ];
    }

    /**
     * @param list<NewsPost> $posts
     * @param callable(NewsPost): string $urlFor
     * @return list<array{title: string, url: string, date: string, datetime: string, author: string, excerpt: string, pinned: string}>
     */
    public function cards(array $posts, callable $urlFor): array
    {
        $names = $this->users->displayNames(array_map(static fn (NewsPost $post): int => $post->authorId, $posts));

        return array_map(fn (NewsPost $post): array => $this->card($post, $names[$post->authorId] ?? '', $urlFor($post)), $posts);
    }

    /**
     * The home page block: the cards and the address of the full list, or an empty array when there is nothing to show.
     *
     * @param list<NewsPost> $posts
     * @return array{posts: list<array<string, string>>, url: string}|array{}
     */
    public function block(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        return ['posts' => $this->cards($posts, static fn (NewsPost $post): string => PublicUrl::newsPost($post->slug)), 'url' => PublicUrl::news()];
    }

    /**
     * @return array{title: string, url: string, date: string, datetime: string, author: string, pinned: string, body: SafeHtml, list_url: string}
     */
    public function single(NewsPost $post, string $url): array
    {
        $names = $this->users->displayNames([$post->authorId]);

        return [
            'title' => $post->title,
            'url' => $url,
            'date' => $this->date($post),
            'datetime' => ($post->publishedAt ?? $post->createdAt)->format('Y-m-d'),
            'author' => $names[$post->authorId] ?? '',
            'pinned' => $post->pinned ? 'Pinned' : '',
            'body' => new SafeHtml($this->renderer->render($post->content, $post->format)),
            'list_url' => PublicUrl::news(),
        ];
    }

    private function date(NewsPost $post): string
    {
        return ($post->publishedAt ?? $post->createdAt)->format('j F Y');
    }

    private function excerpt(NewsPost $post): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', $this->renderer->render($post->content, $post->format))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text, 'UTF-8') > self::EXCERPT_MAX ? rtrim(mb_substr($text, 0, self::EXCERPT_MAX, 'UTF-8')) . '…' : $text;
    }
}
