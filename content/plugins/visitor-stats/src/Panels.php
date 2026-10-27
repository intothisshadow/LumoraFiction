<?php

/**
 * The small pieces of markup the plugin adds to core screens: a dashboard card and a panel on the story screen.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\VisitorStats;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use PDOException;

final class Panels
{
    /** The window the dashboard's most read list covers, in days. */
    public const TOP_DAYS = 7;

    private const TOP_COUNT = 5;

    public function __construct(
        private readonly Store $store,
        private readonly Database $database,
        private readonly Tracker $tracker,
    ) {
    }

    /**
     * @return array{title: string, html: string}|null
     */
    public function dashboardCard(User $user): ?array
    {
        if (!$user->can(Capability::ManageSite)) {
            return null;
        }

        try {
            $this->tracker->ensureTables();
            $totals = $this->store->totals();
            $top = $this->withTitles($this->store->topStories(self::TOP_DAYS, self::TOP_COUNT));
        } catch (PDOException $exception) {
            error_log('Visitor Stats could not read its totals: ' . $exception->getMessage());

            return null;
        }

        return ['title' => 'Visitor stats', 'html' => $this->render('dashboard-card', [
            'totals' => $totals,
            'top' => $top,
            'days' => self::TOP_DAYS,
            'tracking' => $this->tracker->enabled(),
            'screenUrl' => AdminUrl::to(Screen::PATH),
        ])];
    }

    /**
     * Totals for one story, for the Author who wrote it and for staff who may see every story. A person who can edit
     * the story only because an Administrator allowed it still does not see numbers unless they own it or moderate.
     *
     * @return array{title: string, html: string}|null
     */
    public function storyPanel(Story $story, User $user): ?array
    {
        if ($story->ownerId !== $user->id && !$user->can(Capability::ModerateStories)) {
            return null;
        }

        try {
            $this->tracker->ensureTables();
            $totals = $this->store->storyTotals([$story->id])[$story->id] ?? ['week' => 0, 'month' => 0, 'all' => 0];
        } catch (PDOException $exception) {
            error_log('Visitor Stats could not read a story\'s totals: ' . $exception->getMessage());

            return null;
        }

        return ['title' => 'Views', 'html' => $this->render('story-panel', ['totals' => $totals, 'tracking' => $this->tracker->enabled()])];
    }

    /**
     * @param list<array{story_id: int, views: int}> $rows
     * @return list<array{story_id: int, views: int, title: string, url: string}>
     */
    public function withTitles(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_column($rows, 'story_id');
        $found = $this->database->fetchAll(
            'SELECT id, title FROM {prefix}stories WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );
        $titles = array_column($found, 'title', 'id');

        $listed = [];

        foreach ($rows as $row) {
            // A story deleted since it was read has no title left, so it is not listed.
            if (isset($titles[$row['story_id']])) {
                $listed[] = $row + ['title' => (string) $titles[$row['story_id']], 'url' => AdminUrl::to('stories/' . $row['story_id'])];
            }
        }

        return $listed;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data): string
    {
        return View::render(dirname(__DIR__) . '/views/' . $view . '.php', $data);
    }
}
