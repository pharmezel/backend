<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        Category::updateOrCreate(
            ['name' => 'Analgesic'],
            ['description' => 'Pain relievers']
        );

        Category::updateOrCreate(
            ['name' => 'Antibiotic'],
            ['description' => 'Antibacterial medicines']
        );
    }
}
