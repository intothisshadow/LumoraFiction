<?php

/**
 * The site-wide switches for story downloads: on or off, which formats, and whether counts are kept and shown.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\DownloadFormat;
use LumoraFiction\Models\User;

/**
 * Everything reads the values through the static helpers, so a damaged setting falls back to the default instead of
 * switching a feature on or off by accident.
 */
final class DownloadSettings
{
    public const DEFAULTS = [
        'downloads.enabled' => true,
        'downloads.formats' => ['epub', 'pdf', 'html'],
        'downloads.count' => true,
        'downloads.show_counts' => false,
    ];

    private const FLAGS = ['enabled' => 'downloads.enabled', 'count' => 'downloads.count', 'show_counts' => 'downloads.show_counts'];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    public static function enabled(Configuration $config): bool
    {
        return $config->setting('downloads.enabled', true) !== false;
    }

    public static function counting(Configuration $config): bool
    {
        return $config->setting('downloads.count', true) !== false;
    }

    /** Counts are shown only where they are kept, since a switched-off counter would show a frozen number. */
    public static function showingCounts(Configuration $config): bool
    {
        return self::counting($config) && $config->setting('downloads.show_counts', false) === true;
    }

    /**
     * The formats the administrator chose, in a fixed order. Which of them this server can really make is a separate
     * question, answered where the files are built.
     *
     * @return list<DownloadFormat>
     */
    public static function formats(Configuration $config): array
    {
        $chosen = $config->setting('downloads.formats', self::DEFAULTS['downloads.formats']);
        $chosen = is_array($chosen) ? $chosen : self::DEFAULTS['downloads.formats'];

        return array_values(array_filter(DownloadFormat::cases(), static fn (DownloadFormat $format): bool => in_array($format->value, $chosen, true)));
    }

    /**
     * @return array{enabled: bool, formats: list<string>, count: bool, show_counts: bool}
     */
    public function current(): array
    {
        return [
            'enabled' => self::enabled($this->config),
            'formats' => array_map(static fn (DownloadFormat $format): string => $format->value, self::formats($this->config)),
            'count' => self::counting($this->config),
            'show_counts' => $this->config->setting('downloads.show_counts', false) === true,
        ];
    }

    /**
     * @param list<string> $formats the format names that were ticked
     * @return array{errors: array<string, string>, values: array{enabled: bool, formats: list<string>, count: bool, show_counts: bool}}
     */
    public function validate(bool $enabled, array $formats, bool $count, bool $showCounts): array
    {
        $known = array_map(static fn (DownloadFormat $format): string => $format->value, DownloadFormat::cases());
        // Kept in the fixed order whatever order the browser sent them in.
        $chosen = array_values(array_filter($known, static fn (string $name): bool => in_array($name, $formats, true)));
        $errors = [];

        if ($enabled && $chosen === []) {
            $errors['formats'] = 'Choose at least one format, or switch downloads off.';
        }

        return ['errors' => $errors, 'values' => ['enabled' => $enabled, 'formats' => $chosen, 'count' => $count, 'show_counts' => $showCounts]];
    }

    /**
     * @param array{enabled: bool, formats: list<string>, count: bool, show_counts: bool} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (self::FLAGS as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($values['formats'] !== $current['formats']) {
            $changes['downloads.formats'] = ['from' => $current['formats'], 'to' => $values['formats']];
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            $this->audit->record($actor->id, 'settings.downloads.update', 'settings', 'downloads', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
