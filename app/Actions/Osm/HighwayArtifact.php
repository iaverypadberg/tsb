<?php

namespace App\Actions\Osm;

use RuntimeException;

/**
 * The on-disk shape of an `osm_highways` artifact.
 *
 * The artifact exists because `osm:import` shells out to osm2pgsql, which
 * needs a native binary that the Laravel Cloud runtime does not have.
 * Everything downstream of the staging table is plain PHP and SQL, so the
 * import is run where a binary is available — a CI runner — and the staged
 * table is shipped as a file that any environment can load.
 *
 * The file is gzipped NDJSON. The first line is a header describing the
 * export; every line after it is one staged way. Streaming a line at a time
 * keeps both ends bounded in memory, which matters at ~140k ways.
 */
class HighwayArtifact
{
    /**
     * Bumped whenever the header or row shape changes, so a loader refuses a
     * file it would otherwise misread rather than writing nonsense.
     */
    public const FORMAT = 1;

    /**
     * The staging table's tag columns, in the order they are written.
     *
     * Geometry is deliberately absent: it is the one column that is not a
     * plain string, and both ends handle it separately as hex WKB.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'osm_way_id', 'name', 'ref', 'highway', 'maxspeed', 'lanes',
        'shoulder', 'cycleway', 'cycleway_left', 'cycleway_right',
        'cycleway_both', 'bicycle', 'surface', 'oneway', 'access',
        'service_type',
    ];

    /**
     * The table the artifact is read from and written to.
     */
    public const TABLE = 'osm_highways';

    /**
     * Check a header read back off disk, and fail with something a human can
     * act on rather than letting a mismatch surface as a SQL error.
     *
     * @param  mixed  $header  The decoded first line of the artifact.
     * @return array{format: int, generated_at: string, rows: int, source: string, columns: list<string>}
     */
    public static function validateHeader(mixed $header): array
    {
        if (! is_array($header) || ! isset($header['format'], $header['rows'], $header['columns'])) {
            throw new RuntimeException('The artifact has no readable header line.');
        }

        if ($header['format'] !== self::FORMAT) {
            throw new RuntimeException(
                "The artifact is format {$header['format']}, but this build reads format ".self::FORMAT.'.',
            );
        }

        $missing = array_diff(self::COLUMNS, $header['columns']);

        if ($missing !== []) {
            throw new RuntimeException(
                'The artifact is missing the column(s): '.implode(', ', $missing).'.',
            );
        }

        /** @var array{format: int, generated_at: string, rows: int, source: string, columns: list<string>} $header */
        return $header;
    }
}
