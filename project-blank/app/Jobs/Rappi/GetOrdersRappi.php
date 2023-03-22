<?php

namespace App\Jobs\Rappi;

use Log;
use App\Store;
use Exception;
use App\CashierBalance;
use Illuminate\Bus\Queueable;
use App\Traits\RappiIntegration;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GetOrdersRappi implements ShouldQueue{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RappiIntegration;

    public $store;
    public $tries;

    public function __construct(Store $store, int $tries){
        $this->store = $store;
        $this->tries = $tries;
    }

    public function handle(){
        try{

            //$this->store->load('currentCashierBalance');
            $this->store->load('hubs');
            //$hasOpenCashier = $this->store->currentCashierBalance;
            
            //if($hasOpenCashier){
                $this->getOrders($this->store, $this->tries);
            //}else{
            //}
            
        } catch (\Exception $e) {
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
        }
    }

}
