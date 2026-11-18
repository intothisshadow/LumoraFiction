# eFiction Importer

Brings the authors, stories and chapters of an archive that runs eFiction 3.5 across to Lumora Fiction, without retyping anything. It reads the eFiction database, shows what it found before anything is written, and writes everything through the same rules, sanitiser and visibility as work written here by hand. It can be run again to add what is new, and one button removes everything it brought across.

It supports eFiction 3.5.x (the release most sites still run, 3.5.6, and the newest, 3.5.84b). A site still on eFiction 2.x has to be upgraded to 3.5 first.

## Turning it on

Open **Plugins** and press **Turn on** for eFiction Importer. An **eFiction importer** entry appears under **Plugins** in the admin menu. Only an Administrator can open it.

## Before you start

Take a backup of this site (**Maintenance > Backups**), and consider switching on maintenance mode while a large archive is brought across. The eFiction database is only ever read, never changed, so your old site keeps working untouched.

## Connecting

1. **The eFiction folder (optional).** If eFiction's files are on the same server, **Discover folders** lists the folders next to this site that look like an eFiction install (they hold `viewstory.php` and `config.php`), and you can also type the folder yourself. **Read the database details from this folder** fills in the host, port, database name, user and prefix from its `config.php`. That file is read as plain text and never run, and only plain quoted settings are picked out. A password found there is kept for your session and never shown. Whatever you typed that the file does not name is left alone, and everything stays editable. If nothing can be read, say so and fill the details in by hand.
2. **The eFiction database.** Host, port, database name, user and password, and the table prefix: everything in front of `stories` in the name of eFiction's stories table, usually `fanfiction_`. The user only needs permission to read. If the prefix is wrong or empty, the prefixes in that database that do have the eFiction tables are offered, and the databases the account is allowed to list are suggested for the name (which stays free text). If eFiction and this site share one database under different prefixes, that is fine; pointing at this site's own tables is refused.
3. **The story files.** If eFiction keeps story text in files, name the folder they are in. It is the `stories` folder inside the eFiction folder unless you say otherwise.

The password is kept only in your session while you work. It is never written to a file, a setting, the activity log, a report or an address, and **Drop the held connection and password** removes it at any time. The connection is read-only: every request is a single read, and the session asks the database server to refuse writes as well.

## The preview and mapping

**Connect and preview** reads the database and writes nothing. It shows how many authors, stories and chapters it found; how many authors already have an account here (matched by email address); how many keep their old password; where the chapter text is and how many chapters have none; and which stories would be skipped and why. It then lets you choose:

- **Ratings.** Each eFiction rating becomes a rating here: an existing one with the same name, a new one, or none.
- **Categories.** A category that stories use becomes a fandom, or is merged into a fandom you choose. A category that only groups other categories, or that nothing uses, is left out by default.
- **Characters.** Brought across as characters under their fandom, or left out. A comma in a name is replaced by a space, because a comma separates names here.
- **Class types.** Each of eFiction's class types (genres, warnings and any custom ones) becomes genres, archive warnings or tags here, or is left out.
- **Stories that eFiction had not validated.** They become drafts, or go to the approval queue when this site's own rule would hold that author's story for review anyway. A story that would publish without review is left as a draft rather than published.
- **Optional parts**, each off until you tick it: author biographies, story reviews by members as comments, reviews by guests as comments, favourite stories as bookmarks, news posts, and the pages you pick from eFiction's messages.

Nothing is added to a classification list without being shown in this step.

## What is imported

