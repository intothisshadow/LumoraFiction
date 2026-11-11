# Export format

An export from **Stories > Export** is a ZIP file. This page describes its contents, so the stories can be read by other programs and, later, imported. The format has a version number, `format_version`, now `1`. A field is only ever added in a version that keeps the old meaning of the others. When a field is renamed or removed the number is raised, and a program reading the file should refuse a version it does not know.

## What is in the ZIP

| Path | Contents |
| ---- | -------- |
| `manifest.json` | What the export is, and an index of every story in it. |
| `stories/{id}-{slug}.json` | One file per story, with its chapters. |
| `covers/{id}-{file}` | The story's cover picture, only when the export was made with covers. |

All JSON is UTF-8. Dates are ISO 8601 with a time zone (`2026-11-11T10:15:00+00:00`). A value that is not set is `null`; text that is empty is an empty string.

## `manifest.json`

| Key | Meaning |
| --- | ------- |
| `format` | Always `lumora-fiction-export`. |
| `format_version` | The format version, a whole number. |
| `exported_at`, `app_version`, `site_name` | When, by which version of Lumora Fiction, and from which site. No address or other install detail is included. |
| `scope` | `story`, `author` or `archive`. |
| `counts` | `stories`, `chapters`, `series`, `collections` and `covers`. |
| `authors` | The authors of the exported stories: `{display_name, slug}`. |
| `stories` | One entry per story: `{id, slug, title, author, file, chapters, words, publication_state}`. `author` is the author's `slug` and `file` is the path of the story's JSON file. |
| `series` | The series of the exported stories: `{slug, title, summary, work_status, author, stories}`, where `stories` lists the story slugs in order. |
| `collections` | The collections the exported stories are in: `{slug, title, description, visibility, curator, stories}`, where `stories` lists `{story, state, added_by}`. `state` is `approved` or `pending`. |

A series or collection lists only the exported stories that belong to it, so a single story's export never names other people's stories.

## A story file

| Key | Meaning |
| --- | ------- |
| `format`, `format_version` | `lumora-fiction-story` and the format version. |
| `id`, `slug` | The story's number and address on the site it came from. They identify the story across exports; a different site will make its own. |
| `title`, `summary`, `notes`, `end_notes`, `disclaimer` | Plain text. `notes` is the author's note above the story and `end_notes` the closing note. |
| `author` | `{display_name, slug}`. |
| `language` | A language code such as `en`. |
| `work_status` | `ongoing`, `completed`, `on_hiatus` or `abandoned`. |
| `publication_state` | `draft`, `pending`, `published` or `unpublished`. |
| `word_count` | Words over every chapter. |
| `created_at`, `updated_at`, `published_at` | Dates. `published_at` is `null` until the story was first published. |
| `cover` | `null`, or `{file, alt}`. `file` is a path inside the ZIP, or `null` when the export was made without covers; `alt` is the description. |
| `terms` | The classification, by kind: `fandom`, `character`, `relationship`, `genre`, `tag`, `category`, `rating`, `warning` and `relationship_category`. A kind the story has nothing in is left out. Each term is `{name, slug}`, and a character or relationship that belongs to a fandom also has `fandom`, that fandom's name. A story has at most one rating. |
| `series` | `null`, or `{slug, title, position}`, where `position` counts from 1. |
| `collections` | A list of `{slug, title, state, added_by, note}`. `added_by` is the public slug of the person who put it there. |
| `chapters` | The chapters in reading order. |

A chapter is `{number, title, format, content, notes, end_notes, word_count, publication_state, created_at, updated_at, published_at}`. Numbers run from 1 without gaps, and the list is in that order. `content` is the text exactly as it was stored and `format` says how to read it: `html`, `markdown` or `plain`. HTML has already been cleaned by the site, but a program that shows it should clean it again.

## What is never in an export

No login name, email address, password hash, token or setting of the site. Authors, curators and the people who added stories to collections appear only by display name and public slug. Stories in the trash, and chapters in the trash, are not exported. Comments, bookmarks and reading progress belong to readers and are not part of a story's export.

## Who can export what

An Author exports their own stories, drafts included. An Administrator can also export any author's stories and the whole archive. A story's owner, or anyone allowed to edit any story, can export that story. An export is made for one request, sent, and removed from the server.
