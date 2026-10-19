-- The chapter a signed-in reader opened last in each story. Guests are never recorded. One row per reader and
-- story, so the table grows with what people read and not with how often they read it.
CREATE TABLE {prefix}reading_progress (
    user_id INT UNSIGNED NOT NULL,
    story_id INT UNSIGNED NOT NULL,
    chapter_id INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, story_id),
    KEY idx_user_updated (user_id, updated_at),
    KEY idx_story (story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
