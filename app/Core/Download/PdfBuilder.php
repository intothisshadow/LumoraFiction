<?php

/**
 * The PDF download, made with the bundled Dompdf library from the same cleaned chapter HTML the reader sees.
 *
 * @package LumoraFiction
 * @subpackage Download
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Download;

use Dompdf\Dompdf;
use Dompdf\Options;
use LumoraFiction\Helpers\Html;
use Throwable;

final class PdfBuilder
{
    /**
     * Past this a PDF takes more time and memory than a shared host gives a request: a 150,000 word story needed
     * about 15 seconds and 120 MB while building. The reader is pointed to the EPUB instead.
     */
    public const MAX_WORDS = 100000;

    /** Memory a build needs before any text, and for each word, measured on stories of 3,000 to 150,000 words. */
    private const BASE_MEMORY = 40 * 1048576;

    private const BYTES_PER_WORD = 900;

    private const TIME_LIMIT = 120;

    private const FONT = 'DejaVu Serif';

    private const STYLE = <<<'CSS'
@page { margin: 16mm 15mm 18mm 15mm; }
body { font-family: "DejaVu Serif", serif; font-size: 10.5pt; line-height: 1.5; color: #111; }
h1 { font-size: 20pt; line-height: 1.2; margin: 0 0 4pt; }
h2 { font-size: 15pt; line-height: 1.25; margin: 0 0 10pt; }
.title-page { text-align: center; padding-top: 25mm; }
.byline { font-style: italic; margin: 0 0 16pt; }
.details { text-align: left; margin: 0 0 14pt; }
.details p { margin: 0 0 4pt; }
.chapter { page-break-before: always; }
.notes { margin: 0 0 12pt; padding: 2pt 8pt; border-left: 2pt solid #888; font-size: 9.5pt; }
p { margin: 0 0 7pt; }
blockquote { margin: 8pt 0 8pt 16pt; font-style: italic; }
hr { border: 0; border-top: 1pt solid #888; margin: 14pt 30%; }
pre { white-space: pre-wrap; font-size: 9pt; }
a { color: #111; text-decoration: none; }
.lf-align-left { text-align: left; } .lf-align-center { text-align: center; }
.lf-align-right { text-align: right; } .lf-align-justify { text-align: justify; }
ol.contents { text-align: left; }
CSS;

    public function __construct(
        private readonly string $librariesDir,
        private readonly string $workDir,
    ) {
    }

    /** Whether this server has what the library needs and the library is in place. */
    public static function isAvailable(string $librariesDir): bool
    {
        return extension_loaded('dom') && extension_loaded('mbstring') && extension_loaded('iconv') && is_file($librariesDir . '/vendor/autoload.php');
    }

    /**
     * Writes the PDF to $target. The caller moves it into place.
     *
     * @throws DownloadException
     */
    public function build(StoryDocument $document, string $target): void
    {
        if (!self::isAvailable($this->librariesDir)) {
            throw new DownloadException('This server cannot make PDF files.');
        }

        $this->assertFits($document->words);

        require_once $this->librariesDir . '/vendor/autoload.php';

        $fonts = $this->workDir . '/fonts';

        if (!is_dir($fonts) && !mkdir($fonts, 0755, true) && !is_dir($fonts)) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        // Nothing in the document refers to a file, so nothing on the server is readable through it.
        $options->set('chroot', $this->workDir);
        $options->set('allowedProtocols', []);
        $options->set('fontDir', $fonts);
        $options->set('fontCache', $fonts);
        $options->set('tempDir', $fonts);
        $options->set('defaultFont', self::FONT);

        // Only ever raise a limit that is too short. An unlimited one (0) must stay unlimited: set_time_limit() would
        // otherwise impose a limit on a long-running process that never had one.
        $current = (int) ini_get('max_execution_time');

        if ($current !== 0 && $current < self::TIME_LIMIT && function_exists('set_time_limit')) {
            set_time_limit(self::TIME_LIMIT);
        }

        try {
            $pdf = new Dompdf($options);
            $pdf->loadHtml($this->html($document), 'UTF-8');
            $pdf->setPaper('A5');
            $pdf->render();
            $pdf->addInfo('Title', $document->title);
            $pdf->addInfo('Author', $document->author);

            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont(self::FONT);
            $canvas->page_text($canvas->get_width() / 2 - 6, $canvas->get_height() - 30, '{PAGE_NUM}', $font, 8, [0.35, 0.35, 0.35]);

            $bytes = $pdf->output();
        } catch (Throwable $exception) {
            // The details go to the error log; the reader gets a sentence that is safe to show.
            error_log('PDF download failed for story ' . $document->storyId . ': ' . $exception->getMessage());

            throw new DownloadException('The PDF could not be made for this story. Try the EPUB or HTML download.');
        }

        if (!is_string($bytes) || $bytes === '' || file_put_contents($target, $bytes) === false) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }
    }

    /**
     * Refuses a story the server could not finish, before any work starts, so the reader gets a sentence instead of a
     * request that dies part way through.
     *
     * @throws DownloadException
     */
    private function assertFits(int $words): void
    {
        $limit = self::memoryLimit();
        $tooLong = 'This story is too long to make as a PDF. Try the EPUB or HTML download instead.';

        if ($words > self::MAX_WORDS || ($limit > 0 && self::BASE_MEMORY + $words * self::BYTES_PER_WORD > $limit)) {
            throw new DownloadException($tooLong);
        }
    }

    /** The memory limit in bytes, or 0 when there is none. */
    private static function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function html(StoryDocument $document): string
    {
        $out = '<!DOCTYPE html><html lang="' . Html::e($document->language) . '"><head><meta charset="utf-8"><title>' . Html::e($document->title) . '</title><style>' . self::STYLE . '</style></head><body>';
        $out .= '<div class="title-page"><h1>' . Html::e($document->title) . '</h1><p class="byline">by ' . Html::e($document->author) . '</p><div class="details">';

        foreach ($document->details as $detail) {
            $out .= '<p><strong>' . Html::e($detail['label']) . ':</strong> ' . Html::e(implode(', ', $detail['values'])) . '</p>';
        }

        $out .= '<p><strong>Status:</strong> ' . Html::e($document->status) . '</p><p><strong>Words:</strong> ' . number_format($document->words) . '</p></div>';

        foreach (['Summary' => $document->summary, 'Notes' => $document->notes, 'Disclaimer' => $document->disclaimer] as $heading => $text) {
            $out .= trim($text) === '' ? '' : '<div class="details"><p><strong>' . $heading . '</strong></p>' . $this->lines($text) . '</div>';
        }

        $out .= '</div>';

        if (count($document->chapters) > 1) {
            $out .= '<div class="chapter"><h2>Contents</h2><ol class="contents">';

            foreach ($document->chapters as $chapter) {
                $out .= '<li><a href="#chapter-' . $chapter['number'] . '">' . Html::e($chapter['title']) . '</a></li>';
            }

            $out .= '</ol></div>';
        }

        foreach ($document->chapters as $chapter) {
            $out .= '<div class="chapter" id="chapter-' . $chapter['number'] . '"><h2>' . Html::e($chapter['title']) . '</h2>'
                . ($chapter['notes'] === '' ? '' : '<div class="notes">' . $this->lines($chapter['notes']) . '</div>')
                . $chapter['html'] . '</div>';
        }

        return $out . '</body></html>';
    }

    private function lines(string $text): string
    {
        $out = '';

        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $paragraph) {
            $out .= '<p>' . nl2br(Html::e($paragraph), false) . '</p>';
        }

        return $out;
    }
}
