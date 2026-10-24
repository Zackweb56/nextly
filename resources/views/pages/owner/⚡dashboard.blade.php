<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Owner Dashboard')] class extends Component {
    #[Computed]
    public function totalClinics(): int
    {
        return Clinic::query()->count();
    }

    #[Computed]
    public function activeSubscriptions(): int
    {
        return Subscription::withoutGlobalScope('clinic')
            ->where('status', 'active')
            ->count();
    }

    #[Computed]
    public function totalRevenue(): string
    {
        $sum = Payment::withoutGlobalScope('clinic')
            ->where('status', 'paid')
            ->sum('amount');

        return number_format((float) $sum, 2);
    }

    /**
     * @return Collection<int, Clinic>
     */
    #[Computed]
    public function recentClinics(): Collection
    {
        return Clinic::query()
            ->latest()
            ->limit(5)
            ->get();
    }

    /**
     * @return array<int, array{name: string, city: string, status: array{label: string, tone: string}, created_at: string|null}>
     */
    #[Computed]
    public function recentClinicsTableRows(): array
    {
        return $this->recentClinics
            ->map(fn (Clinic $clinic): array => [
                'name' => $clinic->name,
                'city' => $clinic->city ?: '—',
                'status' => [
                    'label' => $clinic->is_active ? __('Active') : __('Inactive'),
                    'tone' => $clinic->is_active ? 'success' : 'danger',
                ],
                'created_at' => $clinic->created_at?->format('M j, Y'),
            ])
            ->all();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Owner Dashboard') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Overview of clinics, subscriptions, and revenue across the platform.') }}</flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <flux:card class="p-6">
            <flux:text>{{ __('Total Clinics') }}</flux:text>
            <flux:heading size="xl" class="mt-2">{{ number_format($this->totalClinics) }}</flux:heading>
        </flux:card>

        <flux:card class="p-6">
            <flux:text>{{ __('Active Subscriptions') }}</flux:text>
            <flux:heading size="xl" class="mt-2">{{ number_format($this->activeSubscriptions) }}</flux:heading>
        </flux:card>

        <flux:card class="p-6">
            <flux:text>{{ __('Total Revenue') }}</flux:text>
            <flux:heading size="xl" class="mt-2">{{ $this->totalRevenue }} MAD</flux:heading>
        </flux:card>
    </div>

    <flux:card>
        <div class="p-6 pb-0">
            <flux:heading size="lg">{{ __('Recent Clinics') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The five most recently created clinics.') }}</flux:text>
        </div>

        <div class="p-6 pt-4">
            <x-data-table
                id="owner-recent-clinics-table"
                :columns="[
                    ['data' => 'name', 'title' => __('Name')],
                    ['data' => 'city', 'title' => __('City')],
                    ['data' => 'status', 'title' => __('Status'), 'render' => 'badge', 'orderable' => false, 'searchable' => false],
                    ['data' => 'created_at', 'title' => __('Created')],
                ]"
                :rows="$this->recentClinicsTableRows"
                :order="[3, 'desc']"
            />
        </div>
    </flux:card>
</div>
