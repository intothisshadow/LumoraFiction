<?php

/**
 * The options a theme offers on the Appearance screen, their stored values, and the stylesheet they produce.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\User;

/**
 * A theme declares its options through the theme_option_fields filter, from its functions file. An option that
 * sets a CSS variable always starts as "use the theme's own value" (an empty string) and writes nothing to the
 * stylesheet until an administrator picks something, because the generated stylesheet loads after the theme's
 * own and would otherwise replace a design choice the theme's author made on purpose.
 */
final class ThemeOptions
{
    private const TYPES = ['color', 'select', 'checkbox'];

    /** Choice values of a select that feeds a CSS variable end up inside a stylesheet, so they stay plain. */
    private const CSS_VALUE = '/^[A-Za-z0-9.%,() _-]{1,60}\z/';

    public function __construct(
        private readonly Configuration $config,
        private readonly HookManager $hooks,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The declared options, with anything malformed dropped so one careless entry cannot break the screen.
     *
     * @return list<array{key: string, label: string, type: string, css_variable: string, choices: array<string, string>, default: string|bool, hint: string}>
     */
    public function fields(): array
    {
        $declared = $this->hooks->applyFilters('theme_option_fields', []);
        $fields = [];

        foreach (is_array($declared) ? $declared : [] as $field) {
            $clean = $this->normalise($field);

            if ($clean !== null && !isset($fields[$clean['key']])) {
                $fields[$clean['key']] = $clean;
            }
        }

        return array_values($fields);
    }

    /**
     * The value of every declared option for a theme: the stored one when it is still valid, otherwise the default.
     *
     * @return array<string, string|bool> option key => value
     */
    public function values(string $themeSlug): array
    {
        $stored = $this->config->setting(self::settingName($themeSlug), []);
        $stored = is_array($stored) ? $stored : [];
        $values = [];

        foreach ($this->fields() as $field) {
            $candidate = $stored[$field['key']] ?? $field['default'];
            $values[$field['key']] = $this->accept($field, $candidate) ? $candidate : $field['default'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $input option key => submitted value (a colour left as "use the theme's" is an empty string)
     * @return array{errors: array<string, string>, values: array<string, string|bool>}
     */
    public function validate(string $themeSlug, array $input): array
    {
        $errors = [];
        $values = [];
        $current = $this->values($themeSlug);

        foreach ($this->fields() as $field) {
            $key = $field['key'];
            $candidate = $input[$key] ?? ($field['type'] === 'checkbox' ? false : '');

            if ($this->accept($field, $candidate)) {
                $values[$key] = $candidate;

                continue;
            }

            $errors[$key] = $field['type'] === 'color' ? 'Enter a colour such as #336699, or use the theme\'s own.' : 'Choose one of the listed values.';
            $values[$key] = $current[$key];
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * Stores only what differs from the defaults, and records which options changed. Returns their keys.
     *
     * @param array<string, string|bool> $values
     * @return list<string>
     */
    public function save(string $themeSlug, array $values, User $actor, string $ipAddress): array
    {
        $before = $this->values($themeSlug);
        $changed = [];
        $keep = [];

        foreach ($this->fields() as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? $field['default'];

            if (($before[$key] ?? null) !== $value) {
                $changed[] = $key;
            }

            if ($value !== $field['default']) {
                $keep[$key] = $value;
            }
        }

        if ($changed === []) {
            return [];
        }

        $this->config->setSetting(self::settingName($themeSlug), $keep);
        $this->audit->record($actor->id, 'settings.theme_options.update', 'theme', $themeSlug, ['options' => $changed], $ipAddress);

        return $changed;
    }

    /**
     * Drops everything stored for a theme, used when the theme is deleted so a later install starts clean.
     */
    public function forget(string $themeSlug): void
    {
        $this->config->forgetSetting(self::settingName($themeSlug));
    }

    /**
     * The stylesheet for a theme's options, empty while nothing has been chosen.
     */
    public function css(string $themeSlug): string
    {
        $values = $this->values($themeSlug);
        $lines = [];

        foreach ($this->fields() as $field) {
            $value = $values[$field['key']] ?? '';

            if ($field['css_variable'] !== '' && is_string($value) && $value !== '' && $this->accept($field, $value)) {
                $lines[] = '    ' . $field['css_variable'] . ': ' . $value . ';';
            }
        }

        return $lines === [] ? '' : ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    private static function settingName(string $themeSlug): string
    {
        // Setting names cannot hold a hyphen; theme slugs cannot hold an underscore, so the mapping stays one to one.
        return 'theme.options.' . str_replace('-', '_', $themeSlug);
    }

    /**
     * @param array{key: string, label: string, type: string, css_variable: string, choices: array<string, string>, default: string|bool, hint: string} $field
     */
    private function accept(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'checkbox' => is_bool($value),
            'color' => is_string($value) && ($value === '' || preg_match('/^#[0-9a-fA-F]{6}\z/', $value) === 1),
            'select' => is_string($value) && (($value === '' && $field['css_variable'] !== '') || isset($field['choices'][$value])),
            default => false,
        };
    }

    /**
     * @return array{key: string, label: string, type: string, css_variable: string, choices: array<string, string>, default: string|bool, hint: string}|null
     */
    private function normalise(mixed $field): ?array
    {
        if (
            !is_array($field)
            || !is_string($field['key'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,39}\z/', $field['key']) !== 1
            || !is_string($field['label'] ?? null) || $field['label'] === ''
            || !is_string($field['type'] ?? null) || !in_array($field['type'], self::TYPES, true)
        ) {
            return null;
        }

        $variable = $field['css_variable'] ?? '';

        if ($variable !== '' && (!is_string($variable) || preg_match('/^--[a-z][a-z0-9-]{0,59}\z/', $variable) !== 1)) {
            return null;
        }

        $choices = [];

        if ($field['type'] === 'select') {
            foreach (is_array($field['choices'] ?? null) ? $field['choices'] : [] as $value => $label) {
                $value = (string) $value;

                if ($value !== '' && is_string($label) && $label !== '' && ($variable === '' || preg_match(self::CSS_VALUE, $value) === 1)) {
                    $choices[$value] = $label;
                }
            }

            if ($choices === []) {
                return null;
            }
        }

        $default = $field['default'] ?? ($field['type'] === 'checkbox' ? false : '');

        // The rule that protects the theme author's design: a CSS variable option never has a default of its own.
        if ($variable !== '') {
            $default = '';
        }

        $clean = [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'css_variable' => $variable,
            'choices' => $choices,
            'default' => $default,
            'hint' => is_string($field['hint'] ?? null) ? $field['hint'] : '',
        ];

        if (!$this->accept($clean, $default)) {
            $clean['default'] = $field['type'] === 'checkbox' ? false : '';

            if ($field['type'] === 'select' && $variable === '') {
                $clean['default'] = (string) array_key_first($choices);
            }
        }

        return $clean;
    }
}
