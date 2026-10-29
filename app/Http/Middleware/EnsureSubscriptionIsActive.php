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

        $sessionClinicId = session('current_clinic_id');
        if ($sessionClinicId) {
            $sessionClinic = $user->clinics()
                ->where('clinics.id', $sessionClinicId)
                ->wherePivot('is_active', true)
                ->first();

            if ($sessionClinic && ! $sessionClinic->is_active) {
                if ($request->routeIs('billing.*', 'logout')) {
                    return $next($request);
                }

                return redirect()->route('billing.suspended')
                    ->with('error', 'Your clinic has been suspended. Please contact support.')
                    ->with('clinic_suspended', true);
            }
        }

        $clinic = CurrentClinic::get();

        if (! $clinic) {
            $suspendedClinic = $user->clinics()
                ->wherePivot('is_active', true)
                ->where('clinics.is_active', false)
                ->first();

            if ($suspendedClinic) {
                if ($request->routeIs('billing.*', 'logout')) {
                    return $next($request);
                }

                return redirect()->route('billing.suspended')
                    ->with('error', 'Your clinic has been suspended. Please contact support.')
                    ->with('clinic_suspended', true);
            }

            return $next($request);
        }

        if (! $clinic->is_active) {
            if ($request->routeIs('billing.*', 'logout')) {
                return $next($request);
            }

            return redirect()->route('billing.suspended')
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
            if ($request->routeIs('billing.*', 'logout')) {
                return $next($request);
            }

            return redirect()->route('billing.suspended')
                ->with('error', 'Your clinic subscription is inactive. Please contact support.');
        }

        return $next($request);
    }
}
