<?php

namespace App\Actions\Osm;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Writes the `osm_highways` staging table out as a gzipped NDJSON artifact.
 *
 * Read in chunks and written a line at a time, so the whole table is never
 * resident: the geometry alone runs to tens of megabytes as hex WKB.
 *
 * @see HighwayArtifact for the file's shape.
 */
class ExportStagedHighways
{
    /**
     * How many staged ways are read per query.
     */
    private const CHUNK = 2000;

    /**
     * @param  string  $path  Where to write the artifact.
     * @param  string  $source  What the staged ways were imported from, recorded in the header.
     * @param  (callable(int): void)|null  $progress  Called with the running row count.
     * @return array{rows: int, bytes: int, path: string}
     */
    public function handle(string $path, string $source, ?callable $progress = null): array
    {
        $expected = DB::table(HighwayArtifact::TABLE)->count();

        if ($expected === 0) {
            throw new RuntimeException(
                HighwayArtifact::TABLE.' is empty. Run `php artisan osm:import` first.',
            );
        }

        File::ensureDirectoryExists(dirname($path));

        $handle = gzopen($path, 'wb6');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$path}] for writing.");
        }

        try {
            $written = $this->write($handle, $expected, $source, $progress);
        } catch (\Throwable $exception) {
            gzclose($handle);
            File::delete($path);

            throw $exception;
        }

        gzclose($handle);

        // A short read means the table changed under us, or a chunk was
        // silently dropped. Either way the artifact is not a snapshot.
        if ($written !== $expected) {
            File::delete($path);

            throw new RuntimeException("Wrote {$written} ways but the table holds {$expected}.");
        }

        return [
            'rows' => $written,
            'bytes' => (int) filesize($path),
            'path' => $path,
        ];
    }

    /**
     * @param  resource  $handle
     * @param  (callable(int): void)|null  $progress
     * @return int How many ways were written.
     */
    private function write(mixed $handle, int $expected, string $source, ?callable $progress): int
    {
        $this->writeLine($handle, [
            'format' => HighwayArtifact::FORMAT,
            'generated_at' => now()->toIso8601String(),
            'source' => $source,
            'rows' => $expected,
            'columns' => [...HighwayArtifact::COLUMNS, 'geom'],
        ]);

        $written = 0;

        $this->stagedHighways()->chunkById(self::CHUNK, function ($ways) use ($handle, &$written, $progress): void {
            foreach ($ways as $way) {
                $this->writeLine($handle, (array) $way);
                $written++;
            }

            if ($progress !== null) {
                $progress($written);
            }
        }, 'osm_way_id');

        return $written;
    }

    /**
     * Geometry is handed over as hex WKB: PostGIS encodes it, the loader
     * hands it straight back to PostGIS, and PHP never parses geometry.
     */
    private function stagedHighways(): Builder
    {
        return DB::table(HighwayArtifact::TABLE)->select([
            ...HighwayArtifact::COLUMNS,
            DB::raw("encode(ST_AsBinary(geom), 'hex') as geom"),
        ]);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $row
     */
    private function writeLine(mixed $handle, array $row): void
    {
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            throw new RuntimeException('Could not encode a row: '.json_last_error_msg());
        }

        if (gzwrite($handle, $line."\n") === false) {
            throw new RuntimeException('Could not write to the artifact.');
        }
    }
}
