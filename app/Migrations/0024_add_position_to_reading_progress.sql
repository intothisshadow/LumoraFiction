-- How far down the chapter a signed-in reader got, as a whole number from 0 to 1000 (a share of the chapter text, so
-- it survives a change of window width or text size). It belongs to the chapter stored in the same row, so opening a
-- different chapter resets it to 0.
ALTER TABLE {prefix}reading_progress
    ADD COLUMN position SMALLINT UNSIGNED NOT NULL DEFAULT 0;
