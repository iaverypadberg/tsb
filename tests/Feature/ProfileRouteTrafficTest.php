<?php

use App\Actions\Aadt\TrafficBand;
use App\Jobs\ProfileRouteTraffic;
use App\Models\RoadSegment;
use App\Models\Route;
use App\Models\TrafficSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A route running due east, over roads carrying the given counts in turn.
 *
 * @param  list<int|null>  $counts
 */
function routeOverTraffic(array $counts): Route
{
    $points = [];

    for ($i = 0; $i <= 40; $i++) {
        $points[] = [44.26, -72.60 + (0.05 * $i / 40)];
    }

    $route = Route::factory()->withCoordinates($points)->create();

    $span = 0.05 / count($counts);

    foreach ($counts as $i => $aadt) {
        RoadSegment::factory()
            ->between(44.26, -72.60 + ($span * $i), 44.26, -72.60 + ($span * ($i + 1)))
            ->create(['aadt' => $aadt]);
    }

    return $route;
}

it('cuts a route into the bands of traffic it runs through', function () {
    $route = routeOverTraffic([500, 20_000]);

    ProfileRouteTraffic::dispatchSync($route);

    $sections = $route->trafficSections()->orderBy('start_fraction')->get();

    expect($sections->pluck('band')->all())->toBe([TrafficBand::Quiet, TrafficBand::Heavy])
        ->and($sections->first()->aadt)->toBe(500)
        ->and($sections->last()->aadt)->toBe(20_000);
});

/**
 * The profile is read as a list of roads, so each stretch has to know which
 * road it ran on — and two roads carrying the same band of traffic are two
 * stretches rather than one.
 */
it('names each stretch after the road it runs on', function () {
    $route = routeOverTraffic([2_000, 2_200]);

    RoadSegment::orderBy('id')->get()->each(fn (RoadSegment $segment, int $i) => $segment->update([
        'name' => $i === 0 ? 'VT 12' : 'Dog River Road',
    ]));

    ProfileRouteTraffic::dispatchSync($route);

    $sections = $route->trafficSections()->orderBy('start_fraction')->get();

    expect($sections->pluck('road_name')->all())->toBe(['VT 12', 'Dog River Road']);
});

/**
 * Most of the lanes here carry no name at all, so an unnamed stretch is an
 * ordinary one rather than a failure to work something out.
 */
it('leaves a stretch unnamed where the road has neither a number nor a name', function () {
    $route = routeOverTraffic([2_000]);

    RoadSegment::query()->update(['name' => null, 'ref' => null]);

    ProfileRouteTraffic::dispatchSync($route);

    expect($route->trafficSections()->sole()->road_name)->toBeNull();
});

it('marks the route profiled when it finishes', function () {
    $route = routeOverTraffic([3_000]);

    ProfileRouteTraffic::dispatchSync($route);

    expect($route->refresh()->traffic_status)->toBe(Route::TRAFFIC_COMPLETED)
        ->and($route->traffic_profiled_at)->not->toBeNull()
        ->and($route->isProfilingTraffic())->toBeFalse();
});

/**
 * Only about one road in ten has ever been counted, so this is the ordinary
 * case rather than an edge one.
 */
it('bands a route on uncounted roads as uncounted rather than quiet', function () {
    $route = routeOverTraffic([null]);

    ProfileRouteTraffic::dispatchSync($route);

    $section = $route->trafficSections()->sole();

    expect($section->band)->toBe(TrafficBand::NotCounted)
        ->and($section->aadt)->toBeNull();
});

it('cuts the coloured geometry out of the route line', function () {
    $route = routeOverTraffic([3_000]);

    ProfileRouteTraffic::dispatchSync($route);

    $section = $route->trafficSections()->sole();

    $apart = DB::scalar(
        'select ST_Distance(t.geom::geography, r.geom::geography) from traffic_sections t, routes r where t.id = ? and r.id = ?',
        [$section->id, $route->id],
    );

    expect((float) $apart)->toBeLessThan(1.0)
        ->and($section->geom->getPoints())->not->toBeEmpty()
        ->and($section->length_m)->toBeGreaterThan(0);
});

it('replaces the previous profile when it runs again', function () {
    $route = routeOverTraffic([3_000]);

    ProfileRouteTraffic::dispatchSync($route);
    ProfileRouteTraffic::dispatchSync($route);

    expect(TrafficSection::where('route_id', $route->id)->count())->toBe(1);
});

it('says so when the profile could not be worked out', function () {
    $route = Route::factory()->create();

    (new ProfileRouteTraffic($route))->failed(new RuntimeException('Postgres went away'));

    expect($route->refresh()->traffic_status)->toBe(Route::TRAFFIC_FAILED)
        ->and($route->isProfilingTraffic())->toBeFalse();
});

/**
 * How fast a road is allowed to go is the other half of the question a
 * count answers, so the profile has to carry it through from the road it
 * matched rather than making the card go back for it.
 */
it('records how fast each stretch is posted at', function () {
    $route = routeOverTraffic([2_000, 2_200]);

    RoadSegment::orderBy('id')->get()->each(fn (RoadSegment $segment, int $i) => $segment->update([
        'name' => $i === 0 ? 'VT 12' : 'Dog River Road',
        'speed_limit_kmh' => $i === 0 ? 80 : 50,
    ]));

    ProfileRouteTraffic::dispatchSync($route);

    $sections = $route->trafficSections()->orderBy('start_fraction')->get();

    expect($sections->pluck('speed_limit_kmh')->all())->toBe([80, 50]);
});

/**
 * OpenStreetMap carries a limit on about one Vermont way in twenty, so an
 * unposted stretch is the ordinary case and says nothing rather than
 * inheriting the state default.
 */
it('leaves a stretch unposted where the road carries no limit', function () {
    $route = routeOverTraffic([2_000]);

    RoadSegment::query()->update(['speed_limit_kmh' => null]);

    ProfileRouteTraffic::dispatchSync($route);

    expect($route->trafficSections()->sole()->speed_limit_kmh)->toBeNull();
});
