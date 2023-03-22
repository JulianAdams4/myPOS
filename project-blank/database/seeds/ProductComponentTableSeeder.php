<?php

use Carbon\Carbon;
use App\Product;
use App\Component;
use Illuminate\Database\Seeder;

class ProductComponentTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Product::with('category.company')->get() as $product) {
            $components = Component::whereHas('category', function ($category) use ($product) {
                $category->where('company_id', $product->category->company_id);
            })->take(rand(1, 5))->get();
            foreach ($components as $component) {
                DB::table('product_components')->insert([
                    'product_id' => $product->id,
                    'component_id' => $component->id,
                    'consumption' => rand(10, 10000) / 100,
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
