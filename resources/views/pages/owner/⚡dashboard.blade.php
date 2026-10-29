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

    <flux:card class="space-y-4 rounded-2xl p-6">
        <div>
            <flux:heading size="lg">{{ __('Recent Clinics') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The five most recently created clinics.') }}</flux:text>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('City') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Created') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->recentClinics as $clinic)
                    <flux:table.row :key="$clinic->id">
                        <flux:table.cell variant="strong">
                            <a href="{{ route('owner.clinics.show', $clinic) }}" class="underline underline-offset-4 hover:text-accent-content" wire:navigate>
                                {{ $clinic->name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $clinic->city ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $clinic->is_active ? 'success' : 'danger' }}">
                                {{ $clinic->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $clinic->created_at?->format('M j, Y') ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center text-sm text-app-muted py-6">
                            {{ __('No records found') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
