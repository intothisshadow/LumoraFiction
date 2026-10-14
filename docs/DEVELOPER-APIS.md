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
