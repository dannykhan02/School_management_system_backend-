<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        // Create role if not exists
        $role = Role::firstOrCreate(['name' => 'super-admin']);

        // Get credentials from environment variables with fallback validation
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');
        $fullName = env('SUPER_ADMIN_NAME', 'Super Administrator');

        // Validate that credentials are set and not default values
        if (empty($email) || empty($password)) {
            $this->command->error('Super admin credentials not found in .env file!');
            $this->command->error('Please set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in your .env file.');
            return;
        }

        // Warn if using weak or default credentials in production
        if (app()->environment('production')) {
            if (strlen($password) < 12) {
                $this->command->warn('WARNING: Super admin password should be at least 12 characters in production!');
            }
            if ($email === 'admin@example.com') {
                $this->command->error('ERROR: Cannot use default email (admin@example.com) in production!');
                return;
            }
        }

        // Create super admin user if not exists
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'full_name' => $fullName,
                'password'  => Hash::make($password),
                'role_id'   => $role->id,
                'status'    => 'active',
            ]
        );

        // Ensure role assigned
        if ($user->role_id !== $role->id) {
            $user->role_id = $role->id;
            $user->save();
        }

        $this->command->info('Super admin user created/verified successfully!');
        $this->command->info('Email: ' . $email);
    }
}