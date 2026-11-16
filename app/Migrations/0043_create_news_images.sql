-- A news post can carry one featured picture. The pictures live in a table of their own so one file can be shared by several
-- posts and cleaned up when the last of them lets go of it; a post only holds the number of the picture and its own
-- alternative text (the same picture can need different wording in two posts). owner_id is not a foreign key, so removing an
-- account never removes a picture that other posts still show. size_bytes is the full-size and the thumbnail file together.
-- Existing posts have no picture and render as before. To undo this by hand, drop the three post columns and this table.
CREATE TABLE {prefix}news_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NULL,
    file_name VARCHAR(48) NOT NULL,
    width SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    thumb_width SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    thumb_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_file_name (file_name),
    KEY idx_owner_created (owner_id, created_at),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE {prefix}news_posts
    ADD COLUMN image_id INT UNSIGNED NULL AFTER pinned,
    ADD COLUMN image_alt VARCHAR(300) NOT NULL DEFAULT '' AFTER image_id,
    ADD COLUMN image_decorative TINYINT(1) NOT NULL DEFAULT 0 AFTER image_alt,
    ADD KEY idx_image (image_id);
