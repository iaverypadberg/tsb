<?php

namespace App\Actions\RoadFlags;

use Illuminate\Support\Facades\DB;

/**
 * Follows a road from the way a rider clicked to both of its ends.
 *
 * Collecting every touching way of the same name is not enough: a slip
 * road, a spur, or the other carriageway carries the same name, and taking
 * all of them gives a rider a selection in pieces rather than a road. What
 * makes a road one road is going straight on — so at each junction this
 * carries on into whichever way continues in the direction of travel, and
 * stops when nothing does.
 *
 * Done here rather than in SQL because a recursive CTE cannot make a choice
 * per step: it would have to take every branch and sort it out afterwards.
 */
class TraceRoad
{
    /**
     * How far the road may bend at a junction and still be the same road,
     * in degrees. A sharp enough turn is a different road wearing the same
     * name.
     */
    private const MAX_TURN_DEGREES = 60.0;

    /**
     * How far from the clicked way the search may reach, in degrees of
     * latitude: roughly 20 km.
     */
    private const SEARCH_BOX_DEGREES = 0.2;

    /**
     * The most ways one road may be traced through.
     */
    public const MAX_SEGMENTS = 250;

    /**
     * How close two ways have to be to share a junction, in degrees: about
     * a centimetre. Ways imported from OpenStreetMap share their end nodes
     * exactly, but a road should not stop being one road over a rounding
     * error.
     */
    private const JOIN_TOLERANCE_DEGREES = 0.0000001;

    /**
     * Coordinates are rounded to this many decimal places to decide
     * whether two ways meet, which is the same centimetre as above.
     */
    private const NODE_PRECISION = 7;

    /**
     * Bearings are taken over this many metres of line, which smooths out
     * the jitter of individual vertices, as the route matcher does.
     */
    private const BEARING_WINDOW_M = 10.0;

    /**
     * Every way of the road running through the clicked one, in order.
     *
     * @return list<int>
     */
    public function handle(int $roadSegmentId): array
    {
        $ways = $this->candidates($roadSegmentId);

        if (! isset($ways[$roadSegmentId])) {
            return [];
        }

        $junctions = $this->junctions($ways);
        $clicked = $ways[$roadSegmentId];

        // Out to one end, then out to the other, and the clicked way joins
        // the two halves in the middle.
        $backwards = $this->follow($ways, $junctions, $clicked, towardsNode: $clicked['start_node']);
        $forwards = $this->follow($ways, $junctions, $clicked, towardsNode: $clicked['end_node']);

        return [...array_reverse($backwards), $roadSegmentId, ...$forwards];
    }

    /**
     * Walk one way at a time away from the clicked way, taking the
     * straightest continuation at each junction.
     *
     * @param  array<int, array<string, mixed>>  $ways
     * @param  array<string, list<int>>  $junctions
     * @param  array<string, mixed>  $way
     * @return list<int>
     */
    private function follow(array $ways, array $junctions, array $way, string $towardsNode): array
    {
        $chain = [];
        $visited = [$way['id'] => true];

        while (count($chain) < self::MAX_SEGMENTS) {
            $heading = $this->headingLeaving($way, $towardsNode);

            if ($heading === null) {
                break;
            }

            $next = $this->straightestAt($ways, $junctions, $towardsNode, $heading, $visited);

            if ($next === null) {
                break;
            }

            $chain[] = $next['id'];
            $visited[$next['id']] = true;

            $way = $next;
            $towardsNode = $next['start_node'] === $towardsNode ? $next['end_node'] : $next['start_node'];
        }

        return $chain;
    }

