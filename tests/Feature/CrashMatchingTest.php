<?php

use App\Actions\Crashes\MatchCrashesToRoadSegments;
use App\Models\Crash;
use App\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A road running due north from a fixed point, long enough for crashes to be
 * placed along it.
 */
function roadForCrashes(float $metres = 1000.0): RoadSegment
{
    return RoadSegment::factory()->straight($metres)->create([
        'base_danger_score' => 30,
        'danger_score' => 30,
    ]);
}

it('gives a crash to the road it happened on', function () {
    $road = roadForCrashes();
    Crash::factory()->at(44.263, -72.58)->create();

    $result = app(MatchCrashesToRoadSegments::class)->handle();

    expect($result['matched'])->toBe(1)
        ->and(Crash::sole()->road_segment_id)->toBe($road->id)
        ->and($road->fresh()->crash_count)->toBe(1);
});

it('leaves a crash unmatched when no road is near it', function () {
    roadForCrashes();
    // Several kilometres east of the road, well outside the buffer.
    Crash::factory()->at(44.263, -72.50)->create();

    $result = app(MatchCrashesToRoadSegments::class)->handle();

    expect($result['matched'])->toBe(0)
        ->and($result['unmatched'])->toBe(1)
        ->and(Crash::sole()->road_segment_id)->toBeNull();
});

it('counts a crash with no coordinates as unplaceable rather than unmatched', function () {
    roadForCrashes();
    Crash::factory()->unplaceable()->create();

    $result = app(MatchCrashesToRoadSegments::class)->handle();

    expect($result['unplaceable'])->toBe(1)
        ->and($result['unmatched'])->toBe(0)
        ->and($result['matched'])->toBe(0);
});

/**
 * Two roads meeting at a junction both have a claim on a crash in it. It goes
 * to the nearer, because splitting it would count one crash twice.
 */
it('gives a crash at a junction to the nearer of the two roads', function () {
    $main = RoadSegment::factory()->between(44.26, -72.58, 44.27, -72.58)->create();
    $side = RoadSegment::factory()->between(44.265, -72.5799, 44.265, -72.57)->create();

    // A few metres east of the junction, so it sits nearer the side road.
    Crash::factory()->at(44.265, -72.5797)->create();

    app(MatchCrashesToRoadSegments::class)->handle();

    expect(Crash::sole()->road_segment_id)->toBe($side->id)
        ->and($side->fresh()->crash_count)->toBe(1)
        ->and($main->fresh()->crash_count)->toBe(0);
});

it('records how far the crash sat from the road it was given to', function () {
    roadForCrashes();
    Crash::factory()->at(44.263, -72.58)->create();

    app(MatchCrashesToRoadSegments::class)->handle();

    expect(Crash::sole()->match_distance_m)->toBeLessThan(MatchCrashesToRoadSegments::MATCH_BUFFER_M);
});

it('totals injuries and deaths separately from the bare count', function () {
    $road = roadForCrashes();
    Crash::factory()->at(44.262, -72.58)->create();
    Crash::factory()->injury()->at(44.263, -72.58)->create();
    Crash::factory()->fatal()->at(44.264, -72.58)->create();

    app(MatchCrashesToRoadSegments::class)->handle();

    $road = $road->fresh();

    expect($road->crash_count)->toBe(3)
        ->and($road->crash_injury_count)->toBe(2)
        ->and($road->crash_fatal_count)->toBe(1);
});

it('records the span the matched crashes cover', function () {
    $road = roadForCrashes();
    Crash::factory()->at(44.262, -72.58)->create(['occurred_at' => '2012-03-04 00:00:00']);
    Crash::factory()->at(44.264, -72.58)->create(['occurred_at' => '2019-08-09 00:00:00']);

    app(MatchCrashesToRoadSegments::class)->handle();

    expect($road->fresh()->crash_first_year)->toBe(2012)
        ->and($road->fresh()->crash_last_year)->toBe(2019);
});

/**
 * Rerunning has to be able to take a total away again, or a road would keep
 * crashes that have since been corrected out of the export.
 */
it('takes back a total when the crash is gone', function () {
    $road = roadForCrashes();
    $crash = Crash::factory()->at(44.263, -72.58)->create();

    app(MatchCrashesToRoadSegments::class)->handle();
    expect($road->fresh()->crash_count)->toBe(1);

    $crash->delete();
    app(MatchCrashesToRoadSegments::class)->handle();

    expect($road->fresh()->crash_count)->toBe(0);
});

it('raises the danger score of a road crashes were matched to', function () {
    $road = roadForCrashes(300.0);
    Crash::factory()->at(44.261, -72.58)->create();
    Crash::factory()->at(44.262, -72.58)->create();

    app(MatchCrashesToRoadSegments::class)->handle();

    expect($road->fresh()->danger_score)->toBeGreaterThan($road->base_danger_score);
});

it('matches again without changing anything the second time', function () {
    $road = roadForCrashes(300.0);
    Crash::factory()->at(44.261, -72.58)->create();

    $matcher = app(MatchCrashesToRoadSegments::class);
    $matcher->handle();
    $once = $road->fresh()->danger_score;
    $matcher->handle();

    expect($road->fresh()->danger_score)->toBe($once)
        ->and($road->fresh()->crash_count)->toBe(1);
});
