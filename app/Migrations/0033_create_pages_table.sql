-- A static page (About, FAQ, Terms of Service, Privacy Policy) is staff-written site content with its own address. It is deliberately
-- not a story or a news post, so the archive's queries, counts and search never see it. The slug is chosen by the editor and is
-- unique among pages; edited_by is not a foreign key so removing an account can never cascade into deleting site content.
-- in_menu and in_footer say where a published page is linked; sort_order orders both lists, lowest first.
CREATE TABLE {prefix}pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_format VARCHAR(20) NOT NULL DEFAULT 'html',
    publication_state VARCHAR(20) NOT NULL DEFAULT 'draft',
    in_menu TINYINT(1) NOT NULL DEFAULT 0,
    in_footer TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    description VARCHAR(300) NOT NULL DEFAULT '',
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    edited_by INT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_state_placement (publication_state, in_menu, in_footer, sort_order),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
