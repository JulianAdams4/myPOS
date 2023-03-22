<?php

use App\Store;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SectionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Store::all() as $store) {
            DB::table('sections')->insert([
                'store_id' => $store->id,
                'name' => 'Menú',
                'subtitle' => '10 AM - 2 PM, 3 PM - 7 PM',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
