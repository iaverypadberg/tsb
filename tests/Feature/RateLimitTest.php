<?php

use App\Jobs\AnalyseRoute;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('analyse-route:1');
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->route = Route::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

it('allows a sensible number of analyses', function () {
    Queue::fake();

    $component = Livewire::test('pages::routes.show', ['route' => $this->route]);

    foreach (range(1, 5) as $ignored) {
        $component->call('analyse')->assertHasNoErrors();
    }

    Queue::assertPushed(AnalyseRoute::class, 5);
});

it('stops a rider hammering the analyser', function () {
    Queue::fake();

    $component = Livewire::test('pages::routes.show', ['route' => $this->route]);

    foreach (range(1, 5) as $ignored) {
        $component->call('analyse');
    }

    $component->call('analyse')->assertHasErrors('riskTolerance');

    // The sixth never reached the queue.
    Queue::assertPushed(AnalyseRoute::class, 5);
    expect($this->route->analyses()->count())->toBe(5);
});

it('counts the limit per rider, not per route', function () {
    Queue::fake();

    $second = Route::factory()->for($this->user)->create();

    foreach (range(1, 5) as $ignored) {
        Livewire::test('pages::routes.show', ['route' => $this->route])->call('analyse');
    }

    Livewire::test('pages::routes.show', ['route' => $second])
        ->call('analyse')
        ->assertHasErrors('riskTolerance');
});

it('does not hold one rider back because of another', function () {
    Queue::fake();

    foreach (range(1, 5) as $ignored) {
        Livewire::test('pages::routes.show', ['route' => $this->route])->call('analyse');
    }

    $other = User::factory()->create();
    $theirRoute = Route::factory()->for($other)->create();

    Livewire::actingAs($other)
        ->test('pages::routes.show', ['route' => $theirRoute])
        ->call('analyse')
        ->assertHasNoErrors();
});

it('stops a rider hammering the uploader', function () {
    // beforeEach already made one route, so measure the change rather than
    // the total.
    $before = Route::count();

    $component = Livewire::test('pages::routes.upload');

    foreach (range(1, 10) as $i) {
        $component->set('gpx', UploadedFile::fake()->createWithContent("ride-{$i}.gpx", sampleGpx()))
            ->call('save')
            ->assertHasNoErrors();
    }

    $component->set('gpx', UploadedFile::fake()->createWithContent('one-too-many.gpx', sampleGpx()))
        ->call('save')
        ->assertHasErrors('gpx');

    expect(Route::count() - $before)->toBe(10);
});

it('tells the rider how long to wait', function () {
    Queue::fake();

    $component = Livewire::test('pages::routes.show', ['route' => $this->route]);

    foreach (range(1, 6) as $ignored) {
        $component->call('analyse');
    }

    expect($component->errors()->first('riskTolerance'))->toContain('seconds');
});
