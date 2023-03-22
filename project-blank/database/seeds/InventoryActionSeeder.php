<?php

use Illuminate\Database\Seeder;
use Carbon\Carbon;

class InventoryActionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('inventory_actions')->insert([
            'name' => "Existencias recibidas",
            'action' => 1,
            'code' => 'receive',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Recuento de inventario",
            'action' => 2,
            'code' => 'count',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Daño",
            'action' => 3,
            'code' => 'damaged',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Robo",
            'action' => 3,
            'code' => 'stolen',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Pérdida",
            'action' => 3,
            'code' => 'lost',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Devolución de artículos reabastecidos",
            'action' => 1,
            'code' => 'return',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Enviar a otra tienda",
            'action' => 3,
            'code' => 'send_transfer',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('inventory_actions')->insert([
            'name' => "Recibir de otra tienda",
            'action' => 1,
            'code' => 'receive_transfer',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}
