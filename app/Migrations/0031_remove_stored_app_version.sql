-- The version of the application is read from version.php alone. The installer used to keep a copy in the settings that nothing
-- updated, so System Information showed the version a site began with after it had been updated. The copy is removed here.
DELETE FROM {prefix}settings WHERE setting_name = 'app.installed_version';
