<?php

/**
 * Exports the portable settings to a versioned file, and reads one back with a preview before anything is applied.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use Closure;
use JsonException;
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;

/**
 * Only values that mean the same on any site go in a file. What belongs to one install is left out by construction,
 * not by filtering: the site address, the administrator's email, the secret key, the table prefix, the database
 * credentials, the trusted proxy list, the HTTPS-only header, the page chosen as the privacy policy (its number is
 * this site's), and the keys and answers of the spam tools are not fields of any group, so there is nothing to forget
 * to remove. Reading a file is the export's mirror image: an unknown group, field or type is refused, and every value
 * goes through the same check as its settings screen, so a file can change nothing a form could not. The whole file is
 * checked before any setting is written, and a file with one bad value is refused as a whole.
 */
final class SettingsTransfer
{
    public const FORMAT = 'lumora-fiction-settings';

    public const VERSION = 1;

    /** Far more than a real file (a few kilobytes); a larger one is not a settings file. */
    public const MAX_BYTES = 65536;

    /** @var array<string, array{label: string, fields: array<string, string>, current: Closure(): array<string, mixed>, validate: Closure(array<string, mixed>): array{errors: array<string, string>, values: array<string, mixed>}, save: Closure(array<string, mixed>, User, string): void}>|null */
    private ?array $groups = null;

    public function __construct(
        private readonly ArchiveSettings $archive,
        private readonly SecuritySettings $security,
        private readonly PrivacySettings $privacy,
        private readonly CommentSettings $comments,
        private readonly DownloadSettings $downloads,
        private readonly FeedSettings $feeds,
        private readonly ClassificationLabelSettings $classification,
        private readonly NewsSettings $news,
        private readonly FrontPageSettings $frontPage,
        private readonly EditorSettings $editor,
        private readonly RetentionSettings $retention,
        private readonly SearchSettings $search,
        private readonly FooterText $footerText,
        private readonly WholeStorySettings $wholeStory,
        private readonly ThemeSettings $themes,
        private readonly ThemeChoice $themeChoice,
        private readonly ThemeOptions $themeOptions,
        private readonly ThemeCatalog $catalog,
        private readonly AuditLogService $audit,
        private readonly string $appVersion,
    ) {
    }

    /**
     * @return array{format: string, version: int, exported_at: string, app_version: string, groups: array<string, array<string, mixed>>}
     */
    public function export(): array
    {
        $groups = [];

        foreach ($this->groups() as $name => $group) {
            $groups[$name] = $group['current']();
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'app_version' => $this->appVersion,
            'groups' => $groups,
        ];
    }

