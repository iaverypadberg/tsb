<?php

use App\Jobs\ProfileRouteTraffic;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function gpxUpload(string $contents, string $name = 'ride.gpx'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

it('stores an uploaded gpx file as a route', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx(metadataName: 'Montpelier loop')))
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('route-uploaded');

    $route = Route::sole();

    expect($route->user_id)->toBe($this->user->id)
        ->and($route->name)->toBe('Montpelier loop')
        ->and($route->source)->toBe(Route::SOURCE_GPX)
        ->and($route->point_count)->toBe(5);
});

/**
 * The traffic colouring is the first thing a new route can show, so the work
 * behind it starts before the rider has asked for anything.
 */
it('queues the traffic profile as soon as a route lands', function () {
    Queue::fake();

    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx()))
        ->call('save')
        ->assertHasNoErrors();

    Queue::assertPushed(ProfileRouteTraffic::class);

    expect(Route::sole()->traffic_status)->toBe(Route::TRAFFIC_QUEUED);
});

it('measures distance in metres on the spheroid', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx()))
        ->call('save')
        ->assertHasNoErrors();

    // Four ~0.002 lat / 0.003 lon steps near 44N is a little over a kilometre.
    expect(Route::sole()->distance_m)->toBeGreaterThan(1_000)
        ->and(Route::sole()->distance_m)->toBeLessThan(2_000);
});

it('records elevation gain from the track', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx()))
        ->call('save')
        ->assertHasNoErrors();

    // Five points climbing 10 metres each step.
    expect(Route::sole()->elevation_gain_m)->toBe(40);
});

it('keeps the original file so a later export can reuse its elevation data', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx()))
        ->call('save')
        ->assertHasNoErrors();

    $path = Route::sole()->original_gpx_path;

    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);
});

it('stores the line so postgis can measure it', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx()))
        ->call('save')
        ->assertHasNoErrors();

    $route = Route::sole();

    expect($route->geom->getPoints())->toHaveCount(5)
        ->and($route->coordinatesForMap())->toHaveCount(5);

    // Leaflet ordering: latitude first.
    expect($route->coordinatesForMap()[0][0])->toEqualWithDelta(44.26, 0.0001);
});

it('falls back to the file name when the gpx has no name', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx(), name: 'saturday-ride.gpx'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Route::sole()->name)->toBe('saturday-ride');
});

it('rejects a file that is not gpx', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload('just some text', name: 'notes.gpx'))
        ->call('save')
        ->assertHasErrors('gpx');

    expect(Route::count())->toBe(0);
});

it('rejects a cue sheet export and says how to fix it', function () {
    $cueSheet = gpxWithRoute(
        routePoint(44.27736, -72.56482, 'Start of route')
        .routePoint(44.29217, -72.67785, 'Turn left onto Vermont Route 100B')
        .routePoint(44.17213, -72.65048, 'Turn left onto Vermont Route 12 North')
    );

    $component = Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload($cueSheet))
        ->call('save')
        ->assertHasErrors('gpx');

    expect($component->errors()->first('gpx'))->toContain('GPX Track');
    expect(Route::count())->toBe(0);
});

it('rejects a gpx file with no track points', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(gpxWithTrack('')))
        ->call('save')
        ->assertHasErrors('gpx');

    expect(Route::count())->toBe(0);
});

it('requires a file', function () {
    Livewire::test('pages::routes.upload')
        ->call('save')
        ->assertHasErrors(['gpx' => 'required']);
});

it('rejects files with a disallowed extension', function () {
    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx(), name: 'ride.txt'))
        ->call('save')
        ->assertHasErrors('gpx');

    expect(Route::count())->toBe(0);
});

/**
 * A route is marked queued before the job is handed over, so that a second
 * reader does not queue the same work twice. If the handover then fails the
 * route must not keep that claim: it was the combination of an unenqueueable
 * job and a route left saying "queued" that stranded uploads on a spinner,
 * because the page only ever re-queues a route still marked unprofiled.
 *
 * Standing in for the real failure, which was a `cloud` connection resolving
 * to the sqs driver without the AWS SDK installed to build its client.
 */
it('does not leave a route claiming to be queued when the queue refuses the job', function () {
    config(['queue.default' => 'a-connection-that-does-not-exist']);

    Livewire::test('pages::routes.upload')
        ->set('gpx', gpxUpload(sampleGpx()))
        ->call('save')
        ->assertHasNoErrors();

    expect(Route::sole())
        ->traffic_status->toBe(Route::TRAFFIC_UNPROFILED)
        ->traffic_queued_at->toBeNull();
});
