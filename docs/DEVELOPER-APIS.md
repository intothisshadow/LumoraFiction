# Developer APIs

Reference for plugin authors. It describes only what exists today and is updated whenever a hook, filter, or service changes.

## Plugins

A plugin is a folder `content/plugins/{slug}/` holding a main file named `{slug}.php`. The slug is lower-case letters, digits and hyphens, starts with a letter and is at most 50 characters. Nothing else in the folder is ever included by the loader; the plugin includes its own files with paths built from `__DIR__`.

The main file starts with a comment that carries the header. It is read as text, never run, within the first 8 KB:

```php
<?php

/**
 * Plugin Name: Reading Lists
 * Version: 1.0.0
 * Author: Someone
 * Description: One short sentence shown on the Plugins screen.
 * Requires PHP: 8.3
 * Requires at least: 0.1.0
 */

declare(strict_types=1);

add_action('routes_register', static function (\LumoraFiction\Core\Http\Router $router): void {
    // ...
});
```

`Plugin Name` and `Version` are required; a plugin without both, or with a version or requirement that is not a plain number such as `1.2.3`, is not listed. `Author`, `Description`, `Requires PHP` and `Requires at least` (the Lumora Fiction version) are optional. The header can sit in its own comment block after the usual file docblock.

- **Lifecycle.** An administrator turns a plugin on under Plugins. The file is run once in that request, and the plugin is refused, with the reason shown, if it throws, has a syntax error, or needs a newer PHP or Lumora Fiction than the site has. Once on, the file runs on every request while the application starts, after the core services and the core routes are ready and before `routes_register` fires, in the order the plugins were turned on. Turning a plugin off stops it running from the next request. The slug is kept in the `plugins.active` setting.
- **What the file sees.** `$kernel` is in scope, so a plugin can read `$kernel->config`, `$kernel->database`, `$kernel->auth`, `$kernel->hooks` and the other services, among them the shared `$kernel->stories`, `$kernel->chapters`, `$kernel->taxonomy` and `$kernel->notifications` (use these rather than building your own, so cover files, links and hooks are handled as they are everywhere else). `$kernel->root` is the site's folder on disk: keep a plugin's own files under `storage/` below it (the folder is closed to the web), and never work the site's folder out from the plugin file's own location. Do the work in hook callbacks rather than directly in the file where you can. Register settings defaults for your own keys with `$kernel->config->registerDefaults([...])`, and read and write them with `setting()` and `setSetting()`; prefix every key with your slug.
- **Registering things.** Routes go through `routes_register`, admin screens through the `admin_menu` filter (see "Admin screens"), site alerts through `admin_alerts` (see "Site alerts"), and public output through the theme hooks. The file is run once per request, so it must not declare a function or class that another plugin might declare too; put classes behind a namespace named for your plugin.
- **When a plugin fails.** Anything the file throws, including a warning, is caught: the plugin is skipped for that request and the site continues. The reason is written to the error log and the audit log (`plugin.load_failed`) once, then kept in the `plugins.failures` setting so the dashboard can flag it; it is cleared when the plugin loads cleanly or is turned off. Hooks the plugin added before it threw stay registered for that request, so register hooks at the end of the file, or inside a function the file calls last. A fatal error PHP cannot catch, such as running out of memory, cannot be skipped; remove the plugin's folder.
- **Development-only plugins.** `config/config.php` holds an `environment` value that the admin cannot change: the installer writes `production`, and a missing value means `production` too. A plugin that must never run on a live site reads `$kernel->config->get('environment', 'production')` and throws from its main file unless it is `development` or `testing`; the loader then refuses it and shows the reason.
- **Own tables.** A plugin that needs a table creates it with `CREATE TABLE IF NOT EXISTS` through `$kernel->database->run()`, using the `{prefix}` placeholder, and checks it before use. Core's migrations never touch plugin tables.
- **Versions.** Change the `Version` in the header whenever the plugin changes; it is what the Plugins screen shows.
- **Security.** A plugin runs with the full power of the site. The PHP files in `content/plugins/` are refused by the web server if someone requests them directly; serve a plugin's own stylesheet or script from a route, or place static files next to the code (images, CSS and JS are served directly). Check capabilities in every handler.

## Hooks

Plugins and themes extend Lumora Fiction with actions (run code at a point in the request) and filters (change a value). The procedural functions are available everywhere once the application has started.

| Function | Purpose |
| -------- | ------- |
| `add_action(string $hook, callable $callback, int $priority = 10)` | Run `$callback` when the action fires. |
| `do_action(string $hook, mixed ...$args)` | Fire an action, passing `$args` to every callback. |
| `remove_action(string $hook, callable $callback, int $priority = 10): bool` | Remove a callback. The priority must match the one it was added with. |
| `add_filter(string $hook, callable $callback, int $priority = 10)` | Register a callback that receives a value and returns a (possibly changed) value. |
| `apply_filters(string $hook, mixed $value, mixed ...$args): mixed` | Pass `$value` through every callback in turn and return the result. |
| `remove_filter(string $hook, callable $callback, int $priority = 10): bool` | Remove a filter callback. |

Lower priorities run first, and callbacks with the same priority run in the order they were added. A callback added while a hook is running takes effect from the next time the hook fires.

## Actions

| Action | Arguments | Fires |
| ------ | --------- | ----- |
| `plugins_loaded` | `Kernel $kernel` | Once every active plugin's file has run, before `routes_register`. Use it for work that needs another plugin to have registered its hooks first. |
| `plugin_activated` | `string $slug` | After an administrator has turned a plugin on. The plugin's file has just run in that request, so the plugin's own listener hears it; test `$slug` to react only to your own plugin, for example to create a table. |
| `plugin_deactivated` | `string $slug` | After an administrator has turned a plugin off. The plugin's listener hears it too, because the plugin is still loaded for that request. Use it to tidy up. |
| `routes_register` | `Router $router` | While the application starts, before any request is matched. Register routes here. |
| `kernel_booted` | `Kernel $kernel` | When the core services are ready, after plugins have loaded. |
| `story_trashed` | `int $storyId` | After a story has been moved to the trash and saved. It is already unpublished by then. |
| `story_deleted` | `int $storyId` | After a story and everything of it has been deleted for good, by a person or by the retention period. Use it to remove what a plugin keeps about the story. |
| `story_added_to_series` | `int $storyId`, `int $seriesId` | After a story has been put in a series, or moved into another one. |
| `story_removed_from_series` | `int $storyId`, `int $seriesId` | After a story has left a series: taken out by its author, moved to another series, or left behind by a deleted series. Deleting the story itself fires `story_deleted` only. |
| `story_added_to_collection` | `int $storyId`, `int $collectionId` | After a story is really in a collection: put there by its author where no approval is needed, or approved by the curator. A story still waiting in the queue does not fire it. |
| `story_removed_from_collection` | `int $storyId`, `int $collectionId` | After an approved story leaves a collection (the curator took it out, its author withdrew it, or the collection was deleted). A submission turned down while it was still waiting does not fire it. |
| `efiction_story_imported` | `int $storyId`, `int $ownerId`, `string $sourceId` | After the bundled eFiction Importer plugin has saved one story with all its chapters, classification and dates. `$sourceId` is the story's number in the eFiction database (`sid`). It does not fire for a story an earlier run already brought across. |
| `story_imported` | `int $storyId`, `int $userId`, `string $sourceId` | After a story has been brought across from another archive by the import screens and everything of it (chapters, classification, dates, series and collections) has been saved. `$sourceId` is the address-based identifier read from the file, such as `archive.example.org/works/123`, or an empty string when the file named none. |
| `news_published` | `NewsPost $post`, `User $actor` | After a news post has been published for the first time or published again, and saved. It does not fire for a draft that is saved, or for a post that is already published. A callback that fails cannot undo the change. |
| `news_deleted` | `int $postId` | After a news post has been deleted. Use it to remove what a plugin keeps about the post. |
| `page_deleted` | `int $pageId` | After a static page has been deleted. Use it to remove what a plugin keeps about the page. |
| `account_deleted` | `int $userId` | After an account's personal data has been removed (see "Privacy and account deletion"). The row stays, anonymised, so the number still points at something; remove whatever a plugin keeps about the person. |
| `story_viewed` | `Story $story`, `?Chapter $chapter`, `?User $viewer`, `Request $request` | When a public story page (`$chapter` is null) or chapter page is about to be shown. It fires only for a story a reader may see, so a draft, a pending story and a private preview never reach it. `$viewer` is the signed-in person, or null when nobody is signed in. Keep the callback quick, and never store the address or browser text from `$request`. A cached page that skips the application skips this action too. |
| `comment_created` | `Comment $comment`, `User $author` | After a reader's comment is stored, whatever its status (check `$comment->status`). Nothing is sent for a held, hidden or spam comment by core. A reply is a comment too: `$comment->parentId` holds the id of the top-level comment it answers (null for any other comment), and `$comment->isReply()` says so. |
| `comment_status_changed` | `Comment $comment`, `CommentStatus $from`, `?User $actor` | After a moderator, the story's writer or the report threshold changes a comment's status. `$actor` is null for the automatic hide. |
| `story_state_changed` | `Story $story`, `PublicationState $from`, `User $actor`, `string $reason` | After a story's publication state has changed and been saved: submitted, published, approved, sent back, withdrawn or unpublished. `$story` is the story as it is now and `$from` its earlier state. `$reason` is the reviewer's note when a story is sent back or taken down in bulk, and an empty string otherwise. Use it to notify people; a callback that fails cannot undo the change. |
| `user_role_changed` | `User $user`, `UserRole $from`, `User $actor`, `string $reason` | After an administrator has changed an account's role and it has been saved. `$user` is the account as it is now, and `$reason` is empty when none was given. |
| `user_status_changed` | `User $user`, `UserStatus $from`, `User $actor`, `string $reason` | After an administrator has suspended or reinstated an account. `$from` is the status the account had before; read the new one from `$user`. |
| `user_publishing_override_changed` | `User $user`, `?PublishingOverride $from`, `User $actor` | After an administrator has changed how an author's stories are published. `$from` is null when the author followed the site setting. |
| `lf_form_fields` | `string $formId` | Inside a public form, fired by the theme's form part. Core prints the hidden trap and timing fields here for `account_register`, `account_forgot` and `account_resend`. |
| `lf_head` | `string $view` | While a public page prints its `<head>`, after the stylesheets. Print markup the page needs there. |
| `lf_body_open` | `string $view` | Right after the opening `<body>` of a public page. |
| `lf_footer` | `string $view` | Just before the closing `</body>` of a public page, after queued scripts. |
| `lf_footer_area` | `string $view` | Inside the footer element of a public page, after the footer links and footer text. Print markup that belongs in the footer, such as a webring's navigation; `lf_footer` fires after the footer element has closed. |

