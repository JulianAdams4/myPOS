<?php

namespace App\Jobs\DarkKitchen;

use Log;
use App\Store;
use App\Employee;
use App\StoreIntegrationToken;
use Carbon\Carbon;
use GuzzleHttp\Psr7;
use GuzzleHttp\Client;
use App\CashierBalance;
use App\ExpensesBalance;
use App\Traits\Mely\MelyRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class OpenCashier implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $cashiers_to_open;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($cashiers_to_open)
    {
        $this->cashiers_to_open = $cashiers_to_open;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        foreach ($this->cashiers_to_open as $storeId) {

            $cashierBalance = CashierBalance::where('store_id', $storeId)
                            ->whereNull('date_close')
                            ->count();

            if ($cashierBalance > 0) {
                continue;
            }

            $infoStore = $this->getInfoStore($storeId);

            try {
                DB::beginTransaction();

                CashierBalance::create([
                    'date_open' => Carbon::now($infoStore->configs->time_zone)->format('Y-m-d'),
                    'hour_open' => Carbon::now($infoStore->configs->time_zone)->format('H:i:s'),
                    'observation' => '',
                    'value_open' => 0,
                    'value_previous_close'=> $this->valuePreviousClose($storeId),
                    'employee_id_open' => $this->getFirstEmployee($storeId),
                    'store_id' => $storeId
                ]);
                try{
                    $storeIntegrationsTokens = StoreIntegrationToken::where('store_id', $storeId)
                    ->where('is_anton', true)
                    ->whereNotNull('external_store_id')
                    ->get();
                    if($storeIntegrationsTokens->count()>0){
                        MelyRequest::sendStatusIntegration($storeIntegrationsTokens, CashierBalance::ANTON_OPEN);
                    }
                } catch (Exception $e){
                    Log::channel('auto_cashier')->info('anton open request error: '.$e->getMessage());
                }
                
    
                DB::commit();
            } catch (Exception $e){
                DB::rollBack();
            }

            Log::channel('auto_cashier')->info('Caja abierta: '.$storeId);

        }
    }

    public function valuePreviousClose($store_id){
        $cashierBalanceClosed = CashierBalance::where('store_id', $store_id)
                                ->orderBy('id', 'DESC')
                                ->get();

        $valuePreviousClose = 0;
        $totalExpenses = 0;

        if (count($cashierBalanceClosed) > 0) {
            $valuePreviousClose = (string)$cashierBalanceClosed[0]->value_close;
            $expenses = ExpensesBalance::where('cashier_balance_id', $cashierBalanceClosed[0]->id)->get();
            foreach ($expenses as $expense) {
                $totalExpenses += $expense->value;
            }
        }

        return $valuePreviousClose = $valuePreviousClose - $totalExpenses;
    }

    public function getFirstEmployee($store_id){
        $employee = Employee::where('store_id', $store_id)
        ->min('id');  

        return $employee;
    }

    public function getInfoStore($storeId){
        $store = Store::where('id', $storeId)->first();  

        return $store;
    }
}
