<?php

use App\Helper;
use App\ProductCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProductTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (ProductCategory::all() as $category) {
            foreach (range(1, rand(1, 10)) as $index) {
                $name = $faker->lexify('?????????');
                DB::table('products')->insert([
                    'product_category_id' => $category->id,
                    'name' => strtoupper($name),
                    'search_string' => strtolower($name),
                    'description' => $faker->sentence,
                    'priority' => $index,
                    'base_value' => rand(50, 2500),
                    'status' => 1,
                    'invoice_name' => strtoupper($name),
                    'ask_instruction' => rand(1, 10) > 6 ? 1 : 0,
                    'sku' => strtoupper($faker->bothify('?????????-#####')),
                    'eats_product_name' => 'Ninguno',
                    'image_version' => 0,
                    'is_alcohol' => rand(1, 10) > 9 ? 1 : 0,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
