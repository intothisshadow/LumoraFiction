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
     * @return array<string, mixed>
     */
    public function story(Story $story, string $author, array $terms, array $chapters, string $storyUrl, Closure $chapterUrl, bool $showState = false): array
    {
        $groups = [];

        foreach (TermType::cases() as $type) {
            if (isset($terms[$type->value]) && $terms[$type->value] !== []) {
                $groups[] = [
                    'label' => $type->pluralLabel(),
                    'type' => $type->value,
                    // There are no public pages for a term yet, so an empty address prints the name as plain text.
                    'terms' => array_map(static fn (Term $term): array => ['name' => $term->name, 'url' => ''], $terms[$type->value]),
                ];
            }
        }

        return [
            'story' => $this->storyFields($story, $author, $storyUrl),
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
     * @return array<string, mixed>
     */
    public function chapter(Story $story, string $author, Chapter $chapter, SafeHtml $html, ?Chapter $previous, ?Chapter $next, string $storyUrl, Closure $chapterUrl): array
    {
        $link = fn (?Chapter $other): ?array => $other === null ? null : ['title' => $this->chapterTitle($other), 'url' => $chapterUrl($other)];

        return [
            'story' => $this->storyFields($story, $author, $storyUrl),
            'chapter' => [
                'number' => $chapter->number,
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
     * @return array<string, mixed>
     */
    private function storyFields(Story $story, string $author, string $storyUrl): array
    {
        return [
            'id' => $story->id,
            'title' => $story->title,
            'url' => $storyUrl,
            'author' => $author,
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