## Filters

| Filter | Value | Purpose |
| ------ | ----- | ------- |
| `lf_styles` | `array<string, string>` handle => path | Add stylesheets to every public page. Paths must start with a single `/`; anything else is dropped. |
| `lf_scripts` | `array<string, string>` handle => path | Add scripts to every public page, loaded with `defer`. Same rule for paths. |
| `lf_body_class` | `list<string>`, plus the view | Add classes to the body of a public page. Names that are not plain class names are dropped. |
| `lf_document_title` | `string`, plus the view | Change the whole `<title>` of a public page. The result is escaped. |
| `lf_navigation_items` | list of `{label, url, current?, area?, children?, section?}` | Add items to the public main menu. An entry whose address is not a path on this site is dropped. An optional `children` list of `{label, url, current?}` makes the item a drop-down: each child follows the same address rule, a child's own `children` are ignored (one level only), and `section: true` marks the parent of the current page. A theme that ignores `children` still shows the parent as a link and the children inline; the footer filter takes no children. Core uses it for pages that have a parent. An `area` of `account` marks a sign-in or account link, which a theme may draw apart from the menu with `lf_navigation($label, 'account')`; any other value is ignored. Core starts the list with Home, Browse and Search (and News while news is on), adds Sign in and Register for visitors, Account for signed-in members, and Admin for staff. |
| `lf_footer_items` | list of `{label, url, current?}` | Add links to the footer list that `lf_footer_links()` prints. Same address rule as the main menu. Core adds the static pages placed in the footer. |
| `lf_privacy_policy` | `{label, url}` or null | The privacy policy link themes print with `lf_privacy_link()`. Core answers with the page named under Settings > Privacy when it is published. |
| `lf_page_feed` | `{label, url}` or null | The feed of the page being shown, which themes print with `lf_feed_link()`. Core answers for an author, fandom, character, relationship, genre or tag page, a story or chapter and the news, while that kind of feed is offered; a plugin may answer for its own page (the address must be a path on this site). |
| `lf_noindex` | `bool`, plus the view | Whether the page asks search engines not to index it, as a `noindex, nofollow` meta tag. Core answers true for every page while **Settings > Search engines** discourages them; return true to add the tag to a page of your own. |
| `theme_option_fields` | list of field definitions | Declare the options of the active theme, including `image` fields and a select's `pictures` setting. See `THEME-DEVELOPMENT.md`. |
| `csp_directives` | `array<string, string>` directive name => value | Widen the default same-origin Content-Security-Policy, for example to allow images from one extra host. A value that is not an array is ignored and the default stays. |
| `admin_menu` | list of menu entries | Add screens to the admin sidebar. See "Admin screens" below. |
| `content_html` | `string` HTML, plus the `ContentFormat` | Adjust the final HTML of a chapter just before it is shown. The result is sanitised again, so a plugin cannot add scripts, images or other elements the allowlist refuses. |
| `lf_registration_spam_reasons` | `list<string>` (empty), plus `array{display_name, email, ip}` | Return reasons to refuse a sign-up. Any non-empty text is enough: the sign-up is dropped without telling the sender, the visitor sees the usual thanks, and the reason is written to the Security events list as `plugin: {reason}`. It runs after the built-in checks and before Akismet, and never sees the password. |
| `admin_dashboard_cards` | `list<array{title: string, html: string}>`, plus the `User` | Add a card to the dashboard, after the core ones. `title` is escaped; `html` is printed as it is, so escape everything in it. Decide from the `User` whether to add a card at all, because the dashboard is open to every signed-in staff member and writer. An entry without a string `title` and `html` is dropped. |
| `admin_story_panels` | `list<array{title: string, html: string}>`, plus the `Story` and the `User` | Add a panel to a story's edit screen, below the chapters. It is asked only for a story the person may already manage, but a plugin that shows private numbers should still check the person's relationship to the story. `title` is escaped; `html` is printed as it is. |
| `admin_alerts` | `list<array{key, severity, message, detail?, path?}>`, plus the `Request` | Add an alert to the Administrators' **Alerts** screen, the dashboard and the header count. See "Site alerts". |
| `admin_dashboard_notices` | `list<array{variant: string, message: string}>`, plus the `Request` | The older way to add a notice to the administrator's dashboard; it keeps working, and each notice also shows on the Alerts screen (`error` as critical, `warning`, `info` and `success` as a plain notice). `variant` is `error`, `warning`, `info` or `success`; the message is plain text and is escaped. New code should use `admin_alerts`. |

```php
add_filter('csp_directives', function (array $directives): array {
    $directives['img-src'] = "'self' https://images.example";

    return $directives;
});
```

## Accounts and permissions

Every signed-in visitor is a `User` with one role: Administrator, Editor, Author, or Reader. A visitor who is not signed in is a Guest. Check what a role may do with capabilities, never by comparing role names:

```php
$kernel->auth->can(\LumoraFiction\Models\Capability::WriteStories);
```

| Capability | Administrator | Editor | Author | Reader |
| ---------- | :-----------: | :----: | :----: | :----: |
| `AccessAdmin` | yes | yes | yes | |
| `ManageSite`, `ManageUsers` | yes | | | |
| `ModerateStories`, `EditAnyStory`, `PublishWithoutReview` | yes | yes | | |
| `WriteStories` | yes | yes | yes | |
| `Bookmark` | yes | yes | yes | yes |
| `PublishNews` | yes | yes | | |

- The site-wide **Who can publish stories** setting (`publishing.who`: `anyone`, `approved` or `staff`) is applied inside `User::can()` through `Core\Security\PublishingPolicy`, so every check above already follows it. In `staff` mode an Author-role account has a Reader's capabilities (`AccessAdmin` and `WriteStories` included) while its stored role stays `author`. A plugin that needs to know whether a role may write under the current rule calls `PublishingPolicy::allowsWriting(UserRole $role)` (or `PublishingPolicy::effectiveRole()`); `PublishingPolicy::mode()` is the mode in force. Never compare roles to decide whether someone may write.
- A capability says what a role may do in general. Whether someone may change one particular story (ownership) is checked separately by the code that loads the story.
- A suspended or unconfirmed account holds no capabilities.
- Changes an administrator makes to other people's accounts go through `UserAdminService` (`changeRole()`, `suspend()`, `reinstate()`, `setPublishingRights()`, and `list()` and `count()` for the Users screen), never straight through `UserService::setRole()` or `setStatus()`, which do no checking. The service refuses anyone who may not manage users, any change to one's own role or status, and any change that would leave the site without an active administrator, writes the audit log, and fires the three actions above. It throws `UserAdminException` with a message that is safe to show. Suspending can take the account's stories out of view first; reinstating returns an account whose address was never confirmed to pending when the site requires confirmation.
- `User::publicProfile()` returns the only fields that may appear in public output: the display name and the slug. The login name and email address are private; never print them on a page, in a feed, or in an error message.

