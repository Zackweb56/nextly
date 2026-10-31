<?php

namespace App\Http\Middleware;

use App\Models\Clinic;
use App\Support\CurrentClinic;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentClinic
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('owner.clinics.show') && Auth::user()?->is_platform_owner) {
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $next($request);
        }

        // Check if the URL has a clinic parameter (e.g., /{clinic:slug}/...)
        $clinicParam = $request->route('clinic');

        if ($clinicParam) {
            if ($request->routeIs('owner.*') && Auth::user()?->is_platform_owner) {
                return $next($request);
            }

            if (! Auth::check()) {
                return $next($request);
            }

            $clinicId = $clinicParam instanceof Clinic
                ? $clinicParam->id
                : Clinic::where('slug', $clinicParam)->value('id');

            if ($clinicId && Auth::user()->clinics()->where('clinics.id', $clinicId)->exists()) {
                CurrentClinic::set((int) $clinicId);
            } else {
                abort(403, 'You do not have access to this clinic.');
            }
        }

        $clinic = CurrentClinic::get();

        if ($clinic) {
            // 4.3: Set the team ID for Spatie Permissions
            setPermissionsTeamId($clinic->id);

            // Clear Spatie cache to avoid stale roles from the previous request/team
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } else {
            // If no clinic is found, ensure Spatie team is reset to null
            setPermissionsTeamId(null);
        }

        return $next($request);
    }
}
