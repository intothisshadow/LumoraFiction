<?php

/**
 * A row of tabs and the attributes of their panels, for any admin screen that shows one panel at a time.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Helpers\Html;

/**
 * Each tab is a plain link to the same screen with a `?tab=` value, and the server prints the chosen panel as selected
 * and the others as hidden, so the screen is complete without a script; `admin/assets/js/tabs.js` only switches panels
 * in place. Every value printed here is escaped, so a plugin screen can use it with its own keys and labels.
 */
final class Tabs
{
    private string $selected;

    /**
     * @param list<array{key: string, label: string}> $tabs in display order; keys are lower-case letters, digits and hyphens
     * @param string $selected the key of the open tab; an unknown key falls back to the first tab
     * @param string $id prefix for the element ids, unique on the page
     * @param string $baseUrl the screen's own address, to which `?tab=` is added
     */
    public function __construct(
        private readonly array $tabs,
        string $selected,
        private readonly string $id,
        private readonly string $baseUrl,
    ) {
        $this->selected = self::choose($selected, array_column($tabs, 'key'), $tabs[0]['key'] ?? '');
    }

    /**
     * The tab to open for a requested value: the request if it names a tab that exists, otherwise the default.
     *
     * @param list<string> $keys
     */
    public static function choose(?string $requested, array $keys, string $default): string
    {
        return $requested !== null && in_array($requested, $keys, true) ? $requested : $default;
    }

    public function selected(): string
    {
        return $this->selected;
    }

    /**
     * The tab bar. Call it once, above the panels.
     */
    public function bar(string $label): string
    {
        $html = '<div class="lf-tabs" data-lf-tabs><div class="lf-tabs__list" role="tablist" aria-label="' . Html::e($label) . '">';

        foreach ($this->tabs as $tab) {
            $isSelected = $tab['key'] === $this->selected;
            $html .= '<a class="lf-tabs__tab' . ($isSelected ? ' is-selected' : '') . '" role="tab"'
                . ' id="' . Html::e($this->tabId($tab['key'])) . '"'
                . ' href="' . Html::e($this->baseUrl . '?tab=' . rawurlencode($tab['key'])) . '"'
                . ' aria-selected="' . ($isSelected ? 'true' : 'false') . '"'
                . ' aria-controls="' . Html::e($this->panelId($tab['key'])) . '"'
                . ' data-lf-tab="' . Html::e($tab['key']) . '">' . Html::e($tab['label']) . '</a>';
        }

        return $html . '</div></div>';
    }

    /**
     * The opening tag of a panel; print its content, then `closePanel()`.
     */
    public function openPanel(string $key): string
    {
        return '<div class="lf-tabs__panel" role="tabpanel" tabindex="0"'
            . ' id="' . Html::e($this->panelId($key)) . '"'
            . ' aria-labelledby="' . Html::e($this->tabId($key)) . '"'
            . ($key === $this->selected ? '' : ' hidden')
            . ' data-lf-tab-panel="' . Html::e($key) . '">';
    }

    public function closePanel(): string
    {
        return '</div>';
    }

    private function tabId(string $key): string
    {
        return $this->id . '-tab-' . $key;
    }

    private function panelId(string $key): string
    {
        return $this->id . '-panel-' . $key;
    }
}
