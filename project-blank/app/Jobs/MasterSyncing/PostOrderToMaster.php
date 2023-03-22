<?php

namespace App\Jobs\MasterSyncing;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\BadResponseException;
use App\Traits\GacelaIntegration;
use App\Order;
use App\PendingSync;
use App\CashierBalance;
use App\AdminStore;
use Log;

class PostOrderToMaster implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $order;
    public $syncPending;

    //public $tries = 5;
    //public $timeout = 90;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order, PendingSync $syncPending)
    {
        $this->order = $order;
        $this->syncPending = $syncPending;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $myposEndpoint = config('app.prod_api') . 'v2/slave/create/order';
        $client = new \GuzzleHttp\Client();
        try{
            $detailsLess = [];

            $details = $this->order->orderDetails;
            foreach ($details as $onlyDetail) {
                array_push($detailsLess, $onlyDetail->makeHidden(['id']));

            }
            $orderLess = $this->order;
            $orderLess->order_details = $detailsLess;
            if ($this->order->billing != null) {
                $orderLess->has_billing = true;
            }
            $orderPayloaded = $orderLess;
            $orderPayloaded->makeHidden(['id', 'synced_id', 'nt_value']);
            $cashierBalance = CashierBalance::find($this->order->cashier_balance_id);
            $orderPayloaded->cashier_balance_id = $cashierBalance->synced_id;
            $adminStore = AdminStore::where('store_id', $this->order->store->id)->first();
            $accessToken = $adminStore->api_token;
            $headers = [
                'Authorization' => 'Bearer '. $accessToken,
                'Content-Type' => 'application/json'
            ];
            $request = new Request('POST', $myposEndpoint, $headers, $orderPayloaded);
            $response = $client->send($request, ['timeout' => 5]);
            Log::info("printing status code");
            Log::info($response->getStatusCode());
            Log::info($response->getBody());
            if ($response->getStatusCode() === 200) {
                $syncedID = json_decode($response->getBody())->results;
                $orderSynced = Order::find($this->order->id);
                $orderSynced->synced_id = $syncedID;
                $orderSynced->save();

                $this->syncPending->delete();
            }
        }catch (ClientException  $e) {
            Log::info("PostOrderToMaster Slave handle prod response ClientException: NO SE PUDO REALIZAR LA PETICION GET");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
        catch (ServerException $e) {
            Log::info("PostOrderToMaster Slave handle prod response ServerException: ERROR EN EL SERVIDOR PROD");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
        catch (BadResponseException $e) {
            Log::info("PostOrderToMaster Slave handle prod response BadResponseException: ERROR DE RESPUESTA DEL SERVIDOR PROD");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
        catch (RequestException $e){
            Log::info("PostOrderToMaster Slave handle prod response RequestException: NO SE PUDO REALIZAR LA PETICION GET");
            Log::info($e->getMessage());
            Log::info("Archivo");
            Log::info($e->getFile());
            Log::info("Línea");
            Log::info($e->getLine());
            Log::info("Provocado por");
            Log::info(json_encode($request->all()));
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
    }

}
