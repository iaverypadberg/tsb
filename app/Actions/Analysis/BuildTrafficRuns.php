<?php

namespace App\Actions\Analysis;

use App\Actions\Aadt\TrafficBand;

/**
 * Collapses the sampled traffic counts along a route into coloured stretches.
 *
 * This is {@see BuildFlaggedRuns} with the danger threshold taken out. Every
 * stretch is kept: a traffic profile is a picture of the whole ride rather
 * than a list of the parts worth warning about, so there is nothing here to
 * drop as noise and no gap in the line to explain.
 *
 * The route is cut wherever it moves into a different band, and wherever it
 * turns onto a different road. The second cut is what makes the profile a
 * list of roads a rider can read down — "VT 100, then Dog River Road" —
 * instead of one stretch covering both because they happen to carry
 * comparable traffic. Two neighbouring stretches in the same band draw in
 * the same colour, so splitting them changes the list without changing the
 * map.
 *
 * Within a stretch the heaviest count along it is the one reported. A
 * stretch is drawn in one colour and labelled with one figure, and of the
 * counts it could carry, the worst is the one a rider is choosing against.
 */
class BuildTrafficRuns
{
    /**
     * @param  list<RouteSample>  $samples
     * @return list<TrafficRun>
     */
    public function handle(array $samples, float $routeLengthMetres): array
    {
        $count = count($samples);

        if ($count === 0) {
            return [];
        }

        $runs = [];
        $start = 0;

        for ($i = 0; $i < $count; $i++) {
            $isLast = $i === $count - 1;
            $runBreaks = ! $isLast && ! $this->isSameStretch($samples[$start], $samples[$i + 1]);

            if (! $isLast && ! $runBreaks) {
                continue;
            }

            $runs[] = $this->buildRun($samples, $start, $i, $routeLengthMetres);

            $start = $i + 1;
        }

        return $runs;
    }

    /**
     * Whether a sample belongs to the stretch that started at another.
     *
     * The road is compared on {@see RouteSample::$roadKey} rather than on the
     * name, so that two different unnamed lanes are not run together just
     * because neither has a name to tell them apart.
     */
    private function isSameStretch(RouteSample $open, RouteSample $next): bool
    {
        return $this->bandOf($next) === $this->bandOf($open)
            && $next->roadKey === $open->roadKey;
    }

    private function bandOf(RouteSample $sample): TrafficBand
    {
        return TrafficBand::for($sample->aadt);
    }

    /**
     * Stretch the run out to halfway towards its neighbours, so consecutive
     * stretches meet and the line is coloured end to end.
     *
     * @param  list<RouteSample>  $samples
     */
    private function buildRun(array $samples, int $start, int $end, float $routeLengthMetres): TrafficRun
    {
        $startFraction = $start > 0
            ? ($samples[$start - 1]->fraction + $samples[$start]->fraction) / 2
            : $samples[$start]->fraction;

        $endFraction = $end < count($samples) - 1
            ? ($samples[$end]->fraction + $samples[$end + 1]->fraction) / 2
            : $samples[$end]->fraction;

        $aadt = null;
        $roadName = null;
        $speedLimitKmh = null;

        for ($i = $start; $i <= $end; $i++) {
            if ($samples[$i]->aadt !== null) {
                $aadt = max($aadt ?? 0, $samples[$i]->aadt);
            }

            // The fastest limit along the stretch, for the same reason the
            // heaviest count is taken: one figure is reported, and of the
            // ones it could carry, the worst is what the rider meets.
            if ($samples[$i]->speedLimitKmh !== null) {
                $speedLimitKmh = max($speedLimitKmh ?? 0, $samples[$i]->speedLimitKmh);
            }

            $roadName ??= $samples[$i]->roadName;
        }

        return new TrafficRun(
            startFraction: $startFraction,
            endFraction: $endFraction,
            band: $this->bandOf($samples[$start]),
            aadt: $aadt,
            lengthMetres: (int) round(($endFraction - $startFraction) * $routeLengthMetres),
            roadName: $roadName,
            speedLimitKmh: $speedLimitKmh,
        );
    }
}
