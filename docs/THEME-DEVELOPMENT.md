# Theme Development

Reference for theme authors. It describes only what exists today and is updated whenever a template tag, a template rule, or a theme option changes.

A theme is a folder of ordinary PHP templates and a stylesheet. There are no blocks, no `theme.json`, and no build step. A theme only draws pages: it never talks to the database, the request, or the configuration, and it never contains logic the application depends on.

## A theme's files

Put the theme in its own folder under `content/themes/`. The folder name is the theme's identifier: lower-case letters, digits and hyphens, starting with a letter.

| File | Required | Purpose |
| ---- | -------- | ------- |
| `style.css` | Yes | The theme's stylesheet. Its first comment holds the theme's details (see below). |
| `index.php` | Yes | The template every view falls back to. |
| `header.php`, `footer.php`, `sidebar.php` | No | Pieces included with `lf_get_header()`, `lf_get_footer()` and `lf_get_sidebar()`. A piece the theme does not have is taken from the built-in theme. |
| `home.php`, `story.php`, `chapter.php`, `author.php`, `archive.php`, `search.php`, `page.php`, `404.php` | No | Templates for one view each (see the hierarchy below). |
| `single.php` | No | Shared by `story` and `chapter` when the theme has no template of its own for them. |
| `parts/*.php` | No | Pieces included with `lf_part()`. |
| `functions.php` | No | Runs once per request before the first template. Use it only to register hooks and declare theme options. |

The theme's PHP files are included by the application. The web server refuses to serve them directly; only the stylesheet, scripts, images and fonts in the folder are sent to browsers.

### The stylesheet header

The first comment in `style.css` is read, never executed:

```css
/*
Theme Name: Storybook
Version: 1.0.0
Author: Ariane
Description: Warm, readable, generous spacing.
*/
```

`Version` is required and must look like a version (`1`, `1.2`, `1.2.3`, `2.0-beta.1`). A folder without a valid header, or without `index.php`, is not offered as a theme. `Theme Name` is optional: when it is missing or empty, the theme is shown under its folder name. `Author` and `Description` are optional and are shown on the Appearance screen, escaped.

Two more optional lines state what the theme needs, as plain version numbers (`8.2`, `0.3.0`): `Requires PHP:` and `Requires at least:` (the Lumora Fiction version). A theme that needs more than the site has is shown with the reason, cannot be switched on, and is refused when uploaded.

### The preview picture

A theme may carry a picture that the Appearance screen shows on its card and in its details: `screenshot.webp`, `screenshot.jpg` or `screenshot.png` in the theme folder (the first one found, in that order). The file must really be that kind of image, at most 1 MB, and between 100 and 4,000 pixels on each side; anything else is ignored and the card shows a plain placeholder. About 800 by 600 works well. The picture is sent to administrators only, from a fixed address, with a type worked out from its contents.

### Packaging a theme as a ZIP

An administrator can add a theme from a ZIP, and update an installed one from a newer ZIP, on the Appearance screen (a host can switch this off with `theme_uploads` in `config/config.php`). Pack the theme **folder**, so the ZIP holds exactly one top-level folder whose name is the theme's identifier:

```
storybook-2.zip
└── storybook/
    ├── style.css
    ├── index.php
    └── …
```

The ZIP is refused, with a reason and nothing written, when it holds files outside that folder or a second folder, has no `index.php` or no valid `style.css` header, has a folder name that is not a valid identifier, contains a path that is absolute or uses `..`, a symbolic link, or a server settings file (`.htaccess`, `.user.ini`, `php.ini`, `web.config`), or is too big (20 MB zipped, 30 MB unpacked, 10 MB for one file, 1,500 entries). Leftovers from file managers (`__MACOSX/`, `.DS_Store`, `Thumbs.db`) are skipped.

An update replaces the theme's files and nothing else. The options saved for the theme and whether it is the theme in use are kept, because they are stored by the theme's folder name, not in the folder. The update needs a higher `Version` than the installed one unless the administrator ticks the box to allow the same or an older one, and the old files are kept until the new ones are in place. Deleting a theme removes its folder and its saved options.

## The template hierarchy

