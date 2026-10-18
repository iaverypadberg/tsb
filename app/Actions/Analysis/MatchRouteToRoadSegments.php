<?php

namespace App\Actions\Analysis;

use App\Models\Route;
use Illuminate\Support\Facades\DB;

/**
 * Works out which roads a route runs along.
 *
 * The route is sampled at a fixed spacing and each sample is matched to the
 * nearest road within a short distance whose bearing agrees with the route's.
 * The bearing test is what stops a riverside bike path being mistaken for the
 * highway beside it, and it filters candidates rather than checking the
 * winner, so a cross street at a junction is passed over for the road
 * actually being ridden.
 *
 * Routes exported from Ride with GPS are already snapped to roads, so this
 * is enough; a true map-matcher would be the upgrade if that stops holding.
 */
class MatchRouteToRoadSegments
{
    /**
     * How far apart samples are taken along the route, in metres.
     */
    public const SAMPLE_INTERVAL_M = 25;

    /**
     * How far from a road a sample may sit and still be counted as on it.
     */
    public const MATCH_BUFFER_M = 15;

    /**
     * How far a road's bearing may differ from the route's, in degrees.
     * Compared modulo 180, since riding a road backwards is still riding it.
     */
    public const MAX_BEARING_DELTA_DEGREES = 30;

    /**
     * Bearings are taken over this many metres of line either side of a
     * point, which smooths out the jitter of individual vertices.
     */
    private const BEARING_WINDOW_M = 10.0;

    /**
     * Degrees of latitude per metre, near enough for a bounding box that is
     * deliberately generous. Used only to pre-filter with the spatial index
     * before the exact distance test.
     */
    private const DEGREES_PER_METRE = 1 / 70000.0;

    /**
     * @return list<RouteSample>
     */
    public function handle(Route $route): array
    {
        $rows = DB::select($this->sql(), [
            'route_id' => $route->id,
            'sample_interval' => self::SAMPLE_INTERVAL_M,
            'bearing_window' => self::BEARING_WINDOW_M,
            'box_degrees' => self::MATCH_BUFFER_M * self::DEGREES_PER_METRE,
            'buffer_m' => self::MATCH_BUFFER_M,
            'max_bearing' => self::MAX_BEARING_DELTA_DEGREES,
        ]);

        // Rows come back as plain objects whose shape only the query knows,
        // so they are read as arrays with the conversions made explicit.
        return array_values(array_map(
            function (object $row): RouteSample {
                $values = (array) $row;

                return new RouteSample(
                    fraction: (float) $values['fraction'],
                    roadSegmentId: isset($values['road_segment_id']) ? (int) $values['road_segment_id'] : null,
                    dangerScore: (int) ($values['danger_score'] ?? 0),
                    gapMetres: isset($values['gap_m']) ? (float) $values['gap_m'] : null,
                    roadKey: isset($values['road_key']) ? (string) $values['road_key'] : null,
                );
            },
            $rows,
        ));
    }

    private function sql(): string
    {
        return <<<'SQL'
            with params as (
                select
                    geom,
                    ST_Length(geom::geography) as length_m,
                    greatest(1, (ST_Length(geom::geography) / :sample_interval)::int) as steps
                from routes
                where id = :route_id
            ),
            samples as (
                select
                    step.i::float / p.steps as fraction,
                    ST_LineInterpolatePoint(p.geom, step.i::float / p.steps) as pt,
                    ST_Azimuth(
                        ST_LineInterpolatePoint(p.geom, greatest(0, step.i::float / p.steps - least(0.5, :bearing_window / p.length_m))),
                        ST_LineInterpolatePoint(p.geom, least(1, step.i::float / p.steps + least(0.5, :bearing_window / p.length_m)))
                    ) as route_bearing
                from params p, generate_series(0, (select steps from params)) as step(i)
            )
            select
                smp.fraction,
                match.road_segment_id,
                match.danger_score,
                match.gap_m,
                match.road_key
            from samples smp
            left join lateral (
                select
                    rs.id as road_segment_id,
                    rs.danger_score,
                    ST_Distance(rs.geom::geography, smp.pt::geography) as gap_m,
                    -- what a rider would call this road, used to split one
                    -- flagged stretch from the next
                    coalesce(rs.ref, rs.name, rs.highway_type) as road_key
                from road_segments rs
                cross join lateral (
                    select
                        least(0.5, :bearing_window / nullif(ST_Length(rs.geom::geography), 0)) as window,
                        ST_LineLocatePoint(rs.geom, smp.pt) as position
                ) loc
                cross join lateral (
                    select ST_Azimuth(
                        ST_LineInterpolatePoint(rs.geom, greatest(0, loc.position - loc.window)),
                        ST_LineInterpolatePoint(rs.geom, least(1, loc.position + loc.window))
                    ) as segment_bearing
                ) bearing
                cross join lateral (
                    select abs(degrees(smp.route_bearing) - degrees(bearing.segment_bearing)) as raw
                ) difference
                cross join lateral (
                    select least(difference.raw, 360 - difference.raw) as absolute
                ) shortest
                where rs.geom && ST_Expand(smp.pt, :box_degrees)
                  and ST_DWithin(rs.geom::geography, smp.pt::geography, :buffer_m)
                  and (
                    smp.route_bearing is null
                    or bearing.segment_bearing is null
                    or least(shortest.absolute, 180 - shortest.absolute) <= :max_bearing
                  )
                order by rs.geom <-> smp.pt
                limit 1
            ) match on true
            order by smp.fraction
        SQL;
    }
}
