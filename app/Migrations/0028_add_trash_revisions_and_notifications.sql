-- Trash: a story or chapter moved to the trash keeps its row and is marked with the time, so it can be restored whole.
-- A trashed chapter also leaves the story's numbering (the application gives it a number outside the story's own range),
-- so the chapters left behind stay numbered 1 to n. Anything trashed is hidden from readers, because it is no longer published.
ALTER TABLE {prefix}stories
    ADD COLUMN trashed_at DATETIME NULL AFTER updated_at,
    ADD KEY idx_trashed (trashed_at);

ALTER TABLE {prefix}chapters
    ADD COLUMN trashed_at DATETIME NULL AFTER updated_at,
    ADD KEY idx_trashed (trashed_at);

-- Earlier versions of a chapter, saved just before a change replaces them, so an author can go back to one. The chapter
-- row is not a foreign key because the application deletes a story's chapters and their versions together.
CREATE TABLE {prefix}chapter_revisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chapter_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_format VARCHAR(20) NOT NULL DEFAULT 'html',
    notes TEXT NOT NULL,
    word_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_chapter (chapter_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A short message for one person about something that needs their attention, with the admin page that handles it.
-- Messages are written by the application and name a story by its title, which is escaped wherever it is shown.
CREATE TABLE {prefix}notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    kind VARCHAR(30) NOT NULL,
    story_id INT UNSIGNED NULL,
    message VARCHAR(300) NOT NULL,
    path VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    KEY idx_user_read (user_id, read_at, id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
