-- A reply answers one top-level comment and points at it. NULL for every comment made before replies existed, so none of
-- them changes. Not a foreign key, like every other id here: the application removes a comment's replies with the comment.
-- To undo this by hand, drop the key idx_parent and then the column parent_id from this table.
ALTER TABLE {prefix}comments
    ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD KEY idx_parent (parent_id, status, created_at, id);
