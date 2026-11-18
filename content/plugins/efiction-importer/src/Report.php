<?php

/**
 * What a run did: counts by kind, every item left out with its reason, and the notes worth reading.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

/**
 * Holds eFiction ids, titles' worth of reasons and counts, and nothing else: no password, no connection detail and no email
 * address, so the report can be kept on the screen and downloaded.
 */
final class Report
{
    /** One run of a hundred thousand skipped items must not make the stored report unreadable, so the list stops here. */
    public const LINES_MAX = 1500;

    public const KINDS = ['user' => 'Accounts', 'term' => 'Classification terms', 'story' => 'Stories', 'chapter' => 'Chapters', 'series' => 'Series', 'comment' => 'Comments', 'bookmark' => 'Bookmarks', 'news' => 'News posts', 'page' => 'Pages'];

    /**
     * @param array<string, array{made: int, matched: int, again: int, skipped: int, failed: int}> $counts
     * @param list<array{kind: string, id: string, status: string, reason: string}> $lines
     * @param list<string> $notes
     * @param array<string, int|list<string>> $extra
     */
    public function __construct(
        public array $counts = [],
        public array $lines = [],
        public int $overflow = 0,
        public array $notes = [],
        public array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_array($data['counts'] ?? null) ? $data['counts'] : [],
            is_array($data['lines'] ?? null) ? $data['lines'] : [],
            (int) ($data['overflow'] ?? 0),
            is_array($data['notes'] ?? null) ? $data['notes'] : [],
            is_array($data['extra'] ?? null) ? $data['extra'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['counts' => $this->counts, 'lines' => $this->lines, 'overflow' => $this->overflow, 'notes' => $this->notes, 'extra' => $this->extra];
    }

    public function made(string $kind): void
    {
        $this->bump($kind, 'made');
    }

    public function matched(string $kind): void
    {
        $this->bump($kind, 'matched');
    }

    /**
     * An item an earlier run already brought across. It is only counted: a re-run of a large archive would otherwise list
     * everything again.
     */
    public function again(string $kind): void
    {
        $this->bump($kind, 'again');
    }

    /**
     * An item the administrator chose to leave out, or nothing uses: counted as skipped, not listed.
     */
    public function left(string $kind): void
    {
        $this->bump($kind, 'skipped');
    }

    public function skipped(string $kind, string $id, string $reason): void
    {
        $this->bump($kind, 'skipped');
        $this->line($kind, $id, 'skipped', $reason);
    }

    public function failed(string $kind, string $id, string $reason): void
    {
        $this->bump($kind, 'failed');
        $this->line($kind, $id, 'failed', $reason);
    }

    public function note(string $text): void
    {
        if (!in_array($text, $this->notes, true) && count($this->notes) < 50) {
            $this->notes[] = $text;
        }
    }

    /**
     * A counter that is not tied to one item (staff accounts, hashes that were kept).
     */
    public function add(string $name, int $by = 1): void
    {
        $this->extra[$name] = (int) ($this->extra[$name] ?? 0) + $by;
    }

    /**
     * Staff and administrator-made accounts are listed by eFiction id only: a person decides what they become, not the importer.
     */
    public function listed(string $name, string $id): void
    {
        $list = is_array($this->extra[$name] ?? null) ? $this->extra[$name] : [];

        if (count($list) < 200) {
            $list[] = $id;
        }

        $this->extra[$name] = $list;
    }

    public function text(): string
    {
        $out = ["eFiction import report\n"];

        foreach (self::KINDS as $kind => $label) {
            $row = $this->counts[$kind] ?? null;

            if ($row !== null) {
                $out[] = sprintf('%s: %d made, %d matched with something already here, %d already imported before, %d skipped, %d failed', $label, $row['made'], $row['matched'], $row['again'] ?? 0, $row['skipped'], $row['failed']);
            }
        }

        foreach ($this->extra as $name => $value) {
            $out[] = $name . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value);
        }

        foreach ($this->notes as $note) {
            $out[] = 'Note: ' . $note;
        }

        $out[] = '';

        foreach ($this->lines as $line) {
            $out[] = sprintf('%s %s %s: %s', $line['status'], $line['kind'], $line['id'], $line['reason']);
        }

        if ($this->overflow > 0) {
            $out[] = sprintf('... and %d more that are not listed.', $this->overflow);
        }

        return implode("\n", $out) . "\n";
    }

    public function csv(): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['status', 'kind', 'efiction_id', 'reason'], ',', '"', '');

        foreach ($this->lines as $line) {
            // A cell that starts with = + - or @ is read as a formula by a spreadsheet, so it is made plain text.
            $reason = preg_match('/^[=+\-@\t\r]/', $line['reason']) === 1 ? "'" . $line['reason'] : $line['reason'];
            fputcsv($handle, [$line['status'], $line['kind'], $line['id'], $reason], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function bump(string $kind, string $field): void
    {
        $this->counts[$kind] ??= ['made' => 0, 'matched' => 0, 'again' => 0, 'skipped' => 0, 'failed' => 0];
        $this->counts[$kind][$field]++;
    }

    private function line(string $kind, string $id, string $status, string $reason): void
    {
        if (count($this->lines) >= self::LINES_MAX) {
            $this->overflow++;

            return;
        }

        $this->lines[] = ['kind' => $kind, 'id' => $id, 'status' => $status, 'reason' => Text::cut($reason, 240)];
    }
}
