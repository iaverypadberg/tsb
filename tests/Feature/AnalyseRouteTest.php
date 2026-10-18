<?php

use App\Jobs\AnalyseRoute;
use App\Models\FlaggedSection;
use App\Models\RoadSegment;
use App\Models\Route;
use App\Models\RouteAnalysis;
use App\Models\SegmentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A route running due east, with a road underneath it.
 *
 * @return array{0: Route, 1: RoadSegment}
 */
function routeOnRoad(int $dangerScore, array $segmentAttributes = []): array
{
    $points = [];

    for ($i = 0; $i <= 40; $i++) {
        $points[] = [44.26, -72.60 + (0.05 * $i / 40)];
    }

    $route = Route::factory()->withCoordinates($points)->create();

    $segment = RoadSegment::factory()
        ->between(44.26, -72.60, 44.26, -72.55)
        ->create([...$segmentAttributes, 'danger_score' => $dangerScore]);

    return [$route, $segment];
}

function analyse(Route $route, string $tolerance = RouteAnalysis::TOLERANCE_NORMAL): RouteAnalysis
{
    $analysis = RouteAnalysis::factory()->for($route)->tolerance($tolerance)->create();

    AnalyseRoute::dispatchSync($analysis);

    return $analysis->refresh();
}

it('flags a route that runs along a dangerous road', function () {
    [$route, $segment] = routeOnRoad(90, ['name' => 'Big Road', 'ref' => 'US 2']);

    $analysis = analyse($route);

    expect($analysis->status)->toBe(RouteAnalysis::STATUS_COMPLETED)
        ->and($analysis->flaggedSections)->toHaveCount(1);

    $section = $analysis->flaggedSections->first();

    expect($section->max_danger)->toBe(90)
        ->and($section->reasons)->toContain('US 2')
        ->and($section->roadSegments->pluck('id')->all())->toBe([$segment->id]);
});

it('leaves a quiet route alone', function () {
    [$route] = routeOnRoad(15);

    expect(analyse($route)->flaggedSections)->toHaveCount(0);
});

it('records when it started and finished', function () {
    [$route] = routeOnRoad(90);

    $analysis = analyse($route);

    expect($analysis->started_at)->not->toBeNull()
        ->and($analysis->finished_at)->not->toBeNull();
});

it('summarises what it found', function () {
    [$route] = routeOnRoad(90);

    $summary = analyse($route)->summary;

    expect($summary['sections'])->toBe(1)
        ->and($summary['max_danger'])->toBe(90)
        ->and($summary['flagged_m'])->toBeGreaterThan(0)
        ->and($summary['matched_samples'])->toBe($summary['samples']);
});

it('cuts the flagged geometry out of the route line', function () {
    [$route] = routeOnRoad(90);

    $section = analyse($route)->flaggedSections->first();

    // The section has to sit on the route, not merely near it.
    $apart = DB::scalar(
        'select ST_Distance(f.geom::geography, r.geom::geography) from flagged_sections f, routes r where f.id = ? and r.id = ?',
        [$section->id, $route->id],
    );

    expect((float) $apart)->toBeLessThan(1.0)
        ->and($section->geom->getPoints())->not->toBeEmpty();
});

it('flags more of the route the more cautious the rider', function () {
    [$route] = routeOnRoad(65);

    expect(analyse($route, RouteAnalysis::TOLERANCE_CAUTIOUS)->flaggedSections)->toHaveCount(1)
        ->and(analyse($route, RouteAnalysis::TOLERANCE_NORMAL)->flaggedSections)->toHaveCount(1)
        ->and(analyse($route, RouteAnalysis::TOLERANCE_BRAVE)->flaggedSections)->toHaveCount(0);
});

it('counts reports against the road in its reasons', function () {
    [$route, $segment] = routeOnRoad(90);
    SegmentReport::factory()->count(2)->for($segment, 'roadSegment')->create();

    expect(analyse($route)->flaggedSections->first()->reasons)->toContain('2 reports');
});

it('replaces the previous findings when run again', function () {
    [$route] = routeOnRoad(90);

    $analysis = RouteAnalysis::factory()->for($route)->create();
    AnalyseRoute::dispatchSync($analysis);
    AnalyseRoute::dispatchSync($analysis);

    expect(FlaggedSection::where('route_analysis_id', $analysis->id)->count())->toBe(1);
});

it('marks the analysis failed when something goes wrong', function () {
    $analysis = RouteAnalysis::factory()->create();

    (new AnalyseRoute($analysis))->failed(new RuntimeException('Postgres went away'));

    expect($analysis->refresh()->status)->toBe(RouteAnalysis::STATUS_FAILED)
        ->and($analysis->failure_reason)->toBe('Postgres went away');
});
