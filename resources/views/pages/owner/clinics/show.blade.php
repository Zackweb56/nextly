<?php

use App\Models\Clinic;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Clinic Billing')] class extends Component
{
    use WithPagination;

    #[Locked]
    public int $clinicId;

    public ?int $selectedPlanId = null;

    public bool $startAsTrial = false;

    #[Locked]
    public ?int $paymentId = null;

    #[Locked]
    public ?int $deletingPaymentId = null;

    public string $paymentAmount = '';

    public string $paymentMethod = 'cash';

    public string $paymentReference = '';

    public string $paymentNotes = '';

    public string $createPaymentAmount = '';

    public string $createPaymentNotes = '';

    public function mount(Clinic $clinic): void
    {
        $this->clinicId = $clinic->id;
        $subscription = $this->currentSubscription;
        $this->selectedPlanId = $subscription?->plan_id;
        $this->paymentAmount = $subscription?->plan?->price ?? '';
        $this->createPaymentAmount = (string) ($subscription?->plan?->price ?? '');
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
        $subscription = Subscription::withoutGlobalScope('clinic')
            ->with('plan')
            ->where('clinic_id', $this->clinicId)
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return null;
        }

        // Normalize expired status so the owner UI always shows the real state.
        // The middleware does this for clinic users, but owners bypass it.
        $isExpiredTrial = $subscription->status === 'trialing'
            && $subscription->trial_ends_at !== null
            && $subscription->trial_ends_at->isPast();

        $isExpiredActive = $subscription->status === 'active'
            && $subscription->ends_at !== null
            && $subscription->ends_at->isPast();

        if ($isExpiredTrial || $isExpiredActive) {
            $subscription->status = 'past_due';
            $subscription->save();
        }

        return $subscription;
    }

    // clinic payments history table
    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return Payment::withoutGlobalScope('clinic')
            ->where('clinic_id', $this->clinicId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /**
     * Returns the current billing period containing now() from the latest paid or pending payment.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    #[Computed]
    public function currentPeriod(): ?array
    {
        $subscription = $this->currentSubscription;

        if ($subscription === null) {
            return null;
        }

        // ── Trial ────────────────────────────────────────────────────────────
        if ($subscription->status === 'trialing') {
            $end = $subscription->trial_ends_at;

            if ($end === null) {
                return null;
            }

            $start = $subscription->starts_at
                ?? $end->copy()->subDays($subscription->plan?->trial_days ?? 0);

            return ['start' => $start, 'end' => $end];
        }

        $now = now();

        $payment = Payment::withoutGlobalScope('clinic')
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', ['paid', 'pending'])
            ->where('period_starts_at', '<=', $now)
            ->where('period_ends_at', '>=', $now)
            ->latest('id')
            ->first();

        if ($payment !== null && $payment->period_starts_at !== null && $payment->period_ends_at !== null) {
            return ['start' => $payment->period_starts_at, 'end' => $payment->period_ends_at];
        }

        if (in_array($subscription->status, ['active', 'cancelled'], true) && $subscription->starts_at !== null && $subscription->ends_at !== null && $subscription->ends_at->isFuture()) {
            return ['start' => $subscription->starts_at, 'end' => $subscription->ends_at];
        }

        return null;
    }

    /**
     * Returns the next prepaid billing period, if the clinic has paid ahead.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    #[Computed]
    public function nextPeriod(): ?array
    {
        $subscription = $this->currentSubscription;

        if ($subscription === null || $subscription->status !== 'active') {
            return null;
        }

        $now = now();

        $nextPayment = Payment::withoutGlobalScope('clinic')
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->where('period_starts_at', '>', $now)
            ->orderBy('period_starts_at')
            ->first();

        if ($nextPayment === null || $nextPayment->period_starts_at === null || $nextPayment->period_ends_at === null) {
            return null;
        }

        return ['start' => $nextPayment->period_starts_at, 'end' => $nextPayment->period_ends_at];
    }

    #[Computed]
    public function currentPeriodPaymentStatus(): string
    {
        $subscription = $this->currentSubscription;

        if ($subscription === null || $subscription->status === 'trialing') {
            return 'unknown';
        }

        $now = now();

        $payment = Payment::withoutGlobalScope('clinic')
            ->where('subscription_id', $subscription->id)
            ->where('period_starts_at', '<=', $now)
            ->where('period_ends_at', '>=', $now)
            ->latest('id')
            ->first();

        if ($payment === null) {
            return 'unpaid';
        }

        return in_array($payment->status, ['paid', 'pending'], true) ? $payment->status : 'unpaid';
    }


    public function updatedSelectedPlanId(?int $planId): void
    {
        $plan = $planId === null
            ? null
            : Plan::query()->where('is_active', true)->find($planId);

        if ($plan) {
            $this->paymentAmount = $plan->price;
            $this->createPaymentAmount = $plan->price;
        }
    }

    #[Computed]
    public function isLocked(): bool
    {
        $subscription = $this->currentSubscription;

        if (! $subscription) {
            return false;
        }

        if ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isFuture() === true) {
            return true;
        }

        $now = now();

        return Payment::withoutGlobalScope('clinic')
            ->where('clinic_id', $this->clinicId)
            ->whereIn('status', ['paid', 'pending'])
            ->where('period_starts_at', '<=', $now)
            ->where('period_ends_at', '>=', $now)
            ->exists();
    }

    public function assignPlan(): void
    {
        $this->ensurePlatformOwner();

        if ($this->isLocked) {
            throw ValidationException::withMessages([
                'selectedPlanId' => __('Plan is locked until the current billing cycle ends or is cancelled.'),
            ]);
        }

        $validated = $this->validate([
            'selectedPlanId' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'startAsTrial' => ['boolean'],
        ]);

        $clinic = Clinic::query()->findOrFail($this->clinicId);
        $plan = Plan::query()->where('is_active', true)->findOrFail($validated['selectedPlanId']);
        $now = now();

        if ($this->startAsTrial) {
            if ($plan->trial_days === null || $plan->trial_days <= 0) {
                throw ValidationException::withMessages([
                    'startAsTrial' => __('The selected plan does not have trial days.'),
                ]);
            }

            $trialEndsAt = $now->copy()->addDays($plan->trial_days);
            $endsAt = $trialEndsAt;
            $status = 'trialing';
        } else {
            $trialEndsAt = null;
            $endsAt = $plan->billing_interval === 'yearly'
                ? $now->copy()->addYear()
                : $now->copy()->addMonth();
            $status = 'active';
        }

        $isTrial = $this->startAsTrial;

        DB::transaction(function () use ($clinic, $plan, $now, $endsAt, $trialEndsAt, $status, $isTrial): void {
            if ($isTrial) {
                Payment::withoutGlobalScope('clinic')
                    ->where('clinic_id', $clinic->id)
                    ->where('status', 'pending')
                    ->delete();
            }

            $subscription = Subscription::withoutGlobalScope('clinic')
                ->where('clinic_id', $clinic->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($subscription) {
                $subscription->plan_id = $plan->id;
                $subscription->status = $status;
                $subscription->starts_at = $now;
                $subscription->ends_at = $endsAt;
                $subscription->trial_ends_at = $trialEndsAt;
                $subscription->cancelled_at = null;
                $subscription->save();
            } else {
                $subscription = Subscription::withoutGlobalScope('clinic')->create([
                    'clinic_id' => $clinic->id,
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'starts_at' => $now,
                    'ends_at' => $endsAt,
                    'trial_ends_at' => $trialEndsAt,
                ]);
            }

            if (! $isTrial) {
                $periodStartsAt = $now;
                $periodEndsAt = $endsAt;

                $pendingPayment = Payment::withoutGlobalScope('clinic')
                    ->where('clinic_id', $clinic->id)
                    ->where('status', 'pending')
                    ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
                    ->latest('id')
                    ->first();

                if ($pendingPayment) {
                    $pendingPayment->amount = $plan->price;
                    $pendingPayment->subscription_id = $subscription->id;
                    $pendingPayment->period_starts_at = $periodStartsAt;
                    $pendingPayment->period_ends_at = $periodEndsAt;
                    $pendingPayment->created_at = $now;
                    $pendingPayment->save();
                } else {
                    Payment::withoutGlobalScope('clinic')->create([
                        'clinic_id' => $clinic->id,
                        'subscription_id' => $subscription->id,
                        'amount' => $plan->price,
                        'currency' => 'MAD',
                        'status' => 'pending',
                        'method' => null,
                        'reference' => null,
                        'recorded_by' => Auth::id(),
                        'created_at' => $now,
                        'period_starts_at' => $periodStartsAt,
                        'period_ends_at' => $periodEndsAt,
                    ]);
                }
            }
        });

        $this->paymentAmount = $plan->price;
        $this->createPaymentAmount = $plan->price;
        $this->startAsTrial = false;
        unset($this->currentSubscription);
        unset($this->currentPeriod);
        unset($this->nextPeriod);
        unset($this->currentPeriodPaymentStatus);
        unset($this->isLocked);
        unset($this->payments);

        Flux::toast(
            variant: 'success',
            text: $isTrial
                ? __('Plan assigned with trial.')
                : __('Plan assigned and pending payment created.')
        );
        $this->dispatch('payments-updated');
    }

    public function cancelSubscription(): void
    {
        $this->ensurePlatformOwner();

        $clinic = Clinic::query()->findOrFail($this->clinicId);

        DB::transaction(function () use ($clinic): void {
            $subscription = Subscription::withoutGlobalScope('clinic')
                ->where('clinic_id', $clinic->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return;
            }

            $now = now();
            $subscription->status = 'cancelled';
            $subscription->cancelled_at = $now;

            $hasPaidCurrentPeriod = Payment::withoutGlobalScope('clinic')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'paid')
                ->where('period_starts_at', '<=', $now)
                ->where('period_ends_at', '>=', $now)
                ->exists();

            if ($hasPaidCurrentPeriod) {
                $subscription->save();
            } else {
                $subscription->ends_at = $now;
                $subscription->save();

                Payment::withoutGlobalScope('clinic')
                    ->where('clinic_id', $clinic->id)
                    ->where('subscription_id', $subscription->id)
                    ->where('status', 'pending')
                    ->delete();
            }
        });

        unset($this->currentSubscription);
        unset($this->currentPeriod);
        unset($this->nextPeriod);
        unset($this->currentPeriodPaymentStatus);
        unset($this->isLocked);
        unset($this->payments);

        Flux::modal('confirm-cancel-subscription')->close();
        Flux::toast(variant: 'success', text: __('Subscription cancelled successfully.'));
        $this->dispatch('payments-updated');
    }

    public function toggleClinicStatus(): void
    {
        $this->ensurePlatformOwner();

        $clinic = $this->clinic;
        $clinic->is_active = ! $clinic->is_active;
        $clinic->save();

        Flux::modal('confirm-clinic-status')->close();
        Flux::toast(
            variant: 'success',
            text: $clinic->is_active
                ? __('Clinic activated successfully.')
                : __('Clinic suspended successfully.')
        );
        $this->dispatch('clinics-updated');
    }

    public function openCreatePayment(): void
    {
        $this->ensurePlatformOwner();

        $subscription = $this->currentSubscription;

        if (! $subscription) {
            Flux::toast(
                variant: 'danger',
                text: __('Assign a plan before recording a payment.')
            );

            throw ValidationException::withMessages([
                'createPaymentAmount' => __('Assign a plan before recording a payment.'),
            ]);
        }

        if ($subscription->status === 'trialing') {
            Flux::toast(
                variant: 'danger',
                text: __('Cannot create payment for a trialing clinic.')
            );

            throw ValidationException::withMessages([
                'createPaymentAmount' => __('Cannot create payment for a trialing clinic.'),
            ]);
        }

        $hasPendingPayment = Payment::withoutGlobalScope('clinic')
            ->where('clinic_id', $this->clinicId)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingPayment) {
            Flux::toast(
                variant: 'danger',
                text: __('This clinic already has a pending invoice. Please mark it as paid first.')
            );

            throw ValidationException::withMessages([
                'createPaymentAmount' => __('This clinic already has a pending invoice. Please mark it as paid first.'),
            ]);
        }

        $this->createPaymentAmount = (string) ($subscription->plan?->price ?? '');
        $this->createPaymentNotes = '';
        $this->resetValidation();

        Flux::modal('create-payment')->show();
    }

    public function createPayment(): void
    {
        $this->ensurePlatformOwner();

        $subscription = Subscription::withoutGlobalScope('clinic')
            ->with('plan')
            ->where('clinic_id', $this->clinicId)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $subscription) {
            Flux::toast(
                variant: 'danger',
                text: __('Assign a plan before recording a payment.')
            );

            throw ValidationException::withMessages([
                'createPaymentAmount' => __('Assign a plan before recording a payment.'),
            ]);
        }

        if ($subscription->status === 'trialing') {
            Flux::toast(
                variant: 'danger',
                text: __('Cannot create payment for a trialing clinic.')
            );

            throw ValidationException::withMessages([
                'createPaymentAmount' => __('Cannot create payment for a trialing clinic.'),
            ]);
        }

        $hasPendingPayment = Payment::withoutGlobalScope('clinic')
            ->where('clinic_id', $this->clinicId)
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingPayment) {
            Flux::toast(
                variant: 'danger',
                text: __('This clinic already has a pending invoice. Please mark it as paid first.')
            );

            throw ValidationException::withMessages([
                'createPaymentAmount' => __('This clinic already has a pending invoice. Please mark it as paid first.'),
            ]);
        }

        $validated = $this->validate([
            'createPaymentAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'createPaymentNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($subscription, $validated): void {
            $isYearly = $subscription->plan?->billing_interval === 'yearly';
            $base = ($subscription->status === 'past_due' || $subscription->ends_at === null || $subscription->ends_at->isPast())
                ? now()
                : $subscription->ends_at->copy();
            $periodStartsAt = $base;
            $periodEndsAt = $isYearly ? $base->copy()->addYear() : $base->copy()->addMonth();

            Payment::withoutGlobalScope('clinic')->create([
                'clinic_id' => $this->clinicId,
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
            'createPaymentAmount',
            'createPaymentNotes',
        ]);
        unset($this->payments);
        unset($this->isLocked);
        unset($this->currentPeriod);
        unset($this->currentPeriodPaymentStatus);
        $this->dispatch('payments-updated');
    }

    public function recordPayment(): void
    {
        $this->ensurePlatformOwner();

        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'paymentMethod' => ['required', Rule::in(['cash', 'bank_transfer'])],
            'paymentReference' => [
                Rule::requiredIf($this->paymentMethod === 'bank_transfer'),
                'nullable',
                'string',
                'max:255',
            ],
            'paymentNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $clinic = Clinic::query()->findOrFail($this->clinicId);

        DB::transaction(function () use ($clinic, $validated): void {
            $subscription = Subscription::withoutGlobalScope('clinic')
                ->with('plan')
                ->where('clinic_id', $clinic->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'selectedPlanId' => __('Assign a plan before recording a payment.'),
                ]);
            }

            $pendingPayment = Payment::withoutGlobalScope('clinic')
                ->where('clinic_id', $clinic->id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            $isYearly = $subscription->plan?->billing_interval === 'yearly';

            if ($pendingPayment) {
                $payment = $pendingPayment;
                if ($payment->period_starts_at === null || $payment->period_ends_at === null) {
                    $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();
                    $payment->period_starts_at = $base;
                    $payment->period_ends_at = $isYearly ? $base->copy()->addYear() : $base->copy()->addMonth();
                }

                $payment->update([
                    'amount' => $validated['paymentAmount'],
                    'status' => 'paid',
                    'method' => $validated['paymentMethod'],
                    'reference' => $validated['paymentReference'] ?: null,
                    'notes' => $validated['paymentNotes'] ?: null,
                    'recorded_by' => Auth::id(),
                    'paid_at' => now(),
                    'period_starts_at' => $payment->period_starts_at,
                    'period_ends_at' => $payment->period_ends_at,
                ]);
            } else {
                if (in_array($subscription->status, ['trialing', 'past_due', 'cancelled'], true) || $subscription->ends_at === null || $subscription->ends_at->isPast()) {
                    $periodStartsAt = now();
                    $periodEndsAt = $isYearly ? now()->addYear() : now()->addMonth();
                } else {
                    $periodStartsAt = $subscription->ends_at->copy();
                    $periodEndsAt = $isYearly ? $subscription->ends_at->copy()->addYear() : $subscription->ends_at->copy()->addMonth();
                }

                $payment = Payment::withoutGlobalScope('clinic')->create([
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
                    'period_starts_at' => $periodStartsAt,
                    'period_ends_at' => $periodEndsAt,
                ]);
            }

            $newStartsAt = $payment->period_starts_at;
            $newEndsAt = $payment->period_ends_at
                ?? ($isYearly ? ($subscription->ends_at?->isFuture() ? $subscription->ends_at->copy()->addYear() : now()->addYear()) : ($subscription->ends_at?->isFuture() ? $subscription->ends_at->copy()->addMonth() : now()->addMonth()));

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

        $this->reset(['paymentReference', 'paymentNotes']);
        $this->paymentMethod = 'cash';
        unset($this->currentSubscription);
        unset($this->currentPeriod);
        unset($this->nextPeriod);
        unset($this->currentPeriodPaymentStatus);
        unset($this->payments);

        Flux::toast(variant: 'success', text: __('Payment recorded successfully.'));
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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ $this->clinic->name }}</flux:heading>
                <flux:text class="mt-1">{{ __('Clinic details and subscription billing.') }}</flux:text>
            </div>

            <flux:button :href="route('owner.clinics.index')" wire:navigate variant="ghost" icon="arrow-left">
                {{ __('Back to Clinics') }}
            </flux:button>
        </div>
    </flux:card>

    <div class="grid gap-6 lg:grid-cols-12">
        <flux:card class="space-y-4 rounded-2xl lg:col-span-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Clinic Details') }}</flux:heading>
                <flux:modal.trigger name="confirm-clinic-status">
                    @if ($this->clinic->is_active)
                        <flux:button size="xs" variant="filled" color="red" icon="no-symbol">
                            {{ __('Suspend Clinic') }}
                        </flux:button>
                    @else
                        <flux:button size="xs" variant="filled" color="green" icon="play-circle">
                            {{ __('Activate Clinic') }}
                        </flux:button>
                    @endif
                </flux:modal.trigger>
            </div>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-app-muted">{{ __('Slug') }}</dt>
                    <dd class="font-medium">{{ $this->clinic->slug }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-app-muted">{{ __('Status') }}</dt>
                    <dd>
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

        <flux:card class="space-y-4 rounded-2xl lg:col-span-8">
            <flux:heading size="lg">{{ __('Current Subscription') }}</flux:heading>

            @if ($this->currentSubscription)
                <dl class="grid gap-4 sm:grid-cols-2">
                    {{-- Plan --}}
                    <div>
                        <dt class="text-sm text-app-muted">{{ __('Plan') }}</dt>
                        <dd class="mt-1 font-medium">
                            {{ $this->currentSubscription->plan->name }}
                        </dd>
                    </div>

                    {{-- Status --}}
                    <div>
                        <dt class="text-sm text-app-muted">{{ __('Status') }}</dt>
                        <dd class="mt-1">
                            @php
                                $statusLabel = match ($this->currentSubscription->status) {
                                    'active'    => __('Active'),
                                    'trialing'  => __('Trial'),
                                    'past_due'  => __('Past due'),
                                    'cancelled' => __('Cancelled'),
                                    default     => __(str_replace('_', ' ', ucfirst($this->currentSubscription->status))),
                                };
                                
                                $statusTone = match ($this->currentSubscription->status) {
                                    'active'    => 'success',
                                    'trialing'  => 'warning',
                                    'past_due', 'cancelled' => 'danger',
                                    default     => 'warning',
                                };
                            @endphp

                            <span class="app-badge app-badge-{{ $statusTone }}">
                                {{ $statusLabel }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        {{-- Assigned At (formerly First Subscribed) --}}
                        <dt class="text-sm text-app-muted">{{ __('Assigned At') }}</dt>
                        <dd class="mt-1 font-medium">
                            {{ $this->currentSubscription->created_at?->format('d/m/Y H:i') ?? __('Not available') }}
                        </dd>

                        {{-- Current Billing Period --}}
                        @if ($this->currentPeriod)
                            <dt class="mt-5 text-sm text-app-muted">
                                {{ $this->currentSubscription->status === 'trialing'
                                    ? __('Trial Period')
                                    : __('Current Billing Period') }}
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ $this->currentPeriod['start']->format('d/m/Y H:i') }}
                                &ndash;
                                {{ $this->currentPeriod['end']->format('d/m/Y H:i') }}
                            </dd>
                        @endif
                    </div>

 

                    {{-- Current Period Status --}}
                    @if (
                        $this->currentSubscription->status !== 'trialing'
                        && $this->currentPeriod
                    )
                        <div>
                            <dt class="text-sm text-app-muted">
                                {{ __('Current Period Status') }}
                            </dt>
                            <dd class="mt-1">
                                @php
                                    $periodStatus = $this->currentPeriodPaymentStatus;
                                    $periodBadgeTone = match ($periodStatus) {
                                        'paid'    => 'success',
                                        'pending' => 'warning',
                                        default   => 'danger',
                                    };
                                    $periodLabel = match ($periodStatus) {
                                        'paid'    => __('Paid'),
                                        'pending' => __('Pending Invoice'),
                                        default   => __('Unpaid'),
                                    };
                                @endphp
                                <span class="app-badge app-badge-{{ $periodBadgeTone }}">
                                    {{ $periodLabel }}
                                </span>
                            </dd>
                        </div>
                    @endif

                    {{-- Next Billing Period (Prepaid) --}}
                    @if ($this->nextPeriod)
                        <div class="sm:col-span-2 border-t border-neutral-200 dark:border-neutral-700 pt-4 mt-2">
                            <dt class="text-sm text-app-muted flex items-center gap-2">
                                {{ __('Next Billing Period') }}
                                <span class="app-badge app-badge-info">{{ __('Prepaid') }}</span>
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ $this->nextPeriod['start']->format('d/m/Y') }}
                                &ndash;
                                {{ $this->nextPeriod['end']->format('d/m/Y') }}
                            </dd>
                        </div>
                    @endif
                </dl>
            @else
                <flux:text>{{ __('This clinic does not have a subscription yet.') }}</flux:text>
            @endif
        </flux:card>
    </div>

    <flux:card class="rounded-2xl">
        <form wire:submit="assignPlan" class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Assign/Change Plan') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Assign or change this clinic’s subscription plan.') }}</flux:text>
                </div>

                @if ($this->isLocked)
                    <flux:modal.trigger name="confirm-cancel-subscription">
                        <flux:button
                            variant="filled"
                            color="yellow"
                            icon="x-circle"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Cancel Subscription') }}
                        </flux:button>
                    </flux:modal.trigger>
                @endif
            
            </div>

            @if ($this->isLocked && $this->currentSubscription)
                @php
                    $cycleEndDate = ($this->currentSubscription->status === 'trialing'
                        ? $this->currentSubscription->trial_ends_at
                        : $this->currentSubscription->ends_at)?->format('d/m/Y') ?? '—';
                @endphp
                <flux:callout variant="warning" icon="information-circle">
                    {{ __('Plan is locked until the current billing cycle ends on :date. To change plans, you must wait until the cycle ends or cancel the subscription manually.', ['date' => $cycleEndDate]) }}
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>{{ __('Plan') }} <span class="text-danger" aria-hidden="true">*</span></flux:label>
                <flux:select wire:model.live="selectedPlanId" :disabled="$this->isLocked" required>
                    <flux:select.option value="">{{ __('Select a plan') }}</flux:select.option>
                    @foreach ($this->plans as $plan)
                        <flux:select.option :value="$plan->id">
                            {{ $plan->name }} — {{ $plan->price }} MAD / {{ $plan->billing_interval }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="selectedPlanId" />
            </flux:field>

            <flux:checkbox
                wire:model="startAsTrial"
                :disabled="$this->isLocked"
                :label="__('Start as Trial (if plan has trial days)')"
            />
            <flux:error name="startAsTrial" />

            <flux:button type="submit" variant="primary" :disabled="$this->isLocked" wire:loading.attr="disabled">
                {{ __('Save Plan') }}
            </flux:button>
        </form>
    </flux:card>

    <flux:card class="space-y-4 rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <flux:heading size="lg">{{ __('Payment History') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Review payment transactions and history for this clinic.') }}</flux:text>
                <flux:error name="createPaymentAmount" class="mt-2" />
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreatePayment">
                {{ __('Record Payment') }}
            </flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Billed Period') }}</flux:table.column>
                <flux:table.column>{{ __('Method') }}</flux:table.column>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column>{{ __('Created At') }}</flux:table.column>
                <flux:table.column>{{ __('Paid At') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->payments as $payment)
                    @php
                        $statusTone = match ($payment->status) {
                            'paid'    => 'success',
                            'pending' => 'warning',
                            'failed'  => 'danger',
                            default   => 'info',
                        };

                        $methodTone = match ($payment->method) {
                            'cash'          => 'info',
                            'bank_transfer' => 'neutral',
                            default         => 'neutral',
                        };

                        $methodLabel = match ($payment->method) {
                            'bank_transfer' => __('Bank Transfer'),
                            'cash'          => __('Cash'),
                            null            => null,
                            default         => __(ucfirst($payment->method)),
                        };
                    @endphp
                    <flux:table.row :key="$payment->id">
                        <flux:table.cell variant="strong">{{ $payment->amount }} {{ $payment->currency }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="app-badge app-badge-{{ $statusTone }}">
                                {{ __(str_replace('_', ' ', ucfirst($payment->status))) }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($payment->period_starts_at && $payment->period_ends_at)
                                {{ $payment->period_starts_at->format('d/m/Y') }} - {{ $payment->period_ends_at->format('d/m/Y') }}
                            @else
                                &mdash;
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($methodLabel)
                                <span class="app-badge app-badge-{{ $methodTone }}">{{ $methodLabel }}</span>
                            @else
                                &mdash;
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $payment->reference ?: '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $payment->created_at?->format('d/m/Y H:i') ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $payment->paid_at?->format('d/m/Y H:i') ?? '—' }}
                        </flux:table.cell>
                        {{-- NEW ACTIONS CELL --}}
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
                        <flux:table.cell colspan="8" class="py-8 text-center text-sm text-app-muted">
                            {{ __('No payment records found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->payments->links() }}
    </flux:card>

    <flux:modal name="confirm-clinic-status" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $this->clinic->is_active ? __('Suspend Clinic') : __('Activate Clinic') }}
                </flux:heading>
                <flux:text class="mt-2">
                    {{ $this->clinic->is_active
                        ? __('Are you sure you want to suspend :clinic? Staff and doctors will be prevented from accessing the clinic.', ['clinic' => $this->clinic->name])
                        : __('Are you sure you want to activate :clinic? Access will be restored for clinic staff.', ['clinic' => $this->clinic->name])
                    }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="{{ $this->clinic->is_active ? 'danger' : 'primary' }}"
                    wire:click="toggleClinicStatus"
                >
                    {{ $this->clinic->is_active ? __('Suspend Clinic') : __('Activate Clinic') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-cancel-subscription" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Cancel Subscription') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to cancel this subscription? The clinic will lose access to active features, and any auto-created pending payment for this subscription will be removed.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Keep Subscription') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="danger"
                    wire:click="cancelSubscription"
                >
                    {{ __('Cancel Subscription') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

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
