<?php

namespace App\Actions\RoadFlags;

/**
 * One road, or part of one road, inside a rider's selection.
 *
 * `startFraction` and `endFraction` run along the road segment's line, so a
 * whole way is 0 to 1 and a section picked out of it is anything narrower.
 */
readonly class RoadSelectionPart
{
    /**
     * @param  list<array{float, float}>  $coordinates  Latitude first, as Leaflet draws them.
     */
    public function __construct(
        public int $roadSegmentId,
        public float $startFraction,
        public float $endFraction,
        public int $lengthMetres,
        public string $label,
        public array $coordinates,
    ) {}
}
