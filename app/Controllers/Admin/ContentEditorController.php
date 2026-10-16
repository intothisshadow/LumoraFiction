<?php

/**
 * The two requests the chapter editor makes while a writer works: show a preview, and change editing mode.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\ContentEditor;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ContentRenderer;

/**
 * These are script requests, so they answer in JSON and never redirect. A form token is spent when it is
 * checked, so every answer carries a fresh one for the next request. Nothing is stored here: the preview
 * and the conversion only show the writer what the text would become.
 */
final class ContentEditorController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ContentRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $checked = $this->check($request);

        if ($checked instanceof Response) {
            return $checked;
        }

        $format = ContentFormat::tryFrom((string) $request->input('format'));
        $content = $request->input('content');

        if ($format === null || $content === null) {
            return $this->json(['ok' => false, 'message' => 'The preview could not be made.'], 422);
        }

        return $this->json(['ok' => true, 'html' => $this->renderer->render($content, $format)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function convert(array $params, Request $request): Response
    {
        $checked = $this->check($request);

        if ($checked instanceof Response) {
            return $checked;
        }

        $from = ContentFormat::tryFrom((string) $request->input('from'));
        $to = ContentFormat::tryFrom((string) $request->input('to'));
        $content = $request->input('content');

        if ($from === null || $to === null || $content === null) {
            return $this->json(['ok' => false, 'message' => 'The text could not be converted.'], 422);
        }

        return $this->json(['ok' => true, 'content' => $this->renderer->convertFormat($content, $from, $to)]);
    }

    /**
     * Returns null when the request may go ahead, or the response that refuses it.
     */
    private function check(Request $request): ?Response
    {
        if (!$request->isPost()) {
            return $this->json(['ok' => false, 'message' => 'Not allowed.'], 405);
        }

        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->json(['ok' => false, 'message' => 'Your session has ended. Sign in again, then copy your text first so nothing is lost.'], 401);
        }

        if (!$user->can(Capability::WriteStories)) {
            return $this->json(['ok' => false, 'message' => 'You may not do that.'], 403);
        }

        if (!Csrf::verify(ContentEditor::TOKEN_ACTION, $request->input('csrf_token'), $request->cookies)) {
            return $this->json(['ok' => false, 'message' => 'The page has been open too long. Reload it, copying your text first so nothing is lost.'], 400);
        }

        // A very long text is refused up front rather than parsed, which keeps this endpoint cheap to call.
        if (mb_strlen((string) $request->input('content'), 'UTF-8') > ChapterService::CONTENT_MAX) {
            return $this->json(['ok' => false, 'message' => 'This text is too long to preview or convert in one piece.'], 413);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): Response
    {
        // A token is only offered to someone who has a session to use it in.
        if ($status !== 401 && $status !== 405) {
            $data['token'] = Csrf::token(ContentEditor::TOKEN_ACTION);
        }

        return new Response(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }
}
