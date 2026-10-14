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
    // The installer suggests a random prefix (e.g. "lumfic_a8f3d1_"); never change it after install.
    'table_prefix' => 'lumfic_',
    // Generated at install time; used for signing tokens. Never change it after install.
    'secret_key' => '',
];
