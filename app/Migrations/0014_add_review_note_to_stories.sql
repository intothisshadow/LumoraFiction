-- Why a story was sent back to its author. A rejected story returns to draft, so the note is what tells
-- the author it was rejected; it is cleared when the story is submitted or published again.
ALTER TABLE {prefix}stories
    ADD COLUMN rejection_reason TEXT NULL AFTER publication_state,
    ADD COLUMN rejected_at DATETIME NULL AFTER rejection_reason;
