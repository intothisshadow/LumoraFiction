<?php

/**
 * Kinds of action a role may be allowed to perform.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

enum Capability: string
{
    case AccessAdmin = 'access_admin';
    case ManageSite = 'manage_site';
    case ManageUsers = 'manage_users';
    case ModerateStories = 'moderate_stories';
    case EditAnyStory = 'edit_any_story';
    case WriteStories = 'write_stories';
    case PublishWithoutReview = 'publish_without_review';
    case Bookmark = 'bookmark';
    case PublishNews = 'publish_news';
}