Each view tries its templates in this order and uses the first one the theme has:

| View | Tried in order |
| ---- | -------------- |
| `home` | `home.php`, `index.php` |
| `story` | `story.php`, `single.php`, `index.php` |
| `chapter` | `chapter.php`, `single.php`, `index.php` |
| `author` | `author.php`, `archive.php`, `index.php` |
| `archive` | `archive.php`, `index.php` |
| `search` | `search.php`, `archive.php`, `index.php` |
| `browse` | `browse.php`, `archive.php`, `index.php` |
| `page` | `page.php`, `index.php` |
| `account` | `account.php`, `page.php`, `index.php` |
| `bookmarks` | `bookmarks.php`, `archive.php`, `index.php` |
| `continue` | `continue.php`, `bookmarks.php`, `archive.php`, `index.php` |
| `updates` | `updates.php`, `bookmarks.php`, `archive.php`, `index.php` |
| `news-archive` | `news-archive.php`, `index.php` |
| `news-single` | `news-single.php`, `index.php` |
| `404` | `404.php`, `index.php` |
| `maintenance` | `maintenance.php`, `404.php`, `index.php` |

A view can name a variant, and then `{view}-{variant}.php` is tried first: the archive of a fandom tries `archive-fandom.php` before `archive.php`.

If the chosen theme is missing, cannot be read, or fails while drawing a page (an exception, a syntax error, a call to a function that does not exist), the problem is written to the error log and the page is drawn again by the built-in theme. Whatever the broken template had already printed is thrown away. A visitor never sees a half-drawn page.

## A page, start to finish

```php
<?php lf_get_header(); ?>

<article class="lf-story">
    <h1 class="lf-story__title"><?php lf_field('story.title'); ?></h1>
    <p class="lf-story__byline">by <?php lf_field('story.author'); ?></p>

    <?php if (lf_has('story.summary')): ?>
        <p><?php lf_field('story.summary'); ?></p>
    <?php endif; ?>

    <ol>
        <?php foreach (lf_items('chapters') as $chapter): ?>
            <li><a href="<?= lf_e($chapter['url']) ?>"><?= lf_e($chapter['title']) ?></a></li>
        <?php endforeach; ?>
    </ol>
</article>

<?php lf_get_footer(); ?>
```

A header usually looks like this:

```php
<!DOCTYPE html>
<html lang="en">
<head>
<?php lf_head(); ?>
</head>
<body class="<?php lf_body_class(); ?>">
<?php lf_body_open(); ?>
<header><a href="<?php lf_site_url(); ?>"><?php lf_site_name(); ?></a> <?php lf_navigation(); ?></header>
<main id="lf-main">
```

and a footer ends with `</main>`, then `<?php lf_footer(); ?>`, then `</body></html>`.

## Template tags

Tags that print, print escaped text. A tag that returns a value returns it already escaped. Called when no page is being drawn, every tag does nothing.

### Page structure

| Tag | Purpose |
| --- | ------- |
| `lf_head()` | Everything inside `<head>`: character set, viewport, the title, a noindex notice when needed, the theme's own stylesheet, stylesheets that features add, the theme options stylesheet, and the `lf_head` action. A theme does not link its own stylesheet. |
| `lf_body_open()` | Right after `<body>`: the preview notice on a preview, and the `lf_body_open` action. |
| `lf_footer()` | Just before `</body>`: scripts that features add and the `lf_footer` action. |
| `lf_body_class()` | The classes for `<body>`: `lf-view-{view}`, plus `lf-is-preview` on a preview. |
| `lf_get_header()`, `lf_get_footer()`, `lf_get_sidebar()` | Include `header.php`, `footer.php` or `sidebar.php`, from the theme or else from the built-in theme. |
| `lf_part(string $name, array $data = [])` | Include `parts/{name}.php`. The keys of `$data` become the variables of the part, so pass one array and let the part unpack it. A name may only hold lower-case letters, digits and hyphens. |
| `lf_navigation(string $label = 'Main')` | The main menu as a list of links in a `nav` element. |
| `lf_pagination()` | Previous and next links with a window of page numbers. Prints nothing when there is one page. |
| `lf_is(string $view): bool` | Whether the page is the given view. |

