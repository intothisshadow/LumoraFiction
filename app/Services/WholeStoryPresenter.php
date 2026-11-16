<?php

/**
 * The view data for the whole-story page: every chapter that fits under the word cap, with its contents list.
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

use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;

/**
 * It only arranges chapters it is given, which the caller took from the same public list the chapter pages use, so it
 * can never show a chapter a visitor could not open on its own page. Every chapter goes through the one content
 * renderer and sanitiser. The cap always lets the first chapter through, so a single enormous chapter still reads,
 * and what does not fit is named and linked to rather than dropped quietly.
 */
final class WholeStoryPresenter
{
    public function __construct(
        private readonly StoryPresenter $presenter,
        private readonly ContentRenderer $renderer,
        private readonly ?CommentPresenter $comments = null,
    ) {
    }

    /**
     * How many of the chapters fit under a word cap, counting from the first.
     *
     * @param list<Chapter> $chapters
     */
    public static function fitting(array $chapters, int $wordLimit): int
    {
        $words = 0;
        $fit = 0;

        foreach ($chapters as $chapter) {
            if ($fit > 0 && $words + $chapter->wordCount > $wordLimit) {
                break;
            }

            $words += $chapter->wordCount;
            $fit++;
        }

        return $fit;
    }

    /**
     * @param list<Chapter> $chapters every chapter a visitor can open, in order
     * @return array{chapters: list<array<string, mixed>>, contents: list<array<string, mixed>>, truncated: bool, notice: string, next: array{title: string, url: string}|null, shown: int, total: int, words: string}
     */
    public function present(Story $story, array $chapters, int $wordLimit): array
    {
        $fit = self::fitting($chapters, $wordLimit);
        $shown = array_slice($chapters, 0, $fit);
        $labels = $this->comments?->chapterLabels(array_map(static fn (Chapter $chapter): int => $chapter->id, $shown)) ?? [];
        $items = [];
        $contents = [];
        $words = 0;

        foreach ($chapters as $index => $chapter) {
            $url = PublicUrl::chapter($story->slug, $chapter->number);
            $title = $this->presenter->chapterTitle($chapter);
            $anchor = 'lf-chapter-' . $chapter->number;

            if ($index >= $fit) {
                $contents[] = ['number' => $chapter->number, 'title' => $title, 'url' => $url, 'words' => number_format($chapter->wordCount), 'on_page' => false];

                continue;
            }

            $words += $chapter->wordCount;
            $contents[] = ['number' => $chapter->number, 'title' => $title, 'url' => '#' . $anchor, 'words' => number_format($chapter->wordCount), 'on_page' => true];
            $items[] = [
                'number' => $chapter->number,
                'position' => $index + 1,
                'anchor' => $anchor,
                'title' => $title,
                'url' => $url,
                'words' => number_format($chapter->wordCount),
                'notes' => $chapter->notes,
                'end_notes' => $chapter->endNotes,
                'html' => new SafeHtml($this->renderer->render($chapter->content, $chapter->format)),
                // Comments are not repeated here: each chapter links to its own, which are on its own page.
                'comments_label' => $labels[$chapter->id] ?? '',
                'comments_url' => isset($labels[$chapter->id]) ? $url . '#comments' : '',
            ];
        }

        $truncated = $fit < count($chapters);
        $next = $truncated ? $chapters[$fit] : null;

        return [
            'chapters' => $items,
            'contents' => $contents,
            'truncated' => $truncated,
            'notice' => $truncated
                ? 'This page shows the first ' . number_format($fit) . ' of ' . number_format(count($chapters)) . ' chapters (' . number_format($words) . ' words), because the whole story is too long for one page. The rest continues on its own pages.'
                : '',
            'next' => $next === null ? null : ['title' => $this->presenter->chapterTitle($next), 'url' => PublicUrl::chapter($story->slug, $next->number)],
            'shown' => $fit,
            'total' => count($chapters),
            'words' => number_format($words),
        ];
    }
}
