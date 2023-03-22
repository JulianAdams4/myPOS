<?php

use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ProductionOrderReasonsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('production_order_reasons')->insert([
            'reason' => 'No hay insumos',
            'type' => 2,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('production_order_reasons')->insert([
            'reason' => 'Daño en equipos',
            'type' => 2,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('production_order_reasons')->insert([
            'reason' => 'Orden ya no es necesaria',
            'type' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('production_order_reasons')->insert([
            'reason' => 'Otros',
            'type' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}
