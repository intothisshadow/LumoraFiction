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

### "The site is temporarily unavailable"

The database could not be reached. Check that the database server is running and that the values in `config/config.php` are still correct. The site recovers on its own once the database is back.
