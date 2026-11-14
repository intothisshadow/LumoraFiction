-- An import now runs in small steps, so a long batch never meets a host's time limit and an interrupted run can be resumed. state
-- is running, done or cancelled (runs made before this change are done); job holds what is left to do and the choices the
-- author made; locked_until stops two requests from working on one run at once. To undo this by hand, drop the three columns.
ALTER TABLE {prefix}import_runs
    ADD COLUMN state VARCHAR(12) NOT NULL DEFAULT 'done' AFTER token,
    ADD COLUMN job MEDIUMTEXT NULL AFTER report,
    ADD COLUMN locked_until DATETIME NULL AFTER job;
