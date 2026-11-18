<?php

namespace App\Actions\Osm;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads a gzipped NDJSON artifact into the `osm_highways` staging table.
 *
 * This is the half of the import that runs anywhere, including Laravel
 * Cloud: no osm2pgsql, no Docker, no `pg_restore`, and nothing that needs a
 * direct (unpooled) database connection.
 *
 * Rows go down as one JSON parameter per chunk and are unpacked inside
 * Postgres, so the geometry is rebuilt by PostGIS from the WKB the export
 * already produced and is never assembled in PHP. This follows the same
 * shape as `App\Actions\Aadt\StoreAadtSections`.
 *
 * The whole load is one transaction that starts by emptying the table, so
 * the staging table is only ever replaced wholesale: a load that fails
 * part-way leaves the previous contents in place rather than an empty table.
 *
 * Rows are plain inserts rather than an upsert on purpose. The delete means
 * there is nothing to conflict with except a duplicate way inside the
 * artifact itself, and on a migrated table the primary key will reject that
 * loudly — which is the right answer for a corrupt artifact.
 *
 * @see HighwayArtifact for the file's shape.
 */
class LoadStagedHighways
{
    /**
     * How many ways are written per statement.
     */
    private const CHUNK = 500;

    /**
     * @param  string  $path  A gzipped NDJSON artifact.
     * @param  (callable(int): void)|null  $progress  Called with the running row count.
     * @return array{rows: int, header: array{format: int, generated_at: string, rows: int, source: string, columns: list<string>}}
     */
    public function handle(string $path, ?callable $progress = null): array
    {
        $header = $this->readHeader($path);

        $loaded = 0;

        DB::transaction(function () use ($path, $header, $progress, &$loaded): void {
            DB::table(HighwayArtifact::TABLE)->delete();

            $loaded = $this->insertRows($path, $progress);

            if ($loaded !== $header['rows']) {
                throw new RuntimeException(
                    "The artifact header promised {$header['rows']} ways but {$loaded} were read.",
                );
            }
        });

        return ['rows' => $loaded, 'header' => $header];
    }

    /**
     * Read the header and count the rows without touching the database.
     *
     * @return array{rows: int, header: array{format: int, generated_at: string, rows: int, source: string, columns: list<string>}}
     */
    public function inspect(string $path): array
    {
        $header = $this->readHeader($path);

        $rows = 0;

        foreach ($this->rows($path) as $row) {
            $this->validateRow($row, $rows);
            $rows++;
        }

        if ($rows !== $header['rows']) {
            throw new RuntimeException(
                "The artifact header promised {$header['rows']} ways but {$rows} were read.",
            );
        }

        return ['rows' => $rows, 'header' => $header];
    }

    /**
     * @param  (callable(int): void)|null  $progress
     * @return int How many ways were inserted.
     */
    private function insertRows(string $path, ?callable $progress): int
    {
        $loaded = 0;
        $chunk = [];

        foreach ($this->rows($path) as $row) {
            $this->validateRow($row, $loaded);

            $chunk[] = $row;

            if (count($chunk) === self::CHUNK) {
                $loaded += $this->insertChunk($chunk);
                $chunk = [];

                if ($progress !== null) {
                    $progress($loaded);
                }
            }
        }

        if ($chunk !== []) {
            $loaded += $this->insertChunk($chunk);

            if ($progress !== null) {
                $progress($loaded);
            }
        }

        return $loaded;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function insertChunk(array $chunk): int
    {
        $json = json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Could not re-encode a chunk: '.json_last_error_msg());
        }

        return DB::affectingStatement($this->sql(), [$json]);
    }

    /**
     * Stream the artifact a line at a time, skipping the header.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(string $path): \Generator
    {
        $handle = $this->open($path);

        try {
            gzgets($handle);

            $number = 1;

            while (($line = gzgets($handle)) !== false) {
                $number++;

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (! is_array($row)) {
                    throw new RuntimeException("Line {$number} of the artifact is not valid JSON.");
                }

                yield $row;
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * @return array{format: int, generated_at: string, rows: int, source: string, columns: list<string>}
     */
    private function readHeader(string $path): array
    {
        $handle = $this->open($path);

        $line = gzgets($handle);

        gzclose($handle);

        if ($line === false) {
            throw new RuntimeException('The artifact is empty.');
        }

        return HighwayArtifact::validateHeader(json_decode(trim($line), true));
    }

    /**
     * @return resource
     */
    private function open(string $path): mixed
    {
        if (! is_file($path)) {
            throw new RuntimeException("No artifact at [{$path}].");
        }

        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$path}] for reading.");
        }

        return $handle;
    }

    /**
     * Catch a malformed row here rather than letting Postgres reject the
     * whole chunk with no clue which way was at fault.
     *
     * @param  array<string, mixed>  $row
     */
    private function validateRow(array $row, int $index): void
    {
        foreach (['osm_way_id', 'highway', 'geom'] as $required) {
            if (($row[$required] ?? null) === null || $row[$required] === '') {
                throw new RuntimeException("Way at position {$index} has no {$required}.");
            }
        }
    }

    private function sql(): string
    {
        $columns = HighwayArtifact::COLUMNS;

        $selected = implode(",\n                ", array_map(
            fn (string $column): string => $column === 'osm_way_id'
                ? "(way->>'osm_way_id')::bigint as osm_way_id"
                : "way->>'{$column}' as {$column}",
            $columns,
        ));

        return <<<SQL
            insert into osm_highways ({$this->columnList($columns)}, geom)
            select
                {$selected},
                ST_SetSRID(ST_GeomFromWKB(decode(way->>'geom', 'hex')), 4326)
            from jsonb_array_elements(?::jsonb) as element(way)
        SQL;
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnList(array $columns): string
    {
        return implode(', ', $columns);
    }
}
