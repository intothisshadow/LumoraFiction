-- Stories an author brought across from another archive (AO3, Squidgeworld and other OTW-Archive sites). import_runs holds one row
-- per confirmed import with its report, so the report can be read and the import undone afterwards. story_imports ties each
-- new story to the work it came from, by the address printed in the downloaded file (source_id) or, when the file has none, a
-- hash of its title, author and first chapter, so importing the same work again can be noticed. user_id and story_id are
-- deliberately not foreign keys: removing a story or an account clears its rows explicitly, as for series and collections.
-- To undo this by hand, drop story_imports, then import_runs.
CREATE TABLE {prefix}import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token CHAR(32) NOT NULL,
    report MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_token (token),
    KEY idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}story_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    story_id INT UNSIGNED NOT NULL,
    source_id VARCHAR(255) NOT NULL DEFAULT '',
    source_url VARCHAR(500) NOT NULL DEFAULT '',
    source_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_story (story_id),
    KEY idx_user_source (user_id, source_id),
    KEY idx_user_hash (user_id, source_hash),
    KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
