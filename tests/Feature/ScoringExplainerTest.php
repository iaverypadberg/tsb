<?php

use App\Models\RoadSegment;
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

it('explains how a score is arrived at', function () {
    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('How these scores are worked out')
        ->assertSee('Every road scores from 0 to 100', escape: false);
});

it('admits that it cannot see traffic volume', function () {
    // The biggest weakness in the model should not be buried.
    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('no traffic count behind this', escape: false);
});

it('credits openstreetmap, as the licence requires', function () {
    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('OpenStreetMap contributors', escape: false)
        ->assertSee('Open Database Licence', escape: false);
});

it('reports how thin the underlying tagging actually is', function () {
    // Four roads, one with a speed limit and two with a surface.
    RoadSegment::factory()->create(['speed_limit_kmh' => 80, 'surface' => 'asphalt', 'has_shoulder' => null]);
    RoadSegment::factory()->create(['speed_limit_kmh' => null, 'surface' => 'gravel', 'has_shoulder' => null]);
    RoadSegment::factory()->create(['speed_limit_kmh' => null, 'surface' => null, 'has_shoulder' => null]);
    RoadSegment::factory()->create(['speed_limit_kmh' => null, 'surface' => null, 'has_shoulder' => null]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Speed limits are tagged on 25% of roads.')
        ->assertSee('Surface is tagged on 50%.');
});

it('says nothing about coverage when no roads are loaded', function () {
    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertDontSee('Speed limits are tagged on');
});

it('caches the coverage figures rather than counting on every view', function () {
    RoadSegment::factory()->create(['speed_limit_kmh' => 80]);

    $this->get(route('routes.show', $this->route))->assertOk();

    expect(Cache::get('road-segments.tag-coverage'))->toHaveKeys(['speed', 'surface', 'shoulder']);
});
