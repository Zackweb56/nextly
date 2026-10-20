<?php

namespace App\Support;

use App\Models\Clinic;
use Illuminate\Support\Facades\Auth;

class CurrentClinic
{
    /**
     * Get the current clinic for the authenticated user.
     */
    public static function get(): ?Clinic
    {
        if (! Auth::check()) {
            return null;
        }

        $sessionClinicId = session('current_clinic_id');

        // 1. If session has an ID, verify the user has an ACTIVE membership in this clinic
        if ($sessionClinicId) {
            $clinic = Auth::user()->clinics()
                ->where('clinics.id', $sessionClinicId)
                ->wherePivot('is_active', true)
                ->where('clinics.is_active', true) // Ensure clinic isn't suspended
                ->first();

            if ($clinic) {
                return $clinic;
            }

            // If they don't belong or it's inactive, clear the bad session
            session()->forget('current_clinic_id');
        }

        // 2. If no session, grab their first active clinic
        $firstClinic = Auth::user()->clinics()
            ->wherePivot('is_active', true)
            ->where('clinics.is_active', true)
            ->first();

        if ($firstClinic) {
            self::set($firstClinic->id);

            return $firstClinic;
        }

        return null;
    }

    /**
     * Set the current clinic in the session.
     */
    public static function set(int $clinicId): void
    {
        session(['current_clinic_id' => $clinicId]);
    }

    /**
     * Get the current clinic ID.
     */
    public static function id(): ?int
    {
        return self::get()?->id;
    }

    /**
     * Get the current clinic slug.
     */
    public static function slug(): ?string
    {
        return self::get()?->slug;
    }
}
