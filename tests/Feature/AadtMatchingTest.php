<?php

use App\Actions\Aadt\MatchAadtToRoadSegments;
use App\Models\AadtSection;
use App\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Roughly 11 metres of latitude, which is inside the match buffer: a
 * published section is drawn along a route's centreline, so it never sits
 * exactly on the way a rider is matched to.
 */
const ALONGSIDE = 0.0001;

/**
 * Roughly 110 metres, which is well outside it.
 */
const ELSEWHERE = 0.001;

/**
 * A north-south road, a kilometre of it per hundredth of a degree.
 *
 * @param  array<string, mixed>  $attributes
 */
function roadRunningNorth(float $fromLatitude, float $toLatitude, array $attributes = []): RoadSegment
{
    return RoadSegment::factory()
        ->between($fromLatitude, -72.58, $toLatitude, -72.58)
        ->create($attributes);
}

/**
 * A counted section beside that road, offset east by a given amount.
 *
 * @param  array<string, mixed>  $attributes
 */
function countedSectionAlong(
    float $fromLatitude,
    float $toLatitude,
    int $aadt,
    float $offset = ALONGSIDE,
    array $attributes = [],
): AadtSection {
    return AadtSection::factory()
        ->between($fromLatitude, -72.58 + $offset, $toLatitude, -72.58 + $offset)
        ->create(['aadt' => $aadt, 'year' => 2025, ...$attributes]);
}

it('gives a road the count of the section running alongside it', function () {
    $road = roadRunningNorth(44.26, 44.27);
    $section = countedSectionAlong(44.255, 44.275, aadt: 8_400);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($result['year'])->toBe(2025)
        ->and($result['roads_measured'])->toBe(1);

    $road->refresh();

    expect($road->aadt)->toBe(8_400)
        ->and($road->aadt_year)->toBe(2025)
        ->and($road->aadt_sample_share)->toBe(1.0)
        ->and($road->aadtSections->pluck('id')->all())->toBe([$section->id]);
});

it('leaves a road alone when the nearest count is on another road', function () {
    $road = roadRunningNorth(44.26, 44.27);
    countedSectionAlong(44.255, 44.275, aadt: 8_400, offset: ELSEWHERE);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($result['roads_measured'])->toBe(0)
        ->and($road->refresh()->aadt)->toBeNull();
});

it('will not take a count from a road crossing it', function () {
    $road = roadRunningNorth(44.26, 44.27);

    // Running east to west over the same ground: close enough to match on
    // distance alone, which is what the bearing test is there to stop.
    AadtSection::factory()
        ->between(44.265, -72.59, 44.265, -72.57)
        ->create(['aadt' => 20_000, 'year' => 2025]);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($road->refresh()->aadt)->toBeNull();
});

it('leaves a road unmeasured when too little of it was counted', function () {
    $road = roadRunningNorth(44.26, 44.28);

    // A count over the southern quarter only, which is under the share a
    // road has to match before its figure is believed.
    countedSectionAlong(44.26, 44.265, aadt: 8_400);

    app(MatchAadtToRoadSegments::class)->handle();

    $road->refresh();

    expect($road->aadt)->toBeNull()
        ->and($road->aadt_sample_share)->toBeNull();
});

it('averages over the sections covering a road', function () {
    $road = roadRunningNorth(44.26, 44.28);

    // Two counts, each over half the road: 1,000 to the south, 9,000 north.
    countedSectionAlong(44.2599, 44.27, aadt: 1_000);
    countedSectionAlong(44.2701, 44.2801, aadt: 9_000);

    app(MatchAadtToRoadSegments::class)->handle();

    $road->refresh();

    expect($road->aadt)->toBeGreaterThan(4_000)
        ->and($road->aadt)->toBeLessThan(6_000)
        ->and($road->aadtSections)->toHaveCount(2);
});

it('ignores the counts published for freeway ramps', function () {
    $road = roadRunningNorth(44.26, 44.27);
    countedSectionAlong(44.255, 44.275, aadt: 8_400, attributes: [
        'route_type' => AadtSection::ROUTE_TYPE_RAMP,
    ]);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($road->refresh()->aadt)->toBeNull();
});

