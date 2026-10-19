<?php

/**
 * Gives the reader script the signed-in reader's saved choices.
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
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ReaderPreferences;

/**
 * Written into the head by core, so every theme's reader script can use the account's choices without a template
 * change, and a theme never has to know who is signed in. It runs before the theme's own head output, because the
 * script that reads these tags runs as soon as it is loaded. A guest gets nothing here.
 */
final class ReaderHead
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ReaderPreferences $preferences,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('lf_head', $this->output(...), 5);
    }

    public function output(): void
    {
        $user = $this->auth->user();

        if ($user === null) {
            return;
        }

        $saved = $this->preferences->forUser($user->id);

        echo '<meta name="lf-reader-prefs" content="', self::attribute(json_encode($saved === [] ? new \stdClass() : $saved, JSON_THROW_ON_ERROR)), '">', "\n";
        echo '<meta name="lf-reader-save" content="', self::attribute(PublicUrl::account('profile/reading')), '">', "\n";
        echo '<meta name="lf-reader-token" content="', self::attribute(Csrf::token('reader_preferences')), '">', "\n";
    }

    private static function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