### Settings read by the account code

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `registration.enabled` | `true` | Whether visitors may register. Anything other than `true` closes registration. |
| `registration.default_role` | `author` | Role given on registration: `author` or `reader`. Staff roles are never given this way. |
| `registration.email_verification` | `true` | Whether a new account must confirm its email address before signing in. |
| `security.password_min_length` | `10` | Minimum password length, between 8 and 64. |
| `security.session_lifetime` | `86400` | Seconds an idle session is kept. |
| `home.published_count`, `home.updated_count` | `8` | How many recently published and recently updated stories the home page lists, from 0 to 30; 0 leaves that list off and a value outside the range counts as 8. Read them with `FrontPageSettings::publishedCount()` and `updatedCount()`. |
| `archive.mode` | `both` | What the site publishes: `both`, `fanfiction`, or `original`. |
| `publishing.hold_first_story` | `true` | Whether a new Author's first story waits for approval. |
| `editor.default_mode` | `visual` | The editing mode writers start with: `visual`, `markdown`, `html` or `plain`. |
| `editor.lock_to_default` | `false` | When `true`, everyone uses the default mode and per-user choices are ignored. |
| `stories.allow_abandoned` | `false` | Whether a story may be marked abandoned. A story already marked abandoned can still be edited when this is off. |
| `security.hsts` | `false` | Send `Strict-Transport-Security` over HTTPS. Once a browser has seen it, it refuses plain HTTP for the site for a year. |

Emailed links are built from the `site_url` value in `config/config.php`, never from the request, so it must be set to the site's full public address.

## Sign-up and form protection

`RegistrationGuard::check($request, $displayName, $email, $answer)` runs in front of every registration and returns a `GuardDecision` whose `outcome` (`GuardOutcome`) is `Allow`, `Reject` (a mistake the visitor can fix; `errors` holds a message per field), `Silent` (looks automated: answer as if it worked and do nothing), `Limited` or `Paused` (`retryAfter` seconds). `FormGuard::inspect($request, $formId)` is the hidden-field and timing check alone, for any other public form that sends mail or creates something. The order of the checks and their reasons are in the code; the rules a plugin or a theme must know are these:

- A public form that should be protected prints its fields with `do_action('lf_form_fields', $formId)`, and the id must be listed in `FormProtectionFields::PROTECTED_FORMS`. The trap field names and the signed timing stamp are made from the site's `secret_key` by `FormShield`; a stamp is tied to one form and cannot be used on another.
- A caught bot gets the same page as a person who succeeded, and a request that sent no timing fields is treated as too fast or tampered with rather than let through. Never add a code path that lets a request with the fields missing succeed.
- The address used for the rate limits is `Request::ip()`. Behind a reverse proxy it is the visitor's address only when the proxy is listed under Settings > Security; see "Sign-in lockout and reverse proxies" below.
- `SecurityEventLog::record($event, $ip, $detail)` writes to the Logs list. It never throws, keeps only the event name, the address and a short note, and trims by age (`security.log_retention_days`). `recordOnce()` skips a repeat from the same address inside ten minutes, which suits events one client can cause thousands of times. Never pass anything a visitor typed as the detail.

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `spam.min_seconds` | `3` | Fastest a form may be sent after it was shown. `0` turns the timing check off. |
| `spam.registration_gap_seconds` | `5` | Shortest wait between two registration submissions from one address. `0` turns it off. |
| `spam.registration_ip_limit` | `10` | Registration submissions from one address in an hour. `0` turns it off. |
| `spam.registration_flood_limit` | `60` | Submissions from every address in an hour that pause registration. `0` turns it off. |
| `spam.registration_pause_minutes` | `60` | How long a flood pause lasts. |
| `spam.block_disposable` | `true` | Refuse addresses at throw-away email services. |
| `spam.domain_blocklist` | empty | Domains whose addresses cannot register, one per line; subdomains count. |
| `spam.question`, `spam.answer` | empty | An optional question on the registration form. Both must be set. |
| `spam.akismet_enabled`, `spam.akismet_key` | off, empty | Optional Akismet check of new sign-ups. Fails open, and sends nothing while off. |
| `security.log_retention_days` | `30` | How long the Logs entries are kept, from 1 to 365. |

Changes to these are written to the audit log as `settings.spam.update`, with only that an answer or key changed and never its value, and a flood pause and its ending as `security.registration_paused` and `security.registration_resumed`. The events themselves are in the `security_events` table, listed under Maintenance > Security events, and are one of `login_lockout`, `registration_rate_limited`, `registration_paused`, `registration_blocked_email`, `registration_wrong_answer`, `registration_spam`, `trap_triggered`, `form_too_fast`, `form_expired` and `form_invalid`.

## Sign-in lockout and reverse proxies

| Setting | Default | Meaning |
| ------- | ------- | ------- |
| `security.lockout_attempts` | `5` | Wrong passwords from one address that start a lockout, from 3 to 20. |
| `security.lockout_minutes` | `15` | How long the lockout lasts and how far back wrong passwords are counted, from 1 to 1440. |
| `security.lockout_progressive` | `true` | Double the lockout for each further batch of failures from the same address within a day, up to sixteen times. |
| `security.notify_owner` | `true` | Email the owner of the account the lockout-starting sign-in named. |
| `security.trusted_proxies` | empty | Addresses or ranges of reverse proxies, one per line. |

- The lockout counts wrong passwords per address, never per account name, so an attacker cannot lock a real person out by guessing at their name. `LoginThrottle` takes the numbers from these settings when the kernel starts.
- When the failure that starts a lockout names an active account (by login name or email address), `AuthService` writes to its owner once an hour at most (`users.lockout_notified_at`, claimed in one statement by `UserService::claimLockoutNotice()`). The sign-in page and its status code are the same whether or not an account was named. The message carries the address and the length of the lockout and a link to the password-reset page, and never what was typed. The lockout is also written to the Security events list as `login_lockout`.
- `TrustedProxies::resolve($server)` runs when the kernel starts and again for the request. When `REMOTE_ADDR` matches a listed proxy, `REMOTE_ADDR` becomes the first address, reading `X-Forwarded-For` from the right, that is not itself a listed proxy; a header that has anything other than an address in the part it reads is ignored. `X-Forwarded-Proto: https` from a listed proxy turns on `HTTPS`. The connection's own address is kept as `LF_PEER_ADDR`. Ranges wider than a /8 (IPv4) or /32 (IPv6) are refused when saved, because trusting nearly everyone lets a visitor choose the address that is recorded.
- Changes are written to the audit log as `settings.security.update`.

## Stories and chapters

`StoryService` and `ChapterService` are the only code that creates, changes, publishes or deletes stories and chapters, and they decide who may. Pass them the signed-in `User`; they throw `StoryPermissionException` when the story is missing or the user may not change it (the two look the same on purpose) and `StoryValidationException`, which carries a message per field, when the input is not acceptable.