### Site facts and addresses

| Tag | Purpose |
| --- | ------- |
| `lf_site_name()` | The site's name. |
| `lf_title()` | The title of this page, without the site name. |
| `lf_site_url(string $path = '')` | An address on this site, with the install's base directory. Anything that could name another site is dropped. |
| `lf_news_url()` | The address of the News page. It answers only while news is switched on in the settings, so print a link to it only inside `lf_has('news.posts')` or when the page's data gave you a news link. |
| `lf_theme_url(string $path = '')` | The address of a file in the theme's folder, such as `lf_theme_url('img/logo.svg')`. |
| `lf_asset_url(string $path)` | The address of a file in the shared `assets/` folder, with a version for cache busting. |

### The page's data

The application hands each page its data as one array of plain values. Read it by dotted name:

| Tag | Purpose |
| --- | ------- |
| `lf_field(string $path)` | Print a value, escaped. Numbers are printed as they are. A value the application marked as sanitised markup, such as a chapter's text, is printed as markup. |
| `lf_value(string $path): string` | The same, returned, for building an attribute. |
| `lf_has(string $path): bool` | Whether there is a non-empty value. |
| `lf_items(string $path): array` | The entries of a list, to loop over. The values inside are **not** escaped: print them with `lf_e()`. |
| `lf_term_links(string $path, string $separator = ', ')` | A list of `{name, url}` entries as links. An entry with no address is printed as plain text. |
| `lf_e(mixed $value): string` | Escape a value for HTML text or a quoted attribute. |

Never print a value from `lf_items()` without `lf_e()`.

### Theme options

| Tag | Purpose |
| --- | ------- |
| `lf_option(string $key)` | Print the value of one of the theme's options. |
| `lf_option_enabled(string $key): bool` | Whether a checkbox option is on. |

## The data each view receives

### Story (`story`)

