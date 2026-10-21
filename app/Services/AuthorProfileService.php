<?php

/**
 * An author's public profile: the text and the picture shown on their author page, and the administrator's power to clear them.
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

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Http\UploadedFile;
use LumoraFiction\Core\Media\AvatarImageStore;
use LumoraFiction\Core\Media\CoverImageException;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\User;

/**
 * The text goes through the same renderer and single sanitiser as a chapter, so it can carry the same formatting
 * and nothing that could run. It is public by design, which is why an administrator can always clear it.
 */
final class AuthorProfileService
{
    /** The most characters of visible text, counted after the markup is taken away. */
    public const ABOUT_MAX = 10000;

    /** A cap on what is stored and parsed, so a huge paste is refused before it is rendered. */
    private const ABOUT_RAW_MAX = 200000;

    public function __construct(
        private readonly Database $database,
        private readonly ContentRenderer $renderer,
        private readonly ?AvatarImageStore $avatars,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * What an author has saved, for the profile screen.
     *
     * @return array{about: string, format: ContentFormat, avatar: string}
     */
    public function forUser(int $userId): array
    {
        $row = $this->database->fetchOne('SELECT about, about_format, avatar_file FROM {prefix}users WHERE id = :id', ['id' => $userId]);

        return [
            'about' => is_string($row['about'] ?? null) ? $row['about'] : '',
            'format' => ContentFormat::tryFrom((string) ($row['about_format'] ?? '')) ?? ContentFormat::Html,
            'avatar' => $this->validAvatar((string) ($row['avatar_file'] ?? '')),
        ];
    }

    /**
     * What the author page shows: the text as safe HTML with its headings moved below the page's own, and the
     * address of the picture. Both are empty when the author has set nothing.
     *
     * @return array{about_html: string, avatar_url: string}
     */
    public function publicView(int $userId): array
    {
        $profile = $this->forUser($userId);
        $html = trim($profile['about']) === '' ? '' : $this->demoteHeadings($this->renderer->render($profile['about'], $profile['format']));
        $avatar = $profile['avatar'];

        return [
            'about_html' => $html,
            'avatar_url' => $avatar !== '' && $this->avatars?->exists($avatar) === true ? $this->avatars->url($avatar) : '',
        ];
    }

    public function avatarUrl(string $name): string
    {
        return $name !== '' && $this->avatars?->exists($name) === true ? $this->avatars->url($name) : '';
    }

    /**
     * Saves the author's own text; empty text clears it.
     *
     * @throws AuthorProfileException keyed "about"
     */
    public function saveAbout(User $actor, string $content, ContentFormat $format, string $ipAddress = ''): void
    {
        if (!$actor->can(Capability::WriteStories)) {
            throw new AuthorProfileException(['about' => 'You may not change an author profile.']);
        }

        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));

        if (mb_strlen($content, 'UTF-8') > self::ABOUT_RAW_MAX) {
            throw new AuthorProfileException(['about' => 'That text is too long. Keep it under ' . number_format(self::ABOUT_MAX) . ' characters.']);
        }

