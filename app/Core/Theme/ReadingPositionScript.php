<?php

/**
 * Loads the reading-position script on a chapter page for a signed-in reader and hands it the details it needs.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Helpers\Html;

/**
 * The script ships with core, not a theme, because it saves to the account and uses a security token; a theme only
 * prints the resume button. The page controller calls track() once it knows which chapter is being shown, and the
 * footer hook writes nothing at all when it was not called, so a guest and every other page get no script.
 */
final class ReadingPositionScript
{
    /** The token action for the save endpoint. */
    public const ACTION = 'reading_position';

    /** @var array{story: int, chapter: int, position: int}|null */
    private ?array $tracking = null;

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('lf_footer', $this->output(...));
    }

    public function track(int $storyId, int $chapterId, int $position): void
    {
        $this->tracking = ['story' => $storyId, 'chapter' => $chapterId, 'position' => $position];
    }

    public function output(): void
    {
        $context = ThemeContext::current();

        if ($this->tracking === null || $context === null) {
            return;
        }

        $source = $context->assetUrl('js/reading-position.js');

        if ($source === '') {
            return;
        }

        echo '<script src="', Html::e($source), '" defer data-lf-reading-position',
            ' data-save="', Html::e(PublicUrl::account('reading/position')), '"',
            ' data-token="', Html::e(Csrf::token(self::ACTION)), '"',
            ' data-story="', $this->tracking['story'], '"',
            ' data-chapter="', $this->tracking['chapter'], '"',
            ' data-position="', $this->tracking['position'], '"></script>', "\n";
    }
}
