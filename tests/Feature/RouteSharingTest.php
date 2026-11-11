<?php

use App\Actions\Visitors\CurrentVisitor;
use App\Jobs\ProfileRouteTraffic;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Act as the browser session that uploaded a guest route.
 */
function asTheGuestWhoUploaded(Route $route): void
{
    session([CurrentVisitor::SESSION_KEY => $route->guest_token]);
}

it('lets a guest share the route they uploaded', function () {
    $route = Route::factory()->guest()->create();

    asTheGuestWhoUploaded($route);

    Livewire::test('pages::routes.show', ['route' => $route])
        ->call('share')
        ->assertHasNoErrors();

    expect($route->refresh()->isShared())->toBeTrue();
});

it('lets anyone with the link read a shared guest route', function () {
    $route = Route::factory()->guest()->shared()->create(['name' => 'Montpelier loop']);

    // No session, no account: someone who was sent the link.
    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Montpelier loop');
});

it('lets anyone with the link read a shared riders route', function () {
    $route = Route::factory()->for(User::factory())->shared()->create(['name' => 'Worcester ridge']);

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Worcester ridge');
});

it('lets a rider share their own route', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::routes.show', ['route' => $route])
        ->call('share')
        ->assertHasNoErrors();

    expect($route->refresh()->isShared())->toBeTrue();
});

it('closes the link again when sharing stops', function () {
    $user = User::factory()->create();
    $route = Route::factory()->for($user)->shared()->create();

    Livewire::actingAs($user)
        ->test('pages::routes.show', ['route' => $route])
        ->call('stopSharing')
        ->assertHasNoErrors();

    expect($route->refresh()->isShared())->toBeFalse();

    // Step out of the owner's shoes: the link has to be dead for the person
    // it was sent to, who is neither signed in nor the uploading session.
    auth()->logout();

    $this->get(route('routes.show', $route))->assertForbidden();
});

/**
 * Being sent a link is not a licence to pass it on from inside the app, nor
 * to take the owner's sharing away.
 */
it('does not let someone who was sent the link share it on', function () {
    $route = Route::factory()->guest()->shared()->create();

    Livewire::test('pages::routes.show', ['route' => $route])
        ->call('share')
        ->assertForbidden();
});

it('does not let someone who was sent the link stop the sharing', function () {
    $route = Route::factory()->for(User::factory())->shared()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::routes.show', ['route' => $route])
        ->call('stopSharing')
        ->assertForbidden();

    expect($route->refresh()->isShared())->toBeTrue();
});

it('does not let someone who was sent the link delete the route', function () {
    $route = Route::factory()->for(User::factory())->shared()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::routes.index')
        ->call('delete', $route)
        ->assertForbidden();

    expect(Route::find($route->id))->not->toBeNull();
});

/**
 * The page said when the route would go away, so that has to hold whether or
 * not the pruner has been round since.
 */
it('stops showing an expired route before anything has deleted it', function () {
    $route = Route::factory()->expired()->shared()->create();

    expect(Route::find($route->id))->not->toBeNull();

    $this->get(route('routes.show', $route))->assertForbidden();
});

it('stops showing an expired route even to the guest who uploaded it', function () {
    $route = Route::factory()->expired()->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => $route->guest_token])
        ->get(route('routes.show', $route))
        ->assertForbidden();
});

it('offers the share panel to the owner', function () {
    $route = Route::factory()->guest()->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => $route->guest_token])
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Share this route');
});

it('does not offer the share panel to someone who was sent the link', function () {
    $route = Route::factory()->guest()->shared()->create();

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertDontSee('Share this route');
});

it('tells the owner of a guest route when it goes away and what to do', function () {
    $route = Route::factory()->guest()->create();

    $this->withSession([CurrentVisitor::SESSION_KEY => $route->guest_token])
        ->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('Create an account to keep this route')
        ->assertSee($route->expires_at->toFormattedDateString());
});

it('tells someone who was sent the link when it stops working', function () {
    $route = Route::factory()->guest()->shared()->create();

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertSee('This link has a time limit')
        ->assertSee($route->expires_at->toFormattedDateString())
        ->assertDontSee('Create an account to keep this route');
});

it('says nothing about expiry on a route that is here to stay', function () {
    $route = Route::factory()->for(User::factory())->shared()->create();

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertDontSee('This link has a time limit');
});

/**
 * Work on a route belongs to whoever uploaded it, not to whoever opens a link.
 */
it('does not queue traffic work when a recipient opens the link', function () {
    Queue::fake();

    $route = Route::factory()->guest()->shared()->create([
        'traffic_status' => Route::TRAFFIC_UNPROFILED,
    ]);

    $this->get(route('routes.show', $route))->assertOk();

    Queue::assertNotPushed(ProfileRouteTraffic::class);
    expect($route->refresh()->traffic_status)->toBe(Route::TRAFFIC_UNPROFILED);
});

it('still queues traffic work when the owner opens their own route', function () {
    Queue::fake();

    $route = Route::factory()->guest()->create(['traffic_status' => Route::TRAFFIC_UNPROFILED]);

    $this->withSession([CurrentVisitor::SESSION_KEY => $route->guest_token])
        ->get(route('routes.show', $route))
        ->assertOk();

    Queue::assertPushed(ProfileRouteTraffic::class);
});

/**
 * A link already sent to someone has to keep working once the person who
 * sent it signs up — claiming the route takes the countdown off it, not the
 * sharing.
 */
it('keeps a link shared before signing up working afterwards', function () {
    $route = Route::factory()->guest()->shared()->create();
    $token = $route->guest_token;

    $this->withSession([CurrentVisitor::SESSION_KEY => $token])
        ->post(route('register.store'), [
            'name' => 'Avery',
            'email' => 'avery@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

    expect($route->refresh()->user_id)->not->toBeNull()
        ->and($route->expires_at)->toBeNull()
        ->and($route->isShared())->toBeTrue();

    auth()->logout();

    $this->get(route('routes.show', $route))
        ->assertOk()
        ->assertDontSee('This link has a time limit');
});

/**
 * Asked of the policy rather than the page, because an expired route cannot
 * be opened at all — there is no page left to press a button on.
 */
it('refuses to share a route whose time is already up', function () {
    $route = Route::factory()->expired()->create();

    asTheGuestWhoUploaded($route);

    expect(Gate::allows('share', $route))->toBeFalse()
        ->and(Gate::allows('view', $route))->toBeFalse();
});
