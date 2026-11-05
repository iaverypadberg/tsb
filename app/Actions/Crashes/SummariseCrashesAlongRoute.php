<?php

namespace App\Actions\Crashes;

use App\Models\Crash;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

/**
 * Totals the reported crashes beside each stretch of a route's traffic
 * profile.
 *
 * The traffic profile says how busy a road is. This says what has happened
 * on it, which is the question a busy count raises and cannot answer: 14,000
 * vehicles a day on a road with a shoulder and nothing recorded is not the
 * same ride as 14,000 on a road where two people on bicycles have been hurt.
 *
 * ## Why it is measured against the ridden line
 *
 * `crashes.road_segment_id` already names the road each crash was matched
 * to, but a traffic stretch does not record the roads it was built from —
 * it is cut out of the route line itself — so there is no segment list to
 * join on. Measuring to the line is also the narrower claim of the two: a
 * crash on a 9 km way counts against the 300 m of it this route rides, not
 * against the whole way.
 *
 * ## Why it is counted at display time
 *
 * The totals are not denormalised onto `traffic_sections` because they move
 * independently of the profile: an import brings in a year of crashes
 * without any route being re-ridden or re-profiled. Counting them when the
 * page is drawn means a freshly imported export shows up on every route at
 * once, and it is one indexed query against a few dozen stretches.
 */
class SummariseCrashesAlongRoute
{
    /**
     * How far from the ridden line a crash may sit and still be counted.
     *
     * Matched to {@see MatchCrashesToRoadSegments::MATCH_BUFFER_M}, for the
     * same reason: a reported position is an officer's placing of an
     * incident, and a crash in the junction or on the verge is still a crash
     * on that road. It does mean a crash on a road running alongside, within
     * 30 m of this one, is counted against both. That is the honest reading
     * of positions placed to this accuracy, and erring the other way would
     * drop real crashes off the roads they happened on.
     */
    public const BUFFER_M = 30;

    /**
     * Degrees of latitude per metre, near enough for a bounding box that is
     * deliberately generous. Used only to pre-filter with the spatial index
     * before the exact distance test.
     */
    private const DEGREES_PER_METRE = 1 / 70000.0;

    /**
     * What has been reported beside each of the route's traffic stretches,
     * keyed by stretch, with the stretches nothing was reported on left out.
     *
     * @return array<int, array{total: int, fatal: int, injury: int, bicycle: int, pedestrian: int}>
     */
    public function handle(Route $route): array
    {
        $rows = DB::select($this->sql(), [
            'route_id' => $route->id,
            'box_degrees' => self::BUFFER_M * self::DEGREES_PER_METRE,
            'buffer_m' => self::BUFFER_M,
            'fatal' => Crash::TYPE_FATAL,
            'injury' => Crash::TYPE_INJURY,
            'bicycle' => Crash::INVOLVING_BICYCLE,
            'pedestrian' => Crash::INVOLVING_PEDESTRIAN,
        ]);

        $summaries = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $summaries[(int) $values['section_id']] = [
                'total' => (int) $values['total'],
                'fatal' => (int) $values['fatal'],
                'injury' => (int) $values['injury'],
                'bicycle' => (int) $values['bicycle'],
                'pedestrian' => (int) $values['pedestrian'],
            ];
        }

        return $summaries;
    }

    private function sql(): string
    {
        return <<<'SQL'
            select
                sections.id as section_id,
                count(*) as total,
                count(*) filter (where crashes.crash_type = :fatal) as fatal,
                count(*) filter (where crashes.crash_type = :injury) as injury,
                count(*) filter (where crashes.involving = :bicycle) as bicycle,
                count(*) filter (where crashes.involving = :pedestrian) as pedestrian
            from traffic_sections sections
            join crashes
                on crashes.geom && ST_Expand(sections.geom, :box_degrees)
                and ST_DWithin(sections.geom::geography, crashes.geom::geography, :buffer_m)
            where sections.route_id = :route_id
            group by sections.id
        SQL;
    }
}
