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
        // Check if the URL has a clinic slug (e.g., /clinic/al-amal/...)
        $clinicSlug = $request->route('clinic');

        if ($clinicSlug) {
            // If the route has a clinic parameter, force the session to switch to it
            // ONLY if the user actually belongs to it.
            if ($clinicSlug instanceof Clinic) {
                $clinicId = $clinicSlug->id;
            } else {
                $clinicId = Clinic::where('slug', $clinicSlug)->value('id');
            }

            if ($clinicId && Auth::user()->clinics()->where('clinic_id', $clinicId)->exists()) {
                CurrentClinic::set($clinicId);
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
