<?php

/**
 * Starter lists for ratings, archive warnings, relationship categories and story status, read from bundled files.
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

use JsonException;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;

/**
 * Presets are a starting point and never a requirement: nothing is applied unless someone chooses it, applying only adds
 * what is missing, and nothing an administrator renamed, hid, described or reordered is overwritten. Each value has a
 * stable key, which is how a later application tells a value that was edited from one that was never made. Nothing is
 * fetched from another site, and the files are read from the application folder, so the lists work after the installer
 * has gone.
 */
final class ClassificationPresets
{
    /** The only file format this code understands; a file that says otherwise is refused rather than guessed at. */
    public const FORMAT = 1;

    /** The lists that have starter files, in the order the screens show them. */
    public const KINDS = ['rating', 'warning', 'relationship_category', 'status'];

    public const KIND_LABELS = [
        'rating' => 'Ratings',
        'warning' => 'Archive warnings',
        'relationship_category' => 'Relationship categories',
        'status' => 'Story status',
    ];

    /** Remembers what was applied to each list last, only to say so on the screen. */
    public const SETTING_APPLIED = 'classification.presets';

    private const KEY_PATTERN = '/^[a-z0-9_]{1,40}\z/';

    private const LABEL_MAX = 80;

    private const DESCRIPTION_MAX = 300;

