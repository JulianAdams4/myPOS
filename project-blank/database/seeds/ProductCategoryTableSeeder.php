<?php

use App\Store;
use App\Company;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProductCategoryTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        $companies = Company::with('stores.sections')->get();
        foreach ($companies as $company) {
            $priority = 1;
            foreach ($company->stores as $store) {
                foreach ($store->sections as $section) {
                    foreach (range(1, rand(1, 3)) as $index) {
                        $name = $faker->lexify('??????');
                        DB::table('product_categories')->insert([
                            'company_id' => $company->id,
                            'section_id' => $section->id,
                            'priority' => $priority,
                            'name' => strtoupper($name),
                            'search_string' => strtolower($name),
                            'status' => 1,
                            'created_at' => Carbon::now()->toDateTimeString(),
                            'updated_at' => Carbon::now()->toDateTimeString(),
                            'subtitle' => ''
                        ]);
                        $priority++;
                    }
                }
            }
        }
    }
}
