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
| `security.hsts` | `false` | Send `Strict-Transport-Security` over HTTPS. Once a browser has seen it, it refuses plain HTTP for the site for a year. |

Emailed links are built from the `site_url` value in `config/config.php`, never from the request, so it must be set to the site's full public address.

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
- Handlers receive the captured parameters as an array of strings. Treat them as untrusted input.
- A route can be named, and `$router->urlFor('story', ['id' => 7])` returns its path. The path does not include the install's base directory.
- Paths containing control characters, invalid UTF-8, or `.` and `..` segments never match a route.
