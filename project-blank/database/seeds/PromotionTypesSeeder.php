<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class PromotionTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('promotion_types')->insert([
            'name' => "Nominales",
            'is_discount_type' => 1,
            'status' => 'A',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        ]);
        DB::table('promotion_types')->insert([
            'name' => "Porcentuales",
            'is_discount_type' => 1,
            'status' => 'A',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        ]);
        DB::table('promotion_types')->insert([
            'name' => "Producto gratis",
            'is_discount_type' => 0,
            'status' => 'A',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        ]);
        DB::table('promotion_types')->insert([
            'name' => "Condicionado a producto",
            'is_discount_type' => 0,
            'status' => 'A',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        ]);
        DB::table('promotion_types')->insert([
            'name' => "Condicionado al valor",
            'is_discount_type' => 0,
            'status' => 'A',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        ]);
    }
}