    public function exportJson(): string
    {
        return json_encode($this->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Reads a file and works out what applying it would change, writing nothing.
     *
     * @return array{errors: list<string>, notes: list<string>, rows: list<array{group: string, field: string, from: string, to: string}>, values: array<string, array<string, mixed>>, label: array<string, string>}
     * @throws SettingsTransferException when the file is not a settings file this version can read
     */
    public function preview(string $json): array
    {
        $data = $this->parse($json);
        $errors = [];
        $notes = [];
        $rows = [];
        $values = [];
        $labels = [];
        $groups = $this->groups();

        foreach ($data['groups'] as $name => $given) {
            if (!is_string($name) || !isset($groups[$name])) {
                $errors[] = 'The file has a group of settings this version does not know (' . $this->shown($name) . ').';

                continue;
            }

            $group = $groups[$name];
            $labels[$name] = $group['label'];

            if (!is_array($given) || ($given !== [] && array_is_list($given))) {
                $errors[] = $group['label'] . ': expected a set of named values.';

                continue;
            }

            $input = [];

            foreach ($given as $field => $value) {
                if (!is_string($field) || !isset($group['fields'][$field])) {
                    $errors[] = $group['label'] . ': the file has a setting this version does not know (' . $this->shown($field) . ').';

                    continue;
                }

                if (!$this->hasType($value, $group['fields'][$field])) {
                    $errors[] = $group['label'] . ': ' . $this->humanise($field) . ' is the wrong kind of value.';

                    continue;
                }

                $input[$field] = $value;
            }

            if ($input === []) {
                continue;
            }

            $current = $group['current']();
            $result = $group['validate']($input + $current);

            foreach ($result['errors'] as $field => $message) {
                // Only a problem with something the file actually carries is the file's fault.
                if (array_key_exists($field, $input)) {
                    $errors[] = $group['label'] . ': ' . $this->humanise($field) . ': ' . $message;
                }
            }

            // A form quietly puts a value it does not know back to a default. A file must not: it is refused instead.
            if ($name !== 'theme') {
                foreach ($input as $field => $value) {
                    if (!isset($result['errors'][$field]) && array_key_exists($field, $result['values']) && !$this->same($value, $result['values'][$field])) {
                        $errors[] = $group['label'] . ': ' . $this->humanise($field) . ': this site does not accept that value.';
                    }
                }
            }

            if ($name === 'theme') {
                foreach ($this->themeNotes($input, $current) as $note) {
                    $notes[] = $note;
                }
            }

            $accepted = [];

            foreach (array_keys($input) as $field) {
                if (!array_key_exists($field, $result['values'])) {
                    continue;
                }

                $accepted[$field] = $result['values'][$field];

                if ($result['values'][$field] !== ($current[$field] ?? null) && !isset($result['errors'][$field])) {
                    $rows[] = ['group' => $group['label'], 'field' => $this->humanise($field), 'from' => $this->display($current[$field] ?? null), 'to' => $this->display($result['values'][$field])];
                }
            }

            $values[$name] = $accepted;
        }

        return ['errors' => $errors, 'notes' => $notes, 'rows' => $rows, 'values' => $values, 'label' => $labels];
    }

    /**
     * Applies a file that preview() found no error in. It is checked again here, so what is applied is always what was
     * checked, whatever reached this call.
     *
     * @return int how many settings changed
     * @throws SettingsTransferException when the file is unreadable or has any error
     */
    public function apply(User $actor, string $json, string $ipAddress = ''): int
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new SettingsTransferException('You may not change the site settings.');
        }

        $preview = $this->preview($json);

        if ($preview['errors'] !== []) {
            throw new SettingsTransferException('The file has problems, so nothing was changed.');
        }

        $groups = $this->groups();
        $touched = [];

        foreach ($preview['values'] as $name => $values) {
            $before = $groups[$name]['current']();
            $groups[$name]['save']($values + $before, $actor, $ipAddress);

            if ($groups[$name]['current']() !== $before) {
                $touched[] = $name;
            }
        }

        $this->audit->record($actor->id, 'settings.import', 'settings', 'import', ['groups' => $touched, 'changed' => count($preview['rows'])], $ipAddress);

        return count($preview['rows']);
    }

