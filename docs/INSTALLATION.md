# Installing Lumora Fiction

This guide covers what your server needs and how to install Lumora Fiction from the browser. If something goes wrong, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

## Requirements

| Requirement | Needed | Notes |
| --- | --- | --- |
| PHP | 8.2, 8.3, 8.4, or 8.5 (8.5 recommended) | Tested on all four. |
| Database | MySQL 5.6.4+ or MariaDB 10.0.5+ (MySQL 8.4+ or MariaDB 10.11+ recommended) | These are the oldest versions Lumora Fiction supports. It is tested on MySQL 5.7, 8.0, and 8.4, and on MariaDB 10.4, 10.11, and 11.8, and should run on the versions in between. Only versions their vendor still maintains are actively supported, so on an end-of-life version the advice will be to move to a currently supported release. |
| PHP extensions | `pdo`, `pdo_mysql`, `session`, `json`, `mbstring`, `zip` | `zip` is needed for updates and content export. The installer asks for it up front so those features work later. Story covers and author pictures also need GD. |
| Web server | Apache with `mod_rewrite` and `.htaccess` files allowed | The shipped `.htaccess` files assume Apache. |
| Writable folders | `config/`, `storage/logs/`, `storage/cache/`, `storage/backups/`, `storage/sessions/`, `content/uploads/` | Must be writable by the web server user. |
| On the server | No SSH, Composer, or Node.js | Everything is installed and updated from the browser. |

The installer checks all of the above before doing anything else and shows a clear, specific message if something is missing.

## Installing

1. Create an empty database and a database user with full rights on it, using your host's control panel.
2. Upload the Lumora Fiction files to your web space, in the site's root folder or a subfolder.
3. Open the site in your browser. You are taken to the installer automatically; if not, go to `/install/` on your site.
4. Fill in the database details, the site name and address, and the first administrator account, then choose **Install Lumora Fiction**. You can also pick a starter list for ratings, warnings and relationship categories, or none; it is optional and can be done later under Settings.

Before it builds anything, the installer checks your server (a missing PHP extension, a folder the web server cannot write to) and tests that the database login can create and change tables. If something fails part-way, it removes what it created, so you can correct the problem and submit the form again.

When it finishes, the installer deletes its own folder. If your host does not allow that, it tells you to delete the `install` folder yourself; please do.

## Good to know

- **Table prefix.** The installer suggests a random prefix, such as `lumfic_a8f3d1_`, for the database tables. It keeps them apart from anything else in the same database and makes them harder to guess. Accept the suggestion unless you have a reason not to.
- **Login name and display name.** Your login name is used only to sign in. It is never public; you can see your own on your account page, and Administrators can see everyone's under **Users**. Your display name is the name readers see. They must be different.
- **Site address.** Links in emails, such as password resets, are built from the address you enter, so make sure it is the one people will really use.
- **Clean web addresses.** If the installer warns that they do not seem to work, ask your host to enable `mod_rewrite` and allow `.htaccess` files.

## Updating later

When a new release is out, an Administrator updates from the browser under **Maintenance > Updates**; nothing needs to be uploaded by FTP, and your configuration, uploads and own plugins and themes are left alone. The web server must be able to write to the application's folders for this to work; the update's review screen says so before it changes anything. See the [administration guide](ADMINISTRATION.md) and, if something goes wrong, [troubleshooting](TROUBLESHOOTING.md).

## Signing in for the first time

Open `/admin/` on your site and sign in with the login name (or email address) and password you chose during installation. Administrators, Editors, and Authors can use the admin area; Readers cannot. From there, see the [administration guide](ADMINISTRATION.md) for setting up the archive.
