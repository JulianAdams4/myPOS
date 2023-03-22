<?php

namespace App\Http\Controllers\API\JobControllers\Integrations;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\StoreIntegrationToken;
use App\AvailableMyposIntegration;

use Log;
use Buzz\Browser;
use Carbon\Carbon;
use Buzz\Client\FileGetContents;
use Buzz\Message\FormRequestBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

class DidiRefresh extends Controller
{
    public function __construct()
    {
        // ...
    }

    /**
     * Funcion principal
     * @return Json response
     */
    public function refreshToken(Request $request)
    {
        try {
            $name_didi = AvailableMyposIntegration::NAME_DIDI;

            $integration_stores = StoreIntegrationToken::where('integration_name', $name_didi);
            if (config('app.env') == 'production') { // ***
                $integration_stores = $integration_stores->whereNotNull('store_id');
            } else {
                $integration_stores = $integration_stores->where('store_id', 3);
            }
            $integration_stores = $integration_stores->where('type', 'delivery')->get();

            if (!$integration_stores) {
                return response()->json(['status' => 'No hay tiendas'], 404);
            }

            $failedIds = [];
            foreach ($integration_stores as $store) {
                if ($this->checkRemainingDaysDiDi($store)) {
                    $external_store = StoreIntegrationId::where('integration_name', $name_didi)
                        ->where('store_id', $store->store_id)->first();

                    $external_store_id = $external_store->external_store_id;
                    if ($external_store_id) {
                        $logStatus = '';
                        $logBody = '';
                        $logMessage = '';

                        $response = $this->refreshDiDiToken($external_store_id);
                        if ($response->getStatusCode() !== 200) {
                            $logStatus = $response->getStatusCode();
                            $logBody = $response->getBody()->__toString();
                            $logMessage = 'No se pudo actualizar el token de DiDi de tienda con id '.$store->store_id;
                            array_push($failedIds, $store->store_id);
                        } else {
                            $decodedError = json_decode($response->getBody());
                            if (property_exists($decodedError, 'errno') || $decodedError['errno'] != 0) {
                                $responseGetToken = $this->getToken($external_store_id);
                                $logStatus = $responseGetToken->getStatusCode();
                                $logBody = $responseGetToken->getBody()->__toString();

                                if ($responseGetToken->getStatusCode() == 200) {
                                    $decodedJson2 = json_decode($responseGetToken->getBody());
                                    if (property_exists($decodedJson2, 'errno') || $decodedJson2['errno'] != 0) {
                                        $this->updateTokenData($store->store_id, $decodedJson2);
                                    }
                                    $logMessage = 'Se actualizo el token de DiDi de tienda con id '.$store->store_id;
                                } else {
                                    $logMessage = 'Error actualizando el token de DiDi de tienda con id '.$store->store_id;
                                    array_push($failedIds, $store->store_id);
                                }
                            } else {
                                $logStatus = 500;
                                $logBody = $response->getBody()->__toString();
                                $logMessage = 'Error actualizando el token de DiDi de tienda con id '.$store->store_id;
                                array_push($failedIds, $store->store_id);
                            }
                        }

                        Log::channel('DiDiTokenRefresh')->info($logStatus);
                        Log::channel('DiDiTokenRefresh')->info($logBody);
                        Log::channel('DiDiTokenRefresh')->info($logMessage);

                        if ($logStatus !== 200) { // Break if any store fails
                            break;
                        }
                    }
                }
            }
            // Aqui mandar (solo 1) response
            if (count($failedIds) > 0) {
                $idStr = " " . implode(", ", $failedIds);
                return response()->json(['status' => "Falló al actualizar los tokens en las tiendas".$idStr], 409);
            }

            return response()->json(['status' => "OK"], 200);
        } catch (\Exception $e) {
            $msg = "Fallo al actualizar los tokens de Didi";
            Log::error($msg.": ".$e);
            return response()->json(['status' => $msg], 500);
        }
    }

    /**
     * Funcion que transforma el tiempo en segundos a dias
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
     * @param storeId    the app_shop_id from DiDi
     * @return Response
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

        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());
        $baseUrl = config('app.didi_url_api');
        $jsonObject = json_encode($bodyParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * @param storeId  the app_shop_id from DiDi
     * @return Response
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

        $client = new FileGetContents(new Psr17Factory());
        $browser = new Browser($client, new Psr17Factory());
        $baseUrl = config('app.didi_url_api');
        $jsonObject = json_encode($bodyParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * @param storeId the store_id field
     * @param response the Api call response
     * @return void
     */
    public function updateTokenData($storeId, $response)
    {
        $name_didi = AvailableMyposIntegration::NAME_DIDI;

        try {
            $storeIntegrationToken = StoreIntegrationToken::where('store_id', '=', $storeId)
                ->where('integration_name', $name_didi)->firstOrFail();

            $bodyJSON = json_decode($response->getBody()->__toString(), true);
            $storeIntegrationToken->expires_in = $bodyJSON['data']['token_expiration_time'];
            $storeIntegrationToken->token = $bodyJSON['data']['auth_token'];
            $storeIntegrationToken->save();
        } catch (\Exception $e) {
            Log::info('Error al actualizar el token de DiDi En Base');
        }
    }
}
