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
use LumoraFiction\Core\Http\UploadedFile;
use LumoraFiction\Core\Media\CoverImageException;
use LumoraFiction\Models\User;

/**
 * A theme declares its options through the theme_option_fields filter, from its functions file. An option that
 * sets a CSS variable always starts as "use the theme's own value" (an empty string) and writes nothing to the
 * stylesheet until an administrator picks something, because the generated stylesheet loads after the theme's
 * own and would otherwise replace a design choice the theme's author made on purpose.
 *
 * Pictures reach a theme in two ways. An image field stores one uploaded picture. A select that names a picture
 * variable also offers every usable picture in the pictures folder and, once one is uploaded, the uploaded
 * picture; the chosen one is handed to the stylesheet as that variable, so no template needs an inline style.
 *
 * @phpstan-type Field array{key: string, label: string, type: string, css_variable: string, choices: array<string, string>, default: string|bool, hint: string, pictures: array{variable: string, upload: string}|null}
 */
final class ThemeOptions
{
    private const TYPES = ['color', 'select', 'checkbox', 'image'];

    /** The choice that stands for the uploaded picture of the image field a select names. */
    public const UPLOAD_CHOICE = 'upload';

    /** Choices for pictures found in the pictures folder start with this, followed by the file name. */
    public const FILE_PREFIX = 'file:';

    /** Choice values of a select that feeds a CSS variable end up inside a stylesheet, so they stay plain. */
    private const CSS_VALUE = '/^[A-Za-z0-9.%,() _-]{1,60}\z/';

    public function __construct(
        private readonly Configuration $config,
        private readonly HookManager $hooks,
        private readonly AuditLogService $audit,
        private readonly ThemePictures $pictures,
    ) {
    }

    /**
     * The declared options, with anything malformed dropped so one careless entry cannot break the screen.
     *
     * @return list<Field>
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
     * Whether the theme offers any picture upload, so the form knows it has to be sent as a file form.
     */
    public function hasImageField(): bool
    {
        foreach ($this->fields() as $field) {
            if ($field['type'] === 'image') {
                return true;
            }
        }

        return false;
    }

