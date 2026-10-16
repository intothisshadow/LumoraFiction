-- An administrator's decision about one writer: "direct" lets them publish without review, "review" holds
-- every story they submit. NULL means the site-wide rule applies (the first story is held, later ones are not).
ALTER TABLE {prefix}users ADD COLUMN publishing_override VARCHAR(10) NULL AFTER editor_mode;