- An Author changes only their own stories. A user with `EditAnyStory` may change any. A suspended account changes nothing.
- A story has a work status (`WorkStatus`: ongoing, completed, on hiatus, abandoned) and, separately, a publication state (`PublicationState`: draft, pending, published, unpublished). `PublicationRules` is the only place that lists which state may follow which; nothing else moves a story between states.
- `StoryService::publish()` publishes at once when `publishesDirectly()` says so and otherwise puts the story in the approval queue as pending. Staff (`ModerateStories`) always publish directly. For an Author the answer comes from, in order: an administrator's per-writer decision (`PublishingOverride::Review` holds every story, `Direct` holds none; set with `setPublishingOverride()`, which needs `ManageUsers`), the `PublishWithoutReview` capability, the `publishing.hold_first_story` setting (when it is off nothing is held), and finally whether any other story of theirs has ever been published (a first story is held, later ones are not).
- The approval queue is `listPending()` and `countPending()` (oldest first, empty for anyone without `ModerateStories`), with `approve()` and `reject($actor, $id, $reason)`. Both need `ModerateStories` and throw `StoryValidationException` with a `state` error when the story is no longer waiting, so two reviewers cannot decide the same story twice. A rejected story returns to draft and keeps the reason (`Story::$rejectionReason`, `wasRejected()`) until it is submitted or published again. `unpublishAllByOwner()` takes every published or waiting story of one writer out of view in one call, keeping the stories, chapters and classification links; each story is its own transaction, so running it again finishes an interrupted run.
- `story_state_changed` fires after each of these changes (see Actions).
- The trash: `StoryService::trash()` marks a story with `trashed_at`, unpublishes it (a pending one goes back to a draft) and fires `story_trashed`; `restore()` clears the mark and leaves it a draft or unpublished; `purge()` deletes a story that is in the trash and refuses one that is not; `delete()` deletes either at once; `listTrashed()` and `purgeExpiredTrash()` serve the Trash screen. A trashed story is refused by `manageable()` like a missing one, so every editing path ignores it; use `manageableIncludingTrashed()` only to restore or delete. The writer lists leave trashed stories out, and public queries never return them because they are unpublished. `ChapterService` has the same `trash()`, `restore()`, `purge()` and `listTrashed()`: a trashed chapter is parked at a number outside the story's own 1 to n and the chapters after it move up, so numbering stays gap-free, and `restore()` puts it back last as a draft.
- `StoryService::duplicate()` copies a story the actor may change into a new draft owned by the actor, with its classification and its live chapters as drafts, and without a cover.
- Earlier chapter versions: `ChapterService::update()` and `restoreRevision()` first save what they replace in `chapter_revisions`, and keep the newest `retention.revisions` (20 by default) per chapter. `revisions()`, `revision()` and `countRevisions()` read them for someone who may change the chapter.
- Notifications: `NotificationService::notify($userId, $kind, $storyId, $message, $path)` adds a message to one person's inbox (never throws; `$path` is an admin address below `/admin/`, `$kind` lower-case letters, digits and `_`), and `forUser()`, `countUnread()`, `markRead()` and `markAllRead()` read and change only the given person's own rows. A person has an inbox if they hold `WriteStories`. `StoryNotifier` turns `story_state_changed` into the built-in messages; `resolveStory($kind, $storyId)` marks everyone's messages of a kind about a story as read once the request they carry is answered. Site problems for Administrators are a different list: see "Site alerts".
- Chapters are numbered 1 to n with no gaps. Reordering and deleting renumber them. A chapter stores its text as written plus a `ContentFormat`; sanitising happens when the text is shown, never by trusting what was stored.
- A story is visible to visitors only when it is published and has at least one published chapter. Build every public query from `StoryVisibility::publicCondition()` (or `StoryService::findPublicBySlug()` and `listPublic()`) so no screen applies a looser rule. A draft must never be returned by a public address.
- Changes are written to the audit log as `story.create`, `story.update`, `story.work_status`, `story.submit`, `story.publish`, `story.approve`, `story.reject`, `story.withdraw`, `story.unpublish`, `user.publishing_override`, `story.delete`, `chapter.create`, `chapter.update`, `chapter.reorder`, `chapter.publish`, `chapter.unpublish` and `chapter.delete`.
- Bulk actions apply one item at a time through the service that owns it and return a `Core\Admin\BulkResult` (done and skipped counts, each skip reason once, `summary()` for the flash sentence; `BulkResult::LIMIT` is 100, `ids()` and `tooMany()` read the posted ids). The services are `ChapterService::bulk($actor, $storyId, $action, $ids)` (`publish`, `unpublish`, `trash`, `delete`; word counts and term counts are refreshed once per story at the end of the batch), `StoryService::bulkReview()` (`approve`, `reject` with one shared reason), `StoryBulkService::apply()` (rating, work status, series, collection, downloads, and tags, fandoms and characters added or removed by name, through `classify()` so the proposal rules of the story form hold), `NewsService::bulk()`, `PageService::bulk()`, `UserAdminService::bulk()` (never the actor, a deleted account or the last active administrator), `RedirectService::bulk()` (and `changeStatus()`), `CollectionService::bulk()`, `SeriesService::removeStories()` and `NotificationService::bulk()`. Each `bulk()` throws before touching anything when the action is unknown or the actor lacks the capability, and writes one `…bulk` audit entry (`chapter.bulk`, `news.bulk`, `page.bulk`, `user.bulk`, `redirect.bulk`, `collection.bulk`, `series.bulk`, `story.bulk`, `story.review_bulk`) with the action and counts besides the single action's own entries. In an admin controller, `AdminController::bulkInput()` checks the token, the action, the 100-item limit and an empty selection the same way everywhere, `askBeforeBulk()` renders the shared confirmation page, and the views build their forms with `Core\Admin\BulkBar` (an empty form beside the table, with tick boxes and buttons joined to it by the `form` attribute) and `admin/assets/js/bulk-select.js`.
- Story and chapter text is stored raw. Print it with `Html::e()`.
- `StoryService::listForWriter()`, `countForWriter()` and `countsByState()` feed the writer screens. The scope `all` is honoured only for users with `EditAnyStory`; for anyone else the list is always limited to their own stories, whatever the request asks. `bulk($actor, 'publish'|'unpublish'|'delete', $ids)` applies one action to up to 100 stories, checking each on its own, and returns `done` and `skipped` counts.
- `ChapterService::move($actor, $chapterId, 'up'|'down')` swaps a chapter with its neighbour through `reorder()`, and `countsForStories()` gives chapter totals for a page of stories in one query. `UserService::displayNames()` returns public names only, in one query.
- The writer screens are `StoryController` and `ChapterController`, which extend `WriterController`. Its `writer()` method is the permission check for a screen that has a form: a guest who posts one of the listed fields gets the 401 `session-ended` page that returns the text and sends no token, instead of a redirect that would discard it. Name the fields to keep and the screen to return to when you build another form that holds long text. `Request::inputList()` reads a posted `name[]` list as text values only.

## News

A news post is a staff announcement with its own table (`news_posts`), so no story query, count or search sees it. `NewsService` is the only code that reads or changes posts.

- Every change needs `Capability::PublishNews` (Editors and Administrators) and throws `NewsPermissionException` otherwise, with the same message for a post that does not exist. News is site-wide, so any holder of the capability may change any post; ownership is not checked. Invalid details throw `NewsValidationException` with a message per field (`title`, `content`, `format`).
- A post is `draft` or `published`. `unpublish()` returns it to a draft and keeps its address and first publication date. The address (`slug`) starts with the post's number, follows the title until the first publication, and never changes after.
- Public code reads only through `findPublicBySlug()`, `listPublic()` and `countPublic()`, which return published posts only, pinned first and then newest. A draft must never be returned by a public address. `NewsSettings::enabled()` and `NewsSettings::homeCount()` read the two settings (`news.enabled`, `news.home_count`); check `enabled()` before drawing any public news page.
- Changes are written to the audit log as `news.create`, `news.update`, `news.publish`, `news.unpublish` and `news.delete`. The actions `news_published` and `news_deleted` tell plugins (see Actions).
- Post text is stored raw and rendered by `ContentRenderer`, like a chapter. `NewsPresenter` builds the data a theme receives and is the one place that decides which fields it may see: the author is the display name only.
- A plugin that wants a news feed or sitemap entries can use `NewsService::listPublic()`; core does not build either yet.
- A post can have one featured picture. Pictures are rows of `news_images` (kept by `NewsImageService`, the files by `NewsImageStore` under `content/uploads/news/`: a full-size file and a `-thumb` file under a random name, built on the same checks as a story cover), and a post holds only the picture's number plus its own `image_alt` and `image_decorative`. `NewsService::applyImage($actor, $postId, $upload, $chosenId, $alt, $decorative, $remove)` sets, replaces, describes or removes it (upload, an earlier upload by number, or the one already there); it needs `PublishNews`, requires a description unless decorative, only lets a person choose their own upload (an administrator any), and deletes a file only when no post uses it any more (`NewsImageService::releaseIfUnused()`). `NewsService::postsUsingImage()` lists the posts that show a picture. Audit actions: `news.image` (with `action`: `set`, `replace`, `describe`, `remove`), `news.image.add` and `news.image.delete`. The service is `$kernel->newsImages`. `NewsImageService::deleteMany($administrator, $ids, $removeFromPosts)` (used by the Media screen, administrators only) deletes pictures, refusing one a post still shows unless `$removeFromPosts` is true, and fires the `news_image_deleted` action (`int $imageId`, `list<int> $postIds` that lost it) after each deletion; `listAll()`, `countAll()`, `totals()` and `uploaders()` feed the screen. `UploadedFile` now also carries the cleaned original `name`.

## Static pages

`PageService` is the only code that reads or changes static pages (About, FAQ, the privacy policy). A `Page` has a title, a slug, text and its format (the same four editing modes and the same sanitiser as a chapter), a state (draft or published), `inMenu` and `inFooter` flags, a sort order, a meta description and a `noindex` flag.

