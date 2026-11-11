<?php

/**
 * Classic Archive theme: loads the reader script and declares the theme's options.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
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
        'hint' => 'Leave on the theme default for the Classic Archive serif. Only typefaces already on the reader\'s device are used.',
    ];
    $fields[] = [
        'key' => 'show_header_image',
        'label' => 'Show a picture band under the site header',
        'type' => 'checkbox',
        'default' => false,
        'hint' => 'Off by default. The picture is decorative and sits between the header and the main content.',
    ];
    $fields[] = [
        'key' => 'header_image',
        'label' => 'Header picture',
        'type' => 'select',
        'choices' => [
            'dusk' => 'Dusk (blue to amber)',
            'ink' => 'Ink (deep blue lines)',
            'meadow' => 'Meadow (soft green hills)',
        ],
        'default' => 'dusk',
        // The picture choices beyond the three bundled ones (files added by FTP and the uploaded picture) reach the stylesheet as this variable.
        'pictures' => ['variable' => '--lf-header-picture', 'upload' => 'header_upload'],
        'hint' => 'Used only when the picture band is switched on. Pictures named banner-something.jpg (or .png, .webp, .svg) in content/uploads/theme-headers/ are listed here too.',
    ];
    $fields[] = [
        'key' => 'header_upload',
        'label' => 'Upload your own header picture',
        'type' => 'image',
        'hint' => 'A JPEG, PNG or WebP picture. A wide, short one (around 1600 by 300 pixels) suits the band, because it is cropped to fill it. After saving, choose "Uploaded picture" above.',
    ];
    $fields[] = [
        'key' => 'show_credit',
        'label' => 'Show "Powered by Lumora Fiction" in the footer',
        'type' => 'checkbox',
        'default' => true,
    ];

    return $fields;
});
