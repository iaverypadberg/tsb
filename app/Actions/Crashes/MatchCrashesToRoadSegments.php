<?php

namespace App\Actions\Crashes;

use App\Actions\RoadSegments\RecalculateDangerScores;
use App\Models\Crash;
use Illuminate\Support\Facades\DB;

/**
 * Works out which road each crash happened on, and totals them per road.
 *
 * A crash is published as a single point, so unlike a traffic count it
 * belongs to at most one road: the matching is a nearest neighbour search
 * rather than the sampling `MatchAadtToRoadSegments` has to do.
 *
 * Distance is measured in Vermont State Plane rather than on the sphere, so
 * the units are already metres and the spatial index is usable. Transforming
 * geometry inside the join instead would make the index useless.
 *
 * ## What the buffer has to trade off
 *
 * Reported coordinates are a dispatcher's best placing of an incident, not a
 * survey. Too tight a buffer and crashes on the road fail to match at all;
 * too loose and a crash on a main road is attributed to the side street it
 * happened near. {@see self::MATCH_BUFFER_M} is the compromise, and
 * `match_distance_m` is stored for every match so a surprising one can be
 * examined rather than merely trusted.
 *
 * Crashes at a junction are the known hard case. One genuinely involves both
 * roads, and it is given to the nearer of them: splitting it across both
 * would count it twice, and roads meeting at a junction are usually close
 * enough in danger terms for the choice not to change a rider's decision.
 */
class MatchCrashesToRoadSegments
{
    /**
     * Vermont State Plane, in metres: the projection distances are taken in.
     */
    public const PROJECTED_SRID = 32145;

    /**
     * How far from a road a crash may sit and still be counted as on it.
     *
     * Wider than the traffic matcher's 20 m because a crash is a point
     * placed by a reporting officer, not a line drawn along a centreline,
     * and because a crash in a junction or on a verge is still a crash on
     * that road.
     */
    public const MATCH_BUFFER_M = 30;

    public function __construct(private RecalculateDangerScores $recalculate) {}

    /**
     * Match every placeable crash, then total them onto the roads.
     *
     * @return array{crashes: int, matched: int, unplaceable: int, unmatched: int, roads: int, rescored: int}
     */
    public function handle(): array
    {
        $this->projectCrashes();
        $this->projectRoadSegments();

        $matched = DB::transaction(function (): int {
            $this->clearPreviousMatches();

            return $this->recordMatches();
        });

        $roads = $this->recordTotals();

        $this->dropScratchTables();

        $counts = DB::selectOne(<<<'SQL'
            select
                count(*) as crashes,
                count(*) filter (where geom is null) as unplaceable,
                count(*) filter (where geom is not null and road_segment_id is null) as unmatched
            from crashes
        SQL);

        return [
            'crashes' => (int) ($counts->crashes ?? 0),
            'matched' => $matched,
            'unplaceable' => (int) ($counts->unplaceable ?? 0),
            'unmatched' => (int) ($counts->unmatched ?? 0),
            'roads' => $roads,
            // A crash total that moved changes the danger score of the road
            // under it, and clearing the previous run's matches changes the
            // roads that no longer have any, so everything is rescored.
            'rescored' => $this->recalculate->handleAll(),
        ];
    }

    private function projectCrashes(): void
    {
        DB::statement('drop table if exists crash_match_points');

        DB::statement(<<<'SQL'
            create unlogged table crash_match_points as
            select
                id as crash_id,
                ST_Transform(geom, ?::int) as geom
            from crashes
            where geom is not null
        SQL, [self::PROJECTED_SRID]);

        DB::statement('create index on crash_match_points using gist (geom)');
        DB::statement('analyze crash_match_points');
    }

