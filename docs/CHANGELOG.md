# Changelog

## [Unreleased]

- Admin area: sign-in and sign-out, a dashboard that flags things needing attention, Settings > Archive (what the site publishes, who may register, what waits for approval), and Maintenance > System Information with a downloadable snapshot that leaves out passwords, the secret key, and server folders. Every settings change is recorded in an audit log. The area works in light and dark mode, with a skip link, labelled forms, and a layout for phones.
- Browser installer: checks the server, tests the database login and privileges before building anything, creates the tables with a random table prefix, sets up the first administrator, and removes itself when done. If anything goes wrong part-way it removes what it created so the form can be submitted again.
- Accounts and sign-in: each account has a private login name separate from its public display name and URL slug; roles for Administrator, Editor, Author and Reader; registration with email confirmation; sign-in that gives the same answer for an unknown account and a wrong password; a five-attempt, fifteen-minute lockout; password reset by emailed single-use link; sessions stored in the database with secure cookies; CSRF protection; standard security headers and a strict Content-Security-Policy.
- Request handling: clean URLs with a router that rejects malformed paths, a generic error page that never exposes file paths or credentials (details go to the log only), and the action and filter hooks plugins and themes build on.
- Database foundation: a configurable random table prefix, versioned database migrations that cannot run twice at once, and the initial tables for accounts, settings, sessions, and the audit log. Each account has a private login name separate from its public display name and URL slug.
- Project skeleton: front controller, directory layout, and web server rules that keep configuration, source, and storage directories from being downloaded.
