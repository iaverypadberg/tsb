<?php

use App\Actions\RoadFlags\RoadSegmentsInView;
use App\Models\RoadFlag;
use App\Models\RoadSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * A north-south way running between two latitudes, roughly 1.1 km of road
 * per hundredth of a degree.
 *
 * @param  array<string, mixed>  $attributes
 */
function wayBetween(float $fromLatitude, float $toLatitude, array $attributes = []): RoadSegment
{
    return RoadSegment::factory()
        ->between($fromLatitude, -72.58, $toLatitude, -72.58)
        ->create($attributes);
}

it('shows the flagging page', function () {
    $this->get(route('flags.index'))
        ->assertOk()
        ->assertSee('Flag a dangerous road')
        ->assertSee('Click a road to select all of it.');
});

it('hands the map the component that drives it', function () {
    $this->get(route('flags.index'))
        ->assertOk()
        ->assertSee('x-data="segmentFlagger(', escape: false)
        ->assertSee('wire:ignore', escape: false);
});

it('sends the roads under the map', function () {
    $segment = wayBetween(44.26, 44.27, ['name' => 'Elm Street']);

    $roads = Livewire::test('pages::flags.index')
        ->call('roadsInView', 44.25, -72.59, 44.28, -72.57, RoadSegmentsInView::MIN_ZOOM)
        ->effects['returns'][0];

    expect($roads['zoomed_out'])->toBeFalse()
        ->and($roads['capped'])->toBeFalse()
        ->and($roads['roads'])->toHaveCount(1)
        ->and($roads['roads'][0]['id'])->toBe($segment->id)
        ->and($roads['roads'][0]['label'])->toBe('Elm Street')
        ->and($roads['roads'][0]['coordinates'][0])->toEqualCanonicalizing([44.26, -72.58]);
});

it('asks the rider to zoom in rather than sending a region of roads', function () {
    wayBetween(44.26, 44.27);

    $roads = Livewire::test('pages::flags.index')
        ->call('roadsInView', 44.25, -72.59, 44.28, -72.57, RoadSegmentsInView::MIN_ZOOM - 1)
        ->effects['returns'][0];

    expect($roads['zoomed_out'])->toBeTrue()
        ->and($roads['roads'])->toBe([]);
});

it('takes a click as the whole road, not just the way clicked', function () {
    $first = wayBetween(44.26, 44.27, ['name' => 'Route 12', 'ref' => 'VT 12']);
    $second = wayBetween(44.27, 44.28, ['name' => 'Route 12', 'ref' => 'VT 12']);
    $elsewhere = wayBetween(44.40, 44.41, ['name' => 'Other Road', 'ref' => null]);

    $component = Livewire::test('pages::flags.index')
        ->call('selectRoad', $first->id);

    expect($component->get('selectedSegmentIds'))
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->not->toContain($elsewhere->id);

    expect($component->get('selectionSummary')['label'])->toBe('VT 12');
});

it('leaves out a branch that only shares the road\'s name', function () {
    $first = wayBetween(44.26, 44.27, ['ref' => 'VT 12']);
    $second = wayBetween(44.27, 44.28, ['ref' => 'VT 12']);

    // A spur leaving the same junction, carrying the same number.
    $spur = RoadSegment::factory()
        ->between(44.27, -72.58, 44.275, -72.57)
        ->create(['ref' => 'VT 12']);

    $component = Livewire::test('pages::flags.index')
        ->call('selectRoad', $first->id);

    expect($component->get('selectedSegmentIds'))
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->not->toContain($spur->id);
});

it('leaves a nameless way on its own rather than walking the lanes around it', function () {
    $lane = wayBetween(44.26, 44.27, ['name' => null, 'ref' => null]);
    wayBetween(44.27, 44.28, ['name' => null, 'ref' => null]);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $lane->id)
        ->assertSet('selectedSegmentIds', [$lane->id]);
});