| Name | Contents |
| ---- | -------- |
| `story.title`, `story.author`, `story.summary`, `story.notes`, `story.disclaimer` | Text. The author is the display name. |
| `story.author_url` | The address of the author's page. Empty on a preview, so check it with `lf_has('story.author_url')` and print the name as plain text when it is empty. |
| `story.status` | Where the story stands, such as "Ongoing". |
| `story.words` | The word count, formatted. |
| `story.language` | The language code, upper-case. |
| `story.published`, `story.updated` | Dates such as "2 January 2026". `published` is empty until the story is first published; check it with `lf_has('story.published')`. |
| `story.url` | The address of the story. |
| `story.cover.thumb`, `story.cover.full`, `story.cover.alt` | The story's cover: a small picture for lists (200 pixels wide at most), a larger one for the story page (600 pixels wide at most), and the text alternative the author wrote. All three are empty strings for a story without a cover, so check with `lf_has('story.cover.full')`. Print the description in the `alt` attribute of the story page's picture. |
| `term_groups` | A list of `{label, type, terms}`. `terms` is a list of `{name, url}`. Use `lf_term_links('term_groups.0.terms')`, with the index of the group. Only the kinds of term the archive mode allows are listed. |
| `chapters` | A list of `{number, title, url, edit_url, words, note}`. `note` is empty except on a preview, where it names a chapter that is not yet published. `edit_url` is the admin address of the chapter and is empty unless the viewer may change the story, so print an **Edit** link only when it is not empty (with the chapter title in the link's accessible name), and the address never reaches anyone else's markup. |
| `chapter` | Only on a finished story that has a single readable chapter, where the story page is also the place to read it. It holds the same fields as on a chapter page (`chapter.html`, `chapter.notes`, and so on). When `lf_has('chapter.html')` is true, print the text under the details and leave out the chapter list and the start-reading link. |
| `bookmarks`, `follow`, `notices`, `continue` | The reader features below. `continue` is `{title, url}` for a signed-in reader who has started the story, and nothing otherwise; check it with `lf_has('continue.url')`. |

### Chapter (`chapter`)

| Name | Contents |
| ---- | -------- |
| `story.title`, `story.url` and the other story fields above | The story the chapter belongs to. |
| `chapter.number`, `chapter.title`, `chapter.words`, `chapter.notes` | Text. A chapter without a title gets "Chapter N". |
| `chapter.position`, `chapter.total` | Where the chapter stands among the chapters a reader can open, counting from one, and how many there are. Both are empty on a preview, so check `lf_has('chapter.total')`. A chapter that is hidden leaves no gap, so `position` can differ from `number`. |
| `chapter.html` | The chapter's text, already sanitised. Print it with `lf_field('chapter.html')`. |
| `previous`, `next` | `{title, url}`, or nothing at the ends of the story. Check with `lf_has('previous.url')`. |
| `bookmarks`, `notices`, `resume` | The reader features below. |

### Other views

| View | Data |
| ---- | ---- |
| `404` | `message`. |
| `maintenance` | `message`, the administrator's text for visitors (plain text, escaped by `lf_field()`). The page is drawn with status 503 and is not indexed; the theme prints only the notice, with no link into the site, since every public page is closed to the visitor. A theme without `maintenance.php` shows its `404.php`, which prints the same title and message. During an update the site shows a plain page instead of any theme, because the database may be changing underneath it. |
| `home` | `stories` (the most recently published), `updated_stories` (the most recently changed), each a list of story cards, and `news`, the latest announcements (see "News" below). Either story list is empty on a new site, and `news` is empty when there is no news, when the home page block is set to 0, or when news is switched off, so test it with `lf_has('news.posts')`. The list of places a signed-in reader left off is no longer part of the home page: it lives on the **Continue reading** page (`continue`), and a theme that still prints `continue_reading` simply finds the key missing. |
| `author` | The list data below. The page title is "Stories by" and the author's display name. It also receives `author_name`, `author_avatar` (the address of the author's 100 by 100 picture, or empty) and `author_about` (the author's own text as sanitised markup, headings already moved below the page's own, or empty). Print both only when they are not empty (`lf_has()`), in a section above the page title; the picture is decorative, so give it `alt=""` and its size of 100 by 100. A theme that does not print them simply omits the section. |
| `archive` | The list data below, for one term. The variant is the kind of term, so the fandom page tries `archive-fandom.php` first; a kind written with an underscore uses a hyphen (`archive-relationship-category`). The page title is the kind and the term, such as "Fandom: Good Omens". |

A story card is `{title, url, author, author_url, summary, status, words, updated, cover}`, where `cover` is `{thumb, full, alt}` as for a story. Print the values with `lf_e()`, and leave the author as plain text when `author_url` is empty. When `cover.thumb` is empty, draw a placeholder so a list keeps its rhythm. A cover beside a title link repeats it, so a list may hide the picture from assistive technology with `alt=""` and let the story page carry the description.

A view with a list of stories receives:

| Name | Contents |
| ---- | -------- |
| `stories` | A list of story cards for this page. |
| `total` | How many public stories the list holds, formatted. |
| `sorts` | The ways to order the list, each `{label, url, current}`. Show them as links and mark the current one in a way that does not rely on colour alone. |
| `pagination` | `{page, pages, url}`, where `url` is an address with a `{page}` marker. `lf_pagination()` reads it. |

### News (`news-archive`, `news-single` and the home block)

News posts are announcements from the staff, kept apart from stories. A theme draws them with two templates and a small block on the home page. Unlike most views, a theme with only `index.php` draws a news page as just a title, so a theme should supply `news-archive.php` and `news-single.php`. A post that is not published, or any post while news is switched off, is never in the data; there is nothing for a theme to check.

A news card is `{title, url, date, datetime, author, excerpt, pinned}`. `date` reads "23 October 2026" and `datetime` is the same day as `2026-10-23` for a `<time>` element. `author` is the display name only. `excerpt` is plain text of about two hundred characters, so print it with `lf_e()`. `pinned` is the word "Pinned" for a post kept at the top and empty otherwise; mark it in a way that does not rely on colour alone.

