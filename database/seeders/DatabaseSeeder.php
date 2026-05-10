<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\ProductCommission;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BrandSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
        ]);

        User::updateOrCreate(
            ['email' => 'admin@pharmicare.com'],
            [
                'first_name' => 'Pharmicare',
                'last_name' => 'Admin',
                'password' => 'pharmicare',
                'role' => 'superadmin',
                'contact_number' => '09170000000',
                'date_of_birth' => null,
                'referral_code' => 'PHARMICARE',
                'referrer_code_used' => null,
                'points' => 0,
                'shipping_address' => null,
                'date_registered' => now(),
            ]
        );

        AppSetting::updateOrCreate(
            ['key' => ProductCommission::GLOBAL_COMMISSION_KEY],
            ['value' => '5.00']
        );
    }
}
