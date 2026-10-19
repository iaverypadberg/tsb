<?php

namespace App\Actions\Aadt;

use Illuminate\Support\Facades\DB;

/**
 * Writes fetched traffic counts into `aadt_sections`.
 *
 * The rows go down as one JSON parameter per chunk and are unpacked inside
 * Postgres, so the geometry is built by PostGIS from the GeoJSON the service
 * already gave us and never has to be assembled in PHP.
 *
 * Upserted on the year and the section's own milepost range: re-running an
 * import refreshes a year in place, and importing a new year leaves the
 * years already held alone.
 */
class StoreAadtSections
{
    /**
     * How many sections are written per statement.
     */
    private const CHUNK = 500;

    /**
     * Columns taken from the JSON, in the order the insert lists them.
     *
     * Geometry and the timestamps are handled separately: they are the only
     * two that are not a plain cast of one value.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'year' => 'int',
        'layer_id' => 'int',
        'aadt' => 'int',
        'ete_id' => 'text',
        'ete_begin_mm' => 'numeric',
        'ete_end_mm' => 'numeric',
        'begin_mm' => 'numeric',
        'end_mm' => 'numeric',
        'route_name' => 'text',
        'route_num' => 'text',
        'route_type' => 'text',
        'functional_class' => 'text',
        'standard_route_code' => 'text',
        'town_name' => 'text',
        'atr_station' => 'text',
        'atr_status' => 'text',
        'location_error' => 'text',
        'is_divided' => 'boolean',
        'extracted_at' => 'timestamp',
    ];

    /**
     * @param  list<AadtSectionData>  $sections
     * @return int How many sections were written.
     */
    public function handle(array $sections): int
    {
        $rows = $this->deduplicate($sections);

        if ($rows === []) {
            return 0;
        }

        $written = 0;

        DB::transaction(function () use ($rows, &$written): void {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $written += DB::affectingStatement($this->sql(), [json_encode($chunk)]);
            }
        });

        return $written;
    }

    /**
     * Keep one row per section, so a chunk cannot ask Postgres to update the
     * same row twice. The service has never published a duplicate, but a
     * botched publish upstream should not fail the whole import.
     *
     * @param  list<AadtSectionData>  $sections
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $sections): array
    {
        $rows = [];

        foreach ($sections as $section) {
            $rows[implode('|', [$section->year, ...$section->key()])] = $section->toRow();
        }

        return array_values($rows);
    }

    /**
     * Drop sections of one year that this import did not see.
     *
     * Only ever called for a year that has just been imported, so an empty
     * fetch cannot be mistaken for the service having retracted everything.
     *
     * @param  list<array{ete_id: string, ete_begin_mm: float, ete_end_mm: float}>  $keys
     * @return int How many sections were removed.
     */
    public function prune(int $year, array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return DB::affectingStatement(<<<'SQL'
            delete from aadt_sections held
            where held.year = ?
              and not exists (
                select 1
                from jsonb_array_elements(?::jsonb) as element(section)
                where held.ete_id = section->>'ete_id'
                  and held.ete_begin_mm = (section->>'ete_begin_mm')::numeric
                  and held.ete_end_mm = (section->>'ete_end_mm')::numeric
              )
        SQL, [$year, json_encode($keys)]);
    }

    private function sql(): string
    {
        $columns = array_keys(self::COLUMNS);

        $selected = implode(",\n                    ", array_map(
            fn (string $column): string => "(section->>'{$column}')::".self::COLUMNS[$column]." as {$column}",
            $columns,
        ));

        $updated = implode(",\n                    ", array_map(
            fn (string $column): string => "{$column} = excluded.{$column}",
            // The conflict target identifies the row; the rest is refreshed.
            array_values(array_diff($columns, ['year', 'ete_id', 'ete_begin_mm', 'ete_end_mm'])),
        ));

        return <<<SQL
            insert into aadt_sections (
                geom, {$this->columnList($columns)}, created_at, updated_at
            )
            select
                ST_SetSRID(ST_GeomFromGeoJSON(section->>'geojson'), 4326),
                {$selected},
                now(),
                now()
            from jsonb_array_elements(?::jsonb) as element(section)
            on conflict (year, ete_id, ete_begin_mm, ete_end_mm) do update set
                geom = excluded.geom,
                {$updated},
                updated_at = now()
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
