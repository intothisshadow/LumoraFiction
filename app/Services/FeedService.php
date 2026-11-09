<?php

/**
 * Builds the feeds: what each one holds, and that it holds nothing a visitor could not read on the site.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Feed\Feed;
use LumoraFiction\Core\Feed\FeedItem;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\TermType;

/**
 * Every story, chapter and comment comes from a method that starts from the public-visibility rule, and a feed returns
 * null, which the controller answers as a missing page, for anything the site's own pages would also hide: a draft, a
 * kind of feed that is switched off, an author or term with nothing public. Entries carry a display name and a public
 * address, never a login name.
 */
final class FeedService
{
    /** Longest opening shown when a feed carries a summary rather than the whole text, in characters. */
    private const EXCERPT = 300;

    private readonly string $siteUrl;

    public function __construct(
        private readonly Configuration $config,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly PublicArchive $archive,
        private readonly StoryCardFacts $facts,
        private readonly TaxonomyService $taxonomy,
        private readonly UserService $users,
        private readonly NewsService $news,
        private readonly CommentService $comments,
        private readonly StoryPresenter $presenter,
        private readonly ContentRenderer $renderer,
        string $siteUrl,
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * The kinds of term that have a feed, which are the ones a reader follows: a fandom, a character, a relationship, a genre or a tag.
     *
     * @return list<TermType>
     */
    public static function termKinds(): array
    {
        return [TermType::Fandom, TermType::Character, TermType::Relationship, TermType::Genre, TermType::Tag];
    }

    public function site(): ?Feed
    {
        if (!$this->on('site')) {
            return null;
        }

        $name = $this->siteName();

        return $this->storyFeed(
            $name,
            'New and updated stories on ' . $name . '.',
            [],
            BasePath::get() . '/',
            $this->archive->recent('updated', $this->limit()),
        );
    }

    public function author(string $slug): ?Feed
    {
        $user = $this->on('author') ? $this->users->findBySlug($slug) : null;

        // As the author's page does: an account with nothing published is not announced.
        if ($user === null || $this->archive->countForAuthor($user->id) === 0) {
            return null;
        }

        $profile = $user->publicProfile();
        $title = $this->siteName() . ': stories by ' . $profile['display_name'];

        return $this->storyFeed(
            $title,
            'New and updated stories by ' . $profile['display_name'] . '.',
            ['author', $profile['slug']],
            PublicUrl::author($profile['slug']),
            $this->archive->forAuthor($user->id, 'updated', $this->limit(), 0),
        );
    }

    public function term(TermType $type, string $slug): ?Feed
    {
        if (!in_array($type, self::termKinds(), true) || !$this->on($type->value) || !$this->taxonomy->isEnabled($type)) {
            return null;
        }

        $term = $this->taxonomy->findBySlug($type, $slug);

        // As the term's page does: one whose stories are all hidden answers like a missing one.
        if ($term === null || $this->archive->countForTerm($term->id) === 0) {
            return null;
        }

        return $this->storyFeed(
            $this->siteName() . ': ' . $type->label() . ' ' . $term->name,
            'New and updated stories tagged ' . $term->name . ' (' . strtolower($type->label()) . ').',
            [$type->value, $term->slug],
            PublicUrl::term($type, $term->slug),
            $this->archive->forTerm($term->id, 'updated', $this->limit(), 0),
        );
    }

    /**
     * The new chapters of one story, newest first.
     */
    public function story(string $slug): ?Feed
    {
        $story = $this->on('story') ? $this->stories->findPublicBySlug($slug) : null;

        if ($story === null) {
            return null;
        }

        $profile = $this->users->publicProfiles([$story->ownerId])[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
        $author = $profile['display_name'];
        $authorUrl = $profile['slug'] === '' ? '' : $this->absolute(PublicUrl::author($profile['slug']));
        $image = $this->cover($story);
        $chapters = $this->chapters->listPublic($story->id);

        usort($chapters, static fn (Chapter $a, Chapter $b): int => [$b->publishedAt ?? $b->createdAt, $b->number] <=> [$a->publishedAt ?? $a->createdAt, $a->number]);

        $full = FeedSettings::fullText($this->config);
        $items = [];

        foreach (array_slice($chapters, 0, $this->limit()) as $chapter) {
            $url = $this->absolute(PublicUrl::chapter($story->slug, $chapter->number));
            $excerpt = $this->excerpt($this->renderer->toPlainText($chapter->content, $chapter->format));
            $content = '';

            if ($full) {
                $content = $this->renderer->render($chapter->content, $chapter->format);

                // The end note belongs to the chapter, so it travels with its text; the story's own end note does not.
                if ($chapter->endNotes !== '') {
                    $content .= "\n<hr>\n<h4>End notes</h4>\n" . self::paragraphs($chapter->endNotes);
                }
            }

            $items[] = new FeedItem(
                id: $url,
                url: $url,
                title: $chapter->title === '' ? 'Chapter ' . $chapter->number : 'Chapter ' . $chapter->number . ': ' . $chapter->title,
                date: $chapter->publishedAt ?? $chapter->createdAt,
                modified: $chapter->updatedAt,
                author: $author,
                authorUrl: $authorUrl,
                summaryHtml: self::paragraphs($excerpt),
                contentHtml: $content,
                image: $image,
                guidIsAddress: true,
            );
        }

        $summary = trim($story->summary);

        return new Feed(
            $story->title,
            $summary !== '' ? $summary : 'New chapters of ' . $story->title . ($author === '' ? '' : ' by ' . $author) . '.',
            $this->absolute(PublicUrl::story($story->slug)),
            $this->absolute(PublicUrl::feed(['story', $story->slug])),
            $this->jsonAddress(['story', $story->slug]),
            $items,
            $image,
        );
    }

    /**
     * The newest comments under one story. It needs both its own switch and comments to be on for the site.
     */
    public function storyComments(string $slug): ?Feed
    {
        if (!$this->on('comments') || !$this->comments->settings()->enabled()) {
            return null;
        }

        $story = $this->stories->findPublicBySlug($slug);

        if ($story === null) {
            return null;
        }

        $items = [];

        foreach ($this->comments->recentPublicForStory($story->id, $this->limit()) as $entry) {
            $comment = $entry['comment'];
            $name = $entry['name'] === '' ? 'A reader' : $entry['name'];
            $url = $this->absolute(PublicUrl::chapter($story->slug, $entry['chapter']) . '#comment-' . $comment->id);

            $items[] = new FeedItem(
                id: $url,
                url: $url,
                title: 'Comment by ' . $name . ' on chapter ' . $entry['chapter'],
                date: $comment->createdAt,
                modified: $comment->editedAt,
                author: $name,
                // A comment is plain text, so it is escaped here and never carries a tag or a clickable link.
                summaryHtml: self::paragraphs($comment->body),
                guidIsAddress: true,
            );
        }

        return new Feed(
            'Comments on ' . $story->title,
            'The newest comments on ' . $story->title . '.',
            $this->absolute(PublicUrl::story($story->slug)),
            $this->absolute(PublicUrl::feed(['story', $story->slug, 'comments'])),
            $this->jsonAddress(['story', $story->slug, 'comments']),
            $items,
            $this->cover($story),
        );
    }

    public function news(): ?Feed
    {
        if (!$this->on('news') || !NewsSettings::enabled($this->config)) {
            return null;
        }

        $posts = $this->news->recentPublic($this->limit());
        $profiles = $this->users->publicProfiles(array_map(static fn ($post): int => $post->authorId, $posts));
        $full = FeedSettings::fullText($this->config);
        $items = [];

        foreach ($posts as $post) {
            $url = $this->absolute(PublicUrl::newsPost($post->slug));
            $profile = $profiles[$post->authorId] ?? ['display_name' => '', 'slug' => ''];
            $excerpt = $this->excerpt($this->renderer->toPlainText($post->content, $post->format));

            $items[] = new FeedItem(
                id: $url,
                url: $url,
                title: $post->title,
                date: $post->publishedAt ?? $post->createdAt,
                modified: $post->updatedAt,
                author: $profile['display_name'],
                authorUrl: $profile['slug'] === '' ? '' : $this->absolute(PublicUrl::author($profile['slug'])),
                summaryHtml: self::paragraphs($excerpt),
                contentHtml: $full ? $this->renderer->render($post->content, $post->format) : '',
                guidIsAddress: true,
            );
        }

        $name = $this->siteName();

        return new Feed(
            $name . ': news',
            'News from ' . $name . '.',
            $this->absolute(PublicUrl::news()),
            $this->absolute(PublicUrl::feed(['news'])),
            $this->jsonAddress(['news']),
            $items,
        );
    }

    /**
     * A feed of stories, as the site, an author and a term all are: one entry per story, ordered by the last change, so
     * a story that gains a chapter comes round again.
     *
     * @param list<string> $feedSegments the feed's own address segments, empty for the site-wide feed
     * @param list<Story> $stories
     */
    private function storyFeed(string $title, string $description, array $feedSegments, string $homePath, array $stories): Feed
    {
        $ids = array_map(static fn (Story $story): int => $story->id, $stories);
        $facts = $this->facts->for($ids);
        $profiles = $this->users->publicProfiles(array_map(static fn (Story $story): int => $story->ownerId, $stories));
        $items = [];

        foreach ($stories as $story) {
            $profile = $profiles[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
            $url = $this->absolute(PublicUrl::story($story->slug));
            $terms = $facts[$story->id]['terms'] ?? [];
            $categories = [];

            foreach ($terms as $list) {
                foreach ($list as $term) {
                    $categories[] = $term->name;
                }
            }

            $items[] = new FeedItem(
                // The change date is part of the identifier, so a reader shows the story again when it is updated.
                id: $url . '#updated-' . $story->updatedAt->getTimestamp(),
                url: $url,
                title: $story->title,
                date: $story->updatedAt,
                modified: $story->updatedAt,
                author: $profile['display_name'],
                authorUrl: $profile['slug'] === '' ? '' : $this->absolute(PublicUrl::author($profile['slug'])),
                summaryHtml: $this->storySummary($story, $facts[$story->id]['chapters'] ?? 0),
                image: $this->cover($story),
                categories: array_values(array_unique($categories)),
            );
        }

        return new Feed(
            $title,
            $description,
            $this->absolute($homePath),
            $this->absolute(PublicUrl::feed($feedSegments)),
            $this->jsonAddress($feedSegments),
            $items,
        );
    }

    private function storySummary(Story $story, int $chapterCount): string
    {
        $facts = [$story->workStatus->label(), number_format($story->wordCount) . ' words'];

        if ($chapterCount > 0) {
            $facts[] = $chapterCount . ($chapterCount === 1 ? ' chapter' : ' chapters');
        }

        return self::paragraphs($story->summary) . '<p>' . Html::e(implode(' · ', $facts)) . '</p>';
    }

    private function on(string $kind): bool
    {
        return FeedSettings::offered($this->config, $kind);
    }

    private function limit(): int
    {
        return FeedSettings::limit($this->config);
    }

    private function siteName(): string
    {
        return (string) $this->config->setting('site.name', 'Lumora Fiction');
    }

    /**
     * The JSON form's address, or an empty string when JSON feeds are switched off so no feed advertises a dead address.
     *
     * @param list<string> $segments
     */
    private function jsonAddress(array $segments): string
    {
        return FeedSettings::jsonEnabled($this->config) ? $this->absolute(PublicUrl::feed($segments, true)) : '';
    }

    private function cover(Story $story): string
    {
        return $this->absolute($this->presenter->cover($story)['thumb']);
    }

    /**
     * Route addresses carry the install folder, and the configured site address already holds it, so the folder is
     * taken off before the two are joined.
     */
    private function absolute(string $path): string
    {
        if ($path === '' || preg_match('#^https?://#i', $path) === 1 || $this->siteUrl === '') {
            return $path;
        }

        $base = BasePath::get();

        return $this->siteUrl . ($base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path);
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text, 'UTF-8') > self::EXCERPT ? rtrim(mb_substr($text, 0, self::EXCERPT, 'UTF-8')) . '…' : $text;
    }

    /**
     * Plain text as escaped paragraphs: a blank line starts one and a single line break stays a break.
     */
    private static function paragraphs(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>' . nl2br(Html::e(trim($paragraph)), false) . '</p>',
            preg_split('/\n{2,}/', $text) ?: [],
        ));
    }
}
