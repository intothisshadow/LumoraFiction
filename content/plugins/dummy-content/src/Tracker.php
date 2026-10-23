<?php

/**
 * Remembers which users, stories and terms the Dummy Content plugin made, and removes them again.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

use LumoraFiction\Core\Database\Database;

/**
 * The plugin keeps its own table instead of marking rows in core tables, so core needs no migration for a
 * developer tool. Chapters, term links, bookmarks and the like are not listed: they are found through the story
 * or user they belong to, which is how removal reaches them.
 */
final class Tracker
{
    public const KIND_USER = 'user';

    public const KIND_STORY = 'story';

    public const KIND_TERM = 'term';

    public function __construct(private readonly Database $database)
    {
    }

    public function ensureTable(): void
    {
        $this->database->run(
            'CREATE TABLE IF NOT EXISTS {prefix}dummy_content_items (
                kind VARCHAR(10) NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                run_id CHAR(6) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (kind, item_id),
                KEY idx_run (run_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /**
     * @param list<int> $ids
     */
    public function record(string $kind, array $ids, string $runId): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (array_chunk($ids, 2000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $params = [];

            foreach ($chunk as $id) {
                array_push($params, $kind, $id, $runId, $now);
            }

            $this->database->execute('INSERT IGNORE INTO {prefix}dummy_content_items (kind, item_id, run_id, created_at) VALUES ' . $marks, $params);
        }
    }

    /**
     * @return list<int>
     */
    public function ids(string $kind): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['item_id'],
            $this->database->fetchAll('SELECT item_id FROM {prefix}dummy_content_items WHERE kind = :kind ORDER BY item_id', ['kind' => $kind]),
        );
    }

    /**
     * @return array{user: int, story: int, term: int}
     */
    public function counts(): array
    {
        $counts = [self::KIND_USER => 0, self::KIND_STORY => 0, self::KIND_TERM => 0];

        foreach ($this->database->fetchAll('SELECT kind, COUNT(*) AS total FROM {prefix}dummy_content_items GROUP BY kind') as $row) {
            if (isset($counts[(string) $row['kind']])) {
                $counts[(string) $row['kind']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Deletes everything the plugin made and the rows hanging off it, then forgets the list. Each statement
     * joins the list rather than sending ids, so a run of a hundred thousand stories is a handful of queries.
     *
     * @return array{user: int, story: int, term: int, chapter: int, bookmark: int}
     */
    public function removeAll(): array
    {
        $removed = ['user' => 0, 'story' => 0, 'term' => 0, 'chapter' => 0, 'bookmark' => 0];

        $removed['chapter'] = $this->database->execute(
            "DELETE c FROM {prefix}chapters c JOIN {prefix}dummy_content_items t ON t.kind = 'story' AND t.item_id = c.story_id",
        );
        $this->database->execute("DELETE st FROM {prefix}story_terms st JOIN {prefix}dummy_content_items t ON t.kind = 'story' AND t.item_id = st.story_id");
        $this->database->execute("DELETE st FROM {prefix}story_terms st JOIN {prefix}dummy_content_items t ON t.kind = 'term' AND t.item_id = st.term_id");
        $this->database->execute("DELETE a FROM {prefix}term_aliases a JOIN {prefix}dummy_content_items t ON t.kind = 'term' AND t.item_id = a.term_id");

        $removed['bookmark'] = $this->deleteFor('bookmarks');
        $this->deleteFor('reading_progress');
        $this->deleteFor('story_follows');

        $removed['story'] = $this->database->execute("DELETE s FROM {prefix}stories s JOIN {prefix}dummy_content_items t ON t.kind = 'story' AND t.item_id = s.id");
        $removed['user'] = $this->database->execute("DELETE u FROM {prefix}users u JOIN {prefix}dummy_content_items t ON t.kind = 'user' AND t.item_id = u.id");
        $removed['term'] = $this->database->execute("DELETE m FROM {prefix}terms m JOIN {prefix}dummy_content_items t ON t.kind = 'term' AND t.item_id = m.id");

        $this->database->execute('DELETE FROM {prefix}dummy_content_items');

        return $removed;
    }

    /**
     * A row goes when either side of it was made by the plugin: a real reader's bookmark on a dummy story points
     * at nothing once the story is gone.
     */
    private function deleteFor(string $table): int
    {
        $count = $this->database->execute(
            "DELETE x FROM {prefix}{$table} x JOIN {prefix}dummy_content_items t ON t.kind = 'story' AND t.item_id = x.story_id",
        );

        return $count + $this->database->execute(
            "DELETE x FROM {prefix}{$table} x JOIN {prefix}dummy_content_items t ON t.kind = 'user' AND t.item_id = x.user_id",
        );
    }
}
