-- An alias is another name that leads to a term, so a writer who types "Hermione G." finds "Hermione Granger".
-- type and parent_id repeat the term's own so the unique key can stop one alias pointing at two terms in the
-- same list; the application keeps them in step when terms are merged.
CREATE TABLE {prefix}term_aliases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id INT UNSIGNED NOT NULL,
    type VARCHAR(30) NOT NULL,
    parent_id INT UNSIGNED NOT NULL DEFAULT 0,
    alias VARCHAR(100) NOT NULL,
    alias_key VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_type_parent_alias (type, parent_id, alias_key),
    KEY idx_term (term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
