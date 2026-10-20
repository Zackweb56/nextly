<?php

use Illuminate\Support\Facades\Route;

// Note: You must also update your routes/web.php to include {clinic:slug} in the prefix, but we will do that when we build the UI

Route::redirect('/', '/login')->name('home');

Route::middleware('auth')->group(function () {
    Route::view('billing/suspended', 'billing.suspended')->name('billing.suspended');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
