-- One table holds every kind of classification term (fandom, character, tag, rating and so on), told apart by
-- type, so adding a kind of term never needs a new table. parent_id is the fandom a character or relationship
-- belongs to, or 0 when it belongs to none, which keeps the same name in two fandoms as two terms.
-- name_key is the name lower-cased and space-collapsed; with type and parent_id it is what stops duplicates.
-- story_count is the number of public stories using the term, kept in step by the application.
-- Neither parent_id nor created_by is a foreign key: merging and account removal are explicit, separate steps.
CREATE TABLE {prefix}terms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL,
    parent_id INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    name_key VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    reviewed TINYINT(1) NOT NULL DEFAULT 1,
    story_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_type_parent_key (type, parent_id, name_key),
    UNIQUE KEY uniq_type_slug (type, slug),
    KEY idx_type_count (type, story_count),
    KEY idx_type_reviewed (type, reviewed),
    KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