    /**
     * The keys of the options that hold an uploaded picture.
     *
     * @return list<string>
     */
    public function imageKeys(): array
    {
        $keys = [];

        foreach ($this->fields() as $field) {
            if ($field['type'] === 'image') {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /**
     * The fields as the options screen draws them: a select that can show the uploaded picture lists it once there
     * is one, and an image field carries the address of its preview.
     *
     * @param array<string, string|bool> $values
     * @return list<Field&array{preview: string}>
     */
    public function displayFields(array $values): array
    {
        $images = $this->imagesFrom($this->fields(), $values);
        $shown = [];

        foreach ($this->fields() as $field) {
            $field['preview'] = '';

            if ($field['type'] === 'image' && ($images[$field['key']] ?? '') !== '') {
                $field['preview'] = $this->pictures->uploads()->url($images[$field['key']], true);
            }

            if ($field['type'] === 'select' && $this->uploadOffered($field, $images)) {
                $field['choices'][self::UPLOAD_CHOICE] = 'Uploaded picture';
            }

            $shown[] = $field;
        }

        return $shown;
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
        $fields = $this->fields();
        $images = $this->imagesFrom($fields, $stored);
        $values = [];

        foreach ($fields as $field) {
            $candidate = $stored[$field['key']] ?? $field['default'];
            $values[$field['key']] = $this->accept($field, $candidate, $images) ? $candidate : $field['default'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $input option key => submitted value (a colour left as "use the theme's" is an empty string;
     *                                    a picture left out keeps the one the theme has now)
     * @return array{errors: array<string, string>, values: array<string, string|bool>}
     */
    public function validate(string $themeSlug, array $input): array
    {
        $errors = [];
        $values = [];
        $current = $this->values($themeSlug);
        $fields = $this->fields();
        $images = $this->imagesFrom($fields, $input + $current);

        foreach ($fields as $field) {
            $key = $field['key'];
            $candidate = $input[$key] ?? match ($field['type']) {
                'checkbox' => false,
                'image' => $current[$key],
                default => '',
            };

            // A picture removed in the same save leaves nothing for "Uploaded picture" to point at, so the theme's own choice returns.
            if ($field['type'] === 'select' && $candidate === self::UPLOAD_CHOICE && !$this->uploadOffered($field, $images)) {
                $candidate = $field['default'];
            }

            if ($this->accept($field, $candidate, $images)) {
                $values[$key] = $candidate;

                continue;
            }

            $errors[$key] = match ($field['type']) {
                'color' => 'Enter a colour such as #336699, or use the theme\'s own.',
                'image' => 'That picture is no longer available. Upload it again.',
                default => 'Choose one of the listed values.',
            };
            $values[$key] = $current[$key];
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * Stores the pictures a form sent and works out which picture each image option should now hold. A failure
     * keeps the picture the option holds now and is reported for that option.
     *
     * @param array<string, array{upload: ?UploadedFile, remove: bool}> $submitted option key => what the form sent for it
     * @return array{images: array<string, string>, errors: array<string, string>, created: list<string>} created lists the new
     *         files, so the caller can discard them when the save does not go ahead
     */
    public function receivePictures(string $themeSlug, array $submitted): array
    {
        $current = $this->values($themeSlug);
        $images = [];
        $errors = [];
        $created = [];

        foreach ($this->fields() as $field) {
            if ($field['type'] !== 'image') {
                continue;
            }

            $key = $field['key'];
            $sent = $submitted[$key] ?? ['upload' => null, 'remove' => false];
            $images[$key] = is_string($current[$key] ?? null) ? $current[$key] : '';
            $file = $sent['upload'];

            if ($file !== null && $file->wasSent()) {
                if ($file->problem() !== '') {
                    $errors[$key] = $file->problem();

                    continue;
                }

                try {
                    $name = $this->pictures->uploads()->save($file->path);
                    $created[] = $name;
                    $images[$key] = $name;
                } catch (CoverImageException $exception) {
                    $errors[$key] = $exception->getMessage();
                }
            } elseif ($sent['remove']) {
                $images[$key] = '';
            }
        }

        return ['images' => $images, 'errors' => $errors, 'created' => $created];
    }

    /**
     * Removes pictures that were stored for a save that did not go ahead.
     *
     * @param list<string> $created
     */
    public function discardPictures(array $created): void
    {
        foreach ($created as $name) {
            $this->pictures->uploads()->delete($name);
        }
    }

    /**
     * Stores only what differs from the defaults, and records which options changed. Returns their keys. A picture
     * that is replaced or removed is deleted from disk, so nothing is left behind that no option points at.
     *
     * @param array<string, string|bool> $values
     * @return list<string>
     */
    public function save(string $themeSlug, array $values, User $actor, string $ipAddress): array
    {
        $before = $this->values($themeSlug);
        $changed = [];
        $keep = [];
        $replaced = [];

        foreach ($this->fields() as $field) {
            $key = $field['key'];
            // A caller that does not mention a picture (a settings import) leaves it as it is.
            $value = $values[$key] ?? ($field['type'] === 'image' ? ($before[$key] ?? '') : $field['default']);

            if (($before[$key] ?? null) !== $value) {
                $changed[] = $key;

                if ($field['type'] === 'image' && is_string($before[$key] ?? null) && $before[$key] !== '') {
                    $replaced[] = $before[$key];
                }
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
        $this->discardPictures($replaced);

        return $changed;
    }

    /**
     * Drops everything stored for a theme, used when the theme is deleted so a later install starts clean. Pictures
     * uploaded for it go too; they are found by what is stored, because the theme's own fields are not loaded then.
     */
    public function forget(string $themeSlug): void
    {
        $stored = $this->config->setting(self::settingName($themeSlug), []);

        foreach (is_array($stored) ? $stored : [] as $value) {
            if (is_string($value)) {
                $this->pictures->uploads()->delete($value);
            }
        }

        $this->config->forgetSetting(self::settingName($themeSlug));
    }

    /**
     * The stylesheet for a theme's options, empty while nothing has been chosen.
     */
    public function css(string $themeSlug): string
    {
        $values = $this->values($themeSlug);
        $fields = $this->fields();
        $images = $this->imagesFrom($fields, $values);
        $lines = [];

        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? '';

            if (!is_string($value) || $value === '') {
                continue;
            }

            if ($field['type'] === 'image') {
                $address = $field['css_variable'] === '' ? '' : $this->pictures->uploads()->url($value);

                $this->addPicture($lines, $field['css_variable'], $address);
            } elseif ($field['css_variable'] !== '' && $this->accept($field, $value, $images)) {
                $lines[] = '    ' . $field['css_variable'] . ': ' . $value . ';';
            }

            if ($field['type'] === 'select' && $field['pictures'] !== null) {
                $address = match (true) {
                    str_starts_with($value, self::FILE_PREFIX) => $this->pictures->folderUrl(substr($value, strlen(self::FILE_PREFIX))),
                    $value === self::UPLOAD_CHOICE && $field['pictures']['upload'] !== '' => $this->pictures->uploads()->url($images[$field['pictures']['upload']] ?? ''),
                    default => '',
                };

                $this->addPicture($lines, $field['pictures']['variable'], $address);
            }
        }

        return $lines === [] ? '' : ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    /**
     * @param list<string> $lines
     */
    private function addPicture(array &$lines, string $variable, string $address): void
    {
        // The address is made from a base path and a validated name; a character outside this set would need escaping, so it is skipped instead.
        if ($variable !== '' && $address !== '' && preg_match('#^[A-Za-z0-9._/~-]+\z#', $address) === 1) {
            $lines[] = '    ' . $variable . ': url("' . $address . '");';
        }
    }

    private static function settingName(string $themeSlug): string
    {
        // Setting names cannot hold a hyphen; theme slugs cannot hold an underscore, so the mapping stays one to one.
        return 'theme.options.' . str_replace('-', '_', $themeSlug);
    }

    /**
     * The picture each image option holds, taken from a set of values; a value that is not a stored picture counts as none.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $source
     * @return array<string, string> option key => file name, or an empty string
     */
    private function imagesFrom(array $fields, array $source): array
    {
        $images = [];

        foreach ($fields as $field) {
            if ($field['type'] === 'image') {
                $value = $source[$field['key']] ?? '';
                $images[$field['key']] = is_string($value) && $this->pictures->uploads()->exists($value) ? $value : '';
            }
        }

        return $images;
    }

    /**
     * @param Field $field
     * @param array<string, string> $images
     */
    private function uploadOffered(array $field, array $images): bool
    {
        return $field['pictures'] !== null && $field['pictures']['upload'] !== '' && ($images[$field['pictures']['upload']] ?? '') !== '';
    }

    /**
     * @param Field $field
     * @param array<string, string> $images pictures the image options hold, which decide whether "Uploaded picture" is a valid choice
     */
    private function accept(array $field, mixed $value, array $images = []): bool
    {
        return match ($field['type']) {
            'checkbox' => is_bool($value),
            'color' => is_string($value) && ($value === '' || preg_match('/^#[0-9a-fA-F]{6}\z/', $value) === 1),
            'select' => is_string($value) && (($value === '' && $field['css_variable'] !== '') || isset($field['choices'][$value]) || ($value === self::UPLOAD_CHOICE && $this->uploadOffered($field, $images))),
            'image' => is_string($value) && ($value === '' || $this->pictures->uploads()->exists($value)),
            default => false,
        };
    }

    /**
     * @return Field|null
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
        $pictures = null;

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

            $pictures = $this->picturesSetting($field['pictures'] ?? null);

            if ($pictures !== null) {
                foreach ($this->pictures->folder() as $name => $label) {
                    $choices[self::FILE_PREFIX . $name] = $label;
                }
            }
        }

        // An image holds a file name, never a typed value, so it has no default and the form never submits one.
        $default = $field['type'] === 'image' ? '' : ($field['default'] ?? ($field['type'] === 'checkbox' ? false : ''));

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
            'pictures' => $pictures,
        ];

        if (!$this->accept($clean, $default)) {
            $clean['default'] = $field['type'] === 'checkbox' ? false : '';

            if ($field['type'] === 'select' && $variable === '') {
                $clean['default'] = (string) array_key_first($choices);
            }
        }

        return $clean;
    }

    /**
     * @return array{variable: string, upload: string}|null
     */
    private function picturesSetting(mixed $setting): ?array
    {
        if (
            !is_array($setting)
            || !is_string($setting['variable'] ?? null) || preg_match('/^--[a-z][a-z0-9-]{0,59}\z/', $setting['variable']) !== 1
        ) {
            return null;
        }

        $upload = $setting['upload'] ?? '';

        return [
            'variable' => $setting['variable'],
            'upload' => is_string($upload) && preg_match('/^[a-z][a-z0-9_]{0,39}\z/', $upload) === 1 ? $upload : '',
        ];
    }
}
