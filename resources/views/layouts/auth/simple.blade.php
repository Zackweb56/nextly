<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-app-page antialiased dark:bg-navy">
        <div class="flex min-h-svh flex-col items-center justify-center gap-8 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="mb-3 flex justify-center" wire:navigate>
                    <img
                        src="{{ asset('assets/logo_branding/full_brand_nextly_primary.jpg') }}"
                        alt="{{ config('app.name', 'Nextly') }}"
                        class="h-28 w-64 rounded-lg object-cover"
                    >
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
