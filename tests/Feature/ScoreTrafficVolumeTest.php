<?php

use App\Actions\RoadSegments\RecalculateDangerScores;
use App\Actions\RoadSegments\ScoreTrafficVolume;
use App\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->score = new ScoreTrafficVolume;
});

it('leaves an unmeasured road alone', function (?int $aadt) {
    expect($this->score->handle($aadt))->toBe(0);
})->with([
    'never counted' => [null],
    'counted at nothing' => [0],
]);

it('earns no adjustment at the volume the tags already assume', function () {
    expect($this->score->handle(ScoreTrafficVolume::PIVOT_AADT))->toBe(0);
});

it('penalises a busier road and credits a quieter one', function () {
    expect($this->score->handle(16000))->toBeGreaterThan(0)
        ->and($this->score->handle(500))->toBeLessThan(0);
});

/**
 * The whole point of reading the count in doublings: the same ratio moves the
 * score by the same amount whether it sits at the bottom of the range or the
 * top. Linearly, the first pair would be indistinguishable.
 */
it('separates the tails as much as the middle', function () {
    $quiet = $this->score->handle(500) - $this->score->handle(250);
    $busy = $this->score->handle(40000) - $this->score->handle(20000);

    expect($quiet)->toBe(ScoreTrafficVolume::POINTS_PER_DOUBLING)
        ->and($busy)->toBe(ScoreTrafficVolume::POINTS_PER_DOUBLING);
});

it('never moves a score further than the cap in either direction', function (int $aadt) {
    expect(abs($this->score->handle($aadt)))->toBeLessThanOrEqual(ScoreTrafficVolume::MAX_ADJUSTMENT);
})->with([
    'far below anything published' => [1],
    'far above anything published' => [500_000],
]);

/**
 * A rail trail runs alongside a highway on the same bearing for miles, so it
 * sits inside the matcher's buffer and inherits the highway's count verbatim.
 * Scoring that would push riders off the safest infrastructure there is.
 */
it('ignores a count matched to a road separated from the traffic', function () {
    expect($this->score->handle(42346, RoadSegment::INFRA_PATH, 'cycleway'))->toBe(0)
        ->and($this->score->handle(42346, RoadSegment::INFRA_NONE, 'cycleway'))->toBe(0)
        ->and($this->score->handle(42346, RoadSegment::INFRA_NONE, 'service'))->toBe(0)
        ->and($this->score->handle(42346, RoadSegment::INFRA_NONE, 'primary'))->toBeGreaterThan(0);
});

it('calculates the same adjustment in postgres as in php', function (int $aadt) {
    $expression = ScoreTrafficVolume::sqlExpression('rs.aadt', 'rs.bike_infra', 'rs.highway_type');

    $segment = RoadSegment::factory()->create([
        'aadt' => $aadt,
        'bike_infra' => RoadSegment::INFRA_NONE,
        'highway_type' => 'primary',
    ]);

    $adjustment = DB::selectOne(
        "select ({$expression}) as adjustment from road_segments rs where rs.id = ?",
        [$segment->id],
    );

    expect((int) $adjustment->adjustment)->toBe($this->score->handle($aadt));
})->with([
    'quietest counted road in the state' => [46],
    'a fifth of the pivot' => [800],
    'at the pivot' => [4000],
    'median of the busy tail' => [12782],
    'busiest counted road in the state' => [43274],
]);

it('folds the traffic count into the stored danger score', function () {
    $busy = RoadSegment::factory()->create([
        'base_danger_score' => 40,
        'danger_score' => 40,
        'aadt' => 16000,
        'bike_infra' => RoadSegment::INFRA_NONE,
        'highway_type' => 'primary',
    ]);

    $quiet = RoadSegment::factory()->create([
        'base_danger_score' => 40,
        'danger_score' => 40,
        'aadt' => 500,
        'bike_infra' => RoadSegment::INFRA_NONE,
        'highway_type' => 'primary',
    ]);

    app(RecalculateDangerScores::class)->handle([$busy->id, $quiet->id]);

    expect($busy->fresh()->danger_score)->toBe(40 + $this->score->handle(16000))
        ->and($quiet->fresh()->danger_score)->toBe(40 + $this->score->handle(500));
});

/**
 * Rescoring has to be idempotent, or every rematch of the counts would walk
 * the score further from the tags it is supposed to be correcting.
 */
it('does not compound the adjustment when rescored', function () {
    $segment = RoadSegment::factory()->create([
        'base_danger_score' => 50,
        'danger_score' => 50,
        'aadt' => 16000,
        'bike_infra' => RoadSegment::INFRA_NONE,
        'highway_type' => 'primary',
    ]);

    $recalculate = app(RecalculateDangerScores::class);
    $recalculate->handle([$segment->id]);
    $once = $segment->fresh()->danger_score;
    $recalculate->handleAll();

    expect($segment->fresh()->danger_score)->toBe($once);
});

it('keeps the stored score inside the scale when the credit outweighs the tags', function () {
    $segment = RoadSegment::factory()->create([
        'base_danger_score' => 5,
        'danger_score' => 5,
        'aadt' => 46,
        'bike_infra' => RoadSegment::INFRA_NONE,
        'highway_type' => 'tertiary',
    ]);

    app(RecalculateDangerScores::class)->handle([$segment->id]);

    expect($segment->fresh()->danger_score)->toBe(0);
});
