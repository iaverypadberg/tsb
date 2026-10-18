<?php

namespace App\Actions\Analysis;

/**
 * One point along a route, and the road it was matched to.
 */
readonly class RouteSample
{
    public function __construct(
        public float $fraction,
        public ?int $roadSegmentId,
        public int $dangerScore,
        public ?float $gapMetres,
        public ?string $roadKey = null,
    ) {}

    /**
     * Whether this point sits on a road the rider should be warned about.
     */
    public function isDangerous(int $threshold): bool
    {
        return $this->roadSegmentId !== null && $this->dangerScore >= $threshold;
    }
}
