<?php

// TEMP: danger scoring is hidden until it is integrated back in.
// use App\Jobs\AnalyseRoute;
use App\Jobs\ProfileRouteTraffic;
use App\Models\Crash;
// TEMP: danger scoring is hidden until it is integrated back in.
// use App\Models\FlaggedSection;
use App\Models\Route;
// TEMP: danger scoring is hidden until it is integrated back in.
// use App\Models\RouteAnalysis;
use App\Models\TrafficSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->route = Route::factory()->for($this->user)->create(['name' => 'Montpelier loop']);
    $this->actingAs($this->user);
});

// TEMP: danger scoring is hidden until it is integrated back in.
// it('offers to analyse a route that has never been looked at', function () {
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('Analyse route')
//         ->assertSee('Risk tolerance');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('queues an analysis when asked', function () {
//     Queue::fake();
//
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->set('riskTolerance', RouteAnalysis::TOLERANCE_BRAVE)
//         ->call('analyse')
//         ->assertHasNoErrors();
//
//     Queue::assertPushed(AnalyseRoute::class);
//
//     $analysis = $this->route->analyses()->sole();
//
//     expect($analysis->risk_tolerance)->toBe(RouteAnalysis::TOLERANCE_BRAVE)
//         ->and($analysis->status)->toBe(RouteAnalysis::STATUS_PENDING);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('rejects a risk tolerance it does not know', function () {
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->set('riskTolerance', 'reckless')
//         ->call('analyse')
//         ->assertHasErrors('riskTolerance');
//
//     expect($this->route->analyses()->count())->toBe(0);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('says it is working while the job is queued', function () {
//     RouteAnalysis::factory()->for($this->route)->running()->create();
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('Checking the roads on this route');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('lists the flagged sections once the analysis finishes', function () {
//     $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->create([
//         'reasons' => ['US 2', '89 km/h limit', 'No bike lane'],
//         'max_danger' => 94,
//         'length_m' => 3_200,
//     ]);
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('1 flagged section')
//         ->assertSee('US 2')
//         ->assertSee('89 km/h limit')
//         ->assertSee('94/100')
//         ->assertSee('3.2 km');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('lists the unflagged sections below the flagged ones', function () {
//     $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->spanning(0.0, 0.3)->create([
//         'reasons' => ['US 2', '89 km/h limit'],
//         'max_danger' => 94,
//     ]);
//
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->unflagged()->spanning(0.3, 1.0)->create([
//         'reasons' => ['Hollow Road', 'Residential'],
//         'max_danger' => 22,
//         'length_m' => 8_400,
//     ]);
//
//     $response = $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('1 flagged section')
//         ->assertSee('1 unflagged section')
//         ->assertSee('Hollow Road')
//         ->assertSee('22/100')
//         ->assertSee('8.4 km');
//
//     // The flags come first: the warning is the point of the page.
//     expect(strpos($response->getContent(), 'US 2'))
//         ->toBeLessThan(strpos($response->getContent(), 'Hollow Road'));
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('still lists the unflagged sections when nothing was flagged', function () {
//     $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->unflagged()->spanning(0.0, 1.0)->create([
//         'reasons' => ['Hollow Road', 'Residential'],
//     ]);
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('This route looks fine')
//         ->assertSee('1 unflagged section')
//         ->assertSee('Hollow Road');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('says so when nothing was flagged', function () {
//     RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('This route looks fine');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('reports a failed analysis rather than staying silent', function () {
//     RouteAnalysis::factory()->for($this->route)->failed('Postgres went away')->create();
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('did not finish')
//         ->assertSee('Postgres went away');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('shows what share of the route was flagged', function () {
//     $route = Route::factory()->for($this->user)->create(['distance_m' => 10_000]);
//
//     RouteAnalysis::factory()->for($route)->completed()->create([
//         'summary' => ['sections' => 1, 'flagged_m' => 2_500, 'max_danger' => 90],
//     ]);
//
//     $this->get(route('routes.show', $route))
//         ->assertOk()
//         ->assertSee('25%');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('passes the flagged geometry to the map', function () {
//     $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->create();
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->unflagged()->create();
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('routeMap', escape: false)
//         ->assertSee('coordinates', escape: false);
// });

/**
 * The whole reason the profile is worked out at upload: a rider who has just
 * put a route up has something to look at before pressing anything.
 */
it('colours the route by traffic before any analysis has been run', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(18_000)->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Heavy')
        ->assertSee('Coloured by measured traffic.')
        ->assertDontSee('flagged section');
});

// TEMP: danger scoring is hidden until it is integrated back in.
// it('offers the colouring switch once there is an analysis as well', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//     TrafficSection::factory()->for($this->route)->carrying(18_000)->create();
//
//     $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->create();
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('Danger')
//         ->assertSee('Traffic');
// });

it('says it is working while the traffic is still being worked out', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_QUEUED]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Working out the traffic on this route');
});

it('queues a profile for a route uploaded before there were any', function () {
    Queue::fake();

    expect($this->route->traffic_status)->toBe(Route::TRAFFIC_UNPROFILED);

    $this->get(route('routes.show', $this->route))->assertOk();

    Queue::assertPushed(ProfileRouteTraffic::class);

    expect($this->route->refresh()->traffic_status)->toBe(Route::TRAFFIC_QUEUED);
});

/**
 * Opening the page twice, or the poll coming round, must not pile up jobs
 * for work that is already in hand.
 */
it('does not queue a second profile for a route already being profiled', function () {
    Queue::fake();

    $this->route->update(['traffic_status' => Route::TRAFFIC_QUEUED]);

    $this->get(route('routes.show', $this->route))->assertOk();

    Queue::assertNotPushed(ProfileRouteTraffic::class);
});

it('names the counts behind each band in the key', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(2_000)->create(['length_m' => 3_300]);
    TrafficSection::factory()->for($this->route)->notCounted()->create(['length_m' => 1_000]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Not counted')
        ->assertSee('No count published')
        ->assertSee('3.3', escape: false);
});

/**
 * A stretch of a route running due north from a fixed point, so a crash can
 * be placed beside it.
 */
function profiledStretch(Route $route, int $aadt, string $name, float $north = 0.0): TrafficSection
{
    return TrafficSection::factory()
        ->for($route)
        ->carrying($aadt)
        ->between(44.26 + $north, -72.58, 44.27 + $north, -72.58)
        ->create(['road_name' => $name, 'length_m' => 2_400]);
}

/**
 * The traffic list's markup alone.
 *
 * Every road name also appears in the map's own payload, in the order the
 * route is ridden, so comparing positions across the whole page would be
 * comparing the wrong list.
 */
function trafficListMarkup(string $html): string
{
    $start = strpos($html, 'data-test="traffic-list"');

    return $start === false ? '' : substr($html, $start);
}

it('lists the roads the route runs on, in the order it rides them', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road')->update(['start_fraction' => 0.0]);
    profiledStretch($this->route, 18_000, 'US 2', north: 0.1)->update(['start_fraction' => 0.4]);

    $response = $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('2 total roads')
        ->assertSee('US 2')
        ->assertSee('Dog River Road')
        ->assertSee('18,000 cars/day - 2.4 km');

    $list = trafficListMarkup($response->getContent());

    expect(strpos($list, 'Dog River Road'))->toBeLessThan(strpos($list, 'US 2'));
});

/**
 * The ride first, the league table on request: a rider arriving at a route
 * they have just uploaded is reading the list against the map, and the two
 * have to run the same way round.
 */
it('reorders the list busiest first when asked', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road')->update(['start_fraction' => 0.0]);
    profiledStretch($this->route, 18_000, 'US 2', north: 0.1)->update(['start_fraction' => 0.4]);

    $component = Livewire::test('pages::routes.show', ['route' => $this->route]);

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Dog River Road', 'US 2']);

    $component->set('trafficSort', 'traffic');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['US 2', 'Dog River Road']);
});