| View | Data |
| ---- | ---- |
| `home` | `news` is `{posts, url}`: a list of news cards and the address of the News page, or an empty array. Draw a block only when `lf_has('news.posts')` and link to `lf_field('news.url')`. |
| `news-archive` | `posts`, a list of news cards (pinned first, then newest), and `pagination` as for the story lists. The page title is "News". |
| `news-single` | `post` is `{title, url, date, datetime, author, pinned, body, list_url}`. `body` is sanitised markup: print it with `lf_field('post.body')`. A staff preview of a draft uses the same data, with the usual preview notice. |

The main menu gets a **News** link from core while news is on, so a theme does not add one.

Both bundled themes draw the home page in two columns: the stories on the left and the news in a narrower column on the right, inside `lf-home__columns` (`lf-home__main` and `lf-home__aside`). The stories come first in the source so a narrow screen, which stacks the columns, reads them before the news. When `news.posts` is empty the page has no aside and carries `lf-home--single`, so the stories keep a comfortable single-column width. A theme is free to lay the page out differently; nothing in the data depends on it.

### Browse (`browse`)

The **Browse** page in the main menu and the indexes under it use the `browse` view, which tries `browse.php`, then `archive.php`, then `index.php`. A theme with no `browse.php` falls back to `archive.php`, which does not draw these lists, so a theme should supply one. One template draws two kinds of page, told apart by which keys are present: the hub has `lists` and `kinds`, and an index of one kind of term has `terms`. The plain lists **New stories**, **Recently updated stories** and **Completed stories** are not this view: they use `archive` with the story-list data above, except that they have no `sorts` because each has its own order.

| Name | Contents |
| ---- | -------- |
| `title`, `description` | The heading and a sentence under it. |
| `lists` | (Hub) The story lists, each `{label, url, description}`. |
| `kinds` | (Hub) The indexes that have something to show, each `{label, url, count, count_label}`. A kind the archive mode switches off, or with nothing listed, is absent. |
| `total` | (Index) How many terms the index lists, already formatted. |
| `terms` | (Index) One page of terms, each `{name, url, count, count_label}`; `count_label` reads "3 stories". Only reviewed terms with at least one public story are listed. Empty when there is nothing to show. |
| `letters` | (Index) The first-letter links, each `{label, url, current}`, beginning with **All** and with `#` for digits and symbols. Empty when there is only one letter. Draw them as a list of links and mark the current one with `aria-current`. |
| `sorts` | (Index) **A to Z** and **Most used**, each `{label, url, current}`. |
| `pagination` | As for the story lists. |

A fandom's page (`archive-fandom.php`, or `archive.php`) also receives `related`: groups of the fandom's most used characters and relationships, each `{label, terms}` where a term is `{name, url, count_label}`. It is empty for every other kind of term, so a theme can loop over it unconditionally.

### Account pages (`account`)

Sign-in, registration, email confirmation, password reset and the profile are all the `account` view, told apart by the variant: `sign-in`, `register`, `resend`, `forgot`, `reset`, `profile` and `message` (a page that only reports a result). A theme can draw every one of them with a single `account.php`, or give one page its own look with `account-{variant}.php`. A theme that has no `account.php`, or no `account-form` part, uses the built-in theme's, so the pages always work.

| Name | Contents |
| ---- | -------- |
| `intro` | A sentence of introduction, or empty. |
| `notices` | Messages to show, each `{type, text}` where the type is `success`, `info`, `warning` or `error`. Give errors `role="alert"` and the rest `role="status"`. |
| `forms` | The forms on the page, each a bundled description. Hand each one to `lf_part('account-form', ['form' => $form])` and let the part draw it. |
| `links` | Links to other account pages, each `{label, url}`, drawn below the forms. |
| `shortcuts` | Links a signed-in member uses often, such as **My bookmarks** on the profile page, plus **Post New Story**, **My stories** and (for those who review stories) **Awaiting approval** for writers, each `{label, url}`. Draw them above the forms, under a small **Quick links** heading, and keep them quieter than the page's buttons. Empty on every other page. |

