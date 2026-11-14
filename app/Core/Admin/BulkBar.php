<?php

/**
 * The shared pieces of a bulk-action list: the form that carries the token, the action buttons, the tick-all heading and a row's tick box.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Core\Http\Request;
use LumoraFiction\Helpers\Html;

/**
 * The bulk form is empty and sits beside the table; tick boxes and buttons join it with the form attribute, so a
 * table that already holds forms of its own (a button per row) stays valid HTML and everything works without
 * scripts. bulk-select.js only adds the tick-all box and the spoken count on top.
 */
final class BulkBar
{
    /**
     * @param array<string, string> $hidden extra fields to carry, such as the page and filters the person is on
     */
    public static function form(string $id, string $action, string $csrfToken, array $hidden = []): string
    {
        $html = '<form method="post" action="' . Html::e($action) . '" id="' . Html::e($id) . '">'
            . '<input type="hidden" name="csrf_token" value="' . Html::e($csrfToken) . '">';

        foreach ($hidden as $name => $value) {
            if ($value !== '') {
                $html .= '<input type="hidden" name="' . Html::e($name) . '" value="' . Html::e($value) . '">';
            }
        }

        return $html . '</form>';
    }

    /**
     * @param array<string, string> $actions action value => button text
     * @param string $subject what is selected, e.g. "chapters"
     */
    public static function bar(string $formId, string $subject, array $actions, string $position = 'top', string $group = ''): string
    {
        $html = '<div class="lf-bulk lf-bulk--terms" role="group" aria-label="Actions for the selected ' . Html::e($subject) . ($position === '' ? '' : ' (' . Html::e($position) . ')') . '">'
            . '<span class="lf-bulk__count" data-lf-selected-count="' . Html::e($group) . '" role="status" aria-live="polite" hidden>None selected</span>';

        foreach ($actions as $value => $label) {
            $html .= '<button type="submit" form="' . Html::e($formId) . '" name="bulk_action" value="' . Html::e((string) $value) . '" class="lf-button lf-button--secondary">' . Html::e($label) . '</button>';
        }

        return $html . '</div>';
    }

    /**
     * The heading cell with the labelled tick-all box, shown by the script; without it the cell just says "Select".
     */
    public static function selectAllHeader(string $group, string $formId, string $subject): string
    {
        return '<th scope="col">'
            . '<input type="checkbox" data-lf-select-all="' . Html::e($group) . '" form="' . Html::e($formId) . '" aria-label="Select all ' . Html::e($subject) . ' on this page" hidden>'
            . '<span class="lf-visually-hidden" data-lf-select-fallback>Select</span></th>';
    }

    public static function checkbox(string $group, string $formId, int $id, string $label): string
    {
        return '<td>' . self::input($group, $formId, $id, $label) . '</td>';
    }

    /**
     * A row's tick box on its own, for a list that is not a table.
     */
    public static function input(string $group, string $formId, int $id, string $label): string
    {
        return '<input type="checkbox" name="ids[]" value="' . $id . '" data-lf-select-item="' . Html::e($group) . '" form="' . Html::e($formId) . '" aria-label="' . Html::e($label) . '">';
    }

    /**
     * The labelled tick-all box for a list that is not a table, shown by the script.
     */
    public static function selectAll(string $group, string $formId, string $subject): string
    {
        return '<p class="lf-choice"><input type="checkbox" data-lf-select-all="' . Html::e($group) . '" form="' . Html::e($formId) . '" aria-label="Select all ' . Html::e($subject) . '" hidden>'
            . '<span class="lf-visually-hidden" data-lf-select-fallback>Tick the ' . Html::e($subject) . ' to change.</span></p>';
    }

    /**
     * The filters, sort and page the person was on, to carry through a bulk action and come back to. The filters are
     * passed in already checked by the screen; the sort is rebuilt from the screen's own column map and the page is
     * only ever a plain number.
     *
     * @param array<string, string> $filters filter name => checked value; empty values are left out
     * @return array<string, string>
     */
    public static function kept(Request $request, ?ColumnSort $sort = null, array $filters = []): array
    {
        $page = preg_match('/^[1-9][0-9]{0,5}\z/', $request->input('page') ?? '') === 1 ? (int) $request->input('page') : 1;

        return array_filter($filters, static fn (string $value): bool => $value !== '') + ($sort?->query() ?? []) + ($page > 1 ? ['page' => (string) $page] : []);
    }

    /**
     * @param array<string, string> $kept
     */
    public static function listUrl(string $base, array $kept): string
    {
        return $base . ($kept === [] ? '' : '?' . http_build_query($kept));
    }

    public static function script(string $assetVersion): string
    {
        return '<script src="' . Html::e(AdminUrl::asset('js/bulk-select.js', $assetVersion)) . '" defer></script>';
    }
}
