<?php

use App\Actions\RoadSegments\RecalculateDangerScores;
use App\Actions\RoadSegments\ScoreCrashHistory;
use App\Models\Crash;
use App\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->score = new ScoreCrashHistory;
});

/**
 * Most of the network has no recorded crash, and the published export is
 * capped per request, so an empty history is missing evidence rather than
 * evidence of safety. Crediting it would reward roads for being absent.
 */
it('adds nothing for a road with no recorded crashes', function () {
    expect($this->score->handle(0, 500.0))->toBe(0);
});

it('scores a short busy stretch above a long quiet one holding the same count', function () {
    // The real case this came from: Susie Wilson Road collected 9 crashes in
    // 709 m while VT 116 collected 8 over 10.5 km. A bare count calls those
    // the same road.
    expect($this->score->handle(9, 709.0))
        ->toBeGreaterThan($this->score->handle(8, 10_520.0));
});

it('separates crash rates at the tails as much as in the middle', function () {
    // Rates chosen to sit clear of the floor and the cap at both ends, so
    // what is being measured is the curve rather than a clamp.
    $low = $this->score->handle(4, 2000.0) - $this->score->handle(2, 2000.0);
    $high = $this->score->handle(16, 2000.0) - $this->score->handle(8, 2000.0);

    expect($low)->toBe(ScoreCrashHistory::POINTS_PER_DOUBLING)
        ->and($high)->toBe(ScoreCrashHistory::POINTS_PER_DOUBLING);
});

it('gives any recorded crash at least the floor', function () {
    expect($this->score->handle(1, 50_000.0))->toBe(ScoreCrashHistory::MIN_PENALTY);
});

it('never adds more than the cap', function () {
    expect($this->score->handle(500, 10.0))->toBe(ScoreCrashHistory::MAX_PENALTY);
});

/**
 * Three quarters of the roads with a crash have exactly one, so a lone
 * report on a very short segment must not read like a blackspot.
 */
it('will not let one crash on a short segment reach the cap', function () {
    expect($this->score->handle(1, 20.0))->toBe(ScoreCrashHistory::MAX_PENALTY_PER_CRASH)
        ->and($this->score->handle(1, 20.0))->toBeLessThan(ScoreCrashHistory::MAX_PENALTY);
});

/**
 * Otherwise importing the full history instead of one year would make every
 * road look worse rather than better evidenced.
 */
it('reads a longer history as a rate rather than as a worse road', function () {
    $oneYear = $this->score->handle(10, 500.0, 1.0);
    $tenYears = $this->score->handle(10, 500.0, 10.0);

    expect($tenYears)->toBeLessThan($oneYear);
});

it('treats a sub-year export as a single year rather than scaling it up', function () {
    expect($this->score->handle(4, 500.0, 0.25))->toBe($this->score->handle(4, 500.0, 1.0));
});

it('calculates the same adjustment in postgres as in php', function (int $count, float $length) {
    // Two riding seasons, each a bit over four months, so the span is a real
    // seasonal one rather than a single day that would floor to one year.
    Crash::factory()->create(['occurred_at' => '2024-04-01 00:00:00']);
    Crash::factory()->create(['occurred_at' => '2024-11-29 00:00:00']);
    Crash::factory()->create(['occurred_at' => '2025-04-01 00:00:00']);
    Crash::factory()->create(['occurred_at' => '2025-11-29 00:00:00']);

    $segment = RoadSegment::factory()->straight($length)->create(['crash_count' => $count]);

    $expression = ScoreCrashHistory::sqlExpression('rs.crash_count', 'rs.geom');

    $row = DB::selectOne(
        "select ({$expression}) as adjustment, ST_Length(rs.geom::geography) as length_m
         from road_segments rs where rs.id = ?",
        [$segment->id],
    );

    // Derived the same way the expression derives it, rather than written in
    // as a number that only happens to round to the same answer.
    $years = (float) DB::selectOne(
        'select greatest(1.0, sum(season.span) / 365.25) as years
         from (
            select max(occurred_at)::date - min(occurred_at)::date + 1 as span
            from crashes where occurred_at is not null
            group by extract(year from occurred_at)
         ) season'
    )->years;

    expect((int) $row->adjustment)
        ->toBe($this->score->handle($count, (float) $row->length_m, $years));
})->with([
    'no crashes' => [0, 500.0],
    'one crash on a long road' => [1, 2000.0],
    'one crash on a short road' => [1, 60.0],
    'a handful on a short road' => [4, 300.0],
    'a blackspot' => [12, 250.0],
]);

it('folds the crash history into the stored danger score', function () {
    Crash::factory()->create(['occurred_at' => '2015-06-01 00:00:00']);

    $segment = RoadSegment::factory()->straight(500.0)->create([
        'base_danger_score' => 30,
        'danger_score' => 30,
        'crash_count' => 3,
    ]);

    app(RecalculateDangerScores::class)->handle([$segment->id]);

    expect($segment->fresh()->danger_score)->toBe(30 + $this->score->handle(3, 500.0));
});

it('does not compound the crash term when rescored', function () {
    Crash::factory()->create(['occurred_at' => '2015-06-01 00:00:00']);

    $segment = RoadSegment::factory()->straight(400.0)->create([
        'base_danger_score' => 40,
        'danger_score' => 40,
        'crash_count' => 5,
    ]);

    $recalculate = app(RecalculateDangerScores::class);
    $recalculate->handle([$segment->id]);
    $once = $segment->fresh()->danger_score;
    $recalculate->handleAll();

    expect($segment->fresh()->danger_score)->toBe($once);
});

/**
 * The exports run April to November. Measuring the span from the first crash
 * to the last would count each closed season as time somebody was watching,
 * which across seven years of these files reads 6.54 years of exposure where
 * only 3.33 were actually observed, halving every rate.
 */
it('does not count the closed season between two exports as observed time', function () {
    // Two seasons of roughly eight months, two and a half years apart.
    Crash::factory()->create(['occurred_at' => '2023-04-01 00:00:00']);
    Crash::factory()->create(['occurred_at' => '2023-11-29 00:00:00']);
    Crash::factory()->create(['occurred_at' => '2025-04-01 00:00:00']);
    Crash::factory()->create(['occurred_at' => '2025-11-29 00:00:00']);

    $segment = RoadSegment::factory()->straight(1000.0)->create(['crash_count' => 8]);

    $expression = ScoreCrashHistory::sqlExpression('rs.crash_count', 'rs.geom');

    $adjustment = (int) DB::selectOne(
        "select ({$expression}) as adjustment from road_segments rs where rs.id = ?",
        [$segment->id],
    )->adjustment;

    // Two 243 day seasons is 1.33 years observed, not the 2.67 the calendar
    // distance between the first and last crash would suggest.
    expect($adjustment)->toBe($this->score->handle(8, 1000.0, 1.33))
        ->and($adjustment)->toBeGreaterThan($this->score->handle(8, 1000.0, 2.67));
});
