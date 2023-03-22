<?php

use App\SectionAvailability;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SectionAvailabilityPeriodsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (SectionAvailability::all() as $availability) {
            DB::table('section_availability_periods')->insert([
                'section_availability_id' => $availability->id,
                'start_time' => '07:00:00',
                'end_time' => '23:00:00',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
