-- When an account owner was last emailed that their account was the target of a sign-in lockout, so an attacker who keeps
-- triggering lockouts cannot fill the owner's inbox. NULL means they never were.
ALTER TABLE {prefix}users ADD COLUMN lockout_notified_at DATETIME NULL AFTER publishing_override;
