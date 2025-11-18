<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            'admin',
            'teacher',
            'student',
            'parent'
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName],
                ['name' => $roleName]
            );
        }

        $this->command->info('Roles seeded successfully!');
    }
}