<?php

/**
 * Storybook theme: loads the reader script and declares the theme's options.
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

// A plain blocking script in the head, because it must run before the page is drawn to avoid a flash of the wrong colours.
add_action('lf_head', static function (): void {
    echo '<script src="';
    lf_theme_url('js/reader.js');
    echo '"></script>', "\n";
});

add_filter('theme_option_fields', static function (array $fields): array {
    $fields[] = [
        'key' => 'story_font',
        'label' => 'Story text typeface',
        'type' => 'select',
        'css_variable' => '--lf-story-font',
        'choices' => [
            'Georgia, serif' => 'Georgia (serif)',
            'Palatino, Palatino Linotype, serif' => 'Palatino (serif)',
            'system-ui, sans-serif' => 'System (sans-serif)',
        ],
        'hint' => 'Leave on the theme default for the Storybook serif. Only typefaces already on the reader\'s device are used.',
    ];
    $fields[] = [
        'key' => 'show_credit',
        'label' => 'Show "Powered by Lumora Fiction" in the footer',
        'type' => 'checkbox',
        'default' => true,
    ];

    return $fields;
});
