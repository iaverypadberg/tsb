<?php

use App\Actions\Analysis\BuildFlaggedRuns;
use App\Actions\Analysis\RouteSample;

/**
 * Build evenly spaced samples from a list of danger scores.
 *
 * @param  list<int>  $scores
 * @param  list<string|null>|null  $roads
 * @return list<RouteSample>
 */
function samplesFrom(array $scores, ?array $roads = null): array
{
    $count = count($scores);

    return array_map(
        fn (int $score, int $i): RouteSample => new RouteSample(
            fraction: $count > 1 ? $i / ($count - 1) : 0.0,
            roadSegmentId: $score > 0 ? 100 + $i : null,
            dangerScore: $score,
            gapMetres: 1.0,
            roadKey: $roads[$i] ?? 'Road A',
        ),
        $scores,
        array_keys($scores),
    );
}

it('finds nothing when the route is calm throughout', function () {
    $runs = (new BuildFlaggedRuns)->handle(samplesFrom([10, 10, 10, 10, 10]), threshold: 60, routeLengthMetres: 10_000);

    expect($runs)->toBeEmpty();
});

it('merges consecutive dangerous samples into one section', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([10, 80, 80, 80, 10]),
        threshold: 60,
        routeLengthMetres: 10_000,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->maxDanger)->toBe(80);
});

it('reports two sections when a calm stretch separates them', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([80, 80, 10, 10, 80, 80]),
        threshold: 60,
        routeLengthMetres: 20_000,
    );

    expect($runs)->toHaveCount(2);
});

it('splits a section where the route moves onto a different road', function () {
    // Continuously dangerous, but half on one highway and half on another:
    // one detour could not cover both.
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom(
            [80, 80, 80, 90, 90, 90],
            ['US 2', 'US 2', 'US 2', 'VT 100B', 'VT 100B', 'VT 100B'],
        ),
        threshold: 60,
        routeLengthMetres: 20_000,
    );

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->maxDanger)->toBe(80)
        ->and($runs[1]->maxDanger)->toBe(90);
});

it('keeps one section while the road keeps its name', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([80, 80, 80, 80], ['US 2', 'US 2', 'US 2', 'US 2']),
        threshold: 60,
        routeLengthMetres: 20_000,
    );

    expect($runs)->toHaveCount(1);
});

it('respects the threshold it is given', function () {
    $samples = samplesFrom([10, 50, 50, 10]);

    expect((new BuildFlaggedRuns)->handle($samples, threshold: 40, routeLengthMetres: 10_000))->toHaveCount(1)
        ->and((new BuildFlaggedRuns)->handle($samples, threshold: 60, routeLengthMetres: 10_000))->toBeEmpty();
});

it('drops a stretch too short to be worth a detour', function () {
    // Five samples over 150 metres: the middle one covers a quarter of that,
    // under the 50 metre floor.
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([10, 10, 70, 10, 10]),
        threshold: 60,
        routeLengthMetres: 150,
    );

    expect($runs)->toBeEmpty();
});

it('keeps a stretch that just reaches the minimum length', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([10, 10, 70, 10, 10]),
        threshold: 60,
        routeLengthMetres: 200,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->lengthMetres)->toBe(BuildFlaggedRuns::MIN_SECTION_LENGTH_M);
});

it('keeps a short stretch when the road is appalling', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([10, 10, 95, 10, 10]),
        threshold: 60,
        routeLengthMetres: 150,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->maxDanger)->toBe(95);
});

it('gives a lone dangerous sample the width of one sampling step', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([10, 10, 95, 10, 10]),
        threshold: 60,
        routeLengthMetres: 10_000,
    );

    // Samples sit a quarter of the route apart, so half a step either side
    // of the middle one covers a quarter of 10 km.
    expect($runs[0]->lengthMetres)->toBe(2_500);
});

it('records every road a section touches', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([80, 80, 80]),
        threshold: 60,
        routeLengthMetres: 10_000,
    );

    expect($runs[0]->roadSegmentIds)->toBe([100, 101, 102]);
});

it('handles a route that is dangerous from start to finish', function () {
    $runs = (new BuildFlaggedRuns)->handle(
        samplesFrom([80, 80, 80, 80]),
        threshold: 60,
        routeLengthMetres: 10_000,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->startFraction)->toBe(0.0)
        ->and($runs[0]->endFraction)->toBe(1.0)
        ->and($runs[0]->lengthMetres)->toBe(10_000);
});

it('ignores unmatched samples however the threshold is set', function () {
    $samples = [
        new RouteSample(fraction: 0.0, roadSegmentId: null, dangerScore: 0, gapMetres: null),
        new RouteSample(fraction: 0.5, roadSegmentId: null, dangerScore: 0, gapMetres: null),
        new RouteSample(fraction: 1.0, roadSegmentId: null, dangerScore: 0, gapMetres: null),
    ];

    expect((new BuildFlaggedRuns)->handle($samples, threshold: 0, routeLengthMetres: 10_000))->toBeEmpty();
});
