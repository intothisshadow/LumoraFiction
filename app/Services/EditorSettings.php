<?php

/**
 * Which editing mode writers get: the site default, an optional lock, and each writer's own choice.
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
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\EditorMode;
use LumoraFiction\Models\User;

/**
 * A stored value that is not a known mode counts as "not chosen" so a corrupt or removed mode falls back
 * to the site default instead of leaving a writer without an editor.
 */
final class EditorSettings
{
    public const DEFAULTS = [
        'editor.default_mode' => 'visual',
        'editor.lock_to_default' => false,
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return array{default_mode: string, lock_to_default: bool}
     */
    public function current(): array
    {
        $mode = $this->config->setting('editor.default_mode');

        return [
            'default_mode' => is_string($mode) && EditorMode::tryFrom($mode) !== null ? $mode : EditorMode::Visual->value,
            // Anything other than a real "true" counts as unlocked, matching how the other switches are read.
            'lock_to_default' => $this->config->setting('editor.lock_to_default') === true,
        ];
    }

    /**
     * @param array{default_mode: ?string, lock_to_default: bool} $input
     * @return array{errors: array<string, string>, values: array{default_mode: string, lock_to_default: bool}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $mode = $input['default_mode'];

        if ($mode === null || EditorMode::tryFrom($mode) === null) {
            $errors['default_mode'] = 'Choose the editor writers start with.';
            $mode = $this->current()['default_mode'];
        }

        return ['errors' => $errors, 'values' => ['default_mode' => $mode, 'lock_to_default' => $input['lock_to_default']]];
    }

    /**
     * Saves only what changed and records it in the same transaction. Returns the names of the settings that changed.
     *
     * @param array{default_mode: string, lock_to_default: bool} $values
     * @return list<string>
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        $current = $this->current();
        $changes = [];

        foreach (['default_mode' => 'editor.default_mode', 'lock_to_default' => 'editor.lock_to_default'] as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $values, $actor, $ipAddress): void {
            foreach (['default_mode' => 'editor.default_mode', 'lock_to_default' => 'editor.lock_to_default'] as $field => $setting) {
                if (isset($changes[$setting])) {
                    $this->config->setSetting($setting, $values[$field]);
                }
            }

            $this->audit->record($actor->id, 'settings.editor.update', 'settings', 'editor', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    public function siteDefault(): EditorMode
    {
        return EditorMode::from($this->current()['default_mode']);
    }

    public function isLocked(): bool
    {
        return $this->current()['lock_to_default'];
    }

    /**
     * The mode the editor opens in. A new chapter uses the writer's choice, or the site default. A chapter
     * that already exists opens in the mode matching how it is stored, so nothing is converted just by
     * opening it, unless the site is locked to one mode, in which case the editor converts it.
     */
    public function modeFor(?int $userId, ?ContentFormat $stored = null): EditorMode
    {
        $preferred = $this->isLocked() ? $this->siteDefault() : ($userId === null ? null : $this->userMode($userId)) ?? $this->siteDefault();

        if ($stored === null || $this->isLocked() || $preferred->format() === $stored) {
            return $preferred;
        }

        return EditorMode::forFormat($stored);
    }

    public function userMode(int $userId): ?EditorMode
    {
        $value = $this->database->fetchColumn('SELECT editor_mode FROM {prefix}users WHERE id = :id', ['id' => $userId]);

        return is_string($value) ? EditorMode::tryFrom($value) : null;
    }

    /**
     * Passing null returns the writer to the site default.
     */
    public function setUserMode(int $userId, ?EditorMode $mode): void
    {
        $this->database->execute(
            'UPDATE {prefix}users SET editor_mode = :mode WHERE id = :id',
            ['mode' => $mode?->value, 'id' => $userId],
        );
    }
}
