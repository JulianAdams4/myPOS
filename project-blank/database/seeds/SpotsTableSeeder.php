<?php

use App\Spot;
use App\Store;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SpotsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Store::all() as $store) {
            $numNormal = rand(1, 3);
            $numDividir = rand(1, 3);
            $hasEats = rand(1, 10) > 7 ? 1 : 0;
            $hasRappi = rand(1, 10) > 7 ? 1 : 0;
            $hasPostmates = rand(1, 10) > 7 ? 1 : 0;
            $hasSinDelantal = rand(1, 10) > 7 ? 1 : 0;
            $hasDomicilios = rand(1, 10) > 7 ? 1 : 0;
            $hasiFood = rand(1, 10) > 7 ? 1 : 0;
            foreach (range(1, $numNormal) as $normal) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Normal " . $normal,
                    'origin' => Spot::ORIGIN_MYPOS_NORMAL,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            foreach (range(1, $numDividir) as $dividir) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Dividir " . $dividir,
                    'origin' => Spot::ORIGIN_MYPOS_DIVIDIR,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasEats) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Uber Eats",
                    'origin' => Spot::ORIGIN_EATS,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasRappi) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Rappi",
                    'origin' => Spot::ORIGIN_RAPPI,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasPostmates) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Postmates",
                    'origin' => Spot::ORIGIN_POSTMATES,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasSinDelantal) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Sin Delantal",
                    'origin' => Spot::ORIGIN_SIN_DELANTAL,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasDomicilios) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Domicilios.com",
                    'origin' => Spot::ORIGIN_DOMICILIOS,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasiFood) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "iFood",
                    'origin' => Spot::ORIGIN_IFOOD,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasDomicilios) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Mercado Pago",
                    'origin' => Spot::ORIGIN_MERCADO_PAGO,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasDomicilios) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Bonos Sodexo Bigpass",
                    'origin' => Spot::ORIGIN_BONOS,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
            if ($hasDomicilios) {
                DB::table('spots')->insert([
                    'store_id' => $store->id,
                    'name' => "Exito",
                    'origin' => Spot::ORIGIN_EXITO,
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
    }
}
