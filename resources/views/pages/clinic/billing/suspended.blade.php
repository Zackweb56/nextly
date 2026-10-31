<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased selection:bg-zinc-900 selection:text-white dark:selection:bg-white dark:selection:text-zinc-900">
@php
    $clinic = $clinic ?? \App\Support\CurrentClinic::get() ?? auth()->user()?->clinics()->first();

    $reason = $reason ?? (function () use ($clinic): string {
        if (! $clinic) {
            return 'no_subscription';
        }

        if (! $clinic->is_active) {
            return 'suspended_by_owner';
        }

        $subscription = $clinic->subscription ?? \App\Models\Subscription::withoutGlobalScope('clinic')
            ->where('clinic_id', $clinic->id)
            ->latest('id')
            ->first();

        if (! $subscription) {
            return 'no_subscription';
        }

        if ($subscription->status === 'trialing' && ($subscription->trial_ends_at === null || $subscription->trial_ends_at->isPast())) {
            return 'expired_trial';
        }

        if ($subscription->status === 'cancelled') {
            return 'cancelled';
        }

        if ($subscription->status === 'past_due' || ($subscription->ends_at !== null && $subscription->ends_at->isPast())) {
            return 'past_due';
        }

        return 'past_due';
    })();

    $stateDetails = match ($reason) {
        'active_valid' => [
            'icon' => 'check-circle',
            'title' => __('Accès Actif'),
            'description' => __('Votre clinique dispose d\'un abonnement actif. Vous pouvez accéder à votre tableau de bord normalement.'),
            'icon_class' => 'bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400',
            'bar_class' => 'bg-green-500',
            'glow_class' => 'bg-green-500',
            'title_color' => 'text-green-600 dark:text-green-500',
        ],
        'trial_valid' => [
            'icon' => 'clock',
            'title' => __('Période d\'essai active'),
            'description' => __('Votre période d\'essai est toujours en cours. Vous pouvez accéder à votre tableau de bord normalement.'),
            'icon_class' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400',
            'bar_class' => 'bg-blue-500',
            'glow_class' => 'bg-blue-500',
            'title_color' => 'text-blue-600 dark:text-blue-500',
        ],
        'expired_trial' => [
            'icon' => 'lock-closed',
            'title' => __('Essai expiré'),
            'description' => __('Votre période d\'essai gratuit est arrivée à son terme. Pour continuer à profiter de toutes les fonctionnalités de Nextly et gérer votre file d\'attente, veuillez souscrire à un abonnement.'),
            'icon_class' => 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400 border-amber-200/80 dark:border-amber-800/60',
            'bar_class' => 'bg-amber-500',
            'glow_class' => 'bg-amber-500',
            'title_color' => 'text-red-600 dark:text-red-500',
        ],
        'past_due' => [
            'icon' => 'clock',
            'title' => __('Abonnement en retard'),
            'description' => __('Votre abonnement a expiré et est actuellement en attente de règlement. L\'accès aux services de la clinique a été temporairement suspendu en attendant la régularisation du paiement.'),
            'icon_class' => 'bg-orange-100 text-orange-600 dark:bg-orange-950/60 dark:text-orange-400 border-orange-200/80 dark:border-orange-800/60',
            'bar_class' => 'bg-orange-500',
            'glow_class' => 'bg-orange-500',
            'title_color' => 'text-red-600 dark:text-red-500',
        ],
        'cancelled' => [
            'icon' => 'x-circle',
            'title' => __('Abonnement annulé'),
            'description' => __('L\'abonnement de votre clinique a été résilié. Si vous souhaitez réactiver votre compte et restaurer vos services immédiatement, contactez notre équipe support.'),
            'icon_class' => 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400 border-rose-200/80 dark:border-rose-800/60',
            'bar_class' => 'bg-rose-500',
            'glow_class' => 'bg-rose-500',
            'title_color' => 'text-red-600 dark:text-red-500',
        ],
        'suspended_by_owner' => [
            'icon' => 'exclamation-triangle',
            'title' => __('Clinique suspendue'),
            'description' => __('L\'accès à votre clinique a été suspendu par l\'administrateur de la plateforme. Veuillez prendre contact avec le support pour obtenir des précisions.'),
            'icon_class' => 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400 border-red-200/80 dark:border-red-800/60',
            'bar_class' => 'bg-red-500',
            'glow_class' => 'bg-red-500',
            'title_color' => 'text-red-600 dark:text-red-500',
        ],
        'no_subscription' => [
            'icon' => 'credit-card',
            'title' => __('Aucun abonnement actif'),
            'description' => __('Votre clinique n\'a actuellement aucun plan d\'abonnement assigné. Contactez l\'administrateur ou le support pour activer un forfait adapté à vos besoins.'),
            'icon_class' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800/80 dark:text-zinc-400 border-zinc-200 dark:border-zinc-700',
            'bar_class' => 'bg-zinc-400 dark:bg-zinc-600',
            'glow_class' => 'bg-zinc-500',
            'title_color' => 'text-zinc-600 dark:text-zinc-500',
        ],
        default => [
            'icon' => 'lock-closed',
            'title' => __('Abonnement inactif'),
            'description' => __('L\'accès aux services de votre clinique est actuellement restreint. Veuillez contacter le support pour réactiver votre espace.'),
            'icon_class' => 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400 border-red-200/80 dark:border-red-800/60',
            'bar_class' => 'bg-red-500',
            'glow_class' => 'bg-red-500',
            'title_color' => 'text-red-600 dark:text-red-500',
        ],
    };
