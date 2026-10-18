<?php

namespace App\Actions\RoadFlags;

use App\Models\RoadFlag;
use App\Models\RoadFlagSegment;
use Illuminate\Support\Facades\DB;

/**
 * The stretches a flag covers, shaped for the map.
 *
 * One query however many ways the flag covers, which matters on the review
 * queue where several flags are drawn at once.
 */
class FlagGeometry
{
    /**
     * @return array{label: string, length_m: int, parts: list<array{id: int, coordinates: list<array{float, float}>}>}
     */
    public function handle(RoadFlag $flag): array
    {
        $rows = DB::select(<<<'SQL'
            select
                fs.road_segment_id as id,
                coalesce(rs.ref, rs.name, rs.highway_type) as label,
                ST_AsGeoJSON(ST_SimplifyPreserveTopology(fs.geom, :tolerance)) as geojson
            from road_flag_segments fs
            join road_segments rs on rs.id = fs.road_segment_id
            where fs.road_flag_id = :flag_id
            order by fs.id
        SQL, [
            'tolerance' => RoadFlagSegment::MAP_SIMPLIFY_TOLERANCE_DEGREES,
            'flag_id' => $flag->id,
        ]);

        $labels = [];
        $parts = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $labels[] = (string) ($values['label'] ?? 'Road');

            $parts[] = [
                'id' => (int) $values['id'],
                'coordinates' => RoadFlagSegment::leafletCoordinates($values['geojson']),
            ];
        }

        return [
            'label' => implode(' · ', array_slice(array_values(array_unique($labels)), 0, 3)),
            'length_m' => $flag->length_m,
            'parts' => $parts,
        ];
    }
}
