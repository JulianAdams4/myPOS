<?php

namespace App\Jobs\Rappi;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Rappi\GetOrdersRappi;
use App\StoreIntegrationToken;
use App\Store;
use App\Jobs\Rappi;
use App\AvailableMyposIntegration;

class FindOrdersPerStore
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle()
    {
       /* $integrations = StoreIntegrationToken::where('integration_name', 'like', AvailableMyposIntegration::NAME_RAPPI)->where('scope', 'job')->get();
        foreach ($integrations as $storeIntegration) {
            $store = $storeIntegration->store;
            $tries = 0;
	
            dispatch(new Rappi\GetOrdersRappi($store, $tries));
        }
	*/
	$integrations = StoreIntegrationToken::where('integration_name', 'like', AvailableMyposIntegration::NAME_RAPPI)
        ->where('scope', 'job')
	->where('is_anton',false)
        ->join('cashier_balances', function ($join) {
            $join->on(
                'store_integration_tokens.store_id',
                '=',
                'cashier_balances.store_id'
            )->whereNull('cashier_balances.date_close');
        })
        ->get(['store_integration_tokens.id','store_integration_tokens.store_id', 'integration_name','token','password', 'type', 'token_type', 'scope', 'store_integration_tokens.deleted_at']);
        foreach ($integrations as $storeIntegration) {
            $store = $storeIntegration->store;
            $tries = 0;
            $connection = "rappi_1";
            //if($store->id%2==0){
          //      $connection = "rappiv2";
	//	dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection($connection)->onQueue('rappiv2');;
            //}else{
            //	dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection($connection)->onQueue('rappi');;
	    //}
	if($store->id==374){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv25")->onQueue('rappiv25');
        }else if($store->id==510){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv24")->onQueue('rappiv24');
        }else if($store->id==270){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv23")->onQueue('rappiv23');
        }else if($store->id==720){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv22")->onQueue('rappiv22');
        }else if($store->id==575){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv21")->onQueue('rappiv21');
        }else if($store->id%20==19){
    		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv20")->onQueue('rappiv20');
	}else if($store->id%20==18){
    		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv19")->onQueue('rappiv19');
	}else if($store->id%20==17){
    		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv18")->onQueue('rappiv18');
	}else if($store->id%20==16){
    		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv17")->onQueue('rappiv17');
	}else if($store->id%20==15){
    		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv16")->onQueue('rappiv16');
	    }else if($store->id%20==14){
    		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv15")->onQueue('rappiv15');
	    }else if($store->id%20==13){
	        dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv14")->onQueue('rappiv14');
            }else if($store->id%20==12){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv13")->onQueue('rappiv13');
            }else if($store->id%20==11){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv12")->onQueue('rappiv12');
            }else if($store->id%20==10){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv11")->onQueue('rappiv11');
            }else if($store->id%20==9){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv10")->onQueue('rappiv10');
            }else if($store->id%20==8){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv9")->onQueue('rappiv9');
            }else if($store->id%20==7){
		dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv8")->onQueue('rappiv8');
	    }else if($store->id%20==6){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv7")->onQueue('rappiv7');
            }else if($store->id%20==5){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv6")->onQueue('rappiv6');
            }else if($store->id%20==4){
		//if($storeIntegration->id%3==1){
			dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv5")->onQueue('rappiv5');
		//}else{
		//	dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappi_v1")->onQueue('rappi');
		//}
                //dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv5")->onQueue('rappiv5');
	   }else if($store->id%20==3){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv4")->onQueue('rappiv4');
            }else if($store->id%20==2){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv3")->onQueue('rappiv3');
            }else if($store->id%20==1){
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappiv2")->onQueue('rappiv2');
            }else{
                dispatch(new Rappi\GetOrdersRappi($store, $tries))->onConnection("rappi_1")->onQueue('rappi');
            }
        }
    }
}
