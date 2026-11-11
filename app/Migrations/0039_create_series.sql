-- A series is an ordered sequence of stories by one author. It belongs to its author's work, so owner_id is deliberately not a
-- foreign key (removing an account is a separate, explicit choice) and deleting a series never touches the stories in it.
-- A story sits in at most one series, which uniq_story enforces; position counts from 1 and is kept without gaps by the application.
-- To undo this by hand, drop series_stories, then series.
CREATE TABLE {prefix}series (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    summary TEXT NOT NULL,
    work_status VARCHAR(20) NOT NULL DEFAULT 'ongoing',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_owner_updated (owner_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}series_stories (
    series_id INT UNSIGNED NOT NULL,
    story_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    PRIMARY KEY (series_id, story_id),
    UNIQUE KEY uniq_story (story_id),
    KEY idx_series_position (series_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Series have a feed, listed on Settings > Feeds as a new kind. A site that already saved its choice of feeds starts with it ticked.
UPDATE {prefix}settings
SET setting_value = JSON_ARRAY_APPEND(setting_value, '$', 'series')
WHERE setting_name = 'feeds.kinds'
  AND JSON_VALID(setting_value)
  AND JSON_TYPE(setting_value) = 'ARRAY'
  AND JSON_SEARCH(setting_value, 'one', 'series') IS NULL;
