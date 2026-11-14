# History

Completed tickets are moved here from `TODO.md` at each Release.

## Version 1.4.0 — 2026-11-16

### LF-012. Feeds

**Status:** Complete (6 of 6) — released in 1.4.0

RSS is a first-class feature. Modelled on Lumora Press's feed service, copied rather than shared. Belongs to the public archive milestone (M3).

- [x] Site-wide feed of recently published and recently updated stories
- [x] Per-author, per-fandom, per-tag, and per-genre feeds
- [x] Per-story feed of new chapters
- [x] Each feed also available as a JSON Feed, with individual feed types switchable off in Settings
- [x] Feeds respect visibility: drafts, unpublished stories, and stories awaiting approval never appear, and feeds expose the display name and slug, never the login name
- [x] Tests for draft and unpublished exclusion and for conditional `304` responses

Built 2026-11-09 and also covering the carried-over parts: the news feed (`/feed/news`), chapter end notes in a story feed (the story's own end note and a draft chapter's never), the cover thumbnail as the item picture (`StoryPresenter::cover()`), and the optional per-story comment feed (published comments only, off by default). The `ETag` is a hash of the body and no `Last-Modified` is sent, because a date taken from the newest entry cannot tell that an older entry was removed. An archive set to require sign-in serves no story feed (a feed reader cannot sign in); news stays open. The site and news feeds are advertised in the head of every public page by core (`FeedLinks`), so no theme changed. Per-page autodiscovery and visible feed links for an author, term or story feed moved to LF-089.

### LF-013. Export

**Status:** Complete (6 of 6) — released in 1.4.0

Built 2026-11-11. Notes: `ExportService` (one request, one ZIP, removed once sent through `Response::file(..., temporary: true)`) with `manifest.json` and one JSON file per story, so a large archive is written to disk story by story and never held in memory; admin screen **Stories > Export** and an **Export the story** button on the story form (POST with a token only). An Author exports their own stories, drafts included; an Administrator also any author's and the archive; a user who may manage a story (an Editor too) can export that one story. Series and collections are in the file (the story's own membership, and the definitions listing only the exported stories). The format is `docs/EXPORT-FORMAT.md`, version 1.

The plan treats portability as essential for an archive. Import is deliberately left for after the MVP; the manifest format should be designed so a later importer can read it. Modelled on Lumora Press's lossless export, copied rather than shared. M5 milestone.

- [x] Export a single story with its chapters
- [x] Export all stories belonging to one Author, available to that Author and to Administrators
- [x] Export the whole archive (Administrator only) as a zip with a `manifest.json` and, optionally, cover images
- [x] Include tags, fandoms, characters, relationships, ratings, warnings, status, dates, and chapter order
- [x] A documented export format in `docs/`, with a format version number
- [x] Tests that an Author cannot export another Author's drafts and that exported files never contain login names or password hashes

From LF-064: the export includes each chapter's `end_notes` and each story's `end_notes`, and the documented format lists them. From LF-016: `AccountDataExporter` already produces a per-account export for privacy requests; this ticket's story export is a different, portable format and should not reuse its shape.

### LF-014. Sitemap, robots and redirects

**Status:** Complete (5 of 5) — released in 1.4.0

Built 2026-11-09. Notes: a story's slug (`{id}-{words}`) never changes after creation, so "when a story's slug changes" has nothing to trigger on; the automatic redirects are made for a changed static page address and a merged term, through `RedirectService::remember()`, and `docs/DEVELOPER-APIS.md` says how a plugin that renames something calls it. A redirect answers only where the site would answer 404 (`Kernel::redirectFor()`), so it never hides a live page. The sitemap is an index with parts of at most 10,000 addresses (`site-1`, `authors-N`, `terms-N`, `stories-N`) and leaves chapters out on purpose. Migration 0037 (`redirects`). Admin: Settings > Search engines, Settings > Redirects.

Stories have URLs worth keeping, and an in-progress archive may want to stay out of search engines. M3 milestone.

- [x] XML sitemap covering public stories, authors, and taxonomy pages only (and the news archive and published news posts once LF-034 exists)
- [x] Virtual `robots.txt`, plus a "discourage search engines" setting that also adds a `noindex` meta tag
- [x] Canonical URL on story and chapter pages
- [x] Admin-managed redirects, with an automatic permanent redirect when a story's slug changes
- [x] Tests that drafts and unpublished stories never appear in the sitemap and that redirects cannot loop

From LF-062: the sitemap lists published static pages that are not marked `noindex` (`PageService::published()` returns them with `noindex` and `slug`; the address is `PublicUrl::page($slug)`), and the page's canonical address is already printed in its head.

### LF-017. Page caching

**Status:** Complete (6 of 6) — released in 1.4.0

Built 2026-11-11 as `Core/Cache/PageCache` (headers, `304`, purge instructions), `PurgeRules`, `CacheExclusions`, `Services/CacheSettings` and the Settings > Cache screen. The decision is made in `Kernel::finishResponse()` from the matched route name; purges are driven by the audit log. The header test is a script that requests the page without credentials. Story and chapter pages are kept by the browser only while a plugin listens to `story_viewed`.

Supports the shared-hosting and performance goals. Modelled on Lumora Press's cache headers and LiteSpeed integration, copied rather than shared. M5 milestone.