- Changing a page needs `Capability::ManageSite`; a person without it, and a page that does not exist, both give `PagePermissionException`, and invalid input gives `PageValidationException` with a message per field. A public lookup uses `findPublicBySlug()`, which never returns a draft; `find()` is for the admin screens only.
- The address is `/{slug}`, one path segment, served by the router's route of last resort (`Router::fallbackGet()`), which is consulted only when no registered route matches the path for any method. It therefore cannot shadow a core or plugin route, whenever that route was registered, and a POST to an unknown address stays a 404. A slug must be lowercase letters, digits and single hyphens, is refused when it is on the reserved list or when the router already serves it (checked at save time, so a plugin route counts), and is unique among pages.
- `PageService` fires `page_slug_changed` (`string $oldSlug`, `string $newSlug`) after a page's address changes; core answers it with an automatic permanent redirect from the old address.
- `FeedLinks` (in `Core/Theme`) is told by the controller which feed the page about to be drawn belongs to (`showing($kind, $label, $segments)`), and writes the autodiscovery links in `<head>`, answers `lf_page_feed` and adds the site's and the news feed to the footer through `lf_footer_items`. `SearchHead` prints the canonical address a controller names (`canonical($path)`) and answers `lf_noindex`. A page that is missing, private or a preview never calls either, so nothing is advertised for it.
- Redirects: `RedirectService` (`find()`, `add()`, `delete()`, `remember($oldPath, $newPath)`) keeps the list; paths are on this site without the install folder. `Kernel::redirectFor()` is asked only when no route owns an address or the page that does answers 404, so a redirect never hides a page that is there. `remember()` repoints redirects that already ended at the old address, so visitors never go through a chain, and drops one that started at the new address, so it cannot loop.
- `SitemapService` builds `/sitemap.xml` (an index) and the parts it lists (`site-1`, `authors-N`, `terms-N`, `stories-N`, at most 10,000 addresses each) from the public-story condition every listing uses. `SearchSettings` holds `seo.discourage`; `SearchSettings::sitemapOffered()` is false while sign-in is required or crawlers are discouraged.
- `SettingsTransfer` is the one place that knows which settings are portable. Each group wraps the settings service of its screen (`current()`, `validate()`, `save()`), so an import goes through the same checks and writes the same audit entries as the screen. See [SETTINGS-FILE.md](SETTINGS-FILE.md).
- `PageService::menuTree()` is the menu as a tree (top-level pages, each with the published, menu-flagged pages under it; a page whose parent is a draft or not in the menu is promoted to the top level), `parentChoices()`, `childCount()` and `parentsOf()` serve the admin, and a page's parent is `Page::$parentId` (`parent_id`; one level, enforced by the service).
- `PagePresentation` adds the published pages flagged for the menu, with their children, through the `lf_navigation_items` filter and those flagged for the footer through `lf_footer_items`, and prints the description and canonical address into `<head>` on the `page` view. The pages are read once per request.
- If the pages table does not exist yet (files uploaded, the administrator has not opened the admin), the public site works as if there were no pages.
- Changes are written to the audit log as `page.create`, `page.update`, `page.publish`, `page.unpublish` and `page.delete`.

## Privacy and account deletion

- `PrivacySettings` holds the settings `privacy.page_id` (a published page, 0 for none), `privacy.deleted_stories` (`keep` or `delete`) and `privacy.neutral_name`. `link()` gives the policy's `{label, url}` or null.
- `AccountDataExporter::export($user)` returns the account's own data as an array with a `format_version`: account details, stories with their chapters and notes, bookmarks, reading places, followed stories, notifications and the activity log (action, time and address only). It never includes the password hash or a token, and it names other people's stories by title only. Add a field by name; do not dump rows.
- `AccountDeletionService::delete($actor, $subject)` removes the bookmarks, reading places, follows, notifications and tokens, clears the author text and picture, scrubs the account's addresses from the activity log, and anonymises the row (status `deleted`, a neutral display name, a login name and email address that cannot be used, a password hash nobody knows). The stories are deleted or kept according to `privacy.deleted_stories`. A person may delete their own account; `Capability::ManageUsers` may delete anyone's; the last active administrator is refused, with the rows locked. It fires `account_deleted`. A plugin that keeps its own data about an account listens to that action.
- `UserStatus::Deleted` accounts never sign in, are not found by email, and refuse every change from the Users screen.
- Audit actions: `settings.privacy.update`, `privacy.export` (by account number) and `account.delete`.

## Classification

`TaxonomyService` is the only code that reads or changes terms and their links to stories. A `Term` has a `TermType` (`Fandom`, `Character`, `Relationship`, `Genre`, `Tag`, `Category`, `Rating`, `Warning`, `RelationshipCategory`), a name, an address (`slug`, unique within its type and never changed by a rename), a `parentId` (the fandom of a character or relationship, otherwise 0), a `reviewed` flag, and `storyCount`, the number of public stories using it.

- `StoryService::classify($actor, $storyId, $selection)` sets a story's terms. `$selection` maps a type name to a list; a whole number is the id of an existing term and a string is a name. A name is matched against term names and aliases without regard to case, and otherwise becomes a new unreviewed term when `TermType::writersMayPropose()` allows it (fandom, character, relationship, tag). Only the types present in `$selection` change, and a type the archive mode hides (`TaxonomyService::enabledTypes()`) is ignored, so its saved links survive. It throws `StoryValidationException` with a message per type name.
- `TaxonomyService::lookup($type, $words, $parentId, $excludeId, $limit)` finds the terms of one kind whose name or alias contains the words, in every state (unapproved and hidden too), for an administrator's merge and create-term screens; it backs the administrator-only `GET /admin/taxonomy/lookup` (JSON, about ten matches, two characters at least, `merging={id}` or `type` and `fandom`). `TaxonomyService::suggest()` is the writer's version, for the story form, and leaves hidden terms out.
- `TermType::maximumPerStory()` limits each type, and a rating is single-choice. `TermType::availableIn($mode)` is the one rule for which types the archive mode shows; fandom, character, relationship and relationship category are for fanfiction, category is for original fiction, the rest are always on.
- Ask `TaxonomyService::writersMayPropose($type)`, not `TermType::writersMayPropose()`, whether writers may add new terms of a kind: the enum holds the fixed rule and the service adds the administrator's switch for tags (`taxonomy.writer_tags`, on unless set to false). A kind writers cannot add to is a curated list: ordered by hand, with descriptions and hiding.
- A term that a person who may curate (`TaxonomyService::canCurate()`, today `Capability::ManageSite`) adds through a story is stored already reviewed, and renaming it, adding or removing an alias, or describing it marks an unreviewed term reviewed in the same transaction. The audit entry carries `reviewed: true` when that happened, so a staff creation reads differently from a writer's proposal.
- Curating (`create`, `rename`, `addAlias`, `removeAlias`, `setReviewed`, `merge`, `delete`, `saveRequiredTypes`) needs `Capability::ManageSite` and throws `TaxonomyException` with a message safe to show. `merge()` moves story links, aliases, and a fandom's characters and relationships, and keeps the merged name as an alias.
- `StoryService::publish()` and `approve()` call `TaxonomyService::missingRequired()` and throw `StoryValidationException` when a required type has no term. A type whose list is empty and that writers cannot add to is never required, except the rating: `requiredTypes()` always starts with `TermType::Rating`, whatever `taxonomy.required` holds, and an empty list of ratings does not excuse it (migration 0036 adds General Audiences, Teen And Up Audiences, Mature and Explicit, with starter-list keys `rating.general` and so on, only when no rating exists). `classify()` on a published or pending story refuses a selection that leaves it without a rating (`StoryValidationException` keyed `rating`). `TaxonomyService::withoutRating($storyIds)` returns the stories among them that carry none, and `setHidden()` and `delete()` refuse the last visible rating with a `TaxonomyException`.
- `TaxonomyService` takes an optional `HookManager` and fires `term_merged` (`Term $source`, `Term $target`) after a merge, with the source's address already gone. Core uses it to redirect the old address. `mergeMany(User $actor, TermType $type, array $ids, int $targetId)` merges the other ticked terms into one of them in a single transaction (the kept term must be among the ids) and returns `merged`, `skipped` and `target`; it fires `term_merged` once for each merged term, after the commit. `moveToFandom(User $actor, int $termId, int $parentId)` gives a character or relationship another fandom (0 for none): the term's aliases move with it, a name or alias the target fandom already uses is refused with a `TaxonomyException`, the audit entry is `term.move` with `from_fandom` and `to_fandom`, and the `term_moved` action (`Term $term`, `int $oldFandomId`, `int $newFandomId`) fires after the commit. `moveByName()` does the same for a name typed on a fandom's screen, and `addManyToFandom(User $actor, TermType $type, int $fandomId, string $names)` creates one reviewed term per line (at most `TaxonomyService::BULK_NAMES_LIMIT`) and returns `created` and `skipped` (each with the name and the reason).
- Read with `list()`, `count()`, `find()`, `findBySlug()`, `resolve()`, `termsForStory()` and `termsForStories()` (one query for a page of stories). Filter public listings by term with the `story_terms` table and `StoryVisibility::publicCondition()`; use `storyCount` for display counts.
- `storyCount` is brought up to date by `StoryService` and `ChapterService` whenever a story or chapter changes whether it is public, and by `classify()`. Code that changes those states directly must call `StoryService::refreshTermCounts()`; `TaxonomyService::refreshAllCounts()` repairs every count.
- Names are trimmed, have runs of spaces collapsed, are at most 100 characters, and cannot contain a comma, control characters, or text-direction characters. They are stored as written; print them with `Html::e()`.
- Changes are written to the audit log as `term.create`, `term.propose`, `term.rename`, `term.review`, `term.alias_add`, `term.alias_remove`, `term.merge`, `term.delete`, `story.classify` and `settings.taxonomy.update`. The setting `taxonomy.required` holds the type names every story must have.

