<?php

/**
 * A work read from a file downloaded from an OTW-Archive site, before anything is written here.
 *
 * @package LumoraFiction
 * @subpackage Import
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Import;

use DateTimeImmutable;

/**
 * The metadata keeps the names exactly as the source printed them; mapping them to this site's lists is the import
 * service's job. The preview step stores a work as an array and reads it back, so every field survives that trip.
 * It is the neutral shape every reader fills (the Archive of Our Own download parser and the general HTML reader), so
 * one preview and one import serve both; a general file leaves most fields empty for the author to fill in.
 */
final readonly class OtwWork
{
    /**
     * @param list<string> $warnings
     * @param list<string> $categories
     * @param list<string> $fandoms
     * @param list<string> $relationships
     * @param list<string> $characters
     * @param list<string> $tags
     * @param list<string> $collections
     * @param list<OtwChapter> $chapters
     */
    public function __construct(
        public string $fileName,
        public string $title,
        public string $byline,
        public string $summary,
        public string $notes,
        public string $endNotes,
        public string $rating,
        public array $warnings,
        public array $categories,
        public array $fandoms,
        public array $relationships,
        public array $characters,
        public array $tags,
        public string $language,
        public string $seriesName,
        public int $seriesPart,
        public array $collections,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $updatedAt,
        public bool $complete,
        public int $words,
        public string $sourceUrl,
        public string $sourceId,
        public array $chapters,
        public string $reader = 'otw',
        public string $fileHash = '',
        public array $readerNotes = [],
    ) {
    }

    /** Whether this came from a file that carries no details of its own, so the author supplies them in the preview. */
    public function isGeneral(): bool
    {
        return $this->reader === 'general';
    }

    /**
     * What identifies this work when it has no address of its own: for a general file, a hash of the file itself, which
     * is unaffected by what the author types in the preview; otherwise the title, the byline and the start of the first
     * chapter, so a second upload of the same file is noticed even when it was saved under another name.
     */
    public function fingerprint(): string
    {
        if ($this->fileHash !== '') {
            return $this->fileHash;
        }

        $first = $this->chapters[0]->html ?? '';
        $start = mb_substr((string) preg_replace('/\s+/u', ' ', strip_tags($first)), 0, 500, 'UTF-8');

        return hash('sha256', mb_strtolower($this->title . "\n" . $this->byline . "\n" . $start, 'UTF-8'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->fileName,
            'title' => $this->title,
            'byline' => $this->byline,
            'summary' => $this->summary,
            'notes' => $this->notes,
            'end_notes' => $this->endNotes,
            'rating' => $this->rating,
            'warnings' => $this->warnings,
            'categories' => $this->categories,
            'fandoms' => $this->fandoms,
            'relationships' => $this->relationships,
            'characters' => $this->characters,
            'tags' => $this->tags,
            'language' => $this->language,
            'series_name' => $this->seriesName,
            'series_part' => $this->seriesPart,
            'collections' => $this->collections,
            'published' => $this->publishedAt?->format('Y-m-d'),
            'updated' => $this->updatedAt?->format('Y-m-d'),
            'complete' => $this->complete,
            'words' => $this->words,
            'source_url' => $this->sourceUrl,
            'source_id' => $this->sourceId,
            'reader' => $this->reader,
            'file_hash' => $this->fileHash,
            'reader_notes' => $this->readerNotes,
            'chapters' => array_map(static fn (OtwChapter $chapter): array => $chapter->toArray(), $this->chapters),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $list = static fn (mixed $value): array => is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
        $date = static function (mixed $value): ?DateTimeImmutable {
            $made = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            return $made === false ? null : $made;
        };
        $chapters = [];

        foreach (is_array($data['chapters'] ?? null) ? $data['chapters'] : [] as $chapter) {
            if (is_array($chapter)) {
                $chapters[] = OtwChapter::fromArray($chapter);
            }
        }

        return new self(
            (string) ($data['file'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['byline'] ?? ''),
            (string) ($data['summary'] ?? ''),
            (string) ($data['notes'] ?? ''),
            (string) ($data['end_notes'] ?? ''),
            (string) ($data['rating'] ?? ''),
            $list($data['warnings'] ?? null),
            $list($data['categories'] ?? null),
            $list($data['fandoms'] ?? null),
            $list($data['relationships'] ?? null),
            $list($data['characters'] ?? null),
            $list($data['tags'] ?? null),
            (string) ($data['language'] ?? ''),
            (string) ($data['series_name'] ?? ''),
            (int) ($data['series_part'] ?? 0),
            $list($data['collections'] ?? null),
            $date($data['published'] ?? null),
            $date($data['updated'] ?? null),
            (bool) ($data['complete'] ?? false),
            (int) ($data['words'] ?? 0),
            (string) ($data['source_url'] ?? ''),
            (string) ($data['source_id'] ?? ''),
            $chapters,
            ($data['reader'] ?? 'otw') === 'general' ? 'general' : 'otw',
            preg_match('/^[a-f0-9]{64}\z/', (string) ($data['file_hash'] ?? '')) === 1 ? (string) $data['file_hash'] : '',
            $list($data['reader_notes'] ?? null),
        );
    }
}
