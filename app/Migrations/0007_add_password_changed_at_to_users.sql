-- A session that began before the password last changed is treated as signed out, so changing or
-- resetting a password ends every other session for the account.
ALTER TABLE {prefix}users ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
