<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Call the RoleSeeder first (roles must exist before users)
        $this->call(RoleSeeder::class);
        
        // Then call the SuperAdminSeeder (needs roles to exist)
        $this->call(SuperAdminSeeder::class);
    }
}