## Series, collections and export

`SeriesService` and `CollectionService` are the only code that changes series and collections, and `ExportService` the only code that builds an export. Each decides who may, throws `GroupingException` (or `ExportException`) with a message safe to show, and records the change in the audit log (`series.create`, `series.update`, `series.delete`, `series.add_story`, `series.remove_story`, `series.move_story`, `collection.create`, `collection.update`, `collection.delete`, `collection.submit`, `collection.approve`, `collection.remove`, `collection.withdraw`, `export.story`, `export.author`, `export.archive`).

- A series belongs to one author and holds only that author's stories, each in at most one series, numbered from 1 without gaps. The owner and staff with `Capability::EditAnyStory` manage it. `addStory()` refuses a story that is already in a series, `assign($actor, $storyId, ?$seriesId, ?$position)` moves it, and deleting a series or a story removes only the links.
- A collection has a curator, a visibility (public or private), `acceptsSubmissions` and `requiresApproval`. A story goes in only by its own author (`submit()`): the curator's own go straight in, other authors' go in at once or wait for approval as the curator chose. The curator `approve()`s or `remove()`s, and an author can always `withdraw()` their own. A private collection is reachable only by the curator and staff (`findVisible()`), and takes no submissions. Who may start one is `GroupingSettings::mayStartCollection()` (setting `collections.creators`: `administrator`, `editor` or `author`).
- Build every public list from `SeriesService::publicStories()` and `CollectionService::publicStories()`, `partOf()` and `forStory()`: they start from `StoryVisibility::publicCondition()`, so a draft, pending or unpublished story is never listed or counted. The switches are `GroupingSettings::seriesEnabled()` and `collectionsEnabled()` (settings `series.enabled`, `collections.enabled`); a feature that is off answers not found and hides its menu entries through the `admin_menu` filter, and keeps its data.
- `FeedSettings::kindEnabled()` and `offered()` also say no for a kind the archive mode or these switches turn off, so a plugin that advertises a feed asks them rather than reading `feeds.kinds`.
- `ExportService::story()`, `author()` and `archive()` return the path of a ZIP made for one request. Send it with `Response::file($path, $name, 'application/zip', true)`: the last argument makes the response delete the file once it is sent. The format is in `docs/EXPORT-FORMAT.md`; raise `ExportService::FORMAT_VERSION` when a field is renamed or removed.
- `TaxonomyService::labels()` returns the `ClassificationLabels` that give the name a site shows for each `TermType` (`singular()`, `plural()`, `lower()`, `lowerPlural()`). Use them wherever a kind is named to a person, and keep using `TermType->value` for addresses, stored values and anything that is parsed.

## Importing stories

