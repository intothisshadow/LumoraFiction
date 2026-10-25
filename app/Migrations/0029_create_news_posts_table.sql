-- A news post is a staff announcement shown to readers. It is deliberately not a story: it has its own table so the archive's
-- story queries, counts and search never see it.
-- author_id is not a foreign key, so removing an account can never cascade into deleting what staff announced.
-- The slug starts with the post's own number, so it is unique without counters; it follows the title until the first
-- publication and is fixed after that. published_at is kept when a post goes back to draft so its address and date survive.
CREATE TABLE {prefix}news_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_format VARCHAR(20) NOT NULL DEFAULT 'html',
    publication_state VARCHAR(20) NOT NULL DEFAULT 'draft',
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_state_pinned_published (publication_state, pinned, published_at),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
