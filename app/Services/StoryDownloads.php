<?php

/**
 * Makes, caches and hands out the downloadable files of a story.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Download\DownloadException;
use LumoraFiction\Core\Download\EpubBuilder;
use LumoraFiction\Core\Download\HtmlBuilder;
use LumoraFiction\Core\Download\PdfBuilder;
use LumoraFiction\Core\Download\StoryDocument;
use LumoraFiction\Models\DownloadFormat;
use LumoraFiction\Models\Story;

/**
 * Whether a story may be downloaded is decided by DownloadAccess and whether it is public by the caller; this class
 * only builds. A file is named by the fingerprint of what it shows, so editing a chapter, reordering, publishing or
 * unpublishing one, or changing the story's details gives a new name and the old file is simply never asked for
 * again (and is deleted when its replacement is made).
 */
final class StoryDownloads
{
    /** How long a second reader waits for a build of the same story that is already running, in tenths of a second. */
    private const WAIT_TICKS = 300;

    public function __construct(
        private readonly Configuration $config,
        private readonly StoryDocumentFactory $documents,
        private readonly string $cacheDir,
        private readonly string $librariesDir,
    ) {
    }

    /**
     * The formats the administrator offers that this server can make.
     *
     * @return list<DownloadFormat>
     */
    public function offered(): array
    {
        return array_values(array_filter(
            DownloadSettings::formats($this->config),
            fn (DownloadFormat $format): bool => $this->canMake($format),
        ));
    }

    public function canMake(DownloadFormat $format): bool
    {
        return match ($format) {
            DownloadFormat::Html => true,
            DownloadFormat::Epub => EpubBuilder::isAvailable(),
            DownloadFormat::Pdf => PdfBuilder::isAvailable($this->librariesDir),
        };
    }

    /**
     * The file for a story in a format, built now if no up-to-date one exists.
     *
     * @return array{path: string, name: string}
     * @throws DownloadException with a message that is safe to show the reader
     */
    public function file(Story $story, DownloadFormat $format): array
    {
        if (!in_array($format, $this->offered(), true)) {
            throw new DownloadException('That format is not available.');
        }

        $document = $this->documents->make($story);
        $directory = rtrim($this->cacheDir, '/\\') . '/story-' . $story->id;
        $path = $directory . '/' . $format->value . '-' . $document->fingerprint() . '.' . $format->value;
        $result = ['path' => $path, 'name' => $document->fileStem() . '.' . $format->value];

        if (is_file($path)) {
            return $result;
        }

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }

        $lock = fopen($directory . '/.lock', 'c');

        if ($lock === false) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }

        try {
            // One build at a time for a story: a second reader waits and then finds the finished file.
            for ($tick = 0; !flock($lock, LOCK_EX | LOCK_NB); $tick++) {
                if ($tick >= self::WAIT_TICKS) {
                    throw new DownloadException('This file is being made right now. Please try again in a minute.');
                }

                usleep(100000);
            }

            if (!is_file($path)) {
                $this->build($format, $document, $path, $directory);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    /** Removes every file made for a story, when it is deleted or leaves the public archive. */
    public function forget(int $storyId): void
    {
        $directory = rtrim($this->cacheDir, '/\\') . '/story-' . $storyId;

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (glob($directory . '/.lock') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function build(DownloadFormat $format, StoryDocument $document, string $path, string $directory): void
    {
        // Built under a temporary name and renamed, so a half-written file is never served.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            match ($format) {
                DownloadFormat::Html => $this->write($temporary, (new HtmlBuilder())->build($document)),
                DownloadFormat::Epub => (new EpubBuilder())->build($document, $temporary),
                DownloadFormat::Pdf => (new PdfBuilder($this->librariesDir, rtrim($this->cacheDir, '/\\') . '/pdf-work'))->build($document, $temporary),
            };

            if (!rename($temporary, $path)) {
                throw new DownloadException('The file could not be made. Please try again later.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        // The earlier versions of this format are out of date now.
        foreach (glob($directory . '/' . $format->value . '-*.' . $format->value) ?: [] as $old) {
            if ($old !== $path) {
                unlink($old);
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }
    }
}
