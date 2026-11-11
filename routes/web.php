<?php

use App\Models\RoadFlag;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/*
 * Open to everyone.
 *
 * Uploading a route and reading what came back needs no account: it is the
 * one thing someone can try before deciding whether this is for them. Who
 * owns an upload, and so who may read it, is settled by the route policy
 * rather than by middleware here.
 */
Route::view('dashboard', 'dashboard')->name('dashboard');

Route::livewire('routes', 'pages::routes.index')->name('routes.index');
Route::livewire('routes/{route}', 'pages::routes.show')->name('routes.show');

/*
 * Behind an account.
 *
 * Flagging a road puts a claim about a public road in front of a maintainer
 * and, once accepted, into a road's score. That is worth a sign-up.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('flags', 'pages::flags.index')->name('flags.index');

    Route::livewire('admin/road-flags', 'pages::admin.road-flags')
        ->middleware('can:review,'.RoadFlag::class)
        ->name('admin.road-flags');
});

require __DIR__.'/settings.php';
