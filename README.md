# Lumora Fiction

A lightweight, self-hosted story archive and publishing site for fanfiction, original fiction, and serialised stories. Write, share, and get lost in a story.

- **Project site:** [coding.unloved-heart.net/scripts/lumorafiction](https://coding.unloved-heart.net/scripts/lumorafiction)
- **GitHub:** [intothisshadow/LumoraFiction](https://github.com/intothisshadow/LumoraFiction)

## Requirements

| Requirement | Needed |
| --- | --- |
| PHP | 8.3, 8.4, 8.5, or 8.6 (8.5 recommended) |
| Database | MySQL 5.6.4+ or MariaDB 10.0.5+ (MySQL 8.4+ or MariaDB 10.11+ recommended) |
| PHP extensions | `pdo`, `pdo_mysql`, `session`, `json`, `mbstring`, `zip` |
| Web server | Apache with `mod_rewrite` and `.htaccess` files allowed |
| On the server | No SSH, Composer, or Node.js |

Lumora Fiction is tested on PHP 8.3 to 8.6, MySQL 5.7, 8.0 and 8.4, and MariaDB 10.4, 10.11 and 11.8. Only database versions their vendor still maintains are actively supported. See [docs/INSTALLATION.md](docs/INSTALLATION.md) for the full list, including writable folders.

## Installing

1. Create an empty database and a database user with full rights on it, using your host's control panel.
2. Upload the Lumora Fiction files to your web space, in the site's root folder or a subfolder.
3. Open the site in your browser. You are taken to the installer automatically; if not, go to `/install/` on your site.
4. Fill in the database details, the site name and address, and the first administrator account, then choose **Install Lumora Fiction**.

The installer checks your server and database first, tells you plainly if something needs fixing, and removes what it created if it fails part-way, so you can correct the problem and try again. When it finishes it deletes its own folder; if your host does not allow that, it tells you to delete the `install` folder yourself, and you should. Details and tips are in [docs/INSTALLATION.md](docs/INSTALLATION.md), and fixes for common problems are in [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).

## Signing in

Open `/admin/` on your site and sign in with the login name (or email address) and password you chose during installation. Administrators, Editors, and Authors can use the admin area; Readers cannot. Your login name is private and used only to sign in; your display name is what readers see.

## Features

### Writing and publishing

Authors can create and edit stories, organise chapters, describe their work with classification lists, add a cover and an author profile, and preview drafts before publishing. Chapters are written in a Visual, Markdown, HTML or plain text editor with a live word count and separate **Save draft** and **Publish** buttons. Editors and Administrators review stories waiting for approval and can approve them or send them back with a reason.

Additional features include notes before and after each chapter and after a whole story, chapter version history, story copying, trash and recovery, notifications, and bulk actions. A bundled Visitor Stats plugin, off until you turn it on, counts story views on your own site without cookies or personal data. See the [user guide](docs/USER-GUIDE.md).

### Reading the archive

Readers can browse new, updated and completed stories, explore indexes of fandoms, genres, tags, ratings and warnings, and search by words with filters for classification, status, language and length. Each story has its own page and each chapter its own address, with previous and next links. Signed-in readers can bookmark stories and chapters, follow stories, pick up where they left off, and set their own colours, text size and reading width. Stories can be downloaded as EPUB, PDF or HTML, unless the administrator or the author turns that off. Signed-in readers can also comment on chapters and reply to one another one level deep, with the administrator's moderation and the writer's own switch to turn comments off. News posts keep readers informed about the site. Three themes are bundled, the default Storybook and the gradient themes Rosewine and Lavender Mist, each with light and dark colours that a reader sets with one button in the header. An administrator can preview any theme on the whole site before using it, and can let visitors choose their own theme. Only published work is ever shown. See the [user guide](docs/USER-GUIDE.md).

### Running the site

Administrators choose whether the archive hosts fanfiction, original fiction, or both, who may register, and how new writers are moderated. They manage classification lists, users, news, themes and plugins, and see problems with the site in one alerts screen. Starter lists for ratings, warnings and relationship categories are optional. Administrators can also write static pages such as About, FAQ, Terms of Service and a Privacy Policy and link them from the menu or the footer, limit tags to ones they add themselves, moderate reader comments on chapters, and answer a data request by exporting or deleting an account. See the [administration guide](docs/ADMINISTRATION.md).

### Security and maintenance

Sign-in is throttled after repeated failures, password resets use single-use expiring links, registration forms are protected against automated sign-ups, and security events are logged without recording what visitors typed. HTTPS-only mode and trusted-proxy settings are available. See the [security guide](docs/SECURITY.md).

If the site is moved to a new domain, to HTTPS or to another folder, the admin notices that the address or folder no longer matches what was saved and shows an alert. The alert leads to a screen that suggests the new address, which you can correct and confirm, or keep the old one for a site that is meant to answer under two names. The saved address is never changed without that confirmation.

Updating needs no FTP or SSH. Under **Maintenance > Updates** an Administrator sees at a glance what is installed and whether a newer release exists, reads its release notes, checks GitHub for it (by hand, or automatically once a day or week if they turn that on) or uploads the release ZIP, reviews what the update will do, and confirms. The package is checked against its published SHA-256 checksum and against the server, the files and the database are backed up first, visitors see a maintenance notice while it runs, and if anything goes wrong the site is put back as it was. An update that is interrupted can be resumed or cancelled. **Maintenance > Backups** makes, downloads, restores and deletes backups on demand, and **Maintenance > Maintenance mode** takes the public site offline for visitors, now or on a schedule, while Administrators keep working; a banner on every admin screen says when visitors are being turned away, and why. Your configuration, uploads, and any plugin or theme the release does not ship are never touched by an update.

## More documentation

- [docs/INSTALLATION.md](docs/INSTALLATION.md): requirements, installation, and first sign-in.
- [docs/USER-GUIDE.md](docs/USER-GUIDE.md): accounts, writing, publishing, reading, and browsing.
- [docs/ADMINISTRATION.md](docs/ADMINISTRATION.md): classification, users, settings, themes, plugins, and maintenance.
- [docs/SECURITY.md](docs/SECURITY.md): sign-in protection, spam protection, and deployment considerations.
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md): fixes for common problems.
- [docs/THEME-DEVELOPMENT.md](docs/THEME-DEVELOPMENT.md) and [docs/DEVELOPER-APIS.md](docs/DEVELOPER-APIS.md): for theme and plugin authors.

## License

GPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