    /** @var array<string, array<string, array{label: string, description: string, values: list<array{key: string, name: string, description: string}>}>> */
    private array $loaded = [];

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly TaxonomyService $taxonomy,
        private readonly AuditLogService $audit,
        private readonly string $dataPath,
    ) {
    }

    /**
     * @return array<string, array{label: string, description: string, values: list<array{key: string, name: string, description: string}>}> by preset key
     * @throws PresetException when the file is missing or not acceptable
     */
    public function presetsFor(string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new PresetException('There is no starter list for that.');
        }

        if (!isset($this->loaded[$kind])) {
            $file = $this->dataPath . '/' . $kind . '.json';
            $json = is_file($file) ? file_get_contents($file) : false;

            if ($json === false) {
                throw new PresetException('The starter lists for ' . self::KIND_LABELS[$kind] . ' are missing.');
            }

            $this->loaded[$kind] = self::parse($json, $kind);
        }

        return $this->loaded[$kind];
    }

    /**
     * Every list with its presets, for a picker. A list whose file cannot be read is left out, so one damaged file
     * cannot take the screen down.
     *
     * @return array<string, array<string, array{label: string, description: string, values: list<array{key: string, name: string, description: string}>}>>
     */
    public function catalogue(): array
    {
        $catalogue = [];

        foreach (self::KINDS as $kind) {
            try {
                $catalogue[$kind] = $this->presetsFor($kind);
            } catch (PresetException $exception) {
                error_log('[ClassificationPresets] ' . $exception->getMessage());
            }
        }

        return $catalogue;
    }

    /**
     * The same as catalogue(), read straight from the files, for the installer, which has no database to ask yet.
     *
     * @return array<string, array<string, array{label: string, description: string, values: list<array{key: string, name: string, description: string}>}>>
     */
    public static function catalogueFromFiles(string $dataPath): array
    {
        $catalogue = [];

        foreach (self::KINDS as $kind) {
            $json = is_file($dataPath . '/' . $kind . '.json') ? file_get_contents($dataPath . '/' . $kind . '.json') : false;

            try {
                if ($json !== false) {
                    $catalogue[$kind] = self::parse($json, $kind);
                }
            } catch (PresetException $exception) {
                error_log('[ClassificationPresets] ' . $exception->getMessage());
            }
        }

        return $catalogue;
    }

    /**
     * Checks a file's text and returns its presets by key.
     *
     * @return array<string, array{label: string, description: string, values: list<array{key: string, name: string, description: string}>}>
     * @throws PresetException
     */
    public static function parse(string $json, string $kind): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PresetException('The starter list file is not valid JSON.');
        }

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new PresetException('The starter list file is in a format this version does not understand.');
        }

        if (($data['kind'] ?? null) !== $kind) {
            throw new PresetException('The starter list file is for a different list.');
        }

        if (!is_array($data['presets'] ?? null) || $data['presets'] === []) {
            throw new PresetException('The starter list file has no presets.');
        }

        $presets = [];

        foreach ($data['presets'] as $preset) {
            $key = is_array($preset) ? ($preset['key'] ?? null) : null;

            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || isset($presets[$key])) {
                throw new PresetException('A preset has a missing, malformed or repeated key.');
            }

            $label = $preset['label'] ?? null;
            $description = $preset['description'] ?? '';

            if (!is_string($label) || trim($label) === '' || mb_strlen($label) > self::LABEL_MAX || !is_string($description) || mb_strlen($description) > self::DESCRIPTION_MAX) {
                throw new PresetException('A preset has a missing or overlong label or description.');
            }

            $presets[$key] = ['label' => trim($label), 'description' => trim($description), 'values' => self::parseValues($preset['values'] ?? null, $kind)];
        }

        return $presets;
    }

    /**
     * @return list<array{key: string, name: string, description: string}>
     * @throws PresetException
     */
    private static function parseValues(mixed $values, string $kind): array
    {
        if (!is_array($values) || $values === []) {
            throw new PresetException('A preset has no values.');
        }

        $seen = [];
        $names = [];
        $clean = [];

        foreach ($values as $value) {
            $key = is_array($value) ? ($value['key'] ?? null) : null;
            $name = is_array($value) ? ($value['name'] ?? null) : null;
            $description = is_array($value) ? ($value['description'] ?? '') : '';

            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || isset($seen[$key])) {
                throw new PresetException('A value has a missing, malformed or repeated key.');
            }

            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > TaxonomyService::NAME_MAX || str_contains($name, ',') || !is_string($description) || mb_strlen($description) > self::DESCRIPTION_MAX) {
                throw new PresetException('A value has a missing, overlong or malformed name or description.');
            }

            if ($kind === 'status' && WorkStatus::tryFrom($key) === null) {
                throw new PresetException('A status value is not one the archive knows.');
            }

            $folded = mb_strtolower(trim($name));

            if (isset($names[$folded])) {
                throw new PresetException('Two values in a preset share a name.');
            }

            $seen[$key] = true;
            $names[$folded] = true;
            $clean[] = ['key' => $key, 'name' => trim($name), 'description' => trim($description)];
        }

        return $clean;
    }

    /**
     * What was last applied to each list, by list.
     *
     * @return array<string, string> list => preset key
     */
    public function applied(): array
    {
        $stored = $this->config->setting(self::SETTING_APPLIED);

        return is_array($stored) ? array_filter($stored, static fn (mixed $value, mixed $key): bool => is_string($value) && is_string($key) && in_array($key, self::KINDS, true), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * Adds the values a preset names that the list does not have yet. A value already there, made from this preset or
     * typed by hand with the same name, is left exactly as the administrator has it. Applying it again later only fills
     * in what was deleted.
     *
     * @return array{created: int, existing: int, skipped: int} how many were added, were already there, and could not be added
     * @throws PresetException
     */
    public function apply(User $actor, string $kind, string $presetKey, string $ipAddress = ''): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new PresetException('You may not change the classification lists.');
        }

        $presets = $this->presetsFor($kind);

        if (!isset($presets[$presetKey])) {
            throw new PresetException('That starter list does not exist.');
        }

        $preset = $presets[$presetKey];

        $result = $kind === 'status' ? $this->applyStatus($preset['values']) : $this->applyTerms(TermType::from($kind), $actor, $preset['values']);

        $applied = $this->applied();
        $applied[$kind] = $presetKey;

        $this->database->transaction(function () use ($actor, $kind, $presetKey, $result, $applied, $ipAddress): void {
            $this->config->setSetting(self::SETTING_APPLIED, $applied);
            $this->audit->record($actor->id, 'preset.apply', 'classification', $kind, ['preset' => $presetKey] + $result, $ipAddress);
        });

        return $result;
    }

    /**
     * @param list<array{key: string, name: string, description: string}> $values
     * @return array{created: int, existing: int, skipped: int}
     */
    private function applyTerms(TermType $type, User $actor, array $values): array
    {
        $result = ['created' => 0, 'existing' => 0, 'skipped' => 0];

        foreach ($values as $value) {
            $key = $type->value . '.' . $value['key'];

            if ($this->taxonomy->findByPresetKey($type, $key) !== null) {
                $result['existing']++;

                continue;
            }

            try {
                $clean = $this->taxonomy->cleanName($value['name']);
                $byHand = $this->taxonomy->resolve($type, $clean, 0);

                if ($byHand !== null) {
                    $this->taxonomy->adoptPresetKey($byHand, $key, $value['description']);
                    $result['existing']++;

                    continue;
                }

                $this->taxonomy->createFromPreset($type, $clean, $value['description'], $key, $actor->id);
                $result['created']++;
            } catch (TaxonomyException) {
                // A name that collides with an alias, or that cannot be used, is left out rather than stopping the rest.
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * Story status is not a list of editable terms, because the archive's own logic depends on what each status means.
     * A preset says which of them writers can choose.
     *
     * @param list<array{key: string, name: string, description: string}> $values
     * @return array{created: int, existing: int, skipped: int}
     */
    private function applyStatus(array $values): array
    {
        $this->config->setSetting('stories.allow_abandoned', in_array('abandoned', array_column($values, 'key'), true));

        return ['created' => 0, 'existing' => count($values), 'skipped' => 0];
    }
}
