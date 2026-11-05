<?php

namespace App\Actions\RoadSegments;

use App\Actions\Aadt\MatchAadtToRoadSegments;
use App\Models\RoadSegment;

/**
 * Turns a measured traffic volume into points on a road's danger score.
 *
 * `InterpretOsmHighway` says it outright: what makes a road dangerous is the
 * traffic on it, and OpenStreetMap does not record traffic volume. Road class
 * and a route number are the proxies it falls back on. Where VTrans has
 * actually counted a road, this corrects that guess with the real figure.
 *
 * ## Why a logarithm
 *
 * Annual Average Daily Traffic is heavily right skewed. Across the roads
 * matched to a count it runs from 46 to 43,274 vehicles a day, with a median
 * of 3,965 and a mean of 5,727; half the measured network sits under 4,000
 * while a thin tail reaches ten times that.
 *
 * Scaled linearly, that tail eats the whole range. Everything below about
 * 10,000 would land within a point or two of everything else, and the
 * difference between a lane carrying 100 vehicles a day and a road carrying
 * 2,000 — which is the difference a rider cares about most — would vanish.
 *
 * So the figure is read in doublings rather than in vehicles. Every time the
 * traffic doubles the score moves by the same amount, which keeps the tails
 * apart at both ends: 250 against 500 vehicles a day separates exactly as
 * much as 20,000 against 40,000.
 *
 * ## Why it is signed
 *
 * The adjustment is a correction to a guess, not a penalty on top of one, so
 * it cuts both ways: a road busier than {@see self::PIVOT_AADT} gains points
 * and a quieter one loses them.
 *
 * That matters because the class baselines are already high for the roads
 * most likely to be counted. A trunk road averages 94 before anything is
 * added. Measured against the real counts, a one-way penalty pinned 2,444 of
 * the 8,976 measured roads to the 100 ceiling and left the scores *less*
 * spread out than they started (a standard deviation of 27.7 against 28.9);
 * signing it lifts that to 31.1. A penalty alone would have destroyed the
 * discrimination at the top end that reading the figure as doublings was
 * meant to buy.
 */
class ScoreTrafficVolume
{
    /**
     * The traffic volume a road is assumed to carry when nothing has
     * measured it, and so the volume that earns no adjustment either way.
     *
     * Set at the median of the measured network, 3,965 vehicles a day,
     * rounded. A road counted at this is exactly what its tags suggested.
     */
    public const PIVOT_AADT = 4000;

    /**
     * How many points the score moves for each doubling or halving of
     * traffic away from the pivot.
     */
    public const POINTS_PER_DOUBLING = 5;

    /**
     * How far the adjustment may run in either direction.
     *
     * Four doublings, so it saturates at 250 and 64,000 vehicles a day,
     * either side of anything Vermont publishes. The cap is there to stop a
     * single surprising count rewriting a road wholesale, not to bind in
     * normal use.
     */
    public const MAX_ADJUSTMENT = 20;

    /**
     * Road classes whose matched count is not the traffic a rider on them is
     * exposed to.
     *
     * A rail trail, a sidepath or a frontage road runs alongside a highway
     * for miles on the same bearing, well inside the buffer the matcher has
     * to allow for a section drawn down a centreline. Until the matching
     * refused them outright, the counts showed it: the measured `cycleway`
     * segments had a median AADT of 11,455 and reached 42,346, which was the
     * adjacent highway's figure read off verbatim rather than anything on
     * the path.
     *
     * {@see MatchAadtToRoadSegments::NOT_ON_COUNTED_NETWORK}
     * is where that is now stopped, so none of these should arrive carrying
     * a count at all. The exclusion stands on its own all the same, because
     * a path physically separated from a road is not exposed to that road's
     * traffic however much of it there is: a figure that reached one by some
     * other route would still not be the traffic its rider is in.
     *
     * @var list<string>
     */
    public const SEPARATED_FROM_COUNTED_TRAFFIC = [
        'cycleway', 'path', 'bridleway', 'track', 'service', 'living_street',
    ];

    /**
     * How many points to add to a road's score for the traffic on it.
     *
     * Returns 0 for an unmeasured road, which is not the same as a quiet one:
     * coverage is partial by design and silence says nothing either way.
     */
    public function handle(?int $aadt, string $bikeInfra = RoadSegment::INFRA_NONE, ?string $highwayType = null): int
    {
        if ($aadt === null || $aadt <= 0) {
            return 0;
        }

        if ($bikeInfra === RoadSegment::INFRA_PATH) {
            return 0;
        }

        if (in_array($highwayType, self::SEPARATED_FROM_COUNTED_TRAFFIC, true)) {
            return 0;
        }

        $doublings = log($aadt / self::PIVOT_AADT, 2);

        return (int) round(max(
            -self::MAX_ADJUSTMENT,
            min(self::MAX_ADJUSTMENT, self::POINTS_PER_DOUBLING * $doublings),
        ));
    }

    /**
     * The same calculation as SQL, for the bulk rescoring paths.
     *
     * Every road has to be rescored whenever the counts are rematched, and
     * `osm:build-segments` rescores the whole network in one statement, so
     * the arithmetic has to exist in Postgres too. It lives here rather than
     * at either call site so the constants above remain the only place any of
     * these numbers are written down.
     *
     * Takes column expressions rather than a table alias because
     * `osm:build-segments` reads the count off the stored road while taking
     * the class and bike provision from the incoming extract.
     */
    public static function sqlExpression(string $aadt, string $bikeInfra, string $highwayType): string
    {
        $separated = "'{".implode(',', self::SEPARATED_FROM_COUNTED_TRAFFIC)."}'::text[]";
        $path = "'".RoadSegment::INFRA_PATH."'";
        $max = self::MAX_ADJUSTMENT;
        $points = self::POINTS_PER_DOUBLING;
        $pivot = self::PIVOT_AADT;

        return <<<SQL
            case
                when {$aadt} is null or {$aadt} <= 0 then 0
                when {$bikeInfra} = {$path} then 0
                when {$highwayType} = any({$separated}) then 0
                else round(greatest(
                    -{$max}::numeric,
                    least({$max}::numeric, {$points} * log(2, {$aadt}::numeric / {$pivot}))
                ))
            end
            SQL;
    }
}
