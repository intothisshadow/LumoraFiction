<?php

/**
 * Template for config/config.php. The installer writes the real file; never commit a real one.
 *
 * @package LumoraFiction
 * @subpackage Config
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => '',
    'db_user' => '',
    'db_password' => '',
    'db_charset' => 'utf8mb4',
    // Full public address of the site, including any subdirectory and with no trailing slash.
    // Emailed links are built from this value, never from the request, so a forged Host header
    // cannot redirect a password-reset link. The installer sets it.
    'site_url' => '',
    // The installer suggests a random prefix (e.g. "lumfic_a8f3d1_"); never change it after install.
    'table_prefix' => 'lumfic_',
    // Generated at install time; used for signing tokens. Never change it after install.
    'secret_key' => '',
    // "production" for a live site. Tools meant only for testing, such as the Dummy Content plugin, refuse to
    // run on anything flagged production, and a missing value counts as production. Set it to "development" by
    // hand on a throwaway copy; nothing in the admin can change it.
    'environment' => 'production',
    // Set to false to switch off installing and updating themes from a ZIP on the Appearance screen. A theme
    // is PHP that runs on the site, so a host that wants themes copied in by hand sets this. A missing value
    // counts as true, and nothing in the admin can change it.
    'theme_uploads' => true,
];
