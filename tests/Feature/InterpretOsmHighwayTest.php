<?php

use App\Actions\RoadSegments\InterpretOsmHighway;
use App\Models\RoadSegment;

beforeEach(function () {
    $this->interpret = new InterpretOsmHighway;
});

it('reads united states speed limits as miles per hour', function (string $maxspeed, ?int $expected) {
    expect($this->interpret->speedLimitKmh($maxspeed))->toBe($expected);
})->with([
    '25 mph' => ['25 mph', 40],
    '55 mph' => ['55 mph', 89],
    '65 mph' => ['65 mph', 105],
    'bare number is km/h' => ['50', 50],
    'explicit km/h' => ['80 km/h', 80],
    'no unit given' => ['none', null],
    'signals' => ['signals', null],
    'empty' => ['', null],
]);

/**
 * The column is km/h, but the sign the rider is riding past is in mph, so
 * the figure has to survive the round trip: a limit tagged "55 mph" has to
 * read back as 55, not 54 or 56.
 */
it('reads a stored limit back as the miles per hour it was tagged with', function (string $maxspeed, int $mph) {
    $stored = $this->interpret->speedLimitKmh($maxspeed);

    expect($this->interpret->speedLimitMph($stored))->toBe($mph);
})->with([
    '15 mph' => ['15 mph', 15],
    '25 mph' => ['25 mph', 25],
    '30 mph' => ['30 mph', 30],
    '35 mph' => ['35 mph', 35],
    '40 mph' => ['40 mph', 40],
    '45 mph' => ['45 mph', 45],
    '50 mph' => ['50 mph', 50],
    '55 mph' => ['55 mph', 55],
    '65 mph' => ['65 mph', 65],
]);

/**
 * A way tagged with a bare number was taken as km/h on the way in, so it
 * converts honestly rather than landing on a round mph figure.
 */
it('converts a limit stored in kilometres per hour to the nearest mile', function () {
    expect($this->interpret->speedLimitMph(50))->toBe(31);
});

/**
 * An untagged road is unknown rather than slow, so nothing is invented for
 * it on the way out either.
 */
it('leaves an untagged limit unknown rather than reading it as nought', function () {
    expect($this->interpret->speedLimitMph(null))->toBeNull();
});

it('scores a quiet residential street as low danger', function () {
    $result = $this->interpret->handle([
        'highway' => 'residential',
        'maxspeed' => '25 mph',
    ]);

    expect($result['danger_score'])->toBeLessThan(25)
        ->and($result['speed_limit_kmh'])->toBe(40);
});

it('scores a fast multi lane trunk road as high danger', function () {
    $result = $this->interpret->handle([
        'highway' => 'trunk',
        'maxspeed' => '65 mph',
        'lanes' => '4',
    ]);

    expect($result['danger_score'])->toBeGreaterThan(80);
});

it('treats motorways as the maximum regardless of other tags', function () {
    $result = $this->interpret->handle(['highway' => 'motorway']);

    expect($result['danger_score'])->toBe(RoadSegment::MAX_DANGER);
});

it('scores a cycleway at zero', function () {
    $result = $this->interpret->handle(['highway' => 'cycleway']);

    expect($result['danger_score'])->toBe(0)
        ->and($result['bike_infra'])->toBe(RoadSegment::INFRA_PATH);
});

it('lowers the score when the road has bike infrastructure', function () {
    $without = $this->interpret->handle(['highway' => 'secondary', 'maxspeed' => '35 mph']);
    $painted = $this->interpret->handle(['highway' => 'secondary', 'maxspeed' => '35 mph', 'cycleway' => 'lane']);
    $separated = $this->interpret->handle(['highway' => 'secondary', 'maxspeed' => '35 mph', 'cycleway' => 'track']);

    expect($painted['danger_score'])->toBeLessThan($without['danger_score'])
        ->and($separated['danger_score'])->toBeLessThan($painted['danger_score']);
});

it('recognises bike infrastructure on either side of the road', function (array $tags, string $expected) {
    expect($this->interpret->bikeInfra($tags))->toBe($expected);
})->with([
    'protected track' => [['highway' => 'primary', 'cycleway' => 'track'], RoadSegment::INFRA_PROTECTED],
    'buffered lane' => [['highway' => 'primary', 'cycleway_both' => 'buffered_lane'], RoadSegment::INFRA_BUFFERED],
    'painted on the right' => [['highway' => 'primary', 'cycleway_right' => 'lane'], RoadSegment::INFRA_PAINTED],
    'best of both sides wins' => [['highway' => 'primary', 'cycleway_left' => 'lane', 'cycleway_right' => 'track'], RoadSegment::INFRA_PROTECTED],
    'designated path' => [['highway' => 'path', 'bicycle' => 'designated'], RoadSegment::INFRA_PATH],
    'undesignated path is not infrastructure' => [['highway' => 'path'], RoadSegment::INFRA_NONE],
    'nothing tagged' => [['highway' => 'residential'], RoadSegment::INFRA_NONE],
]);

it('leaves shoulder unknown when the tag is absent', function () {
    // Vermont tags a shoulder on a few dozen ways out of 138,000, so an
    // untagged road must not be scored as though it had none.
    expect($this->interpret->handle(['highway' => 'secondary'])['has_shoulder'])->toBeNull();
});

