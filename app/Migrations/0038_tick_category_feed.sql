-- Categories now have a feed, listed on Settings > Feeds as a new kind. A site that already saved its choice of feeds would
-- otherwise start with the new kind unticked, so it is added to a saved list that does not hold it. A site that never saved
-- the choice uses the defaults, which already include it. To undo this by hand, untick Each category on Settings > Feeds.
UPDATE {prefix}settings
SET setting_value = JSON_ARRAY_APPEND(setting_value, '$', 'category')
WHERE setting_name = 'feeds.kinds'
  AND JSON_VALID(setting_value)
  AND JSON_TYPE(setting_value) = 'ARRAY'
  AND JSON_SEARCH(setting_value, 'one', 'category') IS NULL;
