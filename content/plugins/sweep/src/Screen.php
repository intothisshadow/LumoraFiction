<?php

/**
 * The Sweep screen under Maintenance: what can be cleaned and how much, a preview, and the cleanup itself.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\Sweep;

use LumoraFiction\Controllers\Admin\AdminController;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use Throwable;

final class Screen extends AdminController
{
    public const PATH = 'maintenance/sweep';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly Sweeper $sweeper,
        private readonly SweepSettings $settings,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        return $user instanceof Response ? $user : $this->page($request, $user, 'overview', [], $this->settings->current(), []);
    }

    /**
     * Shows exactly what the chosen kinds would remove, and removes nothing.
     *
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'sweep_preview')) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        $tasks = $this->chosen($request);

        if ($tasks === []) {
            $this->flash->add('error', 'Tick at least one kind of item to look at.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        return $this->page($request, $user, 'preview', $tasks, $this->settings->current(), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function run(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $tasks = $this->chosen($request);

        if (!$this->validToken($request, 'sweep_run')) {
            $this->flash->add('error', 'Your session expired, so nothing was removed. Please try again.');
        } elseif ($tasks === []) {
            $this->flash->add('error', 'Nothing was chosen, so nothing was removed.');
        } elseif (!$request->checked('confirm')) {
            $this->flash->add('error', 'Tick the box to confirm, so nothing is removed by accident.');
        } else {
            try {
                $removed = $this->sweeper->run($tasks, $user, $request->ip());
                $this->flash->add('success', 'Removed ' . implode(', ', array_map(
                    static fn (string $task, int $count): string => number_format($count) . ' ' . strtolower(Sweeper::TASKS[$task]['label']),
                    array_keys($removed),
                    $removed,
                )) . '.');
            } catch (Throwable $exception) {
                // The details go to the error log; the screen only says it stopped.
                $this->flash->add('error', 'The cleanup stopped part way. What was removed stays removed; look again and run it once more. The error log has the details.');

                throw $exception;
            }
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveSettings(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $result = $this->settings->validate([
            'unconfirmed_days' => $request->input('unconfirmed_days'),
            'reminder_threshold' => $request->input('reminder_threshold'),
        ]);

        if (!$this->validToken($request, 'sweep_settings')) {
            return $this->page($request, $user, 'overview', [], $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, 'overview', [], $result['values'], $result['errors'], 422);
        }

        $changed = $this->settings->save($result['values'], $user, $request->ip());
        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @return list<string> the ticked kinds, in the order the screen lists them, without anything unknown
     */
    private function chosen(Request $request): array
    {
        $ticked = $request->inputList('tasks');

        return array_values(array_filter(array_keys(Sweeper::TASKS), static fn (string $task): bool => in_array($task, $ticked, true)));
    }

    /**
     * @param list<string> $tasks
     * @param array{unconfirmed_days: int, reminder_threshold: int} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, string $step, array $tasks, array $values, array $errors, int $status = 200): Response
    {
        $counts = $this->sweeper->counts();
        $samples = [];

        foreach ($tasks as $task) {
            $samples[$task] = $this->sweeper->samples($task);
        }

        $content = (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            try {
                require $__file;
            } catch (Throwable $exception) {
                ob_end_clean();

                throw $exception;
            }

            return (string) ob_get_clean();
        })(dirname(__DIR__) . '/views/screen.php', [
            'step' => $step,
            'tasks' => $tasks,
            'definitions' => Sweeper::TASKS,
            'counts' => $counts,
            'samples' => $samples,
            'values' => $values,
            'errors' => $errors,
            'maxDays' => SweepSettings::MAX_UNCONFIRMED_DAYS,
            'maxThreshold' => SweepSettings::MAX_REMINDER_THRESHOLD,
            'previewToken' => Csrf::token('sweep_preview'),
            'runToken' => Csrf::token('sweep_run'),
            'settingsToken' => Csrf::token('sweep_settings'),
            'previewAction' => AdminUrl::to(self::PATH . '/preview'),
            'runAction' => AdminUrl::to(self::PATH . '/run'),
            'settingsAction' => AdminUrl::to(self::PATH . '/settings'),
            'backUrl' => AdminUrl::to(self::PATH),
            'retentionUrl' => AdminUrl::to('settings/retention'),
        ]);

        return $this->view->renderContent($content, $user, 'Sweep', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
