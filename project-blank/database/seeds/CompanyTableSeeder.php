<?php

use App\Helper;
use Illuminate\Database\Seeder;

class CompanyTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        foreach (range(1, 20) as $index) {
            DB::table('companies')->insert([
                'name' => $faker->company,
                'identifier' => $faker->lexify('??????'),
                'contact' => $faker->name,
                'TIN' => $faker->numerify('#############'),
            ]);
        }
    }
}
