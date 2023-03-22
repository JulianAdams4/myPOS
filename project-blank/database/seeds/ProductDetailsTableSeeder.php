<?php

use App\Product;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProductDetailsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $products = Product::with('category.company.stores')->get();
        foreach ($products as $product) {
            $costPercentage = rand(10, 45) / 100;
            $incomePercentage = 1 - $costPercentage;
            $stores = $product->category->company->stores;
            foreach ($stores as $store) {
                DB::table('product_details')->insert([
                    'product_id' => $product->id,
                    'store_id' => $store->id,
                    'stock' => 0,
                    'value' => $product->base_value,
                    'status' => 1,
                    'production_cost' => ($product->base_value / 100) * $costPercentage,
                    'income' => ($product->base_value / 100) * $incomePercentage,
                    'cost_ratio' => $costPercentage * 100,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