`ImportService` is the only code that brings a story across from another archive, and writes only through `StoryService`, `ChapterService`, `TaxonomyService`, `SeriesService` and `CollectionService`, so an imported story obeys the same sanitiser, rating rule, approval flow and visibility as one typed in. It throws `ImportException` with a message safe to show. The flow is `begin()` (reads the uploaded files into a private plan under `storage/cache/imports`, returns a token), `preview()` (worked out afresh from the plan, writes nothing), then `apply()`, which only queues a run (state `running`, with the author's choices and the list of works left in `import_runs.job`) and returns the run's token, or `discard()`. `step($user, $runToken, ?$seconds)` writes the next stories for about that many seconds (always at least one, default `ImportService`'s constructor argument of 8), saving after each, and returns `state`, `done`, `total` and `busy`; call it until the state is `done`. It is safe to repeat and to run twice at once (a lock in `locked_until` keeps one at work), and a story whose writing was cut short is removed and made again. `cancel()` stops a run and keeps what it wrote, `progress()` says where a run stands, and `report()`, `reportText()` and `undo()` work on a finished or stopped run (`undo()` refuses a run still going). Every token belongs to the account that made it; another account gets null or an exception.

- Reading a file is a `Core/Import/WorkParser` (`parse(string $html, string $fileName = ''): OtwWork`). `OtwDownloadParser` reads a download from an OTW-Archive site and trusts its details; `OtwDownloadParser::recognises()` says whether a file carries the template's preface. `GeneralHtmlParser` reads any other HTML file: it keeps the title (the first `h1`, else the `title` element, else the file name) and the text, rebuilt from a short list of tags with every style, class and font dropped, and leaves every other field empty (`OtwWork::isGeneral()` is true, `reader` is `general`) so the preview asks the author instead of guessing; `withSplit('none'|'h1'|'h2'|'h3'|'hr')` returns a copy that cuts the file into chapters, and `GeneralHtmlParser::SPLITS` names the choices. A work's `fileHash` (the SHA-256 of the file, which `fingerprint()` returns when set) is how a second import of the same file is noticed, and `readerNotes` carry what the reader dropped, for the preview and the report. `ImportService::begin()` takes an options array (`general`, `split`, `combine`) and `resplit()` cuts a general file again from the text kept with the preview; what the author types for a general file arrives in the choices as `meta` and `order`. `OtwWork` and `OtwChapter` are the neutral value objects, both turned to and from arrays for the plan. `EpubReader::toHtml()` joins an EPUB's pages, in spine order, into the one document the parser reads. `UploadReader` takes the uploaded files (HTML, an EPUB, or a ZIP of HTML files) and opens a ZIP in memory with limits and name checks, and `RatingScale::suggest()` pairs a source rating with a site rating. Another source format is a new parser that returns the same `OtwWork`.
- `ImportSettings::mayImport($config, $user)` is the one rule for who may import (switched on, may write stories, and staff when it is staff-only). Hide a menu entry through the `admin_menu` filter as `ImportSettings::hideFromMenu()` does.
- `StoryService::applyDates()` and `ChapterService::applyDates()` set the created, updated and (for something already published) published dates of work that came from elsewhere. A date in the future is ignored and nothing is published by them.
- `Request::uploads($name)` returns every file sent under one field name (`name="work[]"`), each checked like `upload()`.
- A story is recorded in `story_imports` (cleared when the story is deleted) with the address read from the file and a hash of its title, author and opening, which is how a second import of the same work is noticed. The runs and their reports are in `import_runs`.
- Changes are written to the audit log as `story.import` (when a run finishes), `story.import_cancel`, `story.import_undo` and `settings.import.update`. The `story_imported` action (see Actions) fires after each imported story is saved.

## Chapter text and the editor

`ContentRenderer::render($text, $format)` is the only way chapter text becomes HTML, and the only code that branches on `ContentFormat` (`Html`, `Markdown`, `Plain`). Every format ends in `HtmlSanitizer::clean()`, the single XSS boundary: an allowlist of text formatting, headings, lists, quotations, rules and links, with `http`, `https` and `mailto` addresses only, no images, tables, forms, ids or inline styles, and only `lf-` and `language-` class names. Print its result as trusted HTML and print everything else with `Html::e()`.

- Markdown never passes raw HTML through. A trailing `{.left}`, `{.center}`, `{.right}` or `{.justify}` aligns a paragraph or heading, using the same `lf-align-*` classes the visual editor applies. Plain text is escaped and split into paragraphs and line breaks.
- `ContentRenderer::convertFormat()` changes how a chapter is written without changing what it says, between Markdown and HTML. Plain text is never converted. `toPlainText()` gives excerpt text with no markup.
- `EditorMode` (`Visual`, `Markdown`, `Html`, `Plain`) is what a writer picks; `Visual` and `Html` both store HTML. `EditorSettings::modeFor($userId, $storedFormat)` decides which mode the editor opens in, honouring the site default, the lock, and each writer's own choice (`setUserMode()`).
- Screens place the editor with `ContentEditor::render($user, 'content', $text, $storedFormat)`, inside their own `<form>`. It prints a textarea, a hidden `content_format` field to save with the text, Save draft and Publish buttons (replaceable with the `actions` option), and loads its scripts and styles from `admin/assets/`. The form must be wrapped in CSRF protection as usual.
- The editor script calls `POST /admin/content/preview` and `POST /admin/content/convert`. Both answer JSON, need the `WriteStories` capability, and take a single-use token that every answer replaces. They store nothing.

## Public pages and themes

Public pages are drawn by the active theme. How to write a theme, every template tag, and the data each view receives are in `THEME-DEVELOPMENT.md`; this section is for code that produces a page.

- Build the page's data as one array of plain values and call `ThemeRenderer::render(string $view, array $data = [], int $status = 200, array $page = []): Response`. The views are `home`, `story`, `chapter`, `author`, `archive`, `search`, `account`, `bookmarks`, `continue`, `updates`, `page` and `404`. `$page` may hold `title` (the page title without the site name), `noindex` (adds a robots meta tag and header), `variant` (a more specific template to try first) and `preview` (`['label' => ..., 'edit_url' => ...]`, which adds the preview notice).
- Strings in the data are always escaped when a theme prints them. Wrap text that has already passed through the content pipeline in `Core\Theme\SafeHtml`; nothing else is printed as markup.
- `StoryPresenter` builds the data for a story page and a chapter page. A preview and the public pages use it, so both hand a theme the same shape. Add a field there and it reaches every theme. It deliberately holds no owner id, rejection reason or login name.
- A signed-in reader's bookmarks, reading places and saved preferences live in `BookmarkService`, `ReadingProgressService`, `StoryFollowService` and `ReaderPreferences`. Each takes the account as an argument and scopes every query to it, and the lists leave out anything that is no longer public by the same rule as the archive. `deleteForUser(int $userId)` on the first three removes everything an account holds; a feature that deletes accounts must call all three. `ReadingProgressService` also keeps how far down the chapter the reader got (`savePosition()` accepts 0 to 1000 only), and `StoryFollowService::changes()` reports the new and revised chapters of followed stories since the reader's last visit. A reader follows a story only by choosing to: nothing in core follows on reading or bookmarking, and a plugin should keep to that. Core loads `assets/js/reading-position.js` for a signed-in reader through the `lf_footer` hook on chapter pages. Core writes the account's saved choices into the page head, ahead of the theme's own `lf_head` output, as the `lf-reader-prefs`, `lf-reader-save` and `lf-reader-token` meta tags.
- The renderer is on the kernel as `$kernel->themes`. `$kernel->themes->activeTheme()` returns the site's theme (the built-in one when the chosen theme is missing) and `bootTheme()` runs its `functions.php` once. If a theme fails while drawing a page the renderer logs the error and draws the page again with the built-in theme.
- A chapter and a story each have a second note, `end_notes`, printed after the text (`Chapter::$endNotes`, `Story::$endNotes`, validated like `notes` with `END_NOTES_MAX`). `StoryPresenter::chapter()` blanks `story.end_notes` unless the chapter is the last of a finished story, so the rule lives in one place for every theme. The downloads include both notes.
- The whole-story page is the `story` view with the variant `full` (`PublicUrl::wholeStory()`, route name `story.full`, registered before the chapter route). `WholeStoryPresenter` arranges the chapters the controller took from `ChapterService::listPublic()`, so it cannot show one a visitor could not open; `WholeStoryPresenter::fitting()` applies the word cap, always letting the first chapter through, and `WholeStorySettings::enabled()` and `wordLimit()` read the two settings (`reading.whole_story`, `reading.whole_story_words`, 5,000 to 500,000, 100,000 by default). It fires `story_viewed` once, with a null chapter, however many chapters it prints.
- The site's footer text is a site setting read with `FooterText::text($config)` (`footer.text`, plain text, up to 2,000 characters) and printed by `lf_footer_text()`. It is exported with the settings file as the `footer` group.
- `ReaderPreferences::CHOICES` also holds `view` (`chapters`, `whole`: how a story with several chapters opens, applied on the server) and `reader` (`off`, `on`: reader mode, switched by the reader script and saved with the other display choices). The account form and the reader script each keep the stored value of the choices they do not send.
- A response from a preview or any page that shows non-public text should carry `Cache-Control: private, no-store`; the renderer does not add caching headers of its own.
- The public addresses the theme layer registers are `/` (the home page), `/theme-options.css` (the stylesheet made from a theme's options, the site's theme unless `?theme=folder` names another installed one, empty until something is chosen), `/theme-fallback.css` (the built-in theme's stylesheet) and `POST /theme` (the theme switcher's form).
- Which theme a public page is drawn in is decided in one place, `Services\ThemeChoice`, which `Kernel::selectTheme()` calls once per request after the maintenance check: an Administrator's preview (held in their session), then a visitor's own choice (the account's, else the `lf_theme` cookie, only when it is on the offered list), then the site's theme. The answer is handed to the renderer with `ThemeRenderer::useTheme()`, and `ThemeRenderer::currentTheme()` is what pages are drawn in; it still checks that the theme is installed, valid and compatible, and falls back to `activeTheme()` otherwise. Admin screens, the installer, downloads, feeds and the stylesheets are never asked: they use `activeTheme()` or no theme. A plugin that draws a public page through `ThemeRenderer::render()` gets this for free, and a response carries `Vary: Cookie` when the choice is switched on and `Cache-Control: private, no-store` during a preview.
- A reader's own theme is stored with their other reader preferences (`ReaderPreferences::theme()` and `saveTheme()`); `ReaderPreferences::save()` keeps it when the reading form is saved, and it is part of the account's data export.

## Admin screens

Add a screen to the sidebar with the `admin_menu` filter. Each entry needs a lower-case `slug`, a `label`, a `path` below `/admin/` (letters, digits, `_`, `.`, `/`, `-`), and optionally a `capability` (a `Capability` case) and `children` with the same shape:

```php
add_filter('admin_menu', function (array $items): array {
    $items[] = ['slug' => 'library', 'label' => 'Library', 'path' => 'library', 'capability' => \LumoraFiction\Models\Capability::WriteStories];

    return $items;
});
```

- The built-in top-level slugs are, in order: `dashboard`, `alerts`, `stories`, `news`, `pages`, `profile`, `users`, `taxonomy`, `appearance`, `plugins`, `settings`, `maintenance`. The `stories` group also holds `stories-series`, `stories-collections` and `stories-export`, and `settings` holds `settings-groupings`. Attach a child to a group by finding its slug in the filter. From the first release after 1.1.0: `alerts` and `plugins` are top-level entries, and `maintenance` holds `maintenance-updates`, `maintenance-backups`, `maintenance-mode`, `maintenance-security-events` and `maintenance-system-information`; `users` is a group with `users-all` and `users-privacy`. The old slugs `maintenance-alerts`, `maintenance-plugins` and `maintenance-privacy` no longer exist, and no address changed. A plugin that added a child to `maintenance` still lands there; one that looked for `maintenance-plugins` to sit beside it should look for `plugins` instead.
- A child can be a **heading**, a plain label that groups the entries after it: `['slug' => 'my-heading', 'label' => 'My group', 'path' => '', 'heading' => true]`. It has no address and is never a link or the current page; it is shown only while at least one entry the person can see follows it before the next heading. Core uses headings to group Settings (General, Reading and discovery, Community, Safety and privacy, Data); an entry a plugin appends to a group lands after the last one.
- The menu builds the address itself; an address in an entry is ignored. A malformed entry is dropped. An entry whose capability is not a `Capability` case is shown to administrators only.
- Hiding an entry is a convenience. Your screen must check the capability itself, on the server, before doing anything.
- Register the screen's routes in a `routes_register` callback, as described below.
- For a screen that shows one panel at a time, `LumoraFiction\Core\Admin\Tabs` prints the tab bar and the panel attributes: `new Tabs([['key' => 'one', 'label' => 'One'], ...], $selected, 'my-id', $screenUrl)`, then `$tabs->bar('Label')`, and `$tabs->openPanel('one')` ... `$tabs->closePanel()` around each panel. Each tab is a link to `?tab={key}`, so the screen works without a script, and `Tabs::choose($request->queryValue('tab'), $keys, $default)` picks the open tab safely. Load `admin/assets/js/tabs.js` on the screen to switch panels without a reload. Everything printed is escaped, and the CSS classes are `lf-tabs`, `lf-tabs__tab` and `lf-tabs__panel`.
- To draw the screen inside the admin layout, build the body as a string with every value escaped, then call `$kernel->admin->renderContent($html, $user, $title, $currentPath, $flash, $status)`. `$kernel->admin` (an `AdminView`) is set before plugins load; `$currentPath` is the path you gave the menu entry, so the entry is highlighted. A class that extends `LumoraFiction\Controllers\Admin\AdminController` gets `guard()` (sign-in and capability check) and `validToken()` for free; build a `FlashMessages` from `$kernel->sessions` for it. The bundled Dummy Content plugin is a worked example.

## Site alerts

Alerts are about the site itself and are shown to Administrators only (the `ManageSite` capability), under **Alerts**, on the dashboard and as a count in the admin header. They are separate from anything about stories. There are two kinds.

A **live alert** describes a state that can be checked again on every request: debug mode is on, a plugin is failing. Add one with the `admin_alerts` filter; it clears by itself when the cause goes and cannot be dismissed.

```php
add_filter('admin_alerts', function (array $alerts) use ($kernel): array {
    if (my_plugin_has_no_api_key($kernel)) {
        $alerts[] = [
            'key' => 'my-plugin.no-key',
            'severity' => 'warning',
            'message' => 'My Plugin has no API key, so it is switched off.',
            'path' => 'settings/my-plugin',
        ];
    }

    return $alerts;
});
```

- `key` is lower-case letters, digits, `.`, `_` or `-` (up to 100 characters), and names the alert. `severity` is `critical`, `warning` or `info`; the header count includes only critical alerts and warnings. `message` is plain text of up to 500 characters and `detail` up to 1,000; both are escaped when shown. `path` is optional and is an admin address below `/admin/`, such as `maintenance/plugins`, for the **Fix this** link; a full address is refused.
- An entry that is malformed is dropped on its own and never breaks the screen or the dashboard.
- Never put a folder path, a password, a token, a login name or anything a visitor typed in a message or detail, because the screen and the optional email repeat them.

A **stored alert** records that something happened. Raise it from code that runs after the kernel has booted through `$kernel->alerts`:

```php
$kernel->alerts->raise('my-plugin.sync-failed', \LumoraFiction\Models\AlertSeverity::Warning, 'The nightly sync failed.', '', 'maintenance/plugins');
$kernel->alerts->clear('my-plugin.sync-failed');
```

- Raising the same key again adds one to its count and refreshes its message and last-seen time, so a repeating problem is one alert, not many. `clear()` removes it once the cause is gone.
- An Administrator can dismiss a stored alert. It stays hidden while the problem keeps repeating and comes back if the problem returns after a week without it.
- Stored alerts older than 30 days are removed, and no more than 200 are kept.
- A critical stored alert is also emailed to the oldest active Administrator when they switched that on, at most once per alert per day.
- `raise()` never throws, so a problem with the alert cannot break the request that noticed it. The built-in alerts (debug mode, installer folder, HTTPS, unwritable folders, plugin failures, paused registration, bursts of sign-in lockouts and refused registrations, and failing email) use the same two paths.

## Updates, backups and maintenance mode

The Updates screen's release notes go through `Core\Update\ReleaseNotes`, which runs the Markdown parser (raw HTML is never passed through) and the sanitiser (no images, only http, https and mailto links) and opens every link in a new tab with `rel="noopener noreferrer"`. `CompatibilityChecker::system()` returns the checks that need no package (the same private checks `check()` runs on a staged one), `UpdateChecker::isDue()` and `checkIfDue()` drive the automatic check from `Kernel::checkForUpdates()`, and a GitHub token reaches GitHub's API only through a transport that implements `TokenAwareTransport` (`HttpClient` adds it to requests for `api.github.com`, never to a download or a redirect). `Core\Admin\BackupListing` builds a backup's row for both the Backups and Updates screens.

An update replaces what a release ships and nothing else, so a plugin or theme in `content/plugins/` or `content/themes/` that the release does not contain is never touched, and neither are `config/`, `content/uploads/` and `storage/`. A plugin or theme that a release does ship is replaced as a whole folder (files it no longer has are removed), so a plugin must not keep its own data inside its folder; keep it in the database or under `storage/`. The release script refuses to build while a bundled plugin or theme has changed without a higher `Version:` header.

Core's fence is the `UpdateLayout` class: it names the only folders and files an update may write, and anything that must survive an update belongs outside them.

Maintenance mode is applied before routing. `Kernel::maintenanceResponse()` returns the 503 notice for a visitor, or null when the request may go on (a signed-in Administrator, an allowed address, or any path under `/admin`). The `maintenance` view is drawn by the active theme and receives `message`. An update additionally raises a marker file, `storage/updates/maintenance.flag`, which the front controller checks before the application starts; plugins cannot change that page. `$kernel->maintenance` exposes `isActive()` for a plugin that wants to pause its own background work while the site is closed.

The run state of an update (`storage/updates/state.json`) is read by whichever release is installed when the next step arrives, so its fields and the names of its steps are kept readable across releases; add fields, never rename or remove them.

## Page caching

Public pages go through `Kernel::finishResponse()` before they are sent. `$kernel->pageCache` (a `LumoraFiction\Core\Cache\PageCache`) adds `Cache-Control`, `ETag` and `Vary: Cookie` to a page, answers `304 Not Modified` when the visitor already holds it, and adds `X-LiteSpeed-Purge` when something changed. What a plugin needs to know:

- Only responses of these named routes are ever cached: the home page, story and chapter pages, browse, author, term, series and collection pages, news, static pages and search. Every other response of type `text/html` is sent `Cache-Control: private, no-store`. A route a plugin registers is therefore never cached unless a later release says so.
- A response that already carries a `Cache-Control` header is left exactly as it is. A page that must never be shared (for example one drawn for a signed-in person) sets `Cache-Control: private, no-store` itself, as the core controllers do.
- A page is never cached for a request that carries the session cookie, for a response that sets a cookie, or while reading needs a signed-in account. A page for a visitor who may pick a theme carries `Vary: Cookie` and is kept by that visitor's browser only.
- The page is drawn before the cache decides anything, so `story_viewed` fires on every request that reaches PHP, a `304` included. Because a shared cache would hide visits from a counter, story and chapter pages are kept only by the browser (`private, no-cache`) while any plugin listens to `story_viewed`.
- `Resolution::$name` holds the name of the matched route (`Router::FALLBACK_NAME` for the static pages route), for code that needs to know which kind of page it is looking at.
- Emptying an outside cache is driven by the audit log: `AuditLogService::listen(callable $listener)` registers a callback that hears every recorded `(action, targetType, targetId)` after it is stored (a failing listener is ignored). `PurgeRules::scopeFor()` turns an action into tags to empty, or everything. A plugin whose change alters what visitors see should record an audit entry for it; an action the rules do not know empties everything, so nothing is left stale. `$kernel->pageCache->purge(['story-12-slug'])` asks for a tag to be emptied directly (`story-{slug}`, `listings`, `news`, `page-{slug}`, or `PurgeRules::ALL`).

## Routes

Register routes from a `routes_register` callback:

```php
add_action('routes_register', function (\LumoraFiction\Core\Http\Router $router): void {
    $router->get('/reading-list/{id}', function (array $params): void {
        echo 'List ' . htmlspecialchars($params['id'], ENT_QUOTES, 'UTF-8');
    }, 'reading-list');
});
```

- The first route that matches wins, and core registers its own addresses before `routes_register` runs, so a plugin cannot take over one of them. Core owns `/story/...`, `/author/...`, `/account/...`, `/search`, `/admin/...`, `/theme-options.css`, `/theme-fallback.css`, and one first segment for each kind of term: `/fandom`, `/character`, `/relationship`, `/genre`, `/tag`, `/category`, `/rating`, `/warning` and `/relationship-category`.

- `{name}` matches one path segment. `{name*}` matches across slashes; register greedy routes last.
- Handlers receive the captured parameters as an array of strings, and the `Request`. Treat both as untrusted input.
- A handler may return a `Response` (`Response::html()`, `Response::redirect()`, `Response::download()`) or print its own output. `Response::redirect()` only accepts a path on this site.
- Read cookies, form values, and the visitor's address from the `Request`, never from the PHP superglobals. `Request::ip()` never reads a forwarding header itself, because anyone can forge one: `TrustedProxies` has already replaced `REMOTE_ADDR` when, and only when, the connection came from a proxy the administrator listed. `Request::peer()` is the address the connection really came from, and `Request::isHttps()` already reflects a trusted proxy's `X-Forwarded-Proto`. Never read `HTTP_X_FORWARDED_*` yourself.
- A route can be named, and `$router->urlFor('reading-list', ['id' => 7])` returns its path. The path does not include the install's base directory.
- Paths containing control characters, invalid UTF-8, or `.` and `..` segments never match a route.
