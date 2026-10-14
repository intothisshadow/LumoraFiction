# Changelog

## [Unreleased]

- Database foundation: a configurable random table prefix, versioned database migrations that cannot run twice at once, and the initial tables for accounts, settings, sessions, and the audit log. Each account has a private login name separate from its public display name and URL slug.
- Project skeleton: front controller, directory layout, and web server rules that keep configuration, source, and storage directories from being downloaded.