it('trims the road to where the ends were dragged', function () {
    $segment = wayBetween(44.26, 44.27, ['name' => 'Bridge Road']);

    $component = Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id);

    $whole = $component->get('selectionSummary')['length_m'];

    $component->call('setTrim', 0.25, 0.75);

    expect($component->get('selectionSummary')['length_m'])
        ->toBeGreaterThan((int) ($whole * 0.45))
        ->toBeLessThan((int) ($whole * 0.55));
});

it('trims across every way the stretch runs over', function () {
    $first = wayBetween(44.26, 44.27, ['ref' => 'VT 12']);
    $second = wayBetween(44.27, 44.28, ['ref' => 'VT 12']);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $first->id)
        ->call('setTrim', 0.25, 0.75)
        ->set('reasons', ['Fast traffic'])
        ->set('notes', 'The middle of this road is where the trouble is.')
        ->call('submit')
        ->assertHasNoErrors();

    $parts = RoadFlag::sole()->segments;

    expect($parts)->toHaveCount(2)
        ->and($parts->pluck('road_segment_id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and($parts->every(fn ($part): bool => $part->isPartial()))->toBeTrue();
});

it('gives back the whole road when it is clicked again', function () {
    $segment = wayBetween(44.26, 44.27);

    $component = Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id);

    $whole = $component->get('selectionSummary')['length_m'];

    $component->call('setTrim', 0.4, 0.6)
        ->call('selectRoad', $segment->id)
        ->assertSet('trimStart', 0.0)
        ->assertSet('trimEnd', 1.0);

    expect($component->get('selectionSummary')['length_m'])->toBe($whole);
});

it('will not trim a road down to nothing', function () {
    $segment = wayBetween(44.26, 44.27);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->call('setTrim', 0.5, 0.5001)
        ->assertSet('trimStart', 0.0)
        ->assertSet('trimEnd', 1.0);
});

it('has nothing to trim until a road is selected', function () {
    Livewire::test('pages::flags.index')
        ->call('setTrim', 0.2, 0.8)
        ->assertSet('trimStart', 0.0)
        ->assertSet('trimEnd', 1.0)
        ->assertSet('selectionSummary', null);
});

it('stores a whole-road flag for review', function () {
    $first = wayBetween(44.26, 44.27, ['ref' => 'VT 12']);
    $second = wayBetween(44.27, 44.28, ['ref' => 'VT 12']);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $first->id)
        ->set('reasons', ['No shoulder', 'Fast traffic'])
        ->set('severity', 4)
        ->set('notes', 'Trucks come over the rise with nowhere for a rider to go.')
        ->call('submit')
        ->assertHasNoErrors();

    $flag = RoadFlag::sole();

    expect($flag->user_id)->toBe($this->user->id)
        ->and($flag->reasons)->toBe(['No shoulder', 'Fast traffic'])
        ->and($flag->status)->toBe(RoadFlag::STATUS_PENDING)
        ->and($flag->severity)->toBe(4)
        ->and($flag->length_m)->toBeGreaterThan(2_000)
        ->and($flag->roadSegments->pluck('id')->all())->toEqualCanonicalizing([$first->id, $second->id]);

    expect($flag->segments->every(fn ($segment): bool => ! $segment->isPartial()))->toBeTrue();
});

it('stores where along the road a trimmed flag falls', function () {
    $segment = wayBetween(44.26, 44.27);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->call('setTrim', 0.2, 0.8)
        ->set('reasons', ['Narrow bridge'])
        ->set('notes', 'The bridge parapet pushes you into the lane here.')
        ->call('submit')
        ->assertHasNoErrors();

    $part = RoadFlag::sole()->segments()->sole();

    expect($part->isPartial())->toBeTrue()
        ->and($part->start_fraction)->toBeGreaterThan(0.15)
        ->and($part->start_fraction)->toBeLessThan(0.25)
        ->and($part->end_fraction)->toBeGreaterThan(0.75)
        ->and($part->end_fraction)->toBeLessThan(0.85)
        ->and($part->length_m)->toBeGreaterThan(500);
});

