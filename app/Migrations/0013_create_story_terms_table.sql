-- Links a story to its terms. Neither column is a foreign key: the application removes a story's links in the
-- same transaction as the story, and moves them explicitly when terms are merged.
CREATE TABLE {prefix}story_terms (
    story_id INT UNSIGNED NOT NULL,
    term_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (story_id, term_id),
    KEY idx_term_story (term_id, story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
