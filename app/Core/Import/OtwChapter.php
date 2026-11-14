<?php

/**
 * One chapter read from a downloaded work.
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

/**
 * Summary and notes are plain text with line breaks; the text is still the source's HTML, cleaned later by the
 * same sanitiser as anything written in the editor.
 */
final readonly class OtwChapter
{
    public function __construct(
        public int $number,
        public string $title,
        public string $summary,
        public string $notes,
        public string $endNotes,
        public string $html,
    ) {
    }

    /**
     * @return array{number: int, title: string, summary: string, notes: string, end_notes: string, html: string}
     */
    public function toArray(): array
    {
        return ['number' => $this->number, 'title' => $this->title, 'summary' => $this->summary, 'notes' => $this->notes, 'end_notes' => $this->endNotes, 'html' => $this->html];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['number'] ?? 0),
            (string) ($data['title'] ?? ''),
            (string) ($data['summary'] ?? ''),
            (string) ($data['notes'] ?? ''),
            (string) ($data['end_notes'] ?? ''),
            (string) ($data['html'] ?? ''),
        );
    }
}
