<?php

declare(strict_types=1);

namespace App\Support;

class ClinicPermissionCatalog
{
    /**
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        return [
            'users' => ['users.view', 'users.create', 'users.update', 'users.delete'],
            'roles' => ['roles.view', 'roles.manage'],
            'queue' => ['queue.view', 'queue.manage'],
            'appointments' => ['appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel'],
            'patients' => ['patients.view', 'patients.create', 'patients.update'],
            'services' => ['services.view', 'services.create', 'services.update', 'services.delete'],
            'settings' => ['settings.manage'],
            'billing' => ['billing.view'],
            'dashboard' => [
                'dashboard.stats',
                'dashboard.queue',
                'dashboard.appointments',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::grouped() as $permissions) {
            foreach ($permissions as $permission) {
                $all[] = $permission;
            }
        }

        return array_values(array_unique($all));
    }
}
