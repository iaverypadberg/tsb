<?php

use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('offers a guest the uploader', function () {
    $this->get(route('routes.index'))
        ->assertOk()
        ->assertSee('Upload route')
        ->assertSee('No routes yet');
});

it('does not let a guest view a route they did not upload', function () {
    $route = Route::factory()->create();

    $this->get(route('routes.show', $route))->assertForbidden();
});

/**
 * A counter in the URL would tell whoever holds the link how many routes the
 * site has, and where in that pile theirs landed.
 */
it('identifies a route in its url by the public id', function () {
    $route = Route::factory()->create();

    expect($route->public_id)->not->toBeNull()
        ->and(route('routes.show', $route))->toEndWith('/routes/'.$route->public_id);
});

it('gives every upload a public id of its own', function () {
    $first = Route::factory()->create();
    $second = Route::factory()->create();

    expect($first->public_id)->not->toBe($second->public_id);
});

it('does not serve a route by its number', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create();

    $this->actingAs($user)
        ->get('/routes/'.$route->id)
        ->assertNotFound();
});

it('does not serve a route for an id that belongs to nothing', function () {
    $this->actingAs(User::factory()->create())
        ->get('/routes/'.Str::uuid())
        ->assertNotFound();
});

it('links the route list to the public id', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('routes.index'))
        ->assertOk()
        ->assertSee($route->public_id);
});

it('lists only the signed in users routes', function () {
    $user = User::factory()->create();
    $mine = Route::factory()->for($user)->create(['name' => 'My loop']);
    $theirs = Route::factory()->create(['name' => 'Someone elses loop']);

    $this->actingAs($user)
        ->get(route('routes.index'))
        ->assertOk()
        ->assertSee($mine->name)
        ->assertDontSee($theirs->name);
});

it('shows an empty state when the user has no routes', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('routes.index'))
        ->assertOk()
        ->assertSee('No routes yet');
});

it('shows a route with its map coordinates', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->withCoordinates([
        [44.26, -72.58],
        [44.27, -72.57],
        [44.28, -72.56],
    ])->create(['name' => 'Montpelier loop']);

    $this->actingAs($user)
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Montpelier loop')
        ->assertSee('routeMap', escape: false)
        ->assertSee('44.26', escape: false);
});

it('names the browser tab after the route', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create(['name' => 'Worcester ridge']);

    $this->actingAs($user)
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('<title>'.PHP_EOL.'    Worcester ridge - '.config('app.name'), escape: false);
});

it('does not let a user view someone elses route', function () {
    $route = Route::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('routes.show', $route))
        ->assertForbidden();
});

it('lets a user delete their own route', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::routes.index')
        ->call('delete', $route)
        ->assertHasNoErrors();

    expect(Route::find($route->id))->toBeNull();
});

it('does not let a user delete someone elses route', function () {
    $route = Route::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::routes.index')
        ->call('delete', $route)
        ->assertForbidden();

    expect(Route::find($route->id))->not->toBeNull();
});

it('simplifies dense routes before sending them to the map', function () {
    $coordinates = [];

    // A nearly straight line of 3,000 points: simplification should collapse it.
    for ($i = 0; $i < 3_000; $i++) {
        $coordinates[] = [44.26 + ($i * 0.0001), -72.58];
    }

    $route = Route::factory()->withCoordinates($coordinates)->create();

    expect($route->point_count)->toBe(3_000)
        ->and(count($route->coordinatesForMap()))->toBeLessThan(3_000);
});

it('sends dense routes unsimplified when they are under the limit', function () {
    $coordinates = [];

    for ($i = 0; $i < 50; $i++) {
        $coordinates[] = [44.26 + ($i * 0.001), -72.58 + ($i * 0.001)];
    }

    $route = Route::factory()->withCoordinates($coordinates)->create();

    expect($route->coordinatesForMap())->toHaveCount(50);
});
