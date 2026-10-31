<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\QueueTicket;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tableau de bord')] class extends Component
{
    #[Locked]
    public int $clinicId;

    public function mount(Clinic $clinic): void
    {
        $this->clinicId = $clinic->id;
    }

    #[Computed]
    public function clinic(): Clinic
    {
        return Clinic::query()->findOrFail($this->clinicId);
    }

    #[Computed]
    public function todayTickets(): int
    {
        return QueueTicket::query()
            ->where('clinic_id', $this->clinicId)
            ->whereDate('created_at', today())
            ->count();
    }

    #[Computed]
    public function todayCompleted(): int
    {
        return QueueTicket::query()
            ->where('clinic_id', $this->clinicId)
            ->whereDate('created_at', today())
            ->where('status', 'completed')
            ->count();
    }

    #[Computed]
    public function avgWaitTime(): int
    {
        $completed = QueueTicket::query()
            ->where('clinic_id', $this->clinicId)
            ->whereDate('created_at', today())
            ->where('status', 'completed')
            ->whereNotNull('called_at')
            ->get(['created_at', 'called_at']);

        if ($completed->isEmpty()) {
            return 0;
        }

        $totalMinutes = $completed->sum(function (QueueTicket $ticket): int {
            if (! $ticket->created_at || ! $ticket->called_at) {
                return 0;
            }

            return (int) max(0, $ticket->created_at->diffInMinutes($ticket->called_at));
        });

        return (int) round($totalMinutes / $completed->count());
    }

    /**
     * @return array{appointments: int, walk_ins: int}
     */
    #[Computed]
    public function walkInsVsAppointments(): array
    {
        $baseQuery = QueueTicket::query()
            ->where('clinic_id', $this->clinicId)
            ->whereDate('created_at', today());

        $appointments = (clone $baseQuery)
            ->whereNotNull('appointment_id')
            ->count();

        $walkIns = (clone $baseQuery)
            ->whereNull('appointment_id')
            ->count();

        return [
            'appointments' => $appointments,
            'walk_ins' => $walkIns,
        ];
    }

    /**
     * @return Collection<int, QueueTicket>
     */
    #[Computed]
    public function recentCalledTickets(): Collection
    {
        return QueueTicket::query()
            ->where('clinic_id', $this->clinicId)
            ->whereNotNull('called_at')
            ->where(function ($query): void {
                $query->whereDate('called_at', today())
                    ->orWhereDate('created_at', today());
            })
            ->with(['patient', 'service'])
            ->orderByDesc('called_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    #[Computed]
    public function todayAppointments(): Collection
    {
        return Appointment::query()
            ->where('clinic_id', $this->clinicId)
            ->whereDate('scheduled_at', today())
            ->with(['patient', 'service', 'provider'])
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();
    }

    public function getStatusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'in_consultation' => 'info',
            'called' => 'warning',
            'waiting' => 'neutral',
            'cancelled', 'no_show' => 'danger',
            default => 'neutral',
        };
    }

    public function getStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => __('Terminé'),
            'in_consultation' => __('En consultation'),
            'called' => __('Appelé'),
            'waiting' => __('En attente'),
            'cancelled' => __('Annulé'),
            'no_show' => __('Absent'),
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function getAppointmentStatusTone(string $status): string
    {
        return match ($status) {
            'confirmed' => 'info',
            'arrived' => 'warning',
            'completed' => 'success',
            'cancelled', 'no_show' => 'danger',
            default => 'neutral',
        };
    }

    public function getAppointmentStatusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => __('Confirmé'),
            'arrived' => __('Arrivé'),
            'completed' => __('Terminé'),
            'cancelled' => __('Annulé'),
            'no_show' => __('Absent'),
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function formatWaitTime(QueueTicket $ticket): string
    {
        if (! $ticket->created_at || ! $ticket->called_at) {
            return '—';
        }

        $minutes = (int) max(0, $ticket->created_at->diffInMinutes($ticket->called_at));

        return $minutes === 0 ? '< 1 min' : "{$minutes} min";
    }
}; ?>

