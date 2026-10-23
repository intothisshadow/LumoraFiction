<?php

/**
 * Installing, updating and deleting themes from the Appearance screen: the rules around the file work.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\UploadedFile;
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Core\Theme\ThemeInfo;
use LumoraFiction\Core\Theme\ThemeInstaller;
use LumoraFiction\Core\Theme\ThemeInstallException;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\User;

/**
 * The installer knows folders and archives; this class knows the site: which theme is in use, what an upload
 * switch in the configuration file says, which saved options belong to a deleted theme, and what goes in the
 * audit log. The caller has already checked that the person may manage the site.
 */
final class ThemeManager
{
    public function __construct(
        private readonly ThemeInstaller $installer,
        private readonly ThemeCatalog $catalog,
        private readonly ThemeRenderer $renderer,
        private readonly ThemeSettings $settings,
        private readonly ThemeOptions $options,
        private readonly AuditLogService $audit,
        private readonly Configuration $config,
        private readonly string $appVersion,
    ) {
    }

    /**
     * Uploaded themes are PHP that will run, so a host can turn the feature off in config/config.php and install by hand.
     * A missing key means on.
     */
    public function uploadsAllowed(): bool
    {
        return $this->config->get('theme_uploads', true) !== false;
    }

    public function canWrite(): bool
    {
        return $this->installer->isWritable();
    }

    /**
     * Sentences for what stops a theme from being used on this site; empty when nothing does.
     *
     * @return list<string>
     */
    public function problemsFor(ThemeInfo $theme): array
    {
        return $theme->unmetRequirements(PHP_VERSION, $this->appVersion);
    }

    /**
     * Whether a theme is the one the site shows, or the one an administrator picked (which may be missing right now).
     */
    public function isInUse(string $slug): bool
    {
        return $slug === $this->renderer->activeTheme()->slug || ($slug !== '' && $slug === $this->settings->chosenSlug());
    }

    /**
     * @return array{name: string, version: string, slug: string}
     *
     * @throws ThemeInstallException
     */
    public function install(?UploadedFile $file, User $actor, string $ipAddress): array
    {
        $path = $this->checkedUpload($file);
        $package = $this->installer->install($path);

        $this->audit->record($actor->id, 'appearance.theme.install', 'theme', $package->slug, ['version' => $package->version], $ipAddress);

        return ['name' => $package->name, 'version' => $package->version, 'slug' => $package->slug];
    }

    /**
     * @return array{name: string, from: string, to: string}
     *
     * @throws ThemeInstallException
     */
    public function update(?UploadedFile $file, string $slug, bool $allowSameOrOlder, User $actor, string $ipAddress): array
    {
        $path = $this->checkedUpload($file);
        $result = $this->installer->replace($path, $slug, $allowSameOrOlder);
        $package = $result['package'];

        // Saved options and the active choice live in the settings table, keyed by the folder name, so a new
        // set of files under the same name keeps both without anything being copied.
        $this->audit->record($actor->id, 'appearance.theme.update', 'theme', $slug, ['from' => $result['from'], 'to' => $package->version], $ipAddress);

        return ['name' => $package->name, 'from' => $result['from'], 'to' => $package->version];
    }

    /**
     * Which of the named themes a delete would remove and which it would leave, so a confirmation page and the
     * delete itself agree. The built-in theme is not a folder and is never listed as deletable.
     *
     * @param list<string> $slugs
     * @return array{deletable: list<ThemeInfo>, kept: array<string, string>} kept maps a name to the reason it stays
     */
    public function deletionPlan(array $slugs): array
    {
        $deletable = [];
        $kept = [];

        foreach (array_values(array_unique($slugs)) as $slug) {
            $theme = $this->catalog->find($slug);

            if ($theme === null || $theme->builtIn) {
                $kept[$slug] = 'It is not an installed theme.';
            } elseif ($this->isInUse($slug)) {
                $kept[$slug] = 'It is the theme the site uses. Choose another theme first.';
            } else {
                $deletable[] = $theme;
            }
        }

        return ['deletable' => $deletable, 'kept' => $kept];
    }

    /**
     * @param list<string> $slugs
     * @return array{deleted: list<ThemeInfo>, kept: array<string, string>}
     */
    public function delete(array $slugs, User $actor, string $ipAddress): array
    {
        $plan = $this->deletionPlan($slugs);
        $deleted = [];
        $kept = $plan['kept'];

        foreach ($plan['deletable'] as $theme) {
            if (!$this->installer->delete($theme->slug)) {
                $kept[$theme->slug] = 'Its folder could not be removed.';

                continue;
            }

            $this->options->forget($theme->slug);
            $this->audit->record($actor->id, 'appearance.theme.delete', 'theme', $theme->slug, ['version' => $theme->version, 'bundled' => $theme->bundled], $ipAddress);
            $deleted[] = $theme;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * @throws ThemeInstallException
     */
    private function checkedUpload(?UploadedFile $file): string
    {
        if (!$this->uploadsAllowed()) {
            throw new ThemeInstallException('Uploading themes is turned off for this site. Copy the theme folder into the themes folder instead.');
        }

        if (!$this->installer->isWritable()) {
            throw new ThemeInstallException('The themes folder cannot be written to by the web server, so themes cannot be installed here.');
        }

        if ($file === null || !$file->wasSent()) {
            throw new ThemeInstallException('Choose a ZIP file first.');
        }

        if ($file->problem() !== '') {
            throw new ThemeInstallException($file->problem());
        }

        return $file->path;
    }
}