    /**
     * Only the roads a crash could plausibly belong to, which keeps the
     * nearest neighbour search off the 86,000 ways nothing happened near.
     */
    private function projectRoadSegments(): void
    {
        DB::statement('drop table if exists crash_match_ways');

        DB::statement(<<<'SQL'
            create unlogged table crash_match_ways as
            select
                segments.id as road_segment_id,
                projected.geom
            from road_segments segments
            cross join lateral (select ST_Transform(segments.geom, ?::int) as geom) projected
            where exists (
                select 1
                from crash_match_points points
                where ST_DWithin(points.geom, projected.geom, ?::float)
            )
        SQL, [self::PROJECTED_SRID, self::MATCH_BUFFER_M]);

        DB::statement('create index on crash_match_ways using gist (geom)');
        DB::statement('analyze crash_match_ways');
    }

    /**
     * Forget what an earlier run concluded, so a crash whose road has gone,
     * or which no longer reaches one, loses its match rather than keeping a
     * road nothing supports.
     */
    private function clearPreviousMatches(): void
    {
        DB::statement(<<<'SQL'
            update crashes
            set road_segment_id = null, match_distance_m = null, updated_at = now()
            where road_segment_id is not null or match_distance_m is not null
        SQL);
    }

    /**
     * Give each crash the nearest road within the buffer.
     *
     * `<->` against the GiST index walks the roads in distance order, so the
     * `limit 1` stops at the first one instead of measuring them all.
     */
    private function recordMatches(): int
    {
        return DB::affectingStatement(<<<'SQL'
            update crashes
            set road_segment_id = nearest.road_segment_id,
                match_distance_m = round(nearest.distance::numeric, 1),
                updated_at = now()
            from (
                select
                    points.crash_id,
                    match.road_segment_id,
                    match.distance
                from crash_match_points points
                cross join lateral (
                    select
                        ways.road_segment_id,
                        ST_Distance(ways.geom, points.geom) as distance
                    from crash_match_ways ways
                    where ST_DWithin(ways.geom, points.geom, ?::float)
                    order by ways.geom <-> points.geom
                    limit 1
                ) match
            ) nearest
            where crashes.id = nearest.crash_id
        SQL, [self::MATCH_BUFFER_M]);
    }

    /**
     * Total the matched crashes onto each road.
     *
     * Every road is written, not only the ones with a crash, so a road that
     * has lost its last match falls back to nought instead of keeping a
     * total from a previous run.
     */
    private function recordTotals(): int
    {
        return DB::affectingStatement(<<<'SQL'
            update road_segments segments
            set crash_count = coalesce(totals.crashes, 0),
                crash_injury_count = coalesce(totals.injuries, 0),
                crash_fatal_count = coalesce(totals.fatalities, 0),
                crash_first_year = totals.first_year,
                crash_last_year = totals.last_year,
                updated_at = now()
            from (
                select
                    segments.id as road_segment_id,
                    count(crashes.id) as crashes,
                    count(crashes.id) filter (where crashes.crash_type = any(?::text[])) as injuries,
                    count(crashes.id) filter (where crashes.crash_type = ?) as fatalities,
                    min(extract(year from crashes.occurred_at))::int as first_year,
                    max(extract(year from crashes.occurred_at))::int as last_year
                from road_segments segments
                left join crashes on crashes.road_segment_id = segments.id
                group by segments.id
            ) totals
            where segments.id = totals.road_segment_id
              and (
                segments.crash_count <> coalesce(totals.crashes, 0)
                or segments.crash_injury_count <> coalesce(totals.injuries, 0)
                or segments.crash_fatal_count <> coalesce(totals.fatalities, 0)
                or segments.crash_first_year is distinct from totals.first_year
                or segments.crash_last_year is distinct from totals.last_year
              )
        SQL, [
            '{'.implode(',', array_map(
                fn (string $type): string => '"'.$type.'"',
                Crash::HARM_TYPES,
            )).'}',
            Crash::TYPE_FATAL,
        ]);
    }

    private function dropScratchTables(): void
    {
        foreach (['crash_match_points', 'crash_match_ways'] as $table) {
            DB::statement("drop table if exists {$table}");
        }
    }
}