Note from LF-077 and LF-078 (built 2026-11-08): a public page can differ by visitor. With **Let visitors choose their own theme** on, every page already carries `Vary: Cookie` (the choice is the `lf_theme` cookie or the account's), and while an Administrator previews a theme the page carries `Cache-Control: private, no-store`. A cache or a `304` must keep honouring both, and a cached page must still fire `story_viewed` as noted below.

- [x] HTTP cache headers and conditional `304` responses on cacheable public pages
- [x] Never cache anything for a signed-in user, any draft, preview, admin, or account page, or any response that sets a cookie
- [x] Per-page-type switches and a never-cache path list in Settings
- [x] LiteSpeed Cache purge integration (auto-detected, with a manual override), purging the affected story, author, and listing pages when content changes
- [x] Built-in header test screen so an Administrator can confirm what is being sent
- [x] Tests that a private draft is never served from cache or to a different user, and that publishing, unpublishing, and deleting a story purge the right pages

A guest's cached story or chapter page, or a `304`, must still fire the `story_viewed` action (see `docs/DEVELOPER-APIS.md`), or the Visitor Stats plugin (LF-054) stops counting those views. Counting this way should stay the plugin's job: the cache layer only fires the action.

### LF-018. Settings export and import

**Status:** Complete (5 of 5) — released in 1.4.0

Built 2026-11-09 as `SettingsTransfer`, which wraps the settings service of each screen, so an import is checked and audited exactly as the screen does it. Groups: Archive (which holds the Reading switch), Security (without the proxy list and HTTPS header), Privacy (without the policy page number), Comments, Downloads, Feeds, News, Front page, Editor, Retention, Search engines and Theme. Not included, on purpose: spam protection (keys and the question's answer are secret), updates, maintenance mode and the site location. The format is `docs/SETTINGS-FILE.md`; theme options travel only for the theme already in use, because only that theme's own rules can check them.

Copy one install's configuration to another. Modelled on Lumora Press's portable settings export, copied rather than shared. After the MVP.

- [x] Export Archive, Reading, Security, Privacy, and theme settings as a versioned file
- [x] Import into a different install with a before/after preview shown before anything is applied
- [x] Never exported: site URL, administrator email, secret key, table prefix, database credentials, and any other install-specific or secret value
- [x] Reject files from an unknown format version, and validate every value as the settings screen would
- [x] Tests that secrets never appear in an export and that a tampered file cannot write an unknown setting

### LF-019. Sweep cleanup plugin (bundled)

**Status:** Complete (5 of 5) — released in 1.4.0

Built 2026-11-11 as `content/plugins/sweep/`. The application has no user trash, so never-confirmed accounts are anonymised through account deletion (see `DECISIONS.md`). The reminder is a weekly count taken from the dashboard. The bundled plugin starts at `Version: 1.0.0`.

A database cleanup tool for long-running archives. Modelled on Lumora Press's Sweep plugin. Needs the plugin loader; see `DECISIONS.md`. After the MVP.

- [x] Maintenance › Tools screen listing what can be cleaned and how much each would remove, with a preview before anything is deleted
- [x] Old chapter revisions beyond a retention count, old trashed stories and chapters, and expired password-reset and confirmation tokens
- [x] Never-confirmed accounts past a configurable age, moved to the user trash rather than deleted outright
- [x] Unused tags and fandoms that no story references
- [x] Reminder in the notifications inbox when cleanup candidates build up; tests that nothing a story still references is removed

### LF-033. Classic Archive theme

**Status:** Complete (10 of 10) — released in 1.4.0

Built 2026-11-11. Notes: `content/themes/classic-archive/` is a copy of Storybook's templates and parts (same file list, checked by a test) restyled by its own `style.css`, with new `parts/story-card.php`, an optional picture band in `header.php`, and a side-column layout in `search.php`; `ThemeCatalog::BUNDLED` gained `classic-archive` (the only core change, so the updater and Appearance treat it as bundled). Rating and status squares only: a warning is shown as an outlined tag, not a square, because what a warning means is the site's own vocabulary and neither field may be inferred from the other. The header picture is a choice among three SVGs in the theme's `images/` folder (checkbox `show_header_image`, select `header_image`, both without a CSS variable) instead of an administrator-supplied URL, because Theme Options has no image or text field type and adding one is a core change. Browser-checked on the dev install: home, search (side column), story page, mobile width, dark scheme on search, picture band; NOT seen: chapter page, series, collection pages, print preview. Release prerequisites: the theme's `Version:` is 1.0.0 and `@since 1.4.0` assumes the next release is 1.4.0. `custom themes/` holds no theme, so nothing to propagate.

The second theme from the plan, in a traditional fandom-archive style. Comes after the first release; depends on LF-028. Design mockup: `Resources/themes - mockups/classic-archive/classic-archive.html` (navy, parchment and brick palette with a dark set; compact boxed blurbs; sidebar filters; reader controls). It is the visual reference for the items below. Because LF-068 added series, the theme must also provide `series.php` and the `story-groupings` part ("Part N of M" with previous and next links), which the mockup only hints at.

- [x] Compact story blurbs with metadata and tag lists, simple navigation, and a focus on discoverability
- [x] Story blurb layout per the mockup: a column of at-a-glance signal squares (rating and completion status; warnings are an outlined tag), each with a text label and title so colour is never the only signal; then title and author, fandoms, a tag list (warnings, relationships and characters styled distinctly), summary, and a one-line stats list (language, words, chapters, comments, kudos, updated), with a single-column fallback on phones
- [x] Browse and search pages with a results bar (count and sort control), a sidebar "Filter stories" form (type, fandom, rating, excluded warnings, included tags, word count, status) that honours the archive mode and disabled taxonomies, and numbered pagination
- [x] Optional header image: a band under the site header, off by default and shown only when the administrator turns it on in Theme Options and picks one of the theme's bundled pictures, decorative, shorter on phones, left out of print, and never overriding the theme's own look when off (an administrator-supplied image would need a new Theme Options field type, a core change not made here)
- [x] Reader controls bar on the chapter page (text size, jump to chapter, print) and a chapter navigation bar (previous, index, next), keyboard operable
- [x] All styling in the theme stylesheet under `lf-` classes (no inline styles at all), with the dark set and print rules added with each component
- [x] The same templates and template tags as Storybook, proving a second theme needs no core change
- [x] Light and dark colours, reader controls, skip links, and a print layout equal to Storybook's
- [x] Selectable under Appearance, independent of the reader's own light or dark choice
- [x] Tests: renders every template, passes the same escaping and visibility tests as Storybook

### LF-068. Series

**Status:** Complete (9 of 9) — released in 1.4.0

Built 2026-11-11. Notes: migration 0039; `SeriesService`, `GroupingSettings` (also holds the collections switches), admin `SeriesController` (**Stories > Series**), a Series choice and part number on the story form, public page `/series/{slug}` through `ArchiveController`, "Part N of M" with Previous and Next through the new `story-groupings` part, feed `/feed/series/{slug}`, sitemap part `series-N`, a `series` search filter, export membership. A series with no public story answers 404. Themes: `series.php` and the part in Storybook, Rosewine, Lavender Mist and the built-in theme; the `custom themes/` folder holds no theme, and there is no Classic Archive theme yet (LF-033 must add the part and templates). The view hierarchy falls back to `archive.php` for any theme without `series.php`.

`Project Architecture.md` defers a series model until the core publishing and reading workflow is solid, and `Project Plan.md` lists it as an optional parent of a story. A **series** is an ordered sequence of stories by the same author (sequels, a shared universe told in order); it is part of how the author presents their own work. It is separate from collections (LF-069, curated groups that may hold other people's stories), from the reader's private reading list and bookmarks, and from taxonomy terms.

- [x] Migration adding a `series` table (owner, title, slug, summary, status of the whole series, timestamps) and a `series_stories` link with a position, with a unique slug and a unique story per series so a story sits in at most one series; migration and rollback tested
- [x] `SeriesService` (create, edit, delete, add, remove and reorder stories), enforcing that only the series owner or staff manage it and that an Author can add only their own stories; deleting a series leaves the stories untouched
- [x] Author screens: create and manage a series under their stories, set a story's series and position from its form, and drag-free reordering with move up and down buttons that work from the keyboard
- [x] Public series page (title, author, summary, ordered story list with each story's card, total words and chapters, completion status) and a "Part N of M in Series" line with previous and next story links on the story page, both using only stories the reader may see, so a draft, pending or unpublished story never appears in a series list or its counts
- [x] Administrator setting to switch series on or off (off hides the interface and public pages without deleting data, as for taxonomy features)
- [x] Templates, template tags and the `lf-` CSS classes in the Storybook stylesheet with dark-mode rules added together with each component, propagated to every theme under `custom themes/` in the same session (add missing rules only), plus the Classic Archive theme when LF-033 exists
- [x] Feeds (LF-012) gain a per-series feed; the sitemap (LF-014) lists public series; exports (LF-013) include series membership; search can filter by series title; `docs/DEVELOPER-APIS.md` documents any new action or filter, for example when a story joins or leaves a series
- [x] Story and account deletion are handled: deleting a story removes its series link only (the series and the other stories keep their order), and deleting an Author removes their series per the existing account-deletion rules
- [x] Tests: ordering, one-series-per-story, ownership and permission checks (an Author cannot add another author's story to their series, or edit another's series, by hand-crafted POST); drafts and unpublished stories never leak into a public series, feed, count or "part of" line; switched off hides the screens and public routes; deleting a story or a series leaves the rest intact; the migration upgrades an install that already holds stories

Not in scope: nested series, series that span several authors, co-authors (a separate deferred feature), comments or following a series (these wait for the comments and follows milestone), and automatic series suggestions. Update `README.md` (user-facing screens and settings), `docs/THEME-DEVELOPMENT.md` (new template tags and CSS classes), `docs/DEVELOPER-APIS.md` and `docs/CHANGELOG.md` when implemented.

### LF-069. Collections

**Status:** Complete (9 of 9) — released in 1.4.0

Built 2026-11-11. Notes: migration 0040; `CollectionService`, admin `CollectionController` (**Stories > Collections**: the curator's collections, **Collections open to your stories**, **What you have offered**), public `/collections` and `/collections/{slug}`, feed `/feed/collection/{slug}`, sitemap part `collections-N`, export membership. A story goes in only by its own author (the curator's own stories go straight in), so a curator never adds someone else's work; a recommended-reading list of other people's stories is therefore not possible with this ticket. A private collection is seen only by its curator and by staff who may edit any story, takes no submissions, has no feed and is not in the sitemap. Creators setting `collections.creators`: administrator, editor or author. Same theme notes as LF-068.

A **collection** is a curated group of stories that need not be in order and may hold other people's stories (an anthology, a challenge or event, a recommended-reading list); the person who curates it does not own the stories in it. It is separate from a series (LF-068, one author's ordered sequence), from the reader's private reading list and bookmarks, and from taxonomy terms. The two tickets are independent and can be built in either order; when LF-068 comes first, reuse its story-list partial and public-page templates.

- [x] Migration adding `collections` (owner, title, slug, description, visibility of public or private, whether the owner must approve additions) and `collection_stories` (story, who added it, approval state, optional note); migration and rollback tested
- [x] `CollectionService` with the same permission pattern: the owner and staff manage the collection and its approval queue; any signed-in Author may submit their own story to a public collection that accepts submissions, which is held until approved when the owner requires it; a story's author can always withdraw it; a collection never changes or exposes a story that its viewer could not already see
- [x] Visibility follows the story, not the collection: a private, draft or unpublished story is never listed to anyone who cannot already open it, even inside a public collection, and a private collection is reachable only by its owner and staff
- [x] Public collection index and page (description, curator, approved stories), and a "Part of these collections" list on the story page limited to public collections with the story approved
- [x] Administrator setting to switch collections on or off (off hides the interface and public pages without deleting data, as for taxonomy features), and to limit who may create a collection (Administrators, Editors, Authors)
- [x] Templates, template tags and the `lf-` CSS classes in the Storybook stylesheet with dark-mode rules added together with each component, propagated to every theme under `custom themes/` in the same session (add missing rules only), plus the Classic Archive theme when LF-033 exists
- [x] Feeds (LF-012) gain a per-collection feed; the sitemap (LF-014) lists public collections; exports (LF-013) include collection membership; `docs/DEVELOPER-APIS.md` documents any new action or filter, for example when a story is added to or removed from a collection
- [x] Account deletion and story deletion are handled: deleting a story removes its links only, deleting an Author removes their submissions (and their collections, per the existing account-deletion rules), and a collection survives the removal of stories in it
- [x] Tests: ownership and permission checks (a hand-crafted POST cannot edit another person's collection, approve a submission, or add a story the person does not own); drafts and unpublished stories never leak into a public collection, feed, count or "part of" list; submission, approval, withdrawal and private visibility; switched off hides the screens and public routes; deleting a story or a collection leaves the rest intact; the migration upgrades an install that already holds stories

Not in scope: nested collections, collection-specific themes or custom fields, challenge sign-ups, comments or following a collection (these wait for the comments and follows milestone), and automatic suggestions. Update `README.md` (user-facing screens and settings), `docs/THEME-DEVELOPMENT.md` (new template tags and CSS classes), `docs/DEVELOPER-APIS.md` and `docs/CHANGELOG.md` when implemented.

### LF-088. Make rating mandatory

**Status:** Complete (8 of 8) — released in 1.4.0

Built 2026-11-09. Notes: the rule lives in `TaxonomyService::requiredTypes()`/`missingRequired()` and `StoryService::classify()` (a public or pending story may not lose its rating); the starter four are migration 0036 (the installer deletes them when a rating starter list is chosen); "Not Rated" stays a choosable value (starter lists and the migrated default), so an author who declines to rate has still defined it; "Not rated" is shown by `StoryPresenter` (cards and story page) as a rating group with no address, so no theme changed.

Today the rating is only required by default: `taxonomy.required` starts as `['rating']`, an Administrator can untick it under Classification, and `TaxonomyService::missingRequired()` skips a required kind whose list is empty and that writers cannot add to (so a site with no ratings set up never asks). That makes the rating, which tells a reader who a story is suitable for, a preference rather than a rule. Make it mandatory for every story that is submitted or published, on every site, and keep it as a separate field from the archive warnings (a rating is never worked out from a warning, or the other way round).

- [x] The rating can no longer be switched off: it is always in the required list, shown ticked and disabled on the required-kinds screen with a line saying why, other kinds stay optional as now, and a hand-made POST that leaves it out changes nothing (the stored list is rebuilt to include it)
- [x] A story cannot be submitted for approval or published without exactly one rating, in the story form, the chapter publish action and any plugin or import path that publishes through the services (the rule lives in `StoryService`/`TaxonomyService`, not in a form); the refusal says "Choose a rating." and a draft can still be saved without one
- [x] A site with no ratings yet is not locked out: when the list of ratings is empty, installing or applying a classification starter list is offered first, and a first-run default of the common ratings is added by the installer and by the update that ships this ticket (a migration or a one-off step that adds General, Teen, Mature and Explicit only when the list is empty and never touches an existing list or its order)
- [x] Stories already published without a rating are not unpublished: they keep showing, the admin story list marks them "No rating" with a filter, the author sees a notice on the story's edit screen asking for one, and the next edit that submits or republishes them requires it; nothing is changed automatically
- [x] Story cards and the story page show the rating on every story, and a story without one (an old one) shows "Not rated" instead of leaving the place empty, so a reader can tell the two apart
- [x] A rating term cannot be deleted or merged away while stories use it without choosing a replacement (the existing merge path), and hiding the last visible rating is refused with a plain sentence
- [x] Documented: `docs/ADMINISTRATION.md` (the rating is always required and the starter list), `docs/USER-GUIDE.md` for writers, `README.md` if the feature overview names required fields, `docs/THEME-DEVELOPMENT.md` for the "Not rated" text on the card and story data, and `docs/CHANGELOG.md`
- [x] Tests: the required list always holds the rating, whatever is posted; submit and publish are refused without a rating and allowed with one, from the form and from the service; a draft saves without one; an empty ratings list gets the starter ratings once and a populated list is untouched; an old published story keeps showing and is marked; the card and story page show "Not rated"; deleting or hiding the last rating is refused; the migration applies on an install that already holds stories

Not in scope: making any other kind mandatory (the existing required-kinds setting still covers fandoms, genres and the rest), a different rating system per fandom, age gates or content blocking by rating, and importing ratings from another archive (LF-083 maps eFiction ratings). Check against LF-061 (classification terms from a file) before building: it also adds terms in bulk, so reuse its preview and report components if it exists by then.

### LF-089. Feed links on the pages they belong to

**Status:** Complete (7 of 7) — released in 1.4.0

Built 2026-11-09. The visible link is `lf_feed_link()` (answered through the new `lf_page_feed` filter) and sits right after the `h1` of the `archive`, `story`, `chapter`, `news-archive` and `news-single` templates of the four themes; `FeedLinks` also adds "Feed" and "News feed" to the footer list. Release prerequisite: bump the `Version:` of Storybook, Rosewine and Lavender Mist (templates and CSS changed) and the built-in theme.

LF-012 advertises only the site-wide and news feeds, in the head of every page. A page should also point to its own feed: the page of fandom 1 gets the feed of fandom 1, an author's page that author's feed, a story page that story's chapter feed. The general (site-wide) feed link also shows in the footer of every page.

- [x] Autodiscovery `<link rel="alternate">` in the head (RSS, and JSON Feed while it is on) for the page's own feed, written by core through `lf_head` like `FeedLinks`, so no theme needs to change for it. Covers an author page, a fandom, genre or tag page, a story page and a chapter page (the story's feed), the News page and each news post (the news feed), and a story's comment feed on those pages while it is offered
- [x] The controllers tell core which feed the page being shown belongs to (the way `PagePresentation::showing()` is told), and nothing is advertised on a page that is not public, a 404, a preview, or a kind that is switched off or not offered (`FeedSettings::offered()`, which includes the closed-archive rule)
- [x] A visible, keyboard-reachable feed link on those pages in Storybook, Rosewine, Lavender Mist and the built-in theme, through one template tag (for example `lf_feed_link()`) that returns nothing when the page has no feed on offer, with stable `lf-` classes, light and dark styling, and an accessible name such as "Feed for Skyfall"
- [x] The general feed link in the footer of every public page: a visible "Feed" link to the site-wide feed (and the news feed while it is offered), added through the existing `lf_footer_items` filter or `lf_footer_links()` so every theme shows it without a template change, shown only while `FeedSettings::offered()` allows the site feed, and styled in the bundled themes
- [x] Every theme under `custom themes/` gets the same addition in the same session (add only; ask before changing existing rules) — the folder holds no theme today, so there was nothing to add
- [x] Documented: `docs/THEME-DEVELOPMENT.md` for the tag and its classes, `docs/USER-GUIDE.md` and `README.md` if the overview mentions it, and `docs/CHANGELOG.md`
- [x] Tests: each page type, the News page included, advertises exactly its own feed and no other; fandom 1's page never carries fandom 2's address; nothing on a 404, a draft, a switched-off kind or while sign-in is required; the visible link appears and disappears with the same rule; the footer link is on every public page while the site feed is offered, and gone when it is switched off or sign-in is required

Not in scope: a feed for search results, for a combination of terms, or for a reader's own updates.

### LF-090. Anonymous install tracking (opt-in)

**Status:** Complete (10 of 10) — released in 1.4.0

Built 2026-11-11. The service takes a small `HttpPoster` (implemented by the updater's `HttpClient`, so TLS rules and timeouts live in one place) and is sent from `Kernel::sendInstallPing()`. The receiving server is `install-tracking-server/` at the project root. The Send a test ping button works only while the switch is on, so nothing leaves the site without opt-in.

Lumora Press and Lumora Gallery each have an opt-in, off-by-default anonymous install ping (`InstallPingService`, Settings › Privacy in Press) that tells the maintainer roughly how many installs are active, which versions are still in use and when an older PHP version can be dropped. Lumora Fiction gets the same, built the same way so one operator dashboard can read all of them. It sends an install UUID, the Lumora Fiction version and the PHP version, and nothing else: no domain, site title, administrator details, content, configuration or visitor data. Nothing in Lumora Fiction depends on the endpoint being reachable, and the feature is entirely separate from the update check's release source.

- [x] `InstallPingService` in `app/Services/` (modelled on Press's, namespaced `LumoraFiction\Services`, with the standard file header): `isEnabled()`, `getOrCreateUuid()` (random UUID v4, made once and kept across a disable and re-enable), `maybeSendPing()` (a cheap no-op unless enabled and the interval has passed) and `sendPing()` (throws on failure so the test button can report it); the payload is exactly `install_uuid`, `version` and `php_version`
- [x] Off by default; stored as settings through `Configuration` (enabled flag, UUID, time of the last attempt); the endpoint, a 30-day interval and a 5-second timeout are constants, and the endpoint and the network transport are injectable for tests
- [x] The install ID belongs to one site address: next to the ID the service keeps a hash of the configured site address, and just before a ping is sent the hash is compared with the current address; when they differ (a backup restored on another host, a copied folder, a moved domain) a new ID is made and the new hash stored, so two sites never share one ID. The hash stays on the site and is never sent, the payload still holds exactly three fields, and a restore on the same address keeps its ID (an address change counting as a new install is accepted for an anonymous counter)
- [x] Fires once straight after the administrator turns it on, then at most about monthly; the attempt time is recorded whether it worked or not, so an unreachable endpoint is retried next month and not on every page; a failed automatic ping is swallowed and never shown, and a failed ping can never slow or break an admin page (short timeout, no retry loop)
- [x] Triggered from the admin area only (never on a public page, so a visitor's request never waits on it); reuse whatever HTTP transport and TLS verification `UpdateChecker` already uses, so there is one place that decides how the site makes an outside request, and send a clear `Lumora Fiction/{version}` user agent with no site address in it
- [x] An **Anonymous install ping** switch under Settings › Privacy (the existing `PrivacySettings` screen, audited like its other fields) that states plainly what is sent and what is not, shows the install ID, shows when the last ping was tried, and has a **Send a test ping now** button that reports success or the failure in a sentence; the wording never names a ticket, a class or an internal file
- [x] The setting joins the settings file (LF-018) as part of the Privacy group, **but the enabled flag and the install ID are not exported or imported**: an import must never switch tracking on for another site or copy one site's ID onto another (add them to the list of install-specific values `docs/SETTINGS-FILE.md` names as left out)
- [x] The receiving server is a separate project outside this repository, like the Press and Gallery ones: `install-tracking-server/` at the project root (outside `LumoraFiction/`, never shipped or committed by it) holding `ping.php` (accepts JSON or form fields, validates a UUID v4 and a version pattern, answers 204, or 400 for an invalid request), the one-row-per-install table `lumorafiction_install_pings` (`install_uuid` unique, `version`, `php_version`, `first_seen`, `last_seen`, `ping_count`; upsert on each ping), a login-protected `install-stats.php` (total and active installs in the last 60 days, version and PHP major.minor breakdowns, new installs by month), `config.example.php`, a README and a short plan document, so the tables of all the Lumora apps can share one database and one dashboard (add a `lumorafiction_install_pings` entry to that dashboard's `apps` array when it is deployed)
- [x] Documented: the README's privacy section (off by default, exactly what is sent, what is never sent, how often, where to turn it off), `docs/ADMINISTRATION.md`, `docs/DEVELOPER-APIS.md` if the service or a filter is exposed, a `DECISIONS.md` entry (opt-in, off by default, why it stays out of the settings file, why the endpoint is separate from the update source), and `docs/CHANGELOG.md`
- [x] Tests: disabled sends nothing; enabling sends once; a second call inside the interval sends nothing and one after it sends again; the payload holds exactly the three fields and no other; the UUID is made once and survives a disable and re-enable; an unchanged site address keeps the ID and a changed one makes a new ID, and the address hash is never part of the payload; a transport failure and a non-2xx answer are swallowed by `maybeSendPing()` and thrown by `sendPing()`, with the attempt time recorded either way; the Privacy screen saves the switch, audits it and shows the test result; a settings export leaves out the flag and the ID and an import cannot set them; no public page triggers a ping

Not in scope: any tracking of visitors, readers or content, per-feature usage counts, crash or error reporting, a ping on a schedule that does not need an admin page load, and bundling the receiving server in the release ZIP. Check against LF-018 (settings export and import) and the update checker before building: both touch the Privacy and Updates settings and the outside-request code.

### LF-091. Hide feed kinds the archive mode has switched off

**Status:** Complete (4 of 4) — released in 1.4.0

Built 2026-11-11. The one rule is `FeedSettings::available()` (archive mode through `ArchiveSettings::modeOf()`, which `TaxonomyService::enabledTypes()` uses too, and the series and collections switches), used by `kindEnabled()`, `offered()`, the settings screen and `validate()`.

**Settings > Feeds** lists every kind of feed whatever the archive mode, so an original-fiction site still sees "Each fandom", "Each character" and "Each relationship" with tick boxes that do nothing: the term pages and feeds of a kind the mode switches off answer "not found" already. The Classification screens hide such kinds (`TaxonomyService::enabledTypes()`), and the feeds screen should follow them.

- [x] The Feeds settings screen lists a term-based feed kind (fandom, character, relationship, genre, tag) only while `TaxonomyService::isEnabled()` says its kind is on in the archive mode, with a line saying which kinds are hidden by the archive mode (as the Classification screen does), and keeps what was saved for them
- [x] A saved feed choice for a hidden kind is neither lost nor changed by saving the screen, so switching the archive mode back restores it exactly; a hand-made post that names a hidden kind changes nothing for it
- [x] `FeedSettings::offered()` and the head and footer links already treat a hidden kind as not offered; check that the new rule lives in one place and that the settings screen, `FeedService` and `FeedLinks` all use it
- [x] Tests: in original-fiction mode the screen lists no fandom, character or relationship box, in fanfiction mode no category box once LF-093 exists, saving keeps the hidden kinds' saved values, and switching back shows them again

Update `docs/ADMINISTRATION.md` (Settings > Feeds) and `docs/CHANGELOG.md` when implemented.

### LF-092. Relabel the fixed kinds of classification

**Status:** Complete (7 of 7) — released in 1.4.0

Built 2026-11-11. `ClassificationLabels` (read) and `ClassificationLabelSettings` (validate and save), reached through `TaxonomyService::labels()`; the form is the **Names of the lists** card on the Classification screen. The settings-file group is `classification` with one map, `labels`, of `{kind}_singular` and `{kind}_plural`.

The kinds of term are a fixed list in the code (`TermType`), so a site that calls a relationship a "pairing", or a category a "trope", cannot say so: every screen, page and feed says "Relationship". Adding new kinds is not wanted (the list drives behaviour such as fandom parents, single-choice ratings and per-kind limits), but the words a site shows can be its own. This is display text only: addresses, stored values, feed addresses and the settings file keep the fixed names.

- [x] Settings (under Classification or Archive) lets an Administrator give each kind a singular and a plural label, with the fixed label as the default and shown as the placeholder, validated like term names (length, no control characters, no markup) and reset by clearing the field
- [x] One place supplies the label: `TermType::label()` and `pluralLabel()` stay the defaults, and a small service (for example `ClassificationLabels`) is asked by the admin screens, the story form, the public term and browse pages, story cards and pages, search filters, feeds' titles and descriptions, downloads, and the Classification list, so a relabelled kind reads the same everywhere
- [x] Public addresses (`/relationship/…`, `/browse/…`, `/feed/relationship/…`) and the stored `type` values do not change, and nothing that parses a label (search syntax, imports, the settings file, tests) depends on the shown label
- [x] The label is used as text only and is escaped wherever it is printed, including in feed titles and the `lf_feed_link()` accessible name
- [x] Included in the settings export (LF-018) as part of a new `classification` group, validated as the screen does
- [x] Documented: `docs/ADMINISTRATION.md`, `docs/THEME-DEVELOPMENT.md` (template data carries the shown label, never the fixed one, and `type` stays the fixed name), `docs/SETTINGS-FILE.md` and `docs/CHANGELOG.md`
- [x] Tests: a relabelled kind shows its label on the story form, a term page, a story card, a feed title and the Classification screen, while addresses and `type` are unchanged; an empty value restores the default; markup in a label is escaped; the label survives an export and import

Not in scope: adding new kinds, renaming a kind's address, per-fandom labels, and translating the interface into other languages.

### LF-093. Feed for categories

**Status:** Complete (5 of 5) — released in 1.4.0

Built 2026-11-11 with migration 0038, which ticks the new kind in a saved `feeds.kinds` list.

Original-fiction sites use **categories** where fanfiction sites use fandoms, and categories have no feed today (feeds exist for fandom, character, relationship, genre and tag only), so an original-fiction archive has no feed for its main way of browsing.

- [x] `/feed/category/{slug}` (and `.json`) lists the public stories of a category exactly as the fandom feed does, through `FeedService::term()` with `TermType::Category` added to `FeedService::termKinds()`, answering "not found" while the kind is off in the archive mode (fanfiction only) or switched off in the feed settings
- [x] A category page (`/category/{slug}`) carries the head link and the visible "Feed for …" link, with the same offered and sign-in rules as the other kinds
- [x] A **Each category** kind on the Feeds settings screen, on by default for a new site; a site that already saved its choices keeps them and ticks it (noted in the changelog), and it is hidden in fanfiction mode by LF-091
- [x] Documented: `docs/ADMINISTRATION.md`, `docs/USER-GUIDE.md`, `docs/CHANGELOG.md`
- [x] Tests: the feed lists only public stories, a switched-off kind and a fanfiction-only archive answer "not found", the category page advertises its own feed and no other, and the settings screen lists the kind

Coordinate with LF-091 (which hides kinds the mode switches off) and LF-092 (the label shown for "category" in feed titles).

------

### LF-094. Group the Settings menu under headings

**Status:** Complete (5 of 5) — released in 1.4.0

The Settings group of the admin sidebar lists sixteen screens in one flat list. Group them under non-clickable headings so it is easier to scan. Headings only: real collapsible sub-groups would need a second menu level and are not part of this ticket.

- [x] Menu entries can be headings (a label, no address, no capability); a heading is shown only when at least one entry after it, before the next heading, is visible to the person
- [x] Settings is grouped as: **General** (Archive, Front page, Editor, Series and collections), **Reading and discovery** (Feeds, Search engines, Redirects, Cache, Downloads), **Community** (Comments, News), **Safety and privacy** (Security, Spam protection, Privacy), **Data** (Retention, Export and import); every address stays the same
- [x] The sidebar prints a heading as a plain label (not a link), styled in the admin stylesheet with light and dark colours, and it also reads sensibly in the narrow-screen layout
- [x] `docs/DEVELOPER-APIS.md` explains the `heading` key for plugins that add to a group
- [x] Tests: headings are kept in order, hidden when nothing follows them, never marked current, and a plugin's entry can still be attached after a heading; the existing menu tests follow the new order

------

### LF-095. Classic Archive: header pictures the administrator adds by FTP

**Status:** Complete (6 of 6) — released in 1.4.0

The Classic Archive picture band (LF-033) offers three bundled SVG pictures, hard-wired in the theme's option list and stylesheet. An administrator who uploads their own picture by FTP cannot use it without editing theme files, and the edit is lost on the next theme update. Let the Header picture dropdown also list pictures found in a folder updates never touch.

- [x] Folder: `content/uploads/theme-headers/` (created on demand, outside the theme so updates leave it alone); the Header picture dropdown lists the three bundled pictures first, then each file there named `banner-*` with a `.jpg`, `.jpeg`, `.png`, `.webp` or `.svg` extension, labelled from the file name (`banner-sea-glass.jpg` becomes "Sea glass")
- [x] Only the file name is stored as the option value, and it is checked against a fresh folder listing on every request, so a missing or renamed file falls back to the theme's own default instead of printing a broken picture; no path or traversal characters are ever accepted
- [x] Delivery without inline styles: the chosen file's address reaches the stylesheet as a CSS custom property set in the theme's head output, which the project rule allows for a genuinely dynamic value; the bundled three keep their class rules
- [x] Each file is checked before it is offered: the real image type must match a picture type (`getimagesize()`), and an SVG must contain no script, event attributes or external references; it is used only as a CSS background, never inlined
- [x] The dropdown hint says where to upload, which formats work, and a suggested size (wide and short, around 1600 by 300 pixels), documented in the Classic Archive `README.md` and `docs/THEME-DEVELOPMENT.md` (the dynamic-choices pattern)
- [x] Tests: the dropdown lists the bundled three plus valid uploaded files in order, an unsupported extension, a fake image and an SVG with a script are not offered, a stored name that no longer exists falls back, and the band renders with the chosen picture

Built 2026-11-11. Decision on the open question: a `select` field takes a `pictures` setting (`['variable' => '--lf-header-picture', 'upload' => 'header_upload']`), and `ThemeOptions` adds the folder's pictures to its choices as `file:{name}` (a new `ThemePictures` service lists and checks them). The chosen picture's address is written to the generated options stylesheet (not the page head) as the named custom property, and the template prints the kind through the new `lf_option_class()` tag, so a stylesheet needs one rule per kind (`--file`, `--upload`). The listing is read once per request. A real upload field is LF-096.

------

### LF-096. Theme Options image upload field (admin-set header picture)

**Status:** Complete (7 of 7) — released in 1.4.0

Theme Options have three field types today (`color`, `select`, `checkbox`), so a theme cannot let the administrator upload a picture from the admin screen. Add an `image` field type that reuses the existing picture pipeline, then let Classic Archive use it for the picture band as the mockup showed (an admin-set image), alongside the bundled pictures. Complements LF-095 (pictures added by FTP); build whichever the administrator needs first, they share the band markup.

- [x] `ThemeOptions::TYPES` gains `image` (the picture is resized to the one wide header size for now; a per-field `kind` was left out until a second use needs a different size), and the field type is documented in `docs/THEME-DEVELOPMENT.md` with the other types
- [x] The theme options form becomes `multipart/form-data` only when the theme has an image field; the field shows the current picture, a file input, and a "Remove picture" checkbox
- [x] Uploads go through `ImageStore` (real type check, JPEG, PNG or WebP only, re-encoded under a generated name, SVG refused), with a new picture kind for a wide header (for example 1600 pixels wide, capped height, with a smaller size for narrow screens); a failed upload shows the existing friendly error and keeps the old picture
- [x] The stored value is the generated file name only; replacing or removing the picture, switching theme options off, or deleting the setting removes the old files so nothing is orphaned; the field has no default and prints nothing until a picture is set (the "theme's own choices beat core defaults" rule)
- [x] Classic Archive: a "Header picture" choice **Uploaded picture** (shown only once one is set) beside Dusk, Ink and Meadow; the band uses the uploaded file as a CSS background through a custom property, with the same height, dark-mode and print rules as the bundled pictures; the mockup's behaviour is matched and the alt-less decorative treatment kept
- [x] The picture is served from `content/uploads/`, covered by the same upload rules as story covers (no script execution, never listed or cached publicly beyond the file itself), and the server without GD or WebP support shows the same "cannot read this picture" message as the cover upload
- [x] Tests: the field type validates and renders, a valid upload is stored and offered, SVG and fake images are refused, a failed upload keeps the previous picture, removing and replacing clean up files, the form is multipart only when needed, and the band renders the uploaded picture; the custom-themes and bundled-theme option tests follow the new type

Built 2026-11-11. The file name is stored inside the theme's own options setting, next to the other options, and deleting a theme removes its pictures too. A settings export carries no picture and an import leaves them as they are. Whether Storybook, Rosewine and Lavender Mist should offer the same field later is open (they have no picture band today).

### LF-097. Import your own stories from AO3 and other OTW-Archive sites

**Status:** Complete (15 of 15) — released in 1.4.0

Built 2026-11-14. Notes: core, not a plugin. `Core/Import/` (`OtwDownloadParser`, `OtwWork`, `OtwChapter`, `PlainText`, `RatingScale`, `UploadReader`, `ImportException`), `Services/ImportService` and `ImportSettings`, admin `ImportController` and `ImportSettingsController` (**Stories > Import**, **Settings > Import**), views `import`, `import-preview`, `import-report`, `import-undo` and `settings-import`, migration 0041 (`import_runs`, `story_imports`), hook `story_imported`, `docs/IMPORT-FORMAT.md`. The parser was checked against real downloads made by the local OTW-Archive development install (never kept as fixtures; the tests use invented files built to the same template by `Support/OtwDownload`). The core change the ticket foresaw: `StoryService::applyDates()` and `ChapterService::applyDates()`, plus `Request::uploads()`. The kept upload is a JSON plan in `storage/cache/imports` for up to two hours (removed on confirm, cancel and the next upload), which differs from "nothing is kept". A story is not wrapped in one transaction (the database layer cannot nest one around the services' own); a failed story is deleted again instead. Not built: the **Import a story** button on the dashboard. The EPUB download (LF-098) and resumable steps (LF-099) were built the same day. Release prerequisites: none for bundled themes or plugins; `@since 1.4.0` assumes the next release is 1.4.0.

Writers moving from Archive of Our Own, Squidgeworld or any other site running the OTW's open-source Archive software (the "otwarchive" code base) want to bring their own stories across with everything the archive knew about them, not retype fandoms, characters and tags. This is a per-author tool, not a site migration (that is LF-083, which is administrator-only and reads a database). Every OTW-Archive site offers the same downloads on each work (HTML, EPUB, PDF, MOBI) and they carry the same metadata block, so one parser covers all of them, whatever the site's name. It is **part of core** (decided 2026-11-14): other story archives treat "new post" and "import" as sibling pages, and a writer arriving from another archive expects to find it beside **New story**, not behind a plugin an administrator has to find and switch on. It is a small, self-contained subsystem (an import service, a parser behind an interface, one controller, its own table) that writes only through the existing services, so imported stories obey the same sanitiser, rating rule (LF-088), approval flow and visibility as anything typed in. An Administrator can switch it off in Settings, which hides the screens and routes without deleting data.

**Decision: files, never addresses.** The author uploads the file they downloaded from their own work; the server never fetches a URL. Fetching an AO3 address would be server-side URL fetching (the SSRF risk recorded in `DECISIONS.md`), is blocked or rate-limited by those sites, would reach restricted and locked works only by handling someone's login, and sidesteps the sites' terms. An uploaded file is also the only way to import a work the site no longer shows publicly. Pasting a work's address is therefore not offered.

- [x] A core **Import** screen as a sibling of **New story**: a menu entry under **Stories** right after **New story**, and an **Import a story** button next to **Add a story** on the story list, for every signed-in user who may write stories (the existing story-creation permission, nothing new), with an Administrator setting under Settings (default on) that switches it off and hides the entry, buttons and routes; a user imports only into their own account, never for another person, and every import is one audit entry with the counts
- [x] Upload accepts the **HTML download** of a work, several HTML files at once, or a ZIP of them (the **EPUB** download is LF-098, built); limits on file size, files per run and expanded ZIP size, UTF-8 or declared-charset text only, ZIP entries checked for traversal, symlinks and nested archives, and nothing is kept on disk after the run except the imported story itself
- [x] A parser (`Core/Import/`, with the orchestration in an `ImportService`; the controller stays thin) behind a small interface so another format can be added later, that reads the work's metadata block rather than guessing from the host name: title, author byline (including pseuds), summary, work notes, end notes, rating, archive warnings, relationship categories (F/F, F/M, Gen, M/M, Multi, Other), fandoms, relationships, characters, additional tags, language, series name and part number, published and updated dates, word count, chapter count and "X of Y" completion, and per chapter: number, title, summary, start notes, text and end notes; the downloads label fields in English, and a file whose labels cannot be read is refused with a plain message, never half-imported
- [x] **Ratings are mapped to this site's ratings.** AO3-style names (General Audiences, Teen And Up Audiences, Mature, Explicit, Not Rated) are matched to this site's rating terms by name and alias first, then offered as a suggested pairing by audience level (so the AO3-style preset and the minimal preset both land sensibly); the author sees and can change the pairing for each distinct rating in the file before anything is written; an Administrator fixes the site-wide pairing by adding the other site's rating as an alias of one of the site's ratings under Classification (the existing alias feature, so nothing new is invented, and the Import settings screen says so); a rating that cannot be matched leaves the story a **draft** with a clear note (the rating is mandatory), never a guessed value, and a rating is never inferred from a warning or the reverse
- [x] Archive warnings map to this site's warning list the same way (by name and alias, then the author's choice), with "Creator Chose Not To Use Archive Warnings" and "No Archive Warnings Apply" matched to their equivalents where the site has them; categories map to the relationship-category list; and a kind the site has switched off by archive mode or setting is skipped and said so in the preview, never created
- [x] Fandoms, relationships and characters are matched to existing terms (by name, alias and the hierarchy of fandom and its characters and relationships) through `TaxonomyService`, so every rule that applies to hand-added terms applies here; a term the author may propose here is created as **proposed**, and a term they may not is left out with the reason shown in the preview (an unknown term is never turned into a tag or mapped to another term; matching is by name and alias, so an administrator's aliases decide); additional tags follow the tag rules (writers may be barred from adding tags, in which case unknown tags are left out); nothing is created without appearing in the preview first
- [x] **Series:** when several uploaded files name the same series, the author is offered to create one Lumora series (LF-068) with the parts in the order the files give, or to join an existing series of theirs, or to import without a series; a single work that says it is part 2 of a series is imported on its own with the series name and position kept in the report, never an empty series; series switched off by the administrator hides the choice, and nothing outside the author's own stories is ever added to a series
- [x] **Collections:** the download does list the collections a work is in (they may belong to someone else), so the file's collection names are read; the screen offers, for each name, to put the story in one of the author's own collections (the one with the same title is suggested), to make a new collection with that name where the site lets the author start one, or to leave it out, through `CollectionService` and its rules; membership of someone else's collection is never copied.
- [x] Preview before anything is written, and a separate confirm step: per file the title, chapter count, words, the mapped rating, warnings, fandoms, characters, relationships and tags with each marked **matches** / **new** / **needs a choice** / **dropped**, the status that will result (draft, or submitted or published by the site's own publishing rule for this author), and the problems found; the author picks, per story, **import as draft** or **import and submit** (a first publication still goes through the approval queue where the site requires it)
- [x] Stories and chapters are written through `StoryService` and `ChapterService`: chapter text and notes go through the same sanitiser as the HTML editor (scripts, work skins and custom CSS, inline styles that the sanitiser does not allow, and external images are removed and counted in the report), chapter order and titles are kept, word counts are recalculated, original published and updated dates are kept as the story's and chapters' dates where the services allow a date to be supplied (otherwise the report says the dates are those of the import), and completion maps to the site's work-status values
- [x] **Your own work only.** The upload screen asks the author to confirm they wrote the work or have the author's permission, stored with the import record; the byline is compared with the author's display name and any pseuds they enter and a mismatch is a warning, not a block (pseuds and orphaned or anonymous works are legitimate); an Administrator may switch the plugin to staff-only imports; and the screen does not claim the check proves authorship
- [x] Duplicates and re-runs: the work's original address and id found in the file (or a hash of title, author and first chapter where there is none) are kept in a new `story_imports` table (a migration, tested with rollback and on an install that already holds stories) next to the new story id, so re-importing the same work warns and offers **skip** (the default) or **import as a new story** (adding only the missing chapters is not offered); the original address is shown to the author as an optional **originally published at** line in the story's notes only when they tick it, since it identifies another site
Moved to LF-099 and built there: running an import in small resumable steps on a progress page.
- [x] A report that stays on the screen and downloads as a plain text file: counts, every dropped or remapped term, every file that was refused and why, and every sanitiser removal; **Undo this import** deletes exactly the stories, chapters and links the run made (only while they are still unedited drafts, or after a confirmation that shows the numbers) and never the terms that already existed; the report holds no email address, login name or path
- [x] Documented: `README.md` (the feature overview), a guide in `docs/USER-GUIDE.md` (how to download a work from AO3 or Squidgeworld, what is and is not carried over, ratings and warnings mapping, series and collections behaviour, the authorship note, how to undo), `docs/ADMINISTRATION.md` (the switch, the default rating pairing, the staff-only option), the supported-file notes in `docs/`, `docs/DEVELOPER-APIS.md` for any new hook (for example when a story is imported) and `docs/CHANGELOG.md`
- [x] Tests (EPUB cases are LF-098), using **invented** small HTML files made to the OTW download shape (never a real author's story, never copied from a real site, nothing from `Resources/`): every metadata field is read; each AO3 rating lands on the right site rating under the AO3-style preset, the minimal preset and a custom list, and an unmappable rating leaves a draft; warnings and categories map and a switched-off kind is skipped; existing fandoms and characters are matched through aliases and unknown ones follow the proposal rules; a script tag, work skin and inline style in a chapter are removed and counted; a series of three files makes one ordered series and a lone part 2 makes none; collection choice is limited to the author's own; a ZIP with a traversal path, a symlink or a nested archive is refused; an oversized or non-UTF-8 file is refused; a second import of the same work warns; a failure in chapter three rolls the whole story back; **Undo** removes only what the run made; a user cannot import into another account or see another user's import report by hand-crafted request; switched off hides the menu entry, buttons and routes; the migration applies on an install that already holds stories; and the permission and switch settings are enforced on the server

Not in scope: fetching from any address, logging into AO3 or any other site, scraping, importing other people's works, bookmarks, kudos, comments, hit counts, collections as read from a site, work skins and custom CSS, series descriptions beyond the name, importing a whole site (use LF-083 for eFiction; a whole-site OTW-Archive migration would be its own ticket), PDF and MOBI downloads (no reliable structure), and FanFiction.net or other non-OTW formats (the parser interface leaves room, each would be its own ticket). Check before building: LF-061 (classification terms from a file) and LF-083 (eFiction importer) share the ideas of a preview, a mapping step and a report, so reuse their preview and report components rather than writing a third pair; LF-088 makes the rating mandatory for any submitted or published story; LF-068 and LF-069 supply the series and collection services; and the story and chapter services must accept supplied dates, or this ticket adds that as a small core change with its own tests. Build the parser against a real downloaded sample on the developer's own machine to confirm the labels and markup before fixing the tests' invented shapes, since OTW releases may change the download template.

**Reference install for the parser (development only, outside the repo, never shipped):** a local OTW-Archive development copy is at `/home/piia/Coding/otwarchive/otwarchive` and is meant to answer at `http://localhost:3000/`. It is a Ruby on Rails app run through its own `docker-compose.yml`, so it is separate from the PHP dev stack (Apache and the system MySQL that serve Lumora Fiction, Lumora Press and Lumora Gallery) and is started on its own. Use it only to create invented test works on a throwaway account and download them as HTML and EPUB for fixtures and for checking the labels and markup; never import real people's works into a fixture, and the importer itself never contacts it or any other site. It was not running when this was written (nothing answered on port 3000). Its compose file publishes a MariaDB on host port **3306**, which is the port the PHP dev stack's system MySQL already listens on at `127.0.0.1:3306`, so the two collide; the other published ports (Redis 6379, Elasticsearch 9200, 9300 and 9400, memcached 11211, the app on 3000, Selenium 4444, the test service 5100) are not in use by the PHP stack, though Elasticsearch needs a lot of memory. To avoid it, the `db` port mapping in the otwarchive compose file was changed on 2026-11-14 to `3308:3306` (the app container reaches the database by the service name `db`, so nothing else changes; it is a local edit to that checkout's `docker-compose.yml`, so keep it when pulling updates, and a tool on the host that wants that database must use port 3308). The same trick is already used for the PHP Test Suite's database on 3307. Confirmed 2026-11-14: with that change the OTW-Archive dev install and the PHP dev stack run at the same time.

### LF-098. Import the EPUB download

**Status:** Complete (4 of 4) — released in 1.4.0

Built 2026-11-14. Notes: `Core/Import/EpubReader` joins the pages of the book in spine order into one HTML document and the same `OtwDownloadParser` reads it, so an EPUB gives exactly what the HTML of the same work gives. Checked against real downloads from the local OTW-Archive development install, including a work with three chapters, chapter summaries, notes, end notes and work end notes (made on that install for the purpose): the converter's spine is in reading order, the preface and every chapter are split over several pages with empty wrapper divs left behind, and the classes are renamed (`meta group` becomes `calibre1`, the chapter text block `userstuff2`) but the ids and the `h2.heading` survive. The parser therefore no longer relies on wrapper classes: chapters are found by their heading (the heading's own class tells it from a heading the author wrote in the text), and the preface, summary and notes are found by walking the preface in order. An EPUB inside a ZIP is refused ("upload it on its own") because opening it would need a temporary file.

- [x] Upload accepts an EPUB on its own, opened in memory by `UploadReader` and `EpubReader` with the same size limits and name checks, reading `META-INF/container.xml` and the package's manifest and spine and never extracting to disk (an EPUB inside a ZIP is not opened)
- [x] The pages are joined into the document the HTML parser expects, and the chapter boundaries are found without relying on the classes the converter drops or renames
- [x] The preview, mapping, report and undo of LF-097 work unchanged for an EPUB, and the EPUB and the HTML of one work are noticed as the same work
- [x] Tests with invented EPUB files built to the converter's layout (`Support/OtwEpub`): the result equals the HTML's for a work of one chapter, a chaptered work and one with several tags, an author's own "Chapter N" heading stays in the text, a path leaving the archive, damaged files and a file with no preface are refused

### LF-099. Import in resumable steps

**Status:** Complete (4 of 4) — released in 1.4.0

Built 2026-11-14. Notes: migration 0042 (`state`, `job`, `locked_until` on `import_runs`). `ImportService::apply()` now only queues the run; `step()` writes the next stories for about eight seconds (at least one) and saves after each, `cancel()` and `progress()` complete it. A lock stops two requests working on one run, and the link row of a story is written as soon as the story exists, so a story whose writing was cut short is found and removed before it is made again. Pages: `/admin/import/run/{token}` (progress, `admin/assets/js/import-progress.js` sends the "Carry on" form by itself; the button does it by hand without scripts) and `…/stop`; **Stories > Import** lists a run that is not finished with **Carry on with this import**. The kept upload is touched at each step and is not expired while the run is going; a run left for more than two hours loses its upload and reports the rest as not imported. The audit entry `story.import` is now written when a run finishes.

- [x] The confirmed import is stored as a run with the choices made and the works still to do, and each request imports a few stories within a time budget
- [x] A progress page that continues until every story is written, can be reloaded and resumed after an interruption, and can be stopped (leaving what was written, undoable as now)
- [x] The report and undo work for a run that was resumed or stopped, and a story is never written twice by a repeated request (undo refuses a run that is still going)
- [x] Tests: a run split over several requests gives the same stories as one request, a repeated step writes nothing twice, a request that finds the run locked writes nothing and an old lock is taken over, a story cut short is removed and made again, a stopped run keeps what it had written and can be undone, another account cannot step or stop a run, and a lost upload fails its stories without losing the run

### LF-100. Bulk actions on more screens

**Status:** Complete (11 of 11) — released in 1.4.0

Built 2026-11-14. Shared pieces: `Core/Admin/BulkResult` (done and skipped counts, each reason once, the 100-item limit), `Core/Admin/BulkBar` (an empty form beside the table, tick boxes and buttons joined to it with the `form` attribute, so tables that already hold row forms stay valid), `AdminController::bulkInput()` and `askBeforeBulk()`, the shared `bulk-confirm` view, and `bulk-select.js` (counters can now be named so two lists on one screen keep separate counts). Each service has a `bulk()` that calls the single action per item and writes a `…bulk` summary entry (`chapter.bulk`, `story.review_bulk`, `story.bulk`, `news.bulk`, `page.bulk`, `user.bulk`, `redirect.bulk`, `collection.bulk`, `series.bulk`); `StoryBulkService` holds the new story actions. Decisions: notification housekeeping writes no audit entry, because marking one message read does not either; removing stories from a series or collection does not ask first, because the stories are untouched; a placement change on a page never touches its address, so no redirect is made. Not seen in a browser: dark mode and phone width of the new bars, and every screen other than the story list and a chapter list.

Bulk actions exist today on the story list (publish or send for approval, unpublish, trash, delete), the Classification term lists, the Comments screen, the Trash and the theme list, each with a tick box per row, an action menu, a confirmation page where the action is destructive, and a count of what was done and what was skipped. The rest of the admin makes people repeat the same click row by row. This ticket adds the same pattern to the screens where it saves real work, and widens the story actions. Every action is applied one item at a time through the service that owns it, so each item is permission-checked on its own, a refused item is skipped and counted and never stops the others, the audit log gets the same entries as the single action, and a hand-made request cannot do more than the screen offers. Reuse `admin/assets/js/bulk-select.js`, the confirmation views and the `done` and `skipped` result of `StoryService::bulk()`; a page of results holds at most 100 items, as now. Without JavaScript the screens still work (the tick boxes and the menu are plain form controls).

- [x] **Chapters** (the chapter list of a story): tick chapters and **Publish**, **Unpublish**, **Move to the trash** or **Delete for good** (confirmation page), through `ChapterService` so the story's term counts, word count and chapter numbering are refreshed once per request and not once per chapter, and publishing a chapter in a story that is still a draft stays hidden from readers as now; the order buttons stay as they are
- [x] **Stories**: more actions on the existing menu: **Set the rating** (needed to bring old stories up to the mandatory-rating rule), **Set where it stands** (ongoing, completed, on hiatus, abandoned, honouring the site's abandoned switch), **Add to series** and **Add to collection** (own stories only, existing rules), **Downloads: use my default, allow, refuse**, and **Add a tag, fandom or character** and **Remove a tag, fandom or character** by name through `TaxonomyService`, with the same proposal rules as the story form
- [x] **Awaiting approval** (review queue): tick several and **Approve**, or **Send back** with one shared reason, through `approve()` and `reject()`, with the notification to each writer as for a single decision
- [x] **News posts**: **Publish**, **Unpublish** (back to draft), **Pin**, **Unpin** and **Delete** (confirmation page), by those who may publish news
- [x] **Pages**: **Publish**, **Unpublish**, **Show in the menu**, **Show in the footer**, **Remove from the menu or footer** and **Delete** (confirmation page), keeping the redirect and address rules of a single change
- [x] **Users** (Administrators only): **Suspend**, **Reinstate**, **Change role**, **Change the publishing setting** (direct, held for review, or the site's rule) and **Send a password reset**, never applied to the person doing it, to the last Administrator, or to an account whose change the single screen would refuse; deleting accounts stays one at a time because of the data-privacy steps
- [x] **Redirects**: **Delete**, and **Change kind** (permanent or temporary)
- [x] **Series and collections**: on a collection's screen, **Approve**, **Turn down** and **Remove** several offered stories at once, and on a series' screen **Remove** several stories; the move buttons stay
- [x] **Notifications**: **Mark as read** and **Delete** on chosen messages (**Mark all as read** exists)
- [x] Every bulk form shows what the action will do to how many items before a destructive one runs, says afterwards "N done, M skipped" with the reasons for the skipped ones listed once, writes one audit entry per item as the single action does (plus a single `…bulk` summary entry with the action and the counts), fires the same actions and filters, and keeps the filter, sort and page the person was on; the pages are keyboard operable, the tick-all box has a label, and the selection count is announced to screen readers
- [x] Tests: each action does what the single action does and writes its audit entries; an item the person may not change, or that the service refuses, is skipped and counted while the rest are done; a hand-crafted POST naming another person's story, chapter or account, an action the screen does not offer, more than 100 ids, or no token is refused; a destructive action does nothing without its confirmation; the last Administrator and the person's own account are never touched by a bulk user action; chapter numbering stays complete after a bulk trash or delete; term counts are right afterwards

Not in scope: bulk editing the text of chapters, find and replace across stories, changing a story's owner, bulk export (LF-013 already exports a whole author) and bulk import. Check before building: LF-073 built the term bulk screen and the shared confirmation pattern, `StoryService::bulk()` caps at 100 and reports done and skipped, and `bulk-select.js` is shared with the Comments screen, so extend those instead of writing a second pattern. Update `README.md` if it names bulk actions, `docs/USER-GUIDE.md` and `docs/ADMINISTRATION.md` for each screen, `docs/DEVELOPER-APIS.md` for any new service method or action, and `docs/CHANGELOG.md` when implemented.

### LF-101. Chapter links on the story list

**Status:** Complete (5 of 5) — released in 1.4.0

The story list (**Stories > My stories** and **All stories**) links each row only to the story's own screen, so reaching a story's chapters or starting a new chapter takes a click on the story first, then another to the chapter list. Writers who work chapter by chapter, and editors who correct one, do this all day. Give each row direct links to the story's chapter list and to a new chapter, using the screens that already exist (`/admin/stories/{id}/chapters` and `/admin/stories/{id}/chapters/new`).

- [x] Each row gets a **Chapters** link to the chapter list (the existing "N of M published" count may itself be that link, so the table does not grow a column on a phone) and an **Add a chapter** link to the new-chapter form, both named for the story for screen readers ("Chapters of {title}", "Add a chapter to {title}") and reachable by keyboard in the row's tab order
- [x] The links appear only where the person may use them: the same rule as the story itself, so an Editor or Administrator sees them on **All stories** and an Author only on their own, and a story in the trash gets neither (it is not in this list); the screens behind them still check the permission, so a hand-made address changes nothing
- [x] The layout holds on a phone and in dark mode: the links wrap under the title rather than widening the table, with no new inline styles (the classes go in the admin stylesheet, e.g. `lf-row-actions`), and the print layout and sort headings are unchanged
- [x] After saving a new chapter the person lands where they started from when they came from the list (the chapter list of that story, as now), and **Add a chapter** on the chapter list still works unchanged
- [x] Tests: the links are on every row with the right addresses and accessible names, an Author's list shows them only for their own stories and an Editor's **All stories** shows them for every story, the links follow the active filter, sort and page without changing them, and a hand-crafted request to another author's chapter screens by an Author is still refused

Not in scope: new columns, inline editing of a chapter from the list, and bulk chapter actions (LF-100 covers those). Check before building: `admin/views/stories-list.php` already builds the row and `ChapterController::index()`/`showCreate()` already check ownership; reuse them. Update `docs/USER-GUIDE.md` (the Stories description) and `docs/CHANGELOG.md` when implemented.

Built 2026-11-14. The links are a `lf-row-actions` line under the title in `stories-list.php` (no new column); every listed story is one the person may manage, so the permission rule is the list's own, and the chapter screens still refuse anything else. The after-save redirect was left as it is.

## Version 1.3.0 — 2026-11-08

### LF-077. Let readers choose the theme they use

**Status:** Complete (9 of 9) — released in 1.3.0

Today the Administrator picks one active theme and every visitor sees it. With two bundled themes (Storybook and, once LF-033 is built, Classic Archive) and custom themes, a site may want to let people choose the look they read in, as AO3 does with its site skins. `Project Plan.md` already says theme selection is independent of the reader's light or dark choice; this ticket adds the choice itself, off by default so existing sites behave exactly as before.

- [x] Administrator setting under Appearance, "Let visitors choose their own theme" (default off), and a list of which installed themes visitors may pick from; the site's active theme is always on the list and is the default for anyone who has not chosen
- [x] A theme switcher drawn through a template tag and a part (`lf_theme_switch()`, in the style of `lf_downloads()`), so every theme can place it and none re-implements the form or the token; it prints nothing when the setting is off or only one theme is offered
- [x] A signed-in reader's choice is saved on the account and follows them to any device, reusing the reader-preferences storage rather than a new column if it fits; a visitor who is not signed in gets a small first-party cookie holding only the theme's folder name, set by a POST with a form token, with no script needed (the switcher works as a plain form), and the cookie is `SameSite=Lax`, `Secure` on HTTPS and never holds anything but a name from the offered list
- [x] The renderer resolves the theme per request: account choice, then cookie, then the site's active theme; a name that is missing, not offered, switched off, or whose requirements the site no longer meets falls back silently to the site's theme, and the built-in theme remains the last resort
- [x] Theme Options: each theme's options (colours, spacing) apply when that theme is shown, and the options screen keeps editing the site's active theme only; a theme's stylesheet and options stylesheet are requested by that theme's name so one theme's options never leak into another
- [x] Caching and privacy: a page whose theme depends on the visitor must not be served from a shared cache to someone with a different choice (a `Vary: Cookie` header, or no shared caching for these pages, and a note for LF-017); the choice is not logged, not exported by anyone else, and is included in the account's own data export and removed with the account
- [x] Admin screens, the installer, the maintenance page, emails, downloads (EPUB, PDF, HTML) and feeds always use the site's theme or none, never a visitor's choice
- [x] Documented: the setting, the switcher tag and its part in `docs/THEME-DEVELOPMENT.md`, the template data in `docs/DEVELOPER-APIS.md` if a hook is added, `README.md`, and `docs/CHANGELOG.md`; the switcher and its CSS are added to the Storybook and built-in themes with dark-mode rules, and to every theme under `custom themes/` when it exists
- [x] Tests: off by default changes nothing; a chosen theme shows for that visitor only; a hand-crafted POST or cookie naming an unlisted, missing or unsafe theme (`../x`, an uppercase or long name) falls back and never reaches the filesystem; a stale token changes nothing; the signed-in choice survives a new session; one theme's options do not appear under another; deleting a theme an account had chosen falls back cleanly; downloads and admin ignore the choice

Not in scope: per-story themes, letting authors style their own stories, user-uploaded themes or custom CSS, and following the device's light or dark setting automatically (that already exists independently as the reader's colour choice). Check against LF-033 before building: with only one bundled theme the switcher has nothing to offer, so this ticket is best built after the Classic Archive theme exists.

### LF-078. Administrator theme preview across the whole site

**Status:** Complete (9 of 9) — released in 1.3.0

An Administrator can try any installed theme on the real public site, on every page, before making it the active one, with nobody else seeing the change. This is the same idea as the theme preview in Lumora Gallery, where an administrator adds `?theme=folder-name` to the public address and the theme shows for that request only, with a banner and a Preview button per theme on the Themes screen. Gallery carries the `theme` parameter forward by rewriting every internal link, which is easy to miss for a link a plugin or theme builds itself; here the preview is held in the administrator's own session instead, so it follows them through stories, chapters, archives, search, news, pages, account screens and form posts without touching any link.

- [x] A Preview button for each installed theme on Admin › Appearance › Themes (not shown for the theme already active), and a way to start a preview from any public address with `?theme=folder-name`; only a signed-in Administrator can start one, and anyone else gets the page as normal with the parameter ignored
- [x] The chosen theme is stored in the administrator's session (never a cookie another person could set or a value in the address after the first request), is validated against the list of installed, valid, compatible themes on every request, and a theme that has since been deleted, broken or become incompatible falls back silently to the active theme and clears the preview
- [x] The renderer resolves the theme per request through one place, so the preview, the visitor's own choice from LF-077 and the site's active theme are never decided in three separate spots; the order is the Administrator's preview, then any visitor choice, then the active theme, with the built-in theme as the last resort
- [x] The preview covers every public view the theme draws (built 2026-11-08; the maintenance page is the one exception, because it is drawn before a theme is chosen for the request, see `DECISIONS.md`): home, story, chapter, archive and author pages, search, Browse and its term indexes, News, static pages, account screens, bookmarks, Continue reading, the 404 page and the maintenance page as an administrator sees it; the theme's options (colours, spacing, fonts) apply to the previewed theme exactly as they would if it were active, and its stylesheet and options stylesheet are requested by that theme's own name and version so a browser never shows one theme's files under another
- [x] A banner shown only during a preview (a template tag with a part, in the style of the existing preview notice, so every theme can place it and none re-implements it) names the theme being previewed and offers "Make this the active theme" (a POST with a form token) and "Exit preview"; the banner is added to the Storybook and built-in themes with dark-mode rules, and to every theme under `custom themes/`
- [x] The preview never reaches anyone but the administrator who started it: it is not served from or stored in any shared page cache (no caching for a request carrying it, and a note for LF-017), it is not applied to feeds, downloads (EPUB, PDF, HTML), emails, the installer or the admin screens, it ends with the session, and signing out clears it
- [x] Activating, deleting or updating a theme while previewing it behaves sensibly: making it active ends the preview, and deleting the previewed theme falls back with a notice
- [x] Documented: the Preview button and the address parameter in `README.md`, the banner tag and part in `docs/THEME-DEVELOPMENT.md`, any new hook in `docs/DEVELOPER-APIS.md`, and a `docs/CHANGELOG.md` entry
- [x] Tests: a non-administrator or signed-out request with `?theme=` is unchanged; an administrator's preview shows on several different pages and survives a form post and a redirect; a missing, unlisted or unsafe name (`../x`, an uppercase or overlong name) falls back and never reaches the filesystem; a stale token on "Make this the active theme" changes nothing; exiting, signing out and deleting the theme all clear the preview; feeds, downloads and admin ignore it; the theme's stylesheet and options are the previewed theme's own

Not in scope: previewing for other visitors or by shareable link, previewing a theme from an uploaded ZIP before installing it, per-page theme overrides, and comparing two themes side by side. Check against LF-077 before building: both tickets need the per-request theme resolver, so build that once and let each ticket supply its own source (the administrator's session here, the account or cookie there).

### LF-080. Colour choice as one button instead of a dropdown

**Status:** Complete (8 of 8) — released in 1.3.0

Today the public header holds a "Colours" dropdown with Match my device, Light and Dark. Lumora Gallery uses a single button that cycles through Auto, Dark and Light, which takes less room in the header, is quicker to use on a phone, and avoids a dropdown (and the extra script some browsers need to style one) for a three-way choice. Lumora Fiction should do the same. The choice, where it is stored and how it follows a signed-in reader do not change: `js/reader.js` already keeps the colour scheme in the browser, applies it in the head before the page is drawn so nothing flashes, and saves it to the account of a signed-in reader through the reader-preferences meta tags.

- [x] The colour control in the theme's `parts/scheme-switch.php` becomes one button that cycles Match my device, then Dark, then Light, then back, in the order Gallery uses; it works through the same `data-lf-pref="scheme"` hook and the same stored value, so a reader's saved choice and their account setting carry over untouched
- [x] The button shows the current mode with an icon (a half-filled circle for device, a moon for dark, a sun for light, drawn inline as SVG with `currentColor` so every theme colours it) and a short visible label, and its accessible name says what it does and the mode it is in, for example "Colours: matching your device. Press for dark", updated on every press, so a keyboard or screen reader user always knows what the next press does; the press also says nothing in a live region that would interrupt reading
- [x] The button stays `lf-js-only` (it needs the script), is a real `<button type="button">` that is reachable by keyboard with a visible focus outline, and is large enough to press on a phone; without the script the page follows the device's setting as it does today
- [x] The cycle logic lives in `js/reader.js` (or a small helper loaded from `functions.php`), not in a template, and the part contains markup only; the single-button markup and its icons are in the part, and every colour, size and state is in the theme stylesheet with `lf-` classes and dark-mode rules
- [x] Storybook, Rosewine and Lavender Mist, and the built-in theme if it has the control, get the new button with their own styling; every theme under `custom themes/` gets the equivalent, and the dropdown version of the control is removed from them so there is only one way to set it; the reader's text size and width controls stay as they are
- [x] A signed-in reader's choice is still saved to their account and read back on another device, and the account page's Reading form still sets the same value; pressing the button never needs a page load
- [x] Documented: the part and its markup contract in `docs/THEME-DEVELOPMENT.md`, the button in `docs/USER-GUIDE.md` where it describes the colour choice, and `docs/CHANGELOG.md`; the colour control checks live in a new `ColourButtonTest` that covers Storybook, Rosewine and Lavender Mist (the existing class-has-a-rule checks in `GradientThemesTest` and `StorybookThemeTest` also see the new part), and a new test (a small script check or a rendered-markup check) covers the cycle order and that the stored value is one of `system`, `light` and `dark`
- [x] Tests: the part prints one button and no `<select>`; the cycle visits all three values in order and wraps; a saved value that is not one of the three is ignored; the stored value and the account value are unchanged by the redesign; the button has a name that changes with the mode; no template defines logic or inline styles

Not in scope: a site-wide default colour mode for first-time visitors (Gallery has one; Lumora Fiction follows the device by default, and a setting for it can be its own ticket if wanted), changing the text size and reading width controls, and a separate light or dark choice per theme. Check against LF-077 and LF-078 before building: both touch the theme switcher area of the header, so a theme chooser and this button should share one header tools area.
### LF-082. Replies to chapter comments (one level)

**Status:** Complete (11 of 11) — released in 1.3.0

Chapter comments (LF-070) are flat: a writer answers a reader with another comment further down, and nothing shows which comment it answers. Let a signed-in person reply directly to a comment, so a conversation reads together under the comment it belongs to. Replies go one level deep only: a reply to a top-level comment is allowed, but there is no reply to a reply, which keeps the list readable on a phone and avoids deep nesting. Everything else about comments stays as it is: plain text, signed-in people only, the same approval rule, spam checks, reports, editing window and moderation.

- [x] Migration adding a nullable `parent_id` to the comments table (an unsigned integer with an index, no foreign key, in line with the project's own rule, and null for every existing comment, so existing comments are untouched); the rollback is tested, since restoring files does not undo a migration
- [x] A reply is made through the same service and rules as a comment (`CommentService`, `CommentGuard`): the parent must exist, belong to the same chapter, be published and itself be a top-level comment; a reply to a reply, to a hidden, spam, pending or deleted comment, or to a comment on another chapter is refused with the ordinary not-found or a plain message, never a half-made row
- [x] The comment form gets a **Reply** link on each top-level comment that opens the same form with a note saying whom it answers and a Cancel link, working without scripts (a link to the form for that comment, with the reply target in the address and checked again on posting), and reply targets are never taken from a value the page could have been tampered with without being validated
- [x] Display: replies are listed under their parent, oldest first, indented one step and marked with a clear label for assistive technology ("Reply to {display name}"); the list still paginates by top-level comments so a page's length stays predictable, and a parent's replies are never split from it across pages; the story's own writer's replies carry the existing **Author** badge
- [x] Moderation and state: hiding or marking a parent as spam hides its replies from readers without losing them, and restoring the parent brings them back; deleting a parent asks first and says how many replies go with it; the moderation screen shows a reply with a "reply to" line and a link in context, can filter to replies, and bulk actions work on them as they do on any comment; a reply can be reported and held for approval by the same rules as a comment
- [x] Notifications: the writer is told of a new published reply on their story as they are for a comment; the person replied to is told when someone replies to their comment, using the existing **email me when the writer answers** choice widened to **email me when someone replies to my comment** (the stored value and its label are kept compatible with what exists), and nobody is told about their own reply or about a reply that is held or hidden
- [x] Settings: **Settings > Comments** gets a "Allow replies" switch (on by default); with it off the reply links and form targets disappear, replies already made stay visible as a flat list, and nothing is deleted
- [x] Limits and anti-abuse: replies count against the same throttle, length limit and spam guard as comments, a person can reply to the same comment more than once but not faster than the throttle allows, and a writer who has closed comments on a story or chapter closes replies with them
- [x] Themes: the template data gains the parent and reply structure as one bundled array that the theme forwards, so a field added later reaches the part without updating every theme; `parts/comments.php` and `lf_comments()` in Storybook, Rosewine, Lavender Mist and the built-in theme draw replies with `lf-comment__reply` classes styled in each stylesheet with dark-mode rules, and every theme under `custom themes/` gets the equivalent; none defines comment logic itself
- [x] Documented: the new switch and the reply form in `README.md` and the user guide, the moderation changes in `docs/ADMINISTRATION.md`, the template fields and classes in `docs/THEME-DEVELOPMENT.md`, any new hook or filter in `docs/DEVELOPER-APIS.md`, and `docs/CHANGELOG.md`; the bundled themes' `Version:` headers are bumped at Release
- [x] Tests: a reply to a published top-level comment is made and shown under it; a reply to a reply, a hidden, spam, pending or deleted comment, a comment on another chapter, or with the switch off is refused; hiding a parent hides its replies and restoring it brings them back; deleting a parent removes its replies and says how many; pagination keeps replies with their parent; the writer and the replied-to person are notified once and nobody is told of their own, held or hidden reply; a reply is held by the same rule as a comment; throttle and spam guard apply; a closed chapter or story refuses replies; the migration applies and rolls back with old comments intact

Not in scope: replies to replies, reactions or likes, mentions, collapsing or sorting threads, comments on stories or series pages, and rich text in comments. Check against LF-081 before building: Dummy Content makes flat comments only, so once this exists it should also make replies, in the same states, and a **Remove all** that takes the replies with their comments.
### LF-084. Tell the Administrator when database changes were applied after an upload

**Status:** Complete (5 of 5) — released in 1.3.0

When new files are uploaded over an existing site, the pending database changes are applied the first time an Administrator opens the admin (`Kernel::upgradeDatabase()` and `PendingMigrations::applyFor()`). On success the only trace is a `database.migrated` line in the activity log; a failure raises a critical alert, but a success shows nothing on screen, so an Administrator cannot tell the upgrade finished without opening the log. Checked on the dev install on 2026-11-08: migrations 0032 to 0034 had been applied silently on 2026-11-06 with matching activity entries, so the mechanism works and only the feedback is missing. Show a one-time notice instead. The updater already reports its own migrations on its progress page, so this is only for the upload-the-files path.

- [x] After `PendingMigrations::applyFor()` applies one or more changes, queue one success flash message for that Administrator, such as "Applied 3 database changes after the new files were uploaded.", shown once on the admin screen being opened and never again, using the existing `FlashMessages` (no new storage)
- [x] The message names only the count, never a file name or table name, and nothing about it is shown to anyone but the Administrator whose request applied the changes; it is not shown when nothing was pending, when the run failed (the existing critical alert stays the only signal), or while an update is running
- [x] The wording follows the admin's plain style and is added to the activity wording that already exists for `database.migrated`, so the dashboard's Recent activity and the flash say the same thing
- [x] `docs/ADMINISTRATION.md` and the upgrade note in the changelog say what the Administrator will see after uploading files
- [x] Tests: uploading files with two pending changes shows the message once on the next admin screen and not on the one after, a site with nothing pending shows nothing, a failed change shows the alert and no success message, an Editor opening the admin first applies nothing and sees nothing, and the message is not shown on public pages

Not in scope: a screen listing migrations, an email, or any change to how or when the changes are applied.

### LF-085. The first update on an older site leaves out the release's new plugins and themes

**Status:** Complete (4 of 4) — released in 1.3.0

Found 2026-11-08 when 1.2.0 was installed over a site by the GitHub updater: Rosewine and Lavender Mist were not added. `UpdateService::planPackages()` tells a package the administrator removed from one the release added by reading `storage/updates/bundled.json`, which only an updater run writes. A site that had never run the updater had no record, so every missing package was treated as removed. Built 2026-11-08.

- [x] With no record, only the packages that releases before the record shipped (Visitor Stats and Dummy Content; Storybook is always installed) count as removed when missing; any other missing package is added
- [x] A site with a record behaves exactly as before
- [x] Regression test: no record, a release with the two older plugins, a new theme and a new plugin, leaves out the first two and installs the others; the existing no-record test still passes
- [x] CHANGELOG entry with the way to add the two themes by hand to a site already updated

Not in scope: re-offering the two themes to a site already updated (its record now lists them as shipped, so they are added by hand), and a screen to restore removed bundled packages.

### LF-086. Gradient themes: card for a one-chapter story and a separate panel for its details

**Status:** Complete (4 of 4) — released in 1.3.0

Reported 2026-11-08 from the live site: on a story read on its own page (one chapter, Completed) the text sat loose on the page in Rosewine and Lavender Mist, without the card a chapter page has, and the details above looked the same as the text. Built 2026-11-08; browser-checked on the dev site in Rosewine (light). Lavender Mist carries the same markup and rules with its own accent (a bar along the top edge, as on its story cards); not seen on screen. Storybook is unchanged: it has no card.

- [x] `story.php` of both themes: one `lf-story__details` wrapper around the meta list, term groups and summary, notes and disclaimer, and a `lf-story__text-header` ("The story" and the word count) inside the one-chapter text section
- [x] Both stylesheets: the text section is the chapter card (gradient wash heading, border, radius, shadow), the details are a flat tinted panel with an accent bar; tokens only, so dark mode follows; forced-colours borders
- [x] Updated CHANGELOG `[Unreleased]`; nothing to propagate to `custom themes/` (no theme there uses these templates)
- [x] Existing theme tests pass (`GradientThemesTest`, `EndNotesIntegrationTest`); the dev install is on par

Release prerequisite: bump the `Version:` of Rosewine and Lavender Mist (both changed since 1.2.0). Not in scope: the same panel for Storybook and the built-in theme.

### LF-087. Updates screen: readable release notes and the same information as Lumora Gallery's

**Status:** Complete (9 of 9) — released in 1.3.0

Two complaints from 2026-11-08. First, the release notes on the Updates screen are the raw Markdown text of the GitHub release (`# 🚀 What's New…`, `###`, `-` bullets) in a preformatted block, where Lumora Gallery shows them rendered as headings and lists. Second, the screen is a plain stack of panels, and Lumora Gallery's Updates page tells an Administrator more at a glance. Bring Lumora Fiction's screen to the same layout and the same information, in this project's own components and classes (the screen is an admin view, so the public-CSS rule does not apply, but the admin-styling rule of reusing core classes does) and without copying Gallery's code: the two share a design, not a schema or a class. The updater itself (staging, backup, apply, rollback) is not changed.

The Gallery page, top to bottom: a header strip of three groups (**Installed version** with the schema version and "up to date", and the install folder; **Update status** as a coloured badge with the release channel and when the last check ran; **Update source** with the provider, the repository and a link to all releases); the **GitHub** and **Manual Update** tabs (Lumora Fiction already has these, LF-074); **Latest release** (title and version with a Stable badge, the published date, buttons **View release notes on GitHub**, **Download release ZIP** and **Re-download release**, and the rendered notes in a scrolling box); **Check for Updates** with a sentence saying exactly what is sent; **Database Updates** (schema status and how many changes are applied); **Full Backups** (a list with version, date, size and Download, Restore and Delete, and **Back up now**); **System status** (a pass or fail table: PHP version, ZIP extension, cURL, file permissions, free disk space, temporary folder); **Update settings** (release channel, automatic check on or off, check frequency, optional GitHub token); **Update History** (version, date, result); and an **About Updates** list of plain notes.

- [x] Render the release notes as formatted text instead of showing Markdown: headings, bullet lists, bold, inline code and links, through the existing `MarkdownParser` (which never passes raw HTML through) and `HtmlSanitizer`, so a release body from GitHub can never put a script or an external image on the screen; links open with `rel="noopener noreferrer"`, images are not loaded (GitHub bodies may carry badges), and an unparseable body falls back to plain preformatted text; the notes sit in a box that scrolls past a fixed height, with dark-mode styles
- [x] A header strip in three groups as above: installed version, schema version (the newest applied migration against the newest on disk, with "up to date" or "changes waiting") and the install folder, which System Information already shows privately; update status as a badge (up to date, update available, not checked, check failed) with the channel and the time of the last check; and the update source (GitHub, the repository from the settings, a link to its releases)
- [x] **Latest release** panel: release name, version, a Stable or Pre-release badge, the published date, **View release notes on GitHub**, **Download release ZIP** and **Re-download release**; the download buttons keep the existing rules (the checksum must be published or the download is refused, nothing is installed before the review screen), and a release that is not newer than the installed version offers **Re-download release** as a way to reinstall it only if the updater's version rule allows that, otherwise the button is left out and the reason is not hidden
- [x] A **Database updates** panel (schema status and the number of changes applied) and an **Update checks** sentence saying that only a plain request to the release feed is made and no site content, user data or identifying information is sent
- [x] A **System status** table with a Pass or Fail and a detail for each check the updater already makes before it starts (PHP version against the release's minimum, the ZIP and cURL or stream support it uses, whether the web server can write the site folders, free disk space, whether the staging folder is writable), reusing `CompatibilityChecker` and `FileSync::assertWritable()` rather than a second set of checks, so the table and the review screen can never disagree; a failing row says what to change
- [x] Backups on this screen: the three most recent backups (version, date, size, Download, Restore, Delete) with **Back up now** and a link to **Maintenance > Backups** for the full list, reusing the existing `BackupService` and its confirmation pages and refusing nothing new; no second copy of the backup list logic
- [x] Update settings gain two fields (decided 2026-11-08, Ariane said yes to both; the screen's current line "Updating never happens by itself" stays true for installing, so reword it to say nothing is ever installed without a confirmation): **Automatic check** on or off (off by default) with a frequency (daily or weekly), done opportunistically the next time an Administrator opens the admin once a check is due, never by a background job, never installing anything, and a failed check recorded as it is today and not retried on every page; and an optional **GitHub token** (stored in the settings like other secrets, shown only as "Not set" or "Set", never echoed back or written to a log, blank keeps the current value with a separate way to clear it, used only to lift GitHub's unauthenticated rate limit, and sent only to GitHub); an available release then shows a notice on the dashboard and in the Updates menu entry for Administrators only (this replaces the "dashboard notice" listed as out of scope below); record the decision in `DECISIONS.md`
- [x] The history table gains the version reached (or attempted) and a plain result sentence in the same columns as Gallery's (Version, Date, Result), keeping the Note column for failures, and an **About updates** list at the bottom with the plain notes that apply to this project (what is kept, what a rollback restores, the SHA-256 check, the install folder that is left alone), written for an Administrator and without internal names
- [x] Tests and documentation: release notes with headings, lists, links, a script tag, an image and raw HTML render safely (nothing executable, no image request); the header strip and status badge for up to date, update available, never checked and failed check; the system status table agrees with the review screen's checks for a failing and a passing host; Back up now, Download, Restore and Delete from this screen behave exactly as on the Backups screen; keyboard use of the tabs is unchanged; `docs/ADMINISTRATION.md` and the CHANGELOG describe the screen; no ticket ID or internal class name appears in any visible string

Not in scope: changing how updates are staged, applied or rolled back, updating one plugin or theme on its own (still deferred), and a signed-package check.

## Version 1.2.0 — 2026-11-08

### LF-016. Privacy page and data requests

**Status:** Complete (5 of 5) — released in 1.2.0

Open registration collects email addresses. Modelled on Lumora Press's privacy settings, copied rather than shared. M4 milestone.

- [x] Settings › Privacy: name an existing page as the privacy policy, exposed to themes through a template tag and linked from the registration form
- [x] Administrator tool to look up an account by email and export its data as JSON
- [x] Account deletion that removes personal data while handling the account's stories according to a configurable choice (delete, or keep under a neutral author name)
- [x] Account deletion also calls `BookmarkService::deleteForUser()` and `ReadingProgressService::deleteForUser()` (built and tested in LF-031), and the data export includes the account's bookmarks and saved reading preferences
- [x] Tests that exports and logs never include password hashes or other accounts' data

Built 2026-11-06. Beyond the checklist: members can also download their data and delete their own account from **Your data and privacy** (`/account/privacy`, password-confirmed), `StoryFollowService::deleteForUser()` and notifications are removed too, and `UserStatus::Deleted` marks the anonymised row. See `DECISIONS.md` for why the row is anonymised rather than removed.

### LF-060. Option to turn off writer-proposed tags

**Status:** Complete (6 of 6) — released in 1.2.0

On a large open archive, free-form tags can sprawl quickly (near-duplicates, one-off sentences, spelling variants). A site that wants a tidy, curated vocabulary should be able to switch writer proposals off for tags so that only what an Administrator adds is available. Today `TermType::writersMayPropose()` hard-codes tags (with fandoms, characters and relationships) as proposable; this ticket makes tags configurable. Fandoms, characters and relationships are deliberately out of scope (see `DECISIONS.md`). Default stays **on**, so existing installs behave exactly as before.

- [x] Setting under Settings › Archive: "Writers may propose new tags" (default on), stored through `ArchiveSettings` and validated like its neighbours
- [x] When off, `TaxonomyService` refuses to create a tag from a writer's story submission: only an existing tag or alias resolves, and an unknown name gives the same "choose one from the list" error the closed kinds already give
- [x] The story form drops the free-text tag entry for writers when off and offers a pick list of the Administrator's tags instead; the suggest endpoint and `TermSuggestController` stay consistent with that
- [x] Administrators and Editors can still add tags under Classification, and an Administrator's tag list switches to manual ordering when off, as the other curated kinds do
- [x] Turning the option off never deletes or hides tags that writers already proposed; they stay attached to their stories and remain in the list, and the unreviewed ones can still be reviewed, merged or aliased
- [x] Tests: proposal refused when off and allowed when on, existing tags and aliases still resolve, a hand-crafted POST cannot create a tag when off, and the setting survives a round trip through the settings screen

Built 2026-11-06. `TaxonomyService::writersMayPropose(TermType)` is the one settings-aware check; the enum method stays the fixed rule. Two limits to know: the Classification screens need `ManageSite`, so Editors could not add tags there before and still cannot (the ticket's mention of Editors is not met by a role that has no access to the screen), and the story form's pick list shows at most 200 tags, which a very large curated vocabulary would outgrow (the list helper caps a page at 200); LF-061 (term import) is the natural place to give the pick list a search box if that is ever needed.

Touch points found while scoping: `TermType::writersMayPropose()` and its roughly ten callers in `TaxonomyService`, `StoryController`, `TermSuggestController`, `TaxonomyController` and `admin/views/taxonomy-list.php`. Because the enum method has no access to settings, the cleanest route is a settings-aware check in `TaxonomyService` (for example `writersMayPropose(TermType)`) that callers use instead. The hint text in `admin/views/settings-archive.php` that says writers propose tags must change with it. Update `README.md` (user-facing setting) and `docs/CHANGELOG.md` when implemented.

### LF-062. Static pages

**Status:** Complete (10 of 10) — released in 1.2.0

Administrators need standalone pages such as About, FAQ, Terms of Service and Privacy Policy that readers can reach from the public site. The themes already ship a `page.php` template for plain text pages, but nothing creates or stores pages yet. LF-016 depends on this ticket: it lets the administrator name one of these pages as the privacy policy.

- [x] Migration and `PageService` (business logic and queries live there, not in the controller): title, slug, content, status (draft or published), menu placement, sort order, created and updated timestamps, and the author who last edited it
- [x] Admin screen under the site-content area: list, create, edit, and delete pages (delete is confirmed), restricted to `ManageSite`, CSRF-protected on every state change
- [x] Page content goes through the same editor and sanitiser pipeline as chapter text (WYSIWYG, Markdown, HTML, plain text), so pages cannot carry scripts or unsafe markup
- [x] Slug generated from the title and editable, unique among pages, and refused when it would collide with a reserved route (browse, search, news, account, admin and the like) or an existing top-level address
- [x] Public route serving a published page at its slug through the theme's `page.php`; drafts and unknown slugs give the 404 page, and a draft is previewable by an administrator only
- [x] Placement: each page can be shown in the main menu, in a footer links list, in both, or neither, in an administrator-chosen order; the main menu uses the existing navigation filter, and the footer list is exposed through a template tag so every theme can print it
- [x] Footer links list added to the Storybook footer (and the Classic Archive theme when LF-033 exists) with its CSS classes and dark-mode rules, and propagated to every theme under `custom themes/`
- [x] Page title and meta description from the page, a canonical URL, and a `noindex` option per page; published pages included in the sitemap once LF-014 exists
- [x] Seed suggestions: on the empty list screen, offer one-click starter drafts for About, FAQ, Terms of Service and Privacy Policy with neutral placeholder text for the administrator to edit, never published automatically
- [x] Tests: a draft is never public or in the menus, a non-admin cannot reach the screen or POST, reserved and duplicate slugs are refused, unsafe markup in content is stripped, menu and footer ordering is respected, and deleting a page removes it from the menus

Built 2026-11-06. The address is `/{slug}`, one path segment, served by a route registered after every plugin route; "already served" is checked against the router when a page is saved. The built-in theme got the footer list too. `custom themes/` does not exist, so nothing was propagated; the Classic Archive theme (LF-033) needs the `lf_footer_links()` call in its footer. The sitemap half of the metadata item belongs to LF-014: published, non-`noindex` pages go in it (`PageService::published()` returns them).

Not in scope: nested page hierarchies, per-page templates beyond the theme's `page.php`, revisions, and a general menu builder (a page's placement flags cover the menus for now). Update `README.md` (user-facing screen), `docs/THEME-DEVELOPMENT.md` (footer links tag) and `docs/CHANGELOG.md` when implemented.

### LF-063. Session handler breaks on PHP 8.6

**Status:** Complete (5 of 5) — released in 1.2.0

Found by adding PHP 8.6 (release candidate) to the test matrix. `DatabaseSessionHandler` implemented `SessionHandlerInterface` and `SessionUpdateTimestampHandlerInterface` but not `SessionIdInterface`. PHP 8.6 deprecates a save handler without `create_sid()`, and the application's error handler turns every deprecation into an exception, so on 8.6 every request that starts a session answered 500 (804 deprecations, 29 end-to-end failures and 2 warnings in the suite). `validateId()`, `updateTimestamp()` and `session.use_strict_mode` were already in place.

- [x] `DatabaseSessionHandler` implements `SessionIdInterface`; `create_sid()` returns `bin2hex(random_bytes(16))` and never calls `session_create_id()`, which calls back into the handler and recurses
- [x] Regression test: the handler is a `SessionIdInterface`, identifiers are 32 hex characters, unique, and validate only once written (`DatabaseSessionHandlerIntegrationTest`)
- [x] The dev install's copy of the file is in sync, and the dev site answers 200 again
- [x] The full suite on PHP 8.6 with warnings and deprecations displayed is clean
- [x] The 8.2 to 8.6 matrix passes and is logged in `TEST_LOG.md`

The `ReflectionMethod::invokeArgs()`-on-a-static-method pattern was searched for and does not occur in the application, plugins or tests.

### LF-064. Chapter end notes and story end notes

**Status:** Complete (11 of 11) — released in 1.2.0

Writers often want a note after the text as well as before it: a cliffhanger apology, thanks, a pointer to the next update, or a closing word once the whole story is finished. Today a chapter has one `notes` field and the story has one `notes` field, and the Storybook theme prints the chapter's notes above the text only. This ticket keeps the existing fields as the opening notes (no rename, no data change for existing stories) and adds a separate end note to each, so a writer can use either or both.

- [x] Migration adding a nullable `end_notes` column to `chapters`, `chapter_revisions` and `stories` (text, same length family as `notes`); existing rows are untouched and shown exactly as before; migration and rollback tested
- [x] `ChapterService` and `StoryService` validate and store `end_notes` with the same rules as `notes` (UTF-8, control characters refused, trimmed, line endings normalised), with new `END_NOTES_MAX` limits; the value is carried by `Chapter` and `Story` and by story duplication
- [x] Chapter revisions keep the end note, and restoring a revision restores it, as for `notes`; the revision screen shows it
- [x] The chapter form gets an "End notes" textarea after the text editor, and the story form an "End notes" field after "Author's notes", with hints saying where each is shown; the autosave and unsaved-changes warning cover the new field
- [x] Chapter notes keep today's placement above the text and chapter end notes are printed below it, before the bottom chapter navigation; story end notes are shown on the story page after the chapter list, and on the last chapter's page only when the story's work status is complete
- [x] Template fields `chapter.end_notes` and `story.end_notes` (available to `lf_has()` and `lf_field()`, escaped like `notes`), with the markup carrying stable `lf-chapter__end-notes` and `lf-story__end-notes` classes
- [x] Styling in the Storybook stylesheet with dark-mode rules added together with the component, reusing the look of `.lf-chapter__notes`; propagated to every theme under `custom themes/` in the same session (add missing rules only), plus the Classic Archive theme when LF-033 exists
- [x] The chapter and story views in the full-story reading mode, the print layout, and the story downloads (EPUB, PDF, HTML) include the end notes
- [x] Draft privacy: unpublished chapters and drafts never expose their end notes through the public pages, search or downloads
- [x] Search indexes the end notes only if it already indexes `notes`; no new search behaviour is introduced
- [x] Tests: end notes save, validate and round-trip for chapters and stories; revision restore brings them back; they render below the text and after the chapter list in the right places; a story end note appears on the last chapter only when complete; empty end notes print nothing; markup is escaped; existing rows and revisions without an end note still render and save; the migration upgrades an install that already holds stories

Built 2026-11-06. The rule "story end note on the last chapter only when complete" lives in `StoryPresenter::chapter()`, which blanks `story.end_notes` otherwise, so a theme just prints the field. `custom themes/` does not exist, so nothing was propagated. Still to do when those tickets are built: the exports in LF-013 and the RSS chapter items in LF-012 must include the end notes (chapter end note after the text; a story's own end note on the story item), and feeds and the sitemap (LF-012, LF-014) must keep draft chapters' end notes out. There is no rollback script for a migration in this project (the updater restores the database dump instead), so "rollback tested" is covered by the updater's own restore tests.

Not in scope: rich-text or formatted notes (notes stay plain text like the existing field), separate notes per chapter position beyond start and end, and per-reader hiding of notes. Update `README.md` (user-facing fields), `docs/THEME-DEVELOPMENT.md` (the two new template fields and CSS classes) and `docs/CHANGELOG.md` when implemented.

### LF-065. Staff-created and staff-edited terms are approved automatically

**Status:** Complete (7 of 7) — released in 1.2.0

A term is "not yet reviewed" when a writer proposes it, and an Administrator approves, merges or aliases it later. That queue should hold only writers' proposals. Today `TaxonomyService::create()` and the starter-list path already insert reviewed terms, but `assignToStory()` goes through `selected()` and `propose()`, which always inserts an unreviewed term, even when the person saving the story is an Administrator or Editor. A staff member who types a new fandom, character or tag into a story form therefore lands in their own review queue and has to approve their own entry. Editing a term through `rename()` or the other edit paths also leaves an unreviewed term unreviewed.

- [x] A term proposed through a story by an actor who may curate is inserted already reviewed (`reviewed = 1`), for every kind that writers may propose; a writer's proposal stays unreviewed exactly as today
- [x] Decide and document who counts as staff here: Administrators (`ManageSite`) at minimum, and Editors if they are allowed to curate classification; `requireCurator()` is currently `ManageSite` only, so this ticket either keeps it that way or adds a narrower "approve terms" capability (record the decision in `DECISIONS.md`)
- [x] Renaming, re-parenting, describing, or adding or removing an alias on an unreviewed term by staff marks it reviewed in the same transaction, since staff have then looked at it; editing an already reviewed term changes nothing about its status
- [x] A staff edit never marks a term reviewed when the edit fails validation, and merging into another term keeps the target's own status
- [x] The audit entry records the creation or edit as it does today, with a flag or action that shows it was approved on the spot (for example `term.propose` with `reviewed: true`), so the log still tells a staff creation apart from a writer proposal
- [x] The unreviewed counts on the Classification overview and filters, and the public indexes that only list reviewed terms, stay correct: a staff-created term appears on its public index as soon as it has a public story
- [x] Tests: an Administrator (and an Editor, if included) saving a story with a new tag, fandom, character and relationship creates reviewed terms; an Author doing the same creates unreviewed ones; a hand-crafted POST by an Author cannot get a term reviewed; staff renaming or adding an alias to an unreviewed term approves it; editing a reviewed term leaves it reviewed; the overview counts and audit entries match

Built 2026-11-06. Staff means `TaxonomyService::canCurate()`, which is `ManageSite` (Administrators); Editors are not included because they cannot curate classification today, so what an Editor proposes is reviewed by an Administrator like a writer's. Re-parenting does not exist as an operation, so it is not covered. In the Classification edit form the **Reviewed** tick box still wins over the automatic approval: the rename happens first and the box is applied after, so an administrator who unticks it keeps the term unreviewed.

Not in scope: changing what writers may propose (LF-060) and bulk approval of the existing unreviewed terms, which `setReviewed()` already does one at a time. Existing unreviewed terms are not changed by the migration of behaviour. Update `README.md` (what the unreviewed list contains), `docs/DEVELOPER-APIS.md` if a hook or capability is added, and `docs/CHANGELOG.md` when implemented.

### LF-066. Detect a moved site and offer to update its address and path

**Status:** Complete (9 of 9) — released in 1.2.0

After a site is moved (a new domain, a switch to HTTPS, a different subdirectory, or a new server folder), `site_url` in `config/config.php` still holds the old address, so emailed links, feeds and the HTTPS alert point at the wrong place until someone edits the file by hand. The install location is not recorded anywhere, so a changed folder cannot be noticed at all. This ticket records where the site was installed or last confirmed, notices when the current request or folder no longer matches, and offers the administrator a one-click correction. The address is never changed automatically: `site_url` is deliberately never derived from the request, because a forged Host header must not be able to redirect a password-reset link.

- [x] Record the confirmed install location (the public address and the absolute filesystem path of the site root) at install time and whenever an administrator confirms a change; stored where `config.php` is not needed to read it, so a restored copy of the files from another server still carries the old values to compare against (decide between a settings row and a small file under `storage/`, and record the choice in `DECISIONS.md`)
- [x] Existing installs that have no record yet get one the first time an administrator opens the admin, taken from the current configuration, without raising an alert
- [x] Detect a changed filesystem path (the recorded root differs from the real path of the running site) and a changed public address (the address the administrator is using differs from `site_url` in scheme, host, port or subdirectory), checked on admin requests only so public pages pay nothing; both comparisons are normalised (case, trailing slash, `www.` only if it is treated as the same site, symlinked folders resolved with `realpath()`)
- [x] Never trust the request for anything but the comparison: the proposed address is shown for the administrator to review and edit, honours the trusted-proxy setting for HTTPS detection, and is refused unless it is a well-formed `http` or `https` address with no query or fragment
- [x] A site alert (`CoreAlerts`) appears when a mismatch is found, with a link to a confirmation screen showing the old and new values side by side, an editable address field, and a plain explanation of what each change affects (emailed links, feeds, the sitemap, canonical URLs)
- [x] The confirmation screen is behind `ManageSite`, CSRF-protected and writes through the configuration writer already used by the installer and settings; if `config/config.php` is not writable it says so and shows the exact line to change by hand instead of failing; the change is written to the audit log with the old and new values
- [x] A "Keep the old address" choice dismisses the alert for the current address so a site reachable under two names (a staging alias, a CDN, a temporary host) is not nagged on every visit, and can be reset
- [x] Nothing else is rewritten: stored content, links inside chapters and cached files are left alone, and the screen says so; caches that embed the address (LF-017 once it exists) are cleared when the address changes
- [x] Tests: a matching install raises nothing; a changed host, scheme, port or subdirectory raises the alert; a changed root path raises it; a forged Host header on a non-admin request changes nothing; a non-administrator cannot reach the screen or POST; a malformed or non-http address is refused; an unwritable config shows the manual instruction; confirming updates the address and records an audit entry; "keep" suppresses the alert for that address only; existing installs without a record are seeded silently

Not in scope: automatic rewriting of absolute links inside story content, moving the database or files, and changing the database table prefix or secret key (both must never change after install). Update `README.md` (what the alert and screen do), `docs/TROUBLESHOOTING.md` (moving a site to a new host or domain, including the manual `config.php` edit) and `docs/CHANGELOG.md` when implemented.

Built 2026-11-06. The record is the `site.location` setting; the screen is `/admin/maintenance/site-location`, opened from the alert. Caches that embed the address do not exist yet (LF-017 must clear them on a change).

### LF-067. Visitor Stats counts signed-in readers too

**Status:** Complete (6 of 6) — released in 1.2.0

The Visitor Stats plugin (LF-054) counts a view only when the reader is not signed in: `Tracker::handle()` returns early when `$viewer !== null`, and the plugin's README lists "Signed-in people, including you and the story's own author" among the things that never count. Most of an archive's regular readers are signed in (reading lists, bookmarks, follows), so the totals understate real readership. A signed-in reader opening a story or chapter should count exactly like a visitor who is not signed in.

- [x] `Tracker::handle()` no longer skips a view because `$viewer` is set; the other rules stay as they are (only `GET`, no background prefetch, no search robots, only the plugin's own switch), and the `story_viewed` action itself is unchanged since it already passes the viewer
- [x] Privacy stays as it is: nothing about the signed-in person is stored or used, not the account, id, name or role, so a view row still belongs to no one; `$viewer` is read only to be ignored, and the plugin's privacy wording says plainly that signed-in readers are counted anonymously in the same totals
- [x] Decide whether the story's own author, and staff previewing their own work, should count; the default is that they count like anyone else, since the totals cannot tell them apart without storing who looked, and the decision (with the option of a "do not count my own views" setting that skips the signed-in author of that story) is recorded in `DECISIONS.md`
- [x] The plugin's README and the screen's explanatory text are corrected: signed-in people are counted, and the "never count" list keeps previews, drafts, pending and unpublished stories, robots and prefetches
- [x] Tests: a signed-in reader's story view and chapter view each add one view; a reader who is not signed in still adds one; a signed-in view adds nothing to the breakdowns beyond browser, device, referrer and country as before; prefetches, robots and non-`GET` requests from signed-in people still do not count; no stored row carries an account identifier
- [x] A one-line note in `docs/CHANGELOG.md` that earlier totals only held readers who were not signed in and are not recalculated, since the past views of signed-in readers were never recorded

Not in scope: per-reader or unique-reader counts, reading history, and any breakdown of views by signed-in versus not signed in (that would need a flag stored on each view and is a separate privacy decision). Bump the plugin's `Version:` header when implemented, and update the `story_viewed` row in `docs/DEVELOPER-APIS.md` only if its wording implies guests alone.

Built 2026-11-06. The plugin's `Version:` is bumped at Release.

### LF-070. Chapter comments by readers

**Status:** Complete (15 of 15) — released in 1.2.0

Readers can leave a comment under a chapter. `Project Architecture.md` deferred comments until the core publishing and reading workflow was solid, and `Project Plan.md` lists them with moderation, reporting and spam controls. The follow, notification and spam-guard services now exist, so this ticket reuses them rather than building new ones. Comments are plain text, flat (no threading), and attached to a chapter; the author answers with an ordinary comment. A reader who has not signed in cannot comment.

- [x] Migration adding a `comments` table (chapter, story, author account, body, status of published, pending, hidden or spam, created and edited timestamps, edited flag) with indexes for the chapter listing, the moderation queue and a reader's own comments; a comment survives its author's account being anonymised per the account-deletion rules; migration and rollback tested
- [x] `CommentService` for create, edit, delete and listing, with the body validated like other plain text (UTF-8, control characters refused, trimmed, line endings normalised, a `BODY_MAX` limit and a minimum of one visible character); output is escaped and links are never made clickable by default
- [x] Permissions enforced on the server: any signed-in, verified Reader, Author, Editor or Administrator may comment on a chapter they can read; a comment on a draft, unpublished, pending or private chapter is refused even by a hand-crafted POST; an author can delete or hide comments under their own stories but cannot edit anyone's words; staff can moderate every comment; a commenter can edit their own comment for a short window (a setting) and delete it at any time
- [x] Reuse the spam controls: comment posts go through `FormGuard` (honeypot, timing, throttle and the optional Akismet check configured in Spam settings), with a per-account rate limit and a refusal of an exact duplicate posted again within a few minutes; a suspected comment is held as pending or marked spam rather than dropped, and the reader is told plainly
- [x] Moderation setting under Settings: comments on or off for the whole site, who may comment (anyone signed in, or only readers whose first comment was approved), whether a first comment is held for approval, and whether an author may turn comments off per story or per chapter; switching comments off hides the form and the list without deleting any comment
- [x] Per-story and per-chapter option, set by the author, to turn comments off; the chosen state is shown to readers as "Comments are turned off" instead of the form
- [x] A Comments screen in the admin under Moderation where an Administrator sees every submitted comment on the site in one list, whatever its status (published, pending, hidden, spam, reported), newest first and paginated for archives with tens of thousands of comments; status tabs with live counts (the pending and reported tabs carry a badge in the admin menu and on the dashboard so nothing waits unseen); filters by status, story, chapter, commenter, date range and text search; each row shows the comment (shortened, expandable), the commenter's display name and slug, the story and chapter with a link to the comment in place, its date, its status and its report count; single and bulk actions to approve, hide, mark as spam, restore and delete (with a confirmation and a preview of how many are affected); an inline edit-free "view in context" and a commenter's history link showing all their comments and how many were held or marked spam, with a one-click action to hold or block that account's future comments; an Editor sees the queue for stories they may moderate and an Author sees only comments on their own stories; every moderation action is written to the audit log
- [x] Reporting: a signed-in reader can report a comment with a short reason, a comment reported more than a set number of times is hidden pending review, and reports land in the moderation screen with the reporter and reason (the reporter is never shown to the commenter)
- [x] The comment list on the chapter page (oldest first, paginated, with the commenter's display name and slug and never the login name), the form below it, and a comment count on the chapter and story pages; hidden, spam and pending comments never appear to anyone except their author (pending, marked as awaiting approval) and staff
- [x] Author badge: a comment written by the account that owns the story is marked with an "Author" badge beside the name, so readers can tell the writer's replies at a glance; comments are flat, so any comment by the story's author counts as a reply and no reply link is stored; the badge is worked out when the list is built by comparing the commenter to the story's owner (never a stored flag, so a changed owner or an anonymised account stays correct), it is real text and not colour alone with a screen-reader label, it appears in the chapter list, the moderation screen and the notification email, and a theme restyles it through a stable `lf-comment__badge` class; staff who are not the story's author get no badge
- [x] Notifications and follows: the chapter's story author gets a notification and optionally an email for a new published comment (using `NotificationService` and the existing email settings), a commenter can choose to be told of replies from the author, and no email is sent for a pending, hidden or spam comment; account preferences can turn these off
- [x] Templates, template tags and the `lf-comment` CSS classes in the Storybook stylesheet with dark-mode rules added together with each component (list, single comment, form, notice, report control), propagated to every theme under `custom themes/` in the same session (add missing rules only), plus the Classic Archive theme when LF-033 exists; the form is output through a documented hook or template tag so a theme never re-implements the CSRF or spam-guard fields
- [x] Privacy and caching: comments are not served from a page cache (LF-017) to a signed-in reader, comment text is excluded from search unless a later ticket adds it, a reader's data export and account deletion include or anonymise their comments, and the profile screens show nothing extra about a commenter
- [x] Feeds (LF-012) gain an optional per-story comment feed of published comments only; the setting that switches it off is documented, and `docs/DEVELOPER-APIS.md` gains the new actions and filters (for example `comment_created`, `comment_status_changed` and a filter on the body before storing)
- [x] Tests: validation and round-trip; a hand-crafted POST cannot comment on a draft, unpublished or private chapter, edit someone else's comment, or moderate without permission; an Administrator sees comments of every status and every story, and the tab counts, filters and bulk actions give correct results and need `ManageSite` or the narrower moderation permission; the Author badge shows only on comments by the story's owner and not on a reader's, an Editor's or an Administrator's comment on someone else's story, and follows an ownership change; an Author cannot see or moderate comments on another author's story; held, hidden and spam comments never show to readers or in feeds, counts or notifications; the spam guard and rate limit refuse a flood; reporting hides a comment at the threshold; comments off per site, story and chapter hides the form; editing window; notification and email only for a published comment; anonymising an account keeps its comments; the migration upgrades an install that already holds chapters

Not in scope: threaded replies, reactions or likes, ratings and reviews, guest comments, comments on stories or series pages (chapters only), attachments or images, Markdown or rich text in comments, mentions, and comment search. Update `README.md` (user-facing comment settings, the reader's form, the moderation screen), `docs/THEME-DEVELOPMENT.md` (comment template tags, hook and CSS classes), `docs/DEVELOPER-APIS.md` and `docs/CHANGELOG.md` when implemented.

Built 2026-11-06. The per-story comment feed item moved to LF-012 (no feed exists yet) and comment text is not searched. The first-comment hold and "who may comment" are one control (see `DECISIONS.md`). Per-story and per-chapter switches live in their own table and are set from a small form on the story and chapter screens. Page caching (LF-017) must keep comment pages private for signed-in readers, which the existing private-page rule already does.

### LF-071. Backup archives carry the script name

**Status:** Complete (7 of 7) — released in 1.2.0

A backup is stored under `storage/backups/` as `{id}.json`, `{id}-files.zip` and `{id}-database.sql` (or `.sql.gz`), where the id looks like `20261105-123456-a1b2c3d4e5f6`, and the download sends the same name. Once the files are copied off the server or sit in a downloads folder, nothing in `20261105-123456-a1b2c3d4e5f6-files.zip` says which program or site it belongs to. Backup archives should start with the script name, as `LumoraFiction_20261105-123456-a1b2c3d4e5f6-files.zip`, so they can be told apart from other archives at a glance.

- [x] New backups are written with the script name first, `LumoraFiction_{id}-files.zip`, `LumoraFiction_{id}-database.sql[.gz]` and `LumoraFiction_{id}.json`, built in one place (`BackupService`'s path helpers) from a single constant so the prefix is never typed twice; the id itself, `ID_PATTERN`, the audit entries and the URLs stay exactly as they are, since the prefix is part of the file name only and never of the id
- [x] Backups made before this change keep working: listing, finding, verifying, downloading, restoring and deleting accept the old names (`{id}-files.zip`) as well as the new, deciding by what exists on disk, and nothing renames or rewrites an existing backup; the path helpers never trust a name from the request and the id is still validated against `ID_PATTERN` before any path is built
- [x] The download sends the prefixed name for both old and new backups, so a downloaded older backup is also named `LumoraFiction_...`, while the file on disk of an old backup is left as it was
- [x] The pre-update and pre-restore safety backups made by `UpdateService` use the same naming and show up in the backups list as before; the glob that finds records (`*.json`) and the deletion routine cover both name shapes, and a leftover partial file of either shape is cleaned up
- [x] Anything that shows a file name (the backups screen, the update and restore progress screens, and the system information page) displays the new name, with the id column unchanged
- [x] Tests: a new backup creates the three prefixed files and the id is unchanged; an old-style backup is still listed, verified, downloaded under the prefixed name, restored and deleted; a mixed folder lists both; a hand-crafted id or part cannot reach another file or a path outside the backups folder; an update's safety backup uses the new names and restores from them
- [x] Documentation: `docs/TROUBLESHOOTING.md` (how to recognise and restore a backup by hand from the new file names, including old backups), the backups paragraph in `README.md` if it names the files, and `docs/CHANGELOG.md`

Not in scope: adding the site name or domain to the file name, renaming or migrating existing backups, and changing the backup contents, the id format or the storage folder. The prefix is the script name, `LumoraFiction`, in that capitalisation to match the release packages (`LumoraFiction-v{version}.zip`) with an underscore before the id as requested.

### LF-072. Admin banner while maintenance mode is on

**Status:** Complete (6 of 6) — released in 1.2.0

Maintenance mode takes the public site offline for visitors while administrators keep working, so an administrator signed in during maintenance sees the normal site and admin and can forget that visitors are being turned away. Nothing in the admin says so: `admin/views/layout.php` and the dashboard have no reference to it, and the only place it shows is the Maintenance mode settings screen. A scheduled window that has quietly started, or one left on by mistake, can keep the public site down unnoticed. The admin should say plainly, on every screen, that the site is currently offline to visitors.

- [x] A banner at the top of every admin page (rendered once in the admin layout so no screen can miss it and no plugin screen has to opt in) while `MaintenanceMode::isActive()` is true, saying that the public site is currently offline for visitors and that administrators still see it normally, with a link to the Maintenance mode screen to turn it off; shown only to people who can manage the site, but the layout banner must not depend on which screen is open
- [x] The banner covers the other cause too: the short-lived maintenance flag an update or restore raises while it runs (`MaintenanceFlag`) shows a banner worded for that case ("The site is offline while an update is running") with a link to the update progress screen, so the two states are told apart and the wording never suggests turning off a mode that an update is holding on
- [x] The text says whether the mode was switched on by hand or by a schedule, and for a schedule when it ends, using the site timezone already used by the Maintenance mode screen (no new setting; read from the existing values)
- [x] Styling in the admin stylesheet reusing the existing alert and notice components rather than a lookalike, a `role="status"` landmark with real text (colour alone never carries the meaning), keyboard-reachable link, dark-mode rules added with the component, and the banner not covered by or overlapping the sticky header on narrow screens
- [x] The dashboard shows the same state in its site-status area so it is visible at a glance, and the banner is not repeated as a Site Alert (Maintenance › Alerts stays for problems, this is a state), so the alert count and the alert email are unchanged
- [x] Tests: the banner appears on the dashboard and on an unrelated admin screen only when maintenance mode is active (manually or inside a scheduled window) or the update flag is set; it is absent otherwise, including after a schedule ends; the right wording and link are used for each cause; a person without site-management rights never sees the link; the public site and the maintenance page itself are unchanged

Not in scope: changing how maintenance mode works, who is let in, or the public maintenance page; a banner on the public site for administrators; and email or push notices when it turns on. Update `README.md` (the Maintenance mode section, one line) and `docs/CHANGELOG.md` when implemented.

### LF-073. Classification lists: multi-select, select all and approval status filter

**Status:** Complete (8 of 8) — released in 1.2.0

A classification list (fandoms, characters, relationships, tags and so on) shows its terms one per row, and each term is approved, merged, renamed or deleted from its own screen. The only filter is an "Only those not yet reviewed" checkbox, and the Status column says "Reviewed" or "Not yet reviewed". Clearing a queue of writers' proposals therefore means opening every term in turn. This ticket adds a checkbox on each row, a select-all control, bulk actions, and a clear Approved / Unapproved filter. The screen and the audit log use "approved" and "unapproved" wording; the stored `reviewed` column does not change.

- [x] A checkbox in each row of the term list with an accessible label naming the term, and a "select all" checkbox in the table header that selects every row on the current page; the header checkbox shows a mixed state when only some rows are ticked, works from the keyboard, and with scripts off the checkboxes still work as a plain form (select all is a progressive enhancement, so the list never depends on JavaScript to post)
- [x] An option to select every term that matches the current filter across all pages ("Select all 312 matching terms"), shown only when the match is larger than the page, confirmed with the exact count before anything runs, and capped by a server-side limit so one request cannot run unbounded; the action then acts on the filter as it stands at the time of the post, not on a stale list of ids
- [x] A bulk actions bar above and below the table with a count of what is selected: **Approve**, **Mark as unapproved** and **Delete**, each acting on exactly the selected terms; Delete goes through a confirmation page that lists the terms and the same rules as deleting a single term (a term still used by stories is refused or reassigned as the single-term screen does), and merging stays a single-term action
- [x] A status filter replacing the checkbox: **All**, **Approved** and **Unapproved**, with the counts shown beside each, kept together with the search box and in the pagination links; the old `?unreviewed=1` address keeps working and means Unapproved, so existing bookmarks and the links from the Classification overview do not break
- [x] Wording: the Status column and the term edit screen say **Approved** and **Unapproved** (replacing "Reviewed" and "Not yet reviewed"), and the overview counts and filter links use the same words; the choice is recorded in `DECISIONS.md`
- [x] Server side: bulk actions need the same capability as single-term curation (`requireCurator()`), are CSRF-protected and POST-only, accept only term ids that belong to the list's own kind (a hand-crafted id from another kind or a disabled kind is ignored and reported), run in one transaction so a failure changes nothing, and write one audit entry per term or one batched entry listing the ids and the action; unapproving never changes a term's aliases, parent or story links, and approving an already approved term is a harmless no-op that is not counted as a change
- [x] After an action the list reloads on the same filter and page, with a flash message saying how many terms were changed and how many were skipped and why; the selection is cleared, and the Classification overview's unapproved counts and the public indexes (which list approved terms only) are correct straight away
- [x] Tests: ticking rows and posting approves exactly those terms; select-all on a page covers the page only, and select-all-matching respects the filter and the cap; approve and unapprove change only the status; a hand-crafted POST by an Author or Editor without the right capability is refused; ids of another kind or a deleted term are skipped; the all-or-nothing transaction leaves everything unchanged when one term fails; bulk delete follows the single-term rules; the filter, counts and the `unreviewed=1` redirect behave; the screen works with scripts off

Not in scope: bulk merge, bulk rename, bulk editing of descriptions or parents, drag-reordering, and an undo for a bulk delete. This complements LF-065, which stops staff-created terms from entering the queue at all; the two do not depend on each other. Update `README.md` (the Classification screens), `docs/THEME-DEVELOPMENT.md` only if the public indexes change, and `docs/CHANGELOG.md` when implemented.

Built 2026-11-06.

### LF-074. Updates screen: GitHub and Manual Update in side-by-side tabs

**Status:** Complete (7 of 7) — released in 1.2.0

The Updates screen stacks its two ways of getting a release one above the other: a card to check GitHub and download, then a card to upload a release ZIP, then the settings and history. On a host that cannot reach GitHub the GitHub card is dead weight above the upload, and on a normal host the upload sits below the fold. Lumora Gallery and Lumora Press present the same choice as two pill tabs, **GitHub** and **Manual Update**, with one panel showing at a time. Lumora Fiction should match, so the three admin screens feel the same.

- [x] A tab bar at the top of the update area with two tabs, **GitHub** (check for a release, download and review) and **Manual Update** (upload a release ZIP with the optional checksum); only the selected tab's panel is visible, and the existing content of each panel is moved as it is, not rewritten
- [x] Built as a real tabs pattern: `role="tablist"`, `role="tab"` with `aria-selected` and `aria-controls`, `role="tabpanel"` with `aria-labelledby`, Left and Right arrow keys, Home and End to move between tabs, a visible focus ring, and a selected state shown by more than colour; the tab bar is horizontally usable on narrow screens
- [x] Works without JavaScript: each tab is a link to the same screen with a `?tab=github` or `?tab=manual` query, the server renders the chosen panel as selected and the other as hidden, and the script only enhances it by switching panels without a reload and updating the address; an unknown value falls back to GitHub, and the chosen tab survives the page reload that follows a check, a download or an upload
- [x] A sensible default and automatic switching: GitHub opens first; when an upload was refused or fails, or the Manual Update form returns an error, that tab is opened so the message is not hidden; when the host cannot reach GitHub (the last check failed on the transport) the screen opens on Manual Update instead, with the reason shown
- [x] The settings (repository and channel) and the history stay below the tabs on the page, visible whichever tab is open, because they apply to both ways of updating; if the upload section is not offered on a host (it is conditional today), its tab is not drawn rather than shown empty
- [x] Styling in the admin stylesheet with a stable `lf-tabs` family of classes reusing the admin's existing card and spacing, dark-mode rules added with the component, and a reusable partial so the next screen that needs tabs does not build its own; a plugin screen can use it too
- [x] Tests: the default tab, each `?tab=` value and an unknown one render the right panel as selected; an upload error or a failed GitHub transport opens the right tab; both forms still post to the same actions and everything after (review, run, history) is unchanged; the markup carries the tab roles and the labelling, and the screen is complete and usable with scripts off

Not in scope: changing how an update is checked, downloaded, uploaded, verified or applied, the review and run screens, and tabs on any other admin screen. Update `README.md` only if it describes the screen's layout, and `docs/CHANGELOG.md` when implemented.

### LF-075. Reorder the admin sidebar

**Status:** Complete (7 of 7) — released in 1.2.0

The Maintenance group in the admin sidebar has become a catch-all: Alerts, System Information, Updates, Backups, Maintenance mode, Plugins, Security events and Privacy requests. Things people open every day (Alerts), things that belong with another area (Privacy requests with Users, Plugins as its own area) and the routine maintenance tools are all mixed in one list. The menu is defined in `AdminMenu::definition()`; this ticket rearranges it without changing what any screen does.

- [x] **Alerts** moves out of Maintenance to its own top-level item directly under **Dashboard**, so a Critical or Warning alert is one click from the first screen; it keeps its capability (`ManageSite`) and its path, and carries the open-alert count as it does in the header link today (the count shown next to the sidebar item must come from the same source as the header count so the two never disagree)
- [x] **Maintenance** is reordered to start with **Updates**, **Backups** and **Maintenance mode**, in that order, followed by **Security events**, with **System Information** last; Alerts, Plugins and Privacy requests are no longer in this group, so the final order is Updates, Backups, Maintenance mode, Security events, System Information
- [x] **Users** becomes a group with two children, **All users** (today's Users screen) and **Privacy requests** (moved out of Maintenance, keeping its `ManageUsers` capability, so an administrator without site-management rights but with user management still reaches it); a person who can manage users sees the group even if they cannot see anything else in it
- [x] **Plugins** becomes its own top-level item instead of a Maintenance child; it sits directly after Appearance (confirmed), so the two things that change how the site looks and works sit together, and it keeps its `ManageSite` capability
- [x] Nothing that links to a screen breaks: every admin URL stays exactly as it is (`maintenance/alerts`, `maintenance/privacy`, `maintenance/plugins`), so bookmarks, emails, the dashboard links and the header alert link keep working; only the menu entry's slug and group change, so the "current page" highlight and the open or collapsed state follow the screen in its new place, and a stored collapsed-group preference for the old Maintenance group does not hide the moved items
- [x] Plugins that add to the menu keep working: the `admin_menu` filter and the slugs it sees are documented for the new layout, a plugin that attached a child to the `maintenance` group (the bundled Dummy Content plugin does this) is checked and still lands in a sensible place, and `docs/DEVELOPER-APIS.md` records which slugs moved, with the new group slugs for Users
- [x] Tests (`AdminMenu`): the top-level order is Dashboard, Alerts, then the rest as before with Plugins directly after Appearance; Maintenance lists Updates, Backups, Maintenance mode, Security events and System Information in that order; Privacy requests appears under Users and not under Maintenance; capabilities still filter each item, so an Editor or Author sees none of the moved items, a user manager sees Users and Privacy requests, and an administrator sees all of it; the current-page highlight works for each moved screen; plugin additions through the filter still appear

Not in scope: renaming screens, moving any URL, changing which screens exist, and redesigning the sidebar's look or its collapse behaviour. Update `README.md` only if it describes the menu layout, `docs/DEVELOPER-APIS.md` (moved slugs) and `docs/CHANGELOG.md` when implemented.

### LF-076. Drop PHP 8.2 support, minimum becomes PHP 8.3

**Status:** Complete (9 of 9) — released in 1.2.0. Matrix result is in `PHP Test Suite/TEST_LOG.md`.

PHP 8.2's security support ends on 2026-12-31, about eight weeks after this ticket was written, and the script is days old with one installation. Supporting a version that is about to be unsupported costs a longer test matrix and keeps newer language features off limits for no real gain. The minimum becomes **PHP 8.3**; 8.4, 8.5 and 8.6 stay supported. The trade-off, accepted on purpose: the project is built for shared hosting, and a few hosts still offer 8.2 as their newest option or let customers choose only older versions. State the change plainly in the release notes of the version that ships it.

- [x] The application refuses to run below 8.3 and says so: `index.php` (the `PHP_VERSION_ID` check and its "requires PHP 8.3 or newer" message) and `app/Core/Requirements.php` (`MINIMUM_PHP_VERSION`), and the installer's requirements check and its message follow from the same constant, not a second copy
- [x] The updater keeps working for the one real case: a release that raises the minimum is refused with the existing compatibility message on a host still running 8.2, before anything is replaced; confirm the shipped release that carries this change goes through that check and the message names 8.3
- [x] Bundled plugin and theme headers that say `Requires PHP: 8.2` (Visitor Stats and Dummy Content, and any bundled theme) become 8.3, with each package's `Version:` header bumped at Release as usual so the change is offered as an update
- [x] User documentation: `README.md` and `docs/INSTALLATION.md` (requirements table and the "tested on" sentence, with no hardcoded app version), and a `docs/CHANGELOG.md` entry that says 8.2 is no longer supported; `docs/THEME-DEVELOPMENT.md` and `docs/DEVELOPER-APIS.md` examples that use 8.2 as a sample `Requires PHP:` value are changed to 8.3 so they match the minimum
- [x] Project documents: the PHP compatibility rules in `CLAUDE.md` (primary 8.5, compatible with 8.3, 8.4 and 8.6, and the sentence about features that break the older versions), `Project Architecture.md`, `DECISIONS.md` (a new dated entry replacing "Target PHP 8.5, supported 8.2–8.5" and recording the reason and the shared-hosting trade-off) and `MEMORY.md`; the separate Guestbook memory that keeps 8.2 as its minimum is a different project and stays untouched
- [x] PHP Test Suite: `composer.json` (`php` constraint and the `platform` pin move to 8.3, and the lock file or autoload is regenerated if one is committed), `run-tests-all-php.sh` (the list of versions, its usage text and its "unknown version" message), and `docker/Dockerfile.test` (comment on the images built); the Docker image for 8.2 is no longer built or pulled
- [x] Test fixtures and expectations that use 8.2 are reviewed one by one and changed only where they were standing in for "the minimum": `Support/ReleaseZips::requirements()` default, the updater compatibility tests that build a release requiring a higher version than the host, the plugin and theme header tests that expect "Needs PHP 8.2+" and the parsed `requires_php` value; samples that only need *some* version are changed to 8.3 for consistency, and a test is added that asserts the application's own minimum matches `index.php`, `Requirements` and the bundled package headers so they can never drift apart again
- [x] Optional cleanup, only when it makes code simpler and never as a reason to churn files: look for 8.2 workarounds, polyfills or comments that mention 8.2 and remove them (for example typed class constants and `#[\Override]` become allowed); no new 8.4-only or 8.5-only feature is introduced by this ticket
- [x] Verification: the full suite passes on PHP 8.3, 8.4, 8.5 and 8.6 with warnings and deprecations displayed, and the matrix result is logged in `TEST_LOG.md`; a run on 8.2 is confirmed to be refused with the new message (a manual check or a small integration test that stubs the version), and the dev install is on par with the changed source files

Not in scope: raising the minimum above 8.3, adopting 8.3-only language features across existing code, and changing the MySQL or MariaDB support. Historical records (`docs/HISTORY.md`, `TEST_LOG.md` and the v1.0.0 release notes) keep describing what was true when they were written and are not edited.

### LF-079. Bundle the Rosewine and Lavender Mist themes

**Status:** Complete (9 of 9) — released in 1.2.0

Two gradient colour themes, first built as custom themes, ship with every release beside Storybook. Both are copies of Storybook's templates and reader script with their own colours, gradient tokens, card layout and dropdown styling, so a site owner can switch to either under Appearance › Themes. Storybook stays the default.

- [x] Both theme folders live under `content/themes/` (`rosewine`, `lavender-mist`) with a stylesheet header, the full set of templates and parts, `js/reader.js`, `js/select-menu.js`, a README and a `screenshot.jpg` for the theme card; their file headers name the right theme and `@since` the release that adds them
- [x] Both are listed in `ThemeCatalog::BUNDLED`; `ALWAYS_INSTALLED` in the updater stays Storybook only, since it is the theme the site falls back to
- [x] Light and dark colours as tokens, plus gradient tokens for the header and footer, page, card, accent, buttons, heading wash, tags and rules, defined in both colour schemes
- [x] Chapter, page and news post drawn as one rounded card with the heading on a gradient and a bar of chapter links along the bottom; story and news cards, widget-style news headings, tags, pagination and downloads menu styled to match
- [x] Dropdowns restyled everywhere (reader controls, colour choice, search and account selects): native styling in browsers that support customisable selects, and a keyboard- and screen-reader-friendly scripted list elsewhere that writes back to the real select and falls back to the plain select without scripts
- [x] Text stays readable on every colour of every gradient it sits on, in both colour schemes, after the test found white-on-lilac button text at 4.34:1 and the stop was darkened
- [x] PHP Test Suite: `GradientThemesTest` covers both themes (header and templates, no logic or inline styles in templates, scripts loaded only from `functions.php` and nothing fetched from outside, every class styled, dark, print and motion rules, theme options that never override the theme, every gradient token in both schemes, WCAG AA for the token pairs and for text on each gradient stop), and `ThemeCatalogTest` marks all three bundled
- [x] Documentation: `docs/CHANGELOG.md`, `docs/ADMINISTRATION.md`, `docs/THEME-DEVELOPMENT.md` (the gradient, scripted dropdown and card patterns), `DECISIONS.md` and the themes list in `CLAUDE.md`
- [x] Dev install on par with the source, and both themes shown on Appearance › Themes with their pictures and activated and browsed in a real browser

Not in scope: making either theme the default, inheriting one theme from another, and the scripted dropdown for browsers that already style the native list (they keep the native control). Rosewine and Lavender Mist now carry a copy of Storybook's templates, so a template change made to Storybook is repeated in both. Whether to propagate theme-wide changes automatically or by hand is left to the Classic Archive ticket (LF-033).

## Version 1.1.0 — 2026-10-27

### LF-010. Updater, backups and rollback

**Status:** Complete (9 of 9) — released in 1.1.0

Release-readiness work for the M5 milestone. Modelled on Lumora Press's update system, copied rather than shared.

- [x] Admin-initiated check against GitHub Releases, plus manual ZIP upload
- [x] Compatibility check (PHP version, database version) before applying
- [x] SHA-256 verification against a trusted release checksum
- [x] Automatic pre-update backup of files and database, downloadable by an Administrator, plus an on-demand backup
- [x] Preserve `config.php`, `table_prefix`, `secret_key`, uploads, user data, and everything under `content/plugins/` and `content/themes/` that the release does not ship (custom plugins and themes) across updates
- [x] Versioned migrations run during the update, with rollback designed and tested explicitly (restoring files alone does not undo a migration)
- [x] Resume or cancel an interrupted update
- [x] Maintenance mode: take the public site offline for visitors while Administrators keep working, with an optional schedule, an IP allowlist, and a customisable message; used automatically during updates
- [x] Success and failure logs; tests covering failure and rollback paths

Built on 2026-10-25: Maintenance > Updates, Backups and Maintenance mode. Not built, on purpose, because the ticket did not ask for them and Lumora Press has them: updating a single bundled plugin or theme without a full release, a scheduled or dashboard-triggered check (a check only happens when an Administrator presses the button), a warning that another administrator is active, and a warning that a core file was edited by hand. The decisions are in `DECISIONS.md`.

### LF-054. Visitor Stats plugin (bundled)

**Status:** Complete (9 of 9) — released in 1.1.0. Built 2026-10-27. No page cache exists yet, so the plugin states plainly (README) that a page served without running the application is not counted; LF-017 must fire `story_viewed` when it serves a cached story or chapter page to a guest. Core gained the `story_viewed` action, the `admin_dashboard_cards` and `admin_story_panels` filters and `Kernel::$root`.

A local, privacy-respecting view counter for stories, in the spirit of Lumora Press's Visitor Stats plugin, copied rather than shared. A bundled plugin on the LF-032 loader, not core: counting visits does not make writing, publishing or reading easier. After the MVP.

- [x] Plugin under `content/plugins/visitor-stats/`, off by default: activating it collects nothing until **Track views** is switched on in its settings
- [x] Count one view per guest request to a story or chapter page; signed-in people, previews, drafts, and unpublished or pending stories never count, and bots identified by their User-Agent are skipped
- [x] Day-level aggregate tables only: no cookies, no sessions, no unique-visitor tracking, no raw IP address, no User-Agent string, and no full referrer URL (referring domain only, external referrers only), so no row corresponds to one visitor
- [x] Dashboard widget with today, week, month and all-time totals and a most-read-stories list (7 days)
- [x] **Visitor Stats** admin screen: views over time (7, 30 and 90 days), top stories, top referrers, and browser and device breakdowns; styled and laid out like the core admin screens
- [x] Optional country breakdown from a GeoLite2 CSV the administrator imports themselves, in batches, into a flat file under `storage/` (nothing bundled, no outbound request, IPv4 only at first); reminder when the data is more than six months old
- [x] Per-story view totals for the story's own Author on their story screen, counting only their own stories; Administrators and Editors see all
- [x] Respect page caching (LF-017): the count must still register when a public page is served from cache or answered with `304`, or the plugin must say plainly that it cannot
- [x] Plugin README for users, tests (guests counted, signed-in people, drafts and bots not counted; no personal data in any table; an Author sees only their own stories' numbers), and `docs/DEVELOPER-APIS.md` updated for any hook the plugin needs core to add

Press's plugin also has the bulk of the design to copy from: `Lumora Press/LumoraPress/content/plugins/visitor-stats/` (services, GeoLite2 import, README).

### LF-055. Story downloads

**Status:** Complete (12 of 12) — released in 1.1.0. Built 2026-10-27. EPUBCheck 5.4.0 (with Ariane's permission) found one error, a cover page that nothing linked to, fixed and covered by a unit test; a plain and a covered sample now both pass with no errors or warnings. Browser-checked 2026-10-27 (menu, settings, profile box, story choice; real files fetched from the dev site). PDF is Dompdf (see `DECISIONS.md`). New migration 0030.

Readers can take a story away as PDF, EPUB or HTML. MOBI and AZW3 are not offered; see `DECISIONS.md` and `Future Enhancements.md`. This is a reading feature, so it belongs in core, with the file generation kept in focused service classes. The administrator can turn it off for the whole site; when it is on, each Author can refuse it for all their stories or for single stories, and can allow single stories while refusing the rest. After the MVP.

- [x] Settings › Reading (or a new Downloads section): site-wide switch for downloads, which formats are offered, and whether download counts are shown; everything hidden and every download URL refused when off
- [x] Author-wide setting on the profile or writer settings screen: allow or refuse downloads for all of my stories, defaulting to allow; it applies to stories that have no choice of their own and to stories published later
- [x] Per-story setting on the story screen with three choices: use my default, allow, or refuse; only the story's Author (and staff with edit rights) can change it, and a story's own choice beats the author-wide one in both directions (an Author who refuses globally can still allow one story, and the reverse)
- [x] One service decides whether a story can be downloaded (site switch, then the story's choice, then the Author's default, then allow), used by the template tag, the download route and the cache builder alike, so the answer is the same everywhere; none of the Author's choices has any effect while the site-wide switch is off
- [x] Download icon on the story page opening a small format menu (keyboard operable, works without JavaScript), through a template tag and a bundled `parts/` template so a theme only restyles it; Storybook styles and the built-in theme gets the equivalent, with dark mode
- [x] Download count per story and format, stored as plain totals (no per-person record); shown beside the icon only when the site-wide count switch is on, and counting can be disabled separately from the downloads themselves
- [x] HTML export: one self-contained file with the title page (title, author display name, summary, tags, rating, warnings), all chapters in order, and inline styles only
- [x] EPUB export: a valid EPUB 3 built with `ZipArchive` (cover, metadata, navigation document, one file per chapter), checked with the EPUBCheck tool during development
- [x] PDF export: a bundled, locally hosted PHP library (no Composer or Node on the server, no external service), with the licence recorded in `docs/THIRD_PARTY.md`; chapter text comes from the same sanitised content pipeline as the reader
- [x] Generated files cached under `storage/` and rebuilt when a chapter is added, edited, reordered, published or unpublished, or the story's metadata changes; a size and time limit so a very long story fails with a friendly message instead of exhausting shared hosting, and one build at a time per story
- [x] Visibility and privacy: only published stories on public pages can be downloaded; drafts, pending, unpublished and trashed stories, and private ones for a guest, answer `404`; files and file names contain the display name and a title slug, never the login name or an internal path; downloads send safe headers and are never served from a public cache for a signed-in reader
- [x] Tests (builders produce valid files for each format, count increments once per download, each switch hides and refuses correctly, a draft or another Author's unpublished story cannot be downloaded, cache is rebuilt after a chapter edit, no login name in any output), README and `docs/THEME-DEVELOPMENT.md` updated for the new template tag and part

This is separate from LF-013: downloads make readable files for readers, while the export makes a portable archive for moving between sites.

### LF-056. Visitor Stats: import the country data from a file already on the server

**Status:** Complete (6 of 6) — released in 1.1.0. Built 2026-10-27.

A browser upload of the GeoLite2 file often fails on a large file, so the data can be imported from where it already is. The country data was already a flat file (`storage/visitor-stats/ranges.bin`), never in the database; the README now says so.

- [x] A drop folder, `storage/visitor-stats/incoming/`, made on first use and listed on the screen (each `GeoLite2*.zip`, and the two CSVs together) with an **Import** button; the form posts an id taken from the listing, never a path
- [x] A typed absolute path as the fallback: a `.zip` (opened as a real ZIP) or a folder holding the two CSVs, Administrators only, `..` and symbolic links refused, `realpath()` before use, a plain message when `open_basedir` blocks it
- [x] The import advances by itself with the in-place runner from LF-057 (`continue` answers JSON); it stops going on by itself after an error so it cannot loop, and **Continue** stays as the fallback
- [x] A live alert when the data is more than six months old, cleared when it is replaced
- [x] README and `docs/ADMINISTRATION.md` updated
- [x] Tests: ZIP and CSV pair from the drop folder and from a typed path; a missing path, a wrong name, a symbolic link, a folder with one CSV, a fake `.zip`, a ZIP with neither entry and a path outside `open_basedir` each refused with `ranges.bin` untouched; a non-Administrator refused; the stale alert appearing and clearing

### LF-057. Update, backup and restore progress pages that advance in place

**Status:** Complete (6 of 6) — released in 1.1.0. Built 2026-10-27. Watched on the dev site through a real **Back up now** run at phone width in dark mode: one load of the progress page, three `fetch()` posts, then the finished page. A backup there finishes in about a second, so the redrawn step list between two steps was not caught on screen.

Every step was already its own request; only how the browser asks for the next one changed.

- [x] `advance` answers JSON when the request asks for it (same CSRF check and refusals, a fresh single-use token in the body, steps, log, status, message, and a redirect when finished); the form post and redirect are unchanged, so the page works without JavaScript
- [x] A `fetch()` runner redraws the step list and log inside an `aria-live="polite"` region with a "still working" line, built from text nodes only; any network error, non-JSON answer or timeout falls back to the form, and a step that stops or a rollback that begins reloads the page the server draws, with its Resume and Cancel buttons
- [x] One script for updates, backups, restores and the country import
- [x] The working state styled in `admin.css` in the theme variables, so dark mode follows; no inline styles
- [x] `docs/ADMINISTRATION.md` and the changelog updated
- [x] Tests: the JSON answer's steps, status, token and refusals (stale token, no run, a person who may not), a stopped step reporting needs attention, the finished run redirecting, the form flow untouched, static checks on the script

### LF-058. System Information: show the install folder

**Status:** Complete (3 of 3) — released in 1.1.0. Built 2026-10-27.

- [x] An **Install folder** row in the **Application** section, marked private so it is on the screen for Administrators and never in the downloadable snapshot
- [x] Plain, selectable, escaped text that wraps on a phone
- [x] Tests: present on the screen for an Administrator, absent from `snapshot()`, Editors refused, `docs/ADMINISTRATION.md` updated

### LF-059. Update backup: dump large tables without rescanning rows

**Status:** Complete (6 of 6) — released in 1.1.0. Built 2026-10-28. Found from a bug fixed in the sibling Lumora Gallery, where an update stopped at "Create update backup" on a 367,000-row table.

`DatabaseDump::dump()` paged each table with `SELECT * … ORDER BY <key> LIMIT 400 OFFSET n`, which rescans every skipped row on each page, so time grew with the square of the table size. The dump is resumable across requests through a cursor, so Gallery's single unbuffered pass could not be copied as it is; each page now continues after the last primary key written (`last` in the cursor). On a synthetic 367,000-row table the dump went from 14.4 s to 0.4 s with byte-identical output. Also checked and not affected: downloaded packages are one fixed `package.zip` that is removed when an update finishes or is discarded, so they cannot accumulate as they did in Gallery.

- [x] Keyset paging in `DatabaseDump::dump()`: the cursor carries the last key written, a composite key uses `a > x OR (a = x AND b > y)`, and the output is unchanged
- [x] Fallbacks: a table with no primary key, or a key holding binary text that JSON cannot carry, pages by row count as before, and a cursor saved by the old code still resumes
- [x] Measured old against new on a 367,000-row table: 14.4 s to 0.4 s, identical file
- [x] Tests: every table size around the page size (399, 400, 401, an exact multiple, a remainder) writes one INSERT per page and no empty one, and a dump stopped after every page equals a single call; composite key, no key, binary key and an old cursor
- [x] The full PHP 8.2 to 8.5 matrix
- [x] `docs/CHANGELOG.md` updated

## Version 1.0.0 — 2026-10-25

### LF-001. Project skeleton and version control

**Status:** Complete (5 of 5) — released in 1.0.0

Implemented 2026-10-14. Themes, plugins and uploads live under `content/` (as `content/themes/`, `content/plugins/`, `content/uploads/`), not at the top level as `Project Architecture.md` sketches, so an update can replace code while leaving `content/` alone; see `DECISIONS.md`. Each protected directory carries its own `.htaccess` rather than relying only on the root rules.

- [x] Initialise `LumoraFiction/.git` and add a `.gitignore` (real `config/config.php`, `storage/*` runtime files, `uploads/`)
- [x] Create the minimal directory layout from `Project Architecture.md` (`app/`, `admin/`, `themes/`, `config/`, `storage/`, `install/`, `docs/`)
- [x] Front controller (`index.php`) and `.htaccess` that keep `config/`, `storage/` and `app/` non-downloadable
- [x] `version.php`, `README.md`, `docs/CHANGELOG.md`, `docs/HISTORY.md`, `docs/THIRD_PARTY.md`
- [x] `PHP Test Suite/` scaffold with PHP 8.2/8.3/8.4/8.5 + MariaDB Docker matrix

### LF-002. Core: configuration, database layer and migrations

**Status:** Complete (5 of 5) — released in 1.0.0

Implemented 2026-10-14. Differences from Lumora Press, all deliberate: migrations live in `app/Migrations/` (the installer deletes `install/`, which would remove them before an update could use them); the SQL splitter respects quotes and comments; migrations take a named database lock; settings are stored as JSON, never `serialize()`; SQL uses a `{prefix}` token that `Database` replaces. Users have no role-assignment table: the role is a column, since an account has exactly one role. Verified on PHP 8.2–8.5 with MariaDB 11.8, and on PHP 8.4 with MySQL 8.0, MySQL 5.7 and MariaDB 10.4.

- [x] `Configuration` service with validated settings and defaults
- [x] `Database` service (PDO, prepared statements, transactions) that applies the configurable table prefix; migrations and services never hardcode table names
- [x] `Migrator` with versioned migrations and history table
- [x] Sessions table in the initial schema (`{prefix}sessions`: `id`, `data`, `last_activity`), matching Lumora Press
- [x] Initial schema: users (separate `login_name`, `display_name` and public `slug` columns, each with its own uniqueness rule), roles, settings, migration history, audit log

### LF-003. Router, kernel and hooks

**Status:** Complete (3 of 3) — released in 1.0.0

Implemented 2026-10-14. Differences from Lumora Press, all deliberate: the router escapes the literal parts of patterns, rejects NUL and control characters, invalid UTF-8 and dot segments, answers HEAD with the GET handler, supports named routes with `urlFor()`, and returns a `Resolution` value instead of sending headers itself; the error handler forces `zend.exception_ignore_args` so logged traces never contain arguments such as the database password; hooks can be removed. The installer redirect for an uninstalled site is left to the installer ticket; until then `index.php` answers 503. Verified on PHP 8.2–8.5 with MariaDB 11.8, and on PHP 8.4 with MySQL 5.7 and MariaDB 10.4.

- [x] `Kernel` and `Router` with clean URLs
- [x] `Hooks` (`add_action`, `do_action`, `add_filter`, `apply_filters`)
- [x] Generic error handling: log internally, show generic pages publicly

### LF-004. Security helpers and authentication

**Status:** Complete (11 of 11) — released in 1.0.0

Implemented 2026-10-14 as services and tests; the sign-in, registration, confirmation and reset pages that call them belong to LF-022. Differences from Lumora Press, all deliberate: an unknown account takes as long to reject as a wrong password (Press skips the hash check); failed sign-ins are not wiped by a later successful one, which would let anyone with an account guess at other accounts indefinitely; the session handler implements `validateId`, without which PHP's strict mode adopts a session identifier a visitor invented (session fixation); emailed links are built from `site_url` in `config.php`, never from the request's Host header; verification and reset tokens share one `user_tokens` table; a password change ends every earlier session; the typed login name is never stored with failed attempts; passwords longer than bcrypt's 72 bytes are refused rather than silently truncated. Verified on PHP 8.2–8.5 with MariaDB 11.8, and on PHP 8.4 with MySQL 5.7, MySQL 8.0 and MariaDB 10.4.

- [x] Login name distinct from display name: every account has a private, unique login name (used only to sign in, never shown publicly, never used in URLs, feeds, or markup) and a separate public display name; a public author slug is used for profile URLs and feeds. Login-name rules are enforced at registration, and a login name cannot be set equal to the display name or the slug
- [x] Generic login and password-reset errors that never reveal whether a login name or email exists
- [x] Author-archive and profile URLs, feeds, and API output never expose login names (username-enumeration protection, always on in core)
- [x] Database-backed sessions identical in behaviour to Lumora Press: a `SessionManager` (HttpOnly, SameSite=Lax, Secure on HTTPS, session-lifetime cookie, regenerate and destroy helpers) and a `DatabaseSessionHandler` in a `{prefix}sessions` table (`id`, `data`, `last_activity`), copied rather than shared. PHP's garbage collector is forced on (`gc_probability` 1, `gc_divisor` 100, configurable max lifetime) so shared hosting needs no file cleanup or cron. Every handler method fails safe: a database error is logged and degrades to no session for that request
- [x] Session cookie name `lumora_fiction_session`, distinct from Press's `lumora_press_session`, so both can run on one domain without overwriting each other's sessions; the installer uses its own separate name
- [x] Secure session handling and CSRF protection
- [x] `AuthService`: registration, login, logout, password reset (expiring single-use tokens)
- [x] Login throttling: five attempts, 15-minute lockout
- [x] Email verification
- [x] Roles: Administrator, Editor, Author, Reader, Guest, with server-side permission checks
- [x] Security headers and a restrictive Content Security Policy

### LF-005. Browser-based installer

**Status:** Complete (5 of 5) — released in 1.0.0

Implemented 2026-10-14 and installed on the Apache dev site. The installer is one page (database, site, administrator) rather than Press's two steps, so no database password is ever kept in a session file between steps. Its logic lives in testable classes under `install/src/`, with `install/index.php` as thin wiring. Differences from Press: a pre-flight probe actually creates, fills, alters and drops a throwaway table, so missing privileges are reported before anything is built; a failed install removes the tables it made (the probe first proves none existed, and the cleanup escapes `_` and `%` so a lookalike table is never touched) so the form can simply be resubmitted; the config file is written last and only a file this attempt wrote is ever removed; error details go to the log and the page shows fixed messages only; guessable table prefixes and administrator login names are refused; the visitor is redirected to `install/` using the script's own location, never the Host header. The installer also asks what the site publishes (`archive.mode`) and records the installed version. Verified on PHP 8.2-8.5 with MariaDB 11.8, on PHP 8.4 with MySQL 5.7, MySQL 8.0 and MariaDB 10.4, and on the Apache dev site (subdirectory install).

- [x] Environment prechecks: PHP version, extensions, DB connectivity, directory permissions, URL rewriting, migration dry run
- [x] Database setup and initial administrator account
- [x] Random table prefix suggested by the installer (e.g. `lumfic_a8f3d1_`, the fixed marker `lumfic_` plus six random hex characters, so it never collides with Press's `lum_` prefix on a shared database), editable but restricted to `[A-Za-z0-9_]`, with a hint to avoid guessable values; stored in `config.php` as `table_prefix` and used by every query
- [x] Protected configuration file and installation lock
- [x] `install/` removal after success (never run against the live source tree)

### LF-006. Admin shell

**Status:** Complete (9 of 9) — released in 1.0.0

Implemented 2026-10-16. Handlers return a `Response` and receive a `Request`, so controllers are tested without a web server; the menu lists only screens that exist and later tickets add theirs through the `admin_menu` filter. Found and fixed while testing: `Csrf::verify()` never opened the visitor's session, so no real POST could have passed its token check; and `AuthService` read `$_COOKIE` directly instead of the request's cookies. Differences from Lumora Press: no "return to" address after sign-in (an open-redirect risk), sign-out is POST-only with a token, the system snapshot is built from rows marked private or public so nothing secret can be added by accident, and every settings change is written to the audit log in the same transaction as the change. The dev site's sign-in is verified against real Apache.

- [x] `Request` and `Response` objects; the kernel sends whatever a handler returns
- [x] Admin sign-in and sign-out (`/admin/login`, `/admin/logout`), with the lockout message, CSRF protection, and no way to be redirected to another site
- [x] Access rules: Administrators, Editors and Authors may enter the admin area (new `AccessAdmin` capability); Readers and Guests may not; every screen checks its own capability on the server
- [x] Admin layout: skip link, landmarks, sidebar navigation that marks the current page, signed-in user and sign-out, flash messages, light and dark mode that follows the system and can be switched by hand, and a layout that works on a phone
- [x] Dashboard: welcome, system notices (installer folder still present, debug mode on, site not served over HTTPS), account counts and recent activity for Administrators, and a filter for other features to add notices
- [x] Settings › Archive: archive mode, whether registration is open, the role new accounts get, whether new accounts must confirm their email, and whether a new Author's first story waits for approval; every change is recorded in the audit log
- [x] System Information (Maintenance): site address as stored and as detected, PHP and database versions and limits, required extensions, writable folders, security checks, and a downloadable JSON snapshot that never includes credentials, the secret key, or server paths
- [x] Audit log service used by the settings screen and shown on the dashboard
- [x] Tests: every admin route against every role, CSRF on every form, escaping of names in the layout, and the snapshot never containing a secret

### LF-007. Content pipeline and multi-mode editor

**Status:** Complete (12 of 12) — released in 1.0.0

Port the content pipeline and editor from Lumora Press (`app/Core/Content/`, `app/Services/ContentRenderer.php`, `app/Models/ContentFormat.php`, `admin/assets/js/content-editor.js`) as copies, not a shared dependency. Strip Press ticket references and blog-specific features (embeds, shortcodes, Media Manager). Depends on LF-002 and LF-004; belongs to the publishing milestone (M2).

Implemented 2026-10-16. Deliberately not a line-for-line copy of Press: the Markdown parser and the HTML-to-Markdown converter were rewritten small for fiction (no tables, images, footnotes, table of contents or colours), and the sanitiser allowlist has no images, tables, ids or arbitrary classes (only `lf-` and `language-` classes survive). Markdown input never passes raw HTML through. The editor script is about 330 lines against Press's 3,800. TinyMCE 7.9.1 and EasyMDE 2.18.0 are bundled under `admin/assets/vendor/` with checksums in `docs/THIRD_PARTY.md`; EasyMDE's spell-checker and icon-font downloads are switched off. The reusable editor is `ContentEditor` (markup) with `ContentEditorController` (preview and mode conversion, JSON, single-use tokens rotated on every answer); the chapter screens in LF-025 place it in their forms. Browser-checked under the strict Content-Security-Policy with no console errors and no request to another site. Visual and HTML modes both store HTML; converting between Markdown and HTML is done by the renderer on the server, and plain text is never converted. Settings > Editor is the site-wide screen. A stray closing div in pasted HTML used to drop the rest of the text and is now handled in the sanitiser.

- [x] Four editing modes (WYSIWYG, Markdown, HTML, plain text) with one stored content format, so a chapter can switch modes without loss
- [x] Default editor: a site-wide default mode (Settings), a per-user override, and an optional setting that locks everyone to the site default (the profile page control for the per-user override is added with the account pages, LF-022)
- [x] `ContentFormat` enum and a `ContentRenderer` service as the only place that branches on the format
- [x] `HtmlSanitizer`, with the allowlist tuned for fiction (formatting, headings, lists, blockquotes, horizontal rules, links; no embeds or scripts), as the single XSS boundary for all four modes
- [x] `MarkdownParser` and `HtmlToMarkdownConverter`
- [x] Plain text is escaped and converted to paragraphs and line breaks, never trusted as markup
- [x] Bundle TinyMCE Community and EasyMDE locally with exact version pins; no CDN URLs or other runtime network requests
- [x] Reduced `content-editor.js`: mode switching, editor setup, live word and character count, unsaved-changes warning
- [x] Explicit Save Draft and Publish actions, plus chapter preview rendered through `ContentRenderer`
- [x] CSP-compatible: no inline scripts or styles required by the editor
- [x] Record all bundled assets and licences in `docs/THIRD_PARTY.md`
- [x] Tests: sanitiser XSS cases, mode round-trips, plain-text escaping, and a check that editor assets contain no external URLs

### LF-008. Registration anti-spam and abuse protection

**Status:** Complete — released in 1.0.0 (7 of 7)

Builds on LF-004's basic login throttling and CSRF; this ticket covers abuse of open registration and public forms. Modelled on Lumora Press's registration and comment-form protection, copied rather than shared.

- [x] Account active only after the emailed confirmation link is used (single-use, expiring token) — already true since LF-004 and LF-022 (`AuthService`, `TokenService`); this ticket added a regression test through the public pages
- [x] Hidden trap fields with names unique to the site, plus submission-timing checks, on registration and public forms
- [x] Per-IP rate limits (a short gap plus an hourly cap) and an automatic flood pause on registration
- [x] Disposable-email and custom domain block lists
- [x] Optional challenge question and optional Akismet, both off by default and with no mandatory external service
- [x] Security Events log (blocked logins, spam, rejected registrations) with configurable retention, shown to Administrators
- [x] Tests for each protection, including a bypass attempt per control

Holding a new Author's first story for Editor approval lives in LF-026, and clearing out accounts that never confirmed their email lives in the Sweep plugin (LF-019).

Built 2026-10-21. Choices worth knowing: the "public forms" it protects are the three a guest can use that send mail or create something: registration, password reset and the confirmation-email resend (sign-in has its own lockout, and a password manager's quick submit would trip the timing check). Core prints the hidden fields through one `lf_form_fields` action that the `account-form` part of each theme fires inside the form, so the markup, the derived names (`FormShield`, HMAC of the secret key) and the signed timestamp stay out of themes; both bundled themes got the call and a `lf-form__trap` rule. A form with no timing fields is refused (`form_invalid`), never let through, otherwise omitting them would be a bypass; the cost is that a custom theme must fire the action. A filled trap and an Akismet or plugin verdict are answered with the normal thanks and create nothing (the visitor learns nothing); a form sent too fast, too late or without timing, a blocked email address, a wrong answer and the rate limits are shown, because a real visitor can hit them. Every submission counts toward the per-address limits whether or not it was valid, so the defaults are gentle (5 seconds apart, 10 an hour, flood at 60 an hour, pause 60 minutes) and 0 turns a limit off. A flood pause clears the recent count when it starts and when it ends, so it does not trip again at once. `registration_attempts` and `security_events` are new tables (migrations 0019 and 0020, which means the fresh-install snapshot must be regenerated at Release). The Security events log is separate from the audit log, trimmed by age on write at most once an hour, and `recordOnce()` keeps one attacker from filling it. Per-address limits use `Request::ip()`, so a site behind a shared address shares one allowance until LF-009 adds the trusted-proxy setting. Akismet fails open and is asked only after every local check, and the key is verified when it is entered (an unreachable Akismet saves the key with a note). The settings screen is **Settings > Spam protection**; LF-009 will add Settings > Security for lockouts. `AuthService` gained an optional `SecurityEventLog` last argument that records the sign-in that starts a lockout.

### LF-009. Settings › Security and proxy awareness

**Status:** Complete — released in 1.0.0 (5 of 5)

Extends LF-004's fixed five-attempt / 15-minute lockout into configurable, escalating behaviour.

- [x] Settings › Security screen: lockout thresholds and duration, with the five-attempt / 15-minute defaults
- [x] Lockout lengthens for an IP address that keeps failing
- [x] Email to the account owner when their account is targeted by a lockout
- [x] Reverse-proxy and CDN support: trusted-proxy setting so visitor IPs and HTTPS are read correctly for rate limits and logs
- [x] Tests for escalation, owner notification, and spoofed forwarding headers from untrusted sources

Built 2026-10-21. Choices worth knowing: `LoginThrottle` already supported a longer lockout for repeat failures, so this ticket wires it to settings (`security.lockout_attempts` 3 to 20, `security.lockout_minutes` 1 to 1440, which is also the window failures are counted in, `security.lockout_progressive`, all with the old behaviour as the default except that escalation is now on). The lockout stays per address and never per account name, so nobody can lock a person out by guessing at their name. The owner notice goes to the account named by the sign-in that starts the lockout (failed names are never stored, because people type passwords into that box), only for an active account, at most once an hour (`users.lockout_notified_at`, migration 0021, claimed in one `UPDATE`), and the sign-in page is identical whether or not an account was named. Proxies: `TrustedProxies` (`app/Core/Http`) rewrites `REMOTE_ADDR` and `HTTPS` in the server variables once, in `Kernel::boot()` and again for the `Request` that `Kernel::handle()` builds, so sessions, the secure cookie flag, HSTS, the dashboard warning, the lockout, the registration limits and the Security events list all agree; `Request::ip()` itself still reads no header. The header is read from the right, skipping listed proxies, and anything that is not an address in the part read means the header is ignored; the connection's own address is kept as `LF_PEER_ADDR` and shown by `Request::peer()` on the screen, which also says which address the site recorded for the administrator's visit. Ranges wider than /8 (IPv4) or /32 (IPv6) are refused when saving. `IpRangeMatcher` is new in `app/Core/Security`. Not done on purpose: `security.hsts`, `security.password_min_length` and `security.session_lifetime` still have no screen (they were not in this ticket), and CDN-specific headers such as `CF-Connecting-IP` are not read, because `X-Forwarded-For` is what Cloudflare and most proxies send. `AdminBrowser` in the test suite gained `serverExtras` so tests can send forged headers, and `Kernel::$proxies` is public for that.

### LF-011. Notifications, trash and revisions

**Status:** Complete — released in 1.0.0 (6 of 6). Built 2026-10-23; migration 0028 (`trashed_at` on stories and chapters, `chapter_revisions`, `notifications`). Design choices are in `DECISIONS.md` ("Trash, earlier versions and notifications"): the existing **Delete** buttons still delete at once and **Move to the trash** is added beside them; a restored item comes back unpublished; the settings are under **Settings > Retention**. Not yet looked at by Ariane in a browser. The fresh-install schema snapshot must include 0028 at Release.

Usability features for Editors and Authors. Revisions use basic recovery only; advanced comparison stays deferred.

- [x] Notifications inbox for Editors and Authors: stories awaiting review and their outcome, with links to the exact page that handles each item
- [x] Trash and restore for stories and chapters, with bulk actions (trash, restore, delete permanently, change status)
- [x] Duplicate story
- [x] Chapter revisions: previous versions kept and recoverable by the Author
- [x] Configurable retention for revisions and trashed items
- [x] Tests for permissions on each action, including that an Author cannot restore or view another Author's items

### LF-015. Story cover images

**Status:** Complete — released in 1.0.0 (7 of 7)

Each story may have a cover image. Kept deliberately small: an upload attached to a story, not a general media manager. Needs the story model (LF-023). Schema addition for the stories table once it exists. M2 and M3 milestones.

Implemented 2026-10-21. Migration 0022 adds `stories.cover_file` (a generated name such as `{32 hex}.jpg`, validated on every read) and `cover_alt`. `Core\Media\CoverImageStore` does the work: `getimagesize()` for the real type, size and pixel limits, then GD re-draws the picture at 600×900 and 200×300 boxes (never enlarged, EXIF turn applied then all metadata dropped) into `content/uploads/covers/`. `StoryService::applyCover()` is the one entry point (set, replace, describe, remove) and `delete()` cleans up; `Request::upload()` only returns a file PHP itself received (`is_uploaded_file`). `StoryPresenter::cover()` gives themes `{thumb, full, alt}` on stories and cards. The picture files of a draft are reachable by their random address, as any upload is; they are not listed anywhere. An optional crop (added after review): `admin/assets/js/cover-crop.js` draws the chosen file on a canvas with a 3:2 frame (drag or sliders, read with `createImageBitmap` so the strict policy needs no `blob:` exception) and posts `crop_x/y/w/h` as fractions of the upright picture; `CoverImageStore::parseCrop()` validates them (bad values mean the whole picture) and `save()` crops before resizing. Storybook shows the thumbnail (decorative, `alt=""`) or a placeholder in every card and the full picture with its description on the story page; the built-in theme shows the story-page cover only.

- [x] Optional cover image per story, uploaded in the story editor (the story form from LF-025 has no cover field yet; add it there), with a required text alternative
- [x] Strict upload validation: JPEG, PNG, and WebP only, real image-type check (not just the extension or MIME header), size limits, and re-encoding so embedded payloads are stripped
- [x] Generated thumbnail sizes for listings and a larger size for the story page
- [x] Files stored under `uploads/` with generated filenames, no executable files ever served from that directory, and no EXIF location data kept
- [x] Deleting a story or replacing its cover removes the old files
- [x] Cover shown in listings, on the story page, and in feeds where appropriate, with a placeholder when none is set; styling in the theme stylesheet
- [x] Tests for rejected file types, oversized files, a PHP file disguised as an image, and ownership (an Author cannot change another Author's cover)

### LF-020. Dummy Content generator plugin (bundled)

**Status:** Complete — released in 1.0.0 (4 of 4)

Developer-only. Generates placeholder users, stories, chapters, and taxonomy so themes, search, listings, and feeds can be tested at the scale `CLAUDE.md` targets. Needs the plugin loader. Intended to be built early, once stories exist.

Built 2026-10-23 as the bundled plugin `content/plugins/dummy-content/` (`Corpus`, `Options`, `Tracker`, `Generator`, `Screen`, `views/screen.php`, README). Choices worth knowing: rows go in through multi-row inserts instead of the story and chapter services, so the invariants those services keep (story word counts, gap-free chapter numbers, term story counts) are kept by hand and covered by tests; ratings, genres, categories, warnings and relationship categories are taken from the terms the site already has, because the classification presets (LF-021) do not exist yet, so stories carry none of them on a site that defines none; the "production" flag is a new `environment` key in `config/config.php` (installer writes `production`, a missing value counts as production, only `development` and `testing` allow the plugin to load), and nothing in the admin can change it; the list of what was made is the plugin's own table `dummy_content_items`, created on demand, so core needed no migration; generated accounts get a password nobody knows, so they cannot sign in (a way to sign in as one for testing reader features is not built). Core additions: `Kernel::$admin` and `AdminView::renderContent()` so a plugin can draw its own screen inside the admin layout. The opt-in `Integration/ArchiveScaleTest.php` (`LUMORA_SCALE_STORIES=N`) generates N stories and times the public pages; results are in `TEST_LOG.md`.

- [x] Generate a chosen number of users, stories, chapters, fandoms, tags, and bookmarks with realistic variation (one-shots, long serials, all rating and status values)
- [x] Every generated item is tagged so all of it can be removed in one step
- [x] Refuses to run when the install is flagged as production, and is inactive by default
- [x] Used to check listing and search performance with tens of thousands of stories; results recorded in the test log

### LF-021. Classification presets

**Status:** Complete — released in 1.0.0 (13 of 13). Built 2026-10-23; migration 0027 (`terms` gains `description`, `sort_order`, `hidden`, `preset_key`). Hiding, ordering and descriptions are for the short lists only (genre, category, rating, warning, relationship category). Story status is a choice of whether "abandoned" is offered, not an editable list (see `DECISIONS.md`). Not yet looked at by Ariane in a browser. The fresh-install schema snapshot must include 0027 at Release.

Ship the short, stable classification lists as data files so Administrators never type them in by hand and nothing is fetched from AO3 or FanFiction.net. Presets are a starting point: once applied, every value is editable. Fandoms, characters, and tags are not bundled; they stay author-proposed and administrator-curated. Part of the taxonomy work in the public archive milestone (M3); depends on LF-002, LF-006 and the taxonomy tables (LF-024).

- [x] (Moved to `app/Data/presets/`, because the installer deletes `install/` and the Settings picker needs the files later; see `DECISIONS.md`.) Versioned JSON preset file format under `install/data/presets/`, with a schema, a format version, and a stable key for every value so edits and re-applies can be matched up
- [x] Rating presets: AO3-style (General Audiences, Teen And Up Audiences, Mature, Explicit, Not Rated), FanFiction.net-style (K, K+, T, M, MA, Not Rated), and a minimal original-fiction set
- [x] Movie-style rating preset using the familiar film ratings (G, PG, PG-13, R, NC-17, Not Rated), with each value's description written for stories rather than films; selectable beside the other rating presets, with other national film rating systems left for a later preset file rather than built in now
- [x] Archive warning presets: AO3-style (Creator Chose Not To Use Archive Warnings, Graphic Depictions Of Violence, Major Character Death, No Archive Warnings Apply, Rape/Non-Con, Underage) and a minimal set
- [x] Relationship category preset: F/F, F/M, Gen, M/M, Multi, Other
- [x] Story status preset: ongoing, completed, on hiatus (and abandoned as an optional value)
- [x] Short descriptions for each value written for Lumora Fiction, not copied from either site
- [x] Picker in the installer and Settings › Archive: AO3-style, FanFiction.net-style, movie-style, minimal, or "Start empty / no preset", applied per list (ratings, warnings, categories, status)
- [x] Applying a preset is always optional: choosing "No preset" leaves a list empty so an Administrator can invent their own values from scratch, in the installer or later, with no preset ever applied automatically or re-offered as a requirement
- [x] An empty list never breaks the site: a rating, warning, category, or status field with no values defined is hidden from the story form and filters and is not treated as required, and the story status falls back to a built-in ongoing / completed / on hiatus set only if the Administrator hasn't defined any
- [x] Applying a preset creates the values without deleting existing ones; values can be renamed, reordered, hidden, or added; re-applying a preset restores missing values without overwriting edits
- [x] Hiding or removing a value that stories already use never deletes story data
- [x] Tests: rejection of malformed or unknown-version preset files, no duplicate keys, re-apply preserves edits, each preset loads into a fresh install, and a fresh install with no preset works end to end (story creation, filters, and feeds with empty lists)

### LF-022. Account pages

**Status:** Complete — released in 1.0.0 (9 of 9)

Implemented 2026-10-18. Choices worth knowing: every account page is the one `account` view (variants `sign-in`, `register`, `resend`, `forgot`, `reset`, `profile`, `message`), and the controller hands the theme bundled form descriptions, so a theme needs only `account.php` and an `account-form` part (the built-in theme has both, so a theme without them still works). Every page is `private, no-store`; pages reached by an emailed link also send `Referrer-Policy: no-referrer`. Signing in always lands on the home page: there is no "return to" address, which is how open redirects are built. A confirmation link is used up when opened (the service's design), while a reset link is only checked until the form is submitted. Changing the password or the email address asks for the current password, and wrong guesses there count towards the sign-in lockout; a new password restarts the current session so only the other sessions end. The email address changes immediately, and the old address is told; the new one is marked unconfirmed rather than waiting for a confirmation round trip, because there is no place to hold a pending address without a migration. Asking to change to an address another account uses says so, which tells a signed-in person who knows their password that the address is registered. The display name changes but the author-page address (slug) never does. `AccountNavigation` adds Sign in and Register for guests (Register only while registration is open) and Account for members. `custom themes/` does not exist yet, so nothing was propagated there; when it does, copy `account.php`, `parts/account-form.php` and the form, notice and account rules from Storybook's `style.css`.

The pages that use the LF-004 services. They need the theme renderer (LF-027) and the Storybook theme (LF-028), so they follow those. Every form carries a CSRF token and every message comes from the services unchanged.

- [x] Sign-in page and sign-out, showing the lockout message and wait time, and the unconfirmed-email and suspended messages
- [x] Registration page (honouring the registration settings), with a clear "check your email" result that looks the same whether or not the address was already registered
- [x] Email confirmation page (`/account/verify/{token}`) and a "resend confirmation" form
- [x] Forgot-password form and reset page (`/account/reset/{token}`); the reset page checks the link without using it up, and says nothing about whether an address exists
- [x] Pages that carry a secret link send `Referrer-Policy: no-referrer` and `Cache-Control: no-store`
- [x] Profile settings: change display name (re-checking it differs from the login name), email, and password (asks for the current password; ends other sessions)
- [x] Tests that no page or message shows a login name to anyone but its owner, and that every state-changing form rejects a missing or reused CSRF token
- [x] Profile control for the editing mode (Visual, Markdown, HTML, Plain text, or "site default") using `EditorSettings::setUserMode()`, hidden when the Administrator locks the editor
- [x] Templates for the account pages in Storybook (`content/themes/storybook/`), with `lf-` classes and dark-mode styles in `style.css`, and the same templates added to every theme in `custom themes/`

The sign-in-to-read setting (LF-029) now sends guests to the public sign-in page (`ArchiveController::signInUrl()`), and the admin sign-in keeps working for staff.

### LF-023. Story and chapter data model

**Status:** Complete (10 of 10) — released in 1.0.0

The core records of the archive. Part of the publishing milestone (M2); depends on LF-002 and LF-004. Everything about who may change a story is decided in the service, never in a controller or a view.

Implemented 2026-10-16; the content renderer from LF-007 completed the last item. Choices worth knowing: neither `owner_id` nor `story_id` is a foreign key (deleting an account must not cascade into its stories, and the chapter delete is one explicit transaction); `(story_id, number)` is unique and reordering renumbers in two steps; chapters are renumbered 1 to n on delete; the story slug never changes after creation; `StoryService::publish()` publishes directly for users with `PublishWithoutReview` or `ModerateStories` and otherwise queues the story as pending (the approval screens and the first-story hold rule belong to LF-026); `stories.word_count` is the total over every chapter; an unrecognised stored state or format fails closed (draft, plain text).

- [x] Migrations for stories (owner, title, URL slug, summary, author's notes, disclaimer, language, status of the work, publication state, dates) and chapters (story, number, title, content, content format, notes, word count, publication state, dates), with indexes for the listings the public pages need
- [x] Story status of the work: ongoing, completed, on hiatus (and abandoned when the Administrator allows it); publication state kept separate: draft, pending approval, published, unpublished
- [x] Unique, URL-safe story slugs that never collide with reserved addresses, and stable chapter numbering that survives reordering
- [x] `StoryService` and `ChapterService`: create, edit, reorder, publish, unpublish, delete, each checking ownership and the caller's capability (an Author changes only their own work; Editors and Administrators may change any)
- [x] Word counts calculated on save from the stored text with markup removed, and a story total kept in step; one-shots are stories with a single chapter
- [x] A story's visibility is a single rule in one place: only published stories with at least one published chapter appear to the public, and a draft is never reachable through any public address
- [x] Deleting a story removes its chapters and links in one transaction; deleting an account's stories is a separate, explicit choice (see LF-016)
- [x] Content is stored once in the editor's format (LF-007) and rendered through the content pipeline; nothing is ever output unsanitised
- [x] Every change to a story's state is written to the audit log
- [x] Tests: ownership rules for every role, visibility of every state, reordering, word counts with markup and multibyte text, slug collisions, and a hostile title or summary never reaching the page unescaped

### LF-024. Taxonomy: fandoms, characters, relationships, genres, tags, ratings and warnings

**Status:** Complete — released in 1.0.0 (11 of 11)

Classification that works for fanfiction and original fiction without forcing either to fill in the other's fields. Part of the public archive milestone (M3); depends on LF-023. The classification presets (LF-021) fill the short lists; this ticket is the machinery.

Implemented 2026-10-16. One `terms` table holds every kind, told apart by a `type` column (migrations 0011 terms, 0012 aliases, 0013 story links), so a new kind of term needs no new table. Choices worth knowing: only administrators curate (`ManageSite`), writers can only pick and, for fandoms, characters, relationships and tags, propose; genres, categories, ratings, warnings and relationship categories are administrator lists. A name is matched case-insensitively against term names and aliases before a proposal creates anything. A character or relationship named while exactly one fandom is chosen belongs to that fandom, otherwise to none. Renaming keeps the address. Choosing a term's own alias as its name swaps the two. Merging moves links, aliases, and a fandom's characters and relationships (merging same-named ones), and keeps the merged name as an alias. Required kinds are enforced when a story is submitted (`StoryService::publish()`), never on drafts, and a kind that has no terms and cannot be added to by writers is skipped. `terms.story_count` is recounted for the affected terms whenever a story or chapter changes whether the story is public; `TaxonomyService::refreshAllCounts()` repairs it. The story form and the filters (LF-025, LF-030) read `TaxonomyService::enabledTypes()` to decide which fields to show. There is no public tag or fandom page yet (LF-029).

- [x] Migrations and `TaxonomyService` for fandoms, characters, relationships, genres, free-form tags, original-fiction categories, ratings, archive warnings, and relationship categories, each linked to stories many-to-many
- [x] Ratings and warnings are separate fields and neither is ever inferred from the other
- [x] Archive mode (both, fanfiction only, original only) decides which fields appear on the story form and in filters; switching a field off hides it without deleting anything already saved
- [x] Authors may propose new tags, fandoms, characters and relationships while writing; they are created as "not yet reviewed" and usable at once
- [x] Administrator screens to rename, merge duplicates, add aliases, and choose canonical names; merging moves every story to the surviving term
- [x] Characters and relationships belong to a fandom where one applies, so the same name in two fandoms stays two terms
- [x] Term names are normalised (case, spacing) and limited in length, with unique slugs, and rendered escaped everywhere
- [x] Required fields follow the Administrator's settings, and an empty list is never required
- [x] Per-term story counts counting only public stories, kept cheap enough for tens of thousands of stories
- [x] Deleting a story also removes its term links: extend `StoryService::delete()` so the links go in the same transaction as the chapters
- [x] Tests: archive-mode gating, merge and alias behaviour, duplicate-name handling, counts that exclude drafts, and hostile term names

### LF-025. Writer area: my stories, story form and chapter editor screens

**Status:** Complete — released in 1.0.0 (10 of 10)

The admin screens an Author uses to write. Part of the publishing milestone (M2); depends on LF-006, LF-007, LF-023 and LF-024.

Implemented 2026-10-16. `StoryController` and `ChapterController` share `WriterController`, which holds the one rule for a session that ends mid-edit: a guest who posts a story or chapter form gets a 401 page that hands the typed text back (and sends no token), and the "Continue editing" button posts it again, which, once signed in, finds the token missing and shows the real form with the text and a fresh token. Missing and not-yours answer the same way (a redirect to the list with "not available"). Reordering is a pair of Move up / Move down buttons per chapter (`ChapterService::move()`), so it works by keyboard and without a script. The unsaved-changes warning is in `admin.js` for any form marked `data-lf-guard`; the editor tracks the chapter text itself. Bulk publish follows the same rules as publishing one story (an Author's story goes to approval, a story missing required classification is skipped and counted), and bulk delete asks on a page of its own that lists only stories the caller may change. A classification problem after creating a story keeps the story and shows the edit form with the problems, rather than creating a second story on resubmit. Fandoms, characters, relationships and tags are typed as comma-separated names; ratings are a single choice and the curated lists are checkboxes. The cover image field on the story form lands with LF-015, and the preview screens first rendered through `ContentRenderer` alone; LF-027 moved them onto the theme renderer.

- [x] "My stories" list for Authors, and an "All stories" list for Editors and Administrators, with search, status filters, and bulk actions that respect ownership
- [x] Story form: title, summary, notes, disclaimer, language, status of the work, cover image field (LF-015) and the taxonomy fields allowed by the archive mode (saved with `StoryService::classify()`; shown according to `TaxonomyService::enabledTypes()`; its `StoryValidationException` messages are keyed by kind name, and `missingRequired()` tells the form what a submission still lacks)
- [x] Chapter list for a story with reordering, plus a chapter form with the editor from LF-007, author's notes, live word count, and explicit Save Draft and Publish buttons
- [x] Preview of a draft story or chapter exactly as readers will see it, visible only to its owner and to staff
- [x] A warning when leaving a page with unsaved changes, and saving that never loses text if the session has expired (the form is shown again with the text intact)
- [x] Every form carries a CSRF token, every action checks ownership on the server, and one Author can never open, preview, or change another Author's draft by changing an address
- [x] The menu entries and dashboard summary for writers (my drafts, my published works) added through the existing filters
- [x] Accessible forms: labelled fields, an error summary that links to each problem, and keyboard-operable reordering
- [x] Deleting a story or chapter asks for confirmation on a separate page, not a script prompt
- [x] Tests: every route against every role and ownership case, hostile input in every field, tokens, and the unsaved-text behaviour

### LF-026. Publishing workflow and the approval queue

**Status:** Complete (8 of 8) — released in 1.0.0

How a story moves from draft to published, and how new Authors are held for review. Part of the publishing milestone (M2); depends on LF-023 and LF-025. This ticket owns the approval queue that LF-008 and LF-011 refer to.

Implemented 2026-10-17. Choices worth knowing: the state graph is `PublicationRules` and nothing else moves a story; "first story" means no other story of the author's has ever been published (`published_at` set), so a rejected first story keeps the hold; a per-writer decision (`users.publishing_override`, `direct` or `review`) beats the site rule; a rejection is a draft that carries `rejection_reason` until the story is submitted or published again; approving re-checks the required classification; the `story_state_changed` action fires after the change is saved. The administrator's buttons for granting or taking back direct publishing, and for unpublishing a suspended writer's stories, are not built because no ticket builds a Users screen yet: `StoryService::setPublishingOverride()` and `unpublishAllByOwner()` are done and tested, and whichever ticket adds that screen only has to call them.

- [x] State rules in one place: an Author may submit, withdraw, and unpublish their own work; Editors and Administrators may publish and unpublish anyone's; no other transition exists
- [x] When `publishing.hold_first_story` is on, an Author's first story goes to "pending approval" instead of publishing, and later stories publish directly; an Administrator can grant an Author direct publishing or take it back (service and tests; the screen buttons came with the Users screen)
- [x] Approval queue screen for Editors and Administrators: pending stories with preview, approve, and reject with a reason shown to the Author
- [x] An Author sees the state of each story, and the reason for a rejection, on their own screens; a rejected story returns to draft
- [x] Unpublishing keeps the story, its chapters and its links, and removes it from every public listing, feed and search result at once
- [x] Each transition is written to the audit log with who did it and why, and calls a hook other features use to notify people (LF-011)
- [x] A suspended Author's published stories can be unpublished in one step, without deleting them (service and tests; the screen button came with the Users screen)
- [x] Tests: every transition for every role, the first-story rule on and off, a rejected story's reason, and that nothing pending, rejected, or unpublished is ever visible to the public

### LF-027. Theme renderer and theme API

**Status:** Complete — released in 1.0.0 (10 of 10)

The mechanism that turns data into public pages. Part of the public archive milestone (M3); depends on LF-003. Themes are classic PHP templates; there are no block themes and no `theme.json`. Creates `docs/THEME-DEVELOPMENT.md`.

- [x] `ThemeRenderer` that finds the active theme under `content/themes/`, resolves the template for a request through a documented template hierarchy, and falls back to a built-in minimal theme if the active one is missing or broken
- [x] Theme metadata read from the theme's stylesheet header (name, version, author, description), validated, never executed
- [x] The template files themes may provide: header, footer, sidebar, home, story, chapter, author, archive (listings), search, page, 404, and functions
- [x] Template tags for everything a theme needs (site name and address, story and chapter fields, taxonomy links, pagination, navigation, asset URLs), all escaping their output, so a theme never touches the database or the request directly
- [x] Hooks for head and footer assets, so scripts and styles a feature needs are added by that feature and not copied into each theme; the Content-Security-Policy still allows only this site's own files
- [x] Theme options with the rule that an option with a CSS variable defaults to "use the theme's own value" and never overrides a theme author's design
- [x] Core logic (security checks, sanitising, token handling) never lives in a template; `docs/THEME-DEVELOPMENT.md` says so and shows the template tags and hooks to use instead
- [x] The owner and staff previews of a draft story and chapter (`story-preview` and `chapter-preview` in the admin views, written in LF-025 on top of `ContentRenderer` only) render through the active theme's story and chapter templates, so a preview is exactly what readers will see
- [x] An Appearance screen to see installed themes and choose the active one (installing from a ZIP comes with the updater work)
- [x] Tests: hierarchy resolution, fallback to the built-in theme, escaping in every template tag, a broken theme not taking the site down, and a theme file being unable to read configuration or credentials through the API

Implemented 2026-10-18. Choices worth knowing: the built-in theme lives in `app/Core/Theme/fallback/` (outside `content/`, so no upload or deletion can remove it) and its stylesheet is served by a route, because `app/` is closed to the web. Theme PHP files are denied to direct requests by `content/themes/.htaccess`. Template tags are plain functions that forward to `ThemeContext`; a controller hands a view one array, strings in it are always escaped, and only `SafeHtml` is printed as markup. `StoryPresenter` builds the story and chapter data for both previews and (later) the public pages, and leaves out owner ids and rejection reasons. The options stylesheet is a route (`/theme-options.css`) because the policy forbids inline styles, and it is empty until an administrator picks something. `theme.active` is empty until chosen: empty means "the default theme if it is installed, else the built-in one", so a site without Storybook yet gets no false warning. `/` now answers with the home template (no stories listed yet; that is LF-029), and the 404 and 405 pages go through the theme. Not done on purpose: the `author`, `archive`, `search` and `page` templates exist in the hierarchy but nothing renders them until LF-029 and LF-030; the built-in theme has no sidebar content; PHP cannot sandbox a theme, so the guarantee is for honest themes and the docs say so.

### LF-028. Storybook theme

**Status:** Complete — released in 1.0.0 (9 of 9)

The default theme, designed for reading. Part of the public archive milestone (M3) and the reader experience (M4); depends on LF-027.

- [x] Templates for home, story, chapter, author, listings, search, page and 404, all in the theme and none in core
- [x] Readable serif text for stories with generous spacing and a comfortable line length, and an interface typeface for everything else, using fonts the visitor already has or ones bundled with the theme (nothing from an outside service)
- [x] Light and dark colours that follow the visitor's system and can be switched by hand, with contrast that meets WCAG AA in both
- [x] Reader controls for text size and reading width, remembered in the visitor's browser, and working without scripts at their defaults
- [x] Distinct previous and next chapter navigation at the top and bottom of a chapter, and a chapter list on the story page
- [x] Story metadata (rating, warnings, status, word count, dates, tags) laid out so it is scannable on a phone, with each taxonomy term a link
- [x] Skip links, landmarks, visible focus, and keyboard operation throughout
- [x] A print layout that shows the chapter text and hides navigation and controls
- [x] All styling in the theme's stylesheet using stable `lf-` class names, with no inline styles, so another theme can restyle everything without editing a template

The account page templates moved to LF-022, where their data and forms are built.

Implemented 2026-10-18. Choices worth knowing: the theme lives in `content/themes/storybook/` and is the default when no theme is chosen. The reader's colour scheme, text size and reading width are three `data-lf-*` attributes on the root element, set by `js/reader.js` from the browser's storage; the script is added by `functions.php` as a plain script in the head (a deferred one would flash the wrong colours), and without it every control stays hidden and the page uses its defaults. A colour option never overrides the stylesheet: the only option with a CSS variable is the story typeface, and it starts as "theme default". Contrast is checked by a unit test that reads the colour tokens from `style.css`. `StoryPresenter` gained `story.published` and `story.updated`. The `author`, `archive` and `search` views share one list template that reads a `stories` list of cards (title, url, author, author_url, summary, status, words, updated), which LF-029 and LF-030 supply; until then those pages show the empty message. Term links are plain text until LF-029 gives terms addresses.

### LF-029. Public archive pages

**Status:** Complete — released in 1.0.0 (8 of 8)

What readers see. Part of the public archive milestone (M3); depends on LF-023, LF-024, LF-027 and LF-028. These pages are also where the feeds (LF-012), sitemap (LF-014), and caching (LF-017) get their addresses.

- [x] Home page listing recently published and recently updated stories
- [x] Story page: summary, notes, disclaimer, metadata, cover, chapter list, and a start-reading link
- [x] Chapter page: the text through the content pipeline, the author's notes, previous and next links, and the position in the story; a one-shot story opens straight to its text
- [x] Author page at the author's public address, showing the display name and published stories only; the login name and email never appear
- [x] Listings for each taxonomy term (fandom, genre, tag, category, and so on) with sorting and pagination
- [x] A draft, pending, rejected or unpublished story answers exactly as a missing one does, so its existence is not revealed to the public; its owner and staff see a preview instead
- [x] Guest access setting: the Administrator can require sign-in to read, in which case guests are sent to sign in
- [x] Tests: every visibility state as guest, other Author, owner, and staff; unpublished and deleted stories; hostile content in every field; pagination limits; and that no page output contains a login name or email

Addresses are `/story/{slug}`, `/story/{slug}/{number}`, `/author/{slug}` and `/{kind}/{slug}` for each kind of term (`/fandom`, `/tag`, `/relationship-category` and so on), built in `PublicUrl`. The addresses are clean and never change. The permanent redirect for a changed slug was declined (see `DECISIONS.md`); redirects live in LF-014, to be built together with whatever makes a slug editable. The cover is not shown because covers are LF-015.

Implemented 2026-10-18. Choices worth knowing: `ArchiveController` serves every archive page and `PublicArchive` runs the list queries, all starting from `StoryVisibility::publicCondition()`; `StoryPresenter::card()` is the one place that builds the `stories` card list. Not-found is `ThemeRenderer::notFound()`, shared with the kernel, so a hidden story and a missing one are the same page. Its owner and anyone who may edit it (`StoryService::canManage()`) are redirected to the admin preview; everyone else, signed in or not, gets not-found. A term page, and an author page, answer not-found when there are no public stories to list, so a tag proposed on a draft or a reader's account cannot be found by guessing an address. A one-shot is a finished story with exactly one readable chapter: its text is on the story page. A chapter's position is counted among readable chapters, so a hidden draft in the middle leaves no gap. Pages are 20 stories, `?page=` must be a whole number from 1 up to the last page (anything else is not-found), and `?sort=` falls back to recently updated. Kinds of term the archive mode switches off are not shown and their pages are not found. The sign-in-to-read setting (`reading.require_signin`, Settings > Archive) redirects guests to the admin sign-in page, which only people with admin access can use today; `ArchiveController::signInUrl()` is the one place to point at the public sign-in page when LF-022 builds it. Pages for signed-in readers when the setting is on are sent `private, no-store`. Plugin routes cannot take over a core address because core registers first.

### LF-030. Search, browse and filters

**Status:** Complete — released in 1.0.0 (7 of 7)

Finding stories. Part of the public archive milestone (M3); depends on LF-024 and LF-029.

- Search across title, author display name, summary, and tags: the full-text index part moved to `Future Enhancements.md` on 2026-10-21; matching stays `LIKE`.
- [x] Filters for rating, status of the work, language, word count range, and fandom, genre, tag or category, combinable, with the choices reflecting the archive mode
- [x] Sorting by recently updated, recently published, title, and word count
- [x] Pagination that is cheap on large archives (keyset or bounded offset) and never reveals the total of hidden stories
- [x] The search form and results work without scripts, with the active filters shown and removable
- [x] Only public stories are ever searched or counted, whatever the query or filter
- [x] Every value reaches the database as a bound parameter, search text is length-limited, and a very expensive query is refused rather than run
- [x] Tests: each filter and sort, combined filters, hostile search text, drafts and unpublished stories never matching, and behaviour on a large generated archive (using the Dummy Content plugin, LF-020)

Built 2026-10-18. Choices worth knowing: the page is `/search` (`ArchiveController::search()`), words are matched with `LIKE` against title, summary, author display name and tag names (every word must match somewhere), and the query is built in `PublicArchive::searchCondition()` from `StoryVisibility::publicCondition()` plus bound parameters. `SearchQueryParser` turns the address into a `SearchQuery` (at most six words, 100 characters, 50 per word; a one-letter search is refused with a notice), `SearchPresenter` builds the form, the removable filter list and the page addresses. Term filters are typed or picked by name and resolved through `TaxonomyService::resolve()` (aliases work); a name that matches nothing finds nothing. Counting stops at 1,000 (`PublicArchive::SEARCH_LIMIT`), so there are at most 50 pages. The large-archive test generates 1,100 stories with SQL because the Dummy Content plugin (LF-020) does not exist yet. `LIKE '%word%'` cannot use an index, so a full-text index is parked in `Future Enhancements.md`.

### LF-031. Reader features: bookmarks, reading progress and preferences

**Status:** Complete (7 of 7) — released in 1.0.0

Implemented 2026-10-19. Choices worth knowing: three migrations (0016 bookmarks, 0017 reading_progress, 0018 `users.reader_preferences`) with no foreign keys, matching the other tables; a bookmark on a whole story has `chapter_id` 0 so one unique key rejects duplicates, and chapters are referenced by id so reordering never moves a bookmark. Progress is recorded when a signed-in reader opens a chapter page (a `GET` with a write behind it, on purpose, so it needs no script), one row per reader and story. Hidden stories are left out of every list by joining `StoryVisibility`, never deleted, so a bookmark returns when the story is public again. Preferences reach the theme's script through three meta tags core writes into the head (`ReaderHead`), and Storybook's script posts changes back with a token that is renewed in each reply. Pages built for a signed-in reader are marked `private, no-store`. A reader can keep 500 bookmarks. Account deletion does not exist yet, so its call to `deleteForUser()` is a checklist line in LF-016.

- [x] Bookmark a story or a chapter while signed in, with a "My bookmarks" page and removal, using the Bookmark capability
- [x] Reading progress remembered for signed-in readers, a "continue reading" link on the story page and home page, and no tracking of guests
- [x] Reader preferences (text size, width, light or dark) kept in the browser for everyone and in the account for signed-in readers
- [x] Bookmarks and progress belong to the account: one person cannot read or change another's (deleting them with the account moved to LF-016, where account deletion is built)
- [x] Bookmarks to stories that are later unpublished disappear from the list without revealing why
- [x] Every action is a POST with a CSRF token and works with a plain form when scripts are off
- [x] Tests: ownership, unpublished stories, duplicate bookmarks, guests, and the per-account deletion methods

### LF-032. Plugin loader and Plugins screen

**Status:** Complete — released in 1.0.0 (7 of 7)

The hook functions exist (LF-003); nothing loads plugins yet. LF-019 (Sweep) and LF-020 (Dummy Content) are bundled plugins and need this first. Depends on LF-006.

- [x] Plugin discovery under `content/plugins/{slug}/`, reading a header (name, version, author, description, requirements) from the main file without running it
- [x] An activated-plugins setting, a load step in the kernel that includes each active plugin's main file after the core services are ready, and a hook plugins use to register routes, menu entries and settings
- [x] A plugin that throws while loading is skipped, recorded in the error log and the audit log, and flagged on the dashboard, and never takes the site down
- [x] Plugins screen under Maintenance for Administrators: list, activate, deactivate, with the requirements check (PHP and application versions) shown before activating
- [x] A plugin's slug is validated, and a plugin cannot load files outside its own folder through the loader
- Custom plugins live beside bundled ones in `content/plugins/`; keeping them across updates is now a line in LF-010.
- [x] Documentation in `docs/DEVELOPER-APIS.md`: plugin structure, lifecycle, and the hooks and filters available
- [x] Tests: discovery, activation, a broken plugin, a malicious slug, and the requirements check

Built 2026-10-21. Choices worth knowing: a plugin is `content/plugins/{slug}/{slug}.php`, and the loader includes only that file, after `PluginCatalog::find()` has checked the slug pattern and that the real path stays inside the plugin's own folder (a link pointing out is ignored). The header is a `Plugin Name:`, `Version:`, `Author:`, `Description:`, `Requires PHP:`, `Requires at least:` comment read from the first 8 KB as text. `PluginManager` keeps the `plugins.active` list and a `plugins.failures` map in settings; a plugin is run once as it is turned on (so a broken one is refused), and a plugin that fails later stays in the active list, is skipped, and is noted in the error log and the audit log (`plugin.load_failed`) only when its failure message changes, so a broken plugin does not fill the log on every page view. `PluginNotices` adds the dashboard notice through `admin_dashboard_notices`. The loader uses `require`, not `require_once`, because a file runs once per request anyway and the test harness boots several kernels in one process. New actions: `plugins_loaded`, `plugin_activated`, `plugin_deactivated`. Failure text shown to an administrator has the server's folder name removed. Hooks a plugin registers before it throws stay registered for that request (documented); there is no snapshot-and-undo.

### LF-034. News posts

**Status:** Complete (11 of 11) — released in 1.0.0

Implemented 2026-10-25. Choices worth knowing: the table is `news_posts` (migration 0029) and the slug starts with the post's number, follows the title until the first publication and is fixed after, so it needs no counters; `unpublish` returns a post to a draft and keeps its address and first date. `Settings > News` is its own screen (`NewsSettings`, `news.enabled` and `news.home_count`), not a field on Archive settings. News ignores "visitors must sign in to read" (see `DECISIONS.md`). The News menu link is added in `ThemeRenderer::navigation()` while the feature is on. `WriterController::personWith()` generalises the session-ended page to a screen guarded by another capability. The feed and sitemap lines already sit in LF-012 and LF-014. No `custom themes/` folder exists, so nothing was propagated. Browser-checked on the dev site (admin form, publish, home block, News page and a post, dark mode and phone width); the 8.2, 8.3 and 8.5 matrix has not been run for it.

Site announcements written in the admin and shown to readers, as eFiction's news and AO3's admin posts do: downtime, rule changes, events, new features. It is deliberately small. A news post is a titled, dated announcement from staff, not a blog: no categories, tags, comments, scheduling or per-author blogs, and no reuse of story tables, so the archive stays about stories. Part of the public archive milestone (M3); depends on LF-007 (editor and content pipeline), LF-027 (theme renderer) and LF-029 (home page). Feeds (LF-012) and the sitemap (LF-014) pick it up from the lists below.

Plan:

- **Data.** One `news_posts` table: author (not a foreign key, like stories), title, URL slug unique among news posts and fixed after the first publication, body with a content format (the same pipeline and sanitiser as chapters, so Markdown, HTML, plain text and the Visual editor all work), state (`draft` or `published`), an optional "pin to the top" flag, first publication date and updated date. A `NewsService` owns every rule, like `StoryService`.
- **Who.** A new capability `PublishNews` for Editors and Administrators. An Editor may edit any news post, since news is site-wide rather than owned, and every change is written to the audit log.
- **Admin.** A News menu with the list (search, filter by draft or published) and an add and edit form that reuses the editor from LF-007, with Save draft, Publish, Unpublish, a preview through the theme, a confirmation page for deleting, and the unsaved-changes guard. Written the same way as the writer screens: server-side checks, one form token action, the session-ended page for long text.
- **Public.** Through theme templates, none in core: the latest posts on the home page (how many is a setting, zero hides the block), a news archive page with pagination, and a single post page. Template tags give a theme the latest posts, one post, and the news address. A single post shows its title, date, author's display name (never the login name) and body.
- **Switch.** A setting turns the whole feature off, which hides its menu entry and public pages and keeps the posts, as the taxonomy switches do.
- **Out of scope.** Comments, categories and tags, scheduled publishing, email or subscriber notifications, and any link to a story. If a site wants more, it is a plugin.

- [x] Migration and `NewsPost` model and `NewsService` (create, update, publish, unpublish, delete, list, find public by slug); the slug never changes after first publication; a draft is never returned by a public lookup
- [x] `PublishNews` capability for Editors and Administrators, enforced in the service and on every route, with every change written to the audit log
- [x] Admin News menu entry and list with search, status filter, pagination and a count on the dashboard
- [x] News form: title, body through the content editor and pipeline, pin flag, Save draft, Publish, Unpublish, theme preview, delete on a page of its own, unsaved-changes warning, and the session-ended page that returns typed text
- [x] Settings for turning news off and for how many posts the home page shows; a disabled feature hides its menu entry and public pages and keeps the posts
- [x] Template tags and the `news-archive` and `news-single` templates, with the home block, documented in `docs/THEME-DEVELOPMENT.md`
- [x] News pages added to both bundled themes (LF-028, LF-033) with dark-mode styles and `lf-` classes, and the same additions made to every theme in `custom themes/`
- [x] Pinned posts sort first, then newest first; a published post is public at its own address and in the archive and nowhere else until published
- [x] News feed and sitemap entries added to LF-012 and LF-014 when those exist (a one-line addition to each ticket now)
- [x] README, CHANGELOG and `docs/DEVELOPER-APIS.md` updated, including a hook fired when a post is published
- [x] Tests: every route against every role (guest, Reader, Author, Editor, Administrator), drafts never public or in feeds, hostile markup in title and body, the disabled switch, pagination limits, pin ordering, and that no output contains a login name or email

### LF-035. Users screen

**Status:** Complete (8 of 8) — released in 1.0.0

The administrator's screen for the people on the site. The services already exist: `UserService::setStatus()`, `UserService::setRole()`, `StoryService::setPublishingOverride()` and `StoryService::unpublishAllByOwner()`. This ticket builds the screen that calls them and must not reimplement their rules. Part of the publishing milestone; it needs `ManageUsers`, which only Administrators hold, and sits next to the account pages (LF-022).

Implemented 2026-10-19. Choices worth knowing: a new `UserAdminService` holds the rules the old setters lack (self-change refused, last active administrator kept) and calls the existing services for the writing, so a screen or plugin cannot skip them; `UserService` gained `search()` and `countMatching()` for the list. The last-administrator check locks the active administrators (`FOR UPDATE`) inside the transaction, so two administrators demoting each other at once cannot both succeed. Suspending takes the stories out of view first and suspends second, so a failure part-way leaves the account active and running it again carries on; a suspended person's session ends on their next request because `AuthService::user()` already refuses anyone not active. Reinstating returns a person whose address was never confirmed to pending only when `registration.email_verification` is on, since accounts made with it off are active and unconfirmed. Leaving the Author role clears an author's publishing right. The reason is optional free text kept in the audit log. Publishing-rights changes record who and what but no reason, because `StoryService::setPublishingOverride()` already audits them. Login names are never searched or printed; test accounts use email addresses that do not contain them so that is checked.

- [x] **Users** menu entry (`ManageUsers`) and a list with search by display name or email, filters for role and status, pagination, and the date each person joined; the login name is never shown
- [x] A person's page with their display name, role, status, joined date, email confirmed or not, story counts by state, and a link to their public page (`/author/{slug}`, which only answers once they have published something)
- [x] Change a role, with the rule that the last active Administrator can be neither demoted nor suspended, and that nobody can change their own role or status here
- [x] Suspend and reinstate, each asking on a page of its own, with an optional "also unpublish all their stories" step that calls `unpublishAllByOwner()` and tells the administrator how many stories it took down
- [x] Per-writer publishing rights (follow the site setting, publish without review, always review) through `setPublishingOverride()`, with the effect explained on the page
- [x] Every change written to the audit log with who did it and why, and a hook fired for each so a notification feature (LF-011) can use it
- [x] README, CHANGELOG and `docs/DEVELOPER-APIS.md` updated
- [x] Tests: every route against every role and a guest, the last-administrator guard, no self-demotion, a suspended person unable to sign in or write, stories unpublished in one step, override effect on the next publish, CSRF on every post, and that no output contains a login name

### LF-036. Links between the public site and the admin

**Status:** Complete — released in 1.0.0 (5 of 5)

Staff move between the two sides all the time, so each side links to the other. Part of the public archive milestone (M3); depends on LF-027 and LF-006.

Plan: the public link is added by core through the existing `lf_navigation_items` filter, so every theme, bundled or custom, shows it with no template change and no logic in a theme. It appears only for a signed-in person who holds `AccessAdmin`; a guest or a Reader never sees it and never learns it exists. The admin side gets a plain **View site** link in the admin header.

- [x] A core listener adds an **Admin** item to the main menu for a signed-in person with `AccessAdmin` (Authors, Editors and Administrators), pointing at the admin dashboard; guests and Readers get nothing
- Never serving a page that carries the link to other visitors is covered by the signed-in-user rule in LF-017.
- [x] A **View site** link in the admin header, always visible, that opens the public home page (same tab, with the address built from the install's base directory)
- [x] Both links are reachable by keyboard, have visible focus, and read clearly to screen readers; the admin link gets `lf-` styling from each theme's existing menu rules, so no theme needs a new rule
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` (the menu now may carry a staff item) updated
- [x] Tests: the item appears for each role that holds `AccessAdmin` and not for a guest or Reader, it is absent from the markup rather than hidden, the admin header link points at the right address under a base directory, and neither link carries a login name or email

### LF-037. Browse pages: term indexes and New / Recent

**Status:** Complete — released in 1.0.0 (11 of 11)

Today a reader can only reach a fandom, genre or tag page by already knowing its name (or by following a link from a story), and the only places that show what is new are the home page and the search sort. This ticket adds the pages a person uses to look around: an index of every fandom, genre, category and so on, and plain "New", "Recently updated" and "Completed" lists. Part of the public archive milestone (M3); depends on LF-029, LF-030 and LF-024. Planned only; nothing is built yet.

Plan:

- **Addresses.** All under `/browse`, so they can never collide with the first segments core already owns (`/fandom`, `/tag`, `/search`, ...): `/browse` (a hub that links to the rest), `/browse/{kind}` for each kind of term in the plural (`/browse/fandoms`, `/browse/genres`, `/browse/categories`, `/browse/tags`, `/browse/ratings`, `/browse/warnings`, `/browse/relationship-categories`), and `/browse/new`, `/browse/updated` and `/browse/completed`. Built in `PublicUrl` and registered in `Kernel::registerPublic()` after the core routes, like the other pages.
- **Term indexes.** One query per page from `TaxonomyService`, never one per term: each name links to its existing listing (`/fandom/good-omens`) and shows its count of public stories (`storyCount`). Only terms with at least one public story are listed, so an index cannot reveal a tag that exists only on a draft, which is the same rule the term pages already follow. Only reviewed terms are listed: a tag an author just proposed has a listing page but is not advertised on the index until an administrator has reviewed it (confirm with Ariane). Order is A to Z by default with a "most used" switch; a large index is split by first letter (letters are plain links, with `#` for names that start with a digit or a symbol) and by page within a letter, so a site with tens of thousands of tags stays fast and readable. Characters and relationships have no index of their own, because there are too many and they only make sense inside a fandom: a fandom's listing page gains links to its characters and relationships instead (a small addition to the term page, to be settled when this is built).
- **Archive mode.** A kind that the archive mode switches off is not offered on the hub and its index answers "not found", exactly as its term pages do. Categories only exist for original fiction and fandoms only for fanfiction.
- **New, Recently updated, Completed.** These are the existing story list (`PublicArchive`, `StoryPresenter::card()`, the `archive` view and its sort links and pagination) with a fixed sort and filter, not new queries: New is newest first by first publication, Recently updated by last chapter, Completed is finished stories newest first. Same 20 per page, same visibility rule.
- **Menu.** One new **Browse** item in the main menu through `lf_navigation_items`, linking to the hub, so the menu stays short; the hub then offers the term indexes and the three lists. No script is needed anywhere.
- **Themes.** A new view `browse` (falling back to `archive`, then `index`) for the hub and the indexes, with the data bundled as one array the theme forwards, per the theme rules. Storybook and the built-in theme get the templates, `lf-` classes, CSS, dark mode and phone layout; the New, Updated and Completed pages reuse the `archive` view with their own heading. Everything is documented in `docs/THEME-DEVELOPMENT.md`, and any theme under `custom themes/` gets the same additions in the same session.
- **Elsewhere.** The addresses are added to the sitemap when LF-014 is built, the New and Updated lists are the natural source for the site feed in LF-012, and a page cache (LF-017) can key on them.

- [x] `PublicUrl` addresses and routes for the hub, the term indexes and the three lists, with the plural kind names in one place
- [x] `TaxonomyService` (or a small `BrowseService`) query for an index page: public-story counts in one statement, reviewed terms only, A to Z or most used, first-letter filter and pagination, bound parameters throughout
- [x] Term index pages for fandoms, genres, categories, tags, ratings, warnings and relationship categories, with letter navigation and counts, hidden for any kind the archive mode switches off
- [x] A fandom's listing page links to its characters and relationships (built as a `related` list of the 20 most used reviewed ones of each kind, in the page's header area)
- [x] New, Recently updated and Completed pages built on the existing story list, with the fixed sort and filter, pagination and the usual visibility rule
- [x] A **Browse** hub page and a **Browse** item in the main menu through `lf_navigation_items`
- [x] A `browse` view in Storybook and the built-in theme (templates, `lf-` classes, CSS, dark mode, phone width), and the same additions in every theme under `custom themes/` if any exist then
- [x] Accessible by keyboard and without scripts: real headings, a letter navigation that is a list of links with the current letter marked, and counts that read clearly to screen readers
- [x] Tests: each index and list as guest and signed in; a draft, pending or unpublished story never counts toward or reveals a term; an unreviewed term is not listed; a kind switched off by the archive mode answers not found; letter and page limits, including a hostile letter or page value; the New and Updated order; no output contains a login name or email
- [x] A large-archive test (compared 5 and 250 generated terms; thousands would only slow the suite) showing an index page needs a fixed number of queries
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` updated

### LF-038. Edit links in the public chapter list

**Status:** Complete — released in 1.0.0 (5 of 5). Built 2026-10-23. The story-level `edit_url` was left out: the preview banner already links the story's edit form and the story page needs only the chapter links. No `custom themes/` folder exists, so nothing was propagated. Not yet looked at by Ariane in a browser.

The writer who is looking at their own story page has to go back to the admin and find the chapter. Each chapter in the story page's chapter list should carry an **Edit** link for anyone who may change that story. The admin chapter list already links each title to its edit screen, so this is only about the public page (and its preview).

Plan: the decision and the address are made once, in `StoryPresenter` from the same rule the admin uses (`StoryService::canManage()`), and the theme only prints a link when the chapter entry carries an `edit_url`. Nothing is added for anyone else, so the address is absent from the markup rather than hidden. Because the story page varies by viewer, it must be sent `private, no-store` for the signed-in staff and authors it applies to, as the reader-feature pages already are (see LF-017 for page caching).

- [x] `StoryPresenter` adds an `edit_url` to each chapter entry (and to the story) when the viewer may manage the story, built with `AdminUrl`, and leaves it empty otherwise; the page data stays one bundled array
- [x] The story page marks such a page `private, no-store`, so a cached copy with the links is never served to another visitor
- [x] Storybook and the built-in theme print a small **Edit** link beside each chapter when `edit_url` is set, with an accessible name that includes the chapter title, `lf-` classes, dark mode and phone width; the same addition in every theme under `custom themes/` if any exist then
- [x] Tests: the link shows for the owner, an editor and an administrator and not for another author, a reader or a guest; the address never appears in a guest's page; the response is not cacheable for the people who see the links; a preview of a draft shows them too
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` updated

### LF-039. Writer links on the account page

**Status:** Complete — released in 1.0.0 (4 of 4)

A writer's way into the admin is a main-menu item called **Admin**, and nothing on the public site says where to start a story. The account page already has a shortcuts list above its forms (it holds **My bookmarks**), drawn by every theme from one `shortcuts` array, so writers' links belong there and need no theme change.

Plan: `AccountController` adds the links to the same `shortcuts` list, decided by capability on the server (`WriteStories` for the writing links), and built with `AdminUrl`. The labels do not mention "Admin". Readers who cannot write get nothing new. New accounts are Authors on this archive (Settings > Archive), so no way for a Reader to become an Author is built; an archive that registers Readers would need one.

- [x] **Post New Story** (the new-story form) and **My stories** shortcuts on the account page for anyone who holds `WriteStories`; Editors and Administrators also get **Awaiting approval**
- [x] The links are left out of the page for everyone else rather than hidden, and the page stays `private, no-store`
- [x] Both bundled themes show them with no template change (they read the existing list); the account page's CSS needs no new rule unless a longer list wraps badly at phone width
- [x] Tests: each role sees exactly the links it may use, a guest's and a Reader's page carry none of the admin addresses, and the addresses follow a base directory; README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` updated

### LF-040. Pasting from Google Docs and Word

**Status:** Complete — released in 1.0.0 (7 of 7)

Found by pasting a real Google Docs passage into each editing mode. Google Docs marks italics, bold and underline with a style on a span (`font-style:italic`), and every editing mode throws styles away, so the formatting is lost: in Visual mode the saved chapter came out as plain `<span>` wrappers with the italic title as ordinary text, and in Markdown mode only the plain text of the clipboard arrives, so the italics never get there. Word adds its own leftovers: marker text such as `<![if !supportLists]>` printed on the page, an empty paragraph from `<o:p>`, bullets turned into a "·" character, and tables flattened. The saved page also carries a lot of empty `<span>` tags and a trailing `<p><br><br></p>`.

Plan: the loss happens in two places, so each is fixed where it happens. The Visual editor filters a paste before the page's own rules see it, so a `paste_preprocess` step in `content-editor.js` turns the style markers into real tags first (a style never reaches the stored text). The server sanitiser does the same for text typed or pasted in HTML mode and tidies what is stored, which also cleans old chapters when they are shown, because chapters are sanitised on display. A formatting that was already lost from a chapter that was saved earlier cannot be brought back; it has to be pasted again. No inline style is ever allowed through; the styles are read, translated and discarded.

- [x] Visual editor: a paste step that turns spans styled italic, bold (weight 600 or more, or `bold`) or underlined into `<em>`, `<strong>` and `<u>`, removes Google Docs' `docs-internal-guid` wrapper and Word's marker text, and leaves everything else to the existing rules
- [x] Server sanitiser: the same translation for HTML mode, before styles are dropped; spans with no class are unwrapped, so no empty `<span>` is stored
- [x] Server sanitiser: empty wrappers (`<b></b>`) and paragraphs that hold only line breaks are removed, and Word's conditional-comment text and `<o:p>` leave no trace
- [x] Markdown editor: pasting reads the clipboard's HTML and inserts Markdown (`*italic*`, `**bold**`, links, headings, lists, paragraph breaks) instead of the plain text only; a paste with no HTML part behaves as before
- [x] Decided: Word list paragraphs become real lists (bullet or number from the marker, nesting levels flattened) and a pasted table becomes one paragraph per cell; done in the server sanitiser. Turn Word's list paragraphs (bullet and number markers with a list style) into real lists, and keep or flatten a pasted table (tables are not allowed, so likely flatten)
- [x] (Sanitiser tests written and passing, `paste-clean.js` checked in the browser pane, a real Docs passage pasted into the Visual and Markdown editors on the dev site by Ariane, and the PHP matrix passing 2026-10-21.) Tests: the pasted Google Docs and Word samples from the report, in the sanitiser, the content renderer and the HTML-to-Markdown path; that no style, class or script survives; that old stored chapters show without empty spans; the script is checked in a browser because no JavaScript runs in the test suite
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` (the allowed markup is unchanged, so likely only a README sentence) updated

### LF-041. Continue reading page

**Status:** Complete — released in 1.0.0 (9 of 9)

The home page shows only the last three places a signed-in reader left off (`ReadingProgressService::recent()`), and there is nowhere to see the rest. Progress is kept for every story a reader has opened, so the full list grows without limit. This ticket moves the list off the home page onto a page of its own, linked from the account page, with paging and a way to drop entries. Builds on LF-031.

Plan: a new account page at `/account/continue`, built like the bookmarks page (one list of story cards plus the chapter to resume at), newest first, paged by the usual page size, and private (`private, no-store`). Only stories a reader can still open are listed, by the same public-visibility rule as every other list, so a story that was unpublished or deleted simply drops out and never leaks. Forgetting an entry is a post with a form token, like removing a bookmark. The **Continue reading** list is removed from the home page, so the account page and this page are the only places it lives.

- [x] `ReadingProgressService`: a paged `listFor()` and a `countFor()` that join to stories through `StoryVisibility::publicCondition()` and to the chapter to resume at, in a fixed number of queries
- [x] `/account/continue` route and `PublicUrl` constant, with the page number validated like the bookmarks page, and an out-of-range page answering not found
- [x] A `continue` view in Storybook and the built-in theme (falling back to the bookmarks view's list markup if a theme has none): each entry shows the story, the chapter to resume at and when it was last read, in `lf-` classes with dark mode and phone width
- [x] A **Continue reading** shortcut on the account page next to **My bookmarks**
- [x] Remove **Continue reading** from the home page: the `continue_reading` data and its query in `ArchiveController`, the section in Storybook's and the built-in theme's `home` view, its CSS, and the line in `docs/THEME-DEVELOPMENT.md`; the home page tests that expect it are changed to expect its absence. (A theme under `custom themes/` that prints it would simply find the key missing, so none is broken.)
- [x] Forget one entry, and clear the whole list (asks for confirmation on a page of its own); both are CSRF-protected posts that touch only the signed-in reader's rows
- [x] Decide when building: whether a story whose last chapter has been reached is marked **Finished** or hidden from the list (decided 2026-10-23: marked **Finished**)
- [x] Tests: ordering and paging, that a draft, unpublished or deleted story never appears, that a reader sees and removes only their own entries, guests are sent to sign in, the link appears in the account page and on the home page only when needed, and a large list (hundreds of entries) uses a fixed number of queries
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` updated

### LF-042. "Updated since you last read" notice

**Status:** Complete — released in 1.0.0 (13 of 13)

A reader who comes back to a story they were reading should be told if it has changed since they last opened it, without having to compare chapter lists. The notice is optional: each reader chooses whether they want it. Builds on LF-031 (reading progress and reader preferences) and sits well with LF-041.

Plan: most of the data already exists. `reading_progress` holds when a signed-in reader last opened each story, a small new `story_follows` table records which stories a reader has chosen to follow (neither reading nor bookmarking follows a story on its own), and chapters carry `published_at` and `updated_at`. A story counts as updated for a reader when it has a chapter that the reader can open and that was published after their last visit (a **new chapter**), and, as a separate and quieter case, a chapter they have already opened was edited after it (a **revised chapter**). Both are decided on the server with the public-visibility rule, so a draft or hidden chapter never triggers a notice. The notice is a line on the story page, drawn by the theme from the existing `notices` list, with no script and no theme logic; it is never shown to a guest, because guests are not tracked. The choice is a field in the **Reading** form on the account page and lives in the reader's saved preferences, so that choice needs no migration.

- [x] A service method that, for one reader and one story, returns the number of new chapters and whether any opened chapter was revised since the reader's last visit, in one query, using the public-visibility rule
- [x] The story page shows a short notice such as "2 new chapters since you last read" (with a link to the first new one) or "A chapter you read was revised", through the existing `notices` data, in both bundled themes with `lf-` classes, dark mode and phone width
- [x] The notice is computed before the visit is recorded, so opening the story does not erase it before it is seen
- [x] A reader preference in **Account > Reading**, **Tell me when a story I'm reading has been updated**, on by default or off by default (decide when building), stored with the other preferences
- [x] An **Updated stories** page at `/account/updates`, linked from the account page, listing every story the reader is following that has something new since they last read it (new chapters first, then revised ones), newest change first and paged; it shows for each story what is new and a link to the first new chapter, and answers with a plain "nothing new" message when there is none. "Following" is decided in the next item: only stories the reader has chosen to follow. Only stories the reader can open are listed, by the public-visibility rule, in a fixed number of queries
- [x] Following is always an explicit choice, kept in a small new table `story_follows` (reader, story, when it began; a migration). Reading a story and bookmarking it do not follow it by themselves. A **Follow this story** button on the story page (and **Stop following** once followed) is the main way; "since you last read" is measured from the reader's last visit to the story, or from when they began following if they have not read it
- [x] When creating a bookmark (story or chapter), an optional **Also tell me when this story is updated** tick box on the bookmark button's form, off by default, which follows the story; the bookmarks page shows whether a story is followed and offers to start or stop, and removing a bookmark does not stop the follow
- [x] A **Stop tracking** button on every story in the Updated stories page, which removes the follow so the story stops raising notices and leaves the list, while keeping the reader's reading progress and bookmark; the story page offers **Follow this story** again to undo it
- [x] A **Stop tracking all** button on the page, after a confirmation page of its own, for readers who want a clean slate; both are CSRF-protected posts that touch only the signed-in reader's rows
- [x] The account page's **Updated stories** link shows how many stories have something new, and nothing when the reader has switched the notice off
- [x] The Continue reading page (LF-041) marks entries that have something new, when that ticket exists
- [x] Tests: neither reading nor a bookmark alone follows a story, the Follow button and the bookmark tick box do, a follow with no reading is measured from when it began, removing the bookmark keeps the follow; stopping tracking hides a story from the page and from notices without losing progress or the bookmark, following again brings it back, a reader can follow, stop and see only their own entries, a draft or hidden story cannot be followed, and follows are removed with the reader's data; the Updated stories page lists only followed stories with something new, pages correctly, and never lists a draft, unpublished or deleted story or another reader's follows; new chapters counted, a draft or unpublished chapter never counted, an edit to a chapter the reader has not opened is not a revision, the preference switches the notice off, a guest and a Reader without progress see nothing, and the story page for a signed-in reader stays `private, no-store`
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` updated

### LF-043. Remember where you stopped reading

**Status:** Complete — released in 1.0.0 (7 of 7)

Reading progress today remembers only the chapter a signed-in reader opened last, so coming back to a long chapter means scrolling to find the place again. This ticket also remembers how far down the chapter the reader got, and offers to take them back. Builds on LF-031; the Continue reading page (LF-041) links to the saved place.

Plan: the reader script on the chapter page notes how far through the chapter text the reader has scrolled, as a whole-number share of the text (0 to 1000), and sends it when the page is hidden or closed (and at most every few seconds while reading), to a small account endpoint that uses the same one-time token pattern as the reading-preferences save. The share is stored on the existing `reading_progress` row (a migration adds one column), so there is still one row per reader and story. A share rather than a pixel count means it survives a change of window width, text size or device. When the reader opens that chapter again, the page offers a **Continue where you left off** button instead of jumping by itself, which would be disorienting, and it moves focus there only when pressed. The saved place is kept only for signed-in readers, like the rest of the progress, and goes with the entry when it is forgotten.

- [x] Migration: `reading_progress.position` (small integer, default 0); `ReadingProgressService` stores and returns it, ignoring anything outside 0 to 1000, and keeps the chapter and the place in step (opening a different chapter resets the place)
- [x] A save endpoint under `/account/` (POST, one-time token, signed-in only, touching only the caller's row for a chapter they can open), answering with the next token like the preferences save
- [x] The reader script sends the place when the page is hidden or closed, and while reading at a modest rate; it does nothing for a guest or when scripts are off, and a chapter that fits on the screen records nothing
- [x] The chapter page offers **Continue where you left off** when a place beyond the first screen is saved, as a button that scrolls and moves focus, with `lf-` classes, keyboard use, reduced-motion respected, dark mode and phone width; the saved place reaches the theme as one bundled array
- [x] The Continue reading page (LF-041) shows how far through the chapter the reader is, and its link opens the chapter ready to resume
- [x] Tests: the place is stored and clamped, a hostile or non-numeric value is refused, a reader can save only their own place and only for a chapter they can open, a draft chapter is refused, opening another chapter resets it, forgetting an entry removes it, and the endpoint needs its token; the script is checked in a browser because no JavaScript runs in the test suite
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` updated

### LF-044. Author information and picture on the author page

**Status:** Complete — released in 1.0.0 (12 of 12)

An author's page (`/author/{id-slug}`) shows only the heading **Stories by {name}** and the list, so a reader learns nothing about the person behind the stories. This ticket adds an optional **About the author** area above that heading, with a small square picture (avatar) beside the text, and an optional **Author profile** screen where the author writes the text, with the same editors as a chapter, and uploads the picture. Nothing is shown, and no empty box is drawn, until the author fills it in.

Plan for the picture: it follows the story cover pipeline (`CoverImageStore`), so it is not a second upload implementation. The real image type is read from the file, not trusted from the name; JPEG, PNG and WebP up to 2 MB; the picture is re-drawn by GD into a 100 by 100 square (a centred crop, with the same optional crop frame as the cover form but at a 1:1 ratio) under a random file name in `content/uploads/avatars/`, with orientation applied and all metadata dropped. Replacing or removing it deletes the old files. It is public by design, so the form says so. A short description (alt text) is not asked for: the picture sits beside the author's name and is decorative, so it is drawn with `alt=""`.

Where the forms live: the editors (the bundled Visual and Markdown editors, their preview and mode-switch endpoints, and the paste handling) are part of the admin and need its scripts, styles and signed-in checks. Putting them on the public account page would mean loading admin scripts and styles into a themed page, where they would look out of place and widen the page's security allowances. So the author's forms go on a new **Author profile** admin screen for anyone who holds `WriteStories` (a menu item under the writer's own entries), and the account page's **Quick links** gets an **Author profile** pill pointing to it. This follows the choice already made that all writing tools live in the admin.

Plan for the text: one new optional text field on the account, written in the same four editing modes as a chapter and stored in the same way (the content plus its format), then drawn through the existing content pipeline and the single allowlist sanitiser, so it gets the same formatting as a chapter (italics, bold, links, lists, quotes) and nothing can carry a script, a style or a disallowed tag. The author's own editing mode applies, as for chapters. It is public by design, so the form says so, and it is limited in length. The author page keeps its current rule that an account with nothing published answers not found, so a filled-in field on a reader's account never makes that account discoverable. The profile area is for people who can write stories; Readers do not get the form. The text reaches the themes in the existing bundled author data, so a theme that does not print it simply omits it and no custom theme breaks.

- [x] Migration 0023 (`about`, `about_format`, `avatar_file` on the users table; built as an `AuthorProfileService` rather than fields on the `User` model, so nothing that builds a `User` changed); a service method stores the text trimmed with line endings normalised, and refuses text over the limit (10,000 characters of stored content, counted on the visible text) with a message that names the limit
- [x] Admin: an **Author profile** screen and menu item for anyone who holds `WriteStories`, with an **About you** panel using the shared content editor component (all four modes, live word count, preview, unsaved-changes warning) and a single **Save** button instead of Save draft and Publish, and a hint that the text is shown publicly on the author page; saving is a CSRF-protected post that touches only the signed-in member's own account, and an empty value clears it
- [x] Account page: an **Author profile** pill in **Quick links** for anyone who holds `WriteStories`, decided on the server like the other links
- [x] Author page: `ArchiveController::author()` passes the rendered text as one bundled `author_about` value (empty when unset), and Storybook's and the built-in theme's `author` view print it in an **About the author** section above the **Stories by** heading only when it is not empty, in `lf-` classes with dark mode and phone width
- [x] Migration: a nullable column for the avatar file name on the users table (fresh-install schema and `User` model updated); `AvatarImageStore` (or `CoverImageStore` made reusable by size and folder, whichever leaves less duplicated code) validates and re-draws the upload to 100 by 100 under a random `{32hex}.{jpg|png|webp}` name in `content/uploads/avatars/`, with the same type, size and pixel limits, EXIF turn and metadata removal as covers, and plain messages on a host without GD or WebP
- [x] Admin: a **Picture** panel on the **Author profile** screen (multipart, CSRF-protected, own account only) to add, replace or remove the avatar, with the existing crop script reused at a 1:1 frame, a hint that the picture is shown publicly, and the current picture previewed
- [x] The picture is drawn in the **About the author** area beside the text (and shown on its own when only a picture is set), on the **Author profile** screen, and as `author_avatar` in the bundled author data (an address, empty when unset) in both themes with `lf-` classes, a fixed 100 by 100 size that does not shift the layout, a round or softly rounded frame, dark mode and phone width; a missing file shows nothing rather than a broken image
- [x] The text is rendered through `ContentRenderer` in whatever format it was stored (so Markdown, HTML, plain text and Visual output all go through the one sanitiser, and the pasted-formatting handling applies); links open as chapter links do, and a heading in the text is demoted so it cannot outrank the page's own headings
- [x] Moderation: the user detail screen in the admin shows the text and the picture to Administrators with **Clear author information** and **Remove picture** actions (CSRF-protected, written to the audit log), so public author content is never beyond an administrator's reach; deleting an account would also delete its picture files, but no account deletion exists yet, so that part has nothing to attach to until it is built: whoever builds it must call `AuthorProfileService::removeAvatar`
- [x] The author page's rule is unchanged and tested: an account with no published story still answers not found even when it has text, and the page stays cacheable as it is today
- [x] Tests: saving, trimming and clearing, the length limit, a Reader sees no form and cannot post one, one member cannot change another's text, formatting survives from each of the four modes while script, style attributes, forms and images are removed on the author page, the section is absent when empty, the administrator's clear action works and is logged; the avatar is stored at 100 by 100 under a random name, a non-image or oversize or disguised file (a script renamed `.jpg`) is refused, replacing and removing delete the old files, a Reader cannot upload, and a picture without text still shows; a page-level check that the section sits above the **Stories by** heading
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` (the new `author_about` and `author_avatar` values on the author view) updated

### LF-045. Suggest existing entries while typing in the story form

**Status:** Complete — released in 1.0.0 (8 of 8)

On the story form, fandoms, characters, relationships and tags are typed as comma-separated names, and the only guidance is a hint that a name matching an existing entry or its alias uses it. A writer cannot see what already exists, so spelling variants and near-duplicates get proposed (and an administrator later has to merge them). This ticket shows the matching existing entries while the writer types. (Ratings, genres, categories, archive warnings and relationship categories are already picked from a list, so they need nothing.)

Plan: a small script, loaded only on the story form, turns each of those four text fields into an accessible suggestion box (the ARIA combobox and listbox pattern). It asks a new read-only admin address (`GET /admin/taxonomy/suggest`, for the kind and the typed words) for up to eight matches and shows them under the field; choosing one fills in that name at the place being typed, since the field holds a comma-separated list. If the script does not run, the field works exactly as it does today, because the server still resolves names, aliases and new proposals as before. No outside service is used, and the script needs no inline code.

What is suggested: names that start with the typed words first, then names that contain them, then aliases (shown as "alias, same as Canonical name", so choosing one enters the canonical name). Matching ignores case and accents, as name lookups already do. Names the writer has already entered in that field are left out. Every existing entry is offered, reviewed or not, including another writer's proposal that an administrator has not reviewed yet, so two writers do not propose the same tag twice (decided with Ariane). An entry that is not yet reviewed is marked "new, awaiting review" in the list. This means a tag that exists only on someone else's draft can be found by typing, which is accepted: the suggestions show a name and never the story, the author or a count of stories. Characters and relationships belong to a fandom, so when the form already names a fandom, only entries of those fandoms (and entries with no fandom) are offered (decided with Ariane); when no fandom is named yet, or the named ones are new and not yet in the archive, every character or relationship is offered instead, so the box is never empty for a reason the writer cannot see. The script sends the fandom names currently in the fandom field each time it asks, so changing the fandom changes the suggestions at once.

- [x] `TaxonomyService::suggest()`: for a kind and the typed words, returns up to eight `{name, alias_of, reviewed}` matches in the order above, in a fixed number of queries, with `%` and `_` escaped and every value bound, offering every existing entry and revealing nothing but its name, whether it is reviewed, and the canonical name behind an alias (no story count, no author); characters and relationships can be limited to given fandoms
- [x] `GET /admin/taxonomy/suggest` (kind, words, optional fandom names): needs a signed-in member who holds `WriteStories`, answers `application/json` with `Cache-Control: private, no-store`, refuses a kind that writers may not propose or that the archive mode switches off, and ignores words shorter than two characters or longer than 100
- [x] A script (`term-suggest.js`) loaded on the story form only: replaces nothing until it starts, waits a moment after typing before asking, cancels an earlier request when a newer one starts, suggests for the comma-separated part being typed, and inserts the chosen name in place with the comma and space handled
- [x] Accessible by keyboard and by screen reader: the field is a combobox that owns a listbox, Up and Down move through the matches, Enter or Tab accepts the highlighted one, Escape closes the list, the count of matches is announced in a status message, and a pointer click works too
- [x] Admin CSS for the suggestion list in the same file as the rest of the admin styles, with dark mode, a focus ring that meets contrast, and a list that fits at phone width and never covers the field being typed in
- [x] The existing hint on each field is reworded to say that matching entries appear while typing, and the field's server-side behaviour (names, aliases, new proposals awaiting review, per-story limits) is unchanged and still covered by its tests
- [x] Tests: the service (prefix before contains before alias, case and accent folding, wildcard characters in the words, excluded names, the limit, an unreviewed entry offered and marked as such, nothing beyond the name, review state and alias target in the answer, fandom narrowing, including a fandom with no characters, a fandom that does not exist yet, and a character with no fandom), the endpoint (guest refused, Reader refused, a kind that is not offered refused, short and overlong words, JSON shape, no-store header, an archive-mode-disabled kind), and a static check that the script names no other site and uses no inline handler
- [x] (Done in the browser pane against a stand-in endpoint, light and dark, desktop and phone width, and by Ariane on the dev site's story form 2026-10-22. A screen reader's announcements were not tried.) Checked in a browser: typing, accepting with keyboard and pointer, the comma-separated case, a slow response arriving late, the script switched off, and a screen reader's announcements; README, CHANGELOG and `docs/DEVELOPER-APIS.md` (if a filter is added for the matches) updated

### LF-046. Sortable columns on the Users screen

**Status:** Complete — released in 1.0.0 (4 of 4). Built 2026-10-23. The shared helper is `Core/Admin/ColumnSort` (the whitelist and `ORDER BY`) with `Core/Admin/SortHeading` (the heading markup). Not yet looked at by Ariane in a browser. The sorted columns are not indexed beyond the existing ones (email is unique); sorting tens of thousands of narrow user rows is a filesort that has not been timed.

The **Users** list (`/admin/users`) is fixed to newest first. Clicking a column heading should sort by it, as on a spreadsheet.

- [x] Column headings **Name**, **Email**, **Role**, **Status** and **Joined** (and any other column worth sorting) become links that sort by that column; clicking the active one reverses the direction; the current sort is marked on the heading with `aria-sort` and a visible arrow, and works by keyboard
- [x] The sort is a whitelist in the service (column name mapped to a fixed SQL fragment, a stable tie-break on id, direction restricted to ascending and descending), never built from request text; an unknown value falls back to the default
- [x] The sort and direction are kept by the search box, the role and status filters and the paging links, and a changed sort returns to page 1; the list is still fast with tens of thousands of users (check the indexes for the sorted columns)
- [x] Tests: each column ascending and descending, an unknown or hostile sort value, the sort surviving filters and paging, the heading markup; README and CHANGELOG updated

### LF-047. Sortable columns on the Stories and Awaiting approval screens

**Status:** Complete — released in 1.0.0 (4 of 4). Built 2026-10-23 on the LF-046 helper (`StoryService::writerSort()` and `pendingSort()`; Author joins the users table, Chapters is a count subquery). Not yet looked at by Ariane in a browser.

Same idea as LF-046 for the story lists: `/admin/stories` (My stories and All stories) and `/admin/review` (Awaiting approval).

- [x] Column headings such as **Title**, **Author**, **Status**, **Words**, **Chapters**, **Updated** and **Submitted** become sort links on both screens, with the same behaviour as the Users screen (reversing, `aria-sort`, visible arrow, keyboard); share one small helper for the heading links and the sort whitelist rather than copying it, and reuse it for LF-046
- [x] Whitelisted in the service with a stable tie-break; the sort is kept by filters, search and paging; bulk actions and their selection still work on a sorted list
- [x] Today's default order stays the default on each screen
- [x] Tests: each sortable column both ways on both screens, an unknown value, an Author sees and sorts only their own stories, the sort surviving paging and filters; README and CHANGELOG updated

### LF-048. Theme management: details pop-up, preview image, upload and update by ZIP, bulk delete

**Status:** Complete — released in 1.0.0 (9 of 9). Built 2026-10-23: `Core/Theme/{ThemeArchive,ThemeInstaller,ThemePackage,ThemeInstallException,ThemeScreenshot}`, `Services/ThemeManager`, four new routes on `AppearanceController`, `theme_uploads` in `config.php`. The Details dialog was checked in the browser pane; the ZIP upload forms are covered by tests only (the pane cannot choose a file). The screenshots are schematic drawings made with GD; Ariane may replace them with real ones. No PHP scan, decided 2026-10-23. Not yet looked at by Ariane in a browser.

Bring `/admin/appearance` up to what Lumora Press and Lumora Gallery offer (look at how they do it first, and keep the behaviour and wording alike; do not add a dependency on either). Themes stay presentation only; nothing here changes the theme API.

- [x] **Details** opens a pop-up (an accessible modal dialog: focus moves in and returns, Escape and a close button dismiss it, focus is kept inside, works without a mouse) showing the theme's name, version, author, description, requirements and whether it is active, instead of the inline details; if the script does not run, the details are still reachable as a plain section or page
- [x] A preview image per theme: `screenshot.jpg` or `screenshot.webp` in the theme folder (PNG may be accepted too), shown on the theme card and in the pop-up; the file is checked for type and size, served from a fixed address and never as executable content, and a theme without one shows a quiet placeholder; both bundled themes get a screenshot
- [x] Install a theme by uploading a ZIP on the Appearance screen: the archive is checked before anything is written (size limits, entry count and unpacked-size limit against zip bombs, no absolute paths, no `..`, no symlinks, no entry that escapes the theme folder), it must hold exactly one theme folder with a readable header and the required templates, and the slug must not already exist; unpacked into a temporary folder first and moved into place only when everything passed, with a plain error otherwise and nothing left behind
- [x] Uploaded PHP is code that will run, so the screen says so and is for Administrators only (`ManageSite`), needs a CSRF token, is written to the audit log, and can be switched off by a `config.php` setting for hosts that want themes installed by hand only; decide with Ariane whether the PHP files are scanned for obviously dangerous calls (a warning, not a guarantee)
- [x] Update a theme by uploading a ZIP of the same slug: only offered when the uploaded version is newer (or after a confirmation when equal or older), replaces the files but keeps the theme's saved options and keeps it active if it was; the old folder is kept until the new one is in place so a failed update leaves the working theme as it was; the result says which version replaced which
- [x] Bulk actions with checkboxes on the theme cards: **Delete** (a confirmation page first, as other bulk deletes do); the active theme and the built-in fallback cannot be selected or deleted, a bundled theme can be deleted only with a clear warning (it comes back on the next update), and deletion removes the folder safely (no following symlinks, no path outside the themes folder) and the theme's saved options
- [x] The Appearance screen follows the admin's existing components and classes (no lookalikes), with dark mode and phone width
- [x] Tests: ZIP validation (traversal, absolute paths, symlinks, too many entries, too large when unpacked, two themes in one archive, missing header, duplicate slug, not a ZIP, truncated ZIP), install and update leave nothing behind on failure, options survive an update, the active theme survives a failed update, bulk delete refuses the active theme, a Writer or Editor is refused, the CSRF token is required, screenshots of the wrong type or size are ignored
- [x] README, CHANGELOG and `docs/THEME-DEVELOPMENT.md` (screenshot file, packaging a theme as a ZIP, what the updater keeps) updated

### LF-049. Alerts: one place for site problems and notices

**Status:** Complete — released in 1.0.0 (9 of 9). Built 2026-10-23; migration 0026 (`site_alerts`) applied to the test database only until synced and migrated on dev. Not yet looked at by Ariane in a browser. Deliberate limits: a scheduled-job alert is not built because the site has no scheduler yet (whoever builds one raises `job.failed` through `$kernel->alerts`); the email goes only for critical kept alerts, and none of the built-in kept alerts is critical, so today it serves plugins; the older dashboard notice filter is kept and its notices appear as alerts. The fresh-install schema snapshot must include 0026 at Release.

A separate place, for Administrators only, where things about the site itself are gathered: spam and security trouble, errors, plugins that failed, settings that are unsafe on a live site, and later new versions of Lumora Fiction. It is deliberately not the story notifications inbox in LF-011, which is for Editors and Authors and is about stories. The two never share a list, so a story notice can never be shown to the wrong person and a site problem is never buried under a queue of chapters.

Most of the signals already exist in separate places: the dashboard notices (debug mode on, installer folder still present), the registration pause, plugin load failures, and the **Security events** list. This ticket gathers them behind one service; it does not add new detectors, except where the list below says so.

Plan: a small `SiteAlerts` service builds the list through one filter, so core, bundled plugins and other plugins add alerts the same way and core need not know about them. An alert has a key, a severity (`critical`, `warning`, `info`), a short message, an optional detail, and a link to the screen that fixes it. Two kinds: a state that can be checked again each time (a pause is active, a plugin is failing, debug mode is on) is worked out live and clears itself when the cause goes; an event that happened (a lockout burst, a mail failure, a new version) is stored with a dismissed flag. Alerts are shown under the name **Alerts**.

- [x] `SiteAlerts` service and an `admin_alerts` filter that returns the list, with the alert shape validated so a careless plugin can drop its own alert but never break the screen; the existing dashboard notices become alerts of this kind (the `admin_dashboard_notices` filter keeps working for plugins already using it)
- [x] Live alerts for what exists today: debug mode on, installer folder still present, registration paused, a plugin that failed to load, an unwritable storage or uploads folder, and a trusted-proxy or HTTPS setting that looks wrong for the current request
- [x] Stored alerts for events: a burst of sign-in lockouts, a run of blocked registrations, outgoing mail failing, a scheduled job failing; each de-duplicated by key so a repeating problem is one alert with a count and a last-seen time, not hundreds of rows, with a retention period and a cap on rows
- [x] **Alerts** screen under Maintenance (`ManageSite` only, checked on the server): alerts grouped by severity, each with its message, how long it has been going, a link to fix it, and **Dismiss** for stored ones (CSRF-protected, written to the audit log); a dismissed alert returns if the problem comes back after a quiet period
- [x] A count badge on the admin header for Administrators only (critical and warning alerts), accessible (the count is in the text of the link, not only a colour), and the dashboard shows the few most serious alerts with a link to the full list
- [x] Optional email to the site owner for critical alerts, off by default, at most one message per alert per day, never containing anything a visitor typed
- [x] Plugin API: documented way to add a live alert and to raise and clear a stored one (a function or service method plugins can call), with the Dummy Content and future bundled plugins as examples of use; the updater (LF-010) raises "a new version is available" through it when that ticket is built
- [x] Alerts never leak: no stack traces, filesystem paths, passwords, tokens, login names or visitor text in any message or detail; the screen and badge are absent for Editors and Authors, who see nothing about site problems
- [x] Tests: each live alert appears and clears with its cause, stored alerts de-duplicate and count, dismiss and the return after a quiet period, retention and the row cap, a malformed plugin alert is dropped, an Editor and an Author get 403 on the screen and no badge, the badge counts only critical and warning, the dashboard filter still works, and no message contains a path; README (Maintenance section), CHANGELOG and `docs/DEVELOPER-APIS.md` updated

### LF-050. Show a person their own login name, and show every login name to Administrators

**Status:** Complete — released in 1.0.0 (5 of 5). Built 2026-10-23. The account page prints it as the intro sentence "Your sign-in name is …", so both bundled themes and any custom theme show it with no template change. Not yet looked at by Ariane in a browser.

Today the login name is shown nowhere (`DECISIONS.md`, "Login name is separate from display name"), so a person who forgets it, or an Administrator looking for someone, cannot see it. This ticket makes a deliberate, narrow exception: a signed-in person sees their own login name, and Administrators see everyone's. It must stay out of every public page, address, feed, email, error message and log.

- [x] **My account** page: the person's own login name shown in a read-only **Sign-in name** line (labelled as private, with a short note that it is only used to sign in), visible to every signed-in role including Readers; the page is already `private, no-store`, and nothing about it is copied into a page title, address or any field another person can see
- [x] **Users** list and the person's detail page: the login name shown to Administrators only (`ManageUsers`, checked on the server), as its own column or line; the Users search may also match it for Administrators, never for anyone else; the column is not a sort key unless Ariane wants one
- [x] Decided 2026-10-23: Administrators only; Editors do not see other people's login names. The sign-in name appears on the account page and the Users screen only, not on sign-in-adjacent pages or in emails
- [x] `DECISIONS.md` updated to record the exception and why (the login name is still never public, in URLs, feeds, markup other people can load, or error messages), and the existing tests that assert no login name ever appears on the Users screen are changed to assert it appears for Administrators and nowhere else
- [x] Tests: the account page shows only the signed-in person's own name for each role and never another person's; the Users list and detail show it to an Administrator and never to an Editor, Author or Reader; public pages, author pages, feeds, search results, the admin pages other than Users and every error page still contain no login name; the search by login name works for Administrators only; README and CHANGELOG updated

### LF-051. Settings › Security: the three settings that have no screen

**Status:** Complete — released in 1.0.0 (5 of 5). Built 2026-10-23. The session length is stored in seconds and shown in whole hours (rounded up). Not yet looked at by Ariane in a browser.

Three security settings are read by the code and can be set only by editing the settings table by hand: `security.hsts` (the HTTPS-only header, read by `Kernel::responseHeaders()` and shown on System Information), `security.password_min_length` (read by `PasswordPolicy`, floor 8, ceiling 64, default 10) and `security.session_lifetime` (seconds, read by `Kernel::boot()` for the session manager, default 86,400). Add them to the existing **Settings > Security** screen (`SecuritySettings` and `SecuritySettingsController`), under the sign-in lockout and trusted-proxy settings already there, with the same validation, audit and wording habits.

- [x] **HTTPS only (HSTS)** switch, off by default, with the trade-off said plainly: once a browser has seen the header it refuses plain HTTP for this site for a year, so it is for a site whose whole address works over HTTPS. It can only be turned on from a page that was itself reached over HTTPS (otherwise it is refused with a message), so an Administrator cannot lock themselves out by switching it on over plain HTTP; turning it off is always allowed
- [x] **Minimum password length** field from 8 to 64 (default 10), with a hint that it applies to passwords set or changed from now on and never to ones already set, and that very long passwords are limited to 72 bytes; the installer and the registration, reset and profile forms keep reading it through `PasswordPolicy`, and their hints show the current number
- [x] **Session lifetime** field in hours (for example 1 to 720; default 24), explaining that it is how long a signed-in session lasts without activity and that a shorter time signs people out sooner; a change applies to sessions as they next renew, and nobody is signed out the moment it is saved
- [x] The three are validated on the server with a message per field, shown back with what was typed, saved only when changed, written to the audit log with from and to, and limited to Administrators (`ManageSite`); the fields join the same form and the same token as the lockout settings, and the System Information rows keep working
- [x] Tests: each field's bounds and hostile values, HSTS refused over plain HTTP and allowed over HTTPS and always allowed to turn off, the new minimum enforced for a new password and not for an old one, the session lifetime reaching the session manager on the next request, Editors and Authors refused, a stale token changing nothing; README (Settings > Security), CHANGELOG and the `security.*` settings note in `SESSION.md` updated

### LF-052. Two-column home page: recent stories on the left, news on the right

**Status:** Complete (4 of 4) — released in 1.0.0. Built 2026-10-25. Two columns from 52rem in Storybook (40rem in the built-in theme), stories first in the source; `lf-home--single` when there is no news. Browser-checked on the dev site at desktop and phone width; no `custom themes/` folder exists.

The home page now stacks the news block above the story lists. Put the stories (recently published and recently updated) in a wide left column and the news in a narrower right column, as one layout in both bundled themes. Presentation only: the view data from LF-034 does not change.

- [x] Storybook and the built-in theme draw the home page as two columns on a wide screen (stories left, news right), with the news column narrower and level with the top of the stories
- [x] On a narrow screen the columns stack, with the stories first and the news after them, and nothing overflows sideways
- [x] With no news (none posted, block set to 0, or news switched off) the stories use the full width, and with no stories the news and the welcome line still show sensibly
- [x] Dark-mode and forced-colours styles, `lf-` classes with rules in each stylesheet, no inline styles; `docs/THEME-DEVELOPMENT.md`, CHANGELOG and tests updated; propagated to `custom themes/` if any exist

### LF-053. Settings > Front page: how many stories the home page lists

**Status:** Complete (4 of 4) — released in 1.0.0. Built 2026-10-25; browser-checked on the dev site. The built-in theme draws only the published list, so a 0 there leaves its page without stories.

The home page always lists eight recently published and eight recently updated stories (a constant in `ArchiveController`). Make both numbers an Administrator's choice on a new **Settings > Front page** screen. Ariane also asked for registration to be open or closed; that switch already exists as **Anyone can register** on Settings > Archive, and she decided on 2026-10-25 to leave it there.

- [x] New `FrontPageSettings` service and **Settings > Front page** screen (`ManageSite`): number of recently published stories and of recently updated stories, each 0 to 30 (default 8; 0 leaves that list off the page), validated on the server with a message per field, audited, a stale token changing nothing
- [x] The home page uses the two numbers; a list set to 0 is not queried or drawn, the welcome line shows only when both lists are empty, and a damaged stored value falls back to 8
- [x] Menu entry; README, CHANGELOG and `docs/DEVELOPER-APIS.md` (the `home.*` settings) updated
- [x] Tests: bounds and hostile values, Editors and Authors refused, a stale token changing nothing, and the counts reaching the home page in both bundled themes
