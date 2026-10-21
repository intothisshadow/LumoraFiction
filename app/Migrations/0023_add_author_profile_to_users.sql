-- An author's optional public profile. about holds the text an author wrote for their author page and about_format
-- says how it was written (html, markdown or plain), so it can be shown again through the same content pipeline as a
-- chapter. avatar_file is the generated name of the stored 100 by 100 picture under content/uploads/avatars, empty
-- for none. NULL or empty means nothing is shown on the author page.
ALTER TABLE {prefix}users
    ADD COLUMN about MEDIUMTEXT NULL,
    ADD COLUMN about_format VARCHAR(20) NOT NULL DEFAULT 'html',
    ADD COLUMN avatar_file VARCHAR(64) NOT NULL DEFAULT '';