A form is `{id, action, csrf_token, submit, heading, fields, errors}`. The part prints the hidden `csrf_token` field itself, which every form must carry, and then fires `do_action('lf_form_fields', $form['id'])` once, inside the `<form>` and before the first field. Core uses that action to print the hidden spam-trap and timing fields on the registration, password-reset and confirmation-email forms; a theme that leaves the call out cannot register anyone, because those forms are then refused as sent without their timing check. Give the trap's wrapper, `lf-form__trap`, a rule that takes it off the screen without `display: none` (some programs skip fields that are not displayed): position it far off to the left, one pixel square, as Storybook's stylesheet does. A theme without the rule still works, but visitors see two odd extra fields. A field is `{name, id, label, type, value, autocomplete, hint, error, options}`; the type is `text`, `email`, `password` or `select`, and a select's `options` are `{value, label, selected}`. Print every value with `lf_e()`. Link each hint and error to its field with `aria-describedby`, and mark a field that has an error with `aria-invalid`. Passwords are never sent back to the page, so `value` is always empty for them. The form's `errors` are messages about the form as a whole, each `{message}`.

Do not change the field names or the form's `action`: the account controller reads them.

### Search (`search`)

The search page tries `search.php`, then `archive.php`, then `index.php`. A theme that has no `search.php` falls back to `archive.php`, which has no form, so a theme should supply one (the built-in theme draws a form of its own if a theme has none). It receives the story-list data above, except that the page has no `sorts` (sorting is a field of the form) and `total` can read "More than 1,000", because a search counts no further than that and offers no page past the thousandth result.

| Name | Contents |
| ---- | -------- |
| `forms` | A list holding the one search form, `{action, submit, reset_url, filtered, fields}`. It is a plain `GET` form with no `csrf_token`. Hand it to a part such as `search-form`. A field is `{id, name, label, type, value, hint, options}`; the type is `search`, `text`, `number` or `select`, and a select's `options` are `{value, label, selected}`. Print every value with `lf_e()` and do not change the field names: the page reads them from the address. `filtered` is true while anything narrows the search, which is the time to open a disclosure of filters and to show **Clear search**. |
| `filters` | What the search is narrowed by, each `{label, remove_url}`. Show them as links that remove just that filter. |
| `notices` | Messages, each `{type, text}`. A search that is too long, too wide, or made only of one-letter words is refused and explained here instead of run. |
| `searched` | True when words or filters are in use. Use it to say "no stories match" instead of "no stories yet". |

### Reader features: bookmarks, progress and preferences

Signed-in readers can bookmark a story or a chapter, continue where they left off, and keep their reading choices in their account. Every action is a plain form with a token, so it works without scripts, and a theme never learns who is signed in: a guest simply receives empty lists.

| Name | Contents |
| ---- | -------- |
| `bookmarks` | On a story or chapter page, the buttons to draw, each `{scope, saved, label, form}`. `scope` is `story` or `chapter`, and `saved` says whether the reader already has the bookmark (the form then removes it). Empty for guests. Draw each with `lf_part('bookmark-form', ['form' => $button['form'], 'saved' => $button['saved']])`, or draw the whole group with a `bookmark-buttons` part. |
| `follow` | On a story page, a list of one `{following, label, form}` (empty for guests): the **Follow this story** or **Stop following** button. Draw it with `lf_part('bookmark-form', ['form' => $button['form'], 'saved' => $button['following']])`, or with a `follow-button` part. |
| `notices` | On a story or chapter page, what to tell the reader about their last action, each `{type, text}`; a notice may also carry `url` and `link` (an address and the words for it, empty when there is none), which the `notices` part prints after the text, for example "2 new chapters since you last read." with **Read the first new chapter**. Empty for guests, and drawn once. |
| `resume` | On a chapter page (and on a story page that holds its single chapter), `{label, percent}` when the signed-in reader has a saved place in that chapter, otherwise empty. Draw it with a `resume-button` part: a paragraph marked `data-lf-resume` and `hidden`, holding a button. The reading-position script shows the paragraph only when the saved place is further down than the first screen, so a theme never decides that. |
| `form` | A bookmark form is `{action, csrf_token, submit, hidden}`, and a form that adds a bookmark may also carry `option`, `{name, label}`: a tick box to print inside the form (`<input type="checkbox" name="{name}" value="1">`), which follows the story with the bookmark. Print a `csrf_token` field and one hidden input for each `hidden` entry, and do not change any name or value: the controller reads them. |

