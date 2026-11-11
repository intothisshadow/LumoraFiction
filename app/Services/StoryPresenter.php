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
use LumoraFiction\Core\Media\CoverImageStore;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\WorkStatus;

/**
 * The one place that decides which fields of a story a theme may see. A preview and the public pages build their
 * data here, so they hand a theme exactly the same shape and a new field reaches every theme at once. Nothing
 * administrative is included: no owner id, no rejection reason, no login name.
 */
final class StoryPresenter
{
    private readonly ClassificationLabels $labels;

    public function __construct(private readonly ?CoverImageStore $covers = null, ?ClassificationLabels $labels = null)
    {
        $this->labels = $labels ?? new ClassificationLabels();
    }

    /**
     * The addresses of a story's cover, or empty strings when it has none. The keys are always present so a theme
     * can test one with lf_has() and never has to guard against a missing array.
     *
     * @return array{thumb: string, full: string, alt: string}
     */
    public function cover(Story $story): array
    {
        if (!$story->hasCover() || $this->covers === null || !$this->covers->exists($story->coverFile)) {
            return ['thumb' => '', 'full' => '', 'alt' => ''];
        }

        return [
            // A missing thumbnail falls back to the full picture rather than a broken image.
            'thumb' => $this->covers->url($story->coverFile, $this->covers->exists($story->coverFile, true)),
            'full' => $this->covers->url($story->coverFile),
            'alt' => $story->coverAlt,
        ];
    }

    /**
     * @param array<string, list<Term>> $terms the story's terms by kind
     * @param list<Chapter> $chapters
     * @param Closure(Chapter): string $chapterUrl
     * @param bool $showState whether to mark chapters that are not published, which only a preview does
     * @param (Closure(Term): string)|null $termUrl the address of a term's page; without it a term is printed as plain text
     * @param string $authorUrl the address of the author's page, or empty for none
     * @param (Closure(Chapter): string)|null $chapterEditUrl the admin address of a chapter, given only when the viewer may change the story; otherwise every chapter's edit_url is empty
     * @return array<string, mixed>
     */
    public function story(Story $story, string $author, array $terms, array $chapters, string $storyUrl, Closure $chapterUrl, bool $showState = false, ?Closure $termUrl = null, string $authorUrl = '', ?Closure $chapterEditUrl = null): array
    {
        $groups = [];

        foreach (TermType::cases() as $type) {
            if (isset($terms[$type->value]) && $terms[$type->value] !== []) {
                $groups[] = [
                    'label' => $this->labels->plural($type),
                    'type' => $type->value,
                    'terms' => array_map(static fn (Term $term): array => ['name' => $term->name, 'url' => $termUrl === null ? '' : $termUrl($term)], $terms[$type->value]),
                ];
            } elseif ($type === TermType::Rating) {
                $groups[] = $this->notRated($type);
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
                    // Empty, not hidden, for everyone else: the address is absent from their markup.
                    'edit_url' => $chapterEditUrl === null ? '' : $chapterEditUrl($chapter),
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

        $storyFields = $this->storyFields($story, $author, $storyUrl, $authorUrl);
        // The story's closing note belongs after the last chapter of a finished story. The rule lives here so
        // every theme prints the same thing by simply asking for story.end_notes.
        $storyFields['end_notes'] = $next === null && $story->workStatus === WorkStatus::Completed ? $story->endNotes : '';

        return [
            'story' => $storyFields,
            'chapter' => [
                'number' => $chapter->number,
                // Counted among the chapters a reader can open, so a hidden draft in the middle leaves no gap.
                'position' => $position,
                'total' => $total,
                'title' => $this->chapterTitle($chapter),
                'notes' => $chapter->notes,
                'end_notes' => $chapter->endNotes,
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
     * @param int $chapters the story's published chapters; 0 leaves the count out
     * @param array<string, list<Term>> $terms the story's terms by kind; only the ones a card shows are used
     * @return array{title: string, url: string, author: string, author_url: string, summary: string, status: string, words: string, chapters: string, term_groups: list<array<string, mixed>>, updated: string, cover: array{thumb: string, full: string, alt: string}}
     */
    public function card(Story $story, string $author, string $authorUrl, string $storyUrl, int $chapters = 0, array $terms = []): array
    {
        return [
            'title' => $story->title,
            'url' => $storyUrl,
            'author' => $author,
            'author_url' => $authorUrl,
            'summary' => $story->summary,
            'status' => $story->workStatus->label(),
            'words' => number_format($story->wordCount),
            // Printed text such as "12 chapters", or empty so a theme can leave it out with a plain test.
            'chapters' => $chapters > 0 ? number_format($chapters) . ($chapters === 1 ? ' chapter' : ' chapters') : '',
            'term_groups' => $this->cardTerms($terms),
            'updated' => $story->updatedAt->format('j F Y'),
            'cover' => $this->cover($story),
        ];
    }

    /** The kinds a card shows, in this order, and how many of each before it says "and N more". */
    private const CARD_TERMS = ['rating' => 3, 'warning' => 4, 'fandom' => 4, 'character' => 6, 'relationship' => 4, 'tag' => 8];

    /**
     * A short, linked summary of a story's classification for a list: rating, archive warnings, fandoms, characters, relationships and tags.
     *
     * @param array<string, list<Term>> $terms
     * @return list<array{label: string, type: string, terms: list<array{name: string, url: string}>, more: int}>
     */
    private function cardTerms(array $terms): array
    {
        $groups = [];

        foreach (self::CARD_TERMS as $kind => $limit) {
            $type = TermType::from($kind);
            $all = $terms[$kind] ?? [];

            if ($all === []) {
                // Only an old story can lack a rating; saying so keeps the place from reading as an omission.
                if ($type === TermType::Rating) {
                    $groups[] = $this->notRated($type) + ['more' => 0];
                }

                continue;
            }

            $groups[] = [
                'label' => count($all) === 1 ? $this->labels->singular($type) : $this->labels->plural($type),
                'type' => $kind,
                'terms' => array_map(static fn (Term $term): array => ['name' => $term->name, 'url' => PublicUrl::term($term->type, $term->slug)], array_slice($all, 0, $limit)),
                'more' => max(0, count($all) - $limit),
            ];
        }

        return $groups;
    }

    /**
     * The rating line of a story that has none, with no address because there is no page to link to.
     *
     * @return array{label: string, type: string, terms: list<array{name: string, url: string}>}
     */
    private function notRated(TermType $type): array
    {
        return ['label' => $this->labels->singular($type),'type' => $type->value, 'terms' => [['name' => 'Not rated', 'url' => '']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function storyFields(Story $story, string $author, string $storyUrl, string $authorUrl = ''): array
    {
        return [
            'id' => $story->id,
            'cover' => $this->cover($story),
            'title' => $story->title,
            'url' => $storyUrl,
            'author' => $author,
            'author_url' => $authorUrl,
            'summary' => $story->summary,
            'notes' => $story->notes,
            'end_notes' => $story->endNotes,
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
