<?php

/**
 * The words a site shows for each kind of classification, falling back to the fixed names.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Models\TermType;

/**
 * Display text only. Addresses, stored values, feed addresses and the settings file keep the fixed names, so a site
 * that calls a relationship a "pairing" breaks nothing that parses a kind. Without a configuration it returns the
 * fixed names, which is what tests and tools that never touch the settings get.
 */
final class ClassificationLabels
{
    public const SETTING = 'classification.labels';

    public const MAX_LENGTH = 40;

    public function __construct(private readonly ?Configuration $config = null)
    {
    }

    public function singular(TermType $type): string
    {
        return $this->custom()[$type->value]['singular'] ?? $type->label();
    }

    public function plural(TermType $type): string
    {
        return $this->custom()[$type->value]['plural'] ?? $type->pluralLabel();
    }

    /** The singular for the middle of a sentence. */
    public function lower(TermType $type): string
    {
        return mb_strtolower($this->singular($type), 'UTF-8');
    }

    public function lowerPlural(TermType $type): string
    {
        return mb_strtolower($this->plural($type), 'UTF-8');
    }

    /**
     * The kinds that carry the site's own words, keyed by the fixed kind name. A damaged entry is ignored, so a bad
     * value in the settings never blanks a label.
     *
     * @return array<string, array{singular: string, plural: string}>
     */
    public function custom(): array
    {
        $stored = $this->config?->setting(self::SETTING);
        $labels = [];

        if (!is_array($stored)) {
            return $labels;
        }

        // Walked in the fixed order of the kinds, so the same words always come back in the same order.
        foreach (TermType::cases() as $type) {
            $kind = $type->value;
            $entry = $stored[$kind] ?? null;

            if (!is_array($entry)) {
                continue;
            }

            $singular = is_string($entry['singular'] ?? null) ? trim($entry['singular']) : '';
            $plural = is_string($entry['plural'] ?? null) ? trim($entry['plural']) : '';

            if ($singular !== '' && $plural !== '') {
                $labels[$kind] = ['singular' => $singular, 'plural' => $plural];
            }
        }

        return $labels;
    }
}
