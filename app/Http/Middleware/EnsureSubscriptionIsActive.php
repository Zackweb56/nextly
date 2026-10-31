<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Support\CurrentClinic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_platform_owner || $request->routeIs('owner.*')) {
            return $next($request);
        }

        $clinic = CurrentClinic::get();

        // If no clinic is found in the session, check if the user has ANY active clinic
        if (! $clinic) {
            $firstClinic = $user->clinics()->wherePivot('is_active', true)->first();
            if ($firstClinic) {
                // If they have a clinic, use it
                $clinic = $firstClinic;
            } else {
                // If they have no clinic at all, they shouldn't be here
                if ($request->routeIs('billing.*', 'logout', 'clinic.billing.*', 'settings.*', 'profile.*', 'security.*', 'appearance.*', 'verification.*', 'password.*')) {
                    return $next($request);
                }
                abort(403, 'No active clinic assigned.');
            }
        }

        // Check if clinic is suspended by the owner
        if (! $clinic->is_active) {
            if ($request->routeIs('billing.*', 'logout', 'clinic.billing.*', 'settings.*', 'profile.*', 'security.*', 'appearance.*', 'verification.*', 'password.*')) {
                return $next($request);
            }

            return redirect()->route('clinic.billing.suspended', ['clinic' => $clinic->slug])
                ->with('error', 'Your clinic has been suspended. Please contact support.')
                ->with('clinic_suspended', true);
        }

        $subscription = $clinic->subscription ?? Subscription::withoutGlobalScope('clinic')
            ->where('clinic_id', $clinic->id)
            ->latest('id')
            ->first();

        if ($subscription !== null) {
            $isExpiredTrial = $subscription->status === 'trialing'
                && $subscription->trial_ends_at !== null
                && $subscription->trial_ends_at->isPast();

            $isExpiredActive = $subscription->status === 'active'
                && $subscription->ends_at !== null
                && $subscription->ends_at->isPast();

            $isExpiredCancelled = $subscription->status === 'cancelled'
                && $subscription->ends_at !== null
                && $subscription->ends_at->isPast();

            if ($isExpiredTrial || $isExpiredActive || $isExpiredCancelled) {
                $subscription->status = 'past_due';
                $subscription->save();
            }
        }

        $isValid = $subscription !== null
            && ($subscription->isActive() || $subscription->isTrialing() || $subscription->onGracePeriod());

        if (! $isValid) {
            if ($request->routeIs('billing.*', 'logout', 'clinic.billing.*', 'settings.*', 'profile.*', 'security.*', 'appearance.*', 'verification.*', 'password.*')) {
                return $next($request);
            }

            return redirect()->route('clinic.billing.suspended', ['clinic' => $clinic->slug])
                ->with('error', 'Your clinic subscription is inactive. Please contact support.');
        }

        return $next($request);
    }
}
