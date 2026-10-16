<?php

/**
 * The one rule for which stories a visitor may see.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use InvalidArgumentException;

/**
 * A story is public only when it is published and has at least one published chapter. Every public
 * listing, search and feed builds its query from this fragment so that no screen can invent a looser rule
 * and show a draft.
 */
final class StoryVisibility
{
    /**
     * SQL condition for a stories table aliased as $storyAlias. It contains no user data.
     */
    public static function publicCondition(string $storyAlias = 's'): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,19}\z/', $storyAlias) !== 1) {
            throw new InvalidArgumentException('Invalid table alias.');
        }

        return "({$storyAlias}.publication_state = 'published' AND EXISTS ("
            . "SELECT 1 FROM {prefix}chapters vis_c WHERE vis_c.story_id = {$storyAlias}.id AND vis_c.publication_state = 'published'))";
    }

    /**
     * SQL condition for a chapters table aliased as $chapterAlias that a visitor may read: the chapter and
     * its story must both be published.
     */
    public static function publicChapterCondition(string $chapterAlias = 'c', string $storyAlias = 's'): string
    {
        foreach ([$chapterAlias, $storyAlias] as $alias) {
            if (preg_match('/^[a-z][a-z0-9_]{0,19}\z/', $alias) !== 1) {
                throw new InvalidArgumentException('Invalid table alias.');
            }
        }

        return "({$chapterAlias}.publication_state = 'published' AND {$storyAlias}.publication_state = 'published')";
    }
}
