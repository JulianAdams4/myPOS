<?php

use App\Section;
use App\Company;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SpecificationCategoriesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (Company::all() as $company) {
            $sections = Section::whereHas('store', function ($store) use ($company) {
                $store->where('company_id', $company->id);
            })->get();
            foreach ($sections as $section) {
                foreach (range(1, rand(5, 10)) as $index) {
                    $type = rand(1, 10) > 6 ? 2 : 1;
                    $showQuantity = rand(1, 10) > 5 ? 1 : 0;
                    $max = rand(1, 5);
                    DB::table('specification_categories')->insert([
                        'company_id' => $company->id,
                        'section_id' => $section->id,
                        'name' => strtoupper($faker->lexify('??????')),
                        'priority' => $index,
                        'required' => rand(1, 10) > 7 ? 1 : 0,
                        'max' => $type === 1 ? $max : 1,
                        'show_quantity' => $type === 1 ? $showQuantity : 0,
                        'type' => $type,
                        'created_at' => Carbon::now()->toDateTimeString(),
                        'updated_at' => Carbon::now()->toDateTimeString(),
                        'subtitle' => ''
                    ]);
                }
            }
        }
    }
}
