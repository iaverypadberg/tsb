<?php

namespace App\Actions\RoadSegments;

use App\Models\SegmentReport;
use Illuminate\Support\Facades\DB;

/**
 * Folds the reports held against a road back into its danger score.
 *
 * `osm:build-segments` does this for every road at import time. This does
 * it for a handful of them, the moment a flag is accepted or withdrawn, so
 * local knowledge shows up without waiting for the next OpenStreetMap run.
 * Both work from `base_danger_score`, so a boost never compounds.
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

        return DB::affectingStatement(<<<'SQL'
            update road_segments rs
            set danger_score = least(100, rs.base_danger_score + (
                    -- least() ignores nulls, so the empty sum has to be
                    -- turned into a nought before it is capped, or a road
                    -- with no reports at all would take the full boost.
                    select least(?, coalesce(sum(reports.severity), 0) * ?)
                    from segment_reports reports
                    where reports.road_segment_id = rs.id
                )),
                updated_at = now()
            where rs.id = any(?::bigint[])
        SQL, [
            SegmentReport::MAX_BOOST,
            SegmentReport::SEVERITY_WEIGHT,
            '{'.implode(',', $ids).'}',
        ]);
    }
}
