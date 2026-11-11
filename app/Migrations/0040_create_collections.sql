-- A collection is a curated group of stories that need not be in order and may hold other people's stories. The curator does not own
-- the stories in it, so collection_stories records who added each one (added_by) and whether the curator has approved it.
-- owner_id and added_by are deliberately not foreign keys: removing an account is a separate, explicit choice.
-- visibility is public or private; requires_approval holds a submission by someone else until the curator approves it;
-- accepts_submissions lets other authors offer their stories at all.
-- To undo this by hand, drop collection_stories, then collections.
CREATE TABLE {prefix}collections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'public',
    accepts_submissions TINYINT(1) NOT NULL DEFAULT 0,
    requires_approval TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_owner_updated (owner_id, updated_at),
    KEY idx_visibility_updated (visibility, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}collection_stories (
    collection_id INT UNSIGNED NOT NULL,
    story_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED NOT NULL,
    state VARCHAR(10) NOT NULL DEFAULT 'approved',
    note VARCHAR(300) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (collection_id, story_id),
    KEY idx_story (story_id),
    KEY idx_added_by (added_by),
    KEY idx_collection_state (collection_id, state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Collections have a feed, listed on Settings > Feeds as a new kind. A site that already saved its choice of feeds starts with it ticked.
UPDATE {prefix}settings
SET setting_value = JSON_ARRAY_APPEND(setting_value, '$', 'collection')
WHERE setting_name = 'feeds.kinds'
  AND JSON_VALID(setting_value)
  AND JSON_TYPE(setting_value) = 'ARRAY'
  AND JSON_SEARCH(setting_value, 'one', 'collection') IS NULL;
