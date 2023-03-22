<?php

use App\Store;
use Illuminate\Database\Seeder;

class StorePrintersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Store::all() as $store) {
            DB::table('store_printers')->insert([
                'name' => 'EPSON UB-U03II',
                'model' => 'TM_U220',
                'number_model' => '15',
                'actions' => 1,
                'store_id' => $store->id,
                'connector' => 0,
                'interface' => '',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            DB::table('store_printers')->insert([
                'name' => 'TM-T20II',
                'model' => 'TM_T20',
                'number_model' => '6',
                'actions' => 2,
                'store_id' => $store->id,
                'connector' => 0,
                'interface' => '',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            DB::table('store_printers')->insert([
                'name' => 'EPSON H SERIES',
                'model' => 'H',
                'number_model' => '1',
                'actions' => 3,
                'store_id' => $store->id,
                'connector' => 0,
                'interface' => '',
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
