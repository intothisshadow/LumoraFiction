-- Reader comments under a chapter. Flat, plain text. None of the ids is a foreign key: the application removes a
-- chapter's or story's comments when it removes the chapter or story, and a comment keeps its account number after the
-- account is anonymised, so the comment survives while the name shown becomes the neutral one.
CREATE TABLE {prefix}comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    story_id INT UNSIGNED NOT NULL,
    chapter_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'published',
    report_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    edited_at DATETIME NULL,
    KEY idx_chapter_listing (chapter_id, status, created_at, id),
    KEY idx_status_queue (status, created_at, id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_story_status (story_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One report per reader per comment. The reporter is shown to staff and never to the commenter.
CREATE TABLE {prefix}comment_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id BIGINT UNSIGNED NOT NULL,
    reporter_id INT UNSIGNED NOT NULL,
    reason VARCHAR(300) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_comment_reporter (comment_id, reporter_id),
    KEY idx_reporter (reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A row means comments are turned off. chapter_id 0 is the whole story, so the unique key can reject a duplicate.
CREATE TABLE {prefix}comment_closures (
    story_id INT UNSIGNED NOT NULL,
    chapter_id INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (story_id, chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-account comment settings: staff's hold or block of future comments, and the person's own notification choices.
CREATE TABLE {prefix}comment_accounts (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    moderation VARCHAR(8) NOT NULL DEFAULT '',
    notify_comments TINYINT UNSIGNED NOT NULL DEFAULT 1,
    email_comments TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_replies TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