it('reads the shoulder tag when it is present', function (string $value, ?bool $expected) {
    expect($this->interpret->handle(['highway' => 'secondary', 'shoulder' => $value])['has_shoulder'])->toBe($expected);
})->with([
    'yes' => ['yes', true],
    'both' => ['both', true],
    'no' => ['no', false],
    'nonsense' => ['maybe', null],
]);

it('rewards a tagged shoulder and penalises a tagged absence', function () {
    $unknown = $this->interpret->handle(['highway' => 'secondary', 'maxspeed' => '45 mph']);
    $with = $this->interpret->handle(['highway' => 'secondary', 'maxspeed' => '45 mph', 'shoulder' => 'yes']);
    $without = $this->interpret->handle(['highway' => 'secondary', 'maxspeed' => '45 mph', 'shoulder' => 'no']);

    expect($with['danger_score'])->toBeLessThan($unknown['danger_score'])
        ->and($without['danger_score'])->toBeGreaterThan($unknown['danger_score']);
});

it('parses lane counts and ignores unusable values', function (?string $lanes, ?int $expected) {
    expect($this->interpret->handle(['highway' => 'primary', 'lanes' => $lanes])['lanes'])->toBe($expected);
})->with([
    'simple' => ['2', 2],
    'changing lane count' => ['2;3', 2],
    'zero is meaningless' => ['0', null],
    'not a number' => ['many', null],
    'absent' => [null, null],
]);

it('keeps every score inside the zero to one hundred range', function () {
    $extreme = $this->interpret->handle([
        'highway' => 'motorway',
        'maxspeed' => '80 mph',
        'lanes' => '8',
        'shoulder' => 'no',
    ]);

    $gentle = $this->interpret->handle([
        'highway' => 'cycleway',
        'maxspeed' => '10 mph',
        'shoulder' => 'yes',
    ]);

    expect($extreme['danger_score'])->toBe(100)
        ->and($gentle['danger_score'])->toBe(0);
});

it('falls back to a middling score for an unrecognised road class', function () {
    expect($this->interpret->handle(['highway' => 'elevator'])['danger_score'])->toBe(25);
});

it('treats a route number as a sign of through traffic', function () {
    $local = $this->interpret->handle(['highway' => 'tertiary']);
    $numbered = $this->interpret->handle(['highway' => 'tertiary', 'ref' => 'VT 12']);

    expect($numbered['danger_score'])->toBeGreaterThan($local['danger_score']);
});

it('leaves a numbered through route where the old scoring put it', function () {
    // The class baselines were lowered by exactly the through-route penalty,
    // so a numbered main road must not drift when that penalty is added.
    expect($this->interpret->handle(['highway' => 'primary', 'ref' => 'US 7'])['danger_score'])->toBe(70)
        ->and($this->interpret->handle(['highway' => 'secondary', 'ref' => 'VT 12'])['danger_score'])->toBe(55)
        ->and($this->interpret->handle(['highway' => 'trunk', 'ref' => 'US 2'])['danger_score'])->toBe(85);
});

it('scores a quiet unpaved back road well below a paved through route', function () {
    $backRoad = $this->interpret->handle(['highway' => 'tertiary', 'surface' => 'gravel']);
    $throughRoute = $this->interpret->handle(['highway' => 'primary', 'ref' => 'US 2', 'maxspeed' => '50 mph']);

    expect($backRoad['danger_score'])->toBe(18)
        ->and($throughRoute['danger_score'])->toBeGreaterThan(70);
});

it('recognises the unpaved surfaces vermont actually uses', function (string $surface, bool $unpaved) {
    expect($this->interpret->isUnpaved($surface))->toBe($unpaved);
})->with([
    'gravel' => ['gravel', true],
    'dirt' => ['dirt', true],
    'unpaved' => ['unpaved', true],
    'compacted' => ['compacted', true],
    'ground' => ['ground', true],
    'asphalt' => ['asphalt', false],
    'paved' => ['paved', false],
    'concrete' => ['concrete', false],
    'untagged' => ['', false],
]);

it('does not discount tracks and paths for being unpaved', function () {
    // They already score as near harmless, and a cheaper farm track would
    // only tempt the detour routing in a later phase onto something
    // unrideable.
    $paved = $this->interpret->handle(['highway' => 'track']);
    $gravel = $this->interpret->handle(['highway' => 'track', 'surface' => 'gravel']);

    expect($gravel['danger_score'])->toBe($paved['danger_score']);
});

it('still ranks a back road below the highway it might detour onto', function () {
    $scores = collect([
        'motorway' => ['highway' => 'motorway', 'ref' => 'I 89'],
        'trunk' => ['highway' => 'trunk', 'ref' => 'US 2', 'maxspeed' => '55 mph'],
        'primary' => ['highway' => 'primary', 'ref' => 'VT 100', 'maxspeed' => '50 mph'],
        'secondary' => ['highway' => 'secondary', 'ref' => 'VT 12'],
        'tertiary paved' => ['highway' => 'tertiary'],
        'tertiary gravel' => ['highway' => 'tertiary', 'surface' => 'gravel'],
        'residential' => ['highway' => 'residential'],
        'cycleway' => ['highway' => 'cycleway'],
    ])->map(fn (array $tags): int => $this->interpret->handle($tags)['danger_score']);

    // The ordering is the whole point: a detour must prefer what is lower.
    expect($scores->values()->all())->toBe($scores->values()->sortDesc()->values()->all());
});
