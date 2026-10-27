<?php

/**
 * Country lookup from a GeoLite2 CSV the administrator imports, kept as a sorted flat file.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\VisitorStats;

use RuntimeException;
use ZipArchive;

/**
 * The ranges live in a file rather than the database: they are a large reference dataset (hundreds of thousands of
 * rows) the administrator can rebuild, and keeping them out of the tables keeps database backups small. Nothing is
 * bundled and nothing is fetched; an address is only read here to find its country and is never stored.
 */
final class GeoIp
{
    /** One range: 4-byte start, 4-byte end, 2-letter country. */
    private const RECORD_SIZE = 10;

    private const PACK = 'NNa2';

    private const UNPACK = 'Nstart/Nend/a2country';

    /** Rows read per request, small enough for a slow shared host's time limit. */
    public const BATCH_ROWS = 40000;

    /** Data this old is flagged, because MaxMind revises the dataset and nothing here checks for a newer one. */
    private const STALE_AFTER = 6 * 30 * 86400;

    /** The largest file taken from a ZIP, so a crafted archive cannot fill the disk. */
    private const MAX_UNPACKED_BYTES = 200 * 1048576;

    private readonly string $rangesFile;

    private readonly string $importDir;

    public function __construct(private readonly string $directory)
    {
        $this->rangesFile = $directory . '/ranges.bin';
        $this->importDir = $directory . '/import';
    }

    public function rangeCount(): int
    {
        return is_file($this->rangesFile) ? intdiv((int) filesize($this->rangesFile), self::RECORD_SIZE) : 0;
    }

    public function importedAt(): ?int
    {
        $time = is_file($this->rangesFile) ? filemtime($this->rangesFile) : false;

        return $time === false ? null : $time;
    }

    public function isStale(): bool
    {
        $at = $this->importedAt();

        return $at !== null && $at < time() - self::STALE_AFTER;
    }

    /** IPv6 addresses and addresses outside the data give null: a gap in the numbers, not an error. */
    public function countryFor(string $ip): ?string
    {
        $long = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? false : ip2long($ip);

        if ($long === false || !is_file($this->rangesFile)) {
            return null;
        }

        $handle = fopen($this->rangesFile, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $low = 0;
            $high = $this->rangeCount() - 1;
            $match = null;

            // The last range starting at or before the address; it is a hit only if the address is not past its end.
            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                $record = $this->record($handle, $middle);

                if ($long < $record['start']) {
                    $high = $middle - 1;
                } else {
                    $match = $record;
                    $low = $middle + 1;
                }
            }

            return $match !== null && $long <= $match['end'] ? $match['country'] : null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{blocks: string, locations: string, offset: int, imported: int, started: int}|null the import under way, if any
     */
    public function pending(): ?array
    {
        $file = $this->importDir . '/state.json';

        if (!is_file($file)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($file), true);

        return is_array($state) && isset($state['offset'], $state['imported'], $state['started']) ? [
            'blocks' => $this->importDir . '/blocks.csv',
            'locations' => $this->importDir . '/locations.csv',
            'offset' => (int) $state['offset'],
            'imported' => (int) $state['imported'],
            'started' => (int) $state['started'],
        ] : null;
    }

    /**
     * Starts an import from the two CSV files, moving them into the plugin's own folder.
     *
     * @throws RuntimeException when the files cannot be used
     */
    public function stage(string $blocksFile, string $locationsFile): void
    {
        $this->check($blocksFile, ['network', 'geoname_id', 'registered_country_geoname_id'], 'the IPv4 blocks file');
        $this->check($locationsFile, ['geoname_id', 'country_iso_code'], 'the locations file');

        $this->cancel();
        $this->ensureDirectory($this->importDir);

        // Copied rather than renamed: the upload folder is often on another disk, where a rename fails.
        if (!copy($blocksFile, $this->importDir . '/blocks.csv') || !copy($locationsFile, $this->importDir . '/locations.csv')) {
            $this->cancel();

            throw new RuntimeException('The uploaded files could not be stored.');
        }

        $this->writeState(0, 0, time());
    }

    /**
     * Takes the two files out of MaxMind's ZIP and starts an import. The entries are found by file name because
     * the ZIP nests them in a dated folder.
     *
     * @throws RuntimeException
     */
    public function stageZip(string $zipFile): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException("This server's PHP has no zip support, so upload the two CSV files from the ZIP instead.");
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('That file could not be opened as a ZIP.');
        }