/**
 * A road nobody has counted is not a quiet road, so it cannot be ranked
 * above one with a figure — but what was reported on it still earns it a
 * place on the list.
 */
it('ranks an uncounted road below every road with a count', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->notCounted()
        ->between(44.26, -72.58, 44.27, -72.58)
        ->create(['road_name' => 'Hollow Road', 'length_m' => 2_400]);

    Crash::factory()->at(44.262, -72.58)->injury()->create();

    profiledStretch($this->route, 400, 'Shady Rill Road', north: 0.1);

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'traffic')
        ->assertSee('Hollow Road')
        ->assertSee('Not counted');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Shady Rill Road', 'Hollow Road']);
});

/**
 * Most of a rural route is lanes nobody has counted and nothing has been
 * reported on. A card for one of those says "Not counted" beside a
 * distance, and a page of them buries the roads that do have something to
 * show.
 */
it('leaves out a road with neither a count nor anything reported', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road');

    TrafficSection::factory()->for($this->route)->notCounted()
        ->between(44.46, -72.58, 44.47, -72.58)
        ->create(['road_name' => 'Hollow Road', 'length_m' => 2_400]);

    $response = $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('1 road');

    // The map still colours it; it is the list that leaves it out.
    expect(trafficListMarkup($response->getContent()))->not->toContain('Hollow Road');
});

