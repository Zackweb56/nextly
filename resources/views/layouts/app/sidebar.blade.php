<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-app-page text-app-text">
        <flux:sidebar sticky collapsible="mobile" class="flex h-screen flex-col border-e border-app-border bg-app-sidebar">
            <flux:sidebar.header>
                @php
                    $isPlatformOwner = auth()->user()->is_platform_owner;
                    $currentClinic = $isPlatformOwner ? null : \App\Support\CurrentClinic::get();
                @endphp

                @if ($currentClinic)
                    <flux:sidebar.brand
                        :name="$currentClinic->name"
                        :href="route('clinic.dashboard', ['clinic' => $currentClinic->slug])"
                        wire:navigate
                    >
                        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
                            @if ($currentClinic->logo_path)
                                <img src="{{ Storage::url($currentClinic->logo_path) }}" alt="{{ $currentClinic->name }}" class="size-8 rounded-md object-cover">
                            @else
                                <img src="{{ asset('assets/logo_branding/nextly_primary.jpg') }}" alt="{{ $currentClinic->name }}" class="size-8 rounded-md object-cover">
                            @endif
                        </x-slot>
                    </flux:sidebar.brand>
                @else
                    <x-app-logo
                        :sidebar="true"
                        :href="$isPlatformOwner ? route('owner.dashboard') : route('dashboard')"
                        wire:navigate
                    />
                @endif
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="flex-1">
                @if ($isPlatformOwner)
                    <flux:sidebar.group :heading="__('Platform')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('owner.dashboard')" :current="request()->routeIs('owner.dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-office-2" :href="route('owner.clinics.index')" :current="request()->routeIs('owner.clinics.*')" wire:navigate>
                            {{ __('Clinics') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="credit-card" :href="route('owner.plans.index')" :current="request()->routeIs('owner.plans.*')" wire:navigate>
                            {{ __('Plans') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="banknotes" :href="route('owner.payments.index')" :current="request()->routeIs('owner.payments.*')" wire:navigate>
                            {{ __('Payments') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @else
                    @php
                        $clinicSlug = $currentClinic?->slug;
                    @endphp

                    @if ($clinicSlug)
                        <flux:sidebar.group class="grid">
                            <flux:sidebar.item
                                icon="home"
                                :href="route('clinic.dashboard', ['clinic' => $clinicSlug])"
                                :current="request()->routeIs('clinic.dashboard')"
                                wire:navigate
                            >
                                {{ __('Dashboard') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>

                        @if (auth()->user()->hasPermissionTo('users.view') || auth()->user()->hasPermissionTo('roles.view'))
                            <flux:sidebar.group :heading="__('Gestion')" class="grid">
                                @if (auth()->user()->hasPermissionTo('users.view'))
                                    <flux:sidebar.item
                                        icon="users"
                                        :href="route('clinic.users.index', ['clinic' => $clinicSlug])"
                                        :current="request()->routeIs('clinic.users.*')"
                                        wire:navigate
                                    >
                                        {{ __('Utilisateurs') }}
                                    </flux:sidebar.item>
                                @endif
                                @if (auth()->user()->hasPermissionTo('roles.view'))
                                    <flux:sidebar.item
                                        icon="shield-check"
                                        :href="route('clinic.roles.index', ['clinic' => $clinicSlug])"
                                        :current="request()->routeIs('clinic.roles.*')"
                                        wire:navigate
                                    >
                                        {{ __('Rôles') }}
                                    </flux:sidebar.item>
                                @endif
                            </flux:sidebar.group>
                        @endif

                        @if (auth()->user()->hasPermissionTo('services.view') || auth()->user()->hasPermissionTo('patients.view') || auth()->user()->hasPermissionTo('queue.view') || auth()->user()->hasPermissionTo('appointments.view'))
                            <flux:sidebar.group :heading="__('Clinique')" class="grid">
                                @if (auth()->user()->hasPermissionTo('services.view'))
                                    <flux:sidebar.item
                                        icon="briefcase"
                                        :href="url('/' . $clinicSlug . '/services')"
                                        :current="request()->is($clinicSlug . '/services*')"
                                        wire:navigate
                                    >
                                        {{ __('Services') }}
                                    </flux:sidebar.item>
                                @endif
                                @if (auth()->user()->hasPermissionTo('patients.view'))
                                    <flux:sidebar.item
                                        icon="user-group"
                                        :href="url('/' . $clinicSlug . '/patients')"
                                        :current="request()->is($clinicSlug . '/patients*')"
                                        wire:navigate
                                    >
                                        {{ __('Patients') }}
                                    </flux:sidebar.item>
                                @endif
                                @if (auth()->user()->hasPermissionTo('queue.view'))
                                    <flux:sidebar.item
                                        icon="queue-list"
                                        :href="url('/' . $clinicSlug . '/queue')"
                                        :current="request()->is($clinicSlug . '/queue*')"
                                        wire:navigate
                                    >
                                        {{ __("File d'attente") }}
                                    </flux:sidebar.item>
                                @endif
                                @if (auth()->user()->hasPermissionTo('appointments.view'))
                                    <flux:sidebar.item
                                        icon="calendar"
                                        :href="url('/' . $clinicSlug . '/appointments')"
                                        :current="request()->is($clinicSlug . '/appointments*')"
                                        wire:navigate
                                    >
                                        {{ __('Rendez-vous') }}
                                    </flux:sidebar.item>
                                @endif
                            </flux:sidebar.group>
                        @endif

                        @if (auth()->user()->hasPermissionTo('settings.manage'))
                            <flux:sidebar.group :heading="__('Paramètres')" class="grid">
                                <flux:sidebar.item
                                    icon="cog-6-tooth"
                                    :href="url('/' . $clinicSlug . '/settings')"
                                    :current="request()->is($clinicSlug . '/settings*')"
                                    wire:navigate
                                >
                                    {{ __('Paramètres de la clinique') }}
                                </flux:sidebar.item>
                            </flux:sidebar.group>
                        @endif
                    @else
                        <flux:sidebar.group :heading="__('Clinique')" class="grid">
                            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                                {{ __('Dashboard') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @endif
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="mt-auto hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="bg-app-surface lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @if (session('toast'))
            @php
                $toast = session('toast');
            @endphp
            <div
                x-data
                x-init="
                    $nextTick(() => {
                        $flux.toast(
                            {{ json_encode($toast['text'] ?? '') }},
                            {
                                heading: {{ json_encode($toast['heading'] ?? null) }},
                                variant: {{ json_encode($toast['variant'] ?? 'danger') }}
                            }
                        );
                    })
                "
            ></div>
        @endif

        @fluxScripts
    </body>
</html>
