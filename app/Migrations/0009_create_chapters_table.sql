-- A chapter belongs to one story and is numbered 1..n within it. The unique key on (story_id, number)
-- is what guarantees numbering stays gap-free and duplicate-free; reordering renumbers in two steps
-- so the key is never violated part-way through.
-- story_id is not a foreign key: the application deletes a story and its chapters in one transaction.
CREATE TABLE {prefix}chapters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    story_id INT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_format VARCHAR(20) NOT NULL DEFAULT 'html',
    notes TEXT NOT NULL,
    word_count INT UNSIGNED NOT NULL DEFAULT 0,
    publication_state VARCHAR(20) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_story_number (story_id, number),
    KEY idx_story_state_number (story_id, publication_state, number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
