<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        Brand::updateOrCreate(
            ['name' => 'Generic Labs'],
            [
                'contact_number' => '09170001000',
                'email' => 'hello@genericlabs.demo',
                'address' => null,
            ]
        );

        Brand::updateOrCreate(
            ['name' => 'CarePlus'],
            [
                'contact_number' => '09170002000',
                'email' => null,
                'address' => null,
            ]
        );
    }
}
