<?php

/**
 * Places a rating name on a four-step audience scale, so a rating from another archive can be suggested a match here.
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
 * Only a suggestion: the author sees the pairing and can change it, and a rating this class does not recognise is never
 * guessed at. The steps are 0 for every reader, 1 for teenagers and up, 2 for adults, 3 for explicit material.
 */
final class RatingScale
{
    /** @var array<string, int> names reduced to lower-case letters and digits, with the step they stand for */
    private const LEVELS = [
        'generalaudiences' => 0, 'general' => 0, 'everyone' => 0, 'allages' => 0, 'kids' => 0, 'k' => 0, 'kplus' => 0, 'k+' => 0, 'g' => 0, 'pg' => 0,
        'teenandupaudiences' => 1, 'teen' => 1, 'teens' => 1, 't' => 1, 'pg13' => 1,
        'mature' => 2, 'm' => 2, 'r' => 2, 'ratedr' => 2,
        'explicit' => 3, 'ma' => 3, 'nc17' => 3, 'x' => 3, 'adult' => 3, 'adultsonly' => 3,
    ];

    private const NOT_RATED = ['notrated', 'unrated', 'nr', 'norating'];

    public static function level(string $name): ?int
    {
        return self::LEVELS[self::key($name)] ?? null;
    }

    public static function isNotRated(string $name): bool
    {
        return in_array(self::key($name), self::NOT_RATED, true);
    }

    /**
     * The id of the site rating that suits the source rating best, or null when none can be told. A source rating with
     * no equal on this site goes to the nearest lower step (Explicit to Mature on a site with three ratings), and failing
     * that to the nearest higher one, so a story is never made to look milder than it was unless nothing else exists.
     *
     * @param array<int, string> $siteRatings rating id => name, in the site's order
     */
    public static function suggest(string $source, array $siteRatings): ?int
    {
        if (self::isNotRated($source)) {
            foreach ($siteRatings as $id => $name) {
                if (self::isNotRated($name)) {
                    return $id;
                }
            }

            return null;
        }

        $wanted = self::level($source);

        if ($wanted === null) {
            return null;
        }

        $lower = null;
        $higher = null;

        foreach ($siteRatings as $id => $name) {
            $level = self::level($name);

            if ($level === null) {
                continue;
            }

            if ($level === $wanted) {
                return $id;
            }

            if ($level < $wanted && ($lower === null || $level > $lower[0])) {
                $lower = [$level, $id];
            }

            if ($level > $wanted && ($higher === null || $level < $higher[0])) {
                $higher = [$level, $id];
            }
        }

        return $lower[1] ?? $higher[1] ?? null;
    }

    private static function key(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9+]/', '', mb_strtolower($name, 'UTF-8'));
    }
}
