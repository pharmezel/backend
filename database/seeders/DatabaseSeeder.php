<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Load admin from JSON
        $admin = json_decode(
            File::get(database_path('seeders/data/admin.json')),
            true
        );

        // Create Admin user
        User::create([
            'email' => $admin['email'],
            'password' => bcrypt($admin['password']),
            'role' => 'admin',

            // temporary placeholders 
            'first_name' => 'Admin',
            'last_name' => 'User',
            'contact_number' => '09876543210',
            'date_of_birth' => null,
            'referral_code' => null,
            'referrer_code_used' => null,
            'date_registered' => now(),
        ]);

        // Seed products (should be deleted soon if excel file is not required)
        $this->call([
            ProductSeeder::class,
        ]);
    }
}