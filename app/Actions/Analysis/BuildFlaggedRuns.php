<?php

namespace App\Actions\Analysis;

/**
 * Collapses the samples along a route into continuous sections.
 *
 * Working sample by sample would bury the rider in warnings, so consecutive
 * dangerous samples become one section, and very short ones are dropped: a
 * route that clips thirty metres of a main road crossing a junction does not
 * need a detour suggesting.
 *
 * {@see self::unflaggedRuns()} then names what is left over, so the rider
 * reads the whole ride in order rather than only its worst parts.
 */
class BuildFlaggedRuns
{
    /**
     * Sections shorter than this are noise rather than a stretch of road.
     */
    public const MIN_SECTION_LENGTH_M = 50;

    /**
     * Except when the road is this bad, where even a short stretch is worth
     * saying something about.
     */
    public const ALWAYS_REPORT_DANGER = 90;

    /**
     * @param  list<RouteSample>  $samples
     * @return list<FlaggedRun>
     */
    public function handle(array $samples, int $threshold, float $routeLengthMetres): array
    {
        $runs = [];
        $count = count($samples);
        $start = null;

        for ($i = 0; $i < $count; $i++) {
            $dangerous = $samples[$i]->isDangerous($threshold);

            // A new road means a new section, even with no safe stretch
            // between them: one detour cannot cover two different highways.
            $roadChanged = $start !== null
                && $dangerous
                && $samples[$i]->roadKey !== $samples[$i - 1]->roadKey;

            if ($roadChanged) {
                $this->collect($runs, $samples, $start, $i - 1, $routeLengthMetres);

                $start = $i;
            }

            if ($dangerous && $start === null) {
                $start = $i;
            }

            $runEnds = $start !== null && (! $dangerous || $i === $count - 1);

            if (! $runEnds) {
                continue;
            }

            $this->collect($runs, $samples, $start, $dangerous ? $i : $i - 1, $routeLengthMetres);

            $start = null;
        }

        return $runs;
    }

    /**
     * The rest of the route: every stretch the flagged runs left over.
     *
     * A list of flagged sections says where not to go and nothing about the
     * ride itself. Three warnings could be three quarters of a route or the
     * only blemishes on it, and from the list alone a rider cannot tell
     * which. Reporting the gaps too makes the route read as a sequence —
     * quiet lane, then a mile of the highway, then quiet again — and gives
     * the quiet parts their own description, which is the thing that says
     * the analysis looked at them rather than missed them.
     *
     * Unlike the flagged runs these are not split where the road changes.
     * A flagged run is split because a detour has to be around one road at
     * a time, and nothing is being detoured around here: a rider wants "14
     * km of back roads", not each lane it is made of.
     *
     * A dangerous stretch too short to report is left inside the gap that
     * swallowed it, so its score still shows in that stretch's worst score.
     * That is deliberate: the stretch was not worth flagging on its own, but
     * it is worth not hiding.
     *
     * @param  list<RouteSample>  $samples
     * @param  list<FlaggedRun>  $flagged  The runs {@see self::handle()} returned.
     * @return list<FlaggedRun>
     */
    public function unflaggedRuns(array $samples, array $flagged, float $routeLengthMetres): array
    {
        if ($samples === []) {
            return [];
        }

        $runs = [];
        $cursor = $samples[0]->fraction;
        $finish = $samples[count($samples) - 1]->fraction;

        foreach ($flagged as $run) {
            if ($run->startFraction > $cursor) {
                $this->collectGap($runs, $samples, $cursor, $run->startFraction, $routeLengthMetres);
            }

            $cursor = max($cursor, $run->endFraction);
        }

        if ($cursor < $finish) {
            $this->collectGap($runs, $samples, $cursor, $finish, $routeLengthMetres);
        }

        return $runs;
    }

    /**
     * Build the run covering one gap, and keep it if it is long enough to be
     * a stretch of road rather than the join between two flagged sections.
     *
     * @param  list<FlaggedRun>  $runs
     * @param  list<RouteSample>  $samples
     */
    private function collectGap(
        array &$runs,
        array $samples,
        float $startFraction,
        float $endFraction,
        float $routeLengthMetres,
    ): void {
        $lengthMetres = (int) round(($endFraction - $startFraction) * $routeLengthMetres);

        if ($lengthMetres < self::MIN_SECTION_LENGTH_M) {
            return;
        }

        $maxDanger = 0;
        $segmentIds = [];

        foreach ($samples as $sample) {
            if ($sample->fraction < $startFraction || $sample->fraction > $endFraction) {
                continue;
            }

            $maxDanger = max($maxDanger, $sample->dangerScore);

            if ($sample->roadSegmentId !== null) {
                $segmentIds[$sample->roadSegmentId] = true;
            }
        }

        $runs[] = new FlaggedRun(
            startFraction: $startFraction,
            endFraction: $endFraction,
            maxDanger: $maxDanger,
            lengthMetres: $lengthMetres,
            roadSegmentIds: array_map('intval', array_keys($segmentIds)),
            flagged: false,
        );
    }

    /**
     * Build a run and keep it, if it is substantial enough to mention.
     *
     * @param  list<FlaggedRun>  $runs
     * @param  list<RouteSample>  $samples
     */
    private function collect(array &$runs, array $samples, int $start, int $end, float $routeLengthMetres): void
    {
        $run = $this->buildRun($samples, $start, $end, $routeLengthMetres);

        if ($this->isWorthReporting($run)) {
            $runs[] = $run;
        }
    }

    /**
     * Stretch the run out to halfway towards its neighbours.
     *
     * A sample is a point, but it stands for the road around it, so a lone
     * dangerous sample covers one sample interval rather than nothing.
     *
     * @param  list<RouteSample>  $samples
     */
    private function buildRun(array $samples, int $start, int $end, float $routeLengthMetres): FlaggedRun
    {
        $startFraction = $start > 0
            ? ($samples[$start - 1]->fraction + $samples[$start]->fraction) / 2
            : $samples[$start]->fraction;

        $endFraction = $end < count($samples) - 1
            ? ($samples[$end]->fraction + $samples[$end + 1]->fraction) / 2
            : $samples[$end]->fraction;

        $maxDanger = 0;
        $segmentIds = [];

        for ($i = $start; $i <= $end; $i++) {
            $maxDanger = max($maxDanger, $samples[$i]->dangerScore);

            if ($samples[$i]->roadSegmentId !== null) {
                $segmentIds[$samples[$i]->roadSegmentId] = true;
            }
        }

        return new FlaggedRun(
            startFraction: $startFraction,
            endFraction: $endFraction,
            maxDanger: $maxDanger,
            lengthMetres: (int) round(($endFraction - $startFraction) * $routeLengthMetres),
            roadSegmentIds: array_map('intval', array_keys($segmentIds)),
        );
    }

    private function isWorthReporting(FlaggedRun $run): bool
    {
        return $run->lengthMetres >= self::MIN_SECTION_LENGTH_M
            || $run->maxDanger >= self::ALWAYS_REPORT_DANGER;
    }
}
