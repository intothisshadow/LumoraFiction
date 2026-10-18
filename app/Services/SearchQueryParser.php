<?php

/**
 * Turns the address of a search page into a checked search.
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

use LumoraFiction\Models\SearchQuery;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\WorkStatus;

/**
 * Nothing typed into an address reaches a query unchecked. Text beyond the limits is refused rather than trimmed,
 * so a very long or very wide search never becomes an expensive scan.
 */
final class SearchQueryParser
{
    public const MAX_TEXT_LENGTH = 100;

    public const MAX_WORDS = 6;

    public const MIN_WORD_LENGTH = 2;

    public const MAX_WORD_LENGTH = 50;

    /** The kinds of term a visitor can filter by, in the order the form shows them. */
    private const FILTER_TYPES = [
        TermType::Fandom,
        TermType::Category,
        TermType::Genre,
        TermType::Rating,
        TermType::Warning,
        TermType::Tag,
    ];

    public function __construct(private readonly TaxonomyService $taxonomy)
    {
    }

    /**
     * The kinds a visitor can filter by on this site: the archive mode decides which are offered.
     *
     * @return list<TermType>
     */
    public function filterTypes(): array
    {
        return array_values(array_filter(self::FILTER_TYPES, fn (TermType $type): bool => $this->taxonomy->isEnabled($type)));
    }

    /**
     * @param array<string, mixed> $input the address's query values
     * @return array{query: SearchQuery, notice: string} the notice is empty unless the text was refused
     */
    public function parse(array $input): array
    {
        $notice = '';
        $text = $this->text($input['q'] ?? null);
        $words = [];

        if ($text !== '') {
            $words = $this->words($text);

            if ($words === null) {
                $notice = 'That search is too long. Try a few shorter words.';
            } elseif ($words === []) {
                $notice = 'Search for words of at least two letters.';
            }

            if ($notice !== '') {
                $words = [];
                $text = '';
            }
        }

        $termIds = [];
        $termNames = [];

        foreach ($this->filterTypes() as $type) {
            $name = $this->text($input[$type->value] ?? null);

            if ($name === '') {
                continue;
            }

            $term = mb_strlen($name) <= 100 ? $this->taxonomy->resolve($type, $name) : null;
            $termNames[$type->value] = $name;
            $termIds[$type->value] = $term === null ? 0 : $term->id;
        }

        $status = is_string($input['status'] ?? null) ? WorkStatus::tryFrom($input['status']) : null;
        $language = is_string($input['language'] ?? null) && preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?\z/', $input['language']) === 1 ? $input['language'] : '';

        return [
            'query' => new SearchQuery(
                $text,
                $words,
                $termIds,
                $termNames,
                $status,
                $language,
                $this->count($input['min_words'] ?? null),
                $this->count($input['max_words'] ?? null),
                PublicArchive::sortOrDefault(is_string($input['sort'] ?? null) ? $input['sort'] : null),
            ),
            'notice' => $notice,
        ];
    }

    /**
     * Plain single-line text: control characters dropped, spaces collapsed, and anything that is not text ignored.
     */
    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace('/[\p{C}\s]+/u', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>|null null when the text is over a limit
     */
    private function words(string $text): ?array
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return null;
        }

        $words = [];

        foreach (explode(' ', $text) as $word) {
            if (mb_strlen($word) > self::MAX_WORD_LENGTH) {
                return null;
            }

            // A single letter would match nearly every story, so it is left out rather than searched.
            if (mb_strlen($word) >= self::MIN_WORD_LENGTH) {
                $words[] = $word;
            }
        }

        $words = array_values(array_unique($words));

        return count($words) > self::MAX_WORDS ? null : $words;
    }

    private function count(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[0-9]{1,9}\z/', $value) === 1 ? (int) $value : null;
    }
}
