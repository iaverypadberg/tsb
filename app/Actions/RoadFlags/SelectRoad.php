<?php

namespace App\Actions\RoadFlags;

use App\Models\RoadSegment;
use Illuminate\Support\Facades\DB;

/**
 * Turns a click into the road a rider means by it, and trims that road down
 * to the stretch they actually want to talk about.
 *
 * OpenStreetMap splits a road wherever a tag changes, so clicking "US 2"
 * lands on one of thirty-five ways. A rider means the road, so the
 * selection walks outwards through every way that touches it and carries
 * the same number or name, and then keeps only the continuous run the
 * clicked way belongs to: parallel carriageways and side branches of the
 * same name are a different piece of road, not this one.
 *
 * That run merges into a single line, which is what makes trimming
 * possible: the two ends the rider drags are fractions along it, and this
 * is where they are turned back into a start and end on each way.
 */
class SelectRoad
{
    /**
     * How close a way has to lie to the merged road to count as part of
     * it, in degrees: about a centimetre.
     */
    private const ON_ROAD_TOLERANCE_DEGREES = 0.0000001;

    public function __construct(private TraceRoad $traceRoad) {}

    /**
     * The whole continuous road through the clicked way.
     */
    public function handle(int $roadSegmentId): RoadSelection
    {
        $segment = RoadSegment::query()->find($roadSegmentId);

        if ($segment === null) {
            return new RoadSelection;
        }

        // A way with neither a number nor a name has nothing to be grown
        // along: matching on highway type alone would select the whole of
        // an unnamed lane network.
        return $this->forSegments(
            ($segment->ref ?? $segment->name) === null
                ? [$roadSegmentId]
                : $this->traceRoad->handle($roadSegmentId),
        );
    }

