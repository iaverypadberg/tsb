<?php

use App\Actions\Visitors\CurrentVisitor;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function guestGpxUpload(string $name = 'ride.gpx'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, sampleGpx());
}

it('lets someone without an account upload a route', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', guestGpxUpload())
        ->call('save')
        ->assertHasNoErrors();

    $route = Route::sole();

    expect($route->user_id)->toBeNull()
        ->and($route->guest_token)->toBe(session(CurrentVisitor::SESSION_KEY))
        ->and($route->guest_token)->not->toBeNull()
        ->and($route->expires_at)->not->toBeNull()
        ->and($route->expires_at->isAfter(now()->addDays(Route::GUEST_LIFETIME_DAYS - 1)))->toBeTrue();
});

/**
 * The whole point of the upload is what comes back from it, and a guest has
 * no route list to find it in.
 */
it('takes a guest straight to what they uploaded', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', guestGpxUpload())
        ->call('save')
        ->assertRedirect(route('routes.show', Route::sole()));
});

it('keeps a signed in rider on their route list', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::routes.upload')
        ->set('gpx', guestGpxUpload())
        ->call('save')
        ->assertNoRedirect()
        ->assertDispatched('route-uploaded');

    expect(Route::sole()->user_id)->toBe($user->id)
        ->and(Route::sole()->guest_token)->toBeNull()
        ->and(Route::sole()->expires_at)->toBeNull();
});

/**
 * The flow as a guest actually walks it: upload, read the result, wander off
 * to another page, come back to Routes.
 */
it('still has the route on the list after a guest leaves the page and returns', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', guestGpxUpload())
        ->call('save')
        ->assertHasNoErrors();

    $this->get(route('dashboard'))->assertOk();

    $this->get(route('routes.index'))
        ->assertOk()
        ->assertSee(Route::sole()->name);
});

/**
 * The upload lives for days and can be shared, so a guest who wanders off to
 * another page has to be able to find their way back to it.
 */
it('lists a guests own uploads when they come back to the routes page', function () {
    $route = Route::factory()->guest('my-token')->create(['name' => 'Montpelier loop']);

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.index'))
        ->assertOk()
        ->assertSee('Montpelier loop')
        ->assertSee('Goes away');
});

it('keeps another guests uploads out of that list', function () {
    Route::factory()->guest('someone-elses-token')->create(['name' => 'Not your loop']);

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.index'))
        ->assertOk()
        ->assertDontSee('Not your loop');
});

/**
 * Listing it would offer a link to a page that turns them away.
 */
it('drops an expired upload off the list', function () {
    Route::factory()->guest('my-token')->create([
        'name' => 'Long gone loop',
        'expires_at' => now()->subMinute(),
    ]);

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.index'))
        ->assertOk()
        ->assertDontSee('Long gone loop');
});

it('offers a guest no way to delete what they uploaded', function () {
    $route = Route::factory()->guest('my-token')->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.index'))
        ->assertOk()
        ->assertDontSee('delete-route-'.$route->getRouteKey());
});

it('still offers a rider the bin on their own routes', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('routes.index'))
        ->assertOk()
        ->assertSee('delete-route-'.$route->getRouteKey());
});

it('lets a guest read the route they uploaded', function () {
    $route = Route::factory()->guest('my-token')->create(['name' => 'Montpelier loop']);

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Montpelier loop');
});

it('does not let a guest read another guests upload', function () {
    $route = Route::factory()->guest('someone-elses-token')->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.show', $route))
        ->assertForbidden();
});

/**
 * A visitor who has never uploaded anything has no token, and a guest route
 * with no token to match is nobody's.
 */
it('does not let a visitor with no session read a guest upload', function () {
    $route = Route::factory()->guest()->create();

    $this->get(route('routes.show', $route))->assertForbidden();
});

it('does not let a signed in rider read a guest upload', function () {
    $route = Route::factory()->guest()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('routes.show', $route))
        ->assertForbidden();
});

it('asks a guest to create an account to keep the route', function () {
    $route = Route::factory()->guest('my-token')->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Create an account to keep this route');
});

it('does not ask a rider to create the account they already have', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertDontSee('Create an account to keep this route');
});

it('hands a guests uploads to the account they register', function () {
    $route = Route::factory()->guest('my-token')->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->post(route('register.store'), [
            'name' => 'Avery',
            'email' => 'avery@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertSessionHasNoErrors();

    $user = User::whereEmail('avery@example.com')->sole();

    expect($route->refresh()->user_id)->toBe($user->id)
        ->and($route->guest_token)->toBeNull()
        ->and($route->expires_at)->toBeNull();
});

it('hands a guests uploads to the account they log into', function () {
    $user = User::factory()->create();
    $route = Route::factory()->guest('my-token')->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    expect($route->refresh()->user_id)->toBe($user->id)
        ->and($route->guest_token)->toBeNull()
        ->and($route->expires_at)->toBeNull();
});

it('leaves another guests uploads alone when someone signs in', function () {
    $user = User::factory()->create();
    $theirs = Route::factory()->guest('someone-elses-token')->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => 'my-token'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    expect($theirs->refresh()->user_id)->toBeNull()
        ->and($theirs->guest_token)->toBe('someone-elses-token');
});

it('caps how much one address can upload without an account', function () {
    $component = Livewire::test('pages::routes.upload');

    foreach (range(1, 5) as $i) {
        $component->set('gpx', guestGpxUpload("ride-{$i}.gpx"))
            ->call('save')
            ->assertHasNoErrors();
    }

    $component->set('gpx', guestGpxUpload('one-too-many.gpx'))
        ->call('save')
        ->assertHasErrors('gpx');

    expect(Route::count())->toBe(5);
});

it('does not let a guest delete the route they uploaded', function () {
    $route = Route::factory()->guest('my-token')->create();

    session([CurrentVisitor::SESSION_KEY => 'my-token']);

    Livewire::test('pages::routes.index')
        ->call('delete', $route)
        ->assertForbidden();

    expect(Route::find($route->id))->not->toBeNull();
});

it('keeps flagging a road behind an account', function () {
    $this->get(route('flags.index'))->assertRedirect(route('login'));
});
