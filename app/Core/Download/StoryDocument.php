<?php

/**
 * Everything a downloadable file of a story is built from, gathered once so all formats say the same thing.
 *
 * @package LumoraFiction
 * @subpackage Download
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Download;

use LumoraFiction\Helpers\Slug;

/**
 * Only public fields are held: the Author's display name, never the login name. Chapter text is already cleaned by
 * the content pipeline, the same HTML a reader sees.
 */
final readonly class StoryDocument
{
    /**
     * Bumped when a builder changes what it writes, so files built by the older code are rebuilt.
     */
    public const BUILD_VERSION = 1;

    /**
     * @param list<array{label: string, values: list<string>}> $details rating, warnings, fandoms and the rest, in reading order
     * @param list<array{number: int, title: string, notes: string, html: string}> $chapters
     * @param string $coverPath file on disk, or an empty string for a story without a cover
     * @param string $coverType the cover's media type, such as image/jpeg
     */
    public function __construct(
        public int $storyId,
        public string $title,
        public string $author,
        public string $summary,
        public string $notes,
        public string $disclaimer,
        public string $language,
        public string $status,
        public int $words,
        public int $updatedAt,
        public array $details,
        public array $chapters,
        public string $coverPath = '',
        public string $coverType = '',
        public string $coverAlt = '',
    ) {
    }

    /**
     * Changes whenever anything a file shows changes, so a cached file is reused exactly as long as it is still right.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', json_encode([
            self::BUILD_VERSION,
            $this->title, $this->author, $this->summary, $this->notes, $this->disclaimer, $this->language, $this->status,
            $this->words, $this->updatedAt, $this->details, $this->chapters, $this->coverType, $this->coverAlt,
            $this->coverPath === '' ? '' : (string) hash_file('sha256', $this->coverPath),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * The name a downloaded file is saved under: the display name and the title, never the login name or a path.
     */
    public function fileStem(): string
    {
        return Slug::fromText($this->author, 30, 'author') . '-' . Slug::fromText($this->title, 60, 'story');
    }
}
