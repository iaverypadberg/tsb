<?php

namespace App\Actions\RoadSegments;

use App\Models\SegmentReport;
use Illuminate\Support\Facades\DB;

/**
 * Folds the reports held against a road, the traffic counted on it, and the
 * crashes recorded on it, back into its danger score.
 *
 * `osm:build-segments` does this for every road at import time. This does
 * it for a handful of them, the moment a flag is accepted or withdrawn, so
 * local knowledge shows up without waiting for the next OpenStreetMap run,
 * and for every road at once after `aadt:match` or `crashes:match` has moved
 * the figures under all of them.
 *
 * All of them work from `base_danger_score`, so a boost never compounds.
 */
class RecalculateDangerScores
{
    /**
     * @param  list<int>  $roadSegmentIds
     * @return int How many roads were rescored.
     */
    public function handle(array $roadSegmentIds): int
    {
        $ids = array_values(array_unique($roadSegmentIds));

        if ($ids === []) {
            return 0;
        }

        return $this->rescore(
            'where rs.id = any(?::bigint[])',
            ['{'.implode(',', $ids).'}'],
        );
    }

    /**
     * Rescore the whole network, for when the traffic counts have moved
     * under every road at once rather than a flag being reviewed on one.
     *
     * @return int How many roads were rescored.
     */
    public function handleAll(): int
    {
        return $this->rescore('', []);
    }

    /**
     * The score is the tags, plus what riders have reported, plus what the
     * traffic counts say, plus what has actually happened on the road — each
     * capped on its own so one signal cannot run away with the total.
     *
     * @param  list<mixed>  $bindings
     */
    private function rescore(string $where, array $bindings): int
    {
        $traffic = ScoreTrafficVolume::sqlExpression('rs.aadt', 'rs.bike_infra', 'rs.highway_type');
        $crashes = ScoreCrashHistory::sqlExpression('rs.crash_count', 'rs.geom');

        return DB::affectingStatement(<<<SQL
            update road_segments rs
            set danger_score = greatest(0, least(100, rs.base_danger_score + (
                    -- least() ignores nulls, so the empty sum has to be
                    -- turned into a nought before it is capped, or a road
                    -- with no reports at all would take the full boost.
                    select least(?, coalesce(sum(reports.severity), 0) * ?)
                    from segment_reports reports
                    where reports.road_segment_id = rs.id
                ) + ({$traffic}) + ({$crashes}))),
                updated_at = now()
            {$where}
        SQL, [
            SegmentReport::MAX_BOOST,
            SegmentReport::SEVERITY_WEIGHT,
            ...$bindings,
        ]);
    }
}
