<?php

use App\Component;
use App\MetricUnit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ComponentVariationTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (Component::all() as $component) {
            $metric = MetricUnit::inRandomOrder()->first();
            foreach (range(1, rand(1, 4)) as $index) {
                $value = rand(5, 750);
                $cost = $value * rand(15, 65) / 100;
                DB::table('component_variations')->insert([
                    'component_id' => $component->id,
                    'metric_unit_id' => $metric->id,
                    'name' => $component->name . ' ' . $faker->lexify('???'),
                    'cost' => $cost,
                    'value' => $value,
                    'SKU' => strtoupper($faker->bothify('?????????-#####')),
                    'status' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
