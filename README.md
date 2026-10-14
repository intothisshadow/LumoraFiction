# Lumora Fiction

A lightweight, self-hosted story archive and publishing site for fanfiction, original fiction, and serialised stories. Write, share, and get lost in a story.

Lumora Fiction is in early development and has no stable release yet. This page describes what is ready to try.

## Requirements

- PHP 8.4 recommended; PHP 8.2, 8.3, and 8.5 are also supported
- MySQL 5.6.4 or newer, or MariaDB 10.0.5 or newer
- PHP extensions: `pdo_mysql`, `session`, `json`, `mbstring`, `zip`
- Apache with `mod_rewrite` and `.htaccess` files allowed (or an equivalent rewrite configuration)
- No SSH, Composer, or Node.js required on the server

## Installing

1. Create an empty database and a database user with full rights on it, using your host's control panel.
2. Upload the Lumora Fiction files to your web space, in the site's root folder or a subfolder.
3. Open the site in your browser. You are taken to the installer automatically; if not, go to `/install/` on your site.
4. Fill in the database details, the site name and address, and the first administrator account, then choose **Install Lumora Fiction**.

The installer checks your server first and tells you plainly if something needs fixing, such as a missing PHP extension or a folder the web server cannot write to. It also tests that the database login can create and change tables before it builds anything. If something fails part-way, it removes what it created, so you can correct the problem and submit the form again.

When it finishes, the installer deletes its own folder. If your host does not allow that, it tells you to delete the `install` folder yourself; please do.

### Good to know

- **Table prefix.** The installer suggests a random prefix, such as `lumfic_a8f3d1_`, for the database tables. It keeps them apart from anything else in the same database and makes them harder to guess. Accept the suggestion unless you have a reason not to.
- **Login name and display name.** Your login name is used only to sign in and is never shown to anyone. Your display name is the name readers see. They must be different.
- **Site address.** Links in emails, such as password resets, are built from the address you enter, so make sure it is the one people will really use.
- **Clean web addresses.** If the installer warns that they do not seem to work, ask your host to enable `mod_rewrite` and allow `.htaccess` files.

## License

GPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
