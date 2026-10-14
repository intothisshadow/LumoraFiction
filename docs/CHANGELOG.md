# Changelog

## [Unreleased]

- Request handling: clean URLs with a router that rejects malformed paths, a generic error page that never exposes file paths or credentials (details go to the log only), and the action and filter hooks plugins and themes build on.
- Database foundation: a configurable random table prefix, versioned database migrations that cannot run twice at once, and the initial tables for accounts, settings, sessions, and the audit log. Each account has a private login name separate from its public display name and URL slug.
- Project skeleton: front controller, directory layout, and web server rules that keep configuration, source, and storage directories from being downloaded.
