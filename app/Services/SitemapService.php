<?php

/**
 * Builds the XML sitemap: an index and the parts it points to.
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

use DateTimeImmutable;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Models\TermType;

/**
 * Lists only what a visitor could already open: public stories (the same condition every listing uses), authors who
 * have one, terms an index would list, the news archive and published news posts, and published pages that are not
 * marked noindex. Chapters are left out on purpose, since a story's page is the entry point and a big archive would
 * otherwise run into the limit of 50,000 addresses per file. The index points at parts of at most PART_SIZE addresses.
 */
final class SitemapService
{
    public const PART_SIZE = 10000;

    /** The kinds of part, in the order the index lists them. */
    public const KINDS = ['site', 'authors', 'terms', 'stories'];

    private const PART_PATTERN = '/^(site|authors|terms|stories)-([1-9][0-9]{0,5})\z/';

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly TaxonomyService $taxonomy,
        private readonly PageService $pages,
        private readonly string $siteUrl,
    ) {
    }

    /**
     * Without a configured site address no absolute address can be written, and the sitemap protocol needs them.
     */
    public function available(): bool
    {
        return $this->siteUrl !== '' && SearchSettings::sitemapOffered($this->config);
    }

    /**
     * @return list<string> the part names that have content, such as stories-1
     */
    public function parts(): array
    {
        $parts = ['site-1'];

        foreach (['authors' => $this->countAuthors(), 'terms' => $this->countTerms(), 'stories' => $this->countStories()] as $kind => $total) {
            for ($number = 1; $number <= (int) ceil($total / self::PART_SIZE); $number++) {
                $parts[] = $kind . '-' . $number;
            }
        }

        return $parts;
    }

    public function index(): string
    {
        $entries = '';

        foreach ($this->parts() as $part) {
            $entries .= '<sitemap><loc>' . $this->xml(PublicUrl::absolute($this->siteUrl, PublicUrl::sitemapPart($part))) . '</loc></sitemap>' . "\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $entries . '</sitemapindex>' . "\n";
    }

    /**
     * One part as a urlset, or null for a name that is not one this sitemap makes.
     */
    public function part(string $name): ?string
    {
        if (preg_match(self::PART_PATTERN, $name, $matches) !== 1) {
            return null;
        }

        $kind = $matches[1];
        $number = (int) $matches[2];

        // "site" is one file; a number past the end of any other kind is not a part.
        if (($kind === 'site' && $number !== 1) || !in_array($name, $this->parts(), true)) {
            return null;
        }

        $offset = ($number - 1) * self::PART_SIZE;
        $rows = match ($kind) {
            'site' => $this->siteRows(),
            'authors' => $this->authorRows($offset),
            'terms' => $this->termRows($offset),
            default => $this->storyRows($offset),
        };

        $entries = '';

        foreach ($rows as [$path, $modified]) {
            $entries .= '<url><loc>' . $this->xml(PublicUrl::absolute($this->siteUrl, $path)) . '</loc>'
                . ($modified === null ? '' : '<lastmod>' . $modified->format('Y-m-d') . '</lastmod>') . '</url>' . "\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $entries . '</urlset>' . "\n";
    }

    /**
     * @return list<array{string, ?DateTimeImmutable}>
     */
    private function siteRows(): array
    {
        $rows = [[PublicUrl::home(), null]];

        if (NewsSettings::enabled($this->config)) {
            $posts = $this->database->fetchAll(
                'SELECT slug, updated_at FROM {prefix}news_posts WHERE publication_state = \'published\' ORDER BY published_at DESC, id DESC LIMIT ' . (self::PART_SIZE - 1000),
            );

            if ($posts !== []) {
                $rows[] = [PublicUrl::news(), $this->date($posts[0]['updated_at'])];
            }

            foreach ($posts as $post) {
                $rows[] = [PublicUrl::newsPost((string) $post['slug']), $this->date($post['updated_at'])];
            }
        }

        foreach ($this->pages->published() as $page) {
            if (!$page->noindex) {
                $rows[] = [PublicUrl::page($page->slug), $page->updatedAt];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{string, ?DateTimeImmutable}>
     */
    private function authorRows(int $offset): array
    {
        $rows = [];

        foreach ($this->database->fetchAll(
            'SELECT u.slug, MAX(s.updated_at) AS changed FROM {prefix}stories s JOIN {prefix}users u ON u.id = s.owner_id WHERE ' . StoryVisibility::publicCondition('s')
            . ' GROUP BY u.id, u.slug ORDER BY u.id LIMIT ' . self::PART_SIZE . ' OFFSET ' . $offset,
        ) as $row) {
            $rows[] = [PublicUrl::author((string) $row['slug']), $this->date($row['changed'])];
        }

        return $rows;
    }

    /**
     * @return list<array{string, ?DateTimeImmutable}>
     */
    private function termRows(int $offset): array
    {
        $kinds = $this->enabledKinds();
        $rows = [];

        foreach ($this->database->fetchAll(
            'SELECT t.type, t.slug FROM {prefix}terms t WHERE ' . $this->termCondition($kinds) . ' ORDER BY t.id LIMIT ' . self::PART_SIZE . ' OFFSET ' . $offset,
        ) as $row) {
            $type = TermType::tryFrom((string) $row['type']);

            if ($type !== null) {
                $rows[] = [PublicUrl::term($type, (string) $row['slug']), null];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{string, ?DateTimeImmutable}>
     */
    private function storyRows(int $offset): array
    {
        $rows = [];

        foreach ($this->database->fetchAll(
            'SELECT s.slug, s.updated_at FROM {prefix}stories s WHERE ' . StoryVisibility::publicCondition('s') . ' ORDER BY s.id LIMIT ' . self::PART_SIZE . ' OFFSET ' . $offset,
        ) as $row) {
            $rows[] = [PublicUrl::story((string) $row['slug']), $this->date($row['updated_at'])];
        }

        return $rows;
    }

    private function countStories(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}stories s WHERE ' . StoryVisibility::publicCondition('s'));
    }

    private function countAuthors(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(DISTINCT s.owner_id) FROM {prefix}stories s WHERE ' . StoryVisibility::publicCondition('s'));
    }

    private function countTerms(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}terms t WHERE ' . $this->termCondition($this->enabledKinds()));
    }

    /**
     * The terms a Browse index lists, so the sitemap never advertises a tag only a draft carries or one nobody reviewed.
     *
     * @param list<string> $kinds
     */
    private function termCondition(array $kinds): string
    {
        // The kind names come from the enum, never from a request, so quoting them here cannot carry user data.
        $list = implode(', ', array_map(static fn (string $kind): string => "'" . $kind . "'", $kinds === [] ? ['none'] : $kinds));

        return 't.reviewed = 1 AND t.hidden = 0 AND t.story_count > 0 AND t.type IN (' . $list . ')';
    }

    /**
     * @return list<string>
     */
    private function enabledKinds(): array
    {
        return array_map(static fn (TermType $type): string => $type->value, $this->taxonomy->enabledTypes());
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
