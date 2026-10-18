<?php

namespace App\Actions\Routes;

use RuntimeException;

/**
 * Thrown when a GPX file cannot be turned into a usable route line.
 */
class InvalidGpxFile extends RuntimeException
{
    public static function unreadable(): self
    {
        return new self(__('That file could not be read as GPX.'));
    }

    public static function noTrackPoints(): self
    {
        return new self(__('That GPX file does not contain a track or route.'));
    }

    public static function tooFewPoints(int $minimum): self
    {
        return new self(__('A route needs at least :count points.', ['count' => $minimum]));
    }

    public static function cueSheetOnly(int $spacingMetres): self
    {
        return new self(__(
            'That file is a cue sheet: it lists turn instructions roughly :distance apart, not the path between them. In Ride with GPS choose Export as GPX Track rather than GPX Route.',
            ['distance' => $spacingMetres >= 1000
                ? round($spacingMetres / 1000, 1).' km'
                : $spacingMetres.' m'],
        ));
    }

    public static function tooManyPoints(int $maximum): self
    {
        return new self(__('That route has more than :count points. Please simplify it first.', ['count' => number_format($maximum)]));
    }
}
