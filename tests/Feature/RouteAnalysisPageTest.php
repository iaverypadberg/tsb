<?php

use App\Jobs\AnalyseRoute;
use App\Models\FlaggedSection;
use App\Models\Route;
use App\Models\RouteAnalysis;
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

it('offers to analyse a route that has never been looked at', function () {
    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Analyse route')
        ->assertSee('Risk tolerance');
});

it('queues an analysis when asked', function () {
    Queue::fake();

    Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('riskTolerance', RouteAnalysis::TOLERANCE_BRAVE)
        ->call('analyse')
        ->assertHasNoErrors();

    Queue::assertPushed(AnalyseRoute::class);

    $analysis = $this->route->analyses()->sole();

    expect($analysis->risk_tolerance)->toBe(RouteAnalysis::TOLERANCE_BRAVE)
        ->and($analysis->status)->toBe(RouteAnalysis::STATUS_PENDING);
});

it('rejects a risk tolerance it does not know', function () {
    Livewire::test('pages::routes.show', ['route' => $this->route])
        ->set('riskTolerance', 'reckless')
        ->call('analyse')
        ->assertHasErrors('riskTolerance');

    expect($this->route->analyses()->count())->toBe(0);
});

it('says it is working while the job is queued', function () {
    RouteAnalysis::factory()->for($this->route)->running()->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('Checking the roads on this route');
});

it('lists the flagged sections once the analysis finishes', function () {
    $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();

    FlaggedSection::factory()->for($analysis, 'routeAnalysis')->create([
        'reasons' => ['US 2', '89 km/h limit', 'No bike lane'],
        'max_danger' => 94,
        'length_m' => 3_200,
    ]);

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('1 flagged section')
        ->assertSee('US 2')
        ->assertSee('89 km/h limit')
        ->assertSee('94/100')
        ->assertSee('3.2 km');
});

it('says so when nothing was flagged', function () {
    RouteAnalysis::factory()->for($this->route)->completed()->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('This route looks fine');
});

it('reports a failed analysis rather than staying silent', function () {
    RouteAnalysis::factory()->for($this->route)->failed('Postgres went away')->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('did not finish')
        ->assertSee('Postgres went away');
});

it('shows what share of the route was flagged', function () {
    $route = Route::factory()->for($this->user)->create(['distance_m' => 10_000]);

    RouteAnalysis::factory()->for($route)->completed()->create([
        'summary' => ['sections' => 1, 'flagged_m' => 2_500, 'max_danger' => 90],
    ]);

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('25%');
});

it('passes the flagged geometry to the map', function () {
    $analysis = RouteAnalysis::factory()->for($this->route)->completed()->create();
    FlaggedSection::factory()->for($analysis, 'routeAnalysis')->create();

    $this->get(route('routes.show', $this->route))
        ->assertOk()
        ->assertSee('routeMap', escape: false)
        ->assertSee('coordinates', escape: false);
});

it('does not let a user analyse someone elses route', function () {
    $theirs = Route::factory()->create();

    Livewire::test('pages::routes.show', ['route' => $theirs])
        ->assertForbidden();
});
