@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Nextly')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <img src="{{ asset('assets/logo_branding/nextly_primary.jpg') }}" alt="" class="size-8 rounded-md object-cover">
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Nextly')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <img src="{{ asset('assets/logo_branding/nextly_primary.jpg') }}" alt="" class="size-8 rounded-md object-cover">
        </x-slot>
    </flux:brand>
@endif
