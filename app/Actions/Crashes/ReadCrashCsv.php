<?php

namespace App\Actions\Crashes;

use Generator;
use RuntimeException;

/**
 * Reads the exported crash CSV one row at a time.
 *
 * Streamed rather than read whole: the published export is capped per
 * request, so the full history arrives as a file of a size nobody controls,
 * and holding 300,000 police reports in memory to insert them in batches of
 * 500 would be a waste of the only limit that actually binds here.
 *
 * Headers are taken from the first line and used as given. The export's
 * column names are the crash report form's wording, spaces and punctuation
 * included, and `CrashData` is where they are mapped to anything else.
 */
class ReadCrashCsv
{
    /**
     * Columns the file has to have before any of it is believed.
     *
     * A file missing these is not a crash export, and failing on the header
     * says so immediately rather than importing thousands of empty rows.
     *
     * @var list<string>
     */
    public const REQUIRED_HEADERS = ['ObjectID', 'Crash Date', 'Coordinates'];

    /**
     * @return Generator<int, array<string, string|null>>
     */
    public function handle(string $path): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        try {
            $headers = $this->headers($handle);

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                // fgetcsv yields [null] for a blank line.
                if ($row === [null]) {
                    continue;
                }

                yield $this->combine($headers, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<string>
     */
    private function headers($handle): array
    {
        $headers = fgetcsv($handle, escape: '');

        if (! is_array($headers) || $headers === [null]) {
            throw new RuntimeException('The file is empty.');
        }

        // Excel writes a byte order mark ahead of the first header, which
        // would otherwise make 'ObjectID' unmatchable.
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

        $headers = array_map(fn (?string $header): string => trim((string) $header), $headers);

        $missing = array_diff(self::REQUIRED_HEADERS, $headers);

        if ($missing !== []) {
            throw new RuntimeException(
                'This does not look like a crash export. Missing columns: '.implode(', ', $missing).'.'
            );
        }

        return $headers;
    }

    /**
     * Pair headers with one row's values.
     *
     * A short row is padded and a long one truncated, so a single ragged
     * line cannot shift every column after it.
     *
     * @param  list<string>  $headers
     * @param  list<string|null>  $row
     * @return array<string, string|null>
     */
    private function combine(array $headers, array $row): array
    {
        $row = array_slice(array_pad($row, count($headers), null), 0, count($headers));

        return array_combine($headers, $row);
    }
}
