<?php

// TEMP: danger scoring is hidden until it is integrated back in.
// use App\Models\RoadSegment;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget('road-segments.tag-coverage');

    $this->user = User::factory()->create();
    $this->route = Route::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

// TEMP: danger scoring is hidden until it is integrated back in.
// it('explains how a score is arrived at', function () {
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('How these scores are worked out')
//         ->assertSee('Every road scores from 0 to 100', escape: false);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('is straight about which roads have a traffic count behind them', function () {
//     // The biggest weakness in the model should not be buried: the counts
//     // cover a tenth of the network, so the guess is still doing most of the
//     // work and the page has to say so.
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('most roads have never been counted', escape: false)
//         ->assertSee('the real figure adjusts the score up or down', escape: false);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('credits openstreetmap, as the licence requires', function () {
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('OpenStreetMap contributors', escape: false)
//         ->assertSee('Open Database Licence', escape: false);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('reports how thin the underlying tagging actually is', function () {
//     // Four roads, one with a speed limit and two with a surface.
//     RoadSegment::factory()->create(['speed_limit_kmh' => 80, 'surface' => 'asphalt', 'has_shoulder' => null, 'aadt' => 4000, 'crash_count' => 2]);
//     RoadSegment::factory()->create(['speed_limit_kmh' => null, 'surface' => 'gravel', 'has_shoulder' => null, 'aadt' => null]);
//     RoadSegment::factory()->create(['speed_limit_kmh' => null, 'surface' => null, 'has_shoulder' => null, 'aadt' => null]);
//     RoadSegment::factory()->create(['speed_limit_kmh' => null, 'surface' => null, 'has_shoulder' => null, 'aadt' => null]);
//
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertSee('Speed limits are tagged on 25% of roads.')
//         ->assertSee('Surface is tagged on 50%.')
//         ->assertSee('Traffic has been counted on 25%', escape: false)
//         ->assertSee('A crash has been recorded on 25%', escape: false);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('says nothing about coverage when no roads are loaded', function () {
//     $this->get(route('routes.show', $this->route))
//         ->assertOk()
//         ->assertDontSee('Speed limits are tagged on');
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('caches the coverage figures rather than counting on every view', function () {
//     RoadSegment::factory()->create(['speed_limit_kmh' => 80]);
//
//     $this->get(route('routes.show', $this->route))->assertOk();
//
//     expect(Cache::get('road-segments.tag-coverage'))->toHaveKeys(['speed', 'surface', 'shoulder', 'traffic', 'crashes']);
// });
