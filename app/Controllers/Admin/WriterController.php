<?php

/**
 * Shared behaviour of the writer screens: the permission check, the form token, and what happens to a
 * writer's text when their session has ended.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

abstract class WriterController extends AdminController
{
    protected const TOKEN_ACTION = 'admin_writer';

    protected const STALE_FORM = 'Your session expired, so nothing was saved. Please check the form and send it again.';

    /** Fields whose text is shown back to a writer whose session ended, with the name the page gives each. */
    private const KEPT_LABELS = [
        'title' => 'Title',
        'summary' => 'Summary',
        'notes' => 'Notes',
        'disclaimer' => 'Disclaimer',
        'content' => 'Text',
    ];

    /**
     * Returns the signed-in writer, or the response that refuses them. A guest who posts a form is not sent
     * to the sign-in page, which would throw the text away: they get the page that keeps it.
     *
     * @param list<string> $keep names of the posted fields to hand back if the session has ended
     */
    protected function writer(Request $request, string $currentPath, string $returnPath = '', array $keep = []): User|Response
    {
        if ($request->isPost() && $keep !== [] && $this->auth->user($request->cookies) === null) {
            return $this->sessionEnded($request, $returnPath, $keep);
        }

        return $this->guard($request, Capability::WriteStories, $currentPath);
    }

    /**
     * Story and chapter addresses carry a whole number. Anything else, however it is spelled, is simply not found.
     *
     * @param array<string, string> $params
     */
    protected function idFrom(array $params): int
    {
        $id = $params['id'] ?? '';

        return preg_match('/^[1-9][0-9]{0,9}\z/', $id) === 1 ? (int) $id : 0;
    }

    /**
     * @param array<string, string> $errors
     */
    protected function errorSummary(array $errors): string
    {
        return $this->view->fragment('error-summary', ['errors' => $errors]);
    }

    protected function missing(string $message, string $path = 'stories'): Response
    {
        $this->flash->add('error', $message);

        return Response::redirect(AdminUrl::to($path), 303);
    }

    /**
     * @param list<string> $keep
     */
    private function sessionEnded(Request $request, string $returnPath, array $keep): Response
    {
        $texts = [];
        $hidden = [];

        foreach ($keep as $name) {
            $single = $request->input($name);

            if ($single !== null) {
                if (isset(self::KEPT_LABELS[$name])) {
                    $texts[$name] = ['label' => self::KEPT_LABELS[$name], 'value' => $single];
                } else {
                    $hidden[] = ['name' => $name, 'value' => $single];
                }

                continue;
            }

            foreach ($request->inputList($name) as $value) {
                $hidden[] = ['name' => $name . '[]', 'value' => $value];
            }
        }

        // The form goes back to the same screen without a token. Once the writer has signed in again that
        // screen finds the token missing and shows the form again with this text, ready to send.
        return $this->view->renderBare('session-ended', [
            'texts' => $texts,
            'hidden' => $hidden,
            'action' => AdminUrl::to($returnPath),
            'loginUrl' => AdminUrl::to('login'),
        ], 'Your session has ended', 401);
    }
}
