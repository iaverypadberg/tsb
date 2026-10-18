<?php

namespace App\Actions\Analysis;

/**
 * A continuous stretch of dangerous road found along a route, before it is
 * persisted as a FlaggedSection.
 */
readonly class FlaggedRun
{
    /**
     * @param  list<int>  $roadSegmentIds
     */
    public function __construct(
        public float $startFraction,
        public float $endFraction,
        public int $maxDanger,
        public int $lengthMetres,
        public array $roadSegmentIds,
    ) {}
}