it('keeps a counted road nothing has been reported on', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road');

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('1 road')
        ->assertSee('Dog River Road');
});

/**
 * The count says how much traffic there is; the limit says how fast it is
 * moving. A rider weighing a road needs both, so the card carries both.
 */
it('shows how fast a stretch is posted at', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(1_200)->postedAt(80)
        ->create(['road_name' => 'Dog River Road', 'length_m' => 2_400]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Dog River Road')
        ->assertSee('50 mph');
});

/**
 * An untagged road is unknown rather than slow. A badge reading "0 mph",
 * or the state default dressed up as a posted limit, would be a claim the
 * data does not make.
 */
it('says nothing about speed on a stretch nobody has posted', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road');

    $response = $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Dog River Road');

    expect(trafficListMarkup($response->getContent()))
        ->not->toContain('data-test="traffic-speed-limit"')
        ->and($response->getContent())->not->toContain('mph');
});

/**
 * A lane nobody has counted and nothing has been reported on is still worth
 * listing if it is posted at 88: that figure is the thing a rider would
 * most want to have been told about it.
 */
it('lists an uncounted road that carries a posted limit', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->notCounted()->postedAt(88)
        ->between(44.46, -72.58, 44.47, -72.58)
        ->create(['road_name' => 'Hollow Road', 'length_m' => 2_400]);

    $response = $this->get(route('routes.show', $this->route))
        ->assertOk();

    expect(trafficListMarkup($response->getContent()))
        ->toContain('Hollow Road')
        ->toContain('55 mph');
});

it('says so when no road on the route has anything to show', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->notCounted()->create(['road_name' => 'Hollow Road']);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('no road on this route has a published traffic count');
});

// TEMP: danger scoring is hidden until it is integrated back in.
// /**
//  * The list beside the map is of whichever colouring is on it, so switching
//  * the map switches the list with it.
//  */
// it('swaps the list for the traffic one when the map is coloured by traffic', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//
//     profiledStretch($this->route, 18_000, 'US 2');
//
//     $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
//     FlaggedSection::factory()->for($analysis, 'routeAnalysis')->create(['reasons' => ['Hollow Road']]);
//
//     $component = Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->assertSee('1 flagged section')
//         ->assertDontSee('road ridden');
//
//     $component->set('colourBy', 'traffic')
//         ->assertSee('1 road')
//         ->assertDontSee('1 flagged section');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// /**
//  * Until the rider picks a colouring it follows what there is to show, which
//  * is the point of profiling the traffic at upload: a route has something to
//  * say for itself before anybody has pressed anything.
//  */
// it('colours a route by its flags as soon as an analysis has something to say', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//
//     TrafficSection::factory()->for($this->route)->create();
//
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->assertSet('colourBy', 'traffic');
//
//     RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->assertSet('colourBy', 'danger');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('leaves the colouring alone once the rider has picked one', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//
//     TrafficSection::factory()->for($this->route)->create();
//     RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->set('colourBy', 'traffic')
//         ->refresh()
//         ->assertSet('colourBy', 'traffic');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('ignores a colouring it does not know', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//
//     TrafficSection::factory()->for($this->route)->create();
//
//     // Nothing has been analysed, so the traffic is all there is to draw.
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->set('colourBy', 'rainbow')
//         ->assertSet('colourBy', 'traffic');
//
//     RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->set('colourBy', 'rainbow')
//         ->assertSet('colourBy', 'danger');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// /**
//  * Asking for a fresh analysis takes the flags away until it finishes. A
//  * rider sitting on the flags would otherwise be left looking at a list of
//  * one colouring beside a map showing the other.
//  */
// it('falls back to the traffic while a fresh analysis is running', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//
//     TrafficSection::factory()->for($this->route)->create();
//     RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     $component = Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->set('colourBy', 'danger')
//         ->assertSet('colourBy', 'danger');
//
//     RouteAnalysis::factory()->for($this->route)->running()->create();
//
//     $component->refresh()
//         ->assertSet('colourBy', 'traffic')
//         ->assertSeeHtml('data-test="traffic-panel"')
//         ->assertDontSeeHtml('data-test="danger-panel"');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// /**
//  * One decision, so the panel beside the map is never the list for the
//  * colouring that is not on it.
//  */
// it('shows exactly one list beside the map', function () {
//     $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);
//
//     TrafficSection::factory()->for($this->route)->create();
//     RouteAnalysis::factory()->for($this->route)->completed()->create();
//
//     $component = Livewire::test('pages::routes.show', ['route' => $this->route])
//         ->assertSeeHtml('data-test="danger-panel"')
//         ->assertDontSeeHtml('data-test="traffic-panel"');
//
//     $component->set('colourBy', 'traffic')
//         ->assertSeeHtml('data-test="traffic-panel"')
//         ->assertDontSeeHtml('data-test="danger-panel"');
// });

