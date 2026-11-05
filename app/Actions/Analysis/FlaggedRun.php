<?php

namespace App\Actions\Analysis;

/**
 * A continuous stretch of road found along a route, before it is persisted
 * as a FlaggedSection.
 *
 * Most of these are the dangerous stretches. The rest are the ones in
 * between: a route is shown as every one of its stretches in order, so the
 * quiet parts are named rather than left as whatever is not highlighted.
 */
readonly class FlaggedRun
{
    /**
     * @param  list<int>  $roadSegmentIds
     * @param  bool  $flagged  Whether this stretch passed the rider's threshold.
     */
    public function __construct(
        public float $startFraction,
        public float $endFraction,
        public int $maxDanger,
        public int $lengthMetres,
        public array $roadSegmentIds,
        public bool $flagged = true,
    ) {}
}
