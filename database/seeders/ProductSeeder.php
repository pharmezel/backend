<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Map CSV category_name (mock / legacy labels) to brand + category records.
     */
    private function resolveBrandAndCategory(string $csvCategoryName): array
    {
        $key = strtolower(trim($csvCategoryName));

        $map = [
            'branded' => ['brand' => 'CarePlus', 'category' => 'Analgesic'],
            'generic' => ['brand' => 'Generic Labs', 'category' => 'Analgesic'],
            'analgesic' => ['brand' => 'CarePlus', 'category' => 'Analgesic'],
            'antibiotic' => ['brand' => 'Generic Labs', 'category' => 'Antibiotic'],
        ];

        $resolved = $map[$key] ?? ['brand' => 'Generic Labs', 'category' => 'Analgesic'];

        $brand = Brand::where('name', $resolved['brand'])->first();
        $category = Category::where('name', $resolved['category'])->first();

        return [
            'brand_id' => $brand?->id,
            'category_id' => $category?->id,
        ];
    }

    public function run(): void
    {
        $path = database_path('seeders/data/products.csv');
        if (! is_readable($path)) {
            return;
        }

        $file = fopen($path, 'r');
        $header = fgetcsv($file);

        while (($row = fgetcsv($file)) !== false) {
            if (count($row) < 8) {
                continue;
            }

            $productName = trim($row[0]);
            $csvCategoryName = trim($row[2]);
            $ids = $this->resolveBrandAndCategory($csvCategoryName);

            $dateAdded = $row[6] ? strtotime($row[6]) : false;
            $dateAddedValue = $dateAdded ? date('Y-m-d H:i:s', $dateAdded) : now();

            Product::updateOrCreate(
                ['product_name' => $productName],
                [
                    'description' => $row[1] ?: null,
                    'category_name' => $csvCategoryName,
                    'brand_id' => $ids['brand_id'],
                    'category_id' => $ids['category_id'],
                    'unit_price' => $row[3],
                    'expiry_date' => $row[4] ?: null,
                    'stock_quantity' => (int) $row[5],
                    'date_added' => $dateAddedValue,
                    'commission_rate' => '5.00',
                ]
            );
        }

        fclose($file);
    }
}
