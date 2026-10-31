<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Support\CurrentClinic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireClinicPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        // Platform owners bypass all clinic permission checks
        if ($user->is_platform_owner) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermissionTo($permission)) {
                return $next($request);
            }
        }

        $clinic = CurrentClinic::get();
        $clinicSlug = null;

        if ($clinic !== null) {
            $clinicSlug = $clinic->slug;
        } else {
            $clinicParam = $request->route('clinic');
            if ($clinicParam instanceof Clinic) {
                $clinicSlug = $clinicParam->slug;
            } elseif (is_string($clinicParam)) {
                $clinicSlug = $clinicParam;
            }
        }

        if ($clinicSlug !== null && ! $request->routeIs('clinic.dashboard')) {
            return redirect()
                ->route('clinic.dashboard', ['clinic' => $clinicSlug])
                ->with('toast', [
                    'heading' => __('Accès refusé'),
                    'text' => __('Vous n\'avez pas la permission d\'accéder à cette page.'),
                    'variant' => 'danger',
                ]);
        }

        abort(403, __('Vous n\'avez pas la permission d\'accéder à cette page.'));
    }
}
