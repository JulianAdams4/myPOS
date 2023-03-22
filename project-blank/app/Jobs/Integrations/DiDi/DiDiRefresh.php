<?php

namespace App\Jobs\Integrations\DiDi;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

use App\StoreIntegrationToken;
use App\StoreIntegrationId;
use App\AvailableMyposIntegration;

use Log;
use Carbon\Carbon;
use Buzz\Browser;
use Buzz\Client\FileGetContents;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Message\FormRequestBuilder;

use App\Traits\DidiFood\DidiRequests;


class DiDiRefresh implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(){}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // search for the stores that have DiDi Integration

        $integration_stores = StoreIntegrationToken::where(
                                                'integration_name',
                                                AvailableMyposIntegration::NAME_DIDI
                                            )
                                            ->where('type', 'delivery')
                                            ->get();

        if (!$integration_stores) {
            Log::info("there are no stores");
            return;
        }
        
        foreach ($integration_stores as $store) {
            if($this->checkRemainingDaysDiDi($store)){
                $external_store = StoreIntegrationId::where(
                                    'integration_name',
                                    AvailableMyposIntegration::NAME_DIDI)
                                    ->where('store_id', $store->store_id)
                                    ->first();
                $external_store_id = $external_store->external_store_id;
                
                if($external_store_id){
                    $response = $this->refreshDiDiToken($external_store_id);

                    if ($response->getStatusCode() !== 200) {

                        Log::channel('DiDiTokenRefresh')->info($response->getStatusCode());
                        Log::channel('DiDiTokenRefresh')->info($response->getBody()->__toString());
                        Log::channel('DiDiTokenRefresh')->info('No se pudo actualizar el token de DiDi de tienda con id'.$store->store_id);
                    }
                    else {
                        $response2 = json_decode($response->getBody());
                        if(property_exists($response2, 'errno') || $response2['errno'] != 0)
                        {
                            Log::info($response->getStatusCode());
                            Log::info($response->getBody()->__toString());

                            $responseGet = $this->getToken($external_store_id);

                            if($responseGet->getStatusCode() == 200){
                                $responseGet2 = json_decode($responseGet->getBody());
                                if(property_exists($responseGet2, 'errno') || $responseGet2['errno'] != 0)
                                {
                                    $this->updateTokenData($store->store_id, $responseGet); 
                                }
                            }

                            Log::channel('DiDiTokenRefresh')->info('Se actualizo el token de DiDi de tienda con id'.$store->store_id);
                            return;
                        }
                        Log::channel('DiDiTokenRefresh')->info($response->getStatusCode());
                        Log::channel('DiDiTokenRefresh')->info($response->getBody()->__toString());
                        Log::channel('DiDiTokenRefresh')->info('Error actualizando el token de DiDi de tienda con id'.$store->store_id);
                    }
                }
            }
        
        }
    }

    /**
     * Funcion que transforma el tiempo en segundos a dias
     * 
     * @return float
     */
    public function checkRemainingDaysDiDi($store)
    {
        $now = Carbon::now()->addDays(1);
        $expires = Carbon::createFromTimestamp($store->expires_in);
        $diff = $now->gte($expires);

        return $diff;
    }

    /**
     * Calls DiDi API for token refreshing
     * 
     * @storeId     the app_shop_id from DiDi
     * 
     * @return RESPONSE
     */
    public function refreshDiDiToken($storeId)
    {
        $didiAppId = config('app.didi_app_id');
        $didiAppSecret = config('app.didi_app_secret');

        $bodyParams = [
            "app_id" => $didiAppId,
            "app_secret" => $didiAppSecret,
            "app_shop_id" => $storeId
        ];

        $jsonObject = json_encode($bodyParams, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);

        $baseUrl = config('app.didi_url_api');

        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());
        $response = $browser->post(
            $baseUrl . 'v1/auth/authtoken/refresh',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );

        return $response;
    }


    /**
     * Calls DiDi API to get the new token
     * 
     * @storeId  the app_shop_id from DiDi
     * 
     * @return RESPONSE
     */
    public function getToken($storeId)
    {
        $data = null;
        $status = 0; // 0: Error, 1: Éxito

        $didiAppId = config('app.didi_app_id');
        $didiAppSecret = config('app.didi_app_secret');

        $bodyParams = [
            "app_id" => $didiAppId,
            "app_secret" => $didiAppSecret,
            "app_shop_id" => $storeId
        ];

        $jsonObject = json_encode($bodyParams, JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE);

        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());

        $baseUrl = config('app.didi_url_api');

        $response = $browser->post(
            $baseUrl . 'v1/auth/authtoken/get',
            [
                'User-Agent' => 'Buzz',
                'Content-Type' => 'application/json'
            ],
            $jsonObject
        );
                
        return $response;
    }


    /**
     * Funcion that updates the registers of the DiDi Auth token on the DB
     * 
     * @storeId the store_id field
     * 
     * @response the Api call response
     * 
     * @return void
     * 
     */
    public function updateTokenData($storeId, $response)
    {
        try{
            $bodyJSON = json_decode($response->getBody()->__toString(), true);
            $storeIntegrationToken = StoreIntegrationToken::where('store_id', '=', $storeId)
                                                            ->where(
                                                                'integration_name',
                                                                AvailableMyposIntegration::NAME_DIDI
                                                            )->firstOrFail();

            $storeIntegrationToken->expires_in = $bodyJSON['data']['token_expiration_time'];
            $storeIntegrationToken->token = $bodyJSON['data']['auth_token'];
            $storeIntegrationToken->save();
        }catch (\Exception $e) {
            Log::info('Error al actualizar el token de DiDi En Base');
        }
    }

}
