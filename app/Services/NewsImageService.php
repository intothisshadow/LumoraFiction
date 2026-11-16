<?php

/**
 * The pictures uploaded for news posts: storing one, finding the ones a person may reuse, and removing a file once nothing shows it.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use DateTimeImmutable;
use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\UploadedFile;
use LumoraFiction\Core\Media\CoverImageException;
use LumoraFiction\Core\Media\NewsImageStore;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\NewsImage;
use LumoraFiction\Models\User;

/**
 * A picture is stored once and posts only point at it. Every lookup that serves a person checks that the picture exists and
 * is theirs (or that they are an administrator), so a hand-made post cannot make a post show someone else's file. The
 * files of a picture are removed only when no post uses it any more.
 */
final class NewsImageService
{
    /** Pictures per page of the picker. */
    public const PICKER_PAGE_SIZE = 12;

    public const ALT_MAX = 300;

    public function __construct(
        private readonly Database $database,
        private readonly NewsImageStore $store,
        private readonly AuditLogService $audit,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function files(): NewsImageStore
    {
        return $this->store;
    }

    public static function available(): bool
    {
        return NewsImageStore::isAvailable();
    }

    /**
     * Checks, re-encodes and keeps an uploaded picture, owned by the person who sent it.
     *
     * @throws NewsPermissionException when the person may not manage news
     * @throws NewsValidationException keyed "image" with what is wrong with the file
     */
    public function add(User $actor, UploadedFile $upload, string $ipAddress = ''): NewsImage
    {
        if (!$actor->can(Capability::PublishNews)) {
            throw new NewsPermissionException('That news post is not available.');
        }

        if (!$upload->wasSent() || $upload->problem() !== '') {
            throw new NewsValidationException(['image' => $upload->problem() !== '' ? $upload->problem() : 'Choose a picture to upload.']);
        }

        try {
            $name = $this->store->save($upload->path);
        } catch (CoverImageException $exception) {
            throw new NewsValidationException(['image' => $exception->getMessage()]);
        }

        [$width, $height] = $this->store->dimensions($name);
        [$thumbWidth, $thumbHeight] = $this->store->dimensions($name, true);

        try {
            $id = (int) $this->database->transaction(function () use ($actor, $name, $width, $height, $thumbWidth, $thumbHeight, $ipAddress): int {
                $id = (int) $this->database->insertGetId(
                    'INSERT INTO {prefix}news_images (owner_id, file_name, width, height, thumb_width, thumb_height, size_bytes, created_at)
                     VALUES (:owner, :name, :w, :h, :tw, :th, :bytes, :created)',
                    ['owner' => $actor->id, 'name' => $name, 'w' => $width, 'h' => $height, 'tw' => $thumbWidth, 'th' => $thumbHeight, 'bytes' => $this->store->bytes($name), 'created' => date('Y-m-d H:i:s')],
                );
                $this->audit->record($actor->id, 'news.image.add', 'news_image', (string) $id, [], $ipAddress);

                return $id;
            });
        } catch (\Throwable $exception) {
            // A picture nothing records is a file nobody could ever clean up.
            $this->store->delete($name);

            throw $exception;
        }

        return $this->find($id) ?? throw new NewsValidationException(['image' => 'The picture could not be saved. Please try again.']);
    }

    public function find(int $id): ?NewsImage
    {
        $row = $id > 0 ? $this->database->fetchOne('SELECT * FROM {prefix}news_images WHERE id = :id', ['id' => $id]) : null;

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param list<int> $ids
     * @return array<int, NewsImage>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $params = [];

        foreach ($ids as $index => $id) {
            $params['id' . $index] = $id;
        }

        $found = [];

        foreach ($this->database->fetchAll('SELECT * FROM {prefix}news_images WHERE id IN (:' . implode(', :', array_keys($params)) . ')', $params) as $row) {
            $found[(int) $row['id']] = $this->hydrate($row);
        }

        return $found;
    }

    /**
     * The picture, only if this person may put it on a post: it exists and is theirs, or they administer the site.
     */
    public function usableBy(User $actor, int $id): ?NewsImage
    {
        $image = $actor->can(Capability::PublishNews) ? $this->find($id) : null;

        return $image !== null && $this->mayManage($actor, $image) ? $image : null;
    }

    /**
     * The pictures this person may reuse, newest first. An administrator sees everyone's.
     *
     * @return array{images: list<array{image: NewsImage, uses: int}>, total: int, pages: int, page: int}
     */
    public function pickerFor(User $actor, int $page): array
    {
        if (!$actor->can(Capability::PublishNews)) {
            return ['images' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
        }

        $all = $actor->can(Capability::ManageSite);
        $where = $all ? '1 = 1' : 'i.owner_id = :owner';
        $params = $all ? [] : ['owner' => $actor->id];
        $total = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}news_images i WHERE ' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PICKER_PAGE_SIZE));
        $page = min($pages, max(1, $page));

        $rows = $this->database->fetchAll(
            'SELECT i.*, (SELECT COUNT(*) FROM {prefix}news_posts p WHERE p.image_id = i.id) AS uses FROM {prefix}news_images i WHERE ' . $where
            . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ' . self::PICKER_PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PICKER_PAGE_SIZE),
            $params,
        );

        return [
            'images' => array_map(fn (array $row): array => ['image' => $this->hydrate($row), 'uses' => (int) $row['uses']], $rows),
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
        ];
    }

    /**
     * Every picture for the Media screen, newest first, with the posts using it and who uploaded it.
     *
     * @param array{owner?: int, unused?: bool} $filters
     * @return list<array{image: NewsImage, uses: int, owner: string}>
     */
    public function listAll(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filter($filters);

        $rows = $this->database->fetchAll(
            'SELECT i.*, u.display_name AS owner_name, (SELECT COUNT(*) FROM {prefix}news_posts p WHERE p.image_id = i.id) AS uses
             FROM {prefix}news_images i LEFT JOIN {prefix}users u ON u.id = i.owner_id WHERE ' . $where
            . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return array_map(fn (array $row): array => ['image' => $this->hydrate($row), 'uses' => (int) $row['uses'], 'owner' => (string) ($row['owner_name'] ?? '')], $rows);
    }

    /**
     * @param array{owner?: int, unused?: bool} $filters
     */
    public function countAll(array $filters): int
    {
        [$where, $params] = $this->filter($filters);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}news_images i WHERE ' . $where, $params);
    }

    /**
     * @return array{count: int, bytes: int}
     */
    public function totals(): array
    {
        $row = $this->database->fetchOne('SELECT COUNT(*) AS total, COALESCE(SUM(size_bytes), 0) AS bytes FROM {prefix}news_images');

        return ['count' => (int) ($row['total'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }

    /**
     * The people who uploaded at least one picture, for the filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function uploaders(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['owner_id'], 'name' => (string) $row['display_name']],
            $this->database->fetchAll('SELECT DISTINCT i.owner_id, u.display_name FROM {prefix}news_images i JOIN {prefix}users u ON u.id = i.owner_id ORDER BY u.display_name'),
        );
    }

    /**
     * Deletes pictures from the Media screen. A picture a post still shows is refused unless the posts are told to let go
     * of it, in which case they simply lose their picture. Both files go, and each deletion is audited and announced.
     *
     * @param list<int> $ids
     * @return array{deleted: int, cleared: int, refused: list<int>}
     * @throws NewsPermissionException when the person is not an administrator
     */
    public function deleteMany(User $actor, array $ids, bool $removeFromPosts, string $ipAddress = ''): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new NewsPermissionException('That picture is not available.');
        }

        $result = ['deleted' => 0, 'cleared' => 0, 'refused' => []];

        foreach (array_slice(array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0))), 0, BulkResult::LIMIT) as $id) {
            $image = $this->find($id);

            if ($image === null) {
                continue;
            }

            $posts = array_map(static fn (array $row): int => (int) $row['id'], $this->database->fetchAll('SELECT id FROM {prefix}news_posts WHERE image_id = :id', ['id' => $id]));

            if ($posts !== [] && !$removeFromPosts) {
                $result['refused'][] = $id;

                continue;
            }

            $this->database->transaction(function () use ($actor, $image, $posts, $ipAddress): void {
                if ($posts !== []) {
                    $this->database->execute(
                        'UPDATE {prefix}news_posts SET image_id = NULL, image_alt = \'\', image_decorative = 0, updated_at = :updated WHERE image_id = :id',
                        ['updated' => date('Y-m-d H:i:s'), 'id' => $image->id],
                    );
                }

                $this->database->execute('DELETE FROM {prefix}news_images WHERE id = :id', ['id' => $image->id]);
                $this->audit->record($actor->id, 'news.image.delete', 'news_image', (string) $image->id, ['posts' => $posts], $ipAddress);
            });

            $this->store->delete($image->fileName);
            $this->hooks?->doAction('news_image_deleted', $image->id, $posts);
            $result['deleted']++;
            $result['cleared'] += count($posts);
        }

