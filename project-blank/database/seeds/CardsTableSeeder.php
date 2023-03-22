<?php

use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CardsTableSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run()
	{
		DB::table('cards')->insert([
			'name' => 'Visa Débito',
			'type' => 1,
			'created_at' => Carbon::now()->toDateTimeString(),
			'updated_at' => Carbon::now()->toDateTimeString(),
		]);
		DB::table('cards')->insert([
			'name' => 'Visa Crédito',
			'type' => 0,
			'created_at' => Carbon::now()->toDateTimeString(),
			'updated_at' => Carbon::now()->toDateTimeString(),
		]);
		DB::table('cards')->insert([
			'name' => 'Mastercard Débito',
			'type' => 1,
			'created_at' => Carbon::now()->toDateTimeString(),
			'updated_at' => Carbon::now()->toDateTimeString(),
		]);
		DB::table('cards')->insert([
			'name' => 'Mastercard Credito',
			'type' => 0,
			'created_at' => Carbon::now()->toDateTimeString(),
			'updated_at' => Carbon::now()->toDateTimeString(),
		]);
		DB::table('cards')->insert([
			'name' => 'Amex',
			'type' => 0,
			'created_at' => Carbon::now()->toDateTimeString(),
			'updated_at' => Carbon::now()->toDateTimeString(),
		]);
	}
}
