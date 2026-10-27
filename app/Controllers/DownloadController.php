<?php

/**
 * The address a story's downloadable file is fetched from.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Download\DownloadException;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\DownloadFormat;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\DownloadAccess;
use LumoraFiction\Services\DownloadCounter;
use LumoraFiction\Services\DownloadSettings;
use LumoraFiction\Services\StoryDownloads;
use LumoraFiction\Services\StoryService;

/**
 * Anything that should not be downloaded is answered with the ordinary not-found page, whatever the reason, so the
 * response never says whether a draft, a refused story or a switched-off feature is behind the address.
 */
final class DownloadController
{
    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly StoryService $stories,
        private readonly DownloadAccess $access,
        private readonly StoryDownloads $downloads,
        private readonly DownloadCounter $counter,
        private readonly AuthService $auth,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function download(array $params, Request $request): Response
    {
        $format = DownloadFormat::tryFrom($params['format'] ?? '');

        // The same rule as reading: an archive closed to visitors who are not signed in does not hand out files either.
        if ($this->config->setting('reading.require_signin') === true && !$this->auth->check()) {
            return Response::redirect(PublicUrl::account('sign-in'), 302)->withHeader('Cache-Control', 'private, no-store');
        }

        // Only a published story with a published chapter is found, so a draft, a pending or unpublished story and a
        // trashed one are all missing here.
        $story = $this->stories->findPublicBySlug($params['slug'] ?? '');

        if ($format === null || $story === null || !$this->access->allows($story) || !in_array($format, $this->downloads->offered(), true)) {
            return $this->themes->notFound();
        }

        try {
            $file = $this->downloads->file($story, $format);
        } catch (DownloadException $exception) {
            return $this->unavailable($exception->getMessage(), PublicUrl::story($story->slug));
        }

        if (DownloadSettings::counting($this->config)) {
            $this->counter->record($story->id, $format);
        }

        // A file for a signed-in reader is never kept by a shared cache. A guest's is the same for everyone, but is
        // still revalidated, so unpublishing a story takes effect at once.
        return Response::file($file['path'], $file['name'], $format->mimeType())
            ->withHeader('Cache-Control', $this->auth->check() ? 'private, no-store' : 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    /**
     * Explains that a file could not be made, in the site's own theme and with a way back to the story.
     */
    private function unavailable(string $message, string $storyUrl): Response
    {
        return $this->themes->render('account', [
            'intro' => $message,
            'notices' => [],
            'forms' => [],
            'links' => [['label' => 'Back to the story', 'url' => $storyUrl]],
            'shortcuts' => [],
        ], 422, ['title' => 'Download not available', 'noindex' => true, 'variant' => 'download'])
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
