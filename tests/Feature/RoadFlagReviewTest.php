<?php

use App\Actions\RoadFlags\SelectRoad;
use App\Actions\RoadFlags\StoreRoadFlag;
use App\Models\RoadFlag;
use App\Models\RoadSegment;
use App\Models\SegmentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->rider = User::factory()->create();
});

/**
 * A flag covering one whole way, submitted the way the page submits it.
 */
function flagWholeRoad(RoadSegment $segment, User $rider, int $severity = 4, string $notes = 'Trucks pass far too close along the whole of this road.'): RoadFlag
{
    return app(StoreRoadFlag::class)->handle(
        $rider,
        app(SelectRoad::class)->forSegments([$segment->id]),
        ['No shoulder', 'Blind bend'],
        $severity,
        $notes,
    );
}

function roadSegmentAt(float $fromLatitude, float $toLatitude, int $dangerScore = 20): RoadSegment
{
    return RoadSegment::factory()
        ->between($fromLatitude, -72.58, $toLatitude, -72.58)
        ->create(['danger_score' => $dangerScore, 'base_danger_score' => $dangerScore]);
}

it('keeps the review queue away from ordinary riders', function () {
    $this->actingAs($this->rider)
        ->get(route('admin.road-flags'))
        ->assertForbidden();
});

it('shows a maintainer what a rider said and where', function () {
    $segment = roadSegmentAt(44.26, 44.27);
    flagWholeRoad($segment, $this->rider, notes: 'The bridge has no shoulder at all.');

    $this->actingAs($this->admin)
        ->get(route('admin.road-flags'))
        ->assertOk()
        ->assertSee('The bridge has no shoulder at all.')
        ->assertSee('No shoulder · Blind bend')
        ->assertSee('1 flag is waiting to be reviewed.')
        ->assertSee($this->rider->name);
});

it('opens the review map on the first flag in the queue', function () {
    $segment = roadSegmentAt(44.26, 44.27);
    flagWholeRoad($segment, $this->rider);

    $this->actingAs($this->admin)
        ->get(route('admin.road-flags'))
        ->assertOk()
        ->assertSee('flagMap(JSON.parse(', escape: false)
        // @js escapes the quotes in the geometry it hands the map.
        ->assertSee('coordinates\u0022:[[44.26,-72.58]', escape: false);
});

it('raises the danger score of every road an accepted flag covers', function () {
    $segment = roadSegmentAt(44.26, 44.27, dangerScore: 20);
    $flag = flagWholeRoad($segment, $this->rider, severity: 4);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.road-flags')
        ->set('reviewNotes.'.$flag->id, 'Confirmed on the ground.')
        ->call('accept', $flag->id);

    expect($flag->refresh()->status)->toBe(RoadFlag::STATUS_ACCEPTED)
        ->and($flag->reviewed_by_id)->toBe($this->admin->id)
        ->and($flag->review_note)->toBe('Confirmed on the ground.');

    $report = SegmentReport::sole();

    expect($report->road_segment_id)->toBe($segment->id)
        ->and($report->source)->toBe(SegmentReport::SOURCE_RIDER)
        ->and($report->user_id)->toBe($this->rider->id)
        // Every reason the rider gave reaches the report the scoring reads.
        ->and($report->reason)->toBe('No shoulder · Blind bend');

    // Five points of score per point of severity, on top of the tags.
    expect($segment->refresh()->danger_score)->toBe(40)
        ->and($segment->base_danger_score)->toBe(20);
});

it('does not stack the same flag up twice when it is accepted again', function () {
    $segment = roadSegmentAt(44.26, 44.27, dangerScore: 20);
    $flag = flagWholeRoad($segment, $this->rider, severity: 4);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.road-flags');

    $component->call('accept', $flag->id);
    $component->call('accept', $flag->id);

    expect(SegmentReport::count())->toBe(1)
        ->and($segment->refresh()->danger_score)->toBe(40);
});

it('gives the score back when a flag is rejected', function () {
    $segment = roadSegmentAt(44.26, 44.27, dangerScore: 20);
    $flag = flagWholeRoad($segment, $this->rider, severity: 4);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.road-flags');

    $component->call('accept', $flag->id);
    expect($segment->refresh()->danger_score)->toBe(40);

    $component->call('reject', $flag->id);

    expect($flag->refresh()->status)->toBe(RoadFlag::STATUS_REJECTED)
        ->and(SegmentReport::count())->toBe(0)
        ->and($segment->refresh()->danger_score)->toBe(20);
});

it('caps how far rider reports can push a score', function () {
    $segment = roadSegmentAt(44.26, 44.27, dangerScore: 50);

    $component = Livewire::actingAs($this->admin)->test('pages::admin.road-flags');

    foreach (range(1, 3) as $ignored) {
        $component->call('accept', flagWholeRoad($segment, $this->rider, severity: 5)->id);
    }

    // Three reports at five would be 75 points; reports stop at 30.
    expect($segment->refresh()->danger_score)->toBe(80);
});

it('accepts a flag covering every way of a road', function () {
    $first = roadSegmentAt(44.26, 44.27);
    $second = roadSegmentAt(44.27, 44.28);

    $flag = app(StoreRoadFlag::class)->handle(
        $this->rider,
        app(SelectRoad::class)->forSegments([$first->id, $second->id]),
        ['Fast traffic'],
        3,
        'The whole road is a rat run for log trucks at the moment.',
    );

    Livewire::actingAs($this->admin)
        ->test('pages::admin.road-flags')
        ->call('accept', $flag->id);

    expect(SegmentReport::count())->toBe(2)
        ->and($first->refresh()->danger_score)->toBe(35)
        ->and($second->refresh()->danger_score)->toBe(35);
});

it('shows the rider what the maintainer said back', function () {
    $segment = roadSegmentAt(44.26, 44.27);
    $flag = flagWholeRoad($segment, $this->rider);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.road-flags')
        ->set('reviewNotes.'.$flag->id, 'Already fixed, the shoulder was widened in May.')
        ->call('reject', $flag->id);

    $this->actingAs($this->rider)
        ->get(route('flags.index'))
        ->assertOk()
        ->assertSee('Rejected')
        ->assertSee('Already fixed, the shoulder was widened in May.');
});

it('leaves flags alone that the maintainer has not looked at', function () {
    $segment = roadSegmentAt(44.26, 44.27, dangerScore: 20);
    flagWholeRoad($segment, $this->rider, severity: 5);

    expect(SegmentReport::count())->toBe(0)
        ->and($segment->refresh()->danger_score)->toBe(20);
});
