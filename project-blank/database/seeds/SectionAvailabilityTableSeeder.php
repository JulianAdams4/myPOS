<?php

use App\Section;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SectionAvailabilityTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Section::all() as $section) {
            foreach (range(1, 7) as $day) {
                DB::table('section_availabilities')->insert([
                    'section_id' => $section->id,
                    'day' => $day,
                    'enabled' => 1,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
