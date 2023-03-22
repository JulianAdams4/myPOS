<?php

use App\ComponentCategory;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ComponentTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (ComponentCategory::all() as $category) {
            foreach (range(1, rand(2, 10)) as $index) {
                DB::table('components')->insert([
                    'component_category_id' => $category->id,
                    'name' => strtoupper($faker->lexify('??????')),
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
