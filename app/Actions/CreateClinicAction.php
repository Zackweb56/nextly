<?php

namespace App\Actions;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

class CreateClinicAction
{
    /**
     * Execute the clinic onboarding process.
     *
     * @param  array{name: string, slug?: string, phone?: string|null, city?: string|null, admin_name?: string, admin_email?: string, admin_password?: string}  $data
     */
    public function execute(array $data): Clinic
    {
        $previousTeamId = getPermissionsTeamId();

        try {
            return DB::transaction(function () use ($data): Clinic {
                $slug = $data['slug'] ?? $this->uniqueSlug($data['name']);

                // 1. Create the clinic
                $clinic = Clinic::create([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'phone' => $data['phone'] ?? null,
                    'city' => $data['city'] ?? null,
                    'is_active' => true,
                ]);

                // 2. Setup the Admin User if provided
                $hasAdminName = isset($data['admin_name']);
                $hasAdminEmail = isset($data['admin_email']);

                if ($hasAdminName !== $hasAdminEmail) {
                    throw new InvalidArgumentException('Both admin name and email must be provided when creating a clinic administrator.');
                }

                if ($hasAdminName && $hasAdminEmail) {
                    // Set the team context to the new clinic BEFORE creating/assigning
                    setPermissionsTeamId($clinic->id);
                    app(PermissionRegistrar::class)->forgetCachedPermissions();

                    $admin = User::create([
                        'name' => $data['admin_name'],
                        'email' => $data['admin_email'],
                        'password' => $data['admin_password'] ?? Str::password(24),
                        'email_verified_at' => now(),
                    ]);

                    // Attach user to clinic via your custom pivot table
                    $admin->clinics()->attach($clinic->id, [
                        'role' => 'clinic_admin',
                        'is_active' => true,
                        'joined_at' => now(),
                    ]);

                    // Assign the GLOBAL role directly by its name.
                    // Spatie will automatically write the $clinic->id to the model_has_roles table.
                    $admin->assignRole('clinic_admin');
                }

                return $clinic;
            });
        } finally {
            // Restore previous team context and clear cache
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'clinic';
        $slug = $baseSlug;
        $suffix = 2;

        while (Clinic::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