it('takes back a count when a rerun no longer supports it', function () {
    $road = roadRunningNorth(44.26, 44.27);
    $section = countedSectionAlong(44.255, 44.275, aadt: 8_400);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($road->refresh()->aadt)->toBe(8_400);

    // The section is republished somewhere else entirely.
    $section->delete();
    countedSectionAlong(44.255, 44.275, aadt: 8_400, offset: ELSEWHERE, attributes: ['ete_id' => 'S0002']);

    app(MatchAadtToRoadSegments::class)->handle();

    $road->refresh();

    expect($road->aadt)->toBeNull()
        ->and($road->aadt_year)->toBeNull()
        ->and($road->aadtSections)->toHaveCount(0);
});

it('matches an older year when asked for one', function () {
    $road = roadRunningNorth(44.26, 44.27);
    countedSectionAlong(44.255, 44.275, aadt: 8_400, attributes: ['ete_id' => 'S0001']);
    countedSectionAlong(44.255, 44.275, aadt: 5_100, attributes: ['ete_id' => 'S0002', 'year' => 2019]);

    app(MatchAadtToRoadSegments::class)->handle(2019);

    $road->refresh();

    expect($road->aadt)->toBe(5_100)
        ->and($road->aadt_year)->toBe(2019);
});

it('reports nothing to do when no counts have been imported', function () {
    $this->artisan('aadt:match')
        ->expectsOutputToContain('No counts have been imported')
        ->assertFailed();
});

it('counts a hyphenated interstate ref as agreeing with its route code', function () {
    roadRunningNorth(44.26, 44.27, ['ref' => 'I-91']);
    countedSectionAlong(44.255, 44.275, aadt: 28_000, attributes: ['route_num' => 'I091-S', 'route_type' => 'I']);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($result['compared'])->toBeGreaterThan(0)
        ->and($result['agreeing'])->toBe($result['compared'])
        ->and($result['unchecked'])->toBe(0);
});

it('treats a concurrent route tagged in one ref as agreeing', function () {
    roadRunningNorth(44.26, 44.27, ['ref' => 'US 2;VT 100']);
    countedSectionAlong(44.255, 44.275, aadt: 9_000, attributes: ['route_num' => 'V100', 'route_type' => 'VT']);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($result['agreeing'])->toBe($result['compared']);
});

it('does not confuse a route with its lettered sibling', function () {
    roadRunningNorth(44.26, 44.27, ['ref' => 'VT 10A']);
    countedSectionAlong(44.255, 44.275, aadt: 4_000, attributes: ['route_num' => 'V010', 'route_type' => 'VT']);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    // VT 10 and VT 10A are different roads, so this must not be called agreement.
    expect($result['agreeing'])->toBe(0)
        ->and($result['compared'])->toBeGreaterThan(0);
});

it('counts a town highway as unchecked rather than as disagreeing', function () {
    roadRunningNorth(44.26, 44.27, ['ref' => 'VT 12']);

    // VTrans codes town highways on its own scheme, which OpenStreetMap has
    // no equivalent for, so the comparison is meaningless either way.
    countedSectionAlong(44.255, 44.275, aadt: 1_500, attributes: ['route_num' => 'S3004', 'route_type' => 'FAU']);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($result['compared'])->toBe(0)
        ->and($result['unchecked'])->toBeGreaterThan(0);
});

/**
 * Roughly 16 metres east at this latitude: inside the match buffer, but far
 * enough off a section for a road sitting on it to be clearly nearer.
 */
const BESIDE = 0.0002;

it('will not give a bike path the traffic of the road beside it', function () {
    // The bug this is here for: a separated path runs alongside a busy road
    // on the same bearing and well inside the buffer, so distance and
    // bearing alone handed it the road's count. VTrans publishes no count
    // for a cycleway, and a rider on one is not in that traffic.
    $path = roadRunningNorth(44.26, 44.27, [
        'highway_type' => 'cycleway',
        'bike_infra' => RoadSegment::INFRA_PATH,
    ]);

    countedSectionAlong(44.255, 44.275, aadt: 28_000);

    app(MatchAadtToRoadSegments::class)->handle();

    $path->refresh();

    expect($path->aadt)->toBeNull()
        ->and($path->aadtSections)->toHaveCount(0);
});

