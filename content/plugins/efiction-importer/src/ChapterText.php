<?php

/**
 * Finds the text of one eFiction chapter, in the database or in the stories folder, and repairs its character set.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

final class ChapterText
{
    private const FILE_BYTES_MAX = 4_000_000;

    private readonly ?string $root;

    /**
     * @param string $store the site's own setting: "files" or "db", which only decides what is tried first
     */
    public function __construct(
        string $storiesFolder,
        private readonly string $store,
        private readonly string $charset = 'Windows-1252',
    ) {
        $real = $storiesFolder === '' ? false : realpath($storiesFolder);
        $this->root = $real === false || !is_dir($real) ? null : $real;
    }

    /**
     * @param array<string, mixed> $chapter a chapters row: chapid, uid and storytext
     * @return array{text: string, from: string, converted: bool} from is "database", "file" or "none"
     */
    public function find(array $chapter): array
    {
        $order = strtolower($this->store) === 'db' ? ['database', 'file'] : ['file', 'database'];

        foreach ($order as $source) {
            $raw = $source === 'file' ? $this->fromFile((int) ($chapter['uid'] ?? 0), (int) ($chapter['chapid'] ?? 0)) : (string) ($chapter['storytext'] ?? '');

            if (trim($raw) === '') {
                continue;
            }

            [$text, $converted] = Text::utf8($raw, $this->charset);

            if (trim($text) !== '') {
                return ['text' => $text, 'from' => $source, 'converted' => $converted];
            }
        }

        return ['text' => '', 'from' => 'none', 'converted' => false];
    }

    /**
     * The path is built from two whole numbers only and must resolve to a file inside the stories folder, so nothing in the
     * database can point the reader somewhere else. A chapter with no author number gets no guessed path.
     */
    private function fromFile(int $uid, int $chapterId): string
    {
        if ($this->root === null || $uid <= 0 || $chapterId <= 0) {
            return '';
        }

        $real = realpath($this->root . DIRECTORY_SEPARATOR . $uid . DIRECTORY_SEPARATOR . $chapterId . '.txt');

        if ($real === false || !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR) || !is_file($real) || !is_readable($real)) {
            return '';
        }

        $text = file_get_contents($real, false, null, 0, self::FILE_BYTES_MAX);

        return $text === false ? '' : $text;
    }
}