The **bookmarks** page (`bookmarks.php`, falling back to `archive.php`) receives the story-list data, with `notices`, and each story card also carries `chapter_title` and `chapter_url` (empty when the whole story is bookmarked) and `remove`, a bookmark form to draw next to the card. A theme without `bookmarks.php` still shows the list through `archive.php`, but with no way to remove a bookmark, so supply one. Each card also carries `follow`, `{following, label, form}`, a form that follows or stops following the story.

The **Continue reading** page (`continue.php`, falling back to `bookmarks.php`) receives the story-list data with `notices` and `clear` (`{url, label}`, a link to the confirmation page, empty when the list is empty). Each card carries `chapter_title` and `chapter_url` (where to resume), `read_on` (a date), `finished` (true when the last published chapter was reached), `progress` (such as "37% through", empty when none or finished), `whats_new` (such as "2 new chapters", empty unless the reader follows the story and asked to be told), and `forget`, a bookmark-style form to draw next to the card.

The **Updated stories** page (`updates.php`, falling back to `bookmarks.php`) receives the story-list data with `notices` and `stop_all` (`{url, label}`). Each card carries `whats_new`, `new_url` (the first new chapter, empty when only a chapter was revised) and `stop`, a bookmark-style form. The confirmation pages that ask before **Clear the list** and **Stop tracking all** reuse the `account` view with one form that has no fields.

The reading-position script (`assets/js/reading-position.js`) is loaded by core on chapter pages for a signed-in reader, from the `lf_footer` hook, and finds the chapter text by the class `lf-chapter__text`, so a theme must keep that class on the element that holds `chapter.html`. It stores how far down that element the reader got, as a whole number from 0 to 1000, and does nothing when the element is missing, when the chapter fits on the screen, or for a guest.

A signed-in reader's saved colour scheme, text size and reading width reach a theme's script as three `<meta>` tags that core writes into the head, before the theme's own `lf_head` output: `lf-reader-prefs` (a JSON object of the saved choices, empty when none), `lf-reader-save` (the address to post a change to) and `lf-reader-token` (a token that works once). A script that stores its own preferences, like Storybook's `js/reader.js`, applies the saved choices first and posts `csrf_token`, `script=1`, `scheme`, `size` and `width` to that address after each change; the reply is JSON holding the token for the next change. The accepted values are `system`, `light`, `dark` for `scheme`; `default`, `small`, `large`, `xlarge` for `size`; and `default`, `narrow`, `wide` for `width`. A theme that ignores the tags still works, and readers can set the same choices on their account page.

Later views add their own data here as they are built.

## Hooks for features

A feature adds what it needs in one place, and every theme gets it.

| Hook | Kind | Purpose |
| ---- | ---- | ------- |
| `lf_head` | Action, receives the view | Print markup inside `<head>`. |
| `lf_body_open` | Action, receives the view | Print markup right after `<body>`. |
| `lf_footer` | Action, receives the view | Print markup before `</body>`. |
| `lf_form_fields` | Action, receives the form's `id` | Fired by the `account-form` part inside each form. Prints the hidden fields of a protected form. A theme calls it once per form and prints nothing itself. |
| `lf_styles` | Filter, `array<handle, path>` | Add stylesheets. |
| `lf_scripts` | Filter, `array<handle, path>` | Add scripts, loaded with `defer`. |
| `lf_body_class` | Filter, `list<string>` | Add classes to `<body>`. |
| `lf_document_title` | Filter, `string` | Change the whole `<title>`. |
| `lf_navigation_items` | Filter, list of `{label, url, current?}` | Add items to the main menu. Core uses it to append an **Admin** item for signed-in people who may use the admin area, so `lf_navigation()` can show one more item for staff than for readers; a theme needs no code for it and must not hide or reorder it by who is signed in. |
| `theme_option_fields` | Filter, list of field definitions | Declare theme options (see below). |

Stylesheets and scripts must be paths on this site, starting with a single `/`. Anything else is ignored, so the Content-Security-Policy, which allows only this site's own files, keeps meaning what it says. A theme that needs more calls the `csp_directives` filter and takes responsibility for it.

