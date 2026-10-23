-- Lets the short classification lists (ratings, warnings, categories) be ordered, described and hidden, and remembers which
-- starter-list value a term came from so re-applying a starter list can tell what is missing from what was edited.
-- hidden removes a term from what writers can choose and from the public indexes; stories that already use it keep it.
-- preset_key is empty for terms made by hand and is matched in the application, not by a unique key, because many are empty.
ALTER TABLE {prefix}terms
    ADD COLUMN description VARCHAR(300) NOT NULL DEFAULT '',
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN preset_key VARCHAR(60) NOT NULL DEFAULT '',
    ADD KEY idx_type_hidden_order (type, hidden, sort_order);
