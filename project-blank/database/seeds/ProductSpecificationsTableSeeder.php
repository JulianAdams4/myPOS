<?php

use Carbon\Carbon;
use App\Specification;
use App\Product;
use App\SpecificationCategory;
use Illuminate\Database\Seeder;

class ProductSpecificationsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $products = Product::with('category.company')->get();
        foreach ($products as $product) {
            $specCategories = SpecificationCategory::where('company_id', $product->category->company_id)
                                ->with('specifications')->inRandomOrder()->take(rand(0, 5))->get();
            foreach ($specCategories as $category) {
                foreach ($category->specifications as $spec) {
                    DB::table('product_specifications')->insert([
                        'product_id' => $product->id,
                        'specification_id' => $spec->id,
                        'status' => 1,
                        'value' => $spec->value,
                        'created_at' => Carbon::now()->toDateTimeString(),
                        'updated_at' => Carbon::now()->toDateTimeString(),
                    ]);
                }
            }
        }
    }
}
