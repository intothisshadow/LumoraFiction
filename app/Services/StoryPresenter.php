<?php

/**
 * Builds the view data a theme receives for a story page and a chapter page.
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

use Closure;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;

/**
 * The one place that decides which fields of a story a theme may see. A preview and the public pages build their
 * data here, so they hand a theme exactly the same shape and a new field reaches every theme at once. Nothing
 * administrative is included: no owner id, no rejection reason, no login name.
 */
final class StoryPresenter
{
    /**
     * @param array<string, list<Term>> $terms the story's terms by kind
     * @param list<Chapter> $chapters
     * @param Closure(Chapter): string $chapterUrl
     * @param bool $showState whether to mark chapters that are not published, which only a preview does
     * @param (Closure(Term): string)|null $termUrl the address of a term's page; without it a term is printed as plain text
     * @param string $authorUrl the address of the author's page, or empty for none
     * @return array<string, mixed>
     */
    public function story(Story $story, string $author, array $terms, array $chapters, string $storyUrl, Closure $chapterUrl, bool $showState = false, ?Closure $termUrl = null, string $authorUrl = ''): array
    {
        $groups = [];

        foreach (TermType::cases() as $type) {
            if (isset($terms[$type->value]) && $terms[$type->value] !== []) {
                $groups[] = [
                    'label' => $type->pluralLabel(),
                    'type' => $type->value,
                    'terms' => array_map(static fn (Term $term): array => ['name' => $term->name, 'url' => $termUrl === null ? '' : $termUrl($term)], $terms[$type->value]),
                ];
            }
        }

        return [
            'story' => $this->storyFields($story, $author, $storyUrl, $authorUrl),
            'term_groups' => $groups,
            'chapters' => array_map(
                fn (Chapter $chapter): array => [
                    'number' => $chapter->number,
                    'title' => $this->chapterTitle($chapter),
                    'url' => $chapterUrl($chapter),
                    'words' => number_format($chapter->wordCount),
                    'note' => $showState && $chapter->state !== PublicationState::Published ? strtolower($chapter->state->label()) : '',
                ],
                $chapters,
            ),
        ];
    }

    /**
     * @param Closure(Chapter): string $chapterUrl
     * @param int $position which readable chapter this is, counting from one, or 0 when not known
     * @param int $total how many chapters the reader can read, or 0 when not known
     * @return array<string, mixed>
     */
    public function chapter(Story $story, string $author, Chapter $chapter, SafeHtml $html, ?Chapter $previous, ?Chapter $next, string $storyUrl, Closure $chapterUrl, int $position = 0, int $total = 0, string $authorUrl = ''): array
    {
        $link = fn (?Chapter $other): ?array => $other === null ? null : ['title' => $this->chapterTitle($other), 'url' => $chapterUrl($other)];

        return [
            'story' => $this->storyFields($story, $author, $storyUrl, $authorUrl),
            'chapter' => [
                'number' => $chapter->number,
                // Counted among the chapters a reader can open, so a hidden draft in the middle leaves no gap.
                'position' => $position,
                'total' => $total,
                'title' => $this->chapterTitle($chapter),
                'notes' => $chapter->notes,
                'words' => number_format($chapter->wordCount),
                'html' => $html,
            ],
            'previous' => $link($previous),
            'next' => $link($next),
        ];
    }

    public function chapterTitle(Chapter $chapter): string
    {
        return $chapter->title !== '' ? $chapter->title : 'Chapter ' . $chapter->number;
    }

    /**
     * One entry of a list of stories. These are the only keys a list template may rely on.
     *
     * @return array{title: string, url: string, author: string, author_url: string, summary: string, status: string, words: string, updated: string}
     */
    public function card(Story $story, string $author, string $authorUrl, string $storyUrl): array
    {
        return [
            'title' => $story->title,
            'url' => $storyUrl,
            'author' => $author,
            'author_url' => $authorUrl,
            'summary' => $story->summary,
            'status' => $story->workStatus->label(),
            'words' => number_format($story->wordCount),
            'updated' => $story->updatedAt->format('j F Y'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storyFields(Story $story, string $author, string $storyUrl, string $authorUrl = ''): array
    {
        return [
            'id' => $story->id,
            'title' => $story->title,
            'url' => $storyUrl,
            'author' => $author,
            'author_url' => $authorUrl,
            'summary' => $story->summary,
            'notes' => $story->notes,
            'disclaimer' => $story->disclaimer,
            'status' => $story->workStatus->label(),
            'words' => number_format($story->wordCount),
            'language' => strtoupper($story->language),
            // Empty until first published, so a theme can leave the line out with lf_has().
            'published' => $story->publishedAt?->format('j F Y') ?? '',
            'updated' => $story->updatedAt->format('j F Y'),
        ];
    }
}
