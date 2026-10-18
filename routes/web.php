<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('routes', 'pages::routes.index')->name('routes.index');
    Route::livewire('routes/{route}', 'pages::routes.show')->name('routes.show');
});

require __DIR__.'/settings.php';
