-- Every story now needs a rating before it is published, so a site whose list of ratings is empty would be stuck.
-- This adds the four common ratings and Not Rated (an author may choose not to rate, which is still an answer), with the names and starter-list keys the AO3-style starter list uses, and only
-- when the site has no rating at all: an existing list, in whatever order and wording, is never touched.
-- To undo this by hand, delete the terms whose preset_key is rating.general, rating.teen, rating.mature, rating.explicit and rating.not_rated
-- (only while no story uses them).
INSERT INTO {prefix}terms (type, parent_id, name, name_key, slug, reviewed, story_count, created_by, created_at, description, sort_order, hidden, preset_key)
SELECT 'rating', 0, v.name, LOWER(v.name), v.slug, 1, 0, NULL, NOW(), v.description, v.sort_order, 0, v.preset_key
FROM (
    SELECT 'General Audiences' AS name, 'general-audiences' AS slug, 'Suitable for any reader. Nothing a child should not see.' AS description, 1 AS sort_order, 'rating.general' AS preset_key
    UNION ALL
    SELECT 'Teen And Up Audiences', 'teen-and-up-audiences', 'Suitable for readers of about thirteen and older: some violence, strong language or romance without detail.', 2, 'rating.teen'
    UNION ALL
    SELECT 'Mature', 'mature', 'For adult readers: stronger violence, sexual themes or other mature material, not shown in full detail.', 3, 'rating.mature'
    UNION ALL
    SELECT 'Explicit', 'explicit', 'For adult readers: graphic sexual or violent material.', 4, 'rating.explicit'
    UNION ALL
    SELECT 'Not Rated', 'not-rated', 'The author has chosen not to rate the story.', 5, 'rating.not_rated'
) v
WHERE NOT EXISTS (SELECT 1 FROM {prefix}terms WHERE type = 'rating');
