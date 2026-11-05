<?php

namespace App\Actions\Analysis;

/**
 * One point along a route, and the road it was matched to.
 */
readonly class RouteSample
{
    /**
     * @param  string|null  $roadKey  What splits one road from the next,
     *                                which falls back to the road class
     *                                where a lane has no name at all.
     * @param  string|null  $roadName  What a rider would call the road, or
     *                                 null where it carries neither a route
     *                                 number nor a name.
     * @param  int|null  $aadt  The traffic counted on that road, where any
     *                          has been: null is unmeasured, not quiet.
     * @param  int|null  $speedLimitKmh  The posted limit, where the road
     *                                   carries one: null is untagged, not
     *                                   slow.
     */
    public function __construct(
        public float $fraction,
        public ?int $roadSegmentId,
        public int $dangerScore,
        public ?float $gapMetres,
        public ?string $roadKey = null,
        public ?int $aadt = null,
        public ?string $roadName = null,
        public ?int $speedLimitKmh = null,
    ) {}

    /**
     * Whether this point sits on a road the rider should be warned about.
     */
    public function isDangerous(int $threshold): bool
    {
        return $this->roadSegmentId !== null && $this->dangerScore >= $threshold;
    }
}
