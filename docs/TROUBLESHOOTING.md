# Troubleshooting

## Installation

### "System requirements" page

The installer lists exactly what is missing. A missing PHP extension is enabled in your host's control panel (look for "PHP extensions" or "PHP selector") or by asking your host. A folder that is "not writable" needs its permissions changed so the web server can write to it, usually to 755 or 775 through your FTP program or file manager.

### "Could not connect to the database with those details"

Check each value against what your host shows for the database: the host (often `localhost`, but some hosts use a separate name such as `mysql.example.com`), the port (usually 3306), the database name, the user, and the password. Many hosts add a prefix to database and user names, for example `account_fiction`. The password is not kept when the form is shown again, so enter it again.

### "The database user is not allowed to create / add rows to / alter tables"

The database user needs full rights on the database. In your host's control panel, assign the user to the database with all privileges.

### "This database already contains tables that start with that prefix"

Either an earlier attempt left tables behind, or something else in the database uses that prefix. Choose a different table prefix, or use an empty database. The installer never deletes tables it did not create.

### "The installation could not be completed. Nothing was changed."

Something unexpected went wrong and the installer undid its work. Details are in `storage/logs/error.log`. Fix the cause and submit the form again.

### "Clean web addresses do not seem to work"

Lumora Fiction needs the web server to send unknown addresses to `index.php`. On Apache that means `mod_rewrite` must be enabled and `.htaccess` files must be allowed (`AllowOverride All` for the site's folder). Ask your host if you cannot change this yourself.

### The installer's folder could not be removed

Delete the `install` folder from the server yourself, using FTP or your file manager. Until you do, anyone who visits `/install/` sees an "already installed" page and cannot change anything, but the folder should not stay on a live site.

## After installation

### A blank page or "Something went wrong"

The reason is written to `storage/logs/error.log`. Visitors never see it.

## Updates and backups

### The review says the web server cannot write to a folder

The update replaces the application's own folders (`app`, `admin`, `assets`, `docs`, and the plugin and theme folders the release ships) and the files in the site's root, so the web server user must be able to write to them. Change the permissions through your FTP program, file manager or host (usually 755 or 775 on folders and 644 or 664 on files, with the web server as owner or in the group), or ask your host. The review lists the first folders that fail. If you cannot give the web server that access, update by uploading the files yourself.

### "A step is still running"

Two steps cannot run at once. Wait a minute and press **Continue** again. If the page was closed in the middle, open **Maintenance > Updates** and choose **Resume or cancel it**.

### The progress page stopped, or shows "This step did not finish"

The message under the step says why. **Resume** tries the step again, which is safe: every step can be repeated. If it keeps failing, **Cancel** puts the earlier version back. The reason and the full record are also in the update log at the bottom of the Updates page and in `storage/logs/updates.log`; anything unexpected is in `storage/logs/error.log`.

### Visitors see "Down for maintenance" and no update is running

A stopped update can leave the marker that closes the site. **Maintenance > Updates** says so and has an **Open the site to visitors** button. By hand, delete `storage/updates/maintenance.flag`. If the closed site is the **Maintenance mode** setting instead, switch it off under **Maintenance > Maintenance mode**; administrators can always sign in at `/admin/login`.

### The host cannot reach GitHub

Checking and downloading need cURL or `allow_url_fopen`. Without either, download the release ZIP (`LumoraFiction-v….zip`) and its `.sha256` file on your computer and use **Upload a release package** with the checksum pasted in.

### The upload is "larger than this server accepts"

Raise `upload_max_filesize` and `post_max_size` in your host's PHP settings (a release is a few megabytes), or use the GitHub download.

### Recovering when the admin area itself cannot be reached

Every update makes a backup first. Unzip the newest `storage/backups/…-files.zip` over the site's folder (it holds the application, never `config/config.php` or your uploads), and import the matching `storage/backups/…-database.sql.gz` with your host's database tool (phpMyAdmin or similar; the file is plain SQL after decompressing, or `.sql` on a server without zlib). Then delete `storage/updates/state.json` and `storage/updates/maintenance.flag`. The backups are named by date and time in UTC.

### "The site is temporarily unavailable"

The database could not be reached. Check that the database server is running and that the values in `config/config.php` are still correct. The site recovers on its own once the database is back.
