<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Wrap track points in a minimal but valid GPX document.
 */
function gpxWithTrack(string $points, ?string $metadataName = null, ?string $trackName = null): string
{
    $metadata = $metadataName === null ? '' : "<metadata><name>{$metadataName}</name></metadata>";
    $name = $trackName === null ? '' : "<name>{$trackName}</name>";

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
        {$metadata}
        <trk>{$name}<trkseg>{$points}</trkseg></trk>
    </gpx>
    XML;
}

/**
 * A single <trkpt> element.
 */
function trackPoint(float $latitude, float $longitude, ?float $elevation = null): string
{
    $ele = $elevation === null ? '' : "<ele>{$elevation}</ele>";

    return "<trkpt lat=\"{$latitude}\" lon=\"{$longitude}\">{$ele}</trkpt>";
}

/**
 * Wrap route points in a GPX document, the way a planner exports a <rte>.
 */
function gpxWithRoute(string $points): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
        <rte>{$points}</rte>
    </gpx>
    XML;
}

/**
 * A single <rtept>, optionally carrying a turn instruction.
 */
function routePoint(float $latitude, float $longitude, ?string $comment = null): string
{
    $cmt = $comment === null ? '' : "<cmt>{$comment}</cmt>";

    return "<rtept lat=\"{$latitude}\" lon=\"{$longitude}\">{$cmt}</rtept>";
}

/**
 * A run of route points spaced a given number of degrees of latitude apart.
 * Roughly 111 metres per 0.001 degrees.
 */
function spacedRoute(int $pointCount, float $latitudeStep): string
{
    $points = '';

    for ($i = 0; $i < $pointCount; $i++) {
        $points .= routePoint(44.26 + ($i * $latitudeStep), -72.58);
    }

    return gpxWithRoute($points);
}

/**
 * A short, valid GPX ride near Montpelier, Vermont.
 */
function sampleGpx(int $pointCount = 5, ?string $metadataName = null): string
{
    $points = '';

    for ($i = 0; $i < $pointCount; $i++) {
        $points .= trackPoint(44.26 + ($i * 0.002), -72.58 + ($i * 0.003), 200.0 + ($i * 10));
    }

    return gpxWithTrack($points, metadataName: $metadataName);
}
