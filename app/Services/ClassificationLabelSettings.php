<?php

/**
 * Validating and saving the site's own names for the kinds of classification.
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
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;

/**
 * A kind either keeps its fixed names or has both a singular and a plural: a singular alone would print "Pairing" next
 * to "Relationships" on the next page. A kind the archive mode hides is not on the form, so its saved words are kept.
 */
final class ClassificationLabelSettings
{
    /** Control, zero-width and text-direction characters, as the term names refuse them. */
    private const FORBIDDEN = '/[\p{Cc}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return array<string, array{singular: string, plural: string}> the kinds that have their own words
     */
    public function current(): array
    {
        return (new ClassificationLabels($this->config))->custom();
    }

    /**
     * @param array<string, array{singular: string, plural: string}> $posted entered words by kind name
     * @param list<TermType> $listed the kinds that were on the form
     * @return array{errors: array<string, string>, values: array<string, array{singular: string, plural: string}>}
     */
    public function validate(array $posted, array $listed): array
    {
        $stored = $this->current();
        $values = [];
        $errors = [];

        foreach (TermType::cases() as $type) {
            if (!in_array($type, $listed, true)) {
                if (isset($stored[$type->value])) {
                    $values[$type->value] = $stored[$type->value];
                }

                continue;
            }

            $entry = $posted[$type->value] ?? [];
            $singular = $this->clean($entry['singular'] ?? '');
            $plural = $this->clean($entry['plural'] ?? '');

            if (($singular === '' && $plural === '') || ($singular === $type->label() && $plural === $type->pluralLabel())) {
                continue;
            }

            $problem = $this->problem($type, $singular, $plural);

            if ($problem !== null) {
                $errors['label_' . $type->value . '_singular'] = $problem;
            }

            $values[$type->value] = ['singular' => $singular, 'plural' => $plural];
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * @param array<string, array{singular: string, plural: string}> $values
     * @return bool whether anything changed
     */
    public function save(array $values, User $actor, string $ipAddress): bool
    {
        if (!$actor->can(Capability::ManageSite)) {
            return false;
        }

        $current = $this->current();

        if ($values === $current) {
            return false;
        }

        $this->database->transaction(function () use ($values, $current, $actor, $ipAddress): void {
            $this->config->setSetting(ClassificationLabels::SETTING, $values);
            $this->audit->record($actor->id, 'settings.classification_labels.update', 'settings', 'classification', ['from' => $current, 'to' => $values], $ipAddress);
        });

        return true;
    }

    private function clean(mixed $value): string
    {
        $text = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';

        return $text;
    }

    private function problem(TermType $type, string $singular, string $plural): ?string
    {
        $name = $type->label();

        if ($singular === '' || $plural === '') {
            return 'Give the ' . strtolower($name) . ' list both a singular and a plural name, or leave both empty to use the usual words.';
        }

        foreach ([$singular, $plural] as $word) {
            if (mb_strlen($word, 'UTF-8') > ClassificationLabels::MAX_LENGTH) {
                return 'Names for the ' . strtolower($name) . ' list can be at most ' . ClassificationLabels::MAX_LENGTH . ' characters.';
            }

            if (preg_match(self::FORBIDDEN, $word) === 1 || str_contains($word, '<') || str_contains($word, '>')) {
                return 'Names for the ' . strtolower($name) . ' list cannot hold markup or hidden characters.';
            }
        }

        return null;
    }
}
