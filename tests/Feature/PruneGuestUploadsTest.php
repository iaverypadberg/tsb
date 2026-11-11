<?php

use App\Models\Route;
use App\Models\TrafficSection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An unclaimed upload with a GPX file on disk behind it, expiring when told.
 */
function guestUpload(string $token, DateTimeInterface $expiresAt, string $file = 'ride.gpx'): Route
{
    $path = "routes/guests/{$token}/{$file}";

    Storage::put($path, 'gpx contents');

    return Route::factory()->guest($token)->create([
        'original_gpx_path' => $path,
        'expires_at' => $expiresAt,
    ]);
}

it('deletes an upload whose time is up', function () {
    $route = guestUpload('stale-token', now()->subMinute());

    $this->artisan('routes:prune-guest-uploads')
        ->expectsOutputToContain('Deleted 1 expired uploads')
        ->assertSuccessful();

    expect(Route::find($route->id))->toBeNull();
    Storage::assertMissing('routes/guests/stale-token/ride.gpx');
});

it('keeps an upload whose time has not come', function () {
    $route = guestUpload('fresh-token', now()->addDays(3));

    $this->artisan('routes:prune-guest-uploads')->assertSuccessful();

    expect(Route::find($route->id))->not->toBeNull();
    Storage::assertExists('routes/guests/fresh-token/ride.gpx');
});

/**
 * The whole promise of signing up is that the route stops being temporary.
 */
it('never touches a route an account has claimed', function () {
    $route = Route::factory()
        ->for(User::factory())
        ->create(['created_at' => now()->subYears(2)]);

    $this->artisan('routes:prune-guest-uploads')->assertSuccessful();

    expect(Route::find($route->id))->not->toBeNull();
});

/**
 * Tied together in the database, because a claimed route carrying a countdown
 * would be deleted out from under the account that claimed it.
 */
it('will not let a claimed route carry an expiry at all', function () {
    $route = Route::factory()->for(User::factory())->create();

    expect(fn () => $route->forceFill(['expires_at' => now()->addDay()])->save())
        ->toThrow(QueryException::class);
});

it('clears out the guest folder once nothing of theirs is left', function () {
    guestUpload('stale-token', now()->subMinute());

    $this->artisan('routes:prune-guest-uploads')->assertSuccessful();

    expect(Storage::directoryExists('routes/guests/stale-token'))->toBeFalse();
});

/**
 * A guest who uploaded twice, days apart, still has the newer one.
 */
it('leaves the guest folder alone while a newer upload of theirs stands', function () {
    guestUpload('mixed-token', now()->subMinute());
    guestUpload('mixed-token', now()->addDays(3), file: 'today.gpx');

    $this->artisan('routes:prune-guest-uploads')->assertSuccessful();

    expect(Route::count())->toBe(1);
    Storage::assertMissing('routes/guests/mixed-token/ride.gpx');
    Storage::assertExists('routes/guests/mixed-token/today.gpx');
});

it('takes the traffic worked out for a pruned upload with it', function () {
    $route = guestUpload('stale-token', now()->subMinute());
    TrafficSection::factory()->for($route)->create();

    $this->artisan('routes:prune-guest-uploads')->assertSuccessful();

    expect(TrafficSection::count())->toBe(0);
});

it('deletes nothing when asked only to report', function () {
    $route = guestUpload('stale-token', now()->subMinute());

    $this->artisan('routes:prune-guest-uploads', ['--pretend' => true])
        ->expectsOutputToContain('Nothing was deleted')
        ->assertSuccessful();

    expect(Route::find($route->id))->not->toBeNull();
    Storage::assertExists('routes/guests/stale-token/ride.gpx');
});

it('says so when there is nothing to prune', function () {
    guestUpload('fresh-token', now()->addDays(3));

    $this->artisan('routes:prune-guest-uploads')
        ->expectsOutputToContain('No expired uploads')
        ->assertSuccessful();
});
