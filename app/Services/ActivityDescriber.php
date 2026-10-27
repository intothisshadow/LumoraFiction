<?php

/**
 * Turns an activity-log entry into a plain sentence for the dashboard.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

/**
 * The log keeps machine names so it stays searchable; this is only how a person reads them. The result is plain text
 * and must be escaped by the view. An action nobody wrote a sentence for still gets a readable one, so a new feature
 * never shows a raw code on the dashboard.
 */
final class ActivityDescriber
{
    /** Longest quoted title shown, so one long name cannot fill a line. */
    private const TITLE_MAX = 60;

    /** @var array<string, string> action => what the person did, for the actions that need no detail */
    private const PHRASES = [
        'account.display_name.change' => 'changed their display name',
        'account.downloads_default' => 'changed whether readers may download their stories',
        'account.email.change' => 'changed their email address',
        'account.password.change' => 'changed their password',
        'alert.dismiss' => 'dismissed an alert',
        'alert.email_setting' => 'changed the alert email setting',
        'appearance.theme.delete' => 'deleted a theme',
        'appearance.theme.install' => 'added a theme',
        'appearance.theme.update' => 'updated a theme',
        'author.about' => 'changed their author information',
        'author.about.clear' => 'cleared an author\'s information',
        'author.avatar' => 'changed an author picture',
        'backup.deleted' => 'deleted a backup',
        'backup.downloaded' => 'downloaded a backup',
        'backup.restore_started' => 'started restoring a backup',
        'backup.started' => 'started a backup',
        'chapter.create' => 'added a chapter',
        'chapter.delete' => 'deleted a chapter',
        'chapter.publish' => 'published a chapter',
        'chapter.purge' => 'deleted a chapter from the trash',
        'chapter.reorder' => 'reordered the chapters of a story',
        'chapter.restore' => 'restored a chapter from the trash',
        'chapter.revision_restore' => 'put back an earlier version of a chapter',
        'chapter.trash' => 'moved a chapter to the trash',
        'chapter.unpublish' => 'unpublished a chapter',
        'chapter.update' => 'edited a chapter',
        'dummy_content.generated' => 'made placeholder content',
        'dummy_content.removed' => 'removed placeholder content',
        'plugin.load_failed' => 'could not start a plugin',
        'plugin.visitor_stats.geo_imported' => 'loaded the country data for Visitor Stats',
        'plugin.visitor_stats.tracking' => 'changed whether Visitor Stats counts views',
        'preset.apply' => 'added a classification starter list',
        'security.registration_paused' => 'paused registration because of a burst of sign-ups',
        'security.registration_resumed' => 'turned registration back on',
        'settings.theme.activate' => 'changed the active theme',
        'settings.theme_options.update' => 'changed the theme options',
        'story.approve' => 'approved a story',
        'story.classify' => 'changed the classification of a story',
        'story.cover' => 'changed a story cover',
        'story.create' => 'started a story',
        'story.delete' => 'deleted a story',
        'story.downloads' => 'changed whether a story can be downloaded',
        'story.duplicate' => 'made a copy of a story',
        'story.publish' => 'published a story',
        'story.purge' => 'deleted a story from the trash',
        'story.reject' => 'sent a story back to its author',
        'story.restore' => 'restored a story from the trash',
        'story.submit' => 'sent a story for approval',
        'story.trash' => 'moved a story to the trash',
        'story.unpublish' => 'unpublished a story',
        'story.update' => 'edited a story',
        'story.work_status' => 'changed where a story stands',
        'news.create' => 'wrote a news post',
        'news.delete' => 'deleted a news post',
        'news.publish' => 'published a news post',
        'news.unpublish' => 'unpublished a news post',
        'news.update' => 'edited a news post',
        'term.alias_add' => 'added another name for a classification term',
        'term.alias_remove' => 'removed another name for a classification term',
        'term.create' => 'added a classification term',
        'term.delete' => 'deleted a classification term',
        'term.describe' => 'described a classification term',
        'term.hide' => 'hid or showed a classification term',
        'term.merge' => 'merged classification terms',
        'term.move' => 'moved a classification term',
        'term.propose' => 'suggested a classification term',
        'term.rename' => 'renamed a classification term',
        'term.review' => 'approved a classification term',
        'update.cancel_requested' => 'asked to cancel the update',
        'user.publishing_override' => 'changed whether someone\'s stories need approval',
        'user.reinstate' => 'reinstated an account',
        'user.role.change' => 'changed someone\'s role',
        'user.suspend' => 'suspended an account',
    ];

    /** @var array<string, string> the part of settings.{name}.update => how the screen is called */
    private const SETTINGS = [
        'archive' => 'archive',
        'downloads' => 'download',
        'editor' => 'editor',
        'front_page' => 'front page',
        'maintenance' => 'maintenance mode',
        'news' => 'news',
        'retention' => 'retention',
        'security' => 'security',
        'spam' => 'spam protection',
        'taxonomy' => 'classification',
        'updates' => 'update',
    ];

    /** What an update, backup or restore run did, by its result. */
    private const RUN_RESULTS = [
        'success' => ['update' => 'updated the site', 'backup' => 'made a backup', 'restore' => 'restored the site from a backup'],
        'rolled_back' => ['update' => 'tried to update the site, which was put back as it was', 'restore' => 'tried to restore a backup, which was put back as it was', 'backup' => 'tried to make a backup, which failed'],
        'cancelled' => ['update' => 'cancelled an update', 'backup' => 'cancelled a backup', 'restore' => 'cancelled a restore'],
        'failed' => ['update' => 'tried to update the site, which failed', 'backup' => 'tried to make a backup, which failed', 'restore' => 'tried to restore a backup, which failed'],
    ];

    /**
     * What the person did, to follow their name: "published a story “Winter”".
     *
     * @param array{action: string, target_type: ?string, target_id: ?string, details: array<string, mixed>} $entry
     */
    public function describe(array $entry): string
    {
        $action = $entry['action'];
        $details = $entry['details'];

        if (preg_match('/^settings\.([a-z_]+)\.update\z/', $action, $found) === 1 && isset(self::SETTINGS[$found[1]])) {
            return 'changed the ' . self::SETTINGS[$found[1]] . ' settings';
        }

        if (preg_match('/^(update|backup|restore)\.(success|rolled_back|cancelled|failed)\z/', $action, $found) === 1) {
            return self::RUN_RESULTS[$found[2]][$found[1]] ?? 'ran ' . $found[1];
        }

        if ($action === 'plugin.activate' || $action === 'plugin.deactivate') {
            $name = $this->name($entry['target_id'] ?? '');

            $verb = $action === 'plugin.activate' ? 'turned on' : 'turned off';

            return $name === '' ? $verb . ' a plugin' : $verb . ' the ' . $name . ' plugin';
        }

        $text = self::PHRASES[$action] ?? 'made a change (' . str_replace(['.', '_'], ' ', $action) . ')';
        $title = isset($details['title']) && is_string($details['title']) ? trim($details['title']) : '';

        return $title === '' ? $text : $text . ' “' . mb_strimwidth($title, 0, self::TITLE_MAX, '…', 'UTF-8') . '”';
    }

    /** A plugin's folder name as words: "visitor-stats" becomes "Visitor Stats". */
    private function name(string $slug): string
    {
        return ucwords(trim(str_replace(['-', '_'], ' ', $slug)));
    }
}
