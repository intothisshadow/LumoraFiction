<?php

/**
 * Made-up names, titles and prose for the Dummy Content plugin.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

use Random\Randomizer;

/**
 * Plain English words only, so the text never contains markup, apostrophes or anything the sanitiser or the word
 * counter could treat differently. The random source is injected so a seed reproduces a run exactly.
 */
final class Corpus
{
    private const FIRST_NAMES = ['Rowan', 'Marlowe', 'Sable', 'Imogen', 'Teodor', 'Linnea', 'Casimir', 'Odalys', 'Bram', 'Wren', 'Iris', 'Callum', 'Petra', 'Juniper', 'Ansel', 'Mirela', 'Dorian', 'Selene', 'Thaddeus', 'Fenna', 'Lucan', 'Yara', 'Emeric', 'Nell', 'Ottoline'];

    private const LAST_NAMES = ['Ashdown', 'Vale', 'Hartley', 'Quill', 'Marsh', 'Thorne', 'Fairweather', 'Lindqvist', 'Okafor', 'Bellamy', 'Corvin', 'Eldridge', 'Hollis', 'Mercer', 'Nightingale', 'Pryce', 'Rook', 'Sterling', 'Tamsin', 'Wilder'];

    private const ADJECTIVES = ['Silent', 'Broken', 'Golden', 'Hollow', 'Crimson', 'Forgotten', 'Gentle', 'Restless', 'Winter', 'Burning', 'Distant', 'Fading', 'Hidden', 'Lonely', 'Quiet', 'Scarlet', 'Wandering', 'Bitter', 'Bright', 'Ancient'];

    private const NOUNS = ['Crown', 'Garden', 'Lantern', 'River', 'Harbor', 'Promise', 'Letter', 'Mirror', 'Orchard', 'Shadow', 'Tower', 'Window', 'Compass', 'Ember', 'Kingdom', 'Library', 'Oath', 'Sparrow', 'Storm', 'Thread'];

    private const WORDS = [
        'the', 'a', 'and', 'then', 'she', 'he', 'they', 'walked', 'through', 'silence', 'across', 'old', 'bridge', 'while', 'rain', 'fell', 'softly', 'on', 'stone',
        'remembered', 'every', 'word', 'said', 'before', 'leaving', 'town', 'morning', 'light', 'slipped', 'under', 'door', 'quiet', 'house', 'waited', 'for',
        'someone', 'to', 'speak', 'nothing', 'moved', 'except', 'dust', 'in', 'air', 'of', 'her', 'his', 'their', 'hand', 'trembled', 'as', 'opened', 'letter',
        'it', 'was', 'not', 'what', 'they', 'expected', 'but', 'still', 'read', 'twice', 'smiled', 'without', 'knowing', 'why', 'far', 'away', 'bells', 'rang',
        'night', 'came', 'early', 'that', 'winter', 'brought', 'with', 'cold', 'wind', 'long', 'shadows', 'stories', 'old', 'friends', 'told', 'by', 'fire',
        'asked', 'if', 'would', 'stay', 'laughed', 'turned', 'toward', 'window', 'watched', 'snow', 'gather', 'edge', 'garden', 'there', 'is', 'always',
        'something', 'left', 'unsaid', 'between', 'two', 'people', 'who', 'once', 'knew', 'each', 'other', 'well', 'so', 'began', 'again', 'slowly', 'carefully',
        'like', 'learning', 'a', 'language', 'half', 'forgotten', 'heart', 'beat', 'steady', 'despite', 'everything', 'road', 'ahead', 'narrow', 'bright',
        'promise', 'kept', 'at', 'last', 'when', 'dawn', 'finally', 'arrived', 'nobody', 'could', 'say', 'how', 'long', 'had', 'been', 'standing', 'there',
    ];

    public function __construct(private readonly Randomizer $random)
    {
    }

    public function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    public function between(int $low, int $high): int
    {
        return $this->random->getInt($low, max($low, $high));
    }

    /**
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    /**
     * Favours the start of the list, the way a few fandoms and tags hold most of the stories on a real archive.
     *
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pickPopular(array $items): mixed
    {
        // Strictly below 1, or the square could land one past the end of the list.
        $fraction = $this->random->getInt(0, 999999) / 1000000;

        return $items[(int) floor(count($items) * $fraction * $fraction)];
    }

    public function personName(): string
    {
        return $this->pick(self::FIRST_NAMES) . ' ' . $this->pick(self::LAST_NAMES);
    }

    public function firstName(): string
    {
        return $this->pick(self::FIRST_NAMES);
    }

    public function fandomName(): string
    {
        return $this->chance(50)
            ? 'The ' . $this->pick(self::ADJECTIVES) . ' ' . $this->pick(self::NOUNS) . ' Chronicles'
            : $this->pick(self::NOUNS) . ' of ' . $this->pick(self::LAST_NAMES);
    }

    public function tagName(): string
    {
        return strtolower($this->pick(self::ADJECTIVES) . ' ' . $this->pick(self::NOUNS));
    }

    public function storyTitle(): string
    {
        return match ($this->between(0, 3)) {
            0 => 'The ' . $this->pick(self::ADJECTIVES) . ' ' . $this->pick(self::NOUNS),
            1 => $this->pick(self::NOUNS) . ' of ' . $this->pick(self::ADJECTIVES) . ' ' . $this->pick(self::NOUNS) . 's',
            2 => 'A ' . $this->pick(self::ADJECTIVES) . ' ' . $this->pick(self::NOUNS),
            default => $this->firstName() . ' and the ' . $this->pick(self::NOUNS),
        };
    }

    public function chapterTitle(): string
    {
        return ucfirst($this->pick(self::WORDS)) . ' ' . $this->pick(self::WORDS) . ' ' . $this->pick(self::WORDS);
    }

    public function sentence(): string
    {
        $words = [];
        $count = $this->between(6, 16);

        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->pick(self::WORDS);
        }

        if ($count > 9 && $this->chance(40)) {
            $words[intdiv($count, 2)] .= ',';
        }

        return ucfirst(implode(' ', $words)) . '.';
    }

    /**
     * @return string one paragraph of plain text
     */
    public function paragraph(): string
    {
        $sentences = [];
        $count = $this->between(3, 7);

        for ($i = 0; $i < $count; $i++) {
            $sentences[] = $this->sentence();
        }

        return implode(' ', $sentences);
    }

    public function summary(): string
    {
        return $this->sentence() . ' ' . $this->sentence();
    }
}
