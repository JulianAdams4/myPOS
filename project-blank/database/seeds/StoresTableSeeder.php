<?php

use App\Address;
use App\Company;
use App\City;
use App\Helper;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class StoresTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker\Factory::create();
        $companies = Company::all();
        foreach ($companies as $company) {
            foreach (range(1, rand(1, 4)) as $index) {
                $city = City::with('country')->inRandomOrder()->first();
                DB::table('stores')->insert([
                    'company_id' => $company->id,
                    'name' => $company->name . ' ' . $index,
                    'phone' => $faker->phoneNumber,
                    'contact' => $faker->name,
                    'currency' => 'USD',
                    'issuance_point' => '002',
                    'code' => '001',
                    'address' => $faker->address,
                    'country_code' => $city->country->code,
                    'bill_sequence' => 1,
                    'order_app_sync' => 1,
                    'button_bill_prints' => 1,
                    'city_id' => $city->id,
                    'max_sequence' => 1,
                    'email' => $faker->email,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