        $this->ensureDirectory($this->importDir);
        $blocks = $this->importDir . '/incoming-blocks.csv';
        $locations = $this->importDir . '/incoming-locations.csv';

        try {
            $this->extract($zip, 'GeoLite2-Country-Blocks-IPv4.csv', $blocks);
            $this->extract($zip, 'GeoLite2-Country-Locations-en.csv', $locations);
            $this->stage($blocks, $locations);
        } finally {
            $zip->close();
            $this->remove($blocks);
            $this->remove($locations);
        }
    }

    /**
     * Reads the next batch. The rows are appended unsorted to a side file; the last batch sorts them and swaps the
     * finished file in whole, so a lookup never sees a half-built one.
     *
     * @return array{done: bool, imported: int}
     * @throws RuntimeException
     */
    public function step(int $rows = self::BATCH_ROWS): array
    {
        $state = $this->pending();

        if ($state === null) {
            throw new RuntimeException('No import is waiting to continue.');
        }

        $countries = $this->countries($state['locations']);
        $blocks = fopen($state['blocks'], 'rb');
        $building = fopen($this->rangesFile . '.building', $state['offset'] === 0 ? 'wb' : 'ab');

        if ($blocks === false || $building === false) {
            throw new RuntimeException('The import files could not be opened.');
        }

        $header = fgetcsv($blocks, null, ',', '"', '');
        $network = is_array($header) ? array_search('network', $header, true) : false;
        $geoname = is_array($header) ? array_search('geoname_id', $header, true) : false;
        $registered = is_array($header) ? array_search('registered_country_geoname_id', $header, true) : false;

        if ($network === false || $geoname === false || $registered === false) {
            throw new RuntimeException('The IPv4 blocks file is missing columns this import needs.');
        }

        if ($state['offset'] > 0) {
            fseek($blocks, $state['offset']);
        }

        $imported = 0;
        $done = false;

        for ($read = 0; $read < $rows; $read++) {
            $row = fgetcsv($blocks, null, ',', '"', '');

            if ($row === false) {
                $done = true;

                break;
            }

            $country = $countries[trim((string) ($row[$geoname] ?? ''))] ?? $countries[trim((string) ($row[$registered] ?? ''))] ?? null;
            $range = $country === null ? null : $this->range((string) ($row[$network] ?? ''));

            if ($range !== null && $country !== null) {
                fwrite($building, pack(self::PACK, $range[0], $range[1], $country));
                $imported++;
            }
        }

        $offset = (int) ftell($blocks);
        fclose($blocks);
        fclose($building);

        if (!$done) {
            $this->writeState($offset, $state['imported'] + $imported, $state['started']);

            return ['done' => false, 'imported' => $state['imported'] + $imported];
        }

        $this->finish($this->rangesFile . '.building');
        $this->cancel();

        return ['done' => true, 'imported' => $state['imported'] + $imported];
    }

    /** Throws away an unfinished import and its files, keeping the data already loaded. */
    public function cancel(): void
    {
        foreach (['blocks.csv', 'locations.csv', 'state.json'] as $name) {
            $this->remove($this->importDir . '/' . $name);
        }

        $this->remove($this->rangesFile . '.building');
    }

    /** Removes the loaded data, which turns the country breakdown off. */
    public function clear(): void
    {
        $this->cancel();
        $this->remove($this->rangesFile);
    }

    private function remove(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function finish(string $buildingFile): void
    {
        $contents = file_get_contents($buildingFile);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('Nothing usable was found in the file. Check that it is the IPv4 blocks file of the Country edition.');
        }

        // Fixed-width big-endian records sort bytewise into numeric order, so no unpacking is needed.
        $records = str_split($contents, self::RECORD_SIZE);
        unset($contents);
        sort($records, SORT_STRING);

        $sorted = $this->rangesFile . '.sorted';

        if (file_put_contents($sorted, implode('', $records)) === false || !rename($sorted, $this->rangesFile)) {
            throw new RuntimeException('The finished data could not be saved.');
        }
    }

    /**
     * @param array<int, string> $required
     * @throws RuntimeException
     */
    private function check(string $file, array $required, string $what): void
    {
        $handle = is_file($file) ? fopen($file, 'rb') : false;
        $header = $handle === false ? false : fgetcsv($handle, null, ',', '"', '');

        if ($handle !== false) {
            fclose($handle);
        }

        if (!is_array($header) || array_diff($required, $header) !== []) {
            throw new RuntimeException('That does not look like ' . $what . ' from the GeoLite2 Country CSV download.');
        }
    }

    /**
     * @return array<string, string> geoname id => country code
     */
    private function countries(string $file): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The locations file could not be opened.');
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        $id = is_array($header) ? array_search('geoname_id', $header, true) : false;
        $code = is_array($header) ? array_search('country_iso_code', $header, true) : false;
        $map = [];

        while ($id !== false && $code !== false && ($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $geoname = trim((string) ($row[$id] ?? ''));
            $iso = strtoupper(trim((string) ($row[$code] ?? '')));

            if ($geoname !== '' && preg_match('/^[A-Z]{2}\z/', $iso) === 1) {
                $map[$geoname] = $iso;
            }
        }

        fclose($handle);

        return $map;
    }

    /**
     * @return array{0: int, 1: int}|null the first and last address of a plain IPv4 block, null for anything else
     */
    private function range(string $cidr): ?array
    {
        if (preg_match('/^([0-9.]{7,15})\/([0-9]{1,2})\z/', $cidr, $found) !== 1) {
            return null;
        }

        $address = ip2long($found[1]);
        $prefix = (int) $found[2];

        if ($address === false || $prefix > 32) {
            return null;
        }

        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $start = $address & $mask;

        return [$start, $start | (~$mask & 0xFFFFFFFF)];
    }

    /**
     * @param resource $handle
     * @return array{start: int, end: int, country: string}
     */
    private function record($handle, int $index): array
    {
        fseek($handle, $index * self::RECORD_SIZE);

        /** @var array{start: int, end: int, country: string} $record */
        $record = unpack(self::UNPACK, (string) fread($handle, self::RECORD_SIZE));

        return $record;
    }

    private function extract(ZipArchive $zip, string $basename, string $destination): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || basename($name) !== $basename) {
                continue;
            }

            $source = $zip->getStream($name);
            $target = fopen($destination, 'wb');

            if ($source === false || $target === false) {
                throw new RuntimeException('A file in the ZIP could not be read.');
            }

            // Capped, so a ZIP that expands enormously is stopped instead of filling the disk.
            $copied = stream_copy_to_stream($source, $target, self::MAX_UNPACKED_BYTES + 1);
            fclose($source);
            fclose($target);

            if ($copied === false || $copied > self::MAX_UNPACKED_BYTES) {
                throw new RuntimeException('A file in the ZIP is larger than expected.');
            }

            return;
        }

        throw new RuntimeException('The ZIP does not contain ' . $basename . '. Download the CSV format of GeoLite2 Country, not the database format.');
    }

    private function writeState(int $offset, int $imported, int $started): void
    {
        file_put_contents($this->importDir . '/state.json', json_encode(['offset' => $offset, 'imported' => $imported, 'started' => $started], JSON_THROW_ON_ERROR));
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The plugin could not create its storage folder.');
        }
    }
}
