-- Whether readers may download a story. A story has its own choice (default, allow or refuse) and its Author has a default for
-- every story that makes no choice of its own. The site-wide switch is a setting, not a column.
-- downloads_default is 1 (allow) so every existing Author and story keeps behaving the same once downloads are switched on.
ALTER TABLE {prefix}stories ADD COLUMN download_choice VARCHAR(10) NOT NULL DEFAULT 'default';
ALTER TABLE {prefix}users ADD COLUMN downloads_default TINYINT(1) NOT NULL DEFAULT 1;

-- Plain totals for each story and format: no row per download and nothing about who downloaded.
CREATE TABLE {prefix}story_download_counts (
    story_id INT UNSIGNED NOT NULL,
    format VARCHAR(10) NOT NULL,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (story_id, format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
