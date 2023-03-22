<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\CashierBalance;
use Carbon\Carbon;
use App\Order;
use App\Traits\OrderHelper;
use App\ExpensesBalance;
use Auth;
use Illuminate\Support\Facades\DB;
use Log;
use App\PendingSync;

class UberEatsController extends Controller
{
    use OrderHelper;

    public function getOrderDetails($id)
    {
        // Verificar por token


        //
        Log::info("getOrderDetails");

        return response()->json(
            [
                'status' => 'Success',
                'results' => "Testing"
            ],
            200
        );

        // $cashierBalance = CashierBalance::where('store_id', $store->id)
        //                     ->whereNull('date_close')
        //                     ->first();
        // $lastCashierBalance = $cashierBalance;
        // $totalExpenses = 0;
        // if (!$cashierBalance) {
        //     $cashierBalanceClosed = CashierBalance::where('store_id', $store->id)
        //                             ->orderBy('id', 'DESC')
        //                             ->get();
        //     $valuePreviousClose = '0';
        //     if (count($cashierBalanceClosed) > 0) {
        //         $valuePreviousClose = (string)$cashierBalanceClosed[0]->value_close;
        //         $expenses = ExpensesBalance::where('cashier_balance_id', $cashierBalanceClosed[0]->id)->get();
        //         foreach ($expenses as $expense) {
        //             $totalExpenses += $expense->value;
        //         }
        //     }
        //     $dt = Carbon::now();
        //     $today = $dt->toDateString();
        //     $valuePreviousClose = $valuePreviousClose - $totalExpenses;

        //     $lastCashierBalance = [
        //         "date_open" => str_replace('-', '/', $today),
        //         "hour_open" => Carbon::createFromFormat('Y-m-d H:i:s', $dt)->format('H:i'),
        //         "value_previous_close" => $valuePreviousClose,
        //         "value_open" => null,
        //         "observation" => "",
        //     ];
        // }
        // return response()->json(
        //     [
        //         'status' => 'Success',
        //         'results' => $lastCashierBalance
        //     ],
        //     200
        // );
    }

}
