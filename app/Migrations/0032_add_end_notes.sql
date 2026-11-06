-- A note printed after the text as well as the existing one printed before it. The columns allow NULL so every existing row
-- keeps working untouched: a missing end note and an empty one both print nothing.
ALTER TABLE {prefix}chapters ADD COLUMN end_notes TEXT NULL AFTER notes;
ALTER TABLE {prefix}chapter_revisions ADD COLUMN end_notes TEXT NULL AFTER notes;
ALTER TABLE {prefix}stories ADD COLUMN end_notes TEXT NULL AFTER notes;
