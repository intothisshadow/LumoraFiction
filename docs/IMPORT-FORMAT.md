# What the story import reads

**Stories > Import** reads the HTML download of a work from a site that runs the OTW's open-source archive software (Archive of Our Own, Squidgeworld and others). Every such site builds the file from the same templates, so the importer looks for the template's own parts and not for the name of the site. This page lists what is read, so an administrator can tell what will and will not come across.

## The file

- The **HTML** and **EPUB** downloads are read. PDF and MOBI are not, and neither is a work that cannot be downloaded. An EPUB is read from its pages in reading order (the converter that makes it splits the preface and each chapter over several pages and renames the classes, so the importer relies on the ids and the chapter headings, which it keeps) and gives the same result as the HTML of the same work (the converter also cuts a chapter's text at the headings inside it, so all the text blocks up to the next chapter heading are joined). An EPUB inside a ZIP is not opened: upload it on its own.
- The file must be UTF-8 text (or declare a Western charset such as ISO-8859-1, which is converted). Up to 20 files at once, each up to 5 MB (an EPUB up to 20 MB), or a ZIP of up to 100 entries and 20 MB. A ZIP is read in memory entry by entry: a name that could point outside it, a link, and an archive inside an archive are refused.
- The labels in the tag list are the English ones the downloads print. A file in another language is refused rather than guessed at.

## What is read

| In the file | Becomes |
| --- | --- |
| Title | The story title (shortened to the site's limit). |
| Byline | Only compared with the person's display name and the names they gave, for a warning. It is not stored. |
| Summary, Notes | The story's summary and notes, as plain text with line breaks. |
| End notes (the afterword) | The story's end notes. |
| Rating | One of this site's ratings, chosen by the writer. Matched by name or alias, then by how suitable it is for younger readers. |
| Archive Warning, Category | This site's warnings and relationship categories, matched by name or alias and chosen by the writer. Lists the site does not have are not filled in. |
| Fandom, Relationship, Character, Additional Tags | Fandoms, relationships, characters and tags, through the classification rules. |
| Language | The story language when the name is recognised, in English or in the language itself ("Suomi", "Deutsch"). |
| Series (Part N of …) | A series, only when the writer asks for it. |
| Collections | Offered as a choice of the writer's own collections; nothing is added to someone else's. |
| Stats: Published, Updated or Completed, Words, Chapters | The dates, the work status (complete when the file says Completed or all chapters are present) and nothing else. Word counts are worked out again. |
| The address the file says it was posted at | Kept to notice a second import of the same work, and added to the story's notes only when the writer ticks it. |
| Each chapter's title, summary, notes, text and end notes | A chapter, in order. A work of one chapter has no chapter title. Chapter text goes through the same cleaner as the editor. |

## What is not read

Work skins and custom CSS, hit counts, kudos, comments, bookmarks, related works, translations, images (they are removed from the text when they are embedded items) and anything that needs the other site. Scripts, styles, embedded items and inline styles in the text are removed, and the report counts them.
