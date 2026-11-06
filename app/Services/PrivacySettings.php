<?php

/**
 * The privacy choices an administrator makes: which page is the privacy policy and what happens to the stories of a deleted account.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Models\User;

/**
 * The privacy policy is an ordinary static page the administrator names here, so the policy text is written and
 * published like any other page and this class only remembers which one it is. A page that is later unpublished or
 * deleted simply stops being linked; nothing breaks.
 */
final class PrivacySettings
{
    public const DEFAULTS = [
        'privacy.page_id' => 0,
        'privacy.deleted_stories' => 'keep',
        'privacy.neutral_name' => 'Anonymous author',
    ];

    public const STORY_CHOICES = [
        'keep' => 'Keep the stories, shown under a neutral author name',
        'delete' => 'Delete the stories with the account',
    ];

    public const NAME_MAX = 100;

    /** Form field => setting name. */
    private const FIELDS = [
        'page_id' => 'privacy.page_id',
        'deleted_stories' => 'privacy.deleted_stories',
        'neutral_name' => 'privacy.neutral_name',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
        private readonly PageService $pages,
    ) {
    }

    /**
     * @return array{page_id: int, deleted_stories: string, neutral_name: string}
     */
    public function current(): array
    {
        $page = $this->config->setting('privacy.page_id');
        $choice = $this->config->setting('privacy.deleted_stories');
        $name = $this->config->setting('privacy.neutral_name');

        return [
            'page_id' => is_int($page) && $page > 0 ? $page : 0,
            'deleted_stories' => is_string($choice) && isset(self::STORY_CHOICES[$choice]) ? $choice : 'keep',
            'neutral_name' => is_string($name) && trim($name) !== '' ? $name : self::DEFAULTS['privacy.neutral_name'],
        ];
    }

    /**
     * The name a kept story is shown under once its account is deleted.
     */
    public function neutralName(): string
    {
        return $this->current()['neutral_name'];
    }

    public function keepsStories(): bool
    {
        return $this->current()['deleted_stories'] === 'keep';
    }

    /**
     * The privacy policy as a link a theme can print, or null while no published page is named.
     *
     * @return array{label: string, url: string}|null
     */
    public function link(): ?array
    {
        $id = $this->current()['page_id'];

        if ($id === 0) {
            return null;
        }

        foreach ($this->pages->published() as $page) {
            if ($page->id === $id) {
                return ['label' => $page->title, 'url' => PublicUrl::page($page->slug)];
            }
        }

        return null;
    }

    /**
     * @param array{page_id: string|null, deleted_stories: string|null, neutral_name: string|null} $input
     * @return array{errors: array<string, string>, values: array{page_id: int, deleted_stories: string, neutral_name: string}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->current();

        $pageInput = trim((string) $input['page_id']);
        $page = $current['page_id'];

        if ($pageInput === '' || $pageInput === '0') {
            $page = 0;
        } elseif (preg_match('/^[1-9][0-9]{0,9}\z/', $pageInput) === 1 && isset($this->pages->choices()[(int) $pageInput])) {
            $page = (int) $pageInput;
        } else {
            $errors['page_id'] = 'Choose one of the published pages, or none.';
        }

        $choice = $input['deleted_stories'];

        if ($choice === null || !isset(self::STORY_CHOICES[$choice])) {
            $errors['deleted_stories'] = 'Choose what happens to the stories of a deleted account.';
            $choice = $current['deleted_stories'];
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $input['neutral_name']));

        if ($name === '') {
            $errors['neutral_name'] = 'Enter a name to show on kept stories.';
            $name = $current['neutral_name'];
        } elseif (!mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $name) === 1) {
            $errors['neutral_name'] = 'That name contains characters that cannot be used.';
            $name = $current['neutral_name'];
        } elseif (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            $errors['neutral_name'] = 'Keep the name to ' . self::NAME_MAX . ' characters or fewer.';
            $name = $current['neutral_name'];
        }

        return ['errors' => $errors, 'values' => ['page_id' => $page, 'deleted_stories' => $choice, 'neutral_name' => $name]];
    }

    /**
     * Saves only what changed and records it in the same transaction. Returns the names of the settings that changed.
     *
     * @param array{page_id: int, deleted_stories: string, neutral_name: string} $values
     * @return list<string>
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        $current = $this->current();
        $changes = [];

        foreach (self::FIELDS as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $values, $actor, $ipAddress): void {
            foreach (self::FIELDS as $field => $setting) {
                if (isset($changes[$setting])) {
                    $this->config->setSetting($setting, $values[$field]);
                }
            }

            $this->audit->record($actor->id, 'settings.privacy.update', 'settings', 'privacy', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
