<?php

/**
 * Builds the chapter editor that screens place inside their forms.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\EditorMode;
use LumoraFiction\Models\User;
use LumoraFiction\Services\EditorSettings;

/**
 * One place decides which modes a writer is offered and which one the editor opens in, so every screen
 * that edits chapter text behaves the same. The editor is a plain textarea first; the script upgrades it,
 * so saving still works if the script cannot run.
 */
final class ContentEditor
{
    public const TOKEN_ACTION = 'admin_content_editor';

    public function __construct(
        private readonly AdminView $view,
        private readonly EditorSettings $settings,
        private readonly string $assetVersion,
    ) {
    }

    /**
     * @param array{label?: string, id?: string, rows?: int, actions?: list<array{name: string, value: string, label: string, primary?: bool}>} $options
     */
    public function render(User $user, string $name, string $content, ?ContentFormat $stored, array $options = []): string
    {
        $mode = $this->settings->modeFor($user->id, $stored);
        $allowed = $this->settings->isLocked() ? [$mode] : EditorMode::cases();

        return $this->view->fragment('content-editor', [
            'id' => $options['id'] ?? 'lf-editor-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name),
            'name' => $name,
            'label' => $options['label'] ?? 'Text',
            'rows' => $options['rows'] ?? 24,
            'content' => $content,
            'mode' => $mode->value,
            'format' => $mode->format()->value,
            'modes' => array_map(static fn (EditorMode $candidate): array => ['value' => $candidate->value, 'label' => $candidate->label(), 'format' => $candidate->format()->value], $allowed),
            'actions' => $options['actions'] ?? [
                ['name' => 'action', 'value' => 'save_draft', 'label' => 'Save draft'],
                ['name' => 'action', 'value' => 'publish', 'label' => 'Publish', 'primary' => true],
            ],
            'token' => Csrf::token(self::TOKEN_ACTION),
            'previewUrl' => AdminUrl::to('content/preview'),
            'convertUrl' => AdminUrl::to('content/convert'),
            'tinymceBase' => AdminUrl::asset('vendor/tinymce'),
            'contentCss' => AdminUrl::asset('css/editor-content.css', $this->assetVersion),
            'scripts' => [
                AdminUrl::asset('vendor/tinymce/tinymce.min.js', $this->assetVersion),
                AdminUrl::asset('vendor/easymde/easymde.min.js', $this->assetVersion),
                AdminUrl::asset('js/paste-clean.js', $this->assetVersion),
                AdminUrl::asset('js/content-editor.js', $this->assetVersion),
            ],
            'styles' => [AdminUrl::asset('vendor/easymde/easymde.min.css', $this->assetVersion)],
        ]);
    }
}
