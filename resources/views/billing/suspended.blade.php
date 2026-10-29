@php
    $isSuspended = session('clinic_suspended') || (session('error') && str_contains(strtolower((string) session('error')), 'suspended'));
@endphp
<x-layouts::app :title="$isSuspended ? __('Clinic Suspended') : __('Subscription Inactive')">
    <div class="mx-auto mt-12 max-w-2xl rounded-lg border border-danger/20 bg-app-surface p-6 shadow">
        <h1 class="mb-4 text-2xl font-bold text-danger">
            {{ $isSuspended ? __('Clinic Suspended') : __('Subscription Inactive') }}
        </h1>
        <p class="mb-6 text-app-muted">
            {{ $isSuspended
                ? __("Your clinic account is currently suspended. Please contact the platform administrator to restore access.")
                : __("Your clinic's subscription is currently inactive. Please contact the platform administrator to renew your subscription and restore access to the queue system.") }}
        </p>
        @if (session('error'))
            <p class="mb-6 font-medium text-danger">{{ session('error') }}</p>
        @endif
        <a href="{{ route('dashboard') }}" class="text-info hover:underline">
            {{ __('Return to Dashboard') }}
        </a>
    </div>
</x-layouts::app>

