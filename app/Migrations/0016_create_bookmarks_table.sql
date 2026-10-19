-- A signed-in reader's bookmark on a whole story (chapter_id 0) or on one chapter. chapter_id is never NULL so the
-- unique key can reject a duplicate. Neither id is a foreign key: the application removes a reader's rows when the
-- account goes, and a bookmark whose story is no longer public is simply left out of the list.
CREATE TABLE {prefix}bookmarks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    story_id INT UNSIGNED NOT NULL,
    chapter_id INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_story_chapter (user_id, story_id, chapter_id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_story (story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
