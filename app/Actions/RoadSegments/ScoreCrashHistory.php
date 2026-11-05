<?php

namespace App\Actions\RoadSegments;

/**
 * Turns a road's recorded crash history into points on its danger score.
 *
 * Of everything the score is built from, this is the only signal that is
 * neither a tag nor a proxy: somebody crashed here and a police officer
 * wrote it down. The tags describe a road's shape, the traffic count says
 * how busy it is, and this says what has actually happened on it.
 *
 * ## Why a rate and not a count
 *
 * Counting crashes per road would mostly measure how OpenStreetMap happens
 * to have cut the road up. Segments run from 14 m to 21 km — the 5th and
 * 95th percentiles are 14 m and 2,148 m — so a long way collects crashes a
 * short one cannot, whatever either is like to ride.
 *
 * The numbers bear it out: across the network a raw crash count correlates
 * with segment length at 0.205, while crashes per kilometre correlate at
 * -0.013. Dividing by length removes the artefact almost exactly.
 *
 * It is divided by the time the import actually covers too, so loading a
 * longer export makes the scores better evidenced rather than uniformly
 * worse.
 *
 * That span is summed per year rather than taken end to end, because the
 * exports are seasonal: they run April to November, when people ride here.
 * Measuring from the earliest crash to the latest would count every winter
 * in between as observed time and halve every rate. Summing each year's own
 * span counts only the months a crash could actually have been recorded in,
 * and self-corrects as the exports are refreshed.
 *
 * ## Why a logarithm
 *
 * The rate is skewed harder than the traffic counts are: over the roads with
 * any crash at all it runs from 0.05 to 505 per kilometre, with a median of
 * 1.38 and a 95th percentile of 31. Read linearly, every ordinary road would
 * sit indistinguishably near nought. So, as with {@see ScoreTrafficVolume},
 * it is read in doublings.
 *
 * ## Why it only ever adds
 *
 * Unlike the traffic adjustment, this is one way. Nearly every road in the
 * state has no recorded crash, and that is not evidence of safety: the
 * published export is capped per request, the history is partial, and most
 * quiet lanes are quiet in the record because nobody has had a reportable
 * crash on them *yet*. Crediting a road for an empty crash history would
 * reward roads for being absent from a dataset.
 *
 * So a road with no crashes scores nothing here, and a road with any crash
 * at all takes at least {@see self::MIN_PENALTY}: the record is thin enough
 * that a single report is worth noting but not worth much.
 */
class ScoreCrashHistory
{
    /**
     * The crash rate, per kilometre per year, that earns the pivot score.
     *
     * Set near the lower quartile of the roads that have any crash at all,
     * so the curve is already climbing across the bulk of them rather than
     * flattening against the floor.
     */
    public const PIVOT_CRASHES_PER_KM_YEAR = 0.5;

    /**
     * How many points each doubling of the crash rate adds.
     */
    public const POINTS_PER_DOUBLING = 4;

    /**
     * What any road with a recorded crash takes, however sparse.
     */
    public const MIN_PENALTY = 2;

    /**
     * The most the crash history may add.
     *
     * Held at the traffic adjustment's magnitude deliberately: crashes are
     * the most direct evidence here, but the record is partial enough that
     * it should not be able to outweigh everything else about a road.
     */
    public const MAX_PENALTY = 20;

    /**
     * The most one recorded crash may contribute on its own.
     *
     * Two doublings' worth. Three quarters of the roads with any crash on
     * them have exactly one, and a single report on a very short segment
     * would otherwise read as a blackspot: one crash on a 50 m way works out
     * at 10 per kilometre however the denominator is floored. This ties how
     * hard the rate can bite to how much happened to support it, so the rate
     * still separates ordinary roads while a lone report cannot carry a road
     * to the ceiling by itself.
     */
    public const MAX_PENALTY_PER_CRASH = 8;

    /**
     * The shortest stretch a crash is attributed over, in metres.
     *
     * Reported coordinates are placed to within a few tens of metres, which
     * is the same order as the shortest segments in the network, so a single
     * crash landing on a 14 m way would otherwise read as 71 crashes per
     * kilometre. Treating the denominator as at least this long keeps the
     * rate inside what the positions can actually support.
     */
    public const MIN_EXPOSURE_M = 100;

    /**
     * How many points to add for the crashes recorded on a road.
     *
     * @param  float  $yearsCovered  The span the imported crashes cover, so a
     *                               longer history is not read as a worse road.
     */
    public function handle(int $crashCount, float $lengthMetres, float $yearsCovered = 1.0): int
    {
        if ($crashCount <= 0) {
            return 0;
        }

        $exposureKm = max($lengthMetres, self::MIN_EXPOSURE_M) / 1000;
        $years = max(1.0, $yearsCovered);

        $rate = $crashCount / ($exposureKm * $years);
        $doublings = log($rate / self::PIVOT_CRASHES_PER_KM_YEAR, 2);

        $ceiling = min(self::MAX_PENALTY, self::MAX_PENALTY_PER_CRASH * $crashCount);

        return (int) round(max(
            self::MIN_PENALTY,
            min($ceiling, self::POINTS_PER_DOUBLING * $doublings),
        ));
    }

    /**
     * The same calculation as SQL, for the bulk rescoring paths.
     *
     * Kept here beside the constants for the same reason
     * {@see ScoreTrafficVolume::sqlExpression()} is: so the numbers are
     * written down once.
     *
     * The span is read from `crashes` as an uncorrelated scalar subquery,
     * which Postgres evaluates once for the statement rather than per road.
     * It sums each year's observed window separately, so the closed season
     * between two exports is not mistaken for time anybody was watching.
     */
    public static function sqlExpression(string $crashCount, string $geom): string
    {
        $pivot = self::PIVOT_CRASHES_PER_KM_YEAR;
        $points = self::POINTS_PER_DOUBLING;
        $min = self::MIN_PENALTY;
        $max = self::MAX_PENALTY;
        $exposure = self::MIN_EXPOSURE_M;

        $perCrash = self::MAX_PENALTY_PER_CRASH;

        return <<<SQL
            case
                when coalesce({$crashCount}, 0) <= 0 then 0
                else round(greatest(
                    {$min}::numeric,
                    least(
                        least({$max}::numeric, {$perCrash}::numeric * {$crashCount}),
                        {$points} * log(2, (
                        {$crashCount}::numeric
                        / (greatest(ST_Length({$geom}::geography), {$exposure})::numeric / 1000)
                        / (
                            select greatest(1.0, coalesce(sum(season.span), 0) / 365.25)::numeric
                            from (
                                select max(occurred_at)::date - min(occurred_at)::date + 1 as span
                                from crashes
                                where occurred_at is not null
                                group by extract(year from occurred_at)
                            ) season
                        )
                        ) / {$pivot})
                    )
                ))
            end
            SQL;
    }
}
