<?php

/**
 * Makes placeholder news posts and static pages.
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

namespace LumoraFiction\Plugins\DummyContent;

use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\User;
use LumoraFiction\Services\PageService;
use LumoraFiction\Services\PageValidationException;
use RuntimeException;

/**
 * News posts are written in one batch, as stories are, and take the slug the service would give them (the post's number
 * and its title). Pages go through PageService: there are few of them, and the service already knows which addresses are
 * reserved or routed, so the plugin keeps no copy of that list that could go stale.
 */
final class SiteContentMaker
{
    private ?HtmlSanitizer $sanitizer = null;

    public function __construct(
        private readonly Database $database,
        private readonly Rows $rows,
        private readonly Tracker $tracker,
        private readonly ?PageService $pages = null,
    ) {
    }

    /**
     * @return int posts made
     */
    public function makeNews(int $wanted, string $run, Corpus $corpus, User $actor): int
    {
        if ($wanted === 0) {
            return 0;
        }

        $authors = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll(
                "SELECT u.id FROM {prefix}users u JOIN {prefix}dummy_content_items t ON t.kind = 'user' AND t.item_id = u.id
                 WHERE u.role IN ('editor', 'administrator') AND u.status = 'active' ORDER BY u.id",
            ),
        );
        $authors = $authors === [] ? [$actor->id] : $authors;

        $rows = [];
        $titles = [];
        $firstPublished = null;
        $pinned = 0;

        for ($n = 1; $n <= $wanted; $n++) {
            $title = $corpus->newsTitle();
            $content = $this->clean($corpus->htmlParagraphs(2, 4));
            $roll = $corpus->between(1, 100);
            $state = $roll <= 70 ? 'published' : 'draft';
            // A draft is sometimes a post that was published and taken back, which keeps its date.
            $wasPublished = $state === 'published' || ($roll > 90);
            $at = time() - $corpus->between(86400, 240 * 86400);
            $isPinned = $state === 'published' && $corpus->chance(8);
            $pinned += $isPinned ? 1 : 0;
            $firstPublished ??= $state === 'published' ? $n - 1 : null;

            $rows[] = [
                $corpus->pick($authors),
                $title,
                'dummy-' . $run . '-' . $n,
                $content,
                'html',
                $state,
                $isPinned ? 1 : 0,
                $wasPublished ? date('Y-m-d H:i:s', $at) : null,
                date('Y-m-d H:i:s', $at - $corpus->between(0, 2 * 86400)),
                date('Y-m-d H:i:s', $at),
            ];
            $titles[] = $title;
        }

        // A few pinned posts are part of what a list has to show, so a small run is not left with none.
        if ($pinned === 0 && $wanted >= 5 && $firstPublished !== null) {
            $rows[$firstPublished][6] = 1;
        }

        $before = $this->rows->maxId('news_posts');
        $this->rows->insert('news_posts', ['author_id', 'title', 'slug', 'content', 'content_format', 'publication_state', 'pinned', 'published_at', 'created_at', 'updated_at'], $rows, 100);

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll('SELECT id FROM {prefix}news_posts WHERE id > :before AND slug LIKE :prefix ORDER BY id', ['before' => $before, 'prefix' => 'dummy-' . $run . '-%']),
        );

        if (count($ids) !== $wanted) {
            throw new RuntimeException('Another process wrote news posts while the placeholder posts were being made. Remove the dummy content and try again.');
        }

        // The address the news service gives a post: its number, then the words of its title.
        $this->database->transaction(function () use ($ids, $titles): void {
            foreach ($ids as $index => $id) {
                $this->database->execute('UPDATE {prefix}news_posts SET slug = :slug WHERE id = :id', ['slug' => $id . '-' . Slug::fromText($titles[$index], 100, 'news'), 'id' => $id]);
            }
        });
        $this->tracker->record(Tracker::KIND_NEWS, $ids, $run);

        return count($ids);
    }

    /**
     * @return int pages made; a name whose address a page or route already holds is skipped, so this can be fewer than asked for
     */
    public function makePages(int $wanted, string $run, Corpus $corpus, User $actor): int
    {
        if ($wanted === 0) {
            return 0;
        }

        if ($this->pages === null) {
            throw new RuntimeException('Pages cannot be made here because the page service is not available.');
        }

        $ids = [];

        foreach (array_slice(Corpus::PAGES, 0, $wanted) as $index => [$title, $slug]) {
            // The first page (About) is always published, so a small run still has a page to open.
            $published = $index === 0 || $corpus->chance(75);

            try {
                $page = $this->pages->create($actor, [
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $this->clean($corpus->htmlParagraphs(2, 5)),
                    'format' => 'html',
                    'in_menu' => $corpus->chance(40),
                    'in_footer' => $corpus->chance(60),
                    'sort_order' => $corpus->between(1, 20) * 10,
                    'description' => $corpus->sentence(),
                    'noindex' => false,
                ]);
            } catch (PageValidationException) {
                continue;
            }

            $ids[] = $page->id;

            if ($published) {
                $this->pages->publish($actor, $page->id);
            }
        }

        $this->tracker->record(Tracker::KIND_PAGE, $ids, $run);

        return count($ids);
    }

    private function clean(string $html): string
    {
        $this->sanitizer ??= new HtmlSanitizer();

        return $this->sanitizer->clean($html);
    }
}
