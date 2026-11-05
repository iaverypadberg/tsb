<?php

namespace App\Actions\Analysis;

use App\Actions\Aadt\TrafficBand;

/**
 * A continuous stretch of a route on one road, carrying one band of traffic,
 * before it is persisted as a TrafficSection.
 */
readonly class TrafficRun
{
    /**
     * @param  int|null  $aadt  The heaviest count along the stretch, or null
     *                          where the band is NotCounted.
     * @param  string|null  $roadName  What a rider would call the road, or
     *                                 null where it has neither a route
     *                                 number nor a name.
     * @param  int|null  $speedLimitKmh  The fastest limit posted along the
     *                                   stretch, or null where none of it
     *                                   is tagged with one.
     */
    public function __construct(
        public float $startFraction,
        public float $endFraction,
        public TrafficBand $band,
        public ?int $aadt,
        public int $lengthMetres,
        public ?string $roadName = null,
        public ?int $speedLimitKmh = null,
    ) {}
}
