<?php

namespace App\Support;

class ClinicPermissionCatalog
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'dashboard.view',
            'appointments.view',
            'appointments.create',
            'appointments.update',
            'appointments.cancel',
            'patients.view',
            'patients.create',
            'patients.update',
            'queue.view',
            'queue.manage',
            'services.view',
            'services.create',
            'services.update',
            'services.delete',
            'staff.view',
            'staff.invite',
            'staff.update',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'clinic.settings.update',
            'subscriptions.view',
            'payments.view',
        ];
    }

    /**
     * @return list<string>
     */
    public static function receptionist(): array
    {
        return [
            'appointments.view',
            'appointments.create',
            'appointments.update',
            'patients.view',
            'patients.create',
            'queue.view',
            'queue.manage',
        ];
    }
}
