<?php

namespace App\Actions\Routes;

use Clickbar\Magellan\Data\Geometries\LineString;

/**
 * The result of reading a GPX file: a route line plus the figures we can
 * derive without touching the database.
 */
readonly class ParsedGpxRoute
{
    public function __construct(
        public string $name,
        public LineString $geometry,
        public int $elevationGainM,
        public int $pointCount,
    ) {}
}