        $visible = $content === '' ? 0 : mb_strlen(trim(html_entity_decode(strip_tags($this->renderer->render($content, $format)), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'UTF-8');

        if ($visible > self::ABOUT_MAX) {
            throw new AuthorProfileException(['about' => 'That text is too long: it has ' . number_format($visible) . ' characters and the limit is ' . number_format(self::ABOUT_MAX) . '.']);
        }

        // Text that renders to nothing is treated as no text, so an author cannot leave an empty box on their page.
        $stored = $visible === 0 ? '' : $content;

        $this->database->execute(
            'UPDATE {prefix}users SET about = :about, about_format = :format, updated_at = :updated WHERE id = :id',
            ['about' => $stored === '' ? null : $stored, 'format' => $format->value, 'updated' => date('Y-m-d H:i:s'), 'id' => $actor->id],
        );
        $this->audit->record($actor->id, 'author.about', 'user', (string) $actor->id, ['action' => $stored === '' ? 'clear' : 'set'], $ipAddress);
    }

    /**
     * Sets, replaces or removes the author's own picture.
     *
     * @param array{x: float, y: float, w: float, h: float}|null $crop the part of a new picture to keep, as fractions
     * @throws AuthorProfileException keyed "avatar"
     */
    public function applyAvatar(User $actor, ?UploadedFile $upload, bool $remove, ?array $crop = null, string $ipAddress = ''): void
    {
        if (!$actor->can(Capability::WriteStories)) {
            throw new AuthorProfileException(['avatar' => 'You may not change an author profile.']);
        }

        $uploading = $upload !== null && $upload->wasSent();
        $current = $this->forUser($actor->id)['avatar'];

        if (!$uploading) {
            if ($remove && $current !== '') {
                $this->storeAvatar($actor->id, '', $current, 'remove', $ipAddress);
            }

            return;
        }

        if ($this->avatars === null) {
            throw new AuthorProfileException(['avatar' => 'Pictures are not available on this site.']);
        }

        if ($upload->problem() !== '') {
            throw new AuthorProfileException(['avatar' => $upload->problem()]);
        }

        try {
            $file = $this->avatars->save($upload->path, $crop);
        } catch (CoverImageException $exception) {
            throw new AuthorProfileException(['avatar' => $exception->getMessage()]);
        }

        $this->storeAvatar($actor->id, $file, $current, $current === '' ? 'set' : 'replace', $ipAddress, $actor->id);
    }

    /**
     * An administrator clears an author's text.
     */
    public function clearAbout(User $admin, int $userId, string $ipAddress = ''): bool
    {
        $this->requireModerator($admin);

        if (trim($this->forUser($userId)['about']) === '') {
            return false;
        }

        $this->database->execute('UPDATE {prefix}users SET about = NULL, updated_at = :updated WHERE id = :id', ['updated' => date('Y-m-d H:i:s'), 'id' => $userId]);
        $this->audit->record($admin->id, 'author.about.clear', 'user', (string) $userId, [], $ipAddress);

        return true;
    }

    /**
     * An administrator removes an author's picture.
     */
    public function removeAvatar(User $admin, int $userId, string $ipAddress = ''): bool
    {
        $this->requireModerator($admin);
        $current = $this->forUser($userId)['avatar'];

        if ($current === '') {
            return false;
        }

        $this->storeAvatar($userId, '', $current, 'remove', $ipAddress, $admin->id);

        return true;
    }

    /**
     * Records the new name, then removes the picture it replaced. A failure while recording removes the new
     * picture instead, so no file is left that no account points at.
     */
    private function storeAvatar(int $userId, string $file, string $previous, string $action, string $ipAddress, ?int $actorId = null): void
    {
        try {
            $this->database->transaction(function () use ($userId, $file, $action, $ipAddress, $actorId): void {
                $this->database->execute('UPDATE {prefix}users SET avatar_file = :file, updated_at = :updated WHERE id = :id', ['file' => $file, 'updated' => date('Y-m-d H:i:s'), 'id' => $userId]);
                $this->audit->record($actorId ?? $userId, 'author.avatar', 'user', (string) $userId, ['action' => $action], $ipAddress);
            });
        } catch (\Throwable $exception) {
            if ($file !== '') {
                $this->avatars?->delete($file);
            }

            throw $exception;
        }

        if ($previous !== '' && $previous !== $file) {
            $this->avatars?->delete($previous);
        }
    }

    private function requireModerator(User $admin): void
    {
        if (!$admin->can(Capability::ManageUsers)) {
            throw new AuthorProfileException(['about' => 'You may not do that.']);
        }
    }

    private function validAvatar(string $name): string
    {
        return AvatarImageStore::isValidName($name) ? $name : '';
    }

    /**
     * The author page's own headings are the page title and the section heading, so a heading the author wrote
     * is moved two levels down (a first-level heading becomes third level) and never goes past the sixth.
     */
    private function demoteHeadings(string $html): string
    {
        return (string) preg_replace_callback(
            '~<(/?)h([1-6])\b~i',
            static fn (array $match): string => '<' . $match[1] . 'h' . min(6, (int) $match[2] + 2),
            $html,
        );
    }
}
