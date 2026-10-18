<?php

/**
 * Builds the search form, the removable filter list and the addresses for a search page.
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
use LumoraFiction\Models\SearchQuery;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\WorkStatus;

/**
 * A theme gets one bundled array and prints it, so a new filter reaches every theme without a template change.
 */
final class SearchPresenter
{
    /** Kinds shown as a list to pick from; the rest are open-ended and typed. */
    private const PICKED = [TermType::Category, TermType::Genre, TermType::Rating, TermType::Warning];

    public function __construct(
        private readonly TaxonomyService $taxonomy,
        private readonly SearchQueryParser $parser,
        private readonly PublicArchive $archive,
    ) {
    }

    /**
     * The values that make up a search address. The default sort is left out.
     *
     * @return array<string, string|int|null>
     */
    public function values(SearchQuery $query): array
    {
        return [
            'q' => $query->text,
            ...$query->termNames,
            'status' => $query->status?->value,
            'language' => $query->language,
            'min_words' => $query->minWords,
            'max_words' => $query->maxWords,
            'sort' => $query->sort === PublicArchive::DEFAULT_SORT ? null : $query->sort,
        ];
    }

    /**
     * @return array{action: string, submit: string, reset_url: string, filtered: bool, fields: list<array<string, mixed>>}
     */
    public function form(SearchQuery $query): array
    {
        $fields = [
            ['id' => 'lf-search-q', 'name' => 'q', 'label' => 'Search', 'type' => 'search', 'value' => $query->text, 'hint' => 'Words from a title, summary, author name or tag.'],
        ];

        foreach ($this->parser->filterTypes() as $type) {
            $typed = $query->termNames[$type->value] ?? '';

            if (in_array($type, self::PICKED, true)) {
                $options = [['value' => '', 'label' => 'Any', 'selected' => $typed === '']];

                foreach ($this->taxonomy->list($type, [], 200) as $term) {
                    if ($term->storyCount > 0) {
                        $options[] = ['value' => $term->name, 'label' => $term->name, 'selected' => mb_strtolower($term->name) === mb_strtolower($typed)];
                    }
                }

                $fields[] = ['id' => 'lf-search-' . $type->value, 'name' => $type->value, 'label' => $type->label(), 'type' => 'select', 'options' => $options];
            } else {
                $fields[] = ['id' => 'lf-search-' . $type->value, 'name' => $type->value, 'label' => $type->label(), 'type' => 'text', 'value' => $typed];
            }
        }

        $statuses = [['value' => '', 'label' => 'Any', 'selected' => $query->status === null]];

        foreach (WorkStatus::cases() as $status) {
            $statuses[] = ['value' => $status->value, 'label' => $status->label(), 'selected' => $query->status === $status];
        }

        $fields[] = ['id' => 'lf-search-status', 'name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $statuses];

        $languages = $this->archive->languages();

        if (count($languages) > 1 || ($languages !== [] && $query->language !== '')) {
            $options = [['value' => '', 'label' => 'Any', 'selected' => $query->language === '']];

            foreach ($languages as $code) {
                $options[] = ['value' => $code, 'label' => $code, 'selected' => $code === $query->language];
            }

            $fields[] = ['id' => 'lf-search-language', 'name' => 'language', 'label' => 'Language', 'type' => 'select', 'options' => $options];
        }

        $fields[] = ['id' => 'lf-search-min-words', 'name' => 'min_words', 'label' => 'Fewest words', 'type' => 'number', 'value' => $query->minWords === null ? '' : (string) $query->minWords];
        $fields[] = ['id' => 'lf-search-max-words', 'name' => 'max_words', 'label' => 'Most words', 'type' => 'number', 'value' => $query->maxWords === null ? '' : (string) $query->maxWords];

        $sorts = [];

        foreach (PublicArchive::SORTS as $name => $label) {
            $sorts[] = ['value' => $name, 'label' => $label, 'selected' => $name === $query->sort];
        }

        $fields[] = ['id' => 'lf-search-sort', 'name' => 'sort', 'label' => 'Sort by', 'type' => 'select', 'options' => $sorts];

        return [
            'action' => PublicUrl::search(),
            'submit' => 'Search',
            'reset_url' => PublicUrl::search(),
            'filtered' => $query->isNarrowed(),
            'fields' => $fields,
        ];
    }

    /**
     * What the search is currently narrowed by, each with the address of the same search without it.
     *
     * @return list<array{label: string, remove_url: string}>
     */
    public function active(SearchQuery $query): array
    {
        $values = $this->values($query);
        $chips = [];
        $add = function (string $key, string $label) use (&$chips, $values): void {
            $chips[] = ['label' => $label, 'remove_url' => PublicUrl::search(array_diff_key($values, [$key => true]))];
        };

        if ($query->text !== '') {
            $add('q', 'Search: ' . $query->text);
        }

        foreach ($query->termNames as $kind => $name) {
            $type = TermType::tryFrom($kind);
            $add($kind, ($type === null ? $kind : $type->label()) . ': ' . $name);
        }

        if ($query->status !== null) {
            $add('status', 'Status: ' . $query->status->label());
        }

        if ($query->language !== '') {
            $add('language', 'Language: ' . $query->language);
        }

        if ($query->minWords !== null) {
            $add('min_words', 'At least ' . number_format($query->minWords) . ' words');
        }

        if ($query->maxWords !== null) {
            $add('max_words', 'At most ' . number_format($query->maxWords) . ' words');
        }

        return $chips;
    }

    /**
     * The address of a page of results, with a {page} marker for the pagination tag.
     */
    public function pageUrl(SearchQuery $query): string
    {
        $base = PublicUrl::search($this->values($query));

        return $base . (str_contains($base, '?') ? '&' : '?') . 'page={page}';
    }
}
