<?php

/**
 * Reads the eFiction database to say what an import would do, and suggests how its classification maps to this site's.
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
use LumoraFiction\Models\TermType;
use LumoraFiction\Services\AccountRules;
use LumoraFiction\Services\TaxonomyService;

/**
 * Nothing here writes anything: it reads the source and, for what already exists here, this site.
 */
final class Survey
{
    /** Looking at every chapter file of a very large archive would outlast a page request, so the preview checks this many. */
    public const CHAPTER_SAMPLE = 3000;

    public function __construct(
        private readonly Source $source,
        private readonly Database $database,
        private readonly TaxonomyService $taxonomy,
        private readonly Tracker $tracker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(ChapterText $text): array
    {
        $source = $this->source;
        $settings = $source->settings();
        $facts = ['version' => $settings['version'] ?? '', 'store' => ($settings['store'] ?? 'files') === 'db' ? 'db' : 'files', 'language' => $settings['language'] ?? 'en'];

        $authors = $source->select('SELECT COUNT(*) AS total, COALESCE(SUM(password LIKE \'$2%\'), 0) AS bcrypt FROM `{p}authors`')[0] ?? [];
        $facts['authors'] = (int) ($authors['total'] ?? 0);
        $facts['bcrypt'] = (int) ($authors['bcrypt'] ?? 0);
        $facts['legacy_hashes'] = $facts['authors'] - $facts['bcrypt'];
        $facts['staff'] = $source->hasTable('authorprefs') ? (int) $source->value('SELECT COUNT(*) FROM `{p}authorprefs` WHERE level > 0') : 0;
        $facts['admin_made'] = (int) $source->value('SELECT COUNT(*) FROM `{p}authors` WHERE admincreated > 0');
        [$facts['email_clashes'], $facts['bad_emails']] = $this->emails();

        $facts['stories'] = (int) $source->value('SELECT COUNT(*) FROM `{p}stories`');
        $facts['stories_validated'] = (int) $source->value('SELECT COUNT(*) FROM `{p}stories` WHERE validated > 0');
        $facts['stories_no_chapters'] = (int) $source->value('SELECT COUNT(*) FROM `{p}stories` s WHERE NOT EXISTS (SELECT 1 FROM `{p}chapters` c WHERE c.sid = s.sid)');
        $facts['stories_no_author'] = (int) $source->value('SELECT COUNT(*) FROM `{p}stories` s WHERE NOT EXISTS (SELECT 1 FROM `{p}authors` a WHERE a.uid = s.uid)');
        $facts['chapters'] = (int) $source->value('SELECT COUNT(*) FROM `{p}chapters`');
        $facts['chapter_check'] = $this->chapterCheck($text);

        $facts['categories'] = $source->hasTable('categories') ? (int) $source->value('SELECT COUNT(*) FROM `{p}categories`') : 0;
        $facts['characters'] = $source->hasTable('characters') ? (int) $source->value('SELECT COUNT(*) FROM `{p}characters`') : 0;
        $facts['ratings'] = $source->hasTable('ratings') ? (int) $source->value('SELECT COUNT(*) FROM `{p}ratings`') : 0;
        $facts['classes'] = $source->hasTable('classes') ? (int) $source->value('SELECT COUNT(*) FROM `{p}classes`') : 0;

        $facts['reviews'] = $source->hasTable('reviews') ? (int) $source->value('SELECT COUNT(*) FROM `{p}reviews` WHERE type = \'ST\' AND uid > 0') : 0;
        $facts['guest_reviews'] = $source->hasTable('reviews') ? (int) $source->value('SELECT COUNT(*) FROM `{p}reviews` WHERE type = \'ST\' AND uid = 0') : 0;
        $facts['favorites'] = $source->hasTable('favorites') ? (int) $source->value('SELECT COUNT(*) FROM `{p}favorites` WHERE type = \'ST\'') : 0;
        $facts['news'] = $source->hasTable('news') ? (int) $source->value('SELECT COUNT(*) FROM `{p}news`') : 0;
        $facts['series'] = $source->hasTable('series') ? (int) $source->value('SELECT COUNT(*) FROM `{p}series`') : 0;
        $facts['coauthored'] = $source->hasTable('coauthors') ? (int) $source->value('SELECT COUNT(*) FROM `{p}coauthors`') : 0;
        $facts['pages'] = $this->pages();
        $facts['already'] = $this->tracker->exists() ? $this->tracker->counts() : [];

        return $facts;
    }

    /**
     * What the classification would become, with a suggested choice for each item. Every choice can be changed on the screen.
     *
     * @return array<string, mixed>
     */
    public function mapping(): array
    {
        $source = $this->source;
        $used = ['rid' => $this->usage('rid'), 'catid' => $this->usage('catid'), 'charid' => $this->usage('charid'), 'classes' => $this->usage('classes')];
        $mapping = ['ratings' => [], 'categories' => [], 'characters' => ['total' => 0, 'used' => 0, 'choice' => 'import'], 'classtypes' => []];

        if ($source->hasTable('ratings')) {
            foreach ($source->select('SELECT rid, rating FROM `{p}ratings` ORDER BY rid') as $row) {
                $name = (string) $row['rating'];
                $match = $this->taxonomy->resolve(TermType::Rating, $this->safeName($name));
                $mapping['ratings'][] = ['id' => (int) $row['rid'], 'name' => $name, 'count' => $used['rid'][(int) $row['rid']] ?? 0, 'choice' => $match !== null ? $match->id : 'new'];
            }
        }

        if ($source->hasTable('categories')) {
            $parents = [];

            foreach ($source->select('SELECT catid, parentcatid, category FROM `{p}categories` ORDER BY parentcatid, displayorder, category') as $row) {
                $parents[(int) $row['catid']] = (string) $row['category'];
            }

            foreach ($source->select('SELECT catid, parentcatid, category FROM `{p}categories` ORDER BY parentcatid, displayorder, category') as $row) {
                $count = $used['catid'][(int) $row['catid']] ?? 0;
                $mapping['categories'][] = [
                    'id' => (int) $row['catid'],
                    'name' => (string) $row['category'],
                    'parent' => $parents[(int) $row['parentcatid']] ?? '',
                    'count' => $count,
                    'choice' => $count > 0 ? 'import' : 'skip',
                ];
            }
        }

        if ($source->hasTable('characters')) {
            $mapping['characters'] = ['total' => (int) $source->value('SELECT COUNT(*) FROM `{p}characters`'), 'used' => count($used['charid']), 'choice' => 'import'];
        }

        if ($source->hasTable('classtypes') && $source->hasTable('classes')) {
            $classUse = $used['classes'];

            foreach ($source->select('SELECT t.classtype_id, t.classtype_name, t.classtype_title, COUNT(c.class_id) AS classes FROM `{p}classtypes` t LEFT JOIN `{p}classes` c ON c.class_type = t.classtype_id GROUP BY t.classtype_id, t.classtype_name, t.classtype_title ORDER BY t.classtype_id') as $row) {
                $ids = array_column($source->select('SELECT class_id FROM `{p}classes` WHERE class_type = :type', ['type' => (int) $row['classtype_id']]), 'class_id');
                $mapping['classtypes'][] = [
                    'id' => (int) $row['classtype_id'],
                    'name' => (string) ($row['classtype_title'] !== '' ? $row['classtype_title'] : $row['classtype_name']),
                    'classes' => (int) $row['classes'],
                    'count' => array_sum(array_map(static fn ($id): int => $classUse[(int) $id] ?? 0, $ids)),
                    'choice' => self::suggestKind((string) $row['classtype_name'] . ' ' . (string) $row['classtype_title']),
                ];
            }
        }

        return $mapping;
    }

    /**
     * The kind a class type most likely is, from its name: genres and warnings have their own lists here, everything else
     * becomes tags.
     */
    public static function suggestKind(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_contains($name, 'genre') => TermType::Genre->value,
            str_contains($name, 'warning') => TermType::Warning->value,
            default => TermType::Tag->value,
        };
    }

