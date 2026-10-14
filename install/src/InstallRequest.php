<?php

/**
 * Everything the installer needs, after it has been validated.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Installer;

final readonly class InstallRequest
{
    public function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $tablePrefix,
        public string $siteName,
        public string $siteUrl,
        public string $timezone,
        public string $archiveMode,
        public string $adminLoginName,
        public string $adminDisplayName,
        public string $adminEmail,
        #[\SensitiveParameter]
        public string $adminPassword,
    ) {
    }
}
