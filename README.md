# Lumora Fiction

A lightweight, self-hosted story archive and publishing site for fanfiction, original fiction, and serialised stories. Write, share, and get lost in a story.

Lumora Fiction is in early development and has no stable release yet. This page describes what is ready to try.

## Requirements

| Requirement | Needed | Notes |
| --- | --- | --- |
| PHP | 8.2, 8.3, 8.4, or 8.5 (8.4 recommended) | Tested on all four. |
| Database | MySQL 5.6.4+ or MariaDB 10.0.5+ (MySQL 8.4+ or MariaDB 10.11+ recommended) | These are the oldest versions with InnoDB `FULLTEXT` index support, which search relies on. Lumora Fiction is tested on MySQL 5.7, 8.0, and 8.4, and on MariaDB 10.4, 10.11, and 11.8, and should install and run on the versions in between. Only database versions that their vendor still maintains are actively supported, though. If you have a problem on a version that has reached end of life, the advice will be to move to a currently supported release. Check the MySQL and MariaDB websites for which versions those are. |
| PHP extensions | `pdo`, `pdo_mysql`, `session`, `json`, `mbstring`, `zip` | `zip` is needed for updates and content export. The installer asks for it up front so those features work later. |
| Web server | Apache with `mod_rewrite` and `.htaccess` files allowed | The shipped `.htaccess` files assume Apache. |
| Writable folders | `config/`, `storage/logs/`, `storage/cache/`, `storage/backups/`, `storage/sessions/`, `content/uploads/` | Must be writable by the web server user. |
| On the server | No SSH, Composer, or Node.js | Everything is installed and updated from the browser. |

The installer checks all of the above before doing anything else and shows a clear, specific message if something is missing.

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

## Signing in

Open `/admin/` on your site and sign in with the login name (or email address) and password you chose during installation. Administrators, Editors, and Authors can use the admin area; Readers cannot.

- **Dashboard.** Shows anything that needs attention, such as the installer folder still being on the server, and, for administrators, who has joined and what has changed recently.
- **Stories.** Where Authors write. **My stories** lists your own stories, searchable and filterable by status; Editors and Administrators also get **All stories**. Tick several stories to publish, unpublish or delete them together; stories that are not yours, or that still lack the classification the administrator requires, are skipped and counted. **Add a story** opens the story form: title, summary, notes, disclaimer, language, where the story stands, and the classification lists the archive setting allows (a list marked "needed before publishing" must be filled in first). Open a story to publish it, send it for approval, unpublish it, preview it, or manage its chapters. A story waits as a draft, and an Author's first story may wait for approval, depending on Settings > Archive; after one of their stories has been published, later ones publish directly. If a reviewer sends a story back, it returns to a draft with the reason shown on the story form and a **Not approved** mark in the list, until you send it for approval again. A chapter has a title, optional notes, and the editor with a live word count and separate **Save draft** and **Publish** buttons; **Move up** and **Move down** change the chapter order. A chapter is only visible to readers once both it and its story are published. **Preview** shows a draft the way it will read, to you and to staff only. Leaving a form with unsaved changes asks first, and if your session ends while you write, the page hands your text back so you can sign in again in another tab and carry on without losing it. Deleting asks on a page of its own and cannot be undone.
- **Awaiting approval.** Editors and Administrators see the stories waiting for review here, the one waiting longest first, and the dashboard shows how many there are. **Preview** a story to read it the way it will appear, then **Approve** it to publish it, or **Send back** with a reason the author will read; a sent-back story becomes a draft again. Taking a published story down (**Unpublish** on its form) keeps the story, its chapters and its classification, and removes it from every public list at once. Every decision is recorded in the audit log with who made it.
- **Classification.** The lists writers choose from when they describe a story: fandoms, characters, relationships, genres, tags, categories (for original fiction), ratings, archive warnings, and relationship categories. Ratings and warnings are separate lists, and one is never worked out from the other. Writers can add new fandoms, characters, relationships, and tags while they write; these can be used at once and are marked **not yet reviewed** until you approve them. You can rename a term, add other names that lead to it (aliases), merge duplicates so every story moves to the term you keep, and delete terms nothing uses. Characters and relationships can belong to a fandom, so the same name in two fandoms stays two terms. Choose which lists a story must have before it is submitted; a list with nothing on it is never required. Lists that your archive setting hides (for example fandoms on an original-fiction site) are kept and come back if you switch the setting back.
- **Appearance.** **Themes** lists the themes installed on the site and lets an administrator choose the one the public side uses. To install a theme, copy its folder into the `content/themes/` folder of your site; it appears in the list as soon as its files are in place. If the theme you chose is removed or stops working, the site keeps working with a plain built-in theme and the screen tells you, and putting the theme back restores it. **Theme options** shows the settings the chosen theme offers, such as colours and reading width; the bundled **Storybook** theme is the default and offers the story typeface and the footer credit, while readers choose light or dark colours, text size and reading width themselves, remembered in their browser; anything left on "use the theme's own" keeps the look its designer chose. Previews of draft stories and chapters are drawn by the chosen theme, so a preview is exactly what readers will see.
- **Settings > Archive.** Choose whether the site publishes fanfiction, original fiction, or both; whether anyone can register; what role new accounts receive; whether new accounts must confirm their email address; whether a new Author's first story waits for approval; and whether visitors must sign in to read. The recommended settings are the defaults, and the archive is open to read unless you change that.

## Reading the archive

The home page lists the most recently published and the most recently updated stories. Each story has a page at `/story/its-title` with its summary, notes, details, tags and chapter list, and each chapter is read at `/story/its-title/1`, with the author's notes, previous and next links, and its place in the story. A finished story that has a single chapter shows its text on the story page itself. Every author who has published something has a page at `/author/their-name`, and every fandom, character, relationship, genre, tag, category, rating, warning and relationship category has its own listing, for example `/fandom/good-omens` or `/tag/slow-burn`. Lists can be sorted by recent update, publication date, title or length, and are split into pages of twenty stories.

Only stories that are published, and chapters that are published, are ever shown. A draft, a story waiting for approval, an unpublished story or a deleted one gives the same "page not found" as an address that never existed, so nobody can tell it is there; its author and the staff who can edit it are taken to the private preview instead. A listing for a tag or fandom with no public stories, and the page of an account that has published nothing, also answer "not found". If you turn on **Visitors must sign in to read** under Settings > Archive, anyone who is not signed in is sent to the sign-in page instead of seeing any story page.
- **Settings > Editor.** Choose the editor writers start with: Visual (a formatting toolbar), Markdown, HTML, or plain text. A chapter can be switched to another mode at any time without losing its formatting, and you can lock everyone to your choice. The editors are part of Lumora Fiction and need no internet connection.
- **Maintenance > System Information.** Shows what your server offers and how the site is set up. **Download a snapshot** produces a file you can send when asking for help. It leaves out passwords, the secret key, the database name and table prefix, and the location of folders on the server.
- **Too many failed attempts.** After five wrong passwords from one address, sign-in is paused for fifteen minutes.
- **Light and dark.** The admin area follows your device's setting, and the button in the header switches it by hand.
- **Moving between the site and the admin.** While you are signed in as an Author, Editor or Administrator, the site's main menu shows an **Admin** link, and the admin header has a **View site** link back to the home page. Readers and visitors never see the Admin link.

## License

GPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