    /**
     * @return array{groups: array<mixed>}
     * @throws SettingsTransferException
     */
    private function parse(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new SettingsTransferException('That is not a settings file: it is empty or far too large.');
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SettingsTransferException('That is not a settings file: it is not valid JSON.');
        }

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new SettingsTransferException('That is not a Lumora Fiction settings file.');
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            throw new SettingsTransferException('This settings file is in a format version this site cannot read (it reads version ' . self::VERSION . ').');
        }

        if (!is_array($data['groups'] ?? null)) {
            throw new SettingsTransferException('The settings file has no settings in it.');
        }

        return ['groups' => $data['groups']];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return list<string>
     */
    private function themeNotes(array $input, array $current): array
    {
        if (!array_key_exists('options', $input)) {
            return [];
        }

        $wanted = array_key_exists('active', $input) ? $input['active'] : $current['active'];

        return $wanted !== $current['active'] || $current['active'] === ''
            ? ['The theme options in the file are for another theme than the one active here, so they are skipped. Make that theme active, then import the file again to bring them in.']
            : [];
    }

    /**
     * The site's own names for the kinds as one flat map of "{kind}_singular" and "{kind}_plural", the shape a settings
     * file can hold.
     *
     * @param array<string, array{singular: string, plural: string}> $labels
     * @return array<string, string>
     */
    private function flattenLabels(array $labels): array
    {
        $flat = [];

        foreach ($labels as $kind => $entry) {
            $flat[$kind . '_singular'] = $entry['singular'];
            $flat[$kind . '_plural'] = $entry['plural'];
        }

        return $flat;
    }

    /**
     * The reverse of flattenLabels(). A key that names no kind is dropped, and the check that follows then refuses the
     * file because what comes back differs from what went in.
     *
     * @return array<string, array{singular: string, plural: string}>
     */
    private function expandLabels(mixed $flat): array
    {
        $labels = [];

        foreach (is_array($flat) ? $flat : [] as $key => $word) {
            if (!is_string($key) || !is_string($word) || preg_match('/^([a-z_]+)_(singular|plural)\z/', $key, $match) !== 1 || TermType::tryFrom($match[1]) === null) {
                continue;
            }

            $labels[$match[1]] ??= ['singular' => '', 'plural' => ''];
            $labels[$match[1]][$match[2]] = $word;
        }

        return $labels;
    }

    private function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'bool' => is_bool($value),
            'int' => is_int($value),
            'string' => is_string($value),
            'list' => is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value,
            'map' => is_array($value) && ($value === [] || !array_is_list($value)) && array_filter($value, static fn (mixed $item): bool => is_string($item) || is_bool($item)) === $value,
            default => false,
        };
    }

    /**
     * Whether a value came through its check as it went in. The order of a list is not part of its meaning.
     */
    private function same(mixed $given, mixed $checked): bool
    {
        if (is_array($given) && is_array($checked)) {
            sort($given);
            sort($checked);
        }

        return $given === $checked;
    }

    private function humanise(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    private function shown(mixed $name): string
    {
        return is_string($name) ? '"' . mb_substr(preg_replace('/[^\p{L}\p{N}_ -]/u', '', $name) ?? '', 0, 40) . '"' : 'unnamed';
    }

    private function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'On' : 'Off',
            is_array($value) => $value === [] ? 'None' : implode(', ', array_map(static fn (mixed $item, mixed $key): string => is_string($key) ? $key . ': ' . (is_bool($item) ? ($item ? 'on' : 'off') : (string) $item) : (string) $item, $value, array_keys($value))),
            $value === null || $value === '' => 'Not set',
            default => (string) $value,
        };
    }

    /**
     * @return array<string, array{label: string, fields: array<string, string>, current: Closure(): array<string, mixed>, validate: Closure(array<string, mixed>): array{errors: array<string, string>, values: array<string, mixed>}, save: Closure(array<string, mixed>, User, string): void}>
     */
    private function groups(): array
    {
        return $this->groups ??= [
            'archive' => [
                'label' => 'Archive',
                'fields' => ['archive_mode' => 'string', 'registration_enabled' => 'bool', 'default_role' => 'string', 'email_verification' => 'bool', 'hold_first_story' => 'bool', 'require_signin' => 'bool', 'writer_tags' => 'bool', 'who_publishes' => 'string'],
                'current' => fn (): array => $this->archive->current(),
                'validate' => fn (array $in): array => $this->archive->validate($in),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->archive->save($values, $actor, $ip);
                },
            ],
            'security' => [
                'label' => 'Security',
                'fields' => ['lockout_attempts' => 'int', 'lockout_minutes' => 'int', 'lockout_progressive' => 'bool', 'notify_owner' => 'bool', 'password_min_length' => 'int', 'session_hours' => 'int'],
                'current' => fn (): array => array_intersect_key($this->security->current(), array_flip(['lockout_attempts', 'lockout_minutes', 'lockout_progressive', 'notify_owner', 'password_min_length', 'session_hours'])),
                // The numbers are read as text by the form's rules. The proxy list and the HTTPS header stay as they are on this site.
                'validate' => function (array $in): array {
                    $site = $this->security->current();
                    $input = [
                        'lockout_attempts' => (string) $in['lockout_attempts'],
                        'lockout_minutes' => (string) $in['lockout_minutes'],
                        'lockout_progressive' => $in['lockout_progressive'],
                        'notify_owner' => $in['notify_owner'],
                        'password_min_length' => (string) $in['password_min_length'],
                        'session_hours' => (string) $in['session_hours'],
                        'trusted_proxies' => $site['trusted_proxies'],
                        'hsts' => $site['hsts'],
                    ];

                    return $this->security->validate($input);
                },
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->security->save($values + $this->security->current(), $actor, $ip);
                },
            ],
            'privacy' => [
                'label' => 'Privacy',
                'fields' => ['deleted_stories' => 'string', 'neutral_name' => 'string'],
                'current' => fn (): array => array_intersect_key($this->privacy->current(), array_flip(['deleted_stories', 'neutral_name'])),
                // The page chosen as the policy is a number on this site, so it is kept as it is here.
                'validate' => fn (array $in): array => $this->privacy->validate(['page_id' => (string) $this->privacy->current()['page_id'], 'deleted_stories' => $in['deleted_stories'], 'neutral_name' => $in['neutral_name']]),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->privacy->save($values + $this->privacy->current(), $actor, $ip);
                },
            ],
            'comments' => [
                'label' => 'Comments',
                'fields' => ['enabled' => 'bool', 'trust' => 'string', 'author_toggle' => 'bool', 'edit_minutes' => 'int', 'report_threshold' => 'int', 'replies' => 'bool'],
                'current' => fn (): array => $this->comments->current(),
                'validate' => fn (array $in): array => $this->comments->validate(['edit_minutes' => (string) $in['edit_minutes'], 'report_threshold' => (string) $in['report_threshold']] + $in),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->comments->save($values, $actor, $ip);
                },
            ],
            'downloads' => [
                'label' => 'Downloads',
                'fields' => ['enabled' => 'bool', 'formats' => 'list', 'count' => 'bool', 'show_counts' => 'bool'],
                'current' => fn (): array => $this->downloads->current(),
                'validate' => fn (array $in): array => $this->downloads->validate($in['enabled'], $in['formats'], $in['count'], $in['show_counts']),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->downloads->save($values, $actor, $ip);
                },
            ],
            'feeds' => [
                'label' => 'Feeds',
                'fields' => ['kinds' => 'list', 'json' => 'bool', 'limit' => 'int', 'full_text' => 'bool'],
                'current' => fn (): array => $this->feeds->current(),
                'validate' => fn (array $in): array => $this->feeds->validate($in['kinds'], $in['json'], (string) $in['limit'], $in['full_text']),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->feeds->save($values, $actor, $ip);
                },
            ],
            'classification' => [
                'label' => 'Classification',
                'fields' => ['labels' => 'map'],
                'current' => fn (): array => ['labels' => $this->flattenLabels($this->classification->current())],
                'validate' => function (array $in): array {
                    // Every kind is accepted whatever the archive mode, so a file from a site with another mode still imports.
                    $result = $this->classification->validate($this->expandLabels($in['labels']), TermType::cases());

                    return [
                        'errors' => $result['errors'] === [] ? [] : ['labels' => reset($result['errors'])],
                        'values' => ['labels' => $this->flattenLabels($result['values'])],
                    ];
                },
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->classification->save($this->expandLabels($values['labels']), $actor, $ip);
                },
            ],
            'news' => [
                'label' => 'News',
                'fields' => ['enabled' => 'bool', 'home_count' => 'int'],
                'current' => fn (): array => $this->news->current(),
                'validate' => fn (array $in): array => $this->news->validate(['enabled' => $in['enabled'], 'home_count' => (string) $in['home_count']]),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->news->save($values, $actor, $ip);
                },
            ],
            'front_page' => [
                'label' => 'Front page',
                'fields' => ['published_count' => 'int', 'updated_count' => 'int'],
                'current' => fn (): array => $this->frontPage->current(),
                'validate' => fn (array $in): array => $this->frontPage->validate(['published_count' => (string) $in['published_count'], 'updated_count' => (string) $in['updated_count']]),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->frontPage->save($values, $actor, $ip);
                },
            ],
            'editor' => [
                'label' => 'Editor',
                'fields' => ['default_mode' => 'string', 'lock_to_default' => 'bool'],
                'current' => fn (): array => $this->editor->current(),
                'validate' => fn (array $in): array => $this->editor->validate($in),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->editor->save($values, $actor, $ip);
                },
            ],
            'retention' => [
                'label' => 'Retention',
                'fields' => ['trash_days' => 'int', 'revisions' => 'int'],
                'current' => fn (): array => $this->retention->current(),
                'validate' => fn (array $in): array => $this->retention->validate(['trash_days' => (string) $in['trash_days'], 'revisions' => (string) $in['revisions']]),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->retention->save($values, $actor, $ip);
                },
            ],
            'search' => [
                'label' => 'Search engines',
                'fields' => ['discourage' => 'bool'],
                'current' => fn (): array => $this->search->current(),
                'validate' => fn (array $in): array => ['errors' => [], 'values' => ['discourage' => $in['discourage']]],
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->search->save(['discourage' => (bool) $values['discourage']], $actor, $ip);
                },
            ],
            'reading' => [
                'label' => 'Reading',
                'fields' => ['whole_story' => 'bool', 'whole_story_words' => 'int'],
                'current' => fn (): array => $this->wholeStory->current(),
                'validate' => fn (array $in): array => $this->wholeStory->validate(['whole_story' => $in['whole_story'], 'whole_story_words' => (string) $in['whole_story_words']]),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->wholeStory->save($values, $actor, $ip);
                },
            ],
            'footer' => [
                'label' => 'Footer text',
                'fields' => ['text' => 'string'],
                'current' => fn (): array => ['text' => $this->footerText->current()],
                'validate' => function (array $in): array {
                    $checked = FooterText::validate((string) $in['text']);

                    return ['errors' => $checked['error'] === '' ? [] : ['text' => $checked['error']], 'values' => ['text' => $checked['value']]];
                },
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->footerText->save((string) $values['text'], $actor, $ip);
                },
            ],
            'theme' => [
                'label' => 'Theme',
                'fields' => ['active' => 'string', 'visitor_choice' => 'bool', 'visitor_themes' => 'list', 'options' => 'map'],
                'current' => fn (): array => $this->themeCurrent(),
                'validate' => fn (array $in): array => $this->themeValidate($in),
                'save' => function (array $values, User $actor, string $ip): void {
                    $this->themeSave($values, $actor, $ip);
                },
            ],
        ];
    }

    /**
     * @return array{active: string, visitor_choice: bool, visitor_themes: list<string>, options: array<string, string|bool>}
     */
    private function themeCurrent(): array
    {
        $active = $this->themes->chosenSlug();

        return [
            'active' => $active,
            'visitor_choice' => $this->themeChoice->enabled(),
            'visitor_themes' => $this->themeChoice->ticked(),
            // Options are only known for the theme in use, so that is the only one a file can carry.
            'options' => $active === '' ? [] : array_diff_key($this->themeOptions->values($active), array_flip($this->themeOptions->imageKeys())),
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    private function themeValidate(array $in): array
    {
        $current = $this->themeCurrent();
        $errors = [];
        $values = $in;

        if ($in['active'] !== '' && (!ThemeCatalog::isValidSlug($in['active']) || $this->catalog->find($in['active']) === null || $this->catalog->find($in['active'])?->builtIn === true)) {
            $errors['active'] = 'That theme is not installed on this site.';
            $values['active'] = $current['active'];
        } elseif ($in['active'] === '') {
            // An empty choice would mean "no theme picked", which this site cannot go back to once one is; it is left as it is.
            $values['active'] = $current['active'];
        }

        foreach ($in['visitor_themes'] as $slug) {
            if (!ThemeCatalog::isValidSlug($slug) || $this->catalog->find($slug) === null || $this->catalog->find($slug)?->builtIn === true) {
                $errors['visitor_themes'] = 'One of the themes visitors may choose is not installed on this site.';
                $values['visitor_themes'] = $current['visitor_themes'];

                break;
            }
        }

        // Options belong to a theme; they are read only for the theme this site already uses, where its own rules can check them.
        if ($values['active'] !== $current['active'] || $current['active'] === '') {
            $values['options'] = $current['options'];
        } else {
            // A picture is a file on this server, so a settings file can neither carry one nor point at one; the pictures stay as they are.
            $options = array_diff_key($in['options'], array_flip($this->themeOptions->imageKeys()));
            $checked = $this->themeOptions->validate($current['active'], $options);

            foreach ($checked['errors'] as $key => $message) {
                $errors['options'] = $this->humanise((string) $key) . ': ' . $message;
            }

            $values['options'] = $errors === [] || !isset($errors['options']) ? array_diff_key($checked['values'], array_flip($this->themeOptions->imageKeys())) : $current['options'];
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function themeSave(array $values, User $actor, string $ip): void
    {
        $current = $this->themeCurrent();

        if ($values['active'] !== '' && $values['active'] !== $current['active']) {
            $this->themes->activate((string) $values['active'], $actor, $ip);
        }

        if ($values['visitor_choice'] !== $current['visitor_choice'] || $values['visitor_themes'] !== $current['visitor_themes']) {
            $this->themeChoice->save((bool) $values['visitor_choice'], $values['visitor_themes'], $actor, $ip);
        }

        if ($current['active'] !== '' && $values['active'] === $current['active'] && $values['options'] !== $current['options']) {
            $this->themeOptions->save($current['active'], $values['options'], $actor, $ip);
        }
    }
}
