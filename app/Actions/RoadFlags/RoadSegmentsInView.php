<?php

namespace App\Actions\RoadFlags;

use App\Models\RoadSegment;
use Illuminate\Support\Facades\DB;

/**
 * The roads inside the map's current view, ready to be clicked on.
 *
 * Only what is on screen is sent, and only once the rider has zoomed in far
 * enough to tell one road from the next: the alternative is shipping a whole
 * region's road network to the browser so somebody can click one bridge.
 */
class RoadSegmentsInView
{
    /**
     * Below this zoom the roads are too crowded to pick from, and there are
     * far too many of them to send.
     */
    public const MIN_ZOOM = 14;

    /**
     * The most roads one view will return. Worst first, so a view busier
     * than this still shows the roads a rider is most likely to flag.
     */
    public const MAX_SEGMENTS = 600;

    /**
     * @return list<array{id: int, label: string, danger: int, coordinates: list<array{float, float}>}>
     */
    public function handle(float $south, float $west, float $north, float $east): array
    {
        $rows = DB::select(<<<'SQL'
            select
                id,
                coalesce(ref, name, highway_type) as label,
                danger_score,
                ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tolerance)) as geojson
            from road_segments
            where geom && ST_MakeEnvelope(:west, :south, :east, :north, 4326)
            order by danger_score desc, id
            limit :limit
        SQL, [
            'tolerance' => RoadSegment::MAP_SIMPLIFY_TOLERANCE_DEGREES,
            'west' => $west,
            'south' => $south,
            'east' => $east,
            'north' => $north,
            'limit' => self::MAX_SEGMENTS,
        ]);

        return array_values(array_map(
            function (object $row): array {
                $values = (array) $row;

                return [
                    'id' => (int) $values['id'],
                    'label' => (string) ($values['label'] ?? 'Road'),
                    'danger' => (int) $values['danger_score'],
                    'coordinates' => RoadSegment::leafletCoordinates($values['geojson']),
                ];
            },
            $rows,
        ));
    }
}
