<?php

use App\Store;
use App\CashierBalance;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CashierBalanceNumbersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach (Store::all() as $store) {
            $cashierBalances = CashierBalance::where('store_id', $store->id)->get();
            $cashierNumber = 1;
            foreach ($cashierBalances as $cashierBalance) {
                $cashierBalance->cashier_number = $cashierNumber;
                $cashierBalance->save();
                $cashierNumber++;
            }
        }
    }
}
