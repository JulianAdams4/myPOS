<?php

use Carbon\Carbon;
use App\Country;
use Illuminate\Database\Seeder;

class CitiesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $ecuador = Country::where('code', 'EC')->first();
        if ($ecuador) {
            DB::table('cities')->insert([
                'country_id' => $ecuador->id,
                'name' => 'Guayaquil',
                'code' => 'GYE',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
        $colombia = Country::where('code', 'CO')->first();
        if ($colombia) {
            DB::table('cities')->insert([
                'country_id' => $colombia->id,
                'name' => 'Bogotá',
                'code' => 'DC',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
        $mexico = Country::where('code', 'MX')->first();
        if ($mexico) {
            DB::table('cities')->insert([
                'country_id' => $mexico->id,
                'name' => 'Ciudad de México',
                'code' => 'CDMX',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
