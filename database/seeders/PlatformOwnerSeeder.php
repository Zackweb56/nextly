<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('platform-owner.name');
        $email = config('platform-owner.email');
        $password = config('platform-owner.password');

        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Set PLATFORM_OWNER_NAME before seeding the platform owner.');
        }

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Set a valid PLATFORM_OWNER_EMAIL before seeding the platform owner.');
        }

        if (! is_string($password) || strlen($password) < 12) {
            throw new InvalidArgumentException('Set PLATFORM_OWNER_PASSWORD to a value of at least 12 characters before seeding the platform owner.');
        }
        setPermissionsTeamId(null);
        setPermissionsTeamId(null);

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'email_verified_at' => now(),
                'is_platform_owner' => true,
            ],
        );

        if (! $user->is_platform_owner) {
            $user->forceFill(['is_platform_owner' => true])->save();
        }
    }
}
