-- The stories a signed-in reader has chosen to follow, so the site can say when one has something new. Following is
-- always an explicit choice: reading or bookmarking a story never adds a row. One row per reader and story.
CREATE TABLE {prefix}story_follows (
    user_id INT UNSIGNED NOT NULL,
    story_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, story_id),
    KEY idx_story (story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
