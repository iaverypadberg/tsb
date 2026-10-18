<?php

use App\Actions\Analysis\MatchRouteToRoadSegments;
use App\Models\RoadSegment;
use App\Models\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A route running due east along a constant latitude.
 */
function eastWestRoute(float $latitude = 44.26, float $fromLon = -72.58, float $toLon = -72.57): Route
{
    $points = [];

    for ($i = 0; $i <= 20; $i++) {
        $points[] = [$latitude, $fromLon + ($toLon - $fromLon) * ($i / 20)];
    }

    return Route::factory()->withCoordinates($points)->create();
}

it('matches a route to the road it runs along', function () {
    $road = RoadSegment::factory()
        ->between(44.26, -72.58, 44.26, -72.57)
        ->create(['name' => 'Main Street', 'danger_score' => 42]);

    $samples = (new MatchRouteToRoadSegments)->handle(eastWestRoute());

    expect($samples)->not->toBeEmpty();

    $matched = array_filter($samples, fn ($s): bool => $s->roadSegmentId === $road->id);

    expect(count($matched))->toBe(count($samples))
        ->and($samples[0]->dangerScore)->toBe(42);
});

it('ignores a road that crosses square on at a junction', function () {
    $along = RoadSegment::factory()
        ->between(44.26, -72.58, 44.26, -72.57)
        ->create(['name' => 'Main Street', 'danger_score' => 20]);

    // A side road crossing the route at right angles, passing through it.
    RoadSegment::factory()
        ->between(44.2555, -72.575, 44.2645, -72.575)
        ->create(['name' => 'Cross Street', 'danger_score' => 95]);

    $samples = (new MatchRouteToRoadSegments)->handle(eastWestRoute());

    $matchedIds = array_unique(array_map(fn ($s): ?int => $s->roadSegmentId, $samples));

    // Every sample belongs to the road being ridden, never the cross street.
    expect($matchedIds)->toBe([$along->id]);
});

it('prefers the nearer of two roads running the same way', function () {
    // The case the bearing check cannot settle: a bike path beside a highway.
    RoadSegment::factory()
        ->between(44.2601, -72.58, 44.2601, -72.57)
        ->create(['name' => 'Highway', 'danger_score' => 90]);

    $path = RoadSegment::factory()
        ->between(44.26, -72.58, 44.26, -72.57)
        ->cyclePath()
        ->create(['name' => 'Bike Path']);

    $samples = (new MatchRouteToRoadSegments)->handle(eastWestRoute());

    $matchedIds = array_unique(array_map(fn ($s): ?int => $s->roadSegmentId, $samples));

    expect($matchedIds)->toBe([$path->id]);
});

it('leaves a sample unmatched when no road is near enough', function () {
    // Roughly 300 metres north of the route, well outside the buffer.
    RoadSegment::factory()
        ->between(44.263, -72.58, 44.263, -72.57)
        ->create(['name' => 'Distant Road']);

    $samples = (new MatchRouteToRoadSegments)->handle(eastWestRoute());

    expect($samples)->not->toBeEmpty()
        ->and(array_filter($samples, fn ($s): bool => $s->roadSegmentId !== null))->toBeEmpty();
});

it('samples the whole route from start to finish', function () {
    RoadSegment::factory()->between(44.26, -72.58, 44.26, -72.57)->create();

    $samples = (new MatchRouteToRoadSegments)->handle(eastWestRoute());

    expect($samples[0]->fraction)->toBe(0.0)
        ->and(end($samples)->fraction)->toBe(1.0);
});

it('reports how far the route sits from the road it matched', function () {
    RoadSegment::factory()->between(44.26, -72.58, 44.26, -72.57)->create();

    $samples = (new MatchRouteToRoadSegments)->handle(eastWestRoute());

    expect($samples[0]->gapMetres)->toBeLessThan(MatchRouteToRoadSegments::MATCH_BUFFER_M);
});
