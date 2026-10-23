<?php

/**
 * The Dummy Content screen under Maintenance: make placeholder content, or remove all of it.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

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
use RuntimeException;
use Throwable;

final class Screen extends AdminController
{
    public const PATH = 'maintenance/dummy-content';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly Generator $generator,
        private readonly Tracker $tracker,
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

        return $this->page($request, $user, Options::defaults(), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function generate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $input = [];

        foreach ([...array_keys(Options::FIELDS), 'seed'] as $field) {
            $input[$field] = $request->input($field);
        }

        $result = Options::fromInput($input);

        if (!$this->validToken($request, 'dummy_content_generate')) {
            return $this->page($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was made. Please check the numbers and try again.'], 400);
        }

        if ($result['options'] === null) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        try {
            $report = $this->generator->generate($result['options'], $user, $request->ip());
        } catch (RuntimeException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        } catch (Throwable $exception) {
            // The details are already in the error log; the screen only needs to say it failed.
            $this->flash->add('error', 'Making the content failed part way. Some of it may exist; remove it all and try again with smaller numbers. The error log has the details.');

            throw $exception;
        }

        $this->flash->add('success', sprintf(
            'Made %s users, %s terms, %s stories, %s chapters (%s words) and %s bookmarks in %s seconds.',
            number_format($report['users']),
            number_format($report['terms']),
            number_format($report['stories']),
            number_format($report['chapters']),
            number_format($report['words']),
            number_format($report['bookmarks']),
            (string) $report['seconds'],
        ));

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function remove(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'dummy_content_remove')) {
            $this->flash->add('error', 'Your session expired, so nothing was removed. Please try again.');
        } elseif (!$request->checked('confirm')) {
            $this->flash->add('error', 'Tick the box to confirm, so nothing is removed by accident.');
        } else {
            try {
                $removed = $this->generator->removeAll($user, $request->ip());
                $this->flash->add('success', sprintf(
                    'Removed %s users, %s terms, %s stories, %s chapters and %s bookmarks.',
                    number_format($removed['user']),
                    number_format($removed['term']),
                    number_format($removed['story']),
                    number_format($removed['chapter']),
                    number_format($removed['bookmark']),
                ));
            } catch (RuntimeException $exception) {
                $this->flash->add('error', $exception->getMessage());
            }
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, int|string> $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $this->tracker->ensureTable();

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
            'values' => $values,
            'errors' => $errors,
            'counts' => $this->tracker->counts(),
            'generateToken' => Csrf::token('dummy_content_generate'),
            'removeToken' => Csrf::token('dummy_content_remove'),
            'generateAction' => AdminUrl::to(self::PATH . '/generate'),
            'removeAction' => AdminUrl::to(self::PATH . '/remove'),
        ]);

        return $this->view->renderContent($content, $user, 'Dummy content', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