/**
 * Clicking a road on the map scrolls the list to that road's card, which the
 * map does by looking the card up by the stretch's id. The scrolling itself
 * is Leaflet's and the browser's; what has to hold here is that every card
 * carries the id the map will come looking for.
 */
it('marks each card with the stretch the map can find it by', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    $first = profiledStretch($this->route, 1_200, 'Dog River Road');
    $second = profiledStretch($this->route, 18_000, 'US 2', north: 0.1);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('data-traffic-section="'.$first->id.'"', escape: false)
        ->assertSee('data-traffic-section="'.$second->id.'"', escape: false);
});

/**
 * The pin button is gone and the card itself is the control, so the handler
 * the map relies on has to be on the card. A change that moves it back onto
 * a child would break clicking a road in the list and nothing else, which is
 * exactly the kind of break nothing else here would catch.
 *
 * Keyboard reachability is asserted with it: a div is not focusable on its
 * own, so the role and the tab stop are what keep the list usable without a
 * mouse now that there is no button to tab to.
 */
it('makes the whole card ask the map for its road', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    $section = profiledStretch($this->route, 1_200, 'Dog River Road');

    $response = $this->get(route('routes.show', $this->route))->assertOk();

    $card = trafficListMarkup($response->getContent());

    expect($card)
        ->toContain('badroads.show(&#039;focusedTraffic&#039;, '.$section->id.')')
        ->toContain('role="button"')
        ->toContain('tabindex="0"')
        ->toContain('Show Dog River Road on map')
        // The separate pin button it replaces.
        ->not->toContain('focus-traffic-'.$section->id);
});

it('offers the orderings beside the list', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Order by')
        ->assertSee('Route order')
        ->assertSee('Busiest')
        ->assertSee('Fastest')
        ->assertSee('Most injuries')
        ->assertSee('Most crashes')
        ->assertSee('Most Bike & ped crashes')
        ->assertDontSee('Deaths');
});

/**
 * OpenStreetMap writes a road carrying two route numbers as a tag, not as
 * something anybody would say out loud.
 */
it('spells out a road carrying two route numbers', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 22_000, 'US 2;US 7');

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('US 2 / US 7')
        ->assertDontSee('US 2;US 7');
});

it('names an unnamed lane as one rather than leaving it blank', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->unnamed()->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Unnamed road');
});

it('says what has been reported beside each road', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 18_000, 'US 2');

    Crash::factory()->at(44.262, -72.58)->fatal()->create();
    Crash::factory()->at(44.263, -72.58)->injury()->create(['involving' => Crash::INVOLVING_BICYCLE]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('1 fatal')
        ->assertSee('1 injury')
        ->assertSee('1 bicycle')
        ->assertSee('2 crashes reported');
});

