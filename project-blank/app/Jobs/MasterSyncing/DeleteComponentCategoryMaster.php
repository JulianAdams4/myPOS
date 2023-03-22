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
use App\ComponentCategory;
use App\PendingSync;
use Log;

class DeleteComponentCategoryMaster implements ShouldQueue
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
    public function __construct(ComponentCategory $order, PendingSync $syncPending)
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
        // if(!config('app.slave')){
        //     Log::info("no lo es c:");
        // }else{
        //     Log::info("si lo es again!");
        // }
        // $myposEndpoint = config('app.prod_api') . 'v2/slave/create/component/category';
        // $client = new \GuzzleHttp\Client();
        // try{
        //     $orderLess = $this->order;
        //     $orderPayloaded = $orderLess;
        //     $orderPayloaded->makeHidden(['id']);
        //     $orderPayloaded->makeVisible(['priority','search_string','status','created_at','updated_at','company_id']);
        //     $payload = $orderPayloaded;
        //     $accessToken = config('app.employee_prod_token');
        //     $headers = [
        //         'Authorization' => 'Bearer '. $accessToken,
        //         'Content-Type' => 'application/json'
        //     ];
        //     $request = new Request('POST', $myposEndpoint, $headers, $payload);
        //     $response = $client->send($request, ['timeout' => 5]);
        //     Log::info("printing status code");
        //     Log::info($response->getStatusCode());
        //     Log::info($response->getBody());
        //     if($response->getStatusCode() === 200){
        //         $syncedID = json_decode($response->getBody())->results;
        //         $orderSynced = ComponentCategory::find($this->order->id);
        //         $orderSynced->synced_id = $syncedID;
        //         $orderSynced->save();

        //         $this->syncPending->delete();
        //     }
        // }catch (ClientException  $e) {
        //     $response =$e->getResponse()->getStatusCode();
        //     Log::info("Catching errors Client Error");
        //     Log::info($response);
        //     $this->syncPending->tries = $this->syncPending->tries +1;
        // }
        // catch (ServerException $e) {
        //     $response =$e->getResponse()->getStatusCode();
        //     Log::info("Catching errors Server Error");
        //     Log::info($response);
        //     $this->syncPending->tries = $this->syncPending->tries +1;
        // }
        // catch (BadResponseException $e) {
        //     $response =$e->getResponse()->getStatusCode();
        //     Log::info("Catching errors Bad Response Error");
        //     Log::info($response);
        //     $this->syncPending->tries = $this->syncPending->tries +1;
        // }
        // catch (RequestException $e){
        //     $response =$e->getResponse()->getStatusCode();
        //     Log::info("Catching errors Request Error");
        //     Log::info($response);
        //     $this->syncPending->tries = $this->syncPending->tries +1;
        // }
    }

}
