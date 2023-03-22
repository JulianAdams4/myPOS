<?php
namespace App\Jobs\DarkKitchen;

use Log;
use App\Store;
use App\Employee;
use Carbon\Carbon;
use App\StoreIntegrationToken;
use App\Traits\Mely\MelyRequest;
use GuzzleHttp\Psr7;
use App\StoreConfig;
use GuzzleHttp\Client;
use App\CashierBalance;
use App\ExpensesBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use App\Traits\CashierBalanceHelper;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CloseCashier implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use CashierBalanceHelper;

    public $cashiers_to_close;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($cashiers_to_close)
    {
        $this->cashiers_to_close = $cashiers_to_close;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        foreach ($this->cashiers_to_close as $storeId) {

            $cashierBalance = CashierBalance::where('store_id', $storeId)
                            ->whereNull('date_close')
                            ->first();

            if ($cashierBalance == null) {
                continue;
            }                

            $infoCashier = $this->getValuesCashierBalance($cashierBalance->id);
            $infoStore = $this->getInfoStore($storeId);
        
            try {
                DB::beginTransaction();

                $closedCashier = CashierBalance::where([
                    ['store_id','=',$storeId],
                    ['date_close','=',null]
                ])->first();

                $closedCashier->date_close = Carbon::now($infoStore->configs->time_zone)->format('Y-m-d');
                $closedCashier->hour_close = Carbon::now($infoStore->configs->time_zone)->format('H:i:s');
                $closedCashier->value_close = ceil($infoCashier['close']);
                $closedCashier->employee_id_close = $this->getFirstEmployee($storeId);
                $closedCashier->save();
                try{
                    $storeIntegrationsTokens = StoreIntegrationToken::where('store_id', $storeId)
                    ->where('is_anton', true)
                    ->whereNotNull('external_store_id')
                    ->get();
                    if($storeIntegrationsTokens->count()>0){
                        MelyRequest::sendStatusIntegration($storeIntegrationsTokens, CashierBalance::ANTON_CLOSE);
                    }
                } catch (Exception $e){
                    Log::channel('auto_cashier')->info('anton open request error: '.$e->getMessage());
                }
                DB::commit();
            } catch (Exception $e){
                DB::rollBack();
            }

            Log::channel('auto_cashier')->info('Caja cerrada: '.$storeId.' | Con:'.json_encode($closedCashier));

        }
    }

    public function getFirstEmployee($storeId){
        $employee = Employee::where('store_id', $storeId)
        ->min('id');  

        return $employee;
    }

    public function getInfoStore($storeId){
        $store = Store::where('id', $storeId)->first();  

        return $store;
    }
}
