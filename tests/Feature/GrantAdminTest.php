<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an account review flagged roads', function () {
    $user = User::factory()->create(['email' => 'maintainer@example.com']);

    $this->artisan('user:grant-admin maintainer@example.com')->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeTrue();
});

it('takes the privilege away again', function () {
    $user = User::factory()->admin()->create(['email' => 'maintainer@example.com']);

    $this->artisan('user:grant-admin maintainer@example.com --revoke')->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeFalse();
});

it('says so when there is no such account', function () {
    $this->artisan('user:grant-admin nobody@example.com')->assertFailed();
});