```php
add_filter('lf_styles', function (array $styles): array {
    $styles['my-feature'] = '/content/plugins/my-feature/style.css';

    return $styles;
});
```

## Theme options

Declare options in `functions.php`. They appear on **Appearance > Theme options** and are read in templates with `lf_option()`.

```php
add_filter('theme_option_fields', function (array $fields): array {
    $fields[] = [
        'key' => 'accent',
        'label' => 'Accent colour',
        'type' => 'color',
        'css_variable' => '--lf-accent',
        'hint' => 'Used for links and buttons.',
    ];
    $fields[] = [
        'key' => 'width',
        'label' => 'Reading width',
        'type' => 'select',
        'css_variable' => '--lf-reading-width',
        'choices' => ['38rem' => 'Narrow', '46rem' => 'Medium', '60rem' => 'Wide'],
    ];
    $fields[] = ['key' => 'show_sidebar', 'label' => 'Show the sidebar', 'type' => 'checkbox'];

    return $fields;
});
```

| Key | Meaning |
| --- | ------- |
| `key` | Lower-case letters, digits and underscores, starting with a letter. |
| `label` | The label on the screen. |
| `type` | `color` (a `#rrggbb` value), `select`, or `checkbox`. |
| `choices` | For `select`: value => label. |
| `css_variable` | Optional, for `color` and `select`: a custom property such as `--lf-accent`. |
| `default` | The starting value, for options without a CSS variable. |
| `hint` | Help text under the field. |

**An option with a CSS variable never has a default of its own.** It starts as "use the theme's own value", and nothing is written to the stylesheet until an administrator picks something. The options stylesheet loads after the theme's, so a default would silently replace the design you chose. Put your real default in `style.css`, and let the variable override it only when chosen:

```css
:root { --lf-accent: #7a3b1f; }
a { color: var(--lf-accent); }
```

Select values for an option with a CSS variable may only use letters, digits and `. % , ( ) _ -` and spaces, because they are written into a stylesheet.

## What belongs in a theme and what does not

A theme is presentation only. If an experienced theme author could not safely rewrite a file from a blank page using only this document, something in it belongs in core.

- Do not define functions in a theme file for anything the application depends on: no security checks, no token handling, no sanitising, no hashing.
- Do not copy markup that depends on a feature being switched on. The application fires an action at the right place (`lf_body_open`, `lf_footer`) and the feature prints its own markup there.
- Do not copy shared scripts or images into each theme. Shared files belong in the top-level `assets/` folder and are addressed with `lf_asset_url()`.
- Do not read the database, configuration, request, cookies or session. A template receives the data its view defines, and nothing else.
- Do not use inline `style` attributes, inline `<style>` blocks, or inline scripts. The Content-Security-Policy refuses them.
- Class names use the `lf-` prefix, so another theme can restyle every part without editing a template.

The template tags are the boundary between a theme and the application. PHP cannot stop a theme that deliberately reaches around them, so only install themes you trust.

## Bundled themes

**Storybook** (`content/themes/storybook/`) is the default theme and the fuller example. It shows two patterns worth copying:

- **Reader preferences without inline code.** A small script in `js/reader.js`, added from `functions.php` with the `lf_head` action, stores the reader's colour scheme, text size and reading width in the browser and sets `data-lf-scheme`, `data-lf-size` and `data-lf-width` on the root element. The stylesheet answers those attributes with custom properties, so no style or script is ever written into a template. Controls that need the script carry `lf-js-only` and stay hidden until the script has added `lf-js` to the root element, so the page works without scripts.
- **Light and dark colours as tokens.** All colours are custom properties on `:root`, redefined under `@media (prefers-color-scheme: dark)` guarded by `:root:not([data-lf-scheme="light"])` and again under `:root[data-lf-scheme="dark"]`, so a reader's manual choice wins over their device setting.

## The built-in theme

When no theme is chosen, or the chosen one is missing or broken, a plain built-in theme draws the site. It is a complete, small example of the rules above and is a good place to start. It is never listed as an installed folder and cannot be replaced by a folder with the same name.