- **Authors become accounts.** The pen name is the public display name and a separate private login name is made up, never the pen name. The email address and date joined are kept. A name too short to use becomes "Member" and the eFiction number, and an author with no usable email address gets a placeholder address that reaches nobody. The email address counts as confirmed, because the author already used it on the old site. Authors who have stories become Authors here; the rest become Readers. eFiction's staff levels and accounts an administrator made are listed in the report, and **no role is given automatically**.
- **Passwords.** eFiction 3.5.6 and earlier keep a plain MD5 hash, and 3.5.7 onwards (including 3.5.84b) rewrite a member's hash as a modern `password_hash()` value at that member's next sign-in, so one archive holds both kinds. A modern hash is imported as it is, and that member signs in with the old password. An MD5 hash is never stored, because this site only keeps modern hashes: those people use **Forgot your password?** to set a new one. The preview and the report count both groups.
- **Stories.** Title, summary, the story notes (placed in the story's end notes, the nearest place), created and updated dates, and completed or not. A validated story is published by the administrator running the import; the others are drafts or go to the approval queue. A story keeps its rating, fandoms, characters and the genres, warnings and tags you mapped.
- **Chapters.** Title, order, notes and end notes, with the text run through the same sanitiser as a chapter written in the HTML editor. eFiction text is HTML, plain text or a mixture, and plain text is turned into paragraphs. A chapter that has no text is reported and skipped, and the rest of the story is still imported. eFiction does not date chapters, so each takes its story's dates. Word counts are worked out again.
- **Where chapter text is found.** eFiction's own storage setting decides what is tried first. When that has nothing for a chapter (a missing or empty file, or empty text in the database) the other is tried, because a site that switched part-way holds a mixture. The path of a file is built only from the author and chapter numbers and must lie inside the stories folder. A chapter with no author number gets no guessed path. Text that is not valid UTF-8 is read in the character set the site's language file declares (Windows-1252 if it says nothing), and text that was saved in the wrong character set twice is turned back; the preview and report count how many chapters needed this.
- **Reviews** by signed-in members become comments under the chapter they were written for (a review of the whole story goes under the first chapter), with their original dates. Star ratings are not imported.
- **Guest reviews** have their own tick, off by default, with the number found shown beside it. A guest review keeps the reviewer's name, the date and the text, and nothing else: eFiction keeps no email address or other detail for a guest, and none is stored. It becomes a comment owned by your own account (the Administrator who runs the import) but is shown under the reviewer's name followed by the label "Administrator" in brackets, with no profile link, so it never looks as if you wrote it. Nobody is notified of an imported guest review, and a reply to one does not notify your account. A story or chapter that has comments switched off is skipped and listed in the report, which also counts the guest reviews imported and skipped. **Remove everything this plugin imported** removes them like every other imported comment, and running the import again adds only the ones not yet brought across.
- **Favourites** of stories become bookmarks.
- **News** becomes news posts, published with their original dates, written by the imported member with the pen name when that member may write news here, and otherwise by the administrator running the import.
- **Pages.** The messages you tick (custom pages, and the welcome, copyright and policy texts) become pages. They arrive as drafts to read through, because eFiction's own texts often hold its template placeholders, and none takes an address the site already uses.

## What is not imported

Co-authors (a story belongs to its main author, and the others are listed in the report), series, news comments, challenges, collections, themes and skins, blocks, panels and modules, eFiction's own settings, custom author profile fields, real names and websites (this site has no place for them), images and uploads other than a story's own text, and story featuring. The preview counts the series and co-authored stories so you know what to recreate by hand, for example under **Stories > Series**.

## Running it

**Start the import** works in small steps on a page that carries on by itself, so even a very large archive does not run into the server's time or memory limit. Keep the page open until it finishes. If it is closed, or the server stops, the importer's screen offers to carry the run on, and you enter the eFiction password again if you have signed out. **Stop here** stops a run, and what was already brought across stays. A story cut off half-way is removed and written again whole.

## Running it again

Every imported item is remembered against its eFiction number, so running the import again adds only what is new (a story added to eFiction since, say) and counts what was brought across before. Remember that one install of this plugin holds one eFiction site's numbers: remove an import before bringing a different eFiction site.

## The report

The report stays on the screen after a run and can be downloaded as text or CSV. It counts what was made, matched with something already here, brought across before, skipped and failed, for each kind, and lists everything left out with its eFiction number and the reason (the first 1,500, with a count of the rest). It never holds a password, an email address or the connection details.

## Undoing it

**Remove everything this plugin imported**, on the importer's screen, shows the numbers and asks you to tick a box. It deletes the stories (with their chapters), comments, bookmarks, news posts, pages, classification terms and accounts that imports made, and nothing a person made by hand. A term or account that only matched one already here is left alone, an account that has since written a story or comment is kept, and a term still used by a story made by hand is kept.

## Old addresses

If Lumora Fiction now answers at the address eFiction used (the files of eFiction replaced by this site), **Send the old eFiction addresses to the imported stories, authors and pages** makes `viewstory.php?sid=…` (and `&chapter=…`, a chapter's position in the story), `viewuser.php?uid=…` and `viewpage.php?page=…` lead to the new addresses with a permanent redirect. It only answers for something that was imported and, for a story, that readers can see. It is off until you switch it on.

## What the importer reads

It only reads these eFiction tables (with your prefix in front of the name), and only the columns listed.

- `authors`: `uid`, `penname`, `email`, `bio`, `date`, `password`, `admincreated`.
- `authorprefs`: `uid`, `level`.
- `stories`: `sid`, `title`, `summary`, `storynotes`, `catid`, `classes`, `charid`, `rid`, `date`, `updated`, `uid`, `coauthors`, `featured`, `validated`, `completed`.
- `chapters`: `chapid`, `title`, `inorder`, `notes`, `storytext`, `endnotes`, `validated`, `sid`, `uid`.
- `categories`: `catid`, `parentcatid`, `category`, `displayorder`.
- `characters`: `charid`, `catid`, `charname`.
- `classtypes`: `classtype_id`, `classtype_name`, `classtype_title`.
- `classes`: `class_id`, `class_type`, `class_name`.
- `ratings`: `rid`, `rating`.
- `reviews`: `reviewid`, `item`, `chapid`, `reviewer`, `uid`, `review`, `date`, `type`.
- `favorites`: `uid`, `item`, `type`.
- `news`: `nid`, `author`, `title`, `story`, `time`.
- `messages`: `message_id`, `message_name`, `message_title`, `message_text`.
- `series` and `coauthors`: counted only.
- `settings`: `store`, `storiespath`, `language`, `version`.

## Where it keeps its records

The plugin makes two tables of its own the first time it is used (the list of what was imported, and the runs with their reports). They hold eFiction numbers and nothing else of the old site. Turning the plugin off removes nothing and leaves them in place.

Every step (connecting, previewing, starting, finishing, stopping, removing, the redirect switch) is written to the activity log, without the password or the connection details.
