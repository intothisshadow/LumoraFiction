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
 * Requires PHP: 8.2
 * Requires at least: 0.1.0
 */

declare(strict_types=1);

add_action('routes_register', static function (\LumoraFiction\Core\Http\Router $router): void {
    // ...
});
```

`Plugin Name` and `Version` are required; a plugin without both, or with a version or requirement that is not a plain number such as `1.2.3`, is not listed. `Author`, `Description`, `Requires PHP` and `Requires at least` (the Lumora Fiction version) are optional. The header can sit in its own comment block after the usual file docblock.

- **Lifecycle.** An administrator turns a plugin on under Maintenance > Plugins. The file is run once in that request, and the plugin is refused, with the reason shown, if it throws, has a syntax error, or needs a newer PHP or Lumora Fiction than the site has. Once on, the file runs on every request while the application starts, after the core services and the core routes are ready and before `routes_register` fires, in the order the plugins were turned on. Turning a plugin off stops it running from the next request. The slug is kept in the `plugins.active` setting.
- **What the file sees.** `$kernel` is in scope, so a plugin can read `$kernel->config`, `$kernel->database`, `$kernel->auth`, `$kernel->hooks` and the other services. Do the work in hook callbacks rather than directly in the file where you can. Register settings defaults for your own keys with `$kernel->config->registerDefaults([...])`, and read and write them with `setting()` and `setSetting()`; prefix every key with your slug.
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
| `story_state_changed` | `Story $story`, `PublicationState $from`, `User $actor`, `string $reason` | After a story's publication state has changed and been saved: submitted, published, approved, sent back, withdrawn or unpublished. `$story` is the story as it is now and `$from` its earlier state. `$reason` is the reviewer's note when a story is sent back or taken down in bulk, and an empty string otherwise. Use it to notify people; a callback that fails cannot undo the change. |
| `user_role_changed` | `User $user`, `UserRole $from`, `User $actor`, `string $reason` | After an administrator has changed an account's role and it has been saved. `$user` is the account as it is now, and `$reason` is empty when none was given. |
| `user_status_changed` | `User $user`, `UserStatus $from`, `User $actor`, `string $reason` | After an administrator has suspended or reinstated an account. `$from` is the status the account had before; read the new one from `$user`. |
| `user_publishing_override_changed` | `User $user`, `?PublishingOverride $from`, `User $actor` | After an administrator has changed how an author's stories are published. `$from` is null when the author followed the site setting. |
| `lf_form_fields` | `string $formId` | Inside a public form, fired by the theme's form part. Core prints the hidden trap and timing fields here for `account_register`, `account_forgot` and `account_resend`. |
| `lf_head` | `string $view` | While a public page prints its `<head>`, after the stylesheets. Print markup the page needs there. |
| `lf_body_open` | `string $view` | Right after the opening `<body>` of a public page. |
| `lf_footer` | `string $view` | Just before the closing `</body>` of a public page, after queued scripts. |

## Filters

| Filter | Value | Purpose |
| ------ | ----- | ------- |
| `lf_styles` | `array<string, string>` handle => path | Add stylesheets to every public page. Paths must start with a single `/`; anything else is dropped. |
| `lf_scripts` | `array<string, string>` handle => path | Add scripts to every public page, loaded with `defer`. Same rule for paths. |
| `lf_body_class` | `list<string>`, plus the view | Add classes to the body of a public page. Names that are not plain class names are dropped. |
| `lf_document_title` | `string`, plus the view | Change the whole `<title>` of a public page. The result is escaped. |
| `lf_navigation_items` | list of `{label, url, current?}` | Add items to the public main menu. An entry whose address is not a path on this site is dropped. Core starts the list with Home and Search, adds Sign in and Register for visitors, Account for signed-in members, and Admin for staff. |
| `theme_option_fields` | list of field definitions | Declare the options of the active theme. See `THEME-DEVELOPMENT.md`. |
| `csp_directives` | `array<string, string>` directive name => value | Widen the default same-origin Content-Security-Policy, for example to allow images from one extra host. A value that is not an array is ignored and the default stays. |
| `admin_menu` | list of menu entries | Add screens to the admin sidebar. See "Admin screens" below. |
| `content_html` | `string` HTML, plus the `ContentFormat` | Adjust the final HTML of a chapter just before it is shown. The result is sanitised again, so a plugin cannot add scripts, images or other elements the allowlist refuses. |
| `lf_registration_spam_reasons` | `list<string>` (empty), plus `array{display_name, email, ip}` | Return reasons to refuse a sign-up. Any non-empty text is enough: the sign-up is dropped without telling the sender, the visitor sees the usual thanks, and the reason is written to the Security events list as `plugin: {reason}`. It runs after the built-in checks and before Akismet, and never sees the password. |
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
- `SecurityEventLog::record($event, $ip, $detail)` writes to the Security events list. It never throws, keeps only the event name, the address and a short note, and trims by age (`security.log_retention_days`). `recordOnce()` skips a repeat from the same address inside ten minutes, which suits events one client can cause thousands of times. Never pass anything a visitor typed as the detail.

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
| `security.log_retention_days` | `30` | How long Security events are kept, from 1 to 365. |

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
- Chapters are numbered 1 to n with no gaps. Reordering and deleting renumber them. A chapter stores its text as written plus a `ContentFormat`; sanitising happens when the text is shown, never by trusting what was stored.
- A story is visible to visitors only when it is published and has at least one published chapter. Build every public query from `StoryVisibility::publicCondition()` (or `StoryService::findPublicBySlug()` and `listPublic()`) so no screen applies a looser rule. A draft must never be returned by a public address.
- Changes are written to the audit log as `story.create`, `story.update`, `story.work_status`, `story.submit`, `story.publish`, `story.approve`, `story.reject`, `story.withdraw`, `story.unpublish`, `user.publishing_override`, `story.delete`, `chapter.create`, `chapter.update`, `chapter.reorder`, `chapter.publish`, `chapter.unpublish` and `chapter.delete`.
- Story and chapter text is stored raw. Print it with `Html::e()`.
- `StoryService::listForWriter()`, `countForWriter()` and `countsByState()` feed the writer screens. The scope `all` is honoured only for users with `EditAnyStory`; for anyone else the list is always limited to their own stories, whatever the request asks. `bulk($actor, 'publish'|'unpublish'|'delete', $ids)` applies one action to up to 100 stories, checking each on its own, and returns `done` and `skipped` counts.
- `ChapterService::move($actor, $chapterId, 'up'|'down')` swaps a chapter with its neighbour through `reorder()`, and `countsForStories()` gives chapter totals for a page of stories in one query. `UserService::displayNames()` returns public names only, in one query.
- The writer screens are `StoryController` and `ChapterController`, which extend `WriterController`. Its `writer()` method is the permission check for a screen that has a form: a guest who posts one of the listed fields gets the 401 `session-ended` page that returns the text and sends no token, instead of a redirect that would discard it. Name the fields to keep and the screen to return to when you build another form that holds long text. `Request::inputList()` reads a posted `name[]` list as text values only.

## Classification

`TaxonomyService` is the only code that reads or changes terms and their links to stories. A `Term` has a `TermType` (`Fandom`, `Character`, `Relationship`, `Genre`, `Tag`, `Category`, `Rating`, `Warning`, `RelationshipCategory`), a name, an address (`slug`, unique within its type and never changed by a rename), a `parentId` (the fandom of a character or relationship, otherwise 0), a `reviewed` flag, and `storyCount`, the number of public stories using it.

- `StoryService::classify($actor, $storyId, $selection)` sets a story's terms. `$selection` maps a type name to a list; a whole number is the id of an existing term and a string is a name. A name is matched against term names and aliases without regard to case, and otherwise becomes a new unreviewed term when `TermType::writersMayPropose()` allows it (fandom, character, relationship, tag). Only the types present in `$selection` change, and a type the archive mode hides (`TaxonomyService::enabledTypes()`) is ignored, so its saved links survive. It throws `StoryValidationException` with a message per type name.
- `TermType::maximumPerStory()` limits each type, and a rating is single-choice. `TermType::availableIn($mode)` is the one rule for which types the archive mode shows; fandom, character, relationship and relationship category are for fanfiction, category is for original fiction, the rest are always on.
- Curating (`create`, `rename`, `addAlias`, `removeAlias`, `setReviewed`, `merge`, `delete`, `saveRequiredTypes`) needs `Capability::ManageSite` and throws `TaxonomyException` with a message safe to show. `merge()` moves story links, aliases, and a fandom's characters and relationships, and keeps the merged name as an alias.
- `StoryService::publish()` calls `TaxonomyService::missingRequired()` and throws `StoryValidationException` when a required type has no term. A type whose list is empty and that writers cannot add to is never required.
- Read with `list()`, `count()`, `find()`, `findBySlug()`, `resolve()`, `termsForStory()` and `termsForStories()` (one query for a page of stories). Filter public listings by term with the `story_terms` table and `StoryVisibility::publicCondition()`; use `storyCount` for display counts.
- `storyCount` is brought up to date by `StoryService` and `ChapterService` whenever a story or chapter changes whether it is public, and by `classify()`. Code that changes those states directly must call `StoryService::refreshTermCounts()`; `TaxonomyService::refreshAllCounts()` repairs every count.
- Names are trimmed, have runs of spaces collapsed, are at most 100 characters, and cannot contain a comma, control characters, or text-direction characters. They are stored as written; print them with `Html::e()`.
- Changes are written to the audit log as `term.create`, `term.propose`, `term.rename`, `term.review`, `term.alias_add`, `term.alias_remove`, `term.merge`, `term.delete`, `story.classify` and `settings.taxonomy.update`. The setting `taxonomy.required` holds the type names every story must have.

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
- The renderer is on the kernel as `$kernel->themes`. `$kernel->themes->activeTheme()` returns the theme in use (the built-in one when the chosen theme is missing) and `bootTheme()` runs its `functions.php` once. If a theme fails while drawing a page the renderer logs the error and draws the page again with the built-in theme.
- A response from a preview or any page that shows non-public text should carry `Cache-Control: private, no-store`; the renderer does not add caching headers of its own.
- The public addresses the theme layer registers are `/` (the home page), `/theme-options.css` (the stylesheet made from the active theme's options, empty until something is chosen) and `/theme-fallback.css` (the built-in theme's stylesheet).

## Admin screens

Add a screen to the sidebar with the `admin_menu` filter. Each entry needs a lower-case `slug`, a `label`, a `path` below `/admin/` (letters, digits, `_`, `.`, `/`, `-`), and optionally a `capability` (a `Capability` case) and `children` with the same shape:

```php
add_filter('admin_menu', function (array $items): array {
    $items[] = ['slug' => 'library', 'label' => 'Library', 'path' => 'library', 'capability' => \LumoraFiction\Models\Capability::WriteStories];

    return $items;
});
```

- The menu builds the address itself; an address in an entry is ignored. A malformed entry is dropped. An entry whose capability is not a `Capability` case is shown to administrators only.
- Hiding an entry is a convenience. Your screen must check the capability itself, on the server, before doing anything.
- Register the screen's routes in a `routes_register` callback, as described below.
- To draw the screen inside the admin layout, build the body as a string with every value escaped, then call `$kernel->admin->renderContent($html, $user, $title, $currentPath, $flash, $status)`. `$kernel->admin` (an `AdminView`) is set before plugins load; `$currentPath` is the path you gave the menu entry, so the entry is highlighted. A class that extends `LumoraFiction\Controllers\Admin\AdminController` gets `guard()` (sign-in and capability check) and `validToken()` for free; build a `FlashMessages` from `$kernel->sessions` for it. The bundled Dummy Content plugin is a worked example.

## Site alerts

Alerts are about the site itself and are shown to Administrators only (the `ManageSite` capability), under **Maintenance > Alerts**, on the dashboard and as a count in the admin header. They are separate from anything about stories. There are two kinds.

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
