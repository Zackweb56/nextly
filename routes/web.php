<?php

use App\Http\Controllers\BillingSuspendedController;
use App\Http\Middleware\EnsureSubscriptionIsActive;
use App\Models\Clinic;
use App\Support\CurrentClinic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

// 1. Global Dashboard Redirect
Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        if ($request->user()?->is_platform_owner) {
            return redirect()->route('owner.dashboard');
        }

        $clinic = CurrentClinic::get();
        if ($clinic) {
            return redirect()->route('clinic.dashboard', ['clinic' => $clinic->slug]);
        }

        // A non-owner user with no clinic is an orphan account.
        abort(403, 'No clinic assigned. Please contact support.');
    })->name('dashboard');
});

// 2. Platform Owner Routes
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

// 3. Clinic Staff Routes (Tenant Scoped)
Route::middleware(['auth', EnsureSubscriptionIsActive::class])
    ->prefix('{clinic:slug}')
    ->name('clinic.')
    ->group(function () {
        Route::get('/billing/suspended', BillingSuspendedController::class)->name('billing.suspended');

        Route::livewire('/dashboard', 'pages::clinic.dashboard')
            ->name('dashboard');

        Route::livewire('/users', 'pages::clinic.users.index')
            ->middleware('clinic.permission:users.view')
            ->name('users.index');

        Route::livewire('/roles', 'pages::clinic.roles.index')
            ->middleware('clinic.permission:roles.view')
            ->name('roles.index');
    });

require __DIR__.'/settings.php';
