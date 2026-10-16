-- A story is the unit readers find and authors publish; its text lives in chapters (a one-shot has one).
-- owner_id is deliberately not a foreign key: removing an account must never cascade into deleting
-- stories, because what happens to an account's stories is a separate, explicit choice.
-- word_count is the total over every chapter, kept in step by the application whenever a chapter changes.
CREATE TABLE {prefix}stories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    summary TEXT NOT NULL,
    notes TEXT NOT NULL,
    disclaimer TEXT NOT NULL,
    language VARCHAR(12) NOT NULL DEFAULT 'en',
    work_status VARCHAR(20) NOT NULL DEFAULT 'ongoing',
    publication_state VARCHAR(20) NOT NULL DEFAULT 'draft',
    word_count INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_owner_updated (owner_id, updated_at),
    KEY idx_state_published (publication_state, published_at),
    KEY idx_state_updated (publication_state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
