<?php

/**
 * The HTML download: one self-contained page with a title page, every chapter in order and inline styles only.
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

use LumoraFiction\Helpers\Html;

final class HtmlBuilder
{
    public const STYLE = <<<'CSS'
body{max-width:40rem;margin:0 auto;padding:1.5rem 1rem 4rem;font:1.1rem/1.65 Georgia,"Iowan Old Style","Times New Roman",serif;color:#222;background:#fff}
h1,h2,h3{line-height:1.25}
.lf-dl-title{margin:2rem 0 .25rem;font-size:2rem}
.lf-dl-byline{margin:0 0 1.5rem;font-style:italic}
.lf-dl-details{margin:0 0 1.5rem}
.lf-dl-details dt{font-weight:bold}
.lf-dl-details dd{margin:0 0 .5rem}
.lf-dl-contents{margin:0 0 2rem}
.lf-dl-chapter{margin-top:3rem;padding-top:1rem;border-top:1px solid #bbb}
.lf-dl-notes{padding:.5rem 1rem;border-left:3px solid #bbb;font-size:.95rem}
.lf-align-left{text-align:left}.lf-align-center{text-align:center}.lf-align-right{text-align:right}.lf-align-justify{text-align:justify}
blockquote{margin:1rem 0 1rem 1.5rem;font-style:italic}
hr{border:0;border-top:1px solid #bbb;margin:2rem auto;width:30%}
pre{white-space:pre-wrap}
@media (prefers-color-scheme:dark){body{color:#e8e8e8;background:#161616}.lf-dl-chapter,.lf-dl-notes{border-color:#555}a{color:#9ec5ff}}
CSS;

    public function build(StoryDocument $document): string
    {
        $language = Html::e($document->language);
        $out = '<!DOCTYPE html>' . "\n" . '<html lang="' . $language . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Html::e($document->title) . ' by ' . Html::e($document->author) . '</title>'
            . '<style>' . self::STYLE . '</style></head><body>';

        $out .= '<header><h1 class="lf-dl-title">' . Html::e($document->title) . '</h1>'
            . '<p class="lf-dl-byline">by ' . Html::e($document->author) . '</p>';

        $out .= '<dl class="lf-dl-details">';

        foreach ($document->details as $detail) {
            $out .= '<dt>' . Html::e($detail['label']) . '</dt><dd>' . Html::e(implode(', ', $detail['values'])) . '</dd>';
        }

        $out .= '<dt>Status</dt><dd>' . Html::e($document->status) . '</dd><dt>Words</dt><dd>' . number_format($document->words) . '</dd></dl>';

        $out .= $this->paragraphs('Summary', $document->summary) . $this->paragraphs('Notes', $document->notes) . $this->paragraphs('Disclaimer', $document->disclaimer) . '</header>';

        if (count($document->chapters) > 1) {
            $out .= '<nav class="lf-dl-contents" aria-label="Contents"><h2>Contents</h2><ol>';

            foreach ($document->chapters as $chapter) {
                $out .= '<li><a href="#chapter-' . $chapter['number'] . '">' . Html::e($chapter['title']) . '</a></li>';
            }

            $out .= '</ol></nav>';
        }

        foreach ($document->chapters as $chapter) {
            $out .= '<section class="lf-dl-chapter" id="chapter-' . $chapter['number'] . '"><h2>' . Html::e($chapter['title']) . '</h2>';
            $out .= $chapter['notes'] === '' ? '' : '<aside class="lf-dl-notes">' . $this->lines($chapter['notes']) . '</aside>';
            $out .= $chapter['html'] . '</section>';
        }

        return $out . '</body></html>' . "\n";
    }

    private function paragraphs(string $heading, string $text): string
    {
        return trim($text) === '' ? '' : '<section><h2>' . Html::e($heading) . '</h2>' . $this->lines($text) . '</section>';
    }

    /** Plain text as paragraphs, with a line break kept where the writer made one. */
    private function lines(string $text): string
    {
        $out = '';

        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $paragraph) {
            $out .= '<p>' . nl2br(Html::e($paragraph), false) . '</p>';
        }

        return $out;
    }
}