    /**
     * The way leaving a junction that bends least away from the direction
     * of travel arriving at it.
     *
     * @param  array<int, array<string, mixed>>  $ways
     * @param  array<string, list<int>>  $junctions
     * @param  array<int, bool>  $visited
     * @return array<string, mixed>|null
     */
    private function straightestAt(array $ways, array $junctions, string $node, float $heading, array $visited): ?array
    {
        $best = null;
        $straightest = self::MAX_TURN_DEGREES;

        foreach ($junctions[$node] ?? [] as $candidateId) {
            if (isset($visited[$candidateId])) {
                continue;
            }

            $candidate = $ways[$candidateId];
            $leaving = $this->headingLeaving($candidate, $node);

            if ($leaving === null) {
                continue;
            }

            $turn = $this->turnBetween($heading, $leaving);

            if ($turn <= $straightest) {
                $straightest = $turn;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Which way a road points as it leaves one of its own ends.
     *
     * @param  array<string, mixed>  $way
     */
    private function headingLeaving(array $way, string $node): ?float
    {
        $bearing = match ($node) {
            $way['start_node'] => $way['bearing_at_start'],
            $way['end_node'] => $way['bearing_at_end'],
            default => null,
        };

        return is_float($bearing) ? $bearing : null;
    }

    /**
     * How far a road turns, in degrees, between arriving at a junction
     * along one heading and leaving along another.
     *
     * The heading a way leaves a junction on points away from it, so
     * carrying straight on reverses the direction of travel: a turn of
     * nothing at all comes out as 180.
     */
    private function turnBetween(float $arriving, float $leaving): float
    {
        $difference = abs(rad2deg($arriving) - rad2deg($leaving));

        return abs(180 - min($difference, 360 - $difference));
    }

    /**
     * Which ways meet at each junction.
     *
     * @param  array<int, array<string, mixed>>  $ways
     * @return array<string, list<int>>
     */
    private function junctions(array $ways): array
    {
        $junctions = [];

        foreach ($ways as $way) {
            $junctions[$way['start_node']][] = $way['id'];
            $junctions[$way['end_node']][] = $way['id'];
        }

        return $junctions;
    }

    /**
     * Every way that touches the clicked one, directly or through others,
     * and carries the same number or name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidates(int $roadSegmentId): array
    {
        $rows = DB::select(sprintf(<<<'SQL'
            with recursive clicked as (
                select id, geom, coalesce(ref, name) as road_key
                from road_segments
                where id = :segment_id
            ),
            walk as (
                select c.id, c.geom
                from clicked c
              union
                select nxt.id, nxt.geom
                from walk w
                join road_segments nxt
                  on nxt.id <> w.id
                 and coalesce(nxt.ref, nxt.name) = (select road_key from clicked)
                 and nxt.geom && ST_Expand((select geom from clicked), :box_degrees)
                 and ST_DWithin(nxt.geom, w.geom, :join_tolerance)
            ),
            capped as (
                select id, geom from walk order by id limit :max_segments
            )
            select
                w.id,
                round(ST_Y(ST_StartPoint(w.geom))::numeric, %1$d) || ',' ||
                    round(ST_X(ST_StartPoint(w.geom))::numeric, %1$d) as start_node,
                round(ST_Y(ST_EndPoint(w.geom))::numeric, %1$d) || ',' ||
                    round(ST_X(ST_EndPoint(w.geom))::numeric, %1$d) as end_node,
                -- the direction the way sets off in from each of its ends,
                -- taken over a short run of line so one stray vertex cannot
                -- swing it
                ST_Azimuth(
                    ST_StartPoint(w.geom),
                    ST_LineInterpolatePoint(w.geom, least(0.5, %2$F / greatest(ST_Length(w.geom::geography), 1)))
                ) as bearing_at_start,
                ST_Azimuth(
                    ST_EndPoint(w.geom),
                    ST_LineInterpolatePoint(w.geom, 1 - least(0.5, %2$F / greatest(ST_Length(w.geom::geography), 1)))
                ) as bearing_at_end
            from capped w
            order by w.id
        SQL, self::NODE_PRECISION, self::BEARING_WINDOW_M), [
            'segment_id' => $roadSegmentId,
            'box_degrees' => self::SEARCH_BOX_DEGREES,
            'join_tolerance' => self::JOIN_TOLERANCE_DEGREES,
            'max_segments' => self::MAX_SEGMENTS,
        ]);

        $ways = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $id = (int) $values['id'];

            $ways[$id] = [
                'id' => $id,
                'start_node' => (string) $values['start_node'],
                'end_node' => (string) $values['end_node'],
                'bearing_at_start' => isset($values['bearing_at_start']) ? (float) $values['bearing_at_start'] : null,
                'bearing_at_end' => isset($values['bearing_at_end']) ? (float) $values['bearing_at_end'] : null,
            ];
        }

        return $ways;
    }
}
