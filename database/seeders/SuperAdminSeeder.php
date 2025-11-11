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
        // create role if not exists
        $role = Role::firstOrCreate(['name' => 'super-admin']);

        // create super admin user if not exists
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'full_name' => 'Super Admin',
                'password'  => Hash::make('ChangeMe123!'), // hashed here
                'role_id'   => $role->id,
                'status'    => 'active',
            ]
        );

        // ensure role assigned
        if ($user->role_id !== $role->id) {
            $user->role_id = $role->id;
            $user->save();
        }
    }
}
