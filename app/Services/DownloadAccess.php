<?php

/**
 * The one answer to "may readers download this story": site switch, then the story's choice, then its Author's default.
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
use LumoraFiction\Models\DownloadChoice;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;

/**
 * The page's download menu, the download route and the file builder all ask this class, so a story that cannot be
 * downloaded is hidden and refused for the same reason everywhere. Whether the story is public is a separate rule
 * (StoryVisibility) that the route applies before it asks.
 */
final class DownloadAccess
{
    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly StoryService $stories,
        private readonly AuditLogService $audit,
    ) {
    }

    public function allows(Story $story): bool
    {
        // An Author's choices have no effect while the site switch is off.
        if (!DownloadSettings::enabled($this->config)) {
            return false;
        }

        $row = $this->database->fetchOne(
            'SELECT s.download_choice, u.downloads_default FROM {prefix}stories s LEFT JOIN {prefix}users u ON u.id = s.owner_id WHERE s.id = :id',
            ['id' => $story->id],
        );

        if ($row === null) {
            return false;
        }

        return match (DownloadChoice::tryFrom((string) $row['download_choice']) ?? DownloadChoice::UseDefault) {
            DownloadChoice::Allow => true,
            DownloadChoice::Refuse => false,
            // An account that no longer exists leaves the story with the default, which is to allow.
            DownloadChoice::UseDefault => ($row['downloads_default'] ?? 1) !== 0 && ($row['downloads_default'] ?? 1) !== '0',
        };
    }

    public function choiceFor(int $storyId): DownloadChoice
    {
        return DownloadChoice::tryFrom((string) $this->database->fetchColumn('SELECT download_choice FROM {prefix}stories WHERE id = :id', ['id' => $storyId])) ?? DownloadChoice::UseDefault;
    }

    public function authorAllows(int $userId): bool
    {
        return (int) $this->database->fetchColumn('SELECT downloads_default FROM {prefix}users WHERE id = :id', ['id' => $userId]) !== 0;
    }

    /**
     * @throws StoryPermissionException when the person may not manage the story
     */
    public function setChoice(User $actor, int $storyId, DownloadChoice $choice, string $ipAddress = ''): void
    {
        // The same ownership and staff rule as editing the story, so another Author can never change it.
        $story = $this->stories->manageable($actor, $storyId);

        if ($this->choiceFor($story->id) === $choice) {
            return;
        }

        $this->database->transaction(function () use ($actor, $story, $choice, $ipAddress): void {
            $this->database->execute('UPDATE {prefix}stories SET download_choice = :choice WHERE id = :id', ['choice' => $choice->value, 'id' => $story->id]);
            $this->audit->record($actor->id, 'story.downloads', 'story', (string) $story->id, ['choice' => $choice->value], $ipAddress);
        });
    }

    /**
     * @throws StoryPermissionException when the person is not allowed to write stories
     */
    public function setAuthorDefault(User $actor, bool $allow, string $ipAddress = ''): void
    {
        if (!$actor->can(Capability::WriteStories)) {
            throw new StoryPermissionException('This account may not write stories.');
        }

        if ($this->authorAllows($actor->id) === $allow) {
            return;
        }

        $this->database->transaction(function () use ($actor, $allow, $ipAddress): void {
            $this->database->execute('UPDATE {prefix}users SET downloads_default = :allow WHERE id = :id', ['allow' => $allow ? 1 : 0, 'id' => $actor->id]);
            $this->audit->record($actor->id, 'account.downloads_default', 'user', (string) $actor->id, ['allow' => $allow], $ipAddress);
        });
    }
}