/**
 * The whole point of the ordering: the busiest road is not always the one
 * people have been hurt on.
 */
it('reorders the list by where people have been hurt', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 18_000, 'US 2', north: 0.1);
    profiledStretch($this->route, 1_200, 'Dog River Road');

    Crash::factory()->at(44.262, -72.58)->injury()->create();

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'injuries');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Dog River Road', 'US 2']);
});

/**
 * Traffic and speed are different questions: the busiest road on a route is
 * not always the fastest one on it.
 */
it('reorders the list by the fastest posted limit', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 18_000, 'US 2', north: 0.1)->update(['speed_limit_kmh' => 40]);
    profiledStretch($this->route, 1_200, 'Dog River Road')->update(['speed_limit_kmh' => 88]);

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'speed');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Dog River Road', 'US 2']);
});

/**
 * Most stretches carry no limit at all, and an untagged road is not a
 * derestricted one, so it ranks below every road that has a figure.
 */
it('ranks an unposted road below a posted one', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 18_000, 'US 2', north: 0.1);
    profiledStretch($this->route, 1_200, 'Dog River Road')->update(['speed_limit_kmh' => 56]);

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'speed');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Dog River Road', 'US 2']);
});

/**
 * Deaths are the thinnest thing in the record — 187 of 32,000 reported
 * crashes — so they are not what any ordering leads on. A road with a death
 * and nothing else does not outrank one with several injuries.
 */
it('does not rank a road up for a death alone', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road', north: 0.1);
    profiledStretch($this->route, 1_200, 'Shady Rill Road');

    Crash::factory()->at(44.362, -72.58)->fatal()->create();
    Crash::factory()->at(44.262, -72.58)->injury()->create();
    Crash::factory()->at(44.263, -72.58)->injury()->create();

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'injuries');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Shady Rill Road', 'Dog River Road']);
});

/**
 * Most reported crashes hurt nobody, and a road with twenty of them is
 * still telling a rider something.
 */
it('reorders the list by how much has been reported at all', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 18_000, 'US 2', north: 0.1);
    profiledStretch($this->route, 1_200, 'Dog River Road');

    Crash::factory()->at(44.362, -72.58)->injury()->create();
    Crash::factory()->count(3)->at(44.262, -72.58)->create();

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'crashes');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Dog River Road', 'US 2']);
});

it('reorders the list by the crashes a rider or someone on foot was in', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 18_000, 'US 2', north: 0.1);
    profiledStretch($this->route, 1_200, 'Dog River Road');

    Crash::factory()->at(44.262, -72.58)->injury()->create(['involving' => Crash::INVOLVING_PEDESTRIAN]);

    $component = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'vulnerable');

    expect($component->instance()->rankedTrafficSections->pluck('road_name')->all())
        ->toBe(['Dog River Road', 'US 2']);
});

it('falls back to the route ordering when asked for one it does not know', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->create();

    Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('trafficSort', 'whatever')
        ->assertSet('trafficSort', 'route');
});

/**
 * A route clipping thirty metres of a main road where it crosses a junction
 * has not ridden that road, and sorting by traffic would otherwise put that
 * clip at the top of the list.
 */
it('leaves a clipped junction out of the list', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    profiledStretch($this->route, 1_200, 'Dog River Road');

    TrafficSection::factory()->for($this->route)->carrying(24_000)->create([
        'road_name' => 'Interstate 89',
        'length_m' => 20,
    ]);

    $response = $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('1 road')
        ->assertSee('Dog River Road');

    // The map still colours the clip; it is the list that leaves it out.
    expect(trafficListMarkup($response->getContent()))->not->toContain('Interstate 89');
});

it('does not let a user analyse someone elses route', function () {
    $theirs = Route::factory()->create();

    Livewire::test('pages::routes.show', ['route' => $theirs])
        ->assertForbidden();
});

