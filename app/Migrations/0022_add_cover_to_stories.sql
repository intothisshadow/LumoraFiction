-- An optional cover picture. cover_file is the generated name of the stored picture (empty for none); the
-- picture's files live under content/uploads/covers. cover_alt is the text alternative readers who cannot
-- see the picture are given, and is kept whenever there is a cover.
ALTER TABLE {prefix}stories
    ADD COLUMN cover_file VARCHAR(64) NOT NULL DEFAULT '' AFTER disclaimer,
    ADD COLUMN cover_alt VARCHAR(250) NOT NULL DEFAULT '' AFTER cover_file;
