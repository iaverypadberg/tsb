<?php

namespace App\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Serves a model's `geom` column to Leaflet.
 *
 * Dense lines are simplified in Postgres first: a 20,000 point track looks
 * identical on screen to its simplified form but is far cheaper to serialise
 * and to draw.
 */
trait HasMappableGeometry
{
    /**
     * Lines with more points than this are simplified before being sent.
     */
    public const MAX_MAP_POINTS = 2_000;

    /**
     * Roughly 5.5 metres of latitude, which is well inside the width of a
     * road and so invisible at any zoom a rider would use.
     */
    public const MAP_SIMPLIFY_TOLERANCE_DEGREES = 0.00005;

    /**
     * The line as [latitude, longitude] pairs, in Leaflet's ordering.
     *
     * @return list<array{float, float}>
     */
    public function coordinatesForMap(): array
    {
        $geojson = DB::scalar(
            sprintf(
                'select ST_AsGeoJSON(
                    case when ST_NPoints(geom) > ?
                        then ST_SimplifyPreserveTopology(geom, ?)
                        else geom
                    end
                ) from %s where id = ?',
                $this->getTable(),
            ),
            [self::MAX_MAP_POINTS, self::MAP_SIMPLIFY_TOLERANCE_DEGREES, $this->getKey()],
        );

        if (! is_string($geojson)) {
            return [];
        }

        /** @var array{coordinates?: list<array{float, float}>} $decoded */
        $decoded = json_decode($geojson, true) ?: [];

        // GeoJSON orders coordinates longitude first; Leaflet wants latitude first.
        return array_map(
            fn (array $pair): array => [$pair[1], $pair[0]],
            $decoded['coordinates'] ?? [],
        );
    }
}