    /**
     * The same selection built straight from ways already chosen, so a
     * submission covers exactly what the rider was shown.
     *
     * @param  list<int>  $roadSegmentIds
     */
    public function forSegments(array $roadSegmentIds): RoadSelection
    {
        if ($roadSegmentIds === []) {
            return new RoadSelection;
        }

        $rows = DB::select(<<<'SQL'
            select
                id,
                coalesce(ref, name, highway_type) as label,
                0::double precision as start_fraction,
                1::double precision as end_fraction,
                ST_Length(geom::geography)::int as length_m,
                ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tolerance)) as geojson
            from road_segments
            where id = any(:ids::bigint[])
            order by id
        SQL, [
            'tolerance' => RoadSegment::MAP_SIMPLIFY_TOLERANCE_DEGREES,
            'ids' => self::postgresArray($roadSegmentIds),
        ]);

        return $this->selectionFrom($rows, $roadSegmentIds);
    }

    /**
     * The stretch of a road between two fractions along it.
     *
     * The fractions run along the whole road as one merged line, which is
     * what the rider dragged the ends of. Each way the stretch crosses gets
     * its own start and end, so what is stored stays tied to the ways the
     * scoring works in.
     *
     * @param  list<int>  $roadSegmentIds
     */
    public function trim(array $roadSegmentIds, float $start, float $end): RoadSelection
    {
        if ($roadSegmentIds === []) {
            return new RoadSelection;
        }

        [$from, $to] = $start <= $end ? [$start, $end] : [$end, $start];

        $rows = DB::select(<<<'SQL'
            with params as (
                select
                    greatest(0, least(1, :start::double precision)) as trim_start,
                    greatest(0, least(1, :end::double precision)) as trim_end,
                    :on_road::double precision as on_road,
                    :simplify::double precision as simplify
            ),
            ways as (
                select id, geom, coalesce(ref, name, highway_type) as label
                from road_segments
                where id = any(:ids::bigint[])
            ),
            road as (
                select geom from (
                    select (ST_Dump(ST_LineMerge(ST_Collect(geom order by id)))).geom as geom
                    from ways
                ) runs
                order by ST_Length(geom) desc
                limit 1
            ),
            placed as (
                -- where each way starts and ends along the merged road
                select
                    w.id,
                    w.geom,
                    w.label,
                    ST_LineLocatePoint(r.geom, ST_StartPoint(w.geom)) as a,
                    ST_LineLocatePoint(r.geom, ST_EndPoint(w.geom)) as b
                from ways w
                cross join road r
                cross join params p
                where ST_DWithin(r.geom, ST_LineInterpolatePoint(w.geom, 0.5), p.on_road)
            ),
            spans as (
                select id, geom, label, least(a, b) as lo, greatest(a, b) as hi, a > b as reversed
                from placed
                where a <> b
            ),
            cut as (
                select
                    s.id,
                    s.geom,
                    s.label,
                    s.reversed,
                    s.lo,
                    s.hi,
                    greatest(s.lo, p.trim_start) as covered_from,
                    least(s.hi, p.trim_end) as covered_to
                from spans s
                cross join params p
                where least(s.hi, p.trim_end) > greatest(s.lo, p.trim_start)
            ),
            local as (
                -- back from a position along the road to a position along
                -- the way, flipping it for a way drawn against the run
                select
                    id,
                    geom,
                    label,
                    greatest(0, least(1, case
                        when reversed then 1 - (covered_to - lo) / (hi - lo)
                        else (covered_from - lo) / (hi - lo)
                    end)) as start_fraction,
                    greatest(0, least(1, case
                        when reversed then 1 - (covered_from - lo) / (hi - lo)
                        else (covered_to - lo) / (hi - lo)
                    end)) as end_fraction
                from cut
            )
            select
                l.id,
                l.label,
                l.start_fraction,
                l.end_fraction,
                ST_Length(ST_LineSubstring(l.geom, l.start_fraction, l.end_fraction)::geography)::int as length_m,
                ST_AsGeoJSON(
                    ST_SimplifyPreserveTopology(ST_LineSubstring(l.geom, l.start_fraction, l.end_fraction), p.simplify)
                ) as geojson
            from local l
            cross join params p
            where l.end_fraction > l.start_fraction
            order by l.id
        SQL, [
            'start' => $from,
            'end' => $to,
            'on_road' => self::ON_ROAD_TOLERANCE_DEGREES,
            'simplify' => RoadSegment::MAP_SIMPLIFY_TOLERANCE_DEGREES,
            'ids' => self::postgresArray($roadSegmentIds),
        ]);

        return $this->selectionFrom($rows, $roadSegmentIds);
    }

    /**
     * The road as one line, which is what the rider drags the ends of.
     *
     * @param  list<int>  $roadSegmentIds
     * @return list<array{float, float}>
     */
    private function mergedLine(array $roadSegmentIds): array
    {
        $geojson = DB::scalar(<<<'SQL'
            select ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, ?)) from (
                select (ST_Dump(ST_LineMerge(ST_Collect(geom order by id)))).geom as geom
                from road_segments
                where id = any(?::bigint[])
            ) runs
            order by ST_Length(geom) desc
            limit 1
        SQL, [
            RoadSegment::MAP_SIMPLIFY_TOLERANCE_DEGREES,
            self::postgresArray($roadSegmentIds),
        ]);

        return RoadSegment::leafletCoordinates(is_string($geojson) ? $geojson : null);
    }

    /**
     * @param  array<int, object>  $rows  As `DB::select` hands them back.
     * @param  list<int>  $roadSegmentIds
     */
    private function selectionFrom(array $rows, array $roadSegmentIds): RoadSelection
    {
        if ($rows === []) {
            return new RoadSelection;
        }

        return new RoadSelection(
            array_map(
                function (object $row): RoadSelectionPart {
                    $values = (array) $row;

                    return new RoadSelectionPart(
                        roadSegmentId: (int) $values['id'],
                        startFraction: (float) $values['start_fraction'],
                        endFraction: (float) $values['end_fraction'],
                        lengthMetres: (int) $values['length_m'],
                        label: (string) ($values['label'] ?? 'Road'),
                        coordinates: RoadSegment::leafletCoordinates($values['geojson']),
                    );
                },
                array_values($rows),
            ),
            $this->mergedLine($roadSegmentIds),
        );
    }

    /**
     * PDO has no array type, so ids go across as an array literal.
     *
     * @param  list<int>  $ids
     */
    private static function postgresArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
