<?php

use Carbon\Carbon;
use Illuminate\Database\Seeder;

class StatusTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $status = ['Creada', 'En Camino', 'Local', 'Con Pedido', 'Entregada', 'Cancelada', 'Cerrada'];
        foreach ($status as $name) {
            DB::table('status')->insert([
                'name' => $name,
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }
    }
}
