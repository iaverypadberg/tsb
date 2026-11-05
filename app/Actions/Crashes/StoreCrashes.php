<?php

namespace App\Actions\Crashes;

use Illuminate\Support\Facades\DB;

/**
 * Writes parsed crash rows into `crashes`.
 *
 * The rows go down as one JSON parameter per chunk and are unpacked inside
 * Postgres, the same way the traffic counts are, so a file of any size is
 * written in a predictable number of statements instead of one per row.
 *
 * Upserted on the export's own `object_id`: re-importing the same file
 * refreshes it in place, and importing an overlapping export updates the
 * rows it shares rather than duplicating them.
 *
 * `road_segment_id` is deliberately never written here. It is `crashes:match`
 * that works out which road a crash sits on, and an import must not undo
 * that just because the file was loaded again.
 */
class StoreCrashes
{
    /**
     * How many crashes are written per statement.
     */
    private const CHUNK = 500;

    /**
     * Columns taken from the JSON, in the order the insert lists them.
     *
     * Geometry and the timestamps are handled separately: they are the only
     * ones that are not a plain cast of one value.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'object_id' => 'bigint',
        'occurred_at' => 'timestamp',
        'aot_milepoint' => 'numeric',
        'road_group_id' => 'int',
        'crash_type' => 'text',
        'involving' => 'text',
        'town' => 'text',
        'address' => 'text',
        'street_address' => 'text',
        'non_reportable_address' => 'text',
        'aot_route' => 'text',
        'aot_route_id' => 'text',
        'collision_direction' => 'text',
        'weather' => 'text',
        'road_group' => 'text',
        'report_number' => 'text',
        'reporting_agency' => 'text',
        'reporting_agency_id' => 'text',
        'animal' => 'text',
        'time_of_day' => 'text',
        'intersection_with' => 'text',
        'impairment' => 'text',
        'road_characteristics' => 'text',
        'road_condition' => 'text',
        'surface_condition' => 'text',
        'mapped' => 'text',
    ];

    /**
     * @param  list<CrashData>  $crashes
     * @return int How many crashes were written.
     */
    public function handle(array $crashes): int
    {
        $rows = $this->deduplicate($crashes);

        if ($rows === []) {
            return 0;
        }

        $written = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $written += DB::affectingStatement($this->sql(), [json_encode($chunk)]);
        }

        return $written;
    }

    /**
     * Keep one row per `object_id`, so a chunk cannot ask Postgres to update
     * the same row twice in one statement, which it refuses to do.
     *
     * @param  list<CrashData>  $crashes
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $crashes): array
    {
        $rows = [];

        foreach ($crashes as $crash) {
            $rows[$crash->objectId] = $crash->toRow();
        }

        return array_values($rows);
    }

    private function sql(): string
    {
        $columns = array_keys(self::COLUMNS);

        $selected = implode(",\n                ", array_map(
            fn (string $column): string => "(crash->>'{$column}')::".self::COLUMNS[$column]." as {$column}",
            $columns,
        ));

        $updated = implode(",\n                ", array_map(
            fn (string $column): string => "{$column} = excluded.{$column}",
            array_values(array_diff($columns, ['object_id'])),
        ));

        return <<<SQL
            insert into crashes (
                geom, {$this->columnList($columns)}, created_at, updated_at
            )
            select
                case
                    when crash->>'geojson' is null then null
                    else ST_SetSRID(ST_GeomFromGeoJSON(crash->>'geojson'), 4326)
                end,
                {$selected},
                now(),
                now()
            from jsonb_array_elements(?::jsonb) as element(crash)
            on conflict (object_id) do update set
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
