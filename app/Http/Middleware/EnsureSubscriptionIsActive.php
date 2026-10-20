<?php

namespace App\Http\Middleware;

use App\Support\CurrentClinic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $clinic = CurrentClinic::get();

        if (! $clinic || $user?->is_platform_owner) {
            return $next($request);
        }

        $subscription = $clinic->subscription;
        $isValid = $subscription !== null
            && in_array($subscription->status, ['active', 'trialing'], true)
            && ($subscription->ends_at === null || $subscription->ends_at->isFuture())
            && ($subscription->status !== 'trialing'
                || $subscription->trial_ends_at === null
                || $subscription->trial_ends_at->isFuture());

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
