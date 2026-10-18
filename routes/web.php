<?php

use App\Models\RoadFlag;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('routes', 'pages::routes.index')->name('routes.index');
    Route::livewire('routes/{route}', 'pages::routes.show')->name('routes.show');

    Route::livewire('flags', 'pages::flags.index')->name('flags.index');

    Route::livewire('admin/road-flags', 'pages::admin.road-flags')
        ->middleware('can:review,'.RoadFlag::class)
        ->name('admin.road-flags');
});

require __DIR__.'/settings.php';