<section class="flex flex-col gap-6">
    <flux:card class="rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Tableau de bord de la clinique') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Aperçu en temps réel des performances et de la file d\'attente aujourd\'hui.') }}</flux:text>
            </div>

            <div class="flex items-center gap-2">
                <span class="app-badge app-badge-neutral text-xs">
                    {{ now()->format('d/m/Y') }}
                </span>
            </div>
        </div>
    </flux:card>

    @if (auth()->user()->hasPermissionTo('dashboard.stats'))
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card class="flex flex-col justify-between rounded-2xl p-6">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium">{{ __('Total des tickets') }}</flux:text>
                    <div class="flex size-10 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <flux:icon icon="ticket" class="size-5" />
                    </div>
                </div>
                <div class="mt-4">
                    <flux:heading size="xl" class="text-2xl font-bold">{{ number_format($this->todayTickets) }}</flux:heading>
                    <flux:text class="mt-1 text-xs text-app-muted">{{ __('Tickets émis aujourd\'hui') }}</flux:text>
                </div>
            </flux:card>

            <flux:card class="flex flex-col justify-between rounded-2xl p-6">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium">{{ __('Tickets terminés') }}</flux:text>
                    <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                        <flux:icon icon="check-circle" class="size-5" />
                    </div>
                </div>
                <div class="mt-4">
                    <flux:heading size="xl" class="text-2xl font-bold">{{ number_format($this->todayCompleted) }}</flux:heading>
                    <flux:text class="mt-1 text-xs text-app-muted">{{ __('Consultations finalisées') }}</flux:text>
                </div>
            </flux:card>

            <flux:card class="flex flex-col justify-between rounded-2xl p-6">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium">{{ __('Temps d\'attente moyen') }}</flux:text>
                    <div class="flex size-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">
                        <flux:icon icon="clock" class="size-5" />
                    </div>
                </div>
                <div class="mt-4">
                    <flux:heading size="xl" class="text-2xl font-bold">{{ $this->avgWaitTime }} min</flux:heading>
                    <flux:text class="mt-1 text-xs text-app-muted">{{ __('Temps moyen avant appel') }}</flux:text>
                </div>
            </flux:card>

            <flux:card class="flex flex-col justify-between rounded-2xl p-6">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium">{{ __('Sans RDV / Avec RDV') }}</flux:text>
                    <div class="flex size-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-400">
                        <flux:icon icon="user-group" class="size-5" />
                    </div>
                </div>
                <div class="mt-4">
                    <flux:heading size="xl" class="text-2xl font-bold">
                        {{ $this->walkInsVsAppointments['walk_ins'] }} <span class="text-base font-normal text-app-muted">/</span> {{ $this->walkInsVsAppointments['appointments'] }}
                    </flux:heading>
                    <flux:text class="mt-1 text-xs text-app-muted">
                        {{ __(':walkins sans RDV • :appts avec RDV', ['walkins' => $this->walkInsVsAppointments['walk_ins'], 'appts' => $this->walkInsVsAppointments['appointments']]) }}
                    </flux:text>
                </div>
            </flux:card>
        </div>
    @endif

    @if (auth()->user()->hasPermissionTo('dashboard.queue'))
        <flux:card class="space-y-4 overflow-hidden rounded-2xl p-6">
            <div>
                <flux:heading size="lg">{{ __('Derniers tickets appelés aujourd\'hui') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Les 5 derniers tickets appelés avec leur temps d\'attente.') }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Code ticket') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('Statut') }}</flux:table.column>
                    <flux:table.column>{{ __('Temps d\'attente') }}</flux:table.column>
                    <flux:table.column>{{ __('Appelé à') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->recentCalledTickets as $ticket)
                        <flux:table.row :key="$ticket->id">
                            <flux:table.cell variant="strong">
                                <span class="font-mono font-medium">{{ $ticket->ticket_code }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $ticket->patient?->name ?? __('Patient anonyme') }}
                            </flux:table.cell>

                            <flux:table.cell class="text-app-muted">
                                {{ $ticket->service?->name ?? '—' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                <span class="app-badge app-badge-{{ $this->getStatusTone($ticket->status) }}">
                                    {{ $this->getStatusLabel($ticket->status) }}
                                </span>
                            </flux:table.cell>

                            <flux:table.cell class="text-app-muted">
                                {{ $this->formatWaitTime($ticket) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-app-muted">
                                {{ $ticket->called_at?->format('H:i') ?? '—' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center text-app-muted">
                                {{ __('Aucun ticket appelé aujourd\'hui.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    @if (auth()->user()->hasPermissionTo('dashboard.appointments'))
        <flux:card class="space-y-4 overflow-hidden rounded-2xl p-6">
            <div>
                <flux:heading size="lg">{{ __('Rendez-vous d\'aujourd\'hui') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Liste des rendez-vous programmés pour aujourd\'hui.') }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Heure') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('Praticien') }}</flux:table.column>
                    <flux:table.column>{{ __('Statut') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->todayAppointments as $appointment)
                        <flux:table.row :key="$appointment->id">
                            <flux:table.cell variant="strong">
                                <span class="font-mono font-medium">{{ $appointment->scheduled_at->format('H:i') }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $appointment->patient?->name ?? __('Patient anonyme') }}
                            </flux:table.cell>

                            <flux:table.cell class="text-app-muted">
                                {{ $appointment->service?->name ?? '—' }}
                            </flux:table.cell>

                            <flux:table.cell class="text-app-muted">
                                {{ $appointment->provider?->name ?? '—' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                <span class="app-badge app-badge-{{ $this->getAppointmentStatusTone($appointment->status) }}">
                                    {{ $this->getAppointmentStatusLabel($appointment->status) }}
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="py-8 text-center text-app-muted">
                                {{ __('Aucun rendez-vous programmé pour aujourd\'hui.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    @if (! auth()->user()->hasPermissionTo('dashboard.stats') && ! auth()->user()->hasPermissionTo('dashboard.queue') && ! auth()->user()->hasPermissionTo('dashboard.appointments'))
        <flux:card class="rounded-2xl p-8 sm:p-12 text-center">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="squares-2x2" class="size-7" />
            </div>

            <flux:heading size="lg" class="mt-4">
                {{ __("Vous n'avez pas accès aux widgets du tableau de bord.") }}
            </flux:heading>

            <flux:text class="mt-2 text-sm text-app-muted max-w-md mx-auto">
                {{ __('Vos permissions actuelles ne vous permettent pas de consulter les statistiques, la file d\'attente ou les rendez-vous sur cette page.') }}
            </flux:text>

            <div class="mt-6 flex justify-center">
                <flux:button
                    variant="primary"
                    icon="queue-list"
                    :href="url('/' . $this->clinic->slug . '/queue')"
                    wire:navigate
                >
                    {{ __('Accéder à la file d\'attente') }}
                </flux:button>
            </div>
        </flux:card>
    @endif
</section>
