<?php

use Carbon\Carbon;
use App\Company;
use Illuminate\Database\Seeder;

class ComponentCategoryTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (Company::all() as $company) {
            foreach (range(1, rand(3, 10)) as $index) {
                $name = $faker->lexify('??????');
                DB::table('component_categories')->insert([
                    'company_id' => $company->id,
                    'name' => strtoupper($name),
                    'search_string' => strtolower($name),
                    'status' => 1,
                    'priority' => $index,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
