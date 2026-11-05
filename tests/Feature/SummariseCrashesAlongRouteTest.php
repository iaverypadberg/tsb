<?php

use App\Actions\Crashes\SummariseCrashesAlongRoute;
use App\Models\Crash;
use App\Models\Route;
use App\Models\TrafficSection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A stretch of a route running due north from a fixed point, long enough for
 * crashes to be placed beside it.
 */
function stretchForCrashes(Route $route, float $metres = 1_000.0): TrafficSection
{
    $delta = $metres / 111_320;

    return TrafficSection::factory()
        ->for($route)
        ->between(44.26, -72.58, 44.26 + $delta, -72.58)
        ->create(['length_m' => (int) $metres]);
}

it('counts what was reported beside a stretch, split by what happened', function () {
    $route = Route::factory()->create();
    $stretch = stretchForCrashes($route);

    Crash::factory()->at(44.262, -72.58)->fatal()->create();
    Crash::factory()->at(44.263, -72.58)->injury()->create();
    Crash::factory()->at(44.264, -72.58)->injury()->create(['involving' => Crash::INVOLVING_BICYCLE]);
    Crash::factory()->at(44.265, -72.58)->create(['involving' => Crash::INVOLVING_PEDESTRIAN]);

    $summaries = app(SummariseCrashesAlongRoute::class)->handle($route);

    expect($summaries[$stretch->id])->toBe([
        'total' => 4,
        'fatal' => 1,
        'injury' => 2,
        'bicycle' => 1,
        'pedestrian' => 1,
    ]);
});

it('says nothing about a stretch nothing has been reported on', function () {
    $route = Route::factory()->create();
    stretchForCrashes($route);

    expect(app(SummariseCrashesAlongRoute::class)->handle($route))->toBe([]);
});

it('leaves out a crash reported too far from the ridden line', function () {
    $route = Route::factory()->create();
    stretchForCrashes($route);

    // Several kilometres east of the stretch, well outside the buffer.
    Crash::factory()->at(44.262, -72.50)->fatal()->create();

    expect(app(SummariseCrashesAlongRoute::class)->handle($route))->toBe([]);
});

it('leaves out a crash that was never given a location', function () {
    $route = Route::factory()->create();
    stretchForCrashes($route);

    Crash::factory()->unplaceable()->fatal()->create();

    expect(app(SummariseCrashesAlongRoute::class)->handle($route))->toBe([]);
});

it('counts each crash against the stretch it happened beside', function () {
    $route = Route::factory()->create();

    $first = stretchForCrashes($route);
    $second = TrafficSection::factory()
        ->for($route)
        ->between(44.30, -72.58, 44.31, -72.58)
        ->create();

    Crash::factory()->at(44.262, -72.58)->fatal()->create();
    Crash::factory()->at(44.305, -72.58)->injury()->create();

    $summaries = app(SummariseCrashesAlongRoute::class)->handle($route);

    expect($summaries[$first->id]['fatal'])->toBe(1)
        ->and($summaries[$first->id]['injury'])->toBe(0)
        ->and($summaries[$second->id]['injury'])->toBe(1)
        ->and($summaries[$second->id]['fatal'])->toBe(0);
});

it('leaves out another route, however close its stretches run', function () {
    $route = Route::factory()->create();
    stretchForCrashes($route);

    $theirs = Route::factory()->create();
    stretchForCrashes($theirs);

    Crash::factory()->at(44.262, -72.58)->fatal()->create();

    $summaries = app(SummariseCrashesAlongRoute::class)->handle($route);

    expect($summaries)->toHaveCount(1);
});
