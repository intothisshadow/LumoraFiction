# Settings file format

**Settings > Export and import** saves and reads a JSON file with the settings that mean the same on any site. This page describes the file, so a person or a tool can read or write one.

## Shape

```json
{
    "format": "lumora-fiction-settings",
    "version": 1,
    "exported_at": "2026-11-09T12:00:00Z",
    "app_version": "1.4.0",
    "groups": {
        "archive": { "archive_mode": "both", "registration_enabled": true },
        "news": { "enabled": true, "home_count": 3 }
    }
}
```

- `format` must be `lumora-fiction-settings` and `version` must be the whole number `1`. A file with any other format or version is refused, because a version this site does not know could mean something different. `exported_at` and `app_version` are for people and are ignored.
- `groups` holds named groups of settings. A file may carry only some groups, and a group only some of its settings; whatever is left out keeps its value on the site that reads the file.
- Everything is checked before anything is written. A group or setting this version does not know, a value of the wrong kind (a text where a number or a true/false belongs), or a value the matching settings screen would refuse or quietly change, makes the whole file refused. The file is at most 64 KB.

## Groups

| Group | Settings (kind) |
| ----- | --------------- |
| `archive` | `archive_mode` (text: `both`, `fanfiction`, `original`), `registration_enabled`, `email_verification`, `hold_first_story`, `require_signin`, `writer_tags` (true/false), `default_role` (text: `reader` or `author`) |
| `security` | `lockout_attempts` (3 to 20), `lockout_minutes` (1 to 1440), `password_min_length` (8 to 64), `session_hours` (1 to 720) (whole numbers), `lockout_progressive`, `notify_owner` (true/false) |
| `privacy` | `deleted_stories` (text: `keep` or `delete`), `neutral_name` (text) |
| `comments` | `enabled`, `author_toggle`, `replies` (true/false), `trust` (text), `edit_minutes`, `report_threshold` (whole numbers) |
| `downloads` | `enabled`, `count`, `show_counts` (true/false), `formats` (list of format names) |
| `feeds` | `kinds` (list of feed kinds), `json`, `full_text` (true/false), `limit` (5 to 50) |
| `classification` | `labels` (a map of the site's own names for the kinds of list: `{kind}_singular` and `{kind}_plural`, such as `relationship_singular` and `relationship_plural`; the kind is the fixed name, never the shown word, and a kind has both or neither) |
| `news` | `enabled` (true/false), `home_count` (0 to 20) |
| `front_page` | `published_count`, `updated_count` (whole numbers) |
| `editor` | `default_mode` (text), `lock_to_default` (true/false) |
| `retention` | `trash_days`, `revisions` (whole numbers) |
| `search` | `discourage` (true/false) |
| `theme` | `active` (text: the folder name of an installed theme), `visitor_choice` (true/false), `visitor_themes` (list of installed theme folder names), `options` (the options of the theme in use, by name) |

An empty `active` in the `theme` group means "leave the theme as it is". `options` are only applied when `active` is the theme the site already uses, because only that theme's own rules can check them; otherwise they are skipped and the preview says so.

## What is never in the file

The file has no place for anything that belongs to one install or is secret, so none of it can leak through an export or be written by an import: the site address, the administrator's email address and every account, the secret key, the table prefix and the database details, the list of trusted proxies, the HTTPS-only header, the page chosen as the privacy policy (its number is this site's), the anonymous install ping switch and install ID (an import must never turn tracking on for another site or copy one site's ID onto another), the spam keys and answers, the page cache settings (they describe the server), and the state of updates and maintenance.
