-- A reader's colour scheme, text size and reading width as a small JSON object. NULL means nothing was saved, so the
-- browser's own choice (or the theme default) applies.
ALTER TABLE {prefix}users ADD COLUMN reader_preferences VARCHAR(200) NULL AFTER publishing_override;
