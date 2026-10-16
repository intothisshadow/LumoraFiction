# Developer APIs

Reference for plugin authors. It describes only what exists today and is updated whenever a hook, filter, or service changes.

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
| `routes_register` | `Router $router` | While the application starts, before any request is matched. Register routes here. |
| `kernel_booted` | `Kernel $kernel` | When the core services are ready. |

## Filters

| Filter | Value | Purpose |
| ------ | ----- | ------- |
| `csp_directives` | `array<string, string>` directive name => value | Widen the default same-origin Content-Security-Policy, for example to allow images from one extra host. A value that is not an array is ignored and the default stays. |
| `admin_menu` | list of menu entries | Add screens to the admin sidebar. See "Admin screens" below. |
| `content_html` | `string` HTML, plus the `ContentFormat` | Adjust the final HTML of a chapter just before it is shown. The result is sanitised again, so a plugin cannot add scripts, images or other elements the allowlist refuses. |
| `admin_dashboard_notices` | `list<array{variant: string, message: string}>`, plus the `Request` | Add a notice to the administrator's dashboard. `variant` is `error`, `warning`, `info` or `success`; the message is plain text and is escaped. |

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

## Stories and chapters

`StoryService` and `ChapterService` are the only code that creates, changes, publishes or deletes stories and chapters, and they decide who may. Pass them the signed-in `User`; they throw `StoryPermissionException` when the story is missing or the user may not change it (the two look the same on purpose) and `StoryValidationException`, which carries a message per field, when the input is not acceptable.

- An Author changes only their own stories. A user with `EditAnyStory` may change any. A suspended account changes nothing.
- A story has a work status (`WorkStatus`: ongoing, completed, on hiatus, abandoned) and, separately, a publication state (`PublicationState`: draft, pending, published, unpublished). `StoryService::publish()` publishes at once for users with `PublishWithoutReview` or `ModerateStories` and otherwise puts the story in the approval queue as pending.
- Chapters are numbered 1 to n with no gaps. Reordering and deleting renumber them. A chapter stores its text as written plus a `ContentFormat`; sanitising happens when the text is shown, never by trusting what was stored.
- A story is visible to visitors only when it is published and has at least one published chapter. Build every public query from `StoryVisibility::publicCondition()` (or `StoryService::findPublicBySlug()` and `listPublic()`) so no screen applies a looser rule. A draft must never be returned by a public address.
- Changes are written to the audit log as `story.create`, `story.update`, `story.work_status`, `story.submit`, `story.publish`, `story.unpublish`, `story.delete`, `chapter.create`, `chapter.update`, `chapter.reorder`, `chapter.publish`, `chapter.unpublish` and `chapter.delete`.
- Story and chapter text is stored raw. Print it with `Html::e()`.

## Chapter text and the editor

`ContentRenderer::render($text, $format)` is the only way chapter text becomes HTML, and the only code that branches on `ContentFormat` (`Html`, `Markdown`, `Plain`). Every format ends in `HtmlSanitizer::clean()`, the single XSS boundary: an allowlist of text formatting, headings, lists, quotations, rules and links, with `http`, `https` and `mailto` addresses only, no images, tables, forms, ids or inline styles, and only `lf-` and `language-` class names. Print its result as trusted HTML and print everything else with `Html::e()`.

- Markdown never passes raw HTML through. A trailing `{.left}`, `{.center}`, `{.right}` or `{.justify}` aligns a paragraph or heading, using the same `lf-align-*` classes the visual editor applies. Plain text is escaped and split into paragraphs and line breaks.
- `ContentRenderer::convertFormat()` changes how a chapter is written without changing what it says, between Markdown and HTML. Plain text is never converted. `toPlainText()` gives excerpt text with no markup.
- `EditorMode` (`Visual`, `Markdown`, `Html`, `Plain`) is what a writer picks; `Visual` and `Html` both store HTML. `EditorSettings::modeFor($userId, $storedFormat)` decides which mode the editor opens in, honouring the site default, the lock, and each writer's own choice (`setUserMode()`).
- Screens place the editor with `ContentEditor::render($user, 'content', $text, $storedFormat)`, inside their own `<form>`. It prints a textarea, a hidden `content_format` field to save with the text, Save draft and Publish buttons (replaceable with the `actions` option), and loads its scripts and styles from `admin/assets/`. The form must be wrapped in CSRF protection as usual.
- The editor script calls `POST /admin/content/preview` and `POST /admin/content/convert`. Both answer JSON, need the `WriteStories` capability, and take a single-use token that every answer replaces. They store nothing.

## Admin screens

Add a screen to the sidebar with the `admin_menu` filter. Each entry needs a lower-case `slug`, a `label`, a `path` below `/admin/` (letters, digits, `_`, `.`, `/`, `-`), and optionally a `capability` (a `Capability` case) and `children` with the same shape:

```php
add_filter('admin_menu', function (array $items): array {
    $items[] = ['slug' => 'stories', 'label' => 'Stories', 'path' => 'stories', 'capability' => \LumoraFiction\Models\Capability::WriteStories];

    return $items;
});
```

- The menu builds the address itself; an address in an entry is ignored. A malformed entry is dropped. An entry whose capability is not a `Capability` case is shown to administrators only.
- Hiding an entry is a convenience. Your screen must check the capability itself, on the server, before doing anything.
- Register the screen's routes in a `routes_register` callback, as described below.

## Routes

Register routes from a `routes_register` callback:

```php
add_action('routes_register', function (\LumoraFiction\Core\Http\Router $router): void {
    $router->get('/story/{id}', function (array $params): void {
        echo 'Story ' . htmlspecialchars($params['id'], ENT_QUOTES, 'UTF-8');
    }, 'story');
});
```

- `{name}` matches one path segment. `{name*}` matches across slashes; register greedy routes last.
- Handlers receive the captured parameters as an array of strings, and the `Request`. Treat both as untrusted input.
- A handler may return a `Response` (`Response::html()`, `Response::redirect()`, `Response::download()`) or print its own output. `Response::redirect()` only accepts a path on this site.
- Read cookies, form values, and the visitor's address from the `Request`, never from the PHP superglobals. `Request::ip()` ignores forwarding headers because anyone can forge them.
- A route can be named, and `$router->urlFor('story', ['id' => 7])` returns its path. The path does not include the install's base directory.
- Paths containing control characters, invalid UTF-8, or `.` and `..` segments never match a route.
