<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BillingSuspendedController extends Controller
{
    public function __invoke(Request $request, Clinic $clinic): View
    {
        $reason = $this->determineSuspensionReason($clinic);

        // Check if the clinic is actually fully active (or in a valid trial)
        $isActive = in_array($reason, ['active_valid', 'trial_valid']);

        return view('billing.suspended', [
            'clinic' => $clinic,
            'reason' => $reason,
            'isActive' => $isActive,
        ]);
    }

    private function determineSuspensionReason(Clinic $clinic): string
    {
        if (! $clinic->is_active) {
            return 'suspended_by_owner';
        }

        $subscription = $clinic->subscription ?? Subscription::withoutGlobalScope('clinic')
            ->where('clinic_id', $clinic->id)
            ->latest('id')
            ->first();

        if (! $subscription) {
            return 'no_subscription';
        }

        // If it's a valid trial, it shouldn't be here, but if it is, mark it valid
        if ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isFuture()) {
            return 'trial_valid';
        }

        if ($subscription->status === 'active' && ($subscription->ends_at === null || $subscription->ends_at->isFuture())) {
            return 'active_valid';
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
    }
}
