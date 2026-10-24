<x-layouts::app :title="__('Subscription Inactive')">
    <div class="mx-auto mt-12 max-w-2xl rounded-lg border border-danger/20 bg-app-surface p-6 shadow">
        <h1 class="mb-4 text-2xl font-bold text-danger">{{ __('Subscription Inactive') }}</h1>
        <p class="mb-6 text-app-muted">
            {{ __("Your clinic's subscription is currently inactive. Please contact the platform administrator to renew your subscription and restore access to the queue system.") }}
        </p>
        @if (session('error'))
            <p class="mb-6 text-danger">{{ session('error') }}</p>
        @endif
        <a href="{{ route('dashboard') }}" class="text-info hover:underline">
            {{ __('Return to Dashboard') }}
        </a>
    </div>
</x-layouts::app>