it('changes no danger score until someone has read it', function () {
    $segment = wayBetween(44.26, 44.27, ['danger_score' => 20, 'base_danger_score' => 20]);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->set('reasons', ['Heavy truck traffic'])
        ->set('severity', 5)
        ->set('notes', 'This road is the worst in the county for riding.')
        ->call('submit')
        ->assertHasNoErrors();

    expect($segment->refresh()->danger_score)->toBe(20);
});

it('will not take a flag without saying what is wrong with the road', function () {
    $segment = wayBetween(44.26, 44.27);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->set('reasons', [])
        ->set('notes', 'Something about this road is not right at all.')
        ->call('submit')
        ->assertHasErrors('reasons');

    expect(RoadFlag::count())->toBe(0);
});

it('shows every chosen reason in the bank, and lets one be taken out', function () {
    // Every reason is always in the dropdown, so the bank is told apart by
    // the button that takes one back out again.
    $component = Livewire::test('pages::flags.index')
        ->set('reasons', ['No shoulder', 'Fast traffic', 'Blind bend'])
        ->assertSeeHtml("removeReason('No shoulder')")
        ->assertSeeHtml("removeReason('Fast traffic')")
        ->assertSeeHtml("removeReason('Blind bend')");

    $component->call('removeReason', 'Fast traffic')
        ->assertSet('reasons', ['No shoulder', 'Blind bend'])
        ->assertDontSeeHtml("removeReason('Fast traffic')")
        ->assertSeeHtml("removeReason('No shoulder')");
});

it('starts with no reason chosen, and says so', function () {
    Livewire::test('pages::flags.index')
        ->assertSet('reasons', [])
        ->assertSee('Nothing chosen yet');
});

it('clears the form once a flag has been sent', function () {
    $segment = wayBetween(44.26, 44.27);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->set('reasons', ['Poor surface'])
        ->set('notes', 'The surface has broken up along the whole of this stretch.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('reasons', [])
        ->assertSet('notes', '');
});

it('will not take a reason it does not know', function () {
    $segment = wayBetween(44.26, 44.27);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->set('reasons', ['Haunted'])
        ->set('notes', 'Something about this road is not right at all.')
        ->call('submit')
        ->assertHasErrors('reasons.0');

    expect(RoadFlag::count())->toBe(0);
});

it('will not take a flag with nothing selected', function () {
    Livewire::test('pages::flags.index')
        ->set('reasons', ['Poor surface'])
        ->set('notes', 'There is something wrong with a road around here somewhere.')
        ->call('submit')
        ->assertHasErrors('selection');

    expect(RoadFlag::count())->toBe(0);
});

it('will not take a flag without something an admin can judge it by', function () {
    $segment = wayBetween(44.26, 44.27);

    Livewire::test('pages::flags.index')
        ->call('selectRoad', $segment->id)
        ->set('reasons', ['Poor surface'])
        ->set('notes', 'bad road')
        ->call('submit')
        ->assertHasErrors('notes');

    expect(RoadFlag::count())->toBe(0);
});

it('lets a rider withdraw a flag nobody has acted on', function () {
    $flag = RoadFlag::factory()->for($this->user)->create();

    Livewire::test('pages::flags.index')
        ->call('withdraw', $flag->id);

    expect(RoadFlag::count())->toBe(0);
});

it('will not withdraw a flag once it has been accepted', function () {
    $flag = RoadFlag::factory()->for($this->user)->accepted()->create();

    Livewire::test('pages::flags.index')
        ->call('withdraw', $flag->id)
        ->assertForbidden();

    expect(RoadFlag::count())->toBe(1);
});

it('will not withdraw somebody else\'s flag', function () {
    $flag = RoadFlag::factory()->create();

    Livewire::test('pages::flags.index')
        ->call('withdraw', $flag->id)
        ->assertForbidden();

    expect(RoadFlag::count())->toBe(1);
});

it('shows a rider only their own flags', function () {
    RoadFlag::factory()->for($this->user)->create(['notes' => 'My own report about the bridge.']);
    RoadFlag::factory()->create(['notes' => 'Somebody else about another road.']);

    $this->get(route('flags.index'))
        ->assertOk()
        ->assertSee('My own report about the bridge.')
        ->assertDontSee('Somebody else about another road.');
});
