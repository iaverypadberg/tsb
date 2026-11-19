<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests can visit the dashboard', function () {
    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the landing page explains what the app is for', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee("Know what you're riding into")
        ->assertSee('which of its roads carry heavy traffic', escape: false);
});

test('the landing page draws a roadmap step for each entry', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Where this is going')
        ->assertSee('Danger scoring')
        ->assertSee('In progress');

    // One list item per entry in the $roadmap template.
    expect(substr_count($response->getContent(), 'data-test="roadmap-step"'))->toBe(5);
});

test('the landing page gives a copyable address to write to', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Get in touch')
        ->assertSee('iaverypadberg@gmail.com')
        ->assertSee('data-test="copy-email"', escape: false)
        ->assertSee('data-test="copy-email-address"', escape: false)
        ->assertDontSee('mailto:', escape: false);
});
