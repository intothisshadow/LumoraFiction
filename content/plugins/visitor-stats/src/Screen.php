<?php

/**
 * The Visitor stats admin screen: figures, tracking switch and the country data import.
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

use LumoraFiction\Controllers\Admin\AdminController;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use PDOException;
use RuntimeException;

final class Screen extends AdminController
{
    public const PATH = 'visitor-stats';

    /** The ranges the figures can cover, in days. */
    public const RANGES = [7, 30, 90];

    private const TOKEN = 'visitor_stats';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly Configuration $config,
        private readonly Store $store,
        private readonly GeoIp $geo,
        private readonly Tracker $tracker,
        private readonly Panels $panels,
        private readonly AuditLogService $audit,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $range = (int) ($request->queryValue('range') ?? self::RANGES[1]);

        return $this->page($request, $user, in_array($range, self::RANGES, true) ? $range : self::RANGES[1]);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveSettings(array $params, Request $request): Response
    {
        return $this->act($request, 'Your session expired, so nothing was saved. Please try again.', function (User $user) use ($request): string {
            $on = $request->checked('track');
            $changed = $on !== $this->tracker->enabled();
            $this->config->setSetting(Tracker::SETTING_TRACK, $on);

            if ($on) {
                $this->tracker->ensureTables();
            }

            if ($changed) {
                $this->audit->record($user->id, 'plugin.visitor_stats.tracking', 'plugin', 'visitor-stats', ['enabled' => $on], $request->ip());
            }

            return $on ? 'Counting is on. Views from now on are counted.' : 'Counting is off. Nothing new is counted, and the figures so far are kept.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(array $params, Request $request): Response
    {
        return $this->act($request, 'Your session expired, so nothing was uploaded. Please try again.', function () use ($request): string {
            $zip = $request->upload('geo_zip');
            $blocks = $request->upload('geo_blocks');
            $locations = $request->upload('geo_locations');

            foreach ([$zip, $blocks, $locations] as $file) {
                if ($file !== null && $file->wasSent() && $file->problem() !== '') {
                    throw new RuntimeException($file->problem());
                }
            }

            if ($zip !== null && $zip->wasSent()) {
                $this->geo->stageZip($zip->path);
            } elseif ($blocks !== null && $blocks->wasSent() && $locations !== null && $locations->wasSent()) {
                $this->geo->stage($blocks->path, $locations->path);
            } else {
                throw new RuntimeException('Choose the ZIP from MaxMind, or both CSV files.');
            }

            return 'The files are ready. Continue the import below until it finishes.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function continueImport(array $params, Request $request): Response
    {
        return $this->act($request, 'Your session expired. Please press Continue again.', function () use ($request): string {
            $result = $this->geo->step();

            if (!$result['done']) {
                return 'Read ' . number_format($result['imported']) . ' ranges so far. Press Continue again.';
            }

            $this->audit->record(null, 'plugin.visitor_stats.geo_imported', 'plugin', 'visitor-stats', ['ranges' => $result['imported']], $request->ip());

            return 'Finished: ' . number_format($result['imported']) . ' ranges are loaded. Countries are counted from now on.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function cancelImport(array $params, Request $request): Response
    {
        return $this->act($request, 'Your session expired. Please try again.', function (): string {
            $this->geo->cancel();

            return 'The import was cancelled. The country data already loaded was not changed.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function removeGeo(array $params, Request $request): Response
    {
        return $this->act($request, 'Your session expired. Please try again.', function (): string {
            $this->geo->clear();

            return 'The country data was removed. Countries are no longer counted.';
        });
    }

    /**
     * Runs a POST action behind the capability and token checks, turning a refusal into a message on the screen.
     *
     * @param callable(User): string $action returns the success message
     */
    private function act(Request $request, string $expired, callable $action): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', $expired);

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        try {
            $this->flash->add('success', $action($user));
        } catch (RuntimeException $exception) {
            $this->flash->add('error', $exception->getMessage());
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    private function page(Request $request, User $user, int $range): Response
    {
        try {
            $this->tracker->ensureTables();
            $daily = $this->store->daily($range);
            $stories = $this->panels->withTitles($this->store->topStories($range, 10));
            $referrers = $this->store->breakdown(Store::BREAKDOWN_REFERRER, $range);
            $browsers = $this->store->breakdown(Store::BREAKDOWN_BROWSER, $range);
            $devices = $this->store->breakdown(Store::BREAKDOWN_DEVICE, $range);
            $countries = $this->store->breakdown(Store::BREAKDOWN_COUNTRY, $range);
            $totals = $this->store->totals();
        } catch (PDOException $exception) {
            error_log('Visitor Stats could not read its figures: ' . $exception->getMessage());
            $this->flash->add('error', 'The figures could not be read. The error log has the details.');
            $daily = $stories = $referrers = $browsers = $devices = $countries = [];
            $totals = ['today' => 0, 'week' => 0, 'month' => 0, 'all' => 0];
        }

        // Ninety daily rows is a long table to scan, so that range is summed into weeks.
        $rows = $range === 90 ? $this->weekly($daily) : $daily;
        $counts = array_column($rows, 'views');

        $content = $this->panels->render('screen', [
            'range' => $range,
            'ranges' => self::RANGES,
            'rangeUrl' => static fn (int $days): string => AdminUrl::to(self::PATH) . '?range=' . $days,
            'rows' => $rows,
            'weekly' => $range === 90,
            'peak' => max(1, ...$counts),
            'totals' => $totals,
            'stories' => $stories,
            'referrers' => $referrers,
            'browsers' => $browsers,
            'devices' => $devices,
            'countries' => array_map(static fn (array $row): array => ['value' => self::countryName($row['value'])] + $row, $countries),
            'tracking' => $this->tracker->enabled(),
            'geo' => [
                'ranges' => $this->geo->rangeCount(),
                'importedAt' => $this->geo->importedAt(),
                'stale' => $this->geo->isStale(),
                'pending' => $this->geo->pending(),
            ],
            'token' => Csrf::token(self::TOKEN),
            'actions' => [
                'settings' => AdminUrl::to(self::PATH . '/settings'),
                'upload' => AdminUrl::to(self::PATH . '/geo/upload'),
                'continue' => AdminUrl::to(self::PATH . '/geo/continue'),
                'cancel' => AdminUrl::to(self::PATH . '/geo/cancel'),
                'remove' => AdminUrl::to(self::PATH . '/geo/remove'),
            ],
        ]);

        return $this->view->renderContent($content, $user, 'Visitor stats', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param list<array{date: string, views: int}> $daily
     * @return list<array{date: string, views: int}>
     */
    private function weekly(array $daily): array
    {
        $weeks = [];

        foreach (array_chunk($daily, 7) as $chunk) {
            $weeks[] = [
                'date' => $chunk[0]['date'] . ' to ' . $chunk[count($chunk) - 1]['date'],
                'views' => array_sum(array_column($chunk, 'views')),
            ];
        }

        return $weeks;
    }

    private static function countryName(string $code): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $code, 'en') : '';

        return $name !== '' && $name !== $code && !str_starts_with($name, 'Unknown') ? $name . ' (' . $code . ')' : $code;
    }
}