@endphp

    <div class="w-full max-w-2xl flex flex-col items-center">
        <flux:card class="w-full rounded-2xl shadow-xl border border-zinc-200/80 dark:border-zinc-800/80 overflow-hidden bg-white dark:bg-zinc-900">
            
            <!-- Header -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 p-6 sm:px-8 border-b border-zinc-100 dark:border-zinc-800/80 bg-zinc-50/50 dark:bg-zinc-800/20">
                <div class="flex items-center gap-3">
                    <x-app-logo />
                </div>
                
                <div class="flex flex-col items-center sm:items-end text-sm text-zinc-600 dark:text-zinc-400">
                    @if ($clinic)
                        <div class="font-medium text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                            <flux:icon icon="building-office-2" class="size-4 text-zinc-400" />
                            {{ $clinic->name }}
                        </div>
                    @endif
                    <div class="text-xs mt-1">
                        {{ auth()->user()->name }} &middot; {{ auth()->user()->email }}
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="p-6 sm:p-10 text-center relative">
                <!-- Decorative Accent Top Line -->
                <div class="absolute top-0 inset-x-0 h-1.5 {{ $stateDetails['bar_class'] }}"></div>
                <div class="absolute -top-24 left-1/2 -translate-x-1/2 size-48 rounded-full blur-3xl pointer-events-none opacity-10 {{ $stateDetails['glow_class'] }}"></div>

                <!-- State Icon -->
                <div class="mx-auto size-14 rounded-full flex items-center justify-center mb-5 {{ $stateDetails['icon_class'] }} relative z-10">
                    <flux:icon :icon="$stateDetails['icon']" class="size-7" />
                </div>

                <!-- Heading -->
                <flux:heading size="xl" class="mb-2.5 {{ $stateDetails['title_color'] }} font-bold tracking-tight relative z-10">
                    {{ $stateDetails['title'] }}
                </flux:heading>

                <!-- Description -->
                <flux:text class="text-sm sm:text-base text-zinc-500 dark:text-zinc-400 leading-relaxed max-w-md mx-auto mb-8 relative z-10">
                    {{ $stateDetails['description'] }}
                </flux:text>

                @if ($isActive)
                    <!-- Return to Dashboard Button -->
                    <div class="relative z-10 max-w-sm mx-auto">
                        <flux:button href="{{ route('clinic.dashboard', ['clinic' => $clinic->slug]) }}" variant="primary" class="w-full justify-center" icon="arrow-right">
                            {{ __('Retour au tableau de bord') }}
                        </flux:button>
                    </div>
                @else
                    <!-- Alert Box -->
                    @if (session('error'))
                        <div class="mb-6 p-3.5 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-400 text-xs sm:text-sm font-medium border border-red-200/60 dark:border-red-900/40 text-left flex items-start gap-2.5 relative z-10">
                            <flux:icon icon="exclamation-circle" class="size-4 shrink-0 mt-0.5 text-red-500" />
                            <span>{{ session('error') }}</span>
                        </div>
                    @endif

                    <!-- Contact Actions -->
                    <div class="space-y-3 relative z-10 max-w-sm mx-auto">
                        <flux:button
                            href="https://wa.me/212600000000?text={{ urlencode(__('Bonjour, je souhaite réactiver l\'abonnement de ma clinique sur Nextly.')) }}"
                            target="_blank"
                            variant="primary"
                            class="w-full justify-center gap-2.5 font-semibold shadow-sm hover:shadow transition-all !bg-[#25D366] hover:!bg-[#20ba59] !text-white !border-transparent"
                        >
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 00-3.48-8.413Z"/>
                            </svg>
                            <span>{{ __('Contacter le support (WhatsApp)') }}</span>
                        </flux:button>

                        <flux:button
                            href="mailto:support@nextly.app?subject={{ urlencode(__('Demande de réactivation d\'abonnement')) }}"
                            variant="ghost"
                            class="w-full justify-center gap-2.5 font-medium border border-zinc-200 dark:border-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-800/60"
                        >
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect width="20" height="16" x="2" y="4" rx="2"/>
                                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                            </svg>
                            <span>{{ __('Envoyer un e-mail à support@nextly.app') }}</span>
                        </flux:button>
                    </div>
                @endif

                <!-- Logout Link -->
                <div class="mt-8 pt-6 border-t border-zinc-100 dark:border-zinc-800/80 relative z-10">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300 transition-colors cursor-pointer"
                        >
                            <flux:icon icon="arrow-right-start-on-rectangle" class="size-3.5" />
                            {{ __('Se déconnecter') }}
                        </button>
                    </form>
                </div>
            </div>
            
        </flux:card>

        <p class="text-center text-xs text-zinc-400 dark:text-zinc-600 mt-6">
            &copy; {{ date('Y') }} {{ config('app.name', 'Nextly') }}. {{ __('Tous droits réservés.') }}
        </p>
    </div>

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>
</html>
