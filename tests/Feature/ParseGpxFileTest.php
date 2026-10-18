<?php

use App\Actions\Routes\InvalidGpxFile;
use App\Actions\Routes\ParseGpxFile;

it('parses track points into a route line', function () {
    $gpx = gpxWithTrack(
        trackPoint(44.26, -72.58).trackPoint(44.27, -72.57).trackPoint(44.28, -72.56)
    );

    $parsed = (new ParseGpxFile)->handle($gpx, 'fallback');

    expect($parsed->pointCount)->toBe(3)
        ->and($parsed->geometry->getPoints())->toHaveCount(3);

    $first = $parsed->geometry->getPoints()[0];

    expect($first->getLatitude())->toBe(44.26)
        ->and($first->getLongitude())->toBe(-72.58);
});

it('prefers the metadata name over the track name and the fallback', function () {
    $gpx = gpxWithTrack(
        trackPoint(44.26, -72.58).trackPoint(44.27, -72.57),
        metadataName: 'Metadata name',
        trackName: 'Track name',
    );

    expect((new ParseGpxFile)->handle($gpx, 'fallback')->name)->toBe('Metadata name');
});

it('falls back to the track name, then to the supplied name', function () {
    $withTrackName = gpxWithTrack(
        trackPoint(44.26, -72.58).trackPoint(44.27, -72.57),
        trackName: 'Track name',
    );

    $unnamed = gpxWithTrack(trackPoint(44.26, -72.58).trackPoint(44.27, -72.57));

    expect((new ParseGpxFile)->handle($withTrackName, 'fallback')->name)->toBe('Track name')
        ->and((new ParseGpxFile)->handle($unnamed, 'fallback')->name)->toBe('fallback');
});

it('reads route points when the file has no track', function () {
    // A densely sampled <rte> describes a real path, so it is usable.
    $gpx = spacedRoute(pointCount: 20, latitudeStep: 0.0005);

    expect((new ParseGpxFile)->handle($gpx, 'fallback')->pointCount)->toBe(20);
});

it('rejects a cue sheet that only marks the turns', function () {
    // What Ride with GPS produces when you export GPX Route instead of
    // GPX Track: one point per junction, kilometres apart.
    $gpx = gpxWithRoute(
        routePoint(44.27736, -72.56482, 'Start of route')
        .routePoint(44.29217, -72.67785, 'Turn left onto Vermont Route 100B')
        .routePoint(44.25096, -72.76115, "Turn sharp left onto Wimble's Road")
        .routePoint(44.17213, -72.65048, 'Turn left onto Vermont Route 12 North')
    );

    (new ParseGpxFile)->handle($gpx, 'fallback');
})->throws(InvalidGpxFile::class, 'cue sheet');

it('tells the reader how far apart the turns were', function () {
    $gpx = spacedRoute(pointCount: 5, latitudeStep: 0.05);

    expect(fn () => (new ParseGpxFile)->handle($gpx, 'fallback'))
        ->toThrow(InvalidGpxFile::class, 'GPX Track');
});

it('accepts a sparse track, because a trace is a trace however coarse', function () {
    // The same spacing inside a <trk> is a simplified recording, not a cue
    // sheet, so it must not be turned away.
    $points = '';

    for ($i = 0; $i < 5; $i++) {
        $points .= trackPoint(44.26 + ($i * 0.05), -72.58);
    }

    expect((new ParseGpxFile)->handle(gpxWithTrack($points), 'fallback')->pointCount)->toBe(5);
});

it('drops consecutive duplicate points that would create zero length segments', function () {
    $gpx = gpxWithTrack(
        trackPoint(44.26, -72.58)
        .trackPoint(44.26, -72.58)
        .trackPoint(44.27, -72.57)
    );

    expect((new ParseGpxFile)->handle($gpx, 'fallback')->pointCount)->toBe(2);
});

it('sums elevation gain and ignores descent', function () {
    $gpx = gpxWithTrack(
        trackPoint(44.26, -72.58, 100.0)
        .trackPoint(44.27, -72.57, 150.0)
        .trackPoint(44.28, -72.56, 120.0)
        .trackPoint(44.29, -72.55, 170.0)
    );

    // Up 50, down 30 (ignored), up 50.
    expect((new ParseGpxFile)->handle($gpx, 'fallback')->elevationGainM)->toBe(100);
});

it('ignores elevation wobble below the noise threshold', function () {
    $gpx = gpxWithTrack(
        trackPoint(44.26, -72.58, 100.0)
        .trackPoint(44.27, -72.57, 101.0)
        .trackPoint(44.28, -72.56, 100.5)
        .trackPoint(44.29, -72.55, 101.5)
    );

    expect((new ParseGpxFile)->handle($gpx, 'fallback')->elevationGainM)->toBe(0);
});

it('counts a steady climb made of small steps', function () {
    // Ten 1 metre steps, each below the threshold, but 10 metres of real climb.
    $points = '';

    for ($i = 0; $i <= 10; $i++) {
        $points .= trackPoint(44.26 + ($i * 0.001), -72.58, 100.0 + $i);
    }

    expect((new ParseGpxFile)->handle(gpxWithTrack($points), 'fallback')->elevationGainM)->toBe(10);
});

it('rejects content that is not a gpx document', function (string $contents) {
    (new ParseGpxFile)->handle($contents, 'fallback');
})->throws(InvalidGpxFile::class)->with([
    'empty' => [''],
    'not xml' => ['this is not xml at all'],
    'xml but not gpx' => ['<?xml version="1.0"?><kml><Placemark /></kml>'],
]);

it('rejects a gpx file with no usable points', function () {
    (new ParseGpxFile)->handle(gpxWithTrack(''), 'fallback');
})->throws(InvalidGpxFile::class);

it('rejects a track that collapses to a single distinct point', function () {
    $gpx = gpxWithTrack(trackPoint(44.26, -72.58).trackPoint(44.26, -72.58));

    (new ParseGpxFile)->handle($gpx, 'fallback');
})->throws(InvalidGpxFile::class);
