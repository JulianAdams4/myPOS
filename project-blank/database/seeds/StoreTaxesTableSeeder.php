<?php

use Carbon\Carbon;
use App\Store;
use Illuminate\Database\Seeder;

class StoreTaxesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Store::all() as $store) {
            DB::table('store_taxes')->insert([
                'store_id' => $store->id,
                'name' => 'IVA',
                'percentage' => 12.00,
                'type' => 'included',
                'enabled' => true,
                'is_main' => true,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            if (rand(1, 10) > 6) {
                DB::table('store_taxes')->insert([
                    'store_id' => $store->id,
                    'name' => 'Servicio',
                    'percentage' => 10.00,
                    'type' => 'invoice',
                    'enabled' => true,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if (rand(1, 10) > 8) {
                DB::table('store_taxes')->insert([
                    'store_id' => $store->id,
                    'name' => 'ICE',
                    'percentage' => 8.00,
                    'type' => 'additional',
                    'enabled' => true,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
