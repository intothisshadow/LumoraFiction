-- The name of a guest whose review an importer brought across. NULL for every comment that exists, and for every comment a
-- person posts: only an Administrator's import ever sets it, and the comment itself still belongs to the importing account.
-- To undo this by hand, drop the column guest_name from this table.
ALTER TABLE {prefix}comments
    ADD COLUMN guest_name VARCHAR(60) NULL AFTER parent_id;