it('will not give a driveway or a farm track a highway count either', function () {
    $service = roadRunningNorth(44.26, 44.27, ['highway_type' => 'service']);
    $track = roadRunningNorth(44.26, 44.27, ['highway_type' => 'track']);

    countedSectionAlong(44.255, 44.275, aadt: 12_000);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($service->refresh()->aadt)->toBeNull()
        ->and($track->refresh()->aadt)->toBeNull();
});

it('gives a count to the road it was drawn down rather than the street behind', function () {
    $onTheSection = RoadSegment::factory()
        ->between(44.26, -72.58 + BESIDE, 44.27, -72.58 + BESIDE)
        ->create();

    $behind = roadRunningNorth(44.26, 44.27);

    countedSectionAlong(44.255, 44.275, aadt: 6_000, offset: BESIDE);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($onTheSection->refresh()->aadt)->toBe(6_000)
        ->and($behind->refresh()->aadt)->toBeNull()
        ->and($result['rivalled'])->toBeGreaterThan(0);
});

it('keeps both carriageways of a divided highway measured', function () {
    // VTrans draws some divided sections down one carriageway, which leaves
    // the other one the far side of a median and so further from the count
    // than its twin. It is still the same road, which the route number says.
    $carriageway = ['ref' => 'US 7', 'name' => 'Memorial Highway', 'highway_type' => 'trunk'];

    $northbound = RoadSegment::factory()
        ->between(44.26, -72.58 + BESIDE, 44.27, -72.58 + BESIDE)
        ->create($carriageway);

    $southbound = roadRunningNorth(44.26, 44.27, $carriageway);

    countedSectionAlong(44.255, 44.275, aadt: 15_000, offset: BESIDE, attributes: [
        'route_num' => 'U007-N',
        'route_type' => 'US',
    ]);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($northbound->refresh()->aadt)->toBe(15_000)
        ->and($southbound->refresh()->aadt)->toBe(15_000);
});

it('will not read a numbered route onto a local street', function () {
    $street = roadRunningNorth(44.26, 44.27, ['highway_type' => 'residential']);

    countedSectionAlong(44.255, 44.275, aadt: 19_000, attributes: [
        'route_num' => 'U007',
        'route_type' => 'US',
    ]);

    $result = app(MatchAadtToRoadSegments::class)->handle();

    expect($street->refresh()->aadt)->toBeNull()
        ->and($result['wrong_route'])->toBeGreaterThan(0);
});

it('still measures a through road that has no route number tagged', function () {
    // OpenStreetMap leaves `ref` off a numbered road often enough that a
    // missing number cannot be read as the wrong road.
    $road = roadRunningNorth(44.26, 44.27, ['highway_type' => 'primary', 'ref' => null]);

    countedSectionAlong(44.255, 44.275, aadt: 19_000, attributes: [
        'route_num' => 'U007',
        'route_type' => 'US',
    ]);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($road->refresh()->aadt)->toBe(19_000);
});

it('will not give a road the count of a route it says it is not on', function () {
    $road = roadRunningNorth(44.26, 44.27, ['highway_type' => 'secondary', 'ref' => 'VT 10A']);

    countedSectionAlong(44.255, 44.275, aadt: 4_000, attributes: [
        'route_num' => 'V010',
        'route_type' => 'VT',
    ]);

    app(MatchAadtToRoadSegments::class)->handle();

    expect($road->refresh()->aadt)->toBeNull();
});

it('reports what the matching refused', function () {
    roadRunningNorth(44.26, 44.27, ['highway_type' => 'residential']);
    countedSectionAlong(44.255, 44.275, aadt: 19_000, attributes: [
        'route_num' => 'U007',
        'route_type' => 'US',
    ]);

    $this->artisan('aadt:match')
        ->expectsOutputToContain('Refused as another route')
        ->assertSuccessful();
});
