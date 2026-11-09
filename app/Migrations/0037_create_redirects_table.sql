-- Redirects from one address of the site to another. source is a path on this site without the install folder, kept
-- short enough for the unique key; target is a path on this site or a full web address. automatic marks the ones the
-- application made when something was renamed or merged. created_by is not a foreign key, like every other account id here.
-- To undo this by hand, drop the table.
CREATE TABLE {prefix}redirects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(400) NOT NULL,
    target VARCHAR(500) NOT NULL,
    status SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    automatic TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_source (source),
    KEY idx_target (target(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
