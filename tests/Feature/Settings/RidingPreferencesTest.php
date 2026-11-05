<?php

// TEMP: danger scoring is hidden until it is integrated back in.
// use App\Models\Route;
use App\Models\RouteAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('starts a new rider off on the normal setting', function () {
    expect(User::factory()->create()->risk_tolerance)->toBe(RouteAnalysis::TOLERANCE_NORMAL);
});

it('shows the rider their current setting', function () {
    $user = User::factory()->create(['risk_tolerance' => RouteAnalysis::TOLERANCE_BRAVE]);

    Livewire::actingAs($user)
        ->test('pages::settings.riding')
        ->assertSet('riskTolerance', RouteAnalysis::TOLERANCE_BRAVE);
});

it('saves a new risk tolerance', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.riding')
        ->set('riskTolerance', RouteAnalysis::TOLERANCE_CAUTIOUS)
        ->call('updateRidingPreferences')
        ->assertHasNoErrors();

    expect($user->refresh()->risk_tolerance)->toBe(RouteAnalysis::TOLERANCE_CAUTIOUS);
});

it('refuses a tolerance it does not know', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.riding')
        ->set('riskTolerance', 'reckless')
        ->call('updateRidingPreferences')
        ->assertHasErrors('riskTolerance');

    expect($user->refresh()->risk_tolerance)->toBe(RouteAnalysis::TOLERANCE_NORMAL);
});

it('explains what each setting will flag', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('riding.edit'))
        ->assertOk()
        ->assertSee('Cautious')
        ->assertSee('Brave')
        ->assertSee((string) RouteAnalysis::THRESHOLDS['brave']);
});

it('turns a tolerance into the score it flags at', function () {
    $user = User::factory()->create(['risk_tolerance' => RouteAnalysis::TOLERANCE_CAUTIOUS]);

    expect($user->dangerThreshold())->toBe(RouteAnalysis::THRESHOLDS['cautious']);
});

it('falls back to the normal threshold if the stored value is nonsense', function () {
    $user = User::factory()->create();
    $user->forceFill(['risk_tolerance' => 'nonsense'])->save();

    expect($user->refresh()->dangerThreshold())->toBe(RouteAnalysis::THRESHOLDS['normal']);
});

it('links to riding settings from the settings nav', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('riding.edit'));
});

it('keeps guests out of riding settings', function () {
    $this->get(route('riding.edit'))->assertRedirect(route('login'));
});

// TEMP: danger scoring is hidden until it is integrated back in.
// it('starts a route page on the riders saved setting', function () {
//     $user = User::factory()->create(['risk_tolerance' => RouteAnalysis::TOLERANCE_BRAVE]);
//     $route = Route::factory()->for($user)->create();
//
//     Livewire::actingAs($user)
//         ->test('pages::routes.show', ['route' => $route])
//         ->assertSet('riskTolerance', RouteAnalysis::TOLERANCE_BRAVE);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('keeps showing what a route was last analysed with, not the saved setting', function () {
//     $user = User::factory()->create(['risk_tolerance' => RouteAnalysis::TOLERANCE_BRAVE]);
//     $route = Route::factory()->for($user)->create();
//
//     RouteAnalysis::factory()->for($route)->completed()->tolerance(RouteAnalysis::TOLERANCE_CAUTIOUS)->create();
//
//     Livewire::actingAs($user)
//         ->test('pages::routes.show', ['route' => $route])
//         ->assertSet('riskTolerance', RouteAnalysis::TOLERANCE_CAUTIOUS);
// });

// TEMP: danger scoring is hidden until it is integrated back in.
// it('runs the analysis at whatever the page was set to', function () {
//     $user = User::factory()->create(['risk_tolerance' => RouteAnalysis::TOLERANCE_CAUTIOUS]);
//     $route = Route::factory()->for($user)->create();
//
//     Livewire::actingAs($user)
//         ->test('pages::routes.show', ['route' => $route])
//         ->call('analyse')
//         ->assertHasNoErrors();
//
//     expect($route->analyses()->sole()->risk_tolerance)->toBe(RouteAnalysis::TOLERANCE_CAUTIOUS);
// });
