<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Payments')] class extends Component
{
    use WithPagination;

    public string $statusFilter = 'all';

    public string $search = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    #[Locked]
    public ?int $paymentId = null;

    #[Locked]
    public ?int $deletingPaymentId = null;

    public string $paymentMethod = 'cash';

    public string $paymentReference = '';

    public string $paymentNotes = '';

    public ?int $createClinicId = null;

    public string $createPaymentAmount = '';

    public string $createPaymentNotes = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * @return Collection<int, Clinic>
     */
    #[Computed]
    public function clinics(): Collection
    {
        $latestSubscriptionIds = Subscription::withoutGlobalScope('clinic')
            ->selectRaw('MAX(id)')
            ->groupBy('clinic_id');

        $eligibleClinicIds = Subscription::withoutGlobalScope('clinic')
            ->whereIn('id', $latestSubscriptionIds)
            ->whereIn('status', ['active', 'past_due', 'cancelled'])
            ->pluck('clinic_id');

        return Clinic::query()
            ->whereIn('id', $eligibleClinicIds)
            ->orderBy('name')
            ->get();
    }

    public function updatedCreateClinicId(?int $value): void
    {
        if ($value === null) {
            $this->createPaymentAmount = '';

            return;
        }

        $subscription = Subscription::withoutGlobalScope('clinic')
            ->with('plan')
            ->where('clinic_id', $value)
            ->latest('id')
            ->first();

        $this->createPaymentAmount = (string) ($subscription?->plan?->price ?? '');
    }

    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        $query = Payment::withoutGlobalScope('clinic')
            ->with(['clinic', 'subscription.plan']);

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhereHas('clinic', fn ($cq) => $cq->where('name', 'like', $like));
            });
        }

        match ($this->sortBy) {
            'clinic' => $query->orderBy(
                Clinic::select('name')->whereColumn('clinics.id', 'payments.clinic_id'),
                $this->sortDirection
            ),
            'amount' => $query->orderBy('amount', $this->sortDirection),
            'created_at' => $query->orderBy('created_at', $this->sortDirection),
            'paid_at' => $query->orderBy('paid_at', $this->sortDirection),
            default => $query->orderBy('created_at', 'desc'),
        };

        return $query->paginate(10);
    }

    public function openCreatePayment(): void
    {
        $this->ensurePlatformOwner();
        $this->reset([
            'createClinicId',
            'createPaymentAmount',
            'createPaymentNotes',
        ]);
        $this->resetValidation();
        Flux::modal('create-payment')->show();
    }

    public function createPayment(): void
    {
        $this->ensurePlatformOwner();

        $validated = $this->validate([
            'createClinicId' => ['required', 'integer', Rule::exists('clinics', 'id')->whereNull('deleted_at')],
            'createPaymentAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'createPaymentNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $hasPendingPayment = Payment::withoutGlobalScope('clinic')
            ->where('clinic_id', $validated['createClinicId'])
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingPayment) {
            throw ValidationException::withMessages([
                'createClinicId' => __('This clinic already has a pending invoice. Please mark it as paid first.'),
            ]);
        }

        DB::transaction(function () use ($validated): void {
            $subscription = Subscription::withoutGlobalScope('clinic')
                ->with('plan')
                ->where('clinic_id', $validated['createClinicId'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'createClinicId' => __('Assign a plan to the selected clinic before recording a payment.'),
                ]);
            }

            $isYearly = $subscription->plan?->billing_interval === 'yearly';
            $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();
            $periodStartsAt = $base;
            $periodEndsAt = $isYearly ? $base->copy()->addYear() : $base->copy()->addMonth();

            Payment::withoutGlobalScope('clinic')->create([
                'clinic_id' => $validated['createClinicId'],
                'subscription_id' => $subscription->id,
                'amount' => $validated['createPaymentAmount'],
                'currency' => 'MAD',
                'status' => 'pending',
                'method' => null,
                'reference' => null,
                'notes' => $validated['createPaymentNotes'] ?: null,
                'recorded_by' => Auth::id(),
                'paid_at' => null,
                'period_starts_at' => $periodStartsAt,
                'period_ends_at' => $periodEndsAt,
            ]);
        });

        Flux::modal('create-payment')->close();
        Flux::toast(variant: 'success', text: __('Payment invoice created successfully.'));
        $this->reset([
            'createClinicId',
            'createPaymentAmount',
            'createPaymentNotes',
        ]);
        $this->dispatch('payments-updated');
    }

    public function openMarkAsPaid(int $paymentId): void
    {
        $this->ensurePlatformOwner();

        $payment = Payment::withoutGlobalScope('clinic')
            ->where('status', 'pending')
            ->findOrFail($paymentId);

        $this->paymentId = $payment->id;
        $this->paymentMethod = 'cash';
        $this->paymentReference = '';
        $this->paymentNotes = '';
        $this->resetValidation();

        Flux::modal('mark-payment-paid')->show();
    }

    #[On('payment-mark-requested')]
    public function handleMarkAsPaidRequest(int $paymentId = 0, int $id = 0): void
    {
        $targetId = $paymentId ?: $id;
        if ($targetId > 0) {
            $this->openMarkAsPaid($targetId);
        }
    }

    #[On('payment-delete-requested')]
    public function handlePaymentDeleteRequest(int $paymentId = 0, int $id = 0): void
    {
        $targetId = $paymentId ?: $id;
        if ($targetId > 0) {
            $this->openDeletePayment($targetId);
        }
    }

    public function openDeletePayment(int $paymentId): void
    {
        $this->ensurePlatformOwner();

        $payment = Payment::withoutGlobalScope('clinic')->findOrFail($paymentId);

        if ($payment->status === 'paid') {
            throw ValidationException::withMessages([
                'paymentId' => __('Paid payments cannot be deleted.'),
            ]);
        }

        $this->deletingPaymentId = $payment->id;
        Flux::modal('confirm-delete-payment')->show();
    }

    public function deletePayment(): void
    {
        $this->ensurePlatformOwner();

        if ($this->deletingPaymentId === null) {
            return;
        }

        $payment = Payment::withoutGlobalScope('clinic')
            ->whereIn('status', ['pending', 'failed'])
            ->findOrFail($this->deletingPaymentId);

        $payment->delete();

        $this->deletingPaymentId = null;
        Flux::modal('confirm-delete-payment')->close();
        Flux::toast(variant: 'success', text: __('Payment deleted successfully.'));
        $this->dispatch('payments-updated');
    }

    public function markAsPaid(): void
    {
        $this->ensurePlatformOwner();

        if ($this->paymentId === null) {
            throw ValidationException::withMessages([
                'paymentId' => __('Select a pending payment first.'),
            ]);
        }

        $validated = $this->validate([
            'paymentMethod' => ['required', Rule::in(['cash', 'bank_transfer'])],
            'paymentReference' => [
                Rule::requiredIf($this->paymentMethod === 'bank_transfer'),
                'nullable',
                'string',
                'max:255',
            ],
            'paymentNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($validated): void {
            $payment = Payment::withoutGlobalScope('clinic')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->findOrFail($this->paymentId);

            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'method' => $validated['paymentMethod'],
                'reference' => $validated['paymentReference'] ?: null,
                'notes' => $validated['paymentNotes'] ?: null,
                'recorded_by' => Auth::id(),
            ]);

            if ($payment->subscription_id === null) {
                return;
            }

            $subscription = Subscription::withoutGlobalScope('clinic')
                ->with('plan')
                ->where('clinic_id', $payment->clinic_id)
                ->lockForUpdate()
                ->find($payment->subscription_id);

            if (! $subscription) {
                return;
            }

            $isYearly = $subscription->plan?->billing_interval === 'yearly';

            if ($payment->period_ends_at !== null) {
                $newEndsAt = $payment->period_ends_at;
                $newStartsAt = $payment->period_starts_at ?? ($subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now());
            } elseif (in_array($subscription->status, ['trialing', 'past_due', 'cancelled'], true)) {
                // For non-active subscriptions, always base new ends_at from now()
                $newStartsAt = now();
                $newEndsAt = $isYearly ? now()->addYear() : now()->addMonth();
            } else {
                $newStartsAt = $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();
                $newEndsAt = $subscription->ends_at?->isFuture()
                    ? ($isYearly ? $subscription->ends_at->copy()->addYear() : $subscription->ends_at->copy()->addMonth())
                    : ($isYearly ? now()->addYear() : now()->addMonth());
            }

            if ($payment->period_starts_at === null) {
                $payment->period_starts_at = $newStartsAt;
                $payment->save();
            }
            if ($payment->period_ends_at === null) {
                $payment->period_ends_at = $newEndsAt;
                $payment->save();
            }

            if (in_array($subscription->status, ['trialing', 'past_due', 'cancelled'], true)) {
                $subscription->status = 'active';
                $subscription->trial_ends_at = null;
                $subscription->cancelled_at = null;
                if ($newStartsAt !== null) {
                    $subscription->starts_at = $newStartsAt;
                }
                $subscription->ends_at = $newEndsAt;
                $subscription->save();
            } elseif ($subscription->status === 'active') {
                if ($newEndsAt !== null && ($subscription->ends_at === null || $newEndsAt->gt($subscription->ends_at))) {
                    if ($newStartsAt !== null) {
                        $subscription->starts_at = $newStartsAt;
                    }
                    $subscription->ends_at = $newEndsAt;
                    $subscription->save();
                }
            }
        });

        Flux::modal('mark-payment-paid')->close();
        Flux::toast(variant: 'success', text: __('Payment marked as paid.'));
        $this->paymentId = null;
        $this->reset(['paymentMethod', 'paymentReference', 'paymentNotes']);
        $this->paymentMethod = 'cash';
        $this->dispatch('payments-updated');
    }

    private function ensurePlatformOwner(): void
    {
        abort_unless(Auth::user()?->is_platform_owner, 403);
    }
}; ?>

