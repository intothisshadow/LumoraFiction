<?php

/**
 * Sends the old eFiction addresses to the imported stories, authors and pages, when switched on.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\UserService;

/**
 * The routes exist only while the administrator has the switch on, and each answers a permanent redirect only for an item an
 * import made. Anything else is the ordinary "not found" page, so the old address of something that was never imported does
 * not quietly lead somewhere unrelated.
 */
final class Redirects
{
    public function __construct(
        private readonly Database $database,
        private readonly Tracker $tracker,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly UserService $users,
        private readonly ThemeRenderer $themes,
    ) {
    }

    /**
     * viewstory.php?sid=12 is the story; &chapter=3 is the third chapter of it (a position, not eFiction's chapter id).
     *
     * @param array<string, string> $params
     */
    public function story(array $params, Request $request): Response
    {
        $storyId = $this->tracker->localId(Tracker::STORY, (string) (int) $request->queryValue('sid'));
        $story = $storyId === null ? null : $this->stories->find($storyId);

        if ($story === null || !$this->stories->isPublic($story)) {
            return $this->themes->notFound();
        }

        $number = (int) $request->queryValue('chapter');

        if ($number > 0 && $this->chapters->findPublic($story->id, $number) !== null) {
            return Response::redirect(PublicUrl::chapter($story->slug, $number), 301);
        }

        return Response::redirect(PublicUrl::story($story->slug), 301);
    }

    /**
     * @param array<string, string> $params
     */
    public function user(array $params, Request $request): Response
    {
        $userId = $this->tracker->localId(Tracker::USER, (string) (int) $request->queryValue('uid'));
        $user = $userId === null ? null : $this->users->findById($userId);

        return $user === null ? $this->themes->notFound() : Response::redirect(PublicUrl::author($user->slug), 301);
    }

    /**
     * viewpage.php?page=rules: the page whose address was made from that name and which an import made.
     *
     * @param array<string, string> $params
     */
    public function page(array $params, Request $request): Response
    {
        $slug = Slug::fromText((string) $request->queryValue('page'), 100, 'page');
        $found = $this->database->fetchColumn(
            'SELECT p.slug FROM {prefix}pages p JOIN {prefix}efiction_items t ON t.kind = \'page\' AND t.local_id = p.id WHERE p.slug = :slug AND p.publication_state = \'published\'',
            ['slug' => $slug],
        );

        return $found === false || $found === null ? $this->themes->notFound() : Response::redirect(PublicUrl::page((string) $found), 301);
    }
}