        return $result;
    }

    /**
     * @param array{owner?: int, unused?: bool} $filters
     * @return array{0: string, 1: array<string, int>}
     */
    private function filter(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['owner'] ?? 0) > 0) {
            $where[] = 'i.owner_id = :owner';
            $params['owner'] = (int) $filters['owner'];
        }

        if (($filters['unused'] ?? false) === true) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {prefix}news_posts p WHERE p.image_id = i.id)';
        }

        return [implode(' AND ', $where), $params];
    }

    public function useCount(int $id): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}news_posts WHERE image_id = :id', ['id' => $id]);
    }

    /**
     * Removes the picture's row and files when no post uses it any more. Called after a post lets go of its picture.
     */
    public function releaseIfUnused(?int $id): void
    {
        $image = $id === null ? null : $this->find($id);

        if ($image === null || $this->useCount($image->id) > 0) {
            return;
        }

        $this->database->execute('DELETE FROM {prefix}news_images WHERE id = :id', ['id' => $image->id]);
        $this->store->delete($image->fileName);
    }

    /**
     * Deletes an upload no post shows. A picture that is in use is refused, so the owner cannot blank a post by accident.
     *
     * @throws NewsPermissionException when the picture is not theirs or does not exist
     * @throws NewsValidationException when a post still shows it
     */
    public function deleteUnused(User $actor, int $id, string $ipAddress = ''): void
    {
        $image = $this->usableBy($actor, $id) ?? throw new NewsPermissionException('That picture is not available.');
        $uses = $this->useCount($image->id);

        if ($uses > 0) {
            throw new NewsValidationException(['image' => 'That picture is used by ' . $uses . ($uses === 1 ? ' news post' : ' news posts') . ', so it cannot be deleted.']);
        }

        $this->database->transaction(function () use ($actor, $image, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}news_images WHERE id = :id', ['id' => $image->id]);
            $this->audit->record($actor->id, 'news.image.delete', 'news_image', (string) $image->id, [], $ipAddress);
        });

        $this->store->delete($image->fileName);
    }

    /**
     * What a theme or feed needs to print the picture with its alternative text: addresses, sizes and the text itself.
     * A decorative picture has an empty text on purpose, which tells assistive technology to skip it.
     *
     * @return array{url: string, thumb: string, width: int, height: int, thumb_width: int, thumb_height: int, alt: string, type: string}
     */
    public function present(NewsImage $image, string $alt, bool $decorative): array
    {
        return [
            'url' => $this->store->url($image->fileName),
            'thumb' => $this->store->url($image->fileName, true),
            'width' => $image->width,
            'height' => $image->height,
            'thumb_width' => $image->thumbWidth,
            'thumb_height' => $image->thumbHeight,
            'alt' => $decorative ? '' : $alt,
            'type' => $this->store->mimeType($image->fileName),
        ];
    }

    private function mayManage(User $actor, NewsImage $image): bool
    {
        return $image->ownerId === $actor->id || $actor->can(Capability::ManageSite);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NewsImage
    {
        return new NewsImage(
            id: (int) $row['id'],
            ownerId: $row['owner_id'] === null ? null : (int) $row['owner_id'],
            fileName: (string) $row['file_name'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            thumbWidth: (int) $row['thumb_width'],
            thumbHeight: (int) $row['thumb_height'],
            sizeBytes: (int) $row['size_bytes'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
