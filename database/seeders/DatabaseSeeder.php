<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Support\ProductCommission;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            BrandSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
        ]);

        AppSetting::updateOrCreate(
            ['key' => ProductCommission::GLOBAL_COMMISSION_KEY],
            ['value' => '5.00']
        );
    }
}
