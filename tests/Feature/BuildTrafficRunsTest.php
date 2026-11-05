<?php

use App\Actions\Aadt\TrafficBand;
use App\Actions\Analysis\BuildTrafficRuns;
use App\Actions\Analysis\RouteSample;

/**
 * Build evenly spaced samples from a list of traffic counts.
 *
 * @param  list<int|null>  $counts
 * @return list<RouteSample>
 */
function trafficSamplesFrom(array $counts): array
{
    $total = count($counts);

    return array_map(
        fn (?int $aadt, int $i): RouteSample => new RouteSample(
            fraction: $total > 1 ? $i / ($total - 1) : 0.0,
            roadSegmentId: 100 + $i,
            dangerScore: 20,
            gapMetres: 1.0,
            roadKey: 'Road A',
            aadt: $aadt,
        ),
        $counts,
        array_keys($counts),
    );
}

/**
 * Build evenly spaced samples from a list of the roads ridden, each given as
 * `[roadKey, roadName, aadt]`.
 *
 * @param  list<array{string|null, string|null, int|null}>  $roads
 * @return list<RouteSample>
 */
function trafficSamplesOn(array $roads): array
{
    $total = count($roads);

    return array_map(
        fn (array $road, int $i): RouteSample => new RouteSample(
            fraction: $total > 1 ? $i / ($total - 1) : 0.0,
            roadSegmentId: 100 + $i,
            dangerScore: 20,
            gapMetres: 1.0,
            roadKey: $road[0],
            aadt: $road[2],
            roadName: $road[1],
        ),
        $roads,
        array_keys($roads),
    );
}

it('reports a route on one band as a single stretch', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesFrom([2_000, 2_200, 2_400]),
        routeLengthMetres: 10_000,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->band)->toBe(TrafficBand::Moderate)
        ->and($runs[0]->startFraction)->toBe(0.0)
        ->and($runs[0]->endFraction)->toBe(1.0)
        ->and($runs[0]->lengthMetres)->toBe(10_000);
});

it('cuts the route where it moves into a different band', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesFrom([500, 500, 20_000, 20_000]),
        routeLengthMetres: 12_000,
    );

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->band)->toBe(TrafficBand::Quiet)
        ->and($runs[1]->band)->toBe(TrafficBand::Heavy);
});

/**
 * The colouring is of the whole ride, so the stretches have to meet: a gap
 * would leave the bare route line showing through as a colour of its own.
 */
it('leaves no gap between one stretch and the next', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesFrom([500, 500, 20_000, 20_000, 2_000]),
        routeLengthMetres: 12_000,
    );

    expect($runs[0]->startFraction)->toBe(0.0)
        ->and($runs[count($runs) - 1]->endFraction)->toBe(1.0);

    foreach ($runs as $i => $run) {
        if ($i > 0) {
            expect($run->startFraction)->toBe($runs[$i - 1]->endFraction);
        }
    }
});

it('reports the heaviest count along a stretch rather than an average', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesFrom([5_000, 11_000, 6_000]),
        routeLengthMetres: 10_000,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->aadt)->toBe(11_000);
});

/**
 * Most of a rural route runs on roads nobody has counted, and that is not
 * the same as a route that runs on quiet ones.
 */
it('keeps the uncounted stretches as their own band with no figure', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesFrom([null, null, 9_000, 9_000]),
        routeLengthMetres: 10_000,
    );

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->band)->toBe(TrafficBand::NotCounted)
        ->and($runs[0]->aadt)->toBeNull()
        ->and($runs[1]->aadt)->toBe(9_000);
});

/**
 * The list beside the map is of roads, so a stretch has to be one road: a
 * highway and the lane it turns onto are two entries even where they carry
 * comparable traffic.
 */
it('cuts the route where it turns onto a different road', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesOn([
            ['VT 12', 'VT 12', 2_000],
            ['VT 12', 'VT 12', 2_200],
            ['Dog River Road', 'Dog River Road', 2_400],
            ['Dog River Road', 'Dog River Road', 2_100],
        ]),
        routeLengthMetres: 12_000,
    );

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->band)->toBe(TrafficBand::Moderate)
        ->and($runs[1]->band)->toBe(TrafficBand::Moderate)
        ->and($runs[0]->roadName)->toBe('VT 12')
        ->and($runs[1]->roadName)->toBe('Dog River Road');
});

/**
 * An unnamed lane is named by nothing, rather than by its road class: the
 * class is only there to tell one lane from the next.
 */
it('leaves a stretch unnamed where the road carries no name', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesOn([
            ['residential', null, 400],
            ['residential', null, 500],
        ]),
        routeLengthMetres: 2_000,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->roadName)->toBeNull();
});

/**
 * Two different lanes with no name between them are still two roads, which
 * is why the split is made on the key rather than on the name.
 */
it('tells two unnamed lanes apart', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesOn([
            ['residential', null, 400],
            ['track', null, 500],
        ]),
        routeLengthMetres: 2_000,
    );

    expect($runs)->toHaveCount(2);
});

it('has nothing to colour on a route with no samples', function () {
    expect((new BuildTrafficRuns)->handle([], routeLengthMetres: 10_000))->toBeEmpty();
});

/**
 * Build evenly spaced samples from a list of posted speed limits, all on
 * one road carrying one band of traffic.
 *
 * @param  list<int|null>  $limits
 * @return list<RouteSample>
 */
function trafficSamplesPostedAt(array $limits): array
{
    $total = count($limits);

    return array_map(
        fn (?int $limit, int $i): RouteSample => new RouteSample(
            fraction: $total > 1 ? $i / ($total - 1) : 0.0,
            roadSegmentId: 100 + $i,
            dangerScore: 20,
            gapMetres: 1.0,
            roadKey: 'Road A',
            aadt: 2_000,
            speedLimitKmh: $limit,
        ),
        $limits,
        array_keys($limits),
    );
}

/**
 * A stretch is drawn in one colour and labelled with one figure. Of the
 * limits it could carry, the fastest is the one the rider is choosing
 * against, which is the same reason the heaviest count is the one reported.
 */
it('reports the fastest limit posted along a stretch', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesPostedAt([50, 88, 72]),
        routeLengthMetres: 10_000,
    );

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->speedLimitKmh)->toBe(88);
});

/**
 * A limit on part of a stretch is still the only figure there is for it, so
 * it is reported rather than thrown away for being incomplete.
 */
it('reports the limit from the part of a stretch that carries one', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesPostedAt([null, 72, null]),
        routeLengthMetres: 10_000,
    );

    expect($runs[0]->speedLimitKmh)->toBe(72);
});

/**
 * An untagged road is unknown rather than slow, so nothing is invented for
 * it: that is the ordinary case here, not an edge one.
 */
it('leaves the limit unknown where no part of a stretch is tagged', function () {
    $runs = (new BuildTrafficRuns)->handle(
        trafficSamplesPostedAt([null, null]),
        routeLengthMetres: 10_000,
    );

    expect($runs[0]->speedLimitKmh)->toBeNull();
});
