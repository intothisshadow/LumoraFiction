-- A page may sit under one parent page, so the main menu can group pages in a drop-down. NULL for every page that exists,
-- which all stay at the top level. One level only (a parent never has a parent of its own), and not a foreign key like every
-- other id here: the application moves a deleted parent's children up to the top level when it deletes the parent.
-- To undo this by hand, drop the key idx_parent and then the column parent_id from this table.
ALTER TABLE {prefix}pages
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER sort_order,
    ADD KEY idx_parent (parent_id, sort_order);
