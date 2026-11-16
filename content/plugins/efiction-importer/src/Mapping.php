<?php

/**
 * The administrator's choices for how eFiction's classification becomes this site's, checked against what exists here.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

use LumoraFiction\Models\TermType;
use LumoraFiction\Services\TaxonomyService;

final class Mapping
{
    /** What a class type can become here. */
    public const KINDS = ['genre' => 'Genres', 'warning' => 'Archive warnings', 'tag' => 'Tags', 'skip' => 'Leave out'];

    /**
     * Takes the form's values over the suggestions. A value the screen could not have offered falls back to the suggestion,
     * so a forged form can never make the importer touch a term it was not shown.
     *
     * @param array<string, mixed> $suggested the result of Survey::mapping()
     * @param array<string, mixed> $input the posted form
     * @return array{ratings: array<int, int|string>, categories: array<int, int|string>, characters: string, classtypes: array<int, string>}
     */
    public static function fromInput(array $suggested, array $input, TaxonomyService $taxonomy): array
    {
        $mapping = ['ratings' => [], 'categories' => [], 'characters' => 'import', 'classtypes' => []];

        foreach ($suggested['ratings'] ?? [] as $rating) {
            $mapping['ratings'][$rating['id']] = self::termChoice($input['rating_' . $rating['id']] ?? null, TermType::Rating, $taxonomy, 'new') ?? $rating['choice'];
        }

        foreach ($suggested['categories'] ?? [] as $category) {
            $mapping['categories'][$category['id']] = self::termChoice($input['category_' . $category['id']] ?? null, TermType::Fandom, $taxonomy, 'import') ?? $category['choice'];
        }

        $mapping['characters'] = ($input['characters'] ?? null) === 'skip' ? 'skip' : 'import';

        foreach ($suggested['classtypes'] ?? [] as $type) {
            $choice = $input['classtype_' . $type['id']] ?? null;
            $mapping['classtypes'][$type['id']] = is_string($choice) && isset(self::KINDS[$choice]) ? $choice : $type['choice'];
        }

        return $mapping;
    }

    /**
     * @return int|string|null an existing term's id, the word for "make or match one", "skip", or nothing when the value is not valid
     */
    private static function termChoice(mixed $value, TermType $type, TaxonomyService $taxonomy, string $make): int|string|null
    {
        if (!is_string($value)) {
            return null;
        }

        if ($value === $make || $value === 'skip') {
            return $value;
        }

        if (ctype_digit($value)) {
            $term = $taxonomy->find((int) $value);

            return $term !== null && $term->type === $type ? $term->id : null;
        }

        return null;
    }
}
