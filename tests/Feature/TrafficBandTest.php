<?php

use App\Actions\Aadt\TrafficBand;
use App\Actions\RoadSegments\ScoreTrafficVolume;

it('bands a count by how busy the road actually is', function (?int $aadt, TrafficBand $band) {
    expect(TrafficBand::for($aadt))->toBe($band);
})->with([
    'nothing published' => [null, TrafficBand::NotCounted],
    'a back lane' => [120, TrafficBand::Quiet],
    'just under the first step' => [999, TrafficBand::Quiet],
    'a village street' => [2_500, TrafficBand::Moderate],
    'the typical counted road' => [4_000, TrafficBand::Busy],
    'a numbered highway' => [9_000, TrafficBand::Busy],
    'an arterial' => [20_000, TrafficBand::Heavy],
    'the busiest road in the state' => [43_274, TrafficBand::Heavy],
]);

/**
 * A count of zero is a road nobody has counted recorded badly, not a road
 * with no traffic on it.
 */
it('treats a nought count as no count at all', function () {
    expect(TrafficBand::for(0))->toBe(TrafficBand::NotCounted);
});

/**
 * The pivot is the median of the measured network, so it has to land on a
 * band boundary rather than in the middle of one: the typical counted road
 * is exactly where "moderate" stops.
 */
it('breaks its bands on the figure the danger score pivots at', function () {
    expect(TrafficBand::for(ScoreTrafficVolume::PIVOT_AADT - 1))->toBe(TrafficBand::Moderate)
        ->and(TrafficBand::for(ScoreTrafficVolume::PIVOT_AADT))->toBe(TrafficBand::Busy);
});

it('says the counts each band covers, not just what it calls them', function () {
    expect(TrafficBand::Heavy->range())->toContain('12,000')
        ->and(TrafficBand::Quiet->range())->toContain('1,000')
        ->and(TrafficBand::NotCounted->range())->not->toContain('0');
});

it('lists the bands quietest first, with the uncounted off the end', function () {
    expect(TrafficBand::ladder())->toBe([
        TrafficBand::Quiet,
        TrafficBand::Moderate,
        TrafficBand::Busy,
        TrafficBand::Heavy,
        TrafficBand::NotCounted,
    ]);
});