it('breaks the ride down into kilometres on each sort of road', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(500)->create(['length_m' => 4_000]);
    TrafficSection::factory()->for($this->route)->carrying(2_000)->create(['length_m' => 4_000]);
    TrafficSection::factory()->for($this->route)->carrying(18_000)->create(['length_m' => 2_000]);

    $breakdown = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->instance()
        ->trafficBreakdown;

    expect($breakdown['km'])->toBe(10.0);
    expect($breakdown['roads'])->toBe(3);

    // Every band, quietest first, whether the route touches it or not: a key
    // that changes shape from route to route cannot be compared across two.
    expect(array_column($breakdown['bands'], 'percent', 'band'))->toBe([
        'quiet' => 40.0,
        'moderate' => 40.0,
        'busy' => 0.0,
        'heavy' => 20.0,
    ]);

    // An empty band is listed with nothing in it rather than left out.
    expect(array_column($breakdown['bands'], 'km', 'band'))->toBe([
        'quiet' => 4.0,
        'moderate' => 4.0,
        'busy' => 0.0,
        'heavy' => 2.0,
    ]);
});

it('reads a road with no published count as a quiet one', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(500)->create(['length_m' => 2_000]);
    TrafficSection::factory()->count(3)->for($this->route)->notCounted()->create(['length_m' => 2_000]);

    $breakdown = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->instance()
        ->trafficBreakdown;

    // The uncounted roads are in the quiet band, not in one of their own.
    expect(array_column($breakdown['bands'], 'band'))
        ->toBe(['quiet', 'moderate', 'busy', 'heavy'])
        ->not->toContain('not_counted');

    $quiet = $breakdown['bands'][0];

    expect($quiet['km'])->toBe(8.0);
    expect($quiet['roads'])->toBe(4);
    expect($quiet['percent'])->toBe(100.0);

    // Kept separately, because it is the assumption the bar rests on.
    expect($breakdown['uncounted'])->toBe(['km' => 6.0, 'roads' => 3]);
});

it('says how much of the bar rests on roads nobody has counted', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(500)->create(['length_m' => 2_000]);
    TrafficSection::factory()->count(2)->for($this->route)->notCounted()->create(['length_m' => 3_000]);

    $html = $this->get(route('routes.show', $this->route))->assertOk()->getContent();

    // The figure, not the sentence around it: the wording of the note is
    // copy, and copy is not this test's business.
    expect(noteMarkup($html))->toContain('6.0 km');
});

/**
 * The note beneath the bar, and nothing else on the page.
 */
function noteMarkup(string $html): string
{
    $start = strpos($html, 'data-test="uncounted-note"');

    expect($start)->not->toBeFalse();

    return substr($html, $start, (int) strpos($html, '</p>', $start) - $start);
}

it('leaves the note off a route where every road has been counted', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(500)->create();

    $html = $this->get(route('routes.show', $this->route))->assertOk()->getContent();

    expect($html)
        ->toContain('data-test="band-chart"')
        ->not->toContain('data-test="uncounted-note"');
});

it('names the counts each band covers', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(500)->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSeeInOrder(['under 1,000', '1,000–4,000', '4,000–12,000', 'over 12,000'], escape: false);
});

it('draws one bar, each segment as wide as its own share', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_COMPLETED]);

    TrafficSection::factory()->for($this->route)->carrying(500)->create(['length_m' => 5_000]);
    TrafficSection::factory()->for($this->route)->carrying(18_000)->create(['length_m' => 2_500]);
    TrafficSection::factory()->for($this->route)->notCounted()->create(['length_m' => 2_500]);

    $html = $this->get(route('routes.show', $this->route))->assertOk()->getContent();

    // One bar, not two.
    expect(substr_count($html, 'data-test="band-chart"'))->toBe(1);
    expect($html)->not->toContain('data-test="coverage-chart"');

    // The 2.5 km nobody counted is in with the 5 km of quiet road, so quiet
    // is three quarters of the ride against heavy's quarter.
    expect($html)
        ->toContain('width: 75%')
        ->toContain('width: 25%');

    // The two bands this route never touches are listed in the key but draw
    // no segment, which the minimum width would otherwise give them.
    expect($html)->not->toContain('width: 0%');
});

