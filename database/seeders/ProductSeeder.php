<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // will read the csv file (for development only)
        $file = fopen(database_path('seeders/data/products.csv'), 'r');
        $header = fgetcsv($file);

        while (($row = fgetcsv($file)) !== false) {

            // Safety check
            if (count($row) < 8) continue;

            Product::create([
                'product_name'     => $row[0],
                'description'      => $row[1],
                'category_name'    => $row[2],
                'unit_price'       => $row[3],
                'expiry_date'      => $row[4],
                'stock_quantity'   => $row[5],
                'date_added'       => $row[6],
                'commission_rate'  => $row[7],
            ]);
        }

        fclose($file);
    }
}