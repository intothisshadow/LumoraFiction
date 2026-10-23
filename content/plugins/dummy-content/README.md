# Dummy Content

Makes placeholder users, fandoms, tags, stories, chapters and bookmarks so you can see how themes, lists, search and feeds behave with a lot of content, and removes them again in one step. It is for development and testing copies of the site only.

## Turning it on

1. Open `config/config.php` of the **development** copy of your site and add the line `'environment' => 'development',` (or `'testing'`). The installer writes `'production'`, and a missing line counts as production.
2. Open **Maintenance > Plugins** and press **Turn on** for Dummy Content. On a site that is not flagged for development the plugin refuses to start and says why.
3. A new entry, **Dummy content**, appears under **Maintenance**.

Never set the environment to development on a site with real readers: the plugin would then let an administrator fill the archive with placeholder stories.

## Making content

Choose how much to make and press **Make dummy content**:

- **Users**: most become authors, the rest readers. They are real accounts that appear in lists and have author pages, but nobody knows their password, so none of them can sign in.
- **Fandoms** and **Tags**: new ones are made, and each fandom also gets a few characters and relationships of its own. A few tags and fandoms are used far more than the rest, as on a real archive.
- **Stories**: about 85 of every 100 are published; the others are drafts, waiting for approval, or unpublished. About a third are one chapter, the rest serials up to the length you set, and every work status appears. Ratings, genres, categories, warnings and relationship categories are taken from the ones your site already has (set them up under **Classification** first if you want stories to carry them).
- **Chapters**: made along with the stories. Some published stories have an unpublished last chapter, so you can check that readers do not see it.
- **Bookmarks**: made for the generated users on generated published stories.
- **Seed**: with the same seed and numbers you get the same titles, text and links. Leave it empty for something different every time.

Runs add up. A large run takes a while (about twenty seconds for sixty thousand stories on a laptop) and fills the database, so stay on the page until it reports.

## Removing it

The **Remove dummy content** box deletes everything the plugin made, its chapters, and every bookmark, reading place and follow that points at it, then recounts how many stories each remaining fandom and tag has. Content you or anyone else wrote is never touched. The plugin keeps its own list of what it made in a table of its own; turning the plugin off does not delete the table or the content.
