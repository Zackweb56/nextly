<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware('auth')->group(function () {
    Route::view('billing/suspended', 'billing.suspended')->name('billing.suspended');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        if ($request->user()?->is_platform_owner) {
            return redirect()->route('owner.dashboard');
        }

        return view('dashboard');
    })->name('dashboard');
});

Route::middleware(['auth', 'platform_owner'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {
        Route::livewire('/', 'pages::owner.dashboard')->name('dashboard');
        Route::livewire('/clinics', 'pages::owner.clinics.index')->name('clinics.index');
        Route::livewire('/clinics/{clinic:slug}', 'pages::owner.clinics.show')->name('clinics.show');
        Route::livewire('/plans', 'pages::owner.plans.index')->name('plans.index');
        Route::livewire('/payments', 'pages::owner.payments.index')->name('payments.index');
    });

require __DIR__.'/settings.php';
