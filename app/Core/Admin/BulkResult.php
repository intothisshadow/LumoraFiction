<?php

/**
 * What a bulk action did: how many items were changed, how many were skipped, and why.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

/**
 * One result shape for every bulk screen, so each reports "N done, M skipped" and lists each reason once. Items are
 * always applied one at a time, so a refused item is counted here and never stops the others.
 */
final class BulkResult
{
    /** The most items one request handles, matching the page size of the lists. */
    public const LIMIT = 100;

    private int $done = 0;

    private int $skipped = 0;

    /** @var list<string> */
    private array $reasons = [];

    public function done(): void
    {
        $this->done++;
    }

    public function skip(string $reason): void
    {
        $this->skipped++;

        if ($reason !== '' && !in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
        }
    }

    public function doneCount(): int
    {
        return $this->done;
    }

    public function skippedCount(): int
    {
        return $this->skipped;
    }

    /**
     * @return list<string> each reason once, in the order first met
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * The sentence for the flash message after the action.
     *
     * @param string $what what was done, e.g. "published"
     * @param string $noun singular noun of the items, e.g. "chapter"
     */
    public function summary(string $what, string $noun, ?string $plural = null): string
    {
        $plural ??= $noun . 's';
        $text = $this->done . ' ' . ($this->done === 1 ? $noun . ' was' : $plural . ' were') . ' ' . $what . '.';

        if ($this->skipped > 0) {
            $text .= ' ' . $this->skipped . ' skipped' . ($this->reasons === [] ? '.' : ': ' . implode(' ', $this->reasons));
        }

        return $text;
    }

    public function flashType(): string
    {
        return $this->done > 0 ? 'success' : 'warning';
    }

    /**
     * A request naming more than a page of items was not made by the screen, so it is refused rather than trimmed.
     *
     * @param list<string> $values the raw posted values
     */
    public static function tooMany(array $values): bool
    {
        return count($values) > self::LIMIT;
    }

    /**
     * Pulls the ticked ids out of a request: only plain positive numbers, no repeats, at most LIMIT.
     *
     * @param list<string> $values the raw posted values
     * @return list<int>
     */
    public static function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
                $ids[] = (int) $value;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, self::LIMIT);
    }
}