    /**
     * Custom pages: eFiction keeps them in `messages`, shown through viewpage.php?page=name. The built-in texts (welcome,
     * copyright and the like) are listed too, because a site often has real wording in them.
     *
     * @return list<array{id: int, name: string, title: string}>
     */
    public function pages(): array
    {
        if (!$this->source->hasTable('messages')) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['message_id'], 'name' => (string) $row['message_name'], 'title' => (string) $row['message_title']],
            $this->source->select('SELECT message_id, message_name, message_title FROM `{p}messages` WHERE message_text IS NOT NULL AND message_text <> \'\' ORDER BY message_id'),
        );
    }

    /**
     * @return array{0: int, 1: int} accounts whose email is already used here, and emails that cannot be used at all
     */
    private function emails(): array
    {
        $clashes = 0;
        $bad = 0;
        $cursor = 0;

        while (true) {
            $rows = $this->source->select('SELECT uid, email FROM `{p}authors` WHERE uid > :cursor ORDER BY uid LIMIT 2000', ['cursor' => $cursor]);

            if ($rows === []) {
                break;
            }

            $emails = [];

            foreach ($rows as $row) {
                $cursor = (int) $row['uid'];
                $email = AccountRules::normalizeEmail((string) $row['email']);

                if (AccountRules::emailProblem($email) !== null) {
                    $bad++;
                } else {
                    $emails[] = $email;
                }
            }

            if ($emails !== []) {
                $clashes += (int) $this->database->fetchColumn(
                    'SELECT COUNT(*) FROM {prefix}users WHERE email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')',
                    $emails,
                );
            }
        }

        return [$clashes, $bad];
    }

    /**
     * Where the chapter text is, for the first few thousand chapters.
     *
     * @return array{checked: int, database: int, file: int, none: int, converted: int}
     */
    private function chapterCheck(ChapterText $text): array
    {
        $check = ['checked' => 0, 'database' => 0, 'file' => 0, 'none' => 0, 'converted' => 0];

        foreach ($this->source->select('SELECT chapid, uid, storytext FROM `{p}chapters` ORDER BY chapid LIMIT ' . self::CHAPTER_SAMPLE) as $row) {
            $found = $text->find($row);
            $check['checked']++;
            $check[$found['from']]++;
            $check['converted'] += $found['converted'] ? 1 : 0;
        }

        return $check;
    }

    /**
     * How many stories use each id of a comma-separated list column (rid, catid, charid, classes).
     *
     * @return array<int, int>
     */
    private function usage(string $column): array
    {
        if (!in_array($column, ['rid', 'catid', 'charid', 'classes'], true)) {
            return [];
        }

        $counts = [];

        foreach ($this->source->select('SELECT `' . $column . '` AS ids, COUNT(*) AS total FROM `{p}stories` GROUP BY `' . $column . '`') as $row) {
            foreach (self::ids((string) $row['ids']) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * eFiction stores several ids in one column as "3,17,22"; 0 and empty mean none.
     *
     * @return list<int>
     */
    public static function ids(string $list): array
    {
        $ids = [];

        foreach (explode(',', $list) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function safeName(string $name): string
    {
        try {
            return $this->taxonomy->cleanName($name);
        } catch (\Throwable) {
            return '';
        }
    }
}
