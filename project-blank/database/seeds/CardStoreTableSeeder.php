<?php

use Carbon\Carbon;
use App\Store;
use App\Card;
use Illuminate\Database\Seeder;

class CardStoreTableSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run()
	{
		foreach (Store::all() as $store) {
			foreach (Card::all() as $card) {
				DB::table('card_store')->insert([
					'card_id' => $card->id,
					'store_id' => $store->id,
					'created_at' => Carbon::now()->toDateTimeString(),
					'updated_at' => Carbon::now()->toDateTimeString(),
				]);
			}
		}
	}
}
