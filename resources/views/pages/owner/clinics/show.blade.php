<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Clinic Billing')] class extends Component {
    #[Locked]
    public int $clinicId;

    public ?int $selectedPlanId = null;
    public string $paymentAmount = '';
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';
    public string $paymentNotes = '';

    public function mount(Clinic $clinic): void
    {
        $this->clinicId = $clinic->id;
        $subscription = $this->currentSubscription;
        $this->selectedPlanId = $subscription?->plan_id;
        $this->paymentAmount = $subscription?->plan?->price ?? '';
    }

    #[Computed]
    public function clinic(): Clinic
    {
        return Clinic::query()->findOrFail($this->clinicId);
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function currentSubscription(): ?Subscription
    {
        return Subscription::withoutGlobalScope('clinic')
            ->with('plan')
            ->where('clinic_id', $this->clinicId)
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return Payment::withoutGlobalScope('clinic')
            ->where('clinic_id', $this->clinicId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function updatedSelectedPlanId(?int $planId): void
    {
        $plan = $planId === null
            ? null
            : Plan::query()->where('is_active', true)->find($planId);

        if ($plan) {
            $this->paymentAmount = $plan->price;
        }
    }

    public function assignPlan(): void
    {
        $this->ensurePlatformOwner();

        $validated = $this->validate([
            'selectedPlanId' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ]);

        $clinic = Clinic::query()->findOrFail($this->clinicId);
        $plan = Plan::query()->where('is_active', true)->findOrFail($validated['selectedPlanId']);
        $now = now();
        $endsAt = $plan->billing_interval === 'yearly'
            ? $now->copy()->addYear()
            : $now->copy()->addMonth();

        DB::transaction(function () use ($clinic, $plan, $now, $endsAt): void {
            $subscription = Subscription::withoutGlobalScope('clinic')
                ->where('clinic_id', $clinic->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($subscription) {
                $subscription->plan_id = $plan->id;
                $subscription->status = 'active';
                $subscription->starts_at = $now;
                $subscription->ends_at = $endsAt;
                $subscription->trial_ends_at = null;
                $subscription->cancelled_at = null;
                $subscription->save();

                return;
            }

            Subscription::withoutGlobalScope('clinic')->create([
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => $endsAt,
            ]);
        });

        $this->paymentAmount = $plan->price;
        unset($this->currentSubscription);

        Flux::toast(variant: 'success', text: __('Plan assigned and subscription activated.'));
    }

    public function recordPayment(): void
    {
        $this->ensurePlatformOwner();

        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'paymentMethod' => ['required', Rule::in(['cash', 'bank_transfer'])],
            'paymentReference' => ['nullable', 'string', 'max:255'],
            'paymentNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $clinic = Clinic::query()->findOrFail($this->clinicId);

        DB::transaction(function () use ($clinic, $validated): void {
            $subscription = Subscription::withoutGlobalScope('clinic')
                ->where('clinic_id', $clinic->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'selectedPlanId' => __('Assign a plan before recording a payment.'),
                ]);
            }

            Payment::withoutGlobalScope('clinic')->create([
                'clinic_id' => $clinic->id,
                'subscription_id' => $subscription->id,
                'amount' => $validated['paymentAmount'],
                'currency' => 'MAD',
                'status' => 'paid',
                'method' => $validated['paymentMethod'],
                'reference' => $validated['paymentReference'] ?: null,
                'notes' => $validated['paymentNotes'] ?: null,
                'recorded_by' => Auth::id(),
                'paid_at' => now(),
            ]);

            if ($subscription->status === 'past_due') {
                $end = $subscription->ends_at?->isFuture()
                    ? $subscription->ends_at->copy()
                    : now();

                $subscription->status = 'active';
                $subscription->ends_at = $end->addMonth();
                $subscription->save();
            }
        });

        $this->reset(['paymentReference', 'paymentNotes']);
        $this->paymentMethod = 'cash';
        unset($this->currentSubscription);
        unset($this->payments);

        Flux::toast(variant: 'success', text: __('Payment recorded successfully.'));
    }

    private function ensurePlatformOwner(): void
    {
        abort_unless(Auth::user()?->is_platform_owner, 403);
    }
}; ?>

<section class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $this->clinic->name }}</flux:heading>
            <flux:text class="mt-1">{{ __('Clinic details and subscription billing.') }}</flux:text>
        </div>

        <flux:button :href="route('owner.clinics.index')" wire:navigate variant="ghost">
            {{ __('Back to Clinics') }}
        </flux:button>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Clinic Details') }}</flux:heading>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-app-muted">{{ __('Slug') }}</dt>
                    <dd class="font-medium">{{ $this->clinic->slug }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-app-muted">{{ __('Status') }}</dt>
                    <dd>
                        <!-- <flux:badge :color="$this->clinic->is_active ? 'green' : 'red'">
                            {{ $this->clinic->is_active ? __('Active') : __('Suspended') }}
                        </flux:badge> -->
                        <span class="app-badge app-badge-{{ $this->clinic->is_active ? 'success' : 'danger' }}">
                            {{ $this->clinic->is_active ? __('Active') : __('Suspended') }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-app-muted">{{ __('City') }}</dt>
                    <dd class="font-medium">{{ $this->clinic->city ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-app-muted">{{ __('Phone') }}</dt>
                    <dd class="font-medium">{{ $this->clinic->phone ?: '—' }}</dd>
                </div>
            </dl>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Current Subscription') }}</flux:heading>
            @if ($this->currentSubscription)
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-app-muted">{{ __('Plan') }}</dt>
                        <dd class="font-medium">{{ $this->currentSubscription->plan->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-app-muted">{{ __('Status') }}</dt>
                        <dd>
                            <!-- <flux:badge :color="$this->currentSubscription->status === 'active' ? 'green' : 'amber'">
                                {{ __(str_replace('_', ' ', ucfirst($this->currentSubscription->status))) }}
                            </flux:badge> -->
                            <span class="app-badge app-badge-{{ $this->currentSubscription->status === 'active' ? 'success' : 'warning' }}">
                                {{ __(str_replace('_', ' ', ucfirst($this->currentSubscription->status))) }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-app-muted">{{ __('Ends At') }}</dt>
                        <dd class="font-medium">{{ $this->currentSubscription->ends_at?->format('M j, Y') ?? __('No end date') }}</dd>
                    </div>
                </dl>
            @else
                <flux:text>{{ __('This clinic does not have a subscription yet.') }}</flux:text>
            @endif
        </flux:card>
    </div>

    <flux:card>
        <form wire:submit="assignPlan" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Assign a Plan') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Assign or change this clinic’s subscription plan.') }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Plan') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                <flux:select wire:model.live="selectedPlanId" required>
                    <flux:select.option value="">{{ __('Select a plan') }}</flux:select.option>
                    @foreach ($this->plans as $plan)
                        <flux:select.option :value="$plan->id">
                            {{ $plan->name }} — {{ $plan->price }} MAD / {{ $plan->billing_interval }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="selectedPlanId" />
            </flux:field>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                {{ __('Assign Plan') }}
            </flux:button>
        </form>
    </flux:card>

    <flux:card>
        <form wire:submit="recordPayment" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Record Payment') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Record a cash or bank transfer payment for this clinic.') }}</flux:text>
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

            <div class="grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Amount (MAD)') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:input wire:model="paymentAmount" type="number" min="0.01" step="0.01" required />
                    <flux:error name="paymentAmount" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Method') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                    <flux:select wire:model="paymentMethod" required>
                        <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
                        <flux:select.option value="bank_transfer">{{ __('Bank Transfer') }}</flux:select.option>
                    </flux:select>
                    <flux:error name="paymentMethod" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Reference') }}</flux:label>
                    <flux:input wire:model="paymentReference" />
                    <flux:error name="paymentReference" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="paymentNotes" rows="3" />
                    <flux:error name="paymentNotes" />
                </flux:field>
            </div>

            <flux:button type="submit" variant="primary" :disabled="! $this->currentSubscription" wire:loading.attr="disabled">
                {{ __('Record Payment') }}
            </flux:button>
        </form>
    </flux:card>

    <flux:card class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('Payment History') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Showing the latest 10 payments for this clinic.') }}</flux:text>
        </div>

        @if ($this->payments->isEmpty())
            <flux:text>{{ __('No payments have been recorded for this clinic.') }}</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-app-border text-app-muted">
                            <th class="px-3 py-2 font-medium">{{ __('Amount') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Method') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Status') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Reference') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('Paid At') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->payments as $payment)
                            <tr wire:key="clinic-payment-{{ $payment->id }}" class="border-b border-app-border last:border-0">
                                <td class="px-3 py-3 font-medium">{{ $payment->amount }} {{ $payment->currency }}</td>
                                <td class="px-3 py-3">{{ $payment->method === 'bank_transfer' ? __('Bank Transfer') : __(ucfirst($payment->method)) }}</td>
                                <td class="px-3 py-3">
                                    <!-- <flux:badge :color="$payment->status === 'paid' ? 'green' : 'amber'">
                                        {{ __(str_replace('_', ' ', ucfirst($payment->status))) }}
                                    </flux:badge> -->
                                    <span class="app-badge app-badge-{{ $payment->status === 'paid' ? 'success' : 'warning' }}">
                                        {{ __(str_replace('_', ' ', ucfirst($payment->status))) }}
                                    </span>
                                </td>
                                <td class="px-3 py-3">{{ $payment->reference ?: '—' }}</td>
                                <td class="px-3 py-3">{{ $payment->paid_at?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </flux:card>
</section>
