<x-layouts::app :title="__('Subscription Inactive')">
    <div class="mx-auto mt-12 max-w-2xl rounded-lg border border-red-200 bg-white p-6 shadow">
        <h1 class="mb-4 text-2xl font-bold text-red-600">{{ __('Subscription Inactive') }}</h1>
        <p class="mb-6 text-gray-700">
            {{ __("Your clinic's subscription is currently inactive. Please contact the platform administrator to renew your subscription and restore access to the queue system.") }}
        </p>
        @if (session('error'))
            <p class="mb-6 text-red-600">{{ session('error') }}</p>
        @endif
        <a href="{{ route('dashboard') }}" class="text-blue-600 hover:underline">
            {{ __('Return to Dashboard') }}
        </a>
    </div>
</x-layouts::app>