it('leaves the breakdown off a route whose traffic is not worked out', function () {
    $this->route->update(['traffic_status' => Route::TRAFFIC_QUEUED]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertDontSee('What this ride is made of');
});

/**
 * The page polls every two seconds while it believes work is in hand, and
 * `queued` is the state a job that never reached a worker is left in. Without
 * a deadline on the wait those two facts together mean a dropped job is
 * indistinguishable from a running one and the page polls for the lifetime of
 * the tab.
 */
it('keeps polling while a queued profile is still within its deadline', function () {
    $this->route->update([
        'traffic_status' => Route::TRAFFIC_QUEUED,
        'traffic_queued_at' => now()->subMinutes(Route::TRAFFIC_STALL_AFTER_MINUTES - 1),
    ]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Working out the traffic on this route')
        ->assertSee('wire:poll', escape: false);
});

/**
 * Within a single sitting the deadline is what stops the asking. The poll
 * re-renders the page without running mount again, so nothing re-queues
 * underneath the rider: the page simply stops asking and says so.
 */
it('stops polling once the deadline passes while the page is open', function () {
    $this->route->update([
        'traffic_status' => Route::TRAFFIC_QUEUED,
        'traffic_queued_at' => now(),
    ]);

    $page = Livewire::test('pages::routes.show', ['route' => $this->route])
        ->assertSee('Working out the traffic on this route');

    $this->travel(Route::TRAFFIC_STALL_AFTER_MINUTES + 1)->minutes();

    $page->call('$refresh')
        ->assertSee('The traffic on this route could not be worked out.')
        ->assertDontSee('Working out the traffic on this route');
});

/**
 * Opening the page again is the owner asking again, so a route whose job was
 * dropped rather than failed picks itself back up without anyone running a
 * command against the database.
 */
it('queues a fresh profile for a route whose job went past the deadline', function () {
    Queue::fake();

    $this->route->update([
        'traffic_status' => Route::TRAFFIC_QUEUED,
        'traffic_queued_at' => now()->subMinutes(Route::TRAFFIC_STALL_AFTER_MINUTES + 1),
    ]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Working out the traffic on this route');

    Queue::assertPushed(ProfileRouteTraffic::class);

    expect($this->route->refresh()->traffic_queued_at->diffInMinutes(now(), absolute: true))
        ->toBeLessThan(1);
});

/**
 * Asking again is bounded by the deadline rather than by the reload, so a
 * rider refreshing a waiting page cannot pile up jobs for the same route.
 */
it('does not queue again for a route still within its deadline', function () {
    Queue::fake();

    $this->route->update([
        'traffic_status' => Route::TRAFFIC_QUEUED,
        'traffic_queued_at' => now()->subMinutes(Route::TRAFFIC_STALL_AFTER_MINUTES - 1),
    ]);

    $this->get(route('routes.show', $this->route))->assertOk();
    $this->get(route('routes.show', $this->route))->assertOk();

    Queue::assertNotPushed(ProfileRouteTraffic::class);
});

/**
 * Work on a route belongs to whoever uploaded it. Someone reading a link they
 * were sent cannot start it, so for them a stalled route is simply one with
 * no traffic to show — and they must not be left polling for it either.
 */
it('tells a reader sent the link that a stalled profile is not coming', function () {
    Queue::fake();

    $route = Route::factory()->for(User::factory())->shared()->create([
        'traffic_status' => Route::TRAFFIC_QUEUED,
        'traffic_queued_at' => now()->subMinutes(Route::TRAFFIC_STALL_AFTER_MINUTES + 1),
    ]);

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('The traffic on this route could not be worked out.')
        ->assertDontSee('wire:poll', escape: false);

    Queue::assertNotPushed(ProfileRouteTraffic::class);
});

it('records when the traffic profile was queued', function () {
    Queue::fake();

    expect($this->route->traffic_queued_at)->toBeNull();

    $this->get(route('routes.show', $this->route))->assertOk();

    expect($this->route->refresh()->traffic_queued_at)->not->toBeNull();
});

/**
 * A profile that lands late is still a profile. Calling the wait off is the
 * page giving up on asking, not the route being written off, so a job that
 * finishes after the deadline shows its traffic as normal.
 */
it('still shows traffic that arrives after the deadline has passed', function () {
    TrafficSection::factory()->for($this->route)->create();

    $this->route->update([
        'traffic_status' => Route::TRAFFIC_COMPLETED,
        'traffic_queued_at' => now()->subDay(),
        'traffic_profiled_at' => now(),
    ]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertDontSee('could not be worked out')
        ->assertDontSee('wire:poll', escape: false);
});