<section class="flex flex-col gap-6">
    <flux:card class="rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Payments') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Review and manage payments across all clinics.') }}</flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="openCreatePayment">
                {{ __('Create Payment') }}
            </flux:button>
        </div>
    </flux:card>

    <flux:card class="space-y-5 overflow-hidden rounded-2xl p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Global Payment History') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Filter and search payment records across the platform.') }}</flux:text>
            </div>

            <div class="flex w-full flex-wrap items-end justify-end gap-3 sm:w-auto">
                <flux:field class="w-full sm:w-44">
                    <flux:label>{{ __('Status') }}</flux:label>
                    <flux:select wire:model.live="statusFilter">
                        <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                        <flux:select.option value="pending">{{ __('Pending') }}</flux:select.option>
                        <flux:select.option value="paid">{{ __('Paid') }}</flux:select.option>
                    </flux:select>
                </flux:field>

                <div class="w-full sm:w-64">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search payments...') }}"
                        icon="magnifying-glass"
                        clearable
                    />
                </div>
            </div>
        </div>

        <flux:table :paginate="$this->payments">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'clinic'" :direction="$sortDirection" wire:click="sort('clinic')">
                    {{ __('Clinic') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')">
                    {{ __('Amount') }}
                </flux:table.column>
                <flux:table.column>
                    {{ __('Status') }}
                </flux:table.column>
                <flux:table.column>
                    {{ __('Billed Period') }} 
                </flux:table.column>
                <flux:table.column>
                    {{ __('Method') }}
                </flux:table.column>
                <flux:table.column>
                    {{ __('Reference') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">
                    {{ __('Created') }}
                </flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'paid_at'" :direction="$sortDirection" wire:click="sort('paid_at')">
                    {{ __('Paid At') }}
                </flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->payments as $payment)
                    @php
                        $statusTone = match ($payment->status) {
                            'paid' => 'success',
                            'pending' => 'warning',
                            'failed' => 'danger',
                            default => 'info',
                        };

                        $methodTone = match ($payment->method) {
                            'cash' => 'neutral',
                            'bank_transfer' => 'info',
                            default => 'neutral',
                        };
                    @endphp
                    <flux:table.row :key="$payment->id">
                        <flux:table.cell variant="strong">
                            @if ($payment->clinic)
                                <a href="{{ route('owner.clinics.show', $payment->clinic) }}" class="underline underline-offset-4 hover:text-accent-content" wire:navigate>
                                    {{ $payment->clinic->name }}
                                </a>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $payment->amount }} {{ $payment->currency }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $statusTone }}">
                                {{ __(str_replace('_', ' ', ucfirst($payment->status))) }}
                            </span>
                        </flux:table.cell>
                        {{-- NEW CELL --}}
                        <flux:table.cell>
                            @if ($payment->period_starts_at && $payment->period_ends_at)
                                {{ $payment->period_starts_at->format('M j, Y') }} - {{ $payment->period_ends_at->format('M j, Y') }}
                            @else
                                &mdash;
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($payment->method)
                                <span class="app-badge app-badge-{{ $methodTone }}">
                                    {{ $payment->method === 'bank_transfer' ? __('Bank Transfer') : __(ucfirst($payment->method)) }}
                                </span>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $payment->reference ?: '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $payment->created_at?->format('M j, Y g:i A') ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($payment->paid_at)
                                {{ $payment->paid_at->format('M j, Y g:i A') }}
                            @elseif ($payment->status === 'pending')
                                @php
                                    $subEnds = $payment->subscription?->ends_at;
                                    $diff = $subEnds ? (int) now()->startOfDay()->diffInDays($subEnds->copy()->startOfDay(), false) : null;
                                @endphp
                                @if ($diff !== null)
                                    @if ($diff >= 0)
                                        <span class="text-xs font-medium text-warning" title="{{ __('Due by :date', ['date' => $subEnds->format('M j, Y')]) }}">
                                            {{ $diff === 0 ? __('Due today') : __('Due in :days day(s)', ['days' => $diff]) }}
                                            <span class="block font-normal text-app-muted">{{ $subEnds->format('M j, Y') }}</span>
                                        </span>
                                    @else
                                        <span class="text-xs font-medium text-danger" title="{{ __('Overdue by :days day(s)', ['days' => abs($diff)]) }}">
                                            {{ __('Overdue · :days day(s) ago', ['days' => abs($diff)]) }}
                                            <span class="block font-normal text-app-muted">{{ $subEnds->format('M j, Y') }}</span>
                                        </span>
                                    @endif
                                @else
                                    —
                                @endif
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="py-0">
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"></flux:button>
                                <flux:menu>
                                    @if ($payment->status === 'pending')
                                        <flux:menu.item icon="check" wire:click="openMarkAsPaid({{ $payment->id }})">
                                            {{ __('Mark as Paid') }}
                                        </flux:menu.item>
                                    @endif
                                    @if ($payment->status !== 'paid')
                                        <flux:menu.item icon="trash" variant="danger" wire:click="openDeletePayment({{ $payment->id }})">
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="9" class="py-8 text-center text-sm text-app-muted">
                            {{ __('No payments found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="create-payment" class="w-full max-w-lg">
        <form wire:submit="createPayment" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create Payment') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Record a payment against a clinic subscription.') }}</flux:text>
            </div>

            @if ($errors->any())
                <flux:callout variant="danger" icon="x-circle" heading="{{ __('Please review the highlighted fields.') }}">
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>{{ __('Clinic') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                <flux:select wire:model.live="createClinicId" required>
                    <flux:select.option value="">{{ __('Select a clinic') }}</flux:select.option>
                    @foreach ($this->clinics as $clinic)
                        <flux:select.option :value="$clinic->id">{{ $clinic->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="createClinicId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Amount (MAD)') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                <flux:input wire:model="createPaymentAmount" type="number" min="0.01" step="0.01" required />
                <flux:error name="createPaymentAmount" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="createPaymentNotes" rows="3" />
                <flux:error name="createPaymentNotes" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    {{ __('Save Payment') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="mark-payment-paid" class="w-full max-w-lg">
        <form wire:submit="markAsPaid" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Mark Payment as Paid') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Confirm how this payment was received.') }}</flux:text>
            </div>

            @if ($errors->any())
                <flux:callout variant="danger" icon="x-circle" heading="{{ __('Please review the highlighted fields.') }}">
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>{{ __('Method') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                <flux:select wire:model="paymentMethod" required>
                    <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
                    <flux:select.option value="bank_transfer">{{ __('Bank Transfer') }}</flux:select.option>
                </flux:select>
                <flux:error name="paymentMethod" />
            </flux:field>

            <flux:field>
                <flux:label>
                    {{ __('Reference') }}@if ($paymentMethod === 'bank_transfer') <span class="text-danger" aria-hidden="true">*</span>@endif
                </flux:label>
                <flux:input wire:model="paymentReference" :required="$paymentMethod === 'bank_transfer'" />
                <flux:error name="paymentReference" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="paymentNotes" rows="3" />
                <flux:error name="paymentNotes" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    {{ __('Mark as Paid') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-delete-payment" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Payment') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete this payment record? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deletePayment">
                    {{ __('Delete Payment') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
