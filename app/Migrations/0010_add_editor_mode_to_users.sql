-- A writer's own choice of editing mode. NULL means "follow the site default", so changing the default
-- reaches everyone who never chose.
ALTER TABLE {prefix}users ADD COLUMN editor_mode VARCHAR(20) NULL AFTER status;
