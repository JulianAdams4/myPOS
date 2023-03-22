<?php

use App\Helper;
use App\SpecificationCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SpecificationsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (SpecificationCategory::all() as $category) {
            foreach (range(1, rand(3, $category->max + 3)) as $index) {
                DB::table('specifications')->insert([
                    'specification_category_id' => $category->id,
                    'name' => strtoupper($faker->lexify('??????')),
                    'status' => 1,
                    'value' => rand(1, 10) > 7 ? rand(50, 500) : 0,
                    'priority' => $index,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
