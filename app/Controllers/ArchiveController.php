<?php

/**
 * The public archive: the home page, stories, chapters, authors and term listings.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use Closure;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\BookmarkPresenter;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ContentRenderer;
use LumoraFiction\Services\PublicArchive;
use LumoraFiction\Services\ReadingProgressService;
use LumoraFiction\Services\SearchPresenter;
use LumoraFiction\Services\SearchQueryParser;
use LumoraFiction\Services\StoryPermissionException;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\UserService;

/**
 * Every page here answers for a visitor, so a story that is not public is treated exactly like one that does not
 * exist: the same page, the same status. Only its owner and the staff who may edit it are sent on to the private
 * preview instead.
 */
final class ArchiveController
{
    /** How many stories each list on the home page shows. */
    private const HOME_COUNT = 8;

    /** How many "continue reading" links the home page shows. */
    private const CONTINUE_COUNT = 3;

    /** Pages beyond this are refused outright, so a huge number in an address cannot become a huge database offset. */
    private const MAX_PAGE = 10000;

    /** Terms per page on a Browse index; names are short, so a page holds many more than a page of stories. */
    private const INDEX_PAGE_SIZE = 100;

    /** How many characters or relationships a fandom's page links to. */
    private const RELATED_COUNT = 20;

    /**
     * The plain story lists under Browse: a fixed order and, for one, a fixed work status.
     *
     * @var array<string, array{title: string, description: string, sort: string, status: ?WorkStatus}>
     */
    private const BROWSE_LISTS = [
        'new' => ['title' => 'New stories', 'description' => 'Stories in the order they were first published, newest first.', 'sort' => 'published', 'status' => null],
        'updated' => ['title' => 'Recently updated stories', 'description' => 'Stories in the order their latest chapter was added, newest first.', 'sort' => 'updated', 'status' => null],
        'completed' => ['title' => 'Completed stories', 'description' => 'Stories their authors have marked as finished, most recently updated first.', 'sort' => 'updated', 'status' => WorkStatus::Completed],
    ];

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly UserService $users,
        private readonly PublicArchive $archive,
        private readonly StoryPresenter $presenter,
        private readonly ContentRenderer $renderer,
        private readonly AuthService $auth,
        private readonly Configuration $config,
        private readonly SearchQueryParser $searchParser,
        private readonly SearchPresenter $searchPresenter,
        private readonly BookmarkPresenter $bookmarkPresenter,
        private readonly ReadingProgressService $progress,
        private readonly FlashMessages $flash,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function search(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($request): Response {
            ['query' => $query, 'notice' => $notice] = $this->searchParser->parse($request->query);

            // A refused search runs nothing, so it shows no stories rather than the whole archive.
            $matches = $notice === '' ? $this->archive->countSearch($query) : 0;
            $capped = $matches > PublicArchive::SEARCH_LIMIT;
            $total = min($matches, PublicArchive::SEARCH_LIMIT);
            $pages = max(1, (int) ceil($total / PublicArchive::PAGE_SIZE));
            $requested = $request->queryValue('page');
            $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

            if ($page < 1 || $page > $pages) {
                return $this->themes->notFound();
            }

            $stories = $total === 0 ? [] : $this->archive->search($query, PublicArchive::PAGE_SIZE, ($page - 1) * PublicArchive::PAGE_SIZE);
            $profiles = $this->users->publicProfiles(array_map(static fn (Story $story): int => $story->ownerId, $stories));

            return $this->themes->render('search', [
                'title' => 'Search',
                'total' => ($capped ? 'More than ' : '') . number_format($total),
                'stories' => $this->cards($stories, $profiles),
                'forms' => [$this->searchPresenter->form($query)],
                'filters' => $this->searchPresenter->active($query),
                'notices' => $notice === '' ? [] : [['type' => 'warning', 'text' => $notice]],
                'searched' => $query->isNarrowed(),
                'pagination' => [
                    'page' => $page,
                    'pages' => $pages,
                    'url' => $this->searchPresenter->pageUrl($query),
                ],
            ], 200, ['title' => 'Search', 'noindex' => $query->isNarrowed()]);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function home(array $params, Request $request): Response
    {
        return $this->guarded(function (): Response {
            $published = $this->archive->recent('published', self::HOME_COUNT);
            $updated = $this->archive->recent('updated', self::HOME_COUNT);
            $profiles = $this->users->publicProfiles(array_map(static fn (Story $story): int => $story->ownerId, [...$published, ...$updated]));

            // Only a signed-in reader has a place to continue from; a guest is never recorded.
            $user = $this->auth->user();
            $continue = [];

            foreach ($user === null ? [] : $this->progress->recent($user, self::CONTINUE_COUNT) as $entry) {
                $continue[] = [
                    'story' => $entry['story']->title,
                    'story_url' => PublicUrl::story($entry['story']->slug),
                    'title' => $this->chapterLabel($entry['number'], $entry['title']),
                    'url' => PublicUrl::chapter($entry['story']->slug, $entry['number']),
                ];
            }

            return $this->themes->render('home', [
                'stories' => $this->cards($published, $profiles),
                'updated_stories' => $this->cards($updated, $profiles),
                'continue_reading' => $continue,
            ]);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function story(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($params, $request): Response {
            $slug = $params['slug'] ?? '';
            $story = $this->stories->findPublicBySlug($slug);

            if ($story === null) {
                return $this->unavailable($slug, null);
            }

            $chapters = $this->chapters->listPublic($story->id);
            $profile = $this->users->publicProfiles([$story->ownerId])[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
            $authorUrl = $profile['slug'] === '' ? '' : PublicUrl::author($profile['slug']);

            $data = $this->presenter->story(
                $story,
                $profile['display_name'],
                $this->visibleTerms($this->taxonomy->termsForStory($story->id)),
                $chapters,
                PublicUrl::story($story->slug),
                static fn (Chapter $chapter): string => PublicUrl::chapter($story->slug, $chapter->number),
                false,
                static fn (Term $term): string => PublicUrl::term($term->type, $term->slug),
                $authorUrl,
            );

            $user = $this->auth->user();
            $readsHere = count($chapters) === 1 && $story->workStatus === WorkStatus::Completed;

            // A finished story told in one chapter reads straight away, with its details above the text.
            if ($readsHere) {
                $data['chapter'] = $this->chapterData($story, $profile['display_name'], $authorUrl, $chapters, 0)['chapter'];

                if ($user !== null) {
                    $this->progress->record($user, $story->id, $chapters[0]->id);
                }
            }

            $data += $this->readerData($user, $story, null, $request->cookies);

            // A place to continue from means nothing on a page that already holds the whole text.
            if ($user !== null && !$readsHere) {
                $left = $this->progress->forStory($user, $story->id);
                $data['continue'] = $left === null ? null : [
                    'title' => $this->chapterLabel($left['number'], $left['title']),
                    'url' => PublicUrl::chapter($story->slug, $left['number']),
                ];
            }

            return $this->themes->render('story', $data, 200, ['title' => $story->title]);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function chapter(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($params, $request): Response {
            $slug = $params['slug'] ?? '';
            $number = $params['number'] ?? '';

            if (preg_match('/^[1-9][0-9]{0,8}\z/', $number) !== 1) {
                return $this->themes->notFound();
            }

            $story = $this->stories->findPublicBySlug($slug);
            $chapter = $story === null ? null : $this->chapters->findPublic($story->id, (int) $number);

            if ($story === null || $chapter === null) {
                return $this->unavailable($slug, (int) $number);
            }

            $chapters = $this->chapters->listPublic($story->id);
            $profile = $this->users->publicProfiles([$story->ownerId])[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
            $index = 0;

            foreach ($chapters as $position => $candidate) {
                if ($candidate->id === $chapter->id) {
                    $index = $position;
                }
            }

            $data = $this->chapterData($story, $profile['display_name'], $profile['slug'] === '' ? '' : PublicUrl::author($profile['slug']), $chapters, $index);

            $user = $this->auth->user();

            if ($user !== null) {
                $this->progress->record($user, $story->id, $chapter->id);
            }

            $data += $this->readerData($user, $story, $chapter, $request->cookies);

            return $this->themes->render('chapter', $data, 200, ['title' => $this->presenter->chapterTitle($chapter) . ' — ' . $story->title]);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function author(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($params, $request): Response {
            $user = $this->users->findBySlug($params['slug'] ?? '');

            // An account that has published nothing is not announced: a reader's page would otherwise prove they exist.
            if ($user === null || $this->archive->countForAuthor($user->id) === 0) {
                return $this->themes->notFound();
            }

            $name = $user->publicProfile()['display_name'];
            $slug = $user->publicProfile()['slug'];

            return $this->listing(
                $request,
                'author',
                null,
                'Stories by ' . $name,
                '',
                PublicUrl::author($slug),
                $this->archive->countForAuthor($user->id),
                fn (string $sort, int $limit, int $offset): array => $this->archive->forAuthor($user->id, $sort, $limit, $offset),
            );
        });
    }

    /**
     * The Browse hub (/browse), the indexes of terms (/browse/tags) and the plain story lists (/browse/new).
     *
     * @param array<string, string> $params
     */
    public function browse(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($params, $request): Response {
            $page = $params['page'] ?? '';

            if ($page === '') {
                return $this->browseHub();
            }

            if (isset(self::BROWSE_LISTS[$page])) {
                $list = self::BROWSE_LISTS[$page];

                return $this->listing(
                    $request,
                    'archive',
                    null,
                    $list['title'],
                    $list['description'],
                    PublicUrl::browse($page),
                    $this->archive->countAll($list['status']),
                    fn (string $sort, int $limit, int $offset): array => $this->archive->all($list['sort'], $limit, $offset, $list['status']),
                    $list['sort'],
                );
            }

            $type = PublicUrl::browseKind($page);

            // A kind the archive mode has switched off answers exactly like a term page of that kind would.
            if ($type === null || !$this->taxonomy->isEnabled($type)) {
                return $this->themes->notFound();
            }

            return $this->browseIndex($type, $page, $request);
        });
    }

    private function browseHub(): Response
    {
        $counts = $this->taxonomy->browseCounts();
        $kinds = [];

        foreach (PublicUrl::browseKinds() as $segment => $type) {
            if ($this->taxonomy->isEnabled($type) && ($counts[$type->value] ?? 0) > 0) {
                $kinds[] = ['label' => $type->pluralLabel(), 'url' => PublicUrl::browse($segment), 'count' => number_format($counts[$type->value]), 'count_label' => number_format($counts[$type->value]) . ' ' . strtolower($counts[$type->value] === 1 ? $type->label() : $type->pluralLabel())];
            }
        }

        $lists = [];

        foreach (self::BROWSE_LISTS as $segment => $list) {
            $lists[] = ['label' => $list['title'], 'url' => PublicUrl::browse($segment), 'description' => $list['description']];
        }

        return $this->themes->render('browse', ['title' => 'Browse', 'lists' => $lists, 'kinds' => $kinds], 200, ['title' => 'Browse']);
    }

    /**
     * One page of the terms of a kind, A to Z or most used first, optionally limited to one first letter.
     */
    private function browseIndex(TermType $type, string $segment, Request $request): Response
    {
        $letters = $this->taxonomy->browseLetters($type);
        $letter = $request->queryValue('letter') ?? '';
        $chars = [];

        if ($letter !== '') {
            // Only a letter the index really has is accepted, so an invented one is simply not there.
            $match = array_values(array_filter($letters, static fn (array $group): bool => $group['letter'] === $letter));

            if ($match === []) {
                return $this->themes->notFound();
            }

            $chars = $match[0]['chars'];
        }

        $mostUsed = $request->queryValue('sort') === 'used';
        $total = $this->taxonomy->browseTotal($type, $chars);
        $pages = max(1, (int) ceil($total / self::INDEX_PAGE_SIZE));
        $requested = $request->queryValue('page');
        $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

        if ($page < 1 || $page > $pages || $page > self::MAX_PAGE) {
            return $this->themes->notFound();
        }

        $terms = [];

        foreach ($this->taxonomy->browseTerms($type, $mostUsed, $chars, self::INDEX_PAGE_SIZE, ($page - 1) * self::INDEX_PAGE_SIZE) as $term) {
            $terms[] = [
                'name' => $term->name,
                'url' => PublicUrl::term($type, $term->slug),
                'count' => number_format($term->storyCount),
                'count_label' => $this->plural($term->storyCount, 'story', 'stories'),
            ];
        }

        $sort = $mostUsed ? 'used' : '';
        $letterLinks = [['label' => 'All', 'url' => PublicUrl::browse($segment, ['sort' => $sort]), 'current' => $letter === '']];

        foreach ($letters as $group) {
            $letterLinks[] = ['label' => $group['letter'], 'url' => PublicUrl::browse($segment, ['letter' => $group['letter'], 'sort' => $sort]), 'current' => $group['letter'] === $letter];
        }

        $query = http_build_query(array_filter(['letter' => $letter, 'sort' => $sort], static fn (string $value): bool => $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return $this->themes->render('browse', [
            'title' => $type->pluralLabel(),
            'description' => 'Every ' . strtolower($type->label()) . ' used by at least one story. Each links to the stories that use it.',
            'total' => number_format($total),
            'terms' => $terms,
            'letters' => count($letters) > 1 ? $letterLinks : [],
            'sorts' => $total === 0 ? [] : [
                ['label' => 'A to Z', 'url' => PublicUrl::browse($segment, ['letter' => $letter]), 'current' => !$mostUsed],
                ['label' => 'Most used', 'url' => PublicUrl::browse($segment, ['letter' => $letter, 'sort' => 'used']), 'current' => $mostUsed],
            ],
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'url' => PublicUrl::browse($segment) . '?' . ($query === '' ? '' : $query . '&') . 'page={page}',
            ],
        ], 200, ['title' => $type->pluralLabel()]);
    }

    private function plural(int $count, string $one, string $many): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $one : $many);
    }

    /**
     * @param array<string, string> $params
     */
    public function term(TermType $type, array $params, Request $request): Response
    {
        return $this->guarded(function () use ($type, $params, $request): Response {
            $term = $this->taxonomy->findBySlug($type, $params['slug'] ?? '');

            // A term whose stories are all hidden answers like a missing one, otherwise a tag proposed on a draft
            // could be found by guessing its address.
            if ($term === null || !$this->taxonomy->isEnabled($type)) {
                return $this->themes->notFound();
            }

            $total = $this->archive->countForTerm($term->id);

            if ($total === 0) {
                return $this->themes->notFound();
            }

            return $this->listing(
                $request,
                'archive',
                // Template names use hyphens, so a kind such as relationship_category is archive-relationship-category.
                str_replace('_', '-', $type->value),
                $type->label() . ': ' . $term->name,
                '',
                PublicUrl::term($type, $term->slug),
                $total,
                fn (string $sort, int $limit, int $offset): array => $this->archive->forTerm($term->id, $sort, $limit, $offset),
                null,
                $type === TermType::Fandom ? ['related' => $this->relatedLinks($term)] : [],
            );
        });
    }

    /**
     * A fandom's most used characters and relationships, as groups of links, so they can be reached from the
     * fandom without an index of their own. A kind the archive mode has switched off is left out.
     *
     * @return list<array{label: string, terms: list<array{name: string, url: string, count_label: string}>}>
     */
    private function relatedLinks(Term $fandom): array
    {
        $groups = [];

        foreach ([TermType::Character, TermType::Relationship] as $type) {
            if (!$this->taxonomy->isEnabled($type)) {
                continue;
            }

            $links = array_map(
                fn (Term $term): array => ['name' => $term->name, 'url' => PublicUrl::term($type, $term->slug), 'count_label' => $this->plural($term->storyCount, 'story', 'stories')],
                $this->taxonomy->relatedTerms($fandom->id, $type, self::RELATED_COUNT),
            );

            if ($links !== []) {
                $groups[] = ['label' => $type->pluralLabel(), 'terms' => $links];
            }
        }

        return $groups;
    }

    /**
     * Runs a page and applies the rule for reading without an account. When the archive is closed to visitors who
     * are not signed in, they are sent to sign in and no page is built; every page that is built for a signed-in
     * reader is marked private so a shared cache cannot hand it to someone else.
     *
     * @param Closure(): Response $page
     */
    private function guarded(Closure $page): Response
    {
        if ($this->config->setting('reading.require_signin') !== true) {
            $response = $page();

            // A page built for a signed-in reader carries their bookmarks and place, so no shared cache may keep it.
            return $this->auth->check() ? $response->withHeader('Cache-Control', 'private, no-store') : $response;
        }

        if (!$this->auth->check()) {
            return Response::redirect($this->signInUrl(), 302)->withHeader('Cache-Control', 'private, no-store');
        }

        return $page()->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Where a visitor who must sign in is sent. This is the one place that names the sign-in page.
     */
    private function signInUrl(): string
    {
        return PublicUrl::account('sign-in');
    }

    /**
     * The page for a story or chapter that is not public. Its owner, or a member of staff who may edit it, is sent to
     * the private preview; everyone else, signed in or not, gets the ordinary not-found page.
     */
    private function unavailable(string $slug, ?int $number): Response
    {
        $user = $this->auth->user();
        $story = $user === null ? null : $this->stories->findBySlug($slug);

        if ($user === null || $story === null || !$this->stories->canManage($user, $story)) {
            return $this->themes->notFound();
        }

        $preview = AdminUrl::to('stories/' . $story->id . '/preview');

        if ($number !== null) {
            try {
                foreach ($this->chapters->listForWriter($user, $story->id) as $chapter) {
                    if ($chapter->number === $number) {
                        $preview = AdminUrl::to('chapters/' . $chapter->id . '/preview');
                    }
                }
            } catch (StoryPermissionException) {
                return $this->themes->notFound();
            }
        }

        return Response::redirect($preview, 302)->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * What a story or chapter page shows only to a signed-in reader: the bookmark buttons and any message about the
     * last action. A guest gets empty lists, so a theme can draw them unconditionally.
     *
     * @param array<string, mixed> $cookies the request's cookies
     * @return array{bookmarks: list<array<string, mixed>>, notices: list<array{type: string, text: string}>, continue: array{title: string, url: string}|null}
     */
    private function readerData(?User $user, Story $story, ?Chapter $chapter, array $cookies): array
    {
        $notices = [];

        // Messages are pulled only for a signed-in reader, so a guest's pending sign-out notice is never taken here.
        foreach ($user === null ? [] : $this->flash->pull($cookies) as $message) {
            $notices[] = ['type' => $message['type'], 'text' => $message['message']];
        }

        return [
            'bookmarks' => $this->bookmarkPresenter->buttons($user, $story->id, $chapter?->number, $chapter?->id),
            'notices' => $notices,
            'continue' => null,
        ];
    }

    private function chapterLabel(int $number, string $title): string
    {
        return $title !== '' ? $title : 'Chapter ' . $number;
    }

    /**
     * The data for a chapter page, or for the text of a one-chapter story.
     *
     * @param list<Chapter> $chapters the chapters a reader can open, in order
     * @return array<string, mixed>
     */
    private function chapterData(Story $story, string $author, string $authorUrl, array $chapters, int $index): array
    {
        $chapter = $chapters[$index];

        return $this->presenter->chapter(
            $story,
            $author,
            $chapter,
            new SafeHtml($this->renderer->render($chapter->content, $chapter->format)),
            $chapters[$index - 1] ?? null,
            $chapters[$index + 1] ?? null,
            PublicUrl::story($story->slug),
            static fn (Chapter $other): string => PublicUrl::chapter($story->slug, $other->number),
            $index + 1,
            count($chapters),
            $authorUrl,
        );
    }

    /**
     * A page of stories with its sort choices and page links.
     *
     * @param Closure(string, int, int): list<Story> $fetch the stories for a sort, a limit and an offset
     * @param string|null $fixedSort a page with its own order offers no sort choices and ignores the address's
     * @param array<string, mixed> $extra more view data, such as a fandom's links to its characters
     */
    private function listing(Request $request, string $view, ?string $variant, string $title, string $description, string $baseUrl, int $total, Closure $fetch, ?string $fixedSort = null, array $extra = []): Response
    {
        $sort = $fixedSort ?? PublicArchive::sortOrDefault($request->queryValue('sort'));
        $pages = max(1, (int) ceil($total / PublicArchive::PAGE_SIZE));
        $requested = $request->queryValue('page');
        $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

        // A page number that is not a whole number above zero, or past the end, is simply not there.
        if ($page < 1 || $page > $pages || $page > self::MAX_PAGE) {
            return $this->themes->notFound();
        }

        $stories = $fetch($sort, PublicArchive::PAGE_SIZE, ($page - 1) * PublicArchive::PAGE_SIZE);
        $profiles = $this->users->publicProfiles(array_map(static fn (Story $story): int => $story->ownerId, $stories));

        $sorts = [];

        foreach ($fixedSort === null ? PublicArchive::SORTS : [] as $name => $label) {
            $sorts[] = [
                'label' => $label,
                'url' => $name === PublicArchive::DEFAULT_SORT ? $baseUrl : $baseUrl . '?sort=' . $name,
                'current' => $name === $sort,
            ];
        }

        return $this->themes->render($view, [
            'title' => $title,
            'description' => $description,
            'total' => number_format($total),
            'stories' => $this->cards($stories, $profiles),
            'sorts' => $sorts,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'url' => $baseUrl . '?' . ($fixedSort !== null || $sort === PublicArchive::DEFAULT_SORT ? '' : 'sort=' . $sort . '&') . 'page={page}',
            ],
        ] + $extra, 200, ['title' => $title, 'variant' => $variant]);
    }

    /**
     * @param list<Story> $stories
     * @param array<int, array{display_name: string, slug: string}> $profiles
     * @return list<array<string, string>>
     */
    private function cards(array $stories, array $profiles): array
    {
        return array_map(function (Story $story) use ($profiles): array {
            $profile = $profiles[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];

            return $this->presenter->card(
                $story,
                $profile['display_name'],
                $profile['slug'] === '' ? '' : PublicUrl::author($profile['slug']),
                PublicUrl::story($story->slug),
            );
        }, $stories);
    }

    /**
     * Drops the kinds of term the archive mode has switched off, so a fandom does not show on an original-fiction site.
     *
     * @param array<string, list<Term>> $terms
     * @return array<string, list<Term>>
     */
    private function visibleTerms(array $terms): array
    {
        $enabled = array_map(static fn (TermType $type): string => $type->value, $this->taxonomy->enabledTypes());

        return array_filter($terms, static fn (string $kind): bool => in_array($kind, $enabled, true), ARRAY_FILTER_USE_KEY);
    }
}
