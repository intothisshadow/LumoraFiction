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
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\WorkStatus;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ContentRenderer;
use LumoraFiction\Services\PublicArchive;
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

    /** Pages beyond this are refused outright, so a huge number in an address cannot become a huge database offset. */
    private const MAX_PAGE = 10000;

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

            return $this->themes->render('home', [
                'stories' => $this->cards($published, $profiles),
                'updated_stories' => $this->cards($updated, $profiles),
            ]);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function story(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($params): Response {
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

            // A finished story told in one chapter reads straight away, with its details above the text.
            if (count($chapters) === 1 && $story->workStatus === WorkStatus::Completed) {
                $data['chapter'] = $this->chapterData($story, $profile['display_name'], $authorUrl, $chapters, 0)['chapter'];
            }

            return $this->themes->render('story', $data, 200, ['title' => $story->title]);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function chapter(array $params, Request $request): Response
    {
        return $this->guarded(function () use ($params): Response {
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
            );
        });
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
            return $page();
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
     */
    private function listing(Request $request, string $view, ?string $variant, string $title, string $description, string $baseUrl, int $total, Closure $fetch): Response
    {
        $sort = PublicArchive::sortOrDefault($request->queryValue('sort'));
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

        foreach (PublicArchive::SORTS as $name => $label) {
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
                'url' => $baseUrl . '?' . ($sort === PublicArchive::DEFAULT_SORT ? '' : 'sort=' . $sort . '&') . 'page={page}',
            ],
        ], 200, ['title' => $title, 'variant' => $variant]);
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
