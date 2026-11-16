<?php

/**
 * The short text printed in the footer of every theme, such as a fan-work disclaimer.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * The text belongs to the site, so it is a site setting and never a theme option: theme options are stored under the
 * theme's folder name and would be left behind by a switch, a preview or a visitor's own theme choice. It is plain
 * text, stored as typed, and escaped when printed, so nothing typed here can ever become markup.
 */
final class FooterText
{
    public const SETTING = 'footer.text';

    public const DEFAULTS = [self::SETTING => ''];

    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /** The saved text, or an empty string when none is set or the stored value is not text. */
    public static function text(Configuration $config): string
    {
        $value = $config->setting(self::SETTING, '');

        return is_string($value) ? $value : '';
    }

    public function current(): string
    {
        return self::text($this->config);
    }

    /**
     * Line endings are made uniform and control characters dropped, so the text prints the same whatever browser or
     * system it was typed on.
     *
     * @return array{error: string, value: string}
     */
    public static function validate(string $input): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $input);
        $value = (string) preg_replace('/[^\P{C}\n]/u', '', $value);
        $value = trim((string) preg_replace("/\n{3,}/", "\n\n", $value));

        if (mb_strlen($value) > self::MAX_LENGTH) {
            return ['error' => 'The footer text can be at most ' . number_format(self::MAX_LENGTH) . ' characters long.', 'value' => $value];
        }

        return ['error' => '', 'value' => $value];
    }

    /**
     * @return bool whether anything changed
     */
    public function save(string $value, User $actor, string $ipAddress): bool
    {
        if (!$actor->can(Capability::ManageSite)) {
            return false;
        }

        $checked = self::validate($value);

        if ($checked['error'] !== '' || $checked['value'] === $this->current()) {
            return false;
        }

        $from = $this->current();
        $to = $checked['value'];

        $this->database->transaction(function () use ($to, $from, $actor, $ipAddress): void {
            $this->config->setSetting(self::SETTING, $to);
            // The length is audited, not the text, which can be long and is already public.
            $this->audit->record($actor->id, 'settings.footer_text.update', 'settings', 'footer', ['length' => ['from' => mb_strlen($from), 'to' => mb_strlen($to)]], $ipAddress);
        });

        return true;
    }
}
