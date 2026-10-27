<?php

/**
 * Gathers a public story into the one document every download format is built from.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Download\DownloadException;
use LumoraFiction\Core\Download\StoryDocument;
use LumoraFiction\Core\Media\CoverImageStore;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\TermType;

/**
 * Chapter text goes through the same renderer the reading pages use, so a download can never show markup the site
 * itself would not show, and only the Author's display name is read from the account.
 */
final class StoryDocumentFactory
{
    /** The kinds of classification, in the order a title page lists them. */
    private const ORDER = [
        TermType::Rating, TermType::Warning, TermType::Category, TermType::Fandom, TermType::RelationshipCategory,
        TermType::Relationship, TermType::Character, TermType::Genre, TermType::Tag,
    ];

    private const COVER_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly UserService $users,
        private readonly ContentRenderer $renderer,
        private readonly ?CoverImageStore $covers = null,
    ) {
    }

    /**
     * @throws DownloadException when the story has nothing public to put in a file
     */
    public function make(Story $story): StoryDocument
    {
        $chapters = [];
        $updated = $story->updatedAt->getTimestamp();

        foreach ($this->chapters->listPublic($story->id) as $chapter) {
            $updated = max($updated, $chapter->updatedAt->getTimestamp());
            $chapters[] = [
                'number' => $chapter->number,
                'title' => $chapter->title,
                'notes' => $chapter->notes,
                'html' => $this->renderer->render($chapter->content, $chapter->format),
            ];
        }

        if ($chapters === []) {
            throw new DownloadException('This story has no chapters to download yet.');
        }

        $author = $this->users->publicProfiles([$story->ownerId])[$story->ownerId]['display_name'] ?? '';
        $cover = $story->hasCover() && $this->covers !== null ? $this->covers->filePath($story->coverFile) : '';

        return new StoryDocument(
            storyId: $story->id,
            title: $story->title,
            author: $author,
            summary: $story->summary,
            notes: $story->notes,
            disclaimer: $story->disclaimer,
            language: $story->language,
            status: $story->workStatus->label(),
            words: $story->wordCount,
            updatedAt: $updated,
            details: $this->details($story),
            chapters: $chapters,
            coverPath: $cover,
            coverType: $cover === '' ? '' : (self::COVER_TYPES[strtolower(pathinfo($cover, PATHINFO_EXTENSION))] ?? ''),
            coverAlt: $story->coverAlt,
        );
    }

    /**
     * @return list<array{label: string, values: list<string>}>
     */
    private function details(Story $story): array
    {
        $enabled = $this->taxonomy->enabledTypes();
        $terms = $this->taxonomy->termsForStory($story->id);
        $details = [];

        foreach (self::ORDER as $type) {
            $names = array_map(static fn ($term): string => $term->name, $terms[$type->value] ?? []);

            if ($names !== [] && in_array($type, $enabled, true)) {
                $details[] = ['label' => $type === TermType::Rating ? 'Rating' : $type->pluralLabel(), 'values' => $names];
            }
        }

        return $details;
    }
}